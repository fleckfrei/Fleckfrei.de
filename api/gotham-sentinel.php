<?php
/**
 * Gotham Sentinel — auto-cascade active watchlist targets.
 *
 * Runs every N hours (cron task 7, default 6h). For each active
 * watchlist entry that was NOT scanned in the last 24h, fires a
 * vulture-core cascade with depth=1, mode=fast (no AI tokens to
 * save cost), and collects per-target stats.
 *
 * At the end, sends a single Telegram summary with totals + any
 * high-value findings (new verified objects, anomalies, merge
 * candidates gained).
 *
 * Manual trigger:  GET /api/gotham-sentinel.php?key=<API_KEY>&run=1
 * Preview:         GET /api/gotham-sentinel.php?key=<API_KEY>
 */

ini_set('max_execution_time', 600);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ontology.php';

db_ping_reconnect();

session_start();
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
$isCron = ($apiKey === API_KEY);
if (empty($_SESSION['uid']) && !$isCron) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$actuallyRun = !empty($_GET['run']) || !empty($_POST['run']);
$maxTargets = max(1, min(20, (int)($_GET['max'] ?? 5)));

// ============================================================
// Pick eligible watches: active + not cascaded in the last 24h
// ============================================================
$candidates = allLocal(
    "SELECT w.watch_id, w.label, w.query, w.last_hit_count,
            w.last_sentinel_scan
     FROM ontology_watchlist w
     WHERE w.active = 1
       AND (w.last_sentinel_scan IS NULL
            OR w.last_sentinel_scan < DATE_SUB(NOW(), INTERVAL 24 HOUR))
     ORDER BY COALESCE(w.last_sentinel_scan, '2000-01-01') ASC
     LIMIT " . (int)$maxTargets
);

// Ensure the last_sentinel_scan column exists (auto-migration)
if (empty($candidates)) {
    try {
        $dbLocal->exec("ALTER TABLE ontology_watchlist
                        ADD COLUMN IF NOT EXISTS last_sentinel_scan DATETIME DEFAULT NULL");
        $candidates = allLocal(
            "SELECT watch_id, label, query, last_hit_count, last_sentinel_scan
             FROM ontology_watchlist WHERE active = 1 LIMIT " . (int)$maxTargets
        );
    } catch (Exception $e) { /* MySQL may not support IF NOT EXISTS in ALTER */ }
}

$report = [
    'started_at' => date('Y-m-d H:i:s'),
    'max_targets' => $maxTargets,
    'eligible'   => count($candidates),
    'actually_run' => $actuallyRun,
    'per_target' => [],
    'totals'     => [
        'objects_before' => (int)valLocal("SELECT COUNT(*) FROM ontology_objects"),
        'verified_before'=> (int)valLocal("SELECT COUNT(*) FROM ontology_objects WHERE verified=1"),
    ],
];

if (!$actuallyRun) {
    echo json_encode(['success' => true, 'preview_only' => true, 'report' => $report]);
    exit;
}

// ============================================================
// Execute cascades sequentially (avoid rate-limit / 503)
// ============================================================
$host = $_SERVER['HTTP_HOST'] ?? 'app.' . SITE_DOMAIN;
$scheme = 'https';
$vcUrl = "$scheme://$host/api/vulture-core.php";

foreach ($candidates as $w) {
    $seed = ['name' => $w['query'], 'depth' => 1, 'mode' => 'fast',
             'context' => 'Sentinel auto-scan: ' . $w['label']];

    // Also try to interpret the query as email/phone/domain for better seed
    if (strpos($w['query'], '@') !== false) {
        $seed['email'] = $w['query'];
    } elseif (preg_match('/^\+?\d[\d\s\-]{7,}/', $w['query'])) {
        $seed['phone'] = $w['query'];
    } elseif (preg_match('/^[a-z0-9][a-z0-9-]{1,62}\.[a-z]{2,}$/i', $w['query'])) {
        $seed['domain'] = $w['query'];
    }

    $t0 = microtime(true);
    $ch = curl_init($vcUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($seed),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . API_KEY],
        CURLOPT_TIMEOUT => 240,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $elapsed = round(microtime(true) - $t0, 2);

    $entry = [
        'label'    => $w['label'],
        'query'    => $w['query'],
        'http'     => $code,
        'elapsed'  => $elapsed,
    ];
    if ($code === 200 && $resp) {
        $j = json_decode($resp, true);
        if ($j && !empty($j['success'])) {
            $entry['nodes']     = count($j['graph']['nodes'] ?? []);
            $entry['confidence']= round(($j['report']['confidence_overall'] ?? 0) * 100);
            $entry['objects_created'] = $j['ontology']['objects_created'] ?? 0;
            $entry['links_created']   = $j['ontology']['links_created']   ?? 0;
            $entry['risk_level']= $j['report']['risk_assessment']['level'] ?? '—';
        }
    } else {
        $entry['error'] = "HTTP $code";
    }
    $report['per_target'][] = $entry;

    // Mark this watch as just scanned — reconnect first because the
    // 30s+ curl subcall to vulture-core idled our MySQL connection
    // past Hostinger's wait_timeout
    db_ping_reconnect();
    try {
        qLocal("UPDATE ontology_watchlist SET last_sentinel_scan = NOW() WHERE watch_id = ?",
               [$w['watch_id']]);
    } catch (Exception $e) { /* column may not exist yet */ }
}

// Reconnect before final totals — again, subcalls idled the connection
db_ping_reconnect();

// Final totals + delta
$report['totals']['objects_after']  = (int)valLocal("SELECT COUNT(*) FROM ontology_objects");
$report['totals']['verified_after'] = (int)valLocal("SELECT COUNT(*) FROM ontology_objects WHERE verified=1");
$report['totals']['objects_delta']  = $report['totals']['objects_after']  - $report['totals']['objects_before'];
$report['totals']['verified_delta'] = $report['totals']['verified_after'] - $report['totals']['verified_before'];
$report['finished_at'] = date('Y-m-d H:i:s');

// ============================================================
// Telegram summary (one message for the whole run)
// ============================================================
if (function_exists('telegramNotify') && !empty($report['per_target'])) {
    $lines = ['🦅 <b>GOTHAM SENTINEL</b>'];
    $lines[] = '<i>' . $report['finished_at'] . '</i>';
    $lines[] = '';
    $lines[] = 'Scanned: <b>' . count($report['per_target']) . '</b> watchlist targets';
    $lines[] = '+<code>' . $report['totals']['objects_delta'] . '</code> objects · +<code>' .
               $report['totals']['verified_delta'] . '</code> verified';
    $lines[] = '';
    foreach ($report['per_target'] as $e) {
        $status = isset($e['error']) ? '❌ ' . $e['error'] :
                  '✓ ' . ($e['nodes'] ?? 0) . ' nodes · ' .
                  ($e['confidence'] ?? 0) . '% · risk:' . ($e['risk_level'] ?? '—');
        $lines[] = '• <b>' . htmlspecialchars($e['label']) . '</b> ' . $status;
    }
    $lines[] = '';
    $lines[] = '🔗 /admin/scanner.php';
    try { @telegramNotify(implode("\n", $lines)); $report['telegram_sent'] = true; }
    catch (Exception $e) { $report['telegram_error'] = $e->getMessage(); }
}

echo json_encode(['success' => true, 'report' => $report], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
