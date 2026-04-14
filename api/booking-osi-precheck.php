<?php
/**
 * Background OSI-Precheck for incoming bookings.
 * Async: runs Holehe (email) + Phoneinfoga (tel) on VPS, logs to booking_osi_log.
 * Fire-and-forget from /book.php submit. Non-blocking.
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

// Allow long-running (OSI takes 15-30s)
@set_time_limit(60);
ignore_user_abort(true);

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$name = trim($body['name'] ?? '');
$email = strtolower(trim($body['email'] ?? ''));
$phone = preg_replace('~[^\d+]~', '', $body['phone'] ?? '');

if (!$email && !$phone) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

// Flush response early (fire-and-forget)
echo json_encode(['success' => true, 'queued' => true]);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else flush();

$apiKey = defined('API_KEY') ? API_KEY : '';
$vps = 'http://89.116.22.185:8900';
$findings = ['name' => $name, 'email' => $email, 'phone' => $phone];

if ($email) {
    $ch = curl_init("$vps/holehe");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_POST=>1, CURLOPT_TIMEOUT=>25,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-API-Key: '.$apiKey],
        CURLOPT_POSTFIELDS=>json_encode(['email'=>$email])]);
    $r = curl_exec($ch); curl_close($ch);
    $findings['holehe'] = $r ? (json_decode($r, true) ?: ['raw' => substr($r, 0, 500)]) : null;
}
if ($phone) {
    $ch = curl_init("$vps/phoneinfoga");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_POST=>1, CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-API-Key: '.$apiKey],
        CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone])]);
    $r = curl_exec($ch); curl_close($ch);
    $findings['phoneinfoga'] = $r ? (json_decode($r, true) ?: ['raw' => substr($r, 0, 500)]) : null;
}

// Store in DB for admin review
try {
    q("CREATE TABLE IF NOT EXISTS booking_osi_log (
        bo_id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255), phone VARCHAR(50), name VARCHAR(255),
        findings_json LONGTEXT,
        ip VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email (email), INDEX idx_phone (phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    q("INSERT INTO booking_osi_log (email, phone, name, findings_json, ip) VALUES (?,?,?,?,?)",
      [$email, $phone, $name, json_encode($findings), $_SERVER['REMOTE_ADDR'] ?? null]);
} catch (Exception $e) { /* silent */ }
