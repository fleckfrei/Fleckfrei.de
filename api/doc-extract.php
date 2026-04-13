<?php
/**
 * Document AI-Extract v2 — Imagick PDF→JPG + Groq Vision
 * POST file → JSON {success, label, issued_at, expires_at, issuer, notes}
 */
ini_set('max_execution_time', 90);
ini_set('memory_limit', '256M');
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'POST file required']);
    exit;
}

$file = $_FILES['file'];
$mime = mime_content_type($file['tmp_name']);
$allowed = ['application/pdf','image/png','image/jpeg','image/jpg','image/webp'];
if (!in_array($mime, $allowed) || $file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['error' => 'Invalid file type or too large']);
    exit;
}

$groqKey = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
if (!$groqKey) {
    echo json_encode(['error' => 'GROQ_API_KEY missing — Auto-Fill nicht möglich']);
    exit;
}

// === Convert to JPEG if PDF ===
$jpgPath = $file['tmp_name'];
$tmpFile = null;
try {
    if ($mime === 'application/pdf') {
        if (!extension_loaded('imagick')) {
            echo json_encode(['error' => 'PDF nicht unterstützt (Imagick missing)']);
            exit;
        }
        $im = new Imagick();
        $im->setResolution(200, 200);
        $im->readImage($file['tmp_name'] . '[0]'); // first page only
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(85);
        // Resize if too big
        if ($im->getImageWidth() > 2000) {
            $im->resizeImage(2000, 0, Imagick::FILTER_LANCZOS, 1);
        }
        $im->setImageBackgroundColor('white');
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $tmpFile = sys_get_temp_dir() . '/doc_' . uniqid() . '.jpg';
        $im->writeImage($tmpFile);
        $im->clear();
        $jpgPath = $tmpFile;
        $mime = 'image/jpeg';
    }
} catch (Exception $e) {
    echo json_encode(['error' => 'PDF-Konversion fehlgeschlagen: ' . $e->getMessage()]);
    exit;
}

// === Resize image if too big (Groq prefers <4MB base64) ===
if (filesize($jpgPath) > 3 * 1024 * 1024 && extension_loaded('imagick')) {
    try {
        $im = new Imagick($jpgPath);
        $im->resizeImage(1600, 0, Imagick::FILTER_LANCZOS, 1);
        $im->setImageCompressionQuality(75);
        $im->writeImage($jpgPath);
        $im->clear();
    } catch (Exception $e) {}
}

// === Build base64 data URL ===
$imgData = base64_encode(file_get_contents($jpgPath));
$dataUrl = 'data:' . $mime . ';base64,' . $imgData;

// === Send to Groq Vision ===
$sysPrompt = "Du extrahierst Daten aus deutschen oder englischen Geschäftsdokumenten (Versicherung, Gewerbeschein, Bescheinigung, Vertrag, Zertifikat, etc).
Antworte AUSSCHLIESSLICH mit valid JSON. Keine Erklärung außerhalb des JSON.
Schema: {\"label\":\"kurze Beschreibung max 80 Zeichen\",\"issued_at\":\"YYYY-MM-DD oder leer\",\"expires_at\":\"YYYY-MM-DD oder leer\",\"issuer\":\"Aussteller-Name max 100 Zeichen oder leer\",\"notes\":\"sonstige relevante Info max 200 Zeichen\"}
Datum-Heuristik: 'ausgestellt am'/'date of issue'/'vom'/'Datum' → issued_at. 'gültig bis'/'valid until'/'Ablauf'/'expires' → expires_at.
Aussteller: Versicherung, Behörde, Firma die Document erzeugt hat (z.B. 'AOK Berlin', 'Finanzamt München', 'ARAG Versicherung', 'Camera de Comerț').
label: Was ist das Dokument? z.B. 'Haftpflichtversicherung 2026', 'Gewerbeanmeldung', 'Certificat constatator'.";

$payload = json_encode([
    'model' => 'meta-llama/llama-4-scout-17b-16e-instruct',
    'temperature' => 0.1,
    'max_tokens' => 500,
    'response_format' => ['type' => 'json_object'],
    'messages' => [
        ['role' => 'system', 'content' => $sysPrompt],
        ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Extrahiere die Daten aus diesem Dokument:'],
            ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
        ]],
    ],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $groqKey,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
$aiRaw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($tmpFile && file_exists($tmpFile)) @unlink($tmpFile);

if ($err) {
    echo json_encode(['error' => 'Vision API: ' . $err]);
    exit;
}

$ai = json_decode($aiRaw, true);

if ($httpCode !== 200) {
    // Try fallback model if vision model rejected
    $errMsg = $ai['error']['message'] ?? "HTTP $httpCode";
    echo json_encode(['error' => 'Vision-Modell antwortete: ' . $errMsg]);
    exit;
}

$content = $ai['choices'][0]['message']['content'] ?? '';
$parsed = json_decode($content, true);

$result = ['success' => true, 'label' => '', 'issued_at' => '', 'expires_at' => '', 'issuer' => '', 'notes' => ''];
if (is_array($parsed)) {
    foreach (['label','issued_at','expires_at','issuer','notes'] as $k) {
        if (!empty($parsed[$k])) $result[$k] = $parsed[$k];
    }
}

// 2. Vision Call: KOMPLETTER Text (inkl. Handschrift)
$fullTextPrompt = 'Extrahiere ALLEN sichtbaren Text aus diesem Dokument oder Bild. WICHTIG: Auch handschriftliche Notizen, Stempel, Unterschriften, Tabellen, alles erkennbare. Format: gut lesbarer Plain-Text mit Zeilen-Umbrüchen wie im Original. Wenn Tabellen → Markdown-Tabelle. Keine Erklärung, nur den extrahierten Text.';

$payload2 = json_encode([
    'model' => 'meta-llama/llama-4-scout-17b-16e-instruct',
    'temperature' => 0.0,
    'max_tokens' => 4000,
    'messages' => [
        ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $fullTextPrompt],
            ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
        ]],
    ],
], JSON_UNESCAPED_UNICODE);

$ch2 = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $groqKey]);
curl_setopt($ch2, CURLOPT_POSTFIELDS, $payload2);
curl_setopt($ch2, CURLOPT_TIMEOUT, 60);
$ftRaw = curl_exec($ch2);
curl_close($ch2);
$ft = json_decode($ftRaw, true);
$result['full_text'] = trim($ft['choices'][0]['message']['content'] ?? '');

if (empty($result['label']) && empty($result['issuer']) && empty($result['issued_at']) && empty($result['full_text'])) {
    echo json_encode(['error' => 'Keine Daten erkannt — bitte manuell ausfüllen', 'raw' => substr($content, 0, 200)]);
    exit;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
