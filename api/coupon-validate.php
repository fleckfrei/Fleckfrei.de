<?php
/**
 * Public coupon validator — POST {code, subtotal, service_type}
 * Returns: {valid, discount_amount, new_total, message, expires_in}
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$code = strtoupper(trim($body['code'] ?? ''));
$subtotal = (float)($body['subtotal'] ?? 0);
$serviceType = $body['service_type'] ?? 'hc';  // hc/str/bs

if (!$code) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Code erforderlich']);
    exit;
}

$c = one("SELECT * FROM coupons WHERE code=? AND is_active=1 LIMIT 1", [$code]);
if (!$c) {
    echo json_encode(['success' => false, 'valid' => false, 'error' => '❌ Code ungültig oder abgelaufen']);
    exit;
}

$now = date('Y-m-d');
if (!empty($c['valid_from']) && $c['valid_from'] > $now) {
    echo json_encode(['success' => false, 'valid' => false, 'error' => '❌ Code noch nicht gültig (ab ' . $c['valid_from'] . ')']);
    exit;
}
if (!empty($c['valid_until']) && $c['valid_until'] < $now) {
    echo json_encode(['success' => false, 'valid' => false, 'error' => '❌ Code abgelaufen (am ' . $c['valid_until'] . ')']);
    exit;
}
if ((int)$c['usage_limit'] > 0 && (int)$c['usage_count'] >= (int)$c['usage_limit']) {
    echo json_encode(['success' => false, 'valid' => false, 'error' => '❌ Code maximal ausgenutzt']);
    exit;
}
// Applies to check
$svcMap = ['hc' => 'private', 'str' => 'str', 'bs' => 'office'];
$svc = $svcMap[$serviceType] ?? $serviceType;
if ($c['applies_to'] !== 'all' && $c['applies_to'] !== $svc) {
    echo json_encode(['success' => false, 'valid' => false, 'error' => '❌ Code gilt nur für ' . $c['applies_to']]);
    exit;
}
if ($subtotal < (float)$c['min_order_eur']) {
    echo json_encode(['success' => false, 'valid' => false, 'error' => '❌ Mindest-Bestellwert: ' . $c['min_order_eur'] . '€']);
    exit;
}

// Calculate discount
$discount = 0;
switch ($c['discount_type']) {
    case 'percent':
        $discount = round($subtotal * ($c['discount_value'] / 100), 2);
        break;
    case 'fixed':
        $discount = (float)$c['discount_value'];
        break;
    case 'first-booking-free':
        $discount = $subtotal;
        break;
    case 'override-price':
        $discount = max(0, $subtotal - (float)$c['discount_value']);
        break;
}
$discount = min($discount, $subtotal);
$newTotal = round($subtotal - $discount, 2);

echo json_encode([
    'success' => true,
    'valid' => true,
    'code' => $c['code'],
    'description' => $c['description'],
    'discount_type' => $c['discount_type'],
    'discount_value' => (float)$c['discount_value'],
    'discount_amount' => $discount,
    'subtotal' => $subtotal,
    'new_total' => $newTotal,
    'message' => '✓ ' . $c['description'] . ' · du sparst ' . number_format($discount, 2, ',', '.') . '€',
    'expires' => $c['valid_until'] ?? null,
]);
