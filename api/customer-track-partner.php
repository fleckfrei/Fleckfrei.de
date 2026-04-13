<?php
/**
 * Customer → Partner Live Tracking
 *
 * Returns the latest GPS position of the customer's currently assigned partner
 * (for RUNNING jobs, or jobs starting within the next 2 hours).
 *
 * GET /api/customer-track-partner.php
 * Session auth: must be customer.
 * Returns: { success, partner_lat?, partner_lng?, service_lat?, service_lng?, distance_km?, eta_min?, status, updated_min_ago? }
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SESSION['utype'] ?? '') !== 'customer') {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$cid = (int)$_SESSION['uid'];

// Find an active job: RUNNING, or PENDING/CONFIRMED and starting within ±2 hours
$now = date('Y-m-d H:i:s');
$job = one("
    SELECT j.*, s.title AS stitle, s.street, s.number, s.postal_code, s.city, s.lat AS s_lat, s.lng AS s_lng,
           e.display_name AS partner_name, e.profile_pic AS partner_pic, e.emp_id
    FROM jobs j
    LEFT JOIN services s ON j.s_id_fk = s.s_id
    LEFT JOIN employee e ON j.emp_id_fk = e.emp_id
    WHERE j.customer_id_fk = ?
      AND j.status = 1
      AND j.emp_id_fk IS NOT NULL
      AND (
          j.job_status = 'RUNNING'
          OR (j.job_status IN ('PENDING','CONFIRMED') AND TIMESTAMPDIFF(MINUTE, NOW(), CONCAT(j.j_date, ' ', j.j_time)) BETWEEN -30 AND 120)
      )
    ORDER BY
      FIELD(j.job_status, 'RUNNING', 'CONFIRMED', 'PENDING'),
      j.j_date, j.j_time
    LIMIT 1
", [$cid]);

if (!$job) {
    echo json_encode([
        'success' => true,
        'status'  => 'no_active_job',
    ]);
    exit;
}

// Load latest GPS position from $dbLocal (falls back silently if table missing)
global $dbLocal;
$pos = null;
try {
    $stmt = $dbLocal->prepare("SELECT lat, lng, created_at FROM gps_tracking WHERE emp_id=? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$job['emp_id']]);
    $pos = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Service coordinates — geocode on-demand via Nominatim if missing (cached forever in services.lat/lng)
$sLat = isset($job['s_lat']) ? (float)$job['s_lat'] : 0;
$sLng = isset($job['s_lng']) ? (float)$job['s_lng'] : 0;
if ((!$sLat || !$sLng) && !empty($job['street']) && !empty($job['city'])) {
    $query = trim($job['street'] . ', ' . $job['city'] . ', Germany');
    $ch = curl_init('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($query));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'FleckfreiGeocoder/1.0 (info@fleckfrei.de)',
        CURLOPT_TIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $geo = json_decode($resp, true);
    if (!empty($geo[0]['lat']) && !empty($geo[0]['lon'])) {
        $sLat = (float)$geo[0]['lat'];
        $sLng = (float)$geo[0]['lon'];
        try {
            q("UPDATE services SET lat=?, lng=?, geocoded_at=NOW() WHERE s_id=?",
              [$sLat, $sLng, $job['s_id_fk']]);
        } catch (Exception $e) {}
    }
}

// Partner bleibt anonym für Kunden — nur Status + Zeiten werden zurückgegeben
// Adresse voll zusammenbauen (Street + Nr + PLZ + Stadt)
$svcNumber = $job['number'] ?? '';
$svcPLZ = $job['postal_code'] ?? '';
$addressParts = array_filter([
    trim(($job['street'] ?? '') . ' ' . $svcNumber),
    trim($svcPLZ . ' ' . ($job['city'] ?? '')),
]);

// Kundentyp: Host/B2B = Pauschal-Modus (keine Zeit-Details), Privat = pro-h
$cust = one("SELECT customer_type FROM customer WHERE customer_id=?", [$cid]);
$isFlatRate = in_array($cust['customer_type'] ?? '', ['Airbnb','Host','Co-Host','Short-Term Rental','Booking','Company','B2B','Firma','GmbH','Business']);

// Live-Dauer berechnen (Sekunden seit start_time)
$elapsedSec = null;
if (!empty($job['start_time']) && in_array($job['job_status'], ['RUNNING','STARTED'])) {
    $startTs = strtotime($job['j_date'] . ' ' . $job['start_time']);
    $elapsedSec = max(0, time() - $startTs);
}

$out = [
    'success'        => true,
    'status'         => $job['job_status'],
    'job_id'         => (int)$job['j_id'],
    'service_title'  => $job['stitle'] ?: 'Reinigung',
    'service_lat'    => $sLat ?: null,
    'service_lng'    => $sLng ?: null,
    'service_address'=> implode(', ', $addressParts),
    'job_date'       => $job['j_date'],
    'job_time'       => substr($job['j_time'] ?? '', 0, 5),
    'job_started_at' => !empty($job['start_time']) ? substr($job['start_time'], 0, 5) : null,
    'job_ended_at'   => !empty($job['end_time']) ? substr($job['end_time'], 0, 5) : null,
    'is_started'     => in_array($job['job_status'], ['RUNNING','STARTED']) && !empty($job['start_time']),
    'is_flat_rate'   => $isFlatRate,      // Pauschal-Kunde: weniger Details
    'elapsed_seconds'=> $elapsedSec,       // Sekunden seit Start (für Live-Counter)
    'planned_hours'  => (float)($job['j_hours'] ?? 0),
];

if ($pos) {
    $out['partner_lat'] = (float)$pos['lat'];
    $out['partner_lng'] = (float)$pos['lng'];
    $out['updated_at']  = $pos['created_at'];
    $out['updated_min_ago'] = (int) round((time() - strtotime($pos['created_at'])) / 60);

    // Distance Haversine
    if ($sLat && $sLng) {
        $r = 6371; // km
        $dLat = deg2rad($sLat - (float)$pos['lat']);
        $dLng = deg2rad($sLng - (float)$pos['lng']);
        $a = sin($dLat/2)**2 + cos(deg2rad((float)$pos['lat'])) * cos(deg2rad($sLat)) * sin($dLng/2)**2;
        $distKm = round(2 * $r * atan2(sqrt($a), sqrt(1 - $a)), 2);
        $out['distance_km'] = $distKm;
        // Naive ETA: assume 30 km/h average in city (traffic + transit)
        $out['eta_min'] = (int) ceil($distKm / 30 * 60);
    }
} else {
    $out['partner_lat'] = null;
    $out['partner_lng'] = null;
}

// Stale GPS (>5 min)? Downgrade
if (($out['updated_min_ago'] ?? 0) > 5) {
    $out['gps_stale'] = true;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
