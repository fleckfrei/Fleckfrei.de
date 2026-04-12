<?php
/**
 * Fleckfrei iCal Feed — exports a customer's confirmed jobs as standard iCal.
 * Other systems (Smoobu, Airbnb host calendar, Google Calendar) can subscribe.
 *
 * Auth: token-based (no session) so external services can poll.
 * URL: /api/ical-feed.php?cid=9&token=XXX
 *
 * Token = sha256(customer_id + ical_secret) — generated from customer.ical_token
 */
require_once __DIR__ . '/../includes/config.php';

$cid = (int) ($_GET['cid'] ?? 0);
$token = $_GET['token'] ?? '';

if (!$cid || !$token) {
    http_response_code(400);
    echo "Missing cid or token";
    exit;
}

// Get customer + verify token
$customer = one("SELECT * FROM customer WHERE customer_id=? AND status=1", [$cid]);
if (!$customer) {
    http_response_code(404);
    echo "Customer not found";
    exit;
}

$expected = $customer['ical_token'] ?? null;
if (!$expected) {
    // Auto-generate on first request
    $expected = bin2hex(random_bytes(16));
    q("UPDATE customer SET ical_token=? WHERE customer_id=?", [$expected, $cid]);
}

if (!hash_equals($expected, $token)) {
    http_response_code(403);
    echo "Invalid token";
    exit;
}

// Fetch confirmed/upcoming jobs
$jobs = all("
    SELECT j.j_id, j.j_date, j.j_time, j.j_hours, j.address, j.job_status, j.created_at,
           j.no_people, j.code_door, j.is_check_only,
           s.title AS stitle
    FROM jobs j
    LEFT JOIN services s ON j.s_id_fk = s.s_id
    WHERE j.customer_id_fk = ?
      AND j.status = 1
      AND j.job_status NOT IN ('CANCELLED','PENDING')
      AND j.j_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ORDER BY j.j_date ASC
", [$cid]);

// Build iCal
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="fleckfrei.ics"');

function icalEscape(string $s): string {
    return str_replace([',', ';', "\n", "\\"], ['\,', '\;', '\\n', '\\\\'], $s);
}
function icalDate(string $d, ?string $t): string {
    if ($t) {
        return date('Ymd\THis', strtotime("$d $t"));
    }
    return date('Ymd', strtotime($d));
}

$out = "BEGIN:VCALENDAR\r\n";
$out .= "VERSION:2.0\r\n";
$out .= "PRODID:-//Fleckfrei//Cleaning Calendar//DE\r\n";
$out .= "X-WR-CALNAME:Fleckfrei Reinigungstermine\r\n";
$out .= "X-WR-TIMEZONE:Europe/Berlin\r\n";
$out .= "X-PUBLISHED-TTL:PT15M\r\n";

foreach ($jobs as $j) {
    $hours = max(MIN_HOURS, $j['j_hours'] ?: 3);
    $startStamp = strtotime($j['j_date'] . ' ' . $j['j_time']);
    $endStamp = $startStamp + ($hours * 3600);
    $title = $j['is_check_only'] ? '🔍 Fleckfrei Kontrolle' : '🧹 Fleckfrei Reinigung';
    $title .= ' — ' . ($j['stitle'] ?: 'Service');
    $description = "Reinigungstermin gebucht über Fleckfrei.\\n";
    $description .= "Status: " . ($j['job_status'] ?? '') . "\\n";
    if ($j['code_door']) $description .= "Türcode: " . $j['code_door'] . "\\n";
    $description .= "Personen: " . ($j['no_people'] ?: 1);

    $out .= "BEGIN:VEVENT\r\n";
    $out .= "UID:flk-job-{$j['j_id']}@fleckfrei.de\r\n";
    $out .= "DTSTAMP:" . date('Ymd\THis\Z', strtotime($j['created_at'] ?: 'now')) . "\r\n";
    $out .= "DTSTART;TZID=Europe/Berlin:" . date('Ymd\THis', $startStamp) . "\r\n";
    $out .= "DTEND;TZID=Europe/Berlin:" . date('Ymd\THis', $endStamp) . "\r\n";
    $out .= "SUMMARY:" . icalEscape($title) . "\r\n";
    $out .= "DESCRIPTION:" . icalEscape($description) . "\r\n";
    if ($j['address']) {
        $out .= "LOCATION:" . icalEscape($j['address']) . "\r\n";
    }
    $out .= "STATUS:CONFIRMED\r\n";
    $out .= "TRANSP:OPAQUE\r\n";
    $out .= "URL:https://app.fleckfrei.de/customer/jobs.php\r\n";
    $out .= "END:VEVENT\r\n";
}

$out .= "END:VCALENDAR\r\n";
echo $out;
