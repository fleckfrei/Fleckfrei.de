<?php
/**
 * Public endpoint — Services (Apartments/Adressen) eines Kunden anhand Prebook-Token oder Personal-Slug.
 * Wird von book.php beim Öffnen eines /p/SLUG Links abgerufen.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/config.php';

$token = trim($_GET['pb'] ?? $_GET['token'] ?? '');
if (!$token) { echo json_encode(['services'=>[], 'error'=>'no_token']); exit; }

$cid = 0;
// Prebook-Link first — falls der Token dort, könnten wir den Kunden aus email matchen
$pl = one("SELECT * FROM prebooking_links WHERE token=? LIMIT 1", [$token]);
if ($pl && !empty($pl['email'])) {
    $cRow = one("SELECT customer_id FROM customer WHERE LOWER(email)=? AND status=1 LIMIT 1", [strtolower($pl['email'])]);
    if ($cRow) $cid = (int)$cRow['customer_id'];
}
// Fallback: personal_slug
if (!$cid) {
    $cRow = one("SELECT customer_id FROM customer WHERE personal_slug=? AND status=1 LIMIT 1", [strtolower($token)]);
    if ($cRow) $cid = (int)$cRow['customer_id'];
}

if (!$cid) { echo json_encode(['services'=>[], 'error'=>'customer_not_found']); exit; }

$svcs = all("SELECT s_id, title, price, total_price, unit, street, number, postal_code, city, country,
             box_code, client_code, deposit_code, doorbell_name, wifi_name, qm, room, max_guests, lat, lng
             FROM services WHERE customer_id_fk=? AND status=1
             ORDER BY title LIMIT 50", [$cid]);

echo json_encode([
    'customer_id' => $cid,
    'services'    => array_map(function($s){
        return [
            'id'            => (int)$s['s_id'],
            'title'         => $s['title'],
            'hourly_gross'  => round((float)$s['total_price'], 2),
            'hourly_net'    => round((float)$s['price'], 2),
            'unit'          => $s['unit'] ?: 'hour',
            'address'       => trim(($s['street'] ?? '') . ' ' . ($s['number'] ?? '') . ', ' . ($s['postal_code'] ?? '') . ' ' . ($s['city'] ?? '')),
            'street'        => trim(($s['street'] ?? '') . ' ' . ($s['number'] ?? '')),
            'plz'           => $s['postal_code'] ?? '',
            'city'          => $s['city'] ?? 'Berlin',
            'doorbell_name' => $s['doorbell_name'] ?? '',
            'box_code'      => $s['box_code'] ?? '',
            'qm'            => $s['qm'] ?? '',
            'room'          => $s['room'] ?? '',
            'max_guests'    => (int)($s['max_guests'] ?? 0),
        ];
    }, $svcs),
    'count' => count($svcs),
]);
