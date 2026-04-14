<?php
/**
 * Public customer lookup by email — returns address for auto-fill in booking flow.
 * Rate-limited per IP (max 10 lookups / minute).
 * Returns only address fields + name — NOT phone or other emails.
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

$email = strtolower(trim($_GET['email'] ?? ''));
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['found' => false]); exit;
}

// Rate-limit via local rate_limit_log table
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
try {
    $db->exec("CREATE TABLE IF NOT EXISTS rate_limit_log (
        rl_id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        endpoint VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_ep (ip, endpoint, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $recent = (int)val("SELECT COUNT(*) FROM rate_limit_log WHERE ip=? AND endpoint='customer-lookup' AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)", [$ip]);
    if ($recent >= 10) {
        http_response_code(429);
        echo json_encode(['found' => false, 'error' => 'rate_limit']); exit;
    }
    q("INSERT INTO rate_limit_log (ip, endpoint) VALUES (?, 'customer-lookup')", [$ip]);
    // housekeeping: delete rows older than 1h
    try { $db->exec("DELETE FROM rate_limit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"); } catch (Exception $e) {}
} catch (Exception $e) {}

$c = one("SELECT customer_id, name, surname, customer_type FROM customer WHERE email=? AND status=1 LIMIT 1", [$email]);
if (!$c) {
    echo json_encode(['found' => false]); exit;
}

// Prefer customer_address (most recent), else fall back to latest job address
$addr = one("SELECT street, number, postal_code, city, country FROM customer_address WHERE customer_id_fk=? ORDER BY ca_id DESC LIMIT 1", [$c['customer_id']]);

$job = null;
if (!$addr) {
    $job = one("SELECT address FROM jobs WHERE customer_id_fk=? AND status=1 AND address<>'' ORDER BY j_date DESC LIMIT 1", [$c['customer_id']]);
}

$res = [
    'found'          => true,
    'name'           => trim(($c['name'] ?? '') . ' ' . ($c['surname'] ?? '')),
    'customer_type'  => $c['customer_type'] ?: 'private',
    'street'         => $addr['street'] ?? '',
    'number'         => $addr['number'] ?? '',
    'plz'            => $addr['postal_code'] ?? '',
    'city'           => $addr['city'] ?? '',
    'country'        => $addr['country'] ?? 'Deutschland',
    'raw_address'    => $job['address'] ?? '',
];
echo json_encode($res);
