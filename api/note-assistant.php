<?php
/**
 * AI Note Assistant — helps customer draft a professional invoice note/complaint/correction
 *
 * POST /api/note-assistant.php
 * Body: { type: 'notiz'|'korrektur'|'einwand', context: 'user draft', invoice_number: '...' }
 * Returns: { success, draft: 'formulierter Text' }
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/llm-helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['utype'] ?? '') !== 'customer') {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];

$type = in_array($body['type'] ?? '', ['notiz','korrektur','einwand'], true) ? $body['type'] : 'notiz';
$context = trim(mb_substr($body['context'] ?? '', 0, 800));
$invoiceNumber = mb_substr($body['invoice_number'] ?? '', 0, 50);

if (empty($context)) {
    echo json_encode(['error' => 'Bitte Stichworte eingeben']);
    exit;
}

$typeLabel = [
    'notiz' => 'allgemeine Notiz / Frage',
    'korrektur' => 'Korrektur-Anfrage',
    'einwand' => 'begründeter Einwand gegen Rechnungsposten',
][$type];

$tone = $type === 'einwand' ? 'hoeflich bestimmt' : 'freundlich';
$length = $type === 'einwand' ? '4-6' : '2-4';

$prompt = "Du bist ein Assistent der Kunden eines Reinigungsdienstes (Fleckfrei Berlin) hilft, professionelle Nachrichten an das Buero zu verfassen.\n\n"
    . "Kunde will schreiben: **$typeLabel** zur Rechnung $invoiceNumber\n\n"
    . "Stichworte des Kunden:\n$context\n\n"
    . "Formuliere daraus eine kurze $length Saetze lange Nachricht auf Deutsch, die:\n"
    . "- Hoeflich aber klar ist ($tone Ton)\n"
    . "- Die Rechnungsnummer erwaehnt\n"
    . "- Das Anliegen konkret macht\n"
    . "- Mit freundlicher Grussformel endet\n\n"
    . "Gib NUR die Nachricht zurueck, keine Erklaerung, kein Markdown, keine Anrede 'Sehr geehrtes Team' am Anfang. Direkt ins Thema.";

if (!function_exists('groq_chat')) {
    echo json_encode(['error' => 'LLM not available']);
    exit;
}

$resp = groq_chat($prompt, 400);
$draft = trim($resp['content'] ?? '');

// Strip quotes / markdown if LLM added any
$draft = trim($draft, "\"'`\xe2\x80\x9e\xe2\x80\x9c\xe2\x80\x9d");
if (preg_match('/^```[\w]*\n(.+?)\n```$/s', $draft, $m)) $draft = trim($m[1]);

if (empty($draft)) {
    echo json_encode(['error' => 'Konnte keine Antwort generieren']);
    exit;
}

echo json_encode([
    'success' => true,
    'draft' => $draft,
    'type' => $type,
    'cached' => !empty($resp['_cache_hit']),
]);
