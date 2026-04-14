<?php
/**
 * Public JSON-Feed der Plattform-Services — für Apps Script Sheet-Pull (Option A).
 * Auth via ?key=<API_KEY>.
 * Returns: {updated_at, services: [{s_id, title, wa_keyword, net, gross, unit, tax, min_amount, ...}]}
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../includes/config.php';

$key = $_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!hash_equals(API_KEY, $key)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

// Alle Services (inkl. customer-spezifische) für vollen Sync
$only = $_GET['only'] ?? 'all'; // all | platform | active
$where = "status=1";
if ($only === 'platform') $where .= " AND customer_id_fk=0";

$svcs = all("SELECT s_id, title, wa_keyword, price, total_price, tax_percentage, unit, is_cleaning, customer_id_fk, street, number, postal_code, city, country FROM services WHERE $where ORDER BY customer_id_fk, s_id");

$rows = [];
foreach ($svcs as $s) {
    $rows[] = [
        'service_id'   => (int)$s['s_id'],
        'title'        => $s['title'],
        'unit'         => $s['unit'] ?: 'hour',
        'net'          => round((float)$s['price'], 2),
        'gross'        => round((float)$s['total_price'], 2),
        'tax_percent'  => (int)($s['tax_percentage'] ?: 19),
        'wa_keyword'   => $s['wa_keyword'] ?: '',
        'is_cleaning'  => (int)$s['is_cleaning'],
        'is_platform'  => ((int)$s['customer_id_fk'] === 0) ? 1 : 0,
        'address'      => trim(($s['street'] ? $s['street'].' '.$s['number'].', ' : '').$s['postal_code'].' '.$s['city']),
    ];
}

echo json_encode([
    'updated_at' => date('c'),
    'count'      => count($rows),
    'services'   => $rows,
], JSON_UNESCAPED_UNICODE);
