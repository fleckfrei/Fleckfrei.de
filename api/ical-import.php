<?php
/**
 * iCal Import — Standalone Sync Endpoint
 * Can be called by cron or manually:
 *   GET /api/ical-import.php?key=xxx&all=1         (sync all active feeds)
 *   GET /api/ical-import.php?key=xxx&feed_id=123   (sync one feed)
 *   GET /api/ical-import.php?key=xxx&test=1&url=...(test a URL without creating jobs)
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';

// Auth: API key required
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
if ($apiKey !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$feedId = (int)($_GET['feed_id'] ?? 0);
$syncAll = !empty($_GET['all']);
$testMode = !empty($_GET['test']);
$testUrl = $_GET['url'] ?? '';

// Test mode: just fetch + parse, return event count
if ($testMode && $testUrl) {
    $icalData = fetchIcal($testUrl);
    if (!$icalData) {
        echo json_encode(['success' => false, 'error' => 'URL nicht erreichbar']);
        exit;
    }
    $events = parseIcal($icalData);
    echo json_encode([
        'success' => true,
        'events' => count($events),
        'sample' => array_slice(array_map(fn($e) => [
            'summary' => $e['SUMMARY'] ?? '',
            'start' => $e['DTSTART'] ?? '',
            'end' => $e['DTEND'] ?? '',
            'location' => $e['LOCATION'] ?? '',
        ], $events), 0, 5),
    ]);
    exit;
}

// Get feeds to sync
if ($feedId) {
    $feeds = all("SELECT * FROM ical_feeds WHERE id=? AND active=1", [$feedId]);
} elseif ($syncAll) {
    $feeds = all("SELECT * FROM ical_feeds WHERE active=1");
} else {
    echo json_encode(['error' => 'Specify feed_id=X or all=1']);
    exit;
}

if (empty($feeds)) {
    echo json_encode(['success' => true, 'message' => 'Keine aktiven Feeds', 'synced' => 0]);
    exit;
}

$totalCreated = 0;
$totalSkipped = 0;
$totalUpdated = 0;
$results = [];

foreach ($feeds as $feed) {
    $created = 0; $updated = 0; $skipped = 0;

    $icalData = fetchIcal($feed['url']);
    if (!$icalData) {
        $results[] = ['feed' => $feed['label'], 'error' => 'Nicht erreichbar'];
        continue;
    }

    $events = parseIcal($icalData);
    $seenUids = [];

    foreach ($events as $ev) {
        $uid = $ev['UID'] ?? '';
        if ($uid) $seenUids[] = $uid;
        $dtStart = $ev['DTSTART'] ?? '';
        $parsed = icalParseDate($dtStart);
        if (!$parsed) { $skipped++; continue; }

        $jDate = $parsed['date'];
        $jTime = $parsed['time'] === '00:00' ? '10:00' : $parsed['time'];

        // Calculate hours from duration
        $jHours = 3; // default for check-in/out day events
        $dtEnd = $ev['DTEND'] ?? '';
        $parsedEnd = icalParseDate($dtEnd);
        if ($parsedEnd && $parsed['time'] !== '00:00') {
            $startTs = strtotime($parsed['date'] . ' ' . $parsed['time']);
            $endTs = strtotime($parsedEnd['date'] . ' ' . $parsedEnd['time']);
            if ($endTs > $startTs) {
                $jHours = round(($endTs - $startTs) / 3600, 1);
            }
        }

        $summary = str_replace('\\n', "\n", $ev['SUMMARY'] ?? '');
        $description = str_replace('\\n', "\n", $ev['DESCRIPTION'] ?? '');
        $location = str_replace('\\n', "\n", $ev['LOCATION'] ?? '');

        // Skip "Not available" / blocked dates
        $lowerSummary = strtolower($summary);
        if (str_contains($lowerSummary, 'not available') || str_contains($lowerSummary, 'blocked') || str_contains($lowerSummary, 'closed')) {
            $skipped++;
            continue;
        }

        // Check duplicate by ical_uid
        if ($uid) {
            $existing = one("SELECT j_id, j_date, j_time FROM jobs WHERE ical_uid=?", [$uid]);
        } else {
            $existing = null;
        }

        if ($existing) {
            if ($existing['j_date'] !== $jDate || substr($existing['j_time'] ?? '', 0, 5) !== substr($jTime, 0, 5)) {
                q("UPDATE jobs SET j_date=?, j_time=?, j_hours=? WHERE j_id=?", [$jDate, $jTime, $jHours, $existing['j_id']]);
                $updated++;
            } else {
                $skipped++;
            }
        } else {
            q("INSERT INTO jobs (customer_id_fk, j_date, j_time, j_hours, job_status, platform, ical_uid, job_note, address, status) VALUES (?,?,?,?,?,?,?,?,?,1)",
                [$feed['customer_id_fk'], $jDate, $jTime, $jHours, 'PENDING', $feed['platform'] ?: 'ical', $uid,
                 trim($summary . ($description ? "\n" . $description : '')), $location]);
            $created++;
        }
    }

    // Mark jobs that disappeared from this feed as removed_from_source_at (only future + not running/done)
    $removedCount = 0;
    if (!empty($seenUids)) {
        $placeholders = implode(',', array_fill(0, count($seenUids), '?'));
        $paramsRm = array_merge([$feed['customer_id_fk'], $feed['platform'] ?: 'ical'], $seenUids);
        $rm = q("UPDATE jobs SET removed_from_source_at=NOW()
                  WHERE customer_id_fk=? AND platform=? AND ical_uid IS NOT NULL
                    AND ical_uid NOT IN ($placeholders)
                    AND removed_from_source_at IS NULL
                    AND j_date >= CURDATE()
                    AND job_status NOT IN ('RUNNING','STARTED','COMPLETED')", $paramsRm);
        $removedCount = $rm->rowCount();
        // Un-mark jobs whose UIDs reappeared in this sync
        $paramsUnRm = array_merge([$feed['customer_id_fk']], $seenUids);
        q("UPDATE jobs SET removed_from_source_at=NULL
                  WHERE customer_id_fk=? AND ical_uid IN ($placeholders) AND removed_from_source_at IS NOT NULL", $paramsUnRm);
    }

    // Update feed stats
    q("UPDATE ical_feeds SET last_sync=NOW(), jobs_created=jobs_created+? WHERE id=?", [$created, $feed['id']]);

    $totalCreated += $created;
    $totalUpdated += $updated;
    $totalSkipped += $skipped;

    $results[] = [
        'feed' => $feed['label'],
        'events' => count($events),
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
    ];
}

if ($totalCreated > 0) {
    audit('sync', 'ical_import', 0, "Import: $totalCreated new, $totalUpdated updated");
    telegramNotify("📅 iCal Import: $totalCreated neue Jobs, $totalUpdated aktualisiert");
}

echo json_encode([
    'success' => true,
    'feeds_synced' => count($results),
    'total_created' => $totalCreated,
    'total_updated' => $totalUpdated,
    'total_skipped' => $totalSkipped,
    'feeds' => $results,
]);

// ---- Helper Functions ----

function fetchIcal(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Fleckfrei/1.0 iCal-Sync',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $data) ? $data : null;
}

function parseIcal(string $icalData): array {
    $events = [];
    if (preg_match_all('/BEGIN:VEVENT(.+?)END:VEVENT/s', $icalData, $matches)) {
        foreach ($matches[1] as $block) {
            $ev = [];
            $unfolded = preg_replace('/\r?\n[ \t]/', '', $block);
            $lines = preg_split('/\r?\n/', $unfolded);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!$line || strpos($line, ':') === false) continue;
                [$key, $val] = explode(':', $line, 2);
                $keyBase = explode(';', $key)[0];
                $ev[strtoupper($keyBase)] = $val;
            }
            if (!empty($ev['DTSTART'])) {
                $events[] = $ev;
            }
        }
    }
    return $events;
}

function icalParseDate(string $dt): ?array {
    $dt = trim($dt);
    if (!$dt) return null;
    if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/', $dt, $m)) {
        if (str_ends_with($dt, 'Z')) {
            $ts = gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
            $d = new DateTime('@' . $ts);
            $d->setTimezone(new DateTimeZone('Europe/Berlin'));
            return ['date' => $d->format('Y-m-d'), 'time' => $d->format('H:i')];
        }
        return ['date' => "{$m[1]}-{$m[2]}-{$m[3]}", 'time' => "{$m[4]}:{$m[5]}"];
    }
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $dt, $m)) {
        return ['date' => "{$m[1]}-{$m[2]}-{$m[3]}", 'time' => '00:00'];
    }
    return null;
}
