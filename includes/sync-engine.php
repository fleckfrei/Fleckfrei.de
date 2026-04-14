<?php
/**
 * Fleckfrei Sync-Engine — DB ↔ Google Sheet.
 *
 * Design-Prinzipien (per Max: "nicht gelöscht werden"):
 *   • UPSERT only — nie DELETE, nie TRUNCATE.
 *   • Snapshot vor jedem Lauf (JSON-Dump in uploads/sync-snapshots/).
 *   • Jede Änderung landet in sync_log (Audit).
 *   • Matching-Key: s_id ↔ service_id (wenn leer → match by name+city).
 *   • Idempotent: mehrfach laufen lassen ist safe.
 */

require_once __DIR__ . '/google-helpers.php';

const FF_SYNC_SHEET_ID = '1IuKJJgdJ5Ln0j99e1kEIaEZLFYKYKFDuOK5I-NjgV1g';
const FF_SYNC_SERVICE_TAB = 'Service -Fleckfrei.de';
const FF_SYNC_KEYWORD_TAB = 'Flow_keyword_fleckfrei';

function sync_ensure_schema(): void {
    global $db;
    $db->exec("CREATE TABLE IF NOT EXISTS sync_log (
        sl_id INT AUTO_INCREMENT PRIMARY KEY,
        source VARCHAR(30) NOT NULL,         /* sheet2db | db2sheet */
        entity VARCHAR(30) NOT NULL,         /* services | keywords */
        action VARCHAR(20) NOT NULL,         /* insert | update | noop | skip | error */
        ref_id VARCHAR(60) DEFAULT NULL,
        details TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_src_ent (source, entity, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function sync_snapshot(): string {
    global $db;
    $dir = __DIR__ . '/../uploads/sync-snapshots';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $path = $dir . '/' . date('Y-m-d_His') . '.json';
    $data = [
        'timestamp' => date('c'),
        'services' => all("SELECT s_id, title, wa_keyword, price, total_price, unit, tax_percentage, customer_id_fk, street, city, status, is_cleaning FROM services"),
        'counts'   => [
            'services_total'      => (int) val("SELECT COUNT(*) FROM services"),
            'services_active'     => (int) val("SELECT COUNT(*) FROM services WHERE status=1"),
            'services_with_kw'    => (int) val("SELECT COUNT(*) FROM services WHERE wa_keyword IS NOT NULL AND wa_keyword<>''"),
        ],
    ];
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    // Keep only last 30 snapshots
    $files = glob($dir . '/*.json');
    if ($files && count($files) > 30) {
        sort($files);
        foreach (array_slice($files, 0, count($files) - 30) as $old) @unlink($old);
    }
    return basename($path);
}

function sync_log(string $source, string $entity, string $action, ?string $refId, string $details): void {
    try {
        q("INSERT INTO sync_log (source, entity, action, ref_id, details) VALUES (?,?,?,?,?)",
          [$source, $entity, $action, $refId, $details]);
    } catch (Exception $e) {}
}

/** Parse price string like "40€" / "25.8€" / "70lei" → float (EUR assumed; lei skipped) */
function sync_parse_price($v): ?float {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || $s === '-' || $s === 'N/A') return null;
    if (stripos($s, 'lei') !== false) return null; // skip RON entries
    $s = str_replace(['€',',',' '], ['', '.', ''], $s);
    if (!is_numeric($s)) return null;
    return round((float)$s, 2);
}

/**
 * Sheet → DB for Service-Tab.
 * Strategy: match by service_id (sheet column) ↔ s_id (db). If missing, match by name.
 * If not found, INSERT as new platform-service with customer_id_fk=0.
 * NEVER deletes DB rows.
 */
function sync_sheet_to_db_services(): array {
    global $db;
    sync_ensure_schema();
    $snap = sync_snapshot();

    $tok = google_token();
    if (!$tok) return ['ok' => false, 'error' => 'google_auth_missing', 'snapshot' => $snap];

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . FF_SYNC_SHEET_ID . '/values/' . urlencode(FF_SYNC_SERVICE_TAB . '!A1:L500');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok"], CURLOPT_TIMEOUT=>30]);
    $raw = curl_exec($ch); curl_close($ch);
    $resp = json_decode($raw, true);
    $rows = $resp['values'] ?? [];
    if (empty($rows)) return ['ok' => false, 'error' => 'no_rows', 'snapshot' => $snap];

    $header = array_map('strtolower', array_map('trim', $rows[0]));
    $idx = function($key) use ($header) {
        foreach ($header as $i => $h) if (strpos($h, strtolower($key)) !== false) return $i;
        return -1;
    };

    $iName   = $idx('service_name');
    $iUnit   = $idx('unit_service');
    $iNet    = $idx('nett_price');
    $iGross  = $idx('brutto_service_price');
    $iTax    = $idx('tax_%');
    $iId     = $idx('service_id');

    $stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'noop' => 0];

    for ($r = 1; $r < count($rows); $r++) {
        $row = $rows[$r];
        $name  = trim($row[$iName] ?? '');
        if ($name === '') { $stats['skipped']++; continue; }

        $unit  = trim($row[$iUnit] ?? 'hour');
        $net   = sync_parse_price($row[$iNet] ?? null);
        $gross = sync_parse_price($row[$iGross] ?? null);
        $tax   = 19; if (isset($row[$iTax])) { $t = preg_replace('/\D/', '', $row[$iTax]); if ($t !== '') $tax = (int)$t; }
        $sheetId = trim($row[$iId] ?? '');

        if ($net === null && $gross === null) {
            sync_log('sheet2db', 'services', 'skip', null, "no_price: $name");
            $stats['skipped']++;
            continue;
        }
        if ($net === null && $gross !== null) $net = round($gross / 1.19, 2);
        if ($gross === null && $net !== null) $gross = round($net * 1.19, 2);

        // Match: prefer numeric service_id, else fallback to exact title
        $existing = null;
        if ($sheetId !== '' && is_numeric($sheetId)) {
            $existing = one("SELECT * FROM services WHERE s_id=? LIMIT 1", [(int)$sheetId]);
        }
        if (!$existing) {
            $existing = one("SELECT * FROM services WHERE LOWER(TRIM(title))=LOWER(TRIM(?)) LIMIT 1", [$name]);
        }

        if ($existing) {
            // UPDATE only if values differ (avoid spurious writes)
            $same = (abs((float)$existing['price'] - $net) < 0.01)
                 && (abs((float)$existing['total_price'] - $gross) < 0.01)
                 && (trim($existing['title']) === $name)
                 && (trim($existing['unit'] ?? '') === $unit);
            if ($same) {
                $stats['noop']++;
                continue;
            }
            q("UPDATE services SET title=?, price=?, total_price=?, tax_percentage=?, unit=? WHERE s_id=?",
              [$name, $net, $gross, $tax, $unit, $existing['s_id']]);
            sync_log('sheet2db', 'services', 'update', (string)$existing['s_id'],
                     "from sheet row ".($r+1).": $name, net=$net, gross=$gross");
            $stats['updated']++;
        } else {
            // INSERT — platform service (customer_id_fk=0), default status=1
            q("INSERT INTO services (title, customer_id_fk, price, total_price, tax_percentage, unit, coin, status, is_cleaning, created_at)
               VALUES (?, 0, ?, ?, ?, ?, '€', 1, 1, NOW())",
              [$name, $net, $gross, $tax, $unit]);
            $nid = $db->lastInsertId();
            sync_log('sheet2db', 'services', 'insert', (string)$nid,
                     "from sheet row ".($r+1).": $name, net=$net, gross=$gross");
            $stats['inserted']++;
        }
    }

    return [
        'ok'       => true,
        'snapshot' => $snap,
        'stats'    => $stats,
        'rows_seen' => count($rows) - 1,
    ];
}

/**
 * Sheet → DB for WhatsApp-Keyword-Tab (Flow_keyword_fleckfrei).
 * Append-only: maintains services.wa_keyword if title matches.
 */
function sync_sheet_to_db_keywords(): array {
    sync_ensure_schema();

    $tok = google_token();
    if (!$tok) return ['ok' => false, 'error' => 'google_auth_missing'];

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . FF_SYNC_SHEET_ID . '/values/' . urlencode(FF_SYNC_KEYWORD_TAB . '!A1:H200');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok"], CURLOPT_TIMEOUT=>30]);
    $resp = json_decode(curl_exec($ch), true); curl_close($ch);
    $rows = $resp['values'] ?? [];

    $stats = ['kw_seen' => count($rows) - 1, 'logged' => 0];
    // We just log the keywords mapping — they go to a separate whatsapp_flow_keywords table.
    global $db;
    $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_flow_keywords (
        wk_id INT AUTO_INCREMENT PRIMARY KEY,
        keyword VARCHAR(100) NOT NULL,
        flow_name VARCHAR(200) DEFAULT '',
        flow_id VARCHAR(50) DEFAULT '',
        flow_url VARCHAR(500) DEFAULT '',
        intern_function VARCHAR(50) DEFAULT '',
        notes TEXT,
        source VARCHAR(30) DEFAULT 'sheet_sync',
        synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY idx_kw (keyword)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    for ($r = 1; $r < count($rows); $r++) {
        $row = $rows[$r];
        $kwCol = trim($row[3] ?? '');  // "Flow_name_key_fleckfrei"
        if ($kwCol === '' || $kwCol === 'New') continue;
        // kwCol can be "clean,Clean,Cleaning" → split
        foreach (array_filter(array_map('trim', explode(',', $kwCol))) as $kw) {
            if (!preg_match('/^[a-zA-Z0-9_]{2,50}$/', $kw)) continue;
            q("INSERT INTO whatsapp_flow_keywords (keyword, flow_name, flow_id, flow_url, intern_function, notes)
               VALUES (?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE flow_name=VALUES(flow_name), flow_id=VALUES(flow_id), flow_url=VALUES(flow_url), intern_function=VALUES(intern_function), notes=VALUES(notes)",
              [$kw, trim($row[4] ?? ''), trim($row[7] ?? ''), trim($row[6] ?? ''), trim($row[1] ?? ''), trim($row[5] ?? '')]);
            $stats['logged']++;
        }
    }

    sync_log('sheet2db', 'keywords', 'noop', null, 'keywords logged: ' . $stats['logged']);
    return ['ok' => true, 'stats' => $stats];
}

/**
 * DB → Sheet push for platform services (3 main services).
 * Append-only: only pushes rows that are NOT already in sheet.
 */
function sync_db_to_sheet_platform(): array {
    $tok = google_token();
    if (!$tok) return ['ok' => false, 'error' => 'google_auth_missing'];

    // Get current sheet rows to check duplicates
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . FF_SYNC_SHEET_ID . '/values/' . urlencode(FF_SYNC_SERVICE_TAB . '!A1:L500');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok"], CURLOPT_TIMEOUT=>30]);
    $resp = json_decode(curl_exec($ch), true); curl_close($ch);
    $rows = $resp['values'] ?? [];
    $existingTitles = [];
    for ($r = 1; $r < count($rows); $r++) { $existingTitles[] = strtolower(trim($rows[$r][0] ?? '')); }

    $platform = all("SELECT * FROM services WHERE customer_id_fk=0 AND is_cleaning=1 AND status=1");
    $toAppend = [];
    foreach ($platform as $svc) {
        if (in_array(strtolower(trim($svc['title'])), $existingTitles, true)) continue;
        $toAppend[] = [
            $svc['title'],
            $svc['unit'] ?: 'hour',
            number_format($svc['price'],2,'.','') . '€',
            number_format($svc['price'] * ($svc['tax_percentage']/100),2,'.','') . '€',
            $svc['tax_percentage'] . '%',
            number_format($svc['total_price'],2,'.','') . '€',
            'Regular', 'Normal', 'N/A',
            (string)$svc['s_id'],
        ];
    }

    if (empty($toAppend)) return ['ok' => true, 'appended' => 0, 'msg' => 'Sheet already in sync'];

    $aurl = 'https://sheets.googleapis.com/v4/spreadsheets/' . FF_SYNC_SHEET_ID . '/values/' . urlencode(FF_SYNC_SERVICE_TAB . '!A1:L1') . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';
    $ch = curl_init($aurl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_POST => 1,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $tok", 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['values' => $toAppend]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $aresp = json_decode(curl_exec($ch), true); curl_close($ch);

    foreach ($toAppend as $row) sync_log('db2sheet', 'services', 'insert', null, 'appended: ' . $row[0]);
    return ['ok' => true, 'appended' => count($toAppend), 'sheet_response' => $aresp];
}

/** Master runner — executes all sync phases safely + Telegram-Alerts bei Fail */
function sync_run_all(): array {
    $t0 = microtime(true);
    $res = [
        'started_at' => date('c'),
        'sheet_to_db_services'  => sync_sheet_to_db_services(),
        'sheet_to_db_keywords'  => sync_sheet_to_db_keywords(),
        'db_to_sheet_platform'  => sync_db_to_sheet_platform(),
        'duration_sec'          => round(microtime(true) - $t0, 2),
    ];
    sync_log('all', 'runner', 'noop', null, 'sync_run_all: ' . json_encode($res['sheet_to_db_services']['stats'] ?? []));

    // Telegram-Alert bei Fehler (Sheet→DB ist kritisch; DB→Sheet ist optional solange OAuth-Scope fehlt)
    $critical = [];
    if (empty($res['sheet_to_db_services']['ok'])) $critical[] = 'Sheet→DB Services: '. ($res['sheet_to_db_services']['error'] ?? 'unknown');
    if (empty($res['sheet_to_db_keywords']['ok'])) $critical[] = 'Sheet→DB Keywords: '. ($res['sheet_to_db_keywords']['error'] ?? 'unknown');

    if (!empty($critical) && function_exists('telegramNotify')) {
        telegramNotify("⚠️ <b>Sync-Fehler</b>\n\n" . implode("\n", $critical) . "\n\n<i>Admin: /admin/sync.php</i>");
        sync_log('all', 'runner', 'error', null, 'telegram_alert: ' . implode(' | ', $critical));
    } elseif (!empty($res['sheet_to_db_services']['stats']['inserted']) || !empty($res['sheet_to_db_services']['stats']['updated'])) {
        // Info-Alert bei größeren Änderungen (>5)
        $st = $res['sheet_to_db_services']['stats'];
        if (($st['inserted'] + $st['updated']) > 5 && function_exists('telegramNotify')) {
            telegramNotify("🔄 <b>Sync lief</b>\n\n✓ {$st['inserted']} neu, {$st['updated']} geändert, {$st['noop']} unverändert\n{$res['duration_sec']}s");
        }
    }
    return $res;
}
