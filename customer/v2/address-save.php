<?php
// AJAX endpoint: save a new address to customer_address, return the created row as JSON.
require_once __DIR__ . '/../../includes/auth.php';
requireCustomer();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];

$cid = me()['id'];
$street = trim($data['street'] ?? '');
$number = trim($data['number'] ?? '');
$postal = trim($data['postal_code'] ?? '');
$city = trim($data['city'] ?? '');
$country = trim($data['country'] ?? 'Deutschland');
$type = trim($data['address_for'] ?? 'Wohnung');

if ($street === '' || $city === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Straße und Stadt sind Pflichtfelder.']);
    exit;
}

try {
    q("INSERT INTO customer_address (street, number, postal_code, city, country, address_for, customer_id_fk) VALUES (?, ?, ?, ?, ?, ?, ?)",
      [$street, $number, $postal, $city, $country, $type, $cid]);
    $id = (int) lastInsertId();
    audit('create', 'customer_address', $id, "Customer added address via booking form: $type");

    // Return the created row — booking form will add it to its dropdown and select it
    $full = trim("$street $number, $postal $city");
    echo json_encode([
        'success' => true,
        'data' => [
            'ca_id' => $id,
            'full' => $full,
            'address_for' => $type,
            'street' => $street,
            'number' => $number,
            'postal_code' => $postal,
            'city' => $city,
            'country' => $country,
        ],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Datenbank-Fehler: ' . $e->getMessage()]);
}
