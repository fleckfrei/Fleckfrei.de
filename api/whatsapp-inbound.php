<?php
/**
 * WhatsApp Inbound Webhook — n8n → Fleckfrei
 *
 * Called by n8n when a WhatsApp message arrives for the business number.
 * Matches the sender phone → customer, writes to `messages` table, notifies admin via Telegram.
 *
 * POST /api/whatsapp-inbound.php?key=<API_KEY>
 * {
 *   "from":       "+4915201234567",      // sender phone (E.164)
 *   "name":       "Max Mustermann",      // optional display name
 *   "text":       "Hallo, wann ist mein nächster Termin?",
 *   "media_url":  "https://...",          // optional attachment URL
 *   "wa_message_id": "wamid.xxx",         // optional, for idempotency
 *   "timestamp":  1712345678              // optional, unix ts
 * }
 *
 * Returns: { success, action: matched|new_lead|duplicate, customer_id?, msg_id? }
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

// Auth
$key = $_GET['key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!hash_equals(API_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid key']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];

$from = trim($body['from'] ?? '');
$text = trim($body['text'] ?? '');
$name = trim($body['name'] ?? '');
$mediaUrl = trim($body['media_url'] ?? '');
$waMsgId = trim($body['wa_message_id'] ?? '');
$ts = isset($body['timestamp']) ? (int)$body['timestamp'] : time();

if (!$from || (!$text && !$mediaUrl)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing from/text']);
    exit;
}

// Idempotency: skip duplicates by wa_message_id (stored in subject prefix)
if ($waMsgId) {
    $dup = one("SELECT msg_id FROM messages WHERE subject LIKE ? LIMIT 1", ['wa:' . $waMsgId . '%']);
    if ($dup) {
        echo json_encode(['success' => true, 'action' => 'duplicate', 'msg_id' => $dup['msg_id']]);
        exit;
    }
}

// Normalize phone for matching — keep only digits, match on ends
$digitsOnly = preg_replace('/\D+/', '', $from);
$lastDigits = substr($digitsOnly, -9); // match last 9 digits to catch various formats

$customer = null;
if ($lastDigits) {
    // Match any customer (active first, then archived) — we need to recognise the sender regardless of status
    $customer = one(
        "SELECT customer_id, name, customer_type, status FROM customer
         WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', '') LIKE ?
         ORDER BY status DESC, customer_id DESC LIMIT 1",
        ['%' . $lastDigits]
    );
}

$now = date('Y-m-d H:i:s', $ts);
$subject = $waMsgId ? 'wa:' . $waMsgId : 'wa:inbound';
$content = $text ?: '[Anhang]';

// Attachment: save full JSON of media info for downstream processing
$attachment = $mediaUrl ? $mediaUrl : null;

if ($customer) {
    // Matched customer → insert as customer→admin message
    q("INSERT INTO messages (sender_id, sender_type, sender_name, recipient_id, recipient_type, subject, message, channel, attachment, created_at)
       VALUES (?, 'customer', ?, NULL, 'admin', ?, ?, 'whatsapp', ?, ?)",
       [(int)$customer['customer_id'], $name ?: $customer['name'], $subject, $content, $attachment, $now]);
    $msgId = (int) lastInsertId();

    if (function_exists('telegramNotify')) {
        telegramNotify("💬 <b>WhatsApp von " . e($customer['name']) . "</b>\n\n" . e(mb_substr($content, 0, 200)));
    }

    echo json_encode([
        'success'     => true,
        'action'      => 'matched',
        'customer_id' => (int)$customer['customer_id'],
        'msg_id'      => $msgId,
    ]);
    exit;
}

// Unknown sender → create a lead entry (if leads table exists) + log as system message
$leadId = null;
try {
    q("INSERT INTO leads (source, phone, name, message, status, created_at)
       VALUES ('whatsapp', ?, ?, ?, 'new', NOW())",
      [$from, $name ?: $from, $content]);
    $leadId = (int) lastInsertId();
} catch (Exception $e) {
    // leads table might not exist — ignore
}

// Always log unknown senders as system message so admin sees them
q("INSERT INTO messages (sender_id, sender_type, sender_name, recipient_id, recipient_type, subject, message, channel, attachment, created_at)
   VALUES (0, 'system', ?, NULL, 'admin', ?, ?, 'whatsapp', ?, ?)",
   [$name ?: $from, $subject, '[Unbekannter Absender: ' . $from . '] ' . $content, $attachment, $now]);
$msgId = (int) lastInsertId();

if (function_exists('telegramNotify')) {
    telegramNotify("💬 <b>WhatsApp (unbekannt)</b>\n\nVon: " . e($from) . ($name ? " · " . e($name) : '') . "\n\n" . e(mb_substr($content, 0, 200)));
}

echo json_encode([
    'success' => true,
    'action'  => 'new_lead',
    'lead_id' => $leadId,
    'msg_id'  => $msgId,
]);
