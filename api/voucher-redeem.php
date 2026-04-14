<?php
header('Content-Type: application/json');
$allowedOrigins = ['https://app.fleckfrei.de', 'https://fleckfrei.de', 'https://app.la-renting.de'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) { header('Access-Control-Allow-Origin: ' . $origin); }
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../includes/config.php';

function out($ok, $data = []) { echo json_encode(['success' => $ok] + $data); exit; }

$payload = json_decode(file_get_contents('php://input'), true) ?: $_REQUEST;

$mode    = $payload['mode']         ?? 'check';            // check | apply
$code    = strtoupper(trim($payload['code'] ?? ''));
$email   = strtolower(trim($payload['email'] ?? ''));
$amount  = (float)($payload['amount'] ?? 0);                // gross booking amount
$ctype   = $payload['customer_type'] ?? '';                 // private | host | b2b
$bookingId = isset($payload['booking_id']) ? (int)$payload['booking_id'] : null;
$invoiceId = isset($payload['invoice_id']) ? (int)$payload['invoice_id'] : null;
$customerId = isset($payload['customer_id']) ? (int)$payload['customer_id'] : null;

if ($code === '') out(false, ['error' => 'NO_CODE', 'message' => 'Bitte Code eingeben.']);

$v = q("SELECT * FROM vouchers WHERE code=? LIMIT 1", [$code])->fetch();
if (!$v) out(false, ['error' => 'NOT_FOUND', 'message' => 'Code ungültig.']);
if ((int)$v['active'] !== 1) out(false, ['error' => 'INACTIVE', 'message' => 'Code deaktiviert.']);

$today = date('Y-m-d');
if ($v['valid_from']  && $v['valid_from']  > $today) out(false, ['error' => 'NOT_YET', 'message' => 'Code noch nicht gültig.']);
if ($v['valid_until'] && $v['valid_until'] < $today) out(false, ['error' => 'EXPIRED', 'message' => 'Code abgelaufen.']);

if ((int)$v['max_uses'] > 0 && (int)$v['used_count'] >= (int)$v['max_uses']) {
    out(false, ['error' => 'EXHAUSTED', 'message' => 'Code aufgebraucht.']);
}

if ($v['customer_type'] !== '' && $ctype !== '' && $v['customer_type'] !== $ctype) {
    out(false, ['error' => 'WRONG_TYPE', 'message' => 'Code gilt für diesen Kundentyp nicht.']);
}

if ((float)$v['min_amount'] > 0 && $amount > 0 && $amount < (float)$v['min_amount']) {
    out(false, ['error' => 'MIN_AMOUNT', 'message' => 'Mindestbestellwert ' . number_format($v['min_amount'],2,',','.') . ' € nicht erreicht.']);
}

if ((int)$v['max_per_customer'] > 0 && $email !== '') {
    $usedByEmail = (int)val("SELECT COUNT(*) FROM voucher_redemptions WHERE voucher_id_fk=? AND customer_email=?", [$v['v_id'], $email]);
    if ($usedByEmail >= (int)$v['max_per_customer']) {
        out(false, ['error' => 'PER_CUSTOMER_LIMIT', 'message' => 'Code bereits eingelöst.']);
    }
}

$discount = 0.0;
if ($v['type'] === 'percent') {
    $discount = round($amount * ((float)$v['value'] / 100), 2);
} elseif ($v['type'] === 'fixed') {
    $discount = min((float)$v['value'], $amount);
} elseif ($v['type'] === 'free') {
    $discount = $amount;
} elseif ($v['type'] === 'target') {
    // Admin-Zielpreis: Kunde zahlt max. diesen Betrag
    $discount = max(0, round($amount - (float)$v['value'], 2));
}
if ($discount < 0) $discount = 0;

if ($mode === 'apply') {
    q("INSERT INTO voucher_redemptions (voucher_id_fk, customer_email, customer_id_fk, booking_id_fk, invoice_id_fk, discount_amount) VALUES (?,?,?,?,?,?)",
       [$v['v_id'], $email, $customerId, $bookingId, $invoiceId, $discount]);
    q("UPDATE vouchers SET used_count = used_count + 1 WHERE v_id=?", [$v['v_id']]);
}

out(true, [
    'mode'      => $mode,
    'code'      => $v['code'],
    'type'      => $v['type'],
    'value'     => (float)$v['value'],
    'discount'  => $discount,
    'final'     => max(0, round($amount - $discount, 2)),
    'message'   => 'Code akzeptiert. Rabatt: ' . number_format($discount,2,',','.') . ' €',
    'voucher_id'=> (int)$v['v_id'],
]);
