<?php
/**
 * Chat Widget API — Embeddable on fleckfrei.de website
 * Public endpoints for anonymous and customer chat
 */
header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://fleckfrei.de', 'https://www.fleckfrei.de', 'http://localhost'];
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed) ? $origin : 'https://fleckfrei.de'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../includes/config.php';

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Session for widget (separate from admin)
session_name('fleckfrei_chat');
session_start();

try {
    $result = match(true) {
        // Start or resume chat session
        $action === 'init' => (function() {
            if (empty($_SESSION['chat_id'])) {
                $_SESSION['chat_id'] = 'web_' . bin2hex(random_bytes(8));
                $_SESSION['chat_name'] = '';
            }
            return ['chat_id' => $_SESSION['chat_id'], 'name' => $_SESSION['chat_name']];
        })(),

        // Set visitor name/email
        $action === 'identify' && $_SERVER['REQUEST_METHOD'] === 'POST' => (function() use ($body) {
            $_SESSION['chat_name'] = $body['name'] ?? '';
            $_SESSION['chat_email'] = $body['email'] ?? '';
            $_SESSION['chat_phone'] = $body['phone'] ?? '';
            return ['identified' => true];
        })(),

        // Send message from website visitor
        $action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST' => (function() use ($body) {
            global $dbLocal;
            $msg = trim($body['message'] ?? '');
            if (!$msg) throw new Exception('Empty message');
            $chatId = $_SESSION['chat_id'] ?? 'anonymous';
            $name = $_SESSION['chat_name'] ?: 'Website-Besucher';

            $dbLocal->prepare("INSERT INTO messages (sender_type,sender_id,sender_name,recipient_type,recipient_id,message,channel,created_at) VALUES ('customer',0,?,'admin',0,?,'widget',NOW())")
                ->execute([$name . ' [' . $chatId . ']', $msg]);

            // n8n webhook for KI auto-reply
            @file_get_contents('https://n8n.la-renting.com/webhook/fleckfrei-v2-message', false, stream_context_create([
                'http' => ['method'=>'POST', 'header'=>"Content-Type: application/json\r\n", 'timeout'=>3,
                    'content'=>json_encode([
                        'event' => 'widget_message',
                        'chat_id' => $chatId,
                        'from' => 'website',
                        'from_name' => $name,
                        'from_email' => $_SESSION['chat_email'] ?? '',
                        'from_phone' => $_SESSION['chat_phone'] ?? '',
                        'message' => $msg,
                    ])]
            ]));

            // Telegram notification
            telegramNotify("💬 <b>Website Chat</b>\n\n👤 $name\n📧 " . ($_SESSION['chat_email'] ?? '') . "\n💬 $msg");

            return ['sent' => true, 'chat_id' => $chatId];
        })(),

        // Get messages for this chat session
        $action === 'messages' => (function() {
            global $dbLocal;
            $chatId = $_SESSION['chat_id'] ?? '';
            if (!$chatId) return [];
            $namePattern = '%[' . $chatId . ']%';
            $msgs = $dbLocal->prepare("SELECT msg_id, sender_type, sender_name, message, created_at FROM messages WHERE
                (sender_name LIKE ? AND sender_type='customer') OR
                (recipient_type='customer' AND recipient_id=0 AND sender_type='admin' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR))
                ORDER BY created_at ASC LIMIT 50");
            $msgs->execute([$namePattern]);
            return array_map(fn($m) => [
                'id' => $m['msg_id'],
                'from' => $m['sender_type'] === 'admin' ? 'team' : 'me',
                'message' => $m['message'],
                'time' => date('H:i', strtotime($m['created_at']))
            ], $msgs->fetchAll(PDO::FETCH_ASSOC));
        })(),

        default => throw new Exception('Unknown action')
    };
    echo json_encode(['success' => true, 'data' => $result]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
