<?php
/**
 * Batch-Translate API für JS DOM-Translator.
 * POST {texts: ["...", ...], lang: "de"}
 * Response: {translations: ["...", ...]}
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/translate-helper.php';

if (empty($_SESSION['uid'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$texts = is_array($body['texts'] ?? null) ? $body['texts'] : [];
$lang = preg_replace('/[^a-z]/', '', substr($body['lang'] ?? 'de', 0, 5));

$out = [];
foreach (array_slice($texts, 0, 50) as $t) {
    $t = (string)$t;
    if (strlen($t) > 4000) { $out[] = $t; continue; }
    $out[] = autoTranslate($t, $lang);
}

echo json_encode(['translations' => $out], JSON_UNESCAPED_UNICODE);
