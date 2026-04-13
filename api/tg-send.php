<?php
/**
 * Quick Telegram-Send endpoint (admin only) — schickt Text an Hermes-Bot.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/llm-keys.php';

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$text = trim($body['text'] ?? '');
if (!$text) {
    echo json_encode(['ok' => false, 'error' => 'no text']);
    exit;
}
$text = substr($text, 0, 4000);

$ch = curl_init('https://api.telegram.org/bot' . HERMES_BOT_TOKEN . '/sendMessage');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'chat_id' => HERMES_BOT_CHAT_ID,
    'text' => $text,
    'parse_mode' => 'Markdown',
]));
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$resp = curl_exec($ch);
curl_close($ch);

$d = json_decode($resp, true);
echo json_encode(['ok' => !empty($d['ok']), 'error' => $d['description'] ?? null]);
