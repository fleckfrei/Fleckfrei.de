<?php
/**
 * iCal Export — Token-based, no auth required
 * URL: /api/ical-export.php?token=xxx (all jobs)
 * URL: /api/ical-export.php?token=xxx&customer=123 (customer-specific)
 */
require_once __DIR__ . '/../includes/config.php';

// Token validation
$token = $_GET['token'] ?? '';
$customerId = (int)($_GET['customer'] ?? 0);

if (!$token) {
    http_response_code(401);
    die('Unauthorized: Missing token');
}

// Check against global ICAL_TOKEN or customer-specific ical_token
$authorized = false;
if (defined('ICAL_TOKEN') && hash_equals(ICAL_TOKEN, $token)) {
    $authorized = true;
} elseif ($customerId) {
    $customerToken = val("SELECT ical_token FROM customer WHERE customer_id=? AND status=1", [$customerId]);
    if ($customerToken && hash_equals($customerToken, $token)) {
        $authorized = true;
    }
} else {
    // Try to find any customer with this token
    $cust = one("SELECT customer_id FROM customer WHERE ical_token=? AND status=1", [$token]);
    if ($cust) {
        $authorized = true;
        $customerId = (int)$cust['customer_id'];
    }
}

if (!$authorized) {
    http_response_code(403);
    die('Forbidden: Invalid token');
}

// Build query
$sql = "SELECT j.j_id, j.j_date, j.j_time, j.j_hours, j.job_status, j.address,
        c.name as cname, s.title as stitle,
        e.name as ename, e.surname as esurname
        FROM jobs j
        LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
        LEFT JOIN services s ON j.s_id_fk=s.s_id
        LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
        WHERE j.status=1 AND j.j_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
$params = [];

if ($customerId) {
    $sql .= " AND j.customer_id_fk=?";
    $params[] = $customerId;
}

$sql .= " ORDER BY j.j_date, j.j_time";
$jobs = all($sql, $params);

// Output iCal
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="fleckfrei-jobs.ics"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$domain = 'app.' . SITE_DOMAIN;
$calName = $customerId ? (SITE . ' — Kunde #' . $customerId) : (SITE . ' — Alle Jobs');

$statusLabels = [
    'PENDING' => 'Offen',
    'CONFIRMED' => 'Bestaetigt',
    'RUNNING' => 'Laufend',
    'STARTED' => 'Gestartet',
    'COMPLETED' => 'Erledigt',
    'CANCELLED' => 'Storniert',
];

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//" . SITE . "//Jobs//DE\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo "X-WR-CALNAME:" . icalEscape($calName) . "\r\n";
echo "X-WR-TIMEZONE:Europe/Berlin\r\n";

foreach ($jobs as $j) {
    $date = str_replace('-', '', $j['j_date']);
    $time = str_replace(':', '', substr($j['j_time'] ?? '00:00', 0, 5)) . '00';
    $dtStart = $date . 'T' . $time;

    // Calculate DTEND from j_hours
    $hours = (float)($j['j_hours'] ?? 1);
    $startTs = strtotime($j['j_date'] . ' ' . ($j['j_time'] ?? '00:00'));
    $endTs = $startTs + ($hours * 3600);
    $dtEnd = date('Ymd\THis', $endTs);

    $summary = trim(($j['cname'] ?? 'Kunde') . ' — ' . ($j['stitle'] ?? 'Job'));

    $descParts = [];
    if (!empty($j['address'])) $descParts[] = 'Adresse: ' . $j['address'];
    $empName = trim(($j['ename'] ?? '') . ' ' . ($j['esurname'] ?? ''));
    if ($empName) $descParts[] = 'Partner: ' . $empName;
    $statusLabel = $statusLabels[$j['job_status'] ?? ''] ?? ($j['job_status'] ?? '');
    $descParts[] = 'Status: ' . $statusLabel;
    $description = implode('\\n', $descParts);

    $uid = $j['j_id'] . '@' . $domain;
    $stamp = gmdate('Ymd\THis\Z');

    echo "BEGIN:VEVENT\r\n";
    echo "UID:" . $uid . "\r\n";
    echo "DTSTAMP:" . $stamp . "\r\n";
    echo "DTSTART;TZID=Europe/Berlin:" . $dtStart . "\r\n";
    echo "DTEND;TZID=Europe/Berlin:" . $dtEnd . "\r\n";
    echo "SUMMARY:" . icalEscape($summary) . "\r\n";
    echo "DESCRIPTION:" . icalEscape($description) . "\r\n";
    if (!empty($j['address'])) {
        echo "LOCATION:" . icalEscape($j['address']) . "\r\n";
    }
    echo "STATUS:" . (($j['job_status'] ?? '') === 'CANCELLED' ? 'CANCELLED' : 'CONFIRMED') . "\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";

/**
 * Escape text for iCal format
 */
function icalEscape(string $text): string {
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace("\n", '\\n', $text);
    $text = str_replace("\r", '', $text);
    $text = str_replace(',', '\\,', $text);
    $text = str_replace(';', '\\;', $text);
    return $text;
}
