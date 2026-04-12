<?php
/**
 * PayPal Order Capture — REST API v2
 * POST { order_id: string, inv_id: int }
 * Returns { success, amount, invoice_paid }
 */
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../includes/auth.php';
// Require authenticated session
$uid = (int)($_SESSION['uid'] ?? 0);
$utype = $_SESSION['utype'] ?? '';
if (!$uid || !in_array($utype, ['admin', 'customer'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if (!FEATURE_PAYPAL) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'PayPal ist nicht konfiguriert.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$orderId = $body['order_id'] ?? '';
$invId = (int)($body['inv_id'] ?? 0);

if (!$orderId || !$invId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'order_id und inv_id erforderlich']);
    exit;
}

$inv = one("SELECT i.*, c.name as cname FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id WHERE i.inv_id=?", [$invId]);
if (!$inv) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Rechnung nicht gefunden']);
    exit;
}

// Get access token
$ch = curl_init(PAYPAL_BASE . '/v1/oauth2/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
    CURLOPT_USERPWD => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$tokenResp = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($tokenResp['access_token'])) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'PayPal Auth fehlgeschlagen']);
    exit;
}
$token = $tokenResp['access_token'];

// Capture order
$ch = curl_init(PAYPAL_BASE . '/v2/checkout/orders/' . urlencode($orderId) . '/capture');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => '{}',
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ],
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$captureResp = json_decode(curl_exec($ch), true);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode < 200 || $httpCode >= 300 || ($captureResp['status'] ?? '') !== 'COMPLETED') {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'PayPal Capture fehlgeschlagen', 'status' => $captureResp['status'] ?? 'unknown']);
    exit;
}

// Extract captured amount
$amount = 0;
foreach ($captureResp['purchase_units'] ?? [] as $pu) {
    foreach ($pu['payments']['captures'] ?? [] as $cap) {
        $amount += (float)($cap['amount']['value'] ?? 0);
    }
}

// Update invoice
$newRemaining = max(0, (float)$inv['remaining_price'] - $amount);
$paid = $newRemaining <= 0 ? 'yes' : 'no';
q("UPDATE invoices SET remaining_price=?, invoice_paid=? WHERE inv_id=?", [$newRemaining, $paid, $invId]);

// Record payment
try {
    q("INSERT INTO invoice_payments (invoice_id_fk, amount, payment_date, payment_method, note) VALUES (?,?,?,?,?)",
        [$invId, $amount, date('Y-m-d'), 'PayPal', 'PayPal Order: ' . $orderId]);
} catch (Exception $e) {}

audit('paypal_paid', 'invoice', $invId, "PayPal: $amount EUR, Order: $orderId");
telegramNotify("💳 <b>PayPal Zahlung!</b>\n\n📄 {$inv['invoice_number']}\n👤 {$inv['cname']}\n💶 " . number_format($amount, 2, ',', '.') . " €\n✅ " . ($paid === 'yes' ? 'Vollständig bezahlt' : 'Teilzahlung'));

echo json_encode([
    'success' => true,
    'amount' => $amount,
    'remaining' => $newRemaining,
    'invoice_paid' => $paid === 'yes',
]);
