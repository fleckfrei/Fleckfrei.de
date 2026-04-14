<?php
/**
 * Public coupon validator — POST {code, subtotal, service_type, email?}
 * Unified with vouchers table (new system). Backward-compatible response for book.php.
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$code = strtoupper(trim($body['code'] ?? ''));
$subtotal = (float)($body['subtotal'] ?? 0);
$serviceType = $body['service_type'] ?? 'hc';
$email = strtolower(trim($body['email'] ?? ''));
$hours = max(1, (float)($body['hours'] ?? $body['duration'] ?? 2));

if (!$code) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Code erforderlich']);
    exit;
}

// Map service_type → customer_type (hc=private Home-Care, str=host, bs=b2b office)
$typeMap = ['hc' => 'private', 'str' => 'host', 'bs' => 'b2b'];
$custType = $typeMap[$serviceType] ?? '';

$v = one("SELECT * FROM vouchers WHERE code=? AND active=1 LIMIT 1", [$code]);
if (!$v) {
    echo json_encode(['success' => false, 'valid' => false, 'error' => '❌ Code ungültig oder abgelaufen']);
    exit;
}

$today = date('Y-m-d');
if (!empty($v['valid_from']) && $v['valid_from'] > $today) {
    echo json_encode(['success' => false, 'valid' => false, 'error' => '❌ Code noch nicht gültig (ab ' . $v['valid_from'] . ')']);
    exit;
}
if (!empty($v['valid_until']) && $v['valid_until'] < $today) {
    echo json_encode(['success' => false, 'valid' => false, 'error' => '❌ Code abgelaufen (am ' . $v['valid_until'] . ')']);
    exit;
}
if ((int)$v['max_uses'] > 0 && (int)$v['used_count'] >= (int)$v['max_uses']) {
    echo json_encode(['success' => false, 'valid' => false, 'error' => '❌ Code maximal ausgenutzt']);
    exit;
}
if ($v['customer_type'] !== '' && $custType !== '' && $v['customer_type'] !== $custType) {
    $labels = ['private'=>'Privat','host'=>'Host/STR','b2b'=>'B2B/Office'];
    echo json_encode(['success' => false, 'valid' => false, 'error' => '❌ Code gilt nur für ' . ($labels[$v['customer_type']] ?? $v['customer_type'])]);
    exit;
}
if ((float)$v['min_amount'] > 0 && $subtotal < (float)$v['min_amount']) {
    echo json_encode(['success' => false, 'valid' => false, 'error' => '❌ Mindest-Bestellwert: ' . number_format($v['min_amount'],2,',','.') . '€']);
    exit;
}
if ((int)$v['max_per_customer'] > 0 && $email !== '') {
    $usedByEmail = (int)val("SELECT COUNT(*) FROM voucher_redemptions WHERE voucher_id_fk=? AND customer_email=?", [$v['v_id'], $email]);
    if ($usedByEmail >= (int)$v['max_per_customer']) {
        echo json_encode(['success' => false, 'valid' => false, 'error' => '❌ Code bereits eingelöst']);
        exit;
    }
}

// Calculate discount
$discount = 0;
switch ($v['type']) {
    case 'percent': $discount = round($subtotal * ((float)$v['value'] / 100), 2); break;
    case 'fixed':   $discount = min((float)$v['value'], $subtotal); break;
    case 'free':    $discount = $subtotal; break;
    case 'target':  // Admin-Zielpreis: Rabatt = Preis − Zielbetrag (nie negativ)
                    $discount = max(0, round($subtotal - (float)$v['value'], 2));
                    break;
    case 'hourly_target': // Stundenpreis-Override: neuer Preis = value × hours
                    $newTotal = (float)$v['value'] * $hours;
                    $discount = max(0, round($subtotal - $newTotal, 2));
                    break;
}
$discount = min($discount, $subtotal);
$newTotal = max(0, round($subtotal - $discount, 2));

// Map legacy discount_type for book.php compatibility
$legacyType = match($v['type']) {
    'percent' => 'percent',
    'fixed'   => 'fixed',
    'free'    => 'first-booking-free',
    'target'  => 'override-price',
    default   => $v['type']
};

echo json_encode([
    'success'         => true,
    'valid'           => true,
    'code'            => $v['code'],
    'description'     => $v['description'],
    'discount_type'   => $legacyType,
    'discount_value'  => (float)$v['value'],
    'discount_amount' => $discount,
    'subtotal'        => $subtotal,
    'new_total'       => $newTotal,
    'message'         => '✓ ' . ($v['description'] ?: $v['code']) . ' · du sparst ' . number_format($discount, 2, ',', '.') . '€',
    'expires'         => $v['valid_until'] ?? null,
]);
