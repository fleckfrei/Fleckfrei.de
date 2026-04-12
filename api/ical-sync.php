<?php
/**
 * iCal Sync — fetches all active iCal feeds for a customer, parses VEVENTs,
 * upserts into external_events, harvests guest data into leads table.
 *
 * GET /api/ical-sync.php?cid=9        — sync one customer (admin)
 * GET /api/ical-sync.php?all=1&cron=  — sync all (cron)
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$cidParam = (int)($_GET['cid'] ?? 0);
$cronSecret = $_GET['cron'] ?? '';
$isCustomer = (($_SESSION['utype'] ?? '') === 'customer');
$isAdmin = (($_SESSION['utype'] ?? '') === 'admin');
$isCron = $cronSecret === 'flk_scrape_2026';

if (!$isAdmin && !$isCron && !$isCustomer) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Customer can only sync their own feeds
if ($isCustomer && !$isAdmin) {
    $cidParam = (int) ($_SESSION['uid'] ?? 0);
}

// ============================================================
// iCal parser (basic VEVENT extraction)
// ============================================================
function parseIcal(string $ics): array {
    $events = [];
    // Unfold lines (RFC 5545: lines starting with space/tab continue prev)
    $ics = preg_replace('/\r?\n[ \t]/', '', $ics);
    $lines = preg_split('/\r?\n/', $ics);

    $current = null;
    foreach ($lines as $line) {
        if (trim($line) === 'BEGIN:VEVENT') {
            $current = [];
            continue;
        }
        if (trim($line) === 'END:VEVENT' && is_array($current)) {
            $events[] = $current;
            $current = null;
            continue;
        }
        if ($current === null) continue;

        // Parse property line: KEY[;PARAM=VAL]:VALUE
        if (preg_match('/^([A-Z\-]+)(;[^:]*)?:(.*)$/', $line, $m)) {
            $key = $m[1];
            $value = $m[3];
            // Unescape iCal special chars
            $value = str_replace(['\,', '\;', '\n', '\\\\'], [',', ';', "\n", '\\'], $value);
            $current[$key] = $value;
        }
    }
    return $events;
}

function parseIcalDate(string $val): ?string {
    // Format: 20250411 or 20250411T140000Z
    if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $val, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    return null;
}

function parseIcalTime(string $val): ?string {
    if (preg_match('/T(\d{2})(\d{2})(\d{2})/', $val, $m)) {
        return $m[1] . ':' . $m[2] . ':' . $m[3];
    }
    return null;
}

function extractGuestName(string $summary): ?string {
    // Smoobu format: "First Last, Property Name"
    if (preg_match('/^([^,\n]+?),/', $summary, $m)) {
        $name = trim($m[1]);
        // Skip generic placeholders
        if (in_array(strtolower($name), ['reserved', 'closed', 'blocked', 'not available', 'cleaning'])) return null;
        return $name;
    }
    return null;
}

function extractFromDescription(string $desc, string $key): ?string {
    if (preg_match('/' . preg_quote($key, '/') . ':\s*([^\n]+)/i', $desc, $m)) {
        return trim($m[1]);
    }
    return null;
}

// ============================================================
// Fetch + sync
// ============================================================
$totalFeeds = 0;
$totalEvents = 0;
$totalNew = 0;
$totalLeads = 0;
$results = [];

$feedQuery = $cidParam
    ? "SELECT * FROM ical_feeds WHERE customer_id_fk = ? AND active = 1"
    : "SELECT * FROM ical_feeds WHERE active = 1";
$feedParams = $cidParam ? [$cidParam] : [];
$feeds = all($feedQuery, $feedParams);

foreach ($feeds as $feed) {
    $totalFeeds++;
    $ch = curl_init($feed['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Fleckfrei iCal Sync/1.0',
    ]);
    $ics = curl_exec($ch);
    curl_close($ch);

    if (!$ics || stripos($ics, 'BEGIN:VCALENDAR') === false) {
        $results[] = ['feed' => $feed['label'], 'error' => 'fetch failed'];
        continue;
    }

    $events = parseIcal($ics);
    $newCount = 0;
    foreach ($events as $ev) {
        $uid = $ev['UID'] ?? null;
        $start = parseIcalDate($ev['DTSTART'] ?? '');
        $end = parseIcalDate($ev['DTEND'] ?? '');
        if (!$uid || !$start) continue;

        $summary = $ev['SUMMARY'] ?? '';
        $desc = $ev['DESCRIPTION'] ?? '';
        $startTime = parseIcalTime($ev['DTSTART'] ?? '');
        $endTime = parseIcalTime($ev['DTEND'] ?? '');
        $guestName = extractGuestName($summary);
        $portal = extractFromDescription($desc, 'Portal');
        $phone = extractFromDescription($desc, 'Phone');

        try {
            q("INSERT INTO external_events (ical_feed_id, customer_id_fk, uid, start_date, end_date, start_time, end_time, summary, description, guest_name, source_platform)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE start_date=VALUES(start_date), end_date=VALUES(end_date), summary=VALUES(summary), description=VALUES(description), guest_name=VALUES(guest_name), source_platform=VALUES(source_platform), fetched_at=NOW()",
              [$feed['id'], $feed['customer_id_fk'], $uid, $start, $end ?: $start, $startTime, $endTime, $summary, $desc, $guestName, $portal ?: $feed['platform']]);
            $newCount++;
            $totalNew++;

            // Auto-suggest cleaning job on check-out date (only future, only with guest)
            $checkoutDate = $end ?: $start;
            if ($guestName && $checkoutDate >= date('Y-m-d')) {
                $evId = (int) val("SELECT ev_id FROM external_events WHERE ical_feed_id=? AND uid=?", [$feed['id'], $uid]);
                if ($evId) {
                    try {
                        q("INSERT IGNORE INTO job_suggestions (customer_id_fk, external_event_id, suggested_date, suggested_time, property_label, guest_name, source_platform, trigger_type, status)
                           VALUES (?, ?, ?, '11:00:00', ?, ?, ?, 'checkout', 'pending')",
                          [$feed['customer_id_fk'], $evId, $checkoutDate, $feed['label'], $guestName, $portal ?: $feed['platform']]);
                    } catch (Exception $e) {}
                }
            }

            // Harvest into leads table for OSINT/marketing — only future bookings, only with guest name
            if ($guestName && $start >= date('Y-m-d')) {
                $leadEmail = null;
                $leadPhone = $phone ?: null;
                $leadNotes = "Portal: " . ($portal ?: 'iCal') . " · " . $start . " - " . ($end ?: $start);
                try {
                    q("INSERT IGNORE INTO leads (source, source_url, category, name, phone, city, notes, raw_snippet, status)
                       VALUES (?, ?, 'airbnb', ?, ?, 'Berlin', ?, ?, 'new')",
                      ['ical_' . ($portal ?: 'smoobu'), 'ical://' . $feed['id'] . '/' . $uid, $guestName, $leadPhone, $leadNotes, substr($desc, 0, 500)]);
                    if (q("SELECT ROW_COUNT()", [])->fetchColumn() > 0) $totalLeads++;
                } catch (Exception $e) {}
            }
        } catch (Exception $e) {
            // skip
        }
    }
    q("UPDATE ical_feeds SET last_sync = NOW() WHERE id = ?", [$feed['id']]);
    $results[] = ['feed' => $feed['label'], 'platform' => $feed['platform'], 'events' => count($events), 'new' => $newCount];
    $totalEvents += count($events);
}

if (function_exists('audit')) {
    audit('sync', 'ical_feeds', 0, "$totalFeeds feeds → $totalEvents events ($totalNew upserted, $totalLeads new leads)");
}

echo json_encode([
    'success' => true,
    'synced_at' => date('Y-m-d H:i:s'),
    'feeds_count' => $totalFeeds,
    'events_count' => $totalEvents,
    'upserted' => $totalNew,
    'new_leads' => $totalLeads,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
