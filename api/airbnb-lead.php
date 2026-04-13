<?php
/**
 * Public Lead-Collection for /airbnb-check.php landing
 * Inserts into leads table + notifies Telegram
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$name = trim($body['name'] ?? '');
$email = trim($body['email'] ?? '');
$phone = trim($body['phone'] ?? '');
$consentContact = !empty($body['consent_contact']);
$consentPrivacy = !empty($body['consent_privacy']);
$consentMarketing = !empty($body['consent_marketing']);
$analysis = $body['analysis'] ?? null;

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Gültige Email erforderlich']);
    exit;
}
if (!$consentContact || !$consentPrivacy) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Pflicht-Einverständnisse (Kontakt + Datenschutz) fehlen']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

try {
    q("CREATE TABLE IF NOT EXISTS airbnb_leads (
        al_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255),
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NULL,
        analysis_json LONGTEXT,
        consent_contact TINYINT(1) DEFAULT 0,
        consent_privacy TINYINT(1) DEFAULT 0,
        consent_marketing TINYINT(1) DEFAULT 0,
        ip VARCHAR(45),
        user_agent VARCHAR(255),
        status ENUM('new','contacted','booked','rejected') DEFAULT 'new',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Add columns if table pre-existed
    foreach (['phone VARCHAR(50) NULL','consent_contact TINYINT(1) DEFAULT 0','consent_privacy TINYINT(1) DEFAULT 0','consent_marketing TINYINT(1) DEFAULT 0'] as $col) {
        try { q("ALTER TABLE airbnb_leads ADD COLUMN $col"); } catch (Exception $e) {}
    }

    q("INSERT INTO airbnb_leads (name, email, phone, analysis_json, consent_contact, consent_privacy, consent_marketing, ip, user_agent) VALUES (?,?,?,?,?,?,?,?,?)",
      [$name, $email, $phone, json_encode($analysis), (int)$consentContact, (int)$consentPrivacy, (int)$consentMarketing, $ip, $ua]);
    $leadId = (int) lastInsertId();

    // Consent snapshot already stored in airbnb_leads columns (customer_id_fk not yet assigned)

    $plan = $analysis['plan'] ?? [];
    $title = $analysis['meta']['title'] ?? '(ohne Titel)';
    $msg = "🆕 <b>Check-Lead</b>\n\n"
         . "👤 " . ($name ?: '(ohne Name)') . "\n"
         . "📧 " . htmlspecialchars($email) . "\n"
         . ($phone ? "📱 " . htmlspecialchars($phone) . "\n" : '')
         . "🏠 " . htmlspecialchars($title) . "\n"
         . "📐 " . ($plan['apartment_type'] ?? '?') . " · " . ($plan['estimated_sqm'] ?? '?') . "qm\n"
         . "⏱ " . ($plan['recommended_hours'] ?? '?') . "h empfohlen\n"
         . "✉️ Marketing-OK: " . ($consentMarketing ? 'JA' : 'nein') . "\n\n"
         . "→ <a href=\"https://app.fleckfrei.de/admin/airbnb-analyzer.php\">Admin öffnen</a>";

    if (function_exists('telegramNotify')) telegramNotify($msg);

    // Optional: Email-Benachrichtigung an info@fleckfrei.de
    @mail('info@fleckfrei.de', 'Neuer Airbnb-Check Lead: ' . $email,
          "Name: $name\nEmail: $email\nTitel: $title\nAnalyse: " . json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
          "From: no-reply@fleckfrei.de\r\nContent-Type: text/plain; charset=utf-8");

    echo json_encode(['success' => true, 'lead_id' => $leadId]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
