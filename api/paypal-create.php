<?php
/**
 * PayPal Order Creation — REST API v2
 * POST { inv_id: int }
 * Returns { success, order_id, approval_url }
 */
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../includes/auth.php';
// Require authenticated session (customer paying their invoice)
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
$invId = (int)($body['inv_id'] ?? 0);

if (!$invId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Rechnungs-ID fehlt']);
    exit;
}

$inv = one("SELECT i.*, c.name as cname FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id WHERE i.inv_id=?", [$invId]);
if (!$inv) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Rechnung nicht gefunden']);
    exit;
}
if ($inv['invoice_paid'] === 'yes' || (float)$inv['remaining_price'] <= 0) {
    echo json_encode(['success' => false, 'error' => 'Rechnung ist bereits bezahlt']);
    exit;
}

// Get PayPal access token
$ch = curl_init(PAYPAL_BASE . '/v1/oauth2/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
    CURLOPT_USERPWD => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: de_DE'],
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$tokenResp = json_decode(curl_exec($ch), true);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || empty($tokenResp['access_token'])) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'PayPal Auth fehlgeschlagen']);
    exit;
}
$token = $tokenResp['access_token'];

// Create order
$amount = number_format((float)$inv['remaining_price'], 2, '.', '');
$orderData = [
    'intent' => 'CAPTURE',
    'purchase_units' => [[
        'reference_id' => 'INV-' . $invId,
        'description' => 'Rechnung ' . ($inv['invoice_number'] ?? '#' . $invId) . ' — ' . SITE,
        'amount' => [
            'currency_code' => 'EUR',
            'value' => $amount,
        ],
        'custom_id' => (string)$invId,
    ]],
    'application_context' => [
        'brand_name' => SITE,
        'locale' => 'de-DE',
        'shipping_preference' => 'NO_SHIPPING',
        'user_action' => 'PAY_NOW',
        'return_url' => 'https://app.' . SITE_DOMAIN . '/customer/invoices.php?paypal=success',
        'cancel_url' => 'https://app.' . SITE_DOMAIN . '/customer/invoices.php?paypal=cancel',
    ],
];

$ch = curl_init(PAYPAL_BASE . '/v2/checkout/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($orderData),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'Prefer: return=representation',
    ],
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$orderResp = json_decode(curl_exec($ch), true);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode < 200 || $httpCode >= 300 || empty($orderResp['id'])) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'PayPal Order Erstellung fehlgeschlagen', 'debug' => $orderResp['message'] ?? '']);
    exit;
}

// Find approval URL
$approvalUrl = '';
foreach ($orderResp['links'] ?? [] as $link) {
    if ($link['rel'] === 'approve') { $approvalUrl = $link['href']; break; }
}

audit('paypal_order', 'invoice', $invId, "PayPal Order: {$orderResp['id']}, {$amount} EUR");

echo json_encode([
    'success' => true,
    'order_id' => $orderResp['id'],
    'approval_url' => $approvalUrl,
]);
