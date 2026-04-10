<?php
/**
 * Gotham OCR — extract entities from an uploaded document/image
 *
 * Pipeline:
 *   1. Accept multipart upload (image: jpg/png/pdf, max 5MB) OR
 *      raw text via POST {text: "..."}
 *   2. If image: send to ocr.space API (free tier, no signup, key
 *      'helloworld' rate-limited but functional). German + English
 *      languages enabled.
 *   3. Run ontology_extract_entities() on the OCR'd text
 *   4. Create a 'document' meta-object linking to all extracted
 *      entities (emails, phones, urls, handles, IBANs)
 *   5. Return per-entity counts + the OCR'd text snippet
 *
 * POST multipart:
 *   file:    the image
 *   label:   optional document label (defaults to filename)
 *
 * POST JSON:
 *   { "text": "...", "label": "..." }
 *
 * Response:
 *   { success, doc_obj_id, text_excerpt, entities: {...}, stats: {...} }
 */

ini_set('max_execution_time', 60);
ini_set('memory_limit', '128M');
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ontology.php';

db_ping_reconnect();

session_start();
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($_SESSION['uid']) && $apiKey !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$text = '';
$label = 'doc_' . date('Ymd_His');

// ============================================================
// 1. Read input — file upload or JSON text
// ============================================================
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'multipart/form-data') !== false) {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'file upload failed or missing']);
        exit;
    }
    if ($_FILES['file']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['error' => 'file too large (max 5MB)']);
        exit;
    }
    $tmpPath = $_FILES['file']['tmp_name'];
    $origName = $_FILES['file']['name'] ?? 'upload';
    $mime = mime_content_type($tmpPath) ?: '';
    $label = trim($_POST['label'] ?? '') ?: $origName;

    if (str_starts_with($mime, 'text/') || pathinfo($origName, PATHINFO_EXTENSION) === 'txt') {
        // Plain text — skip OCR
        $text = file_get_contents($tmpPath);
    } else {
        // Image / PDF → ocr.space
        $ocrUrl = 'https://api.ocr.space/parse/image';
        $cf = curl_file_create($tmpPath, $mime, $origName);
        $post = [
            'apikey'         => 'helloworld',  // public free tier key
            'language'       => 'ger',          // German primary; eng fallback handled by ocr.space
            'isOverlayRequired' => 'false',
            'OCREngine'      => '2',            // engine 2 better for non-english
            'scale'          => 'true',
            'detectOrientation' => 'true',
            'file'           => $cf,
        ];
        $ch = curl_init($ocrUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) {
            echo json_encode(['error' => "OCR API error HTTP $code"]);
            exit;
        }
        $j = json_decode($resp, true);
        if (!$j) {
            echo json_encode(['error' => 'invalid OCR response']);
            exit;
        }
        if (!empty($j['IsErroredOnProcessing'])) {
            $msg = is_array($j['ErrorMessage'] ?? null) ? implode('; ', $j['ErrorMessage']) : ($j['ErrorMessage'] ?? 'unknown');
            echo json_encode(['error' => 'OCR failed: ' . $msg]);
            exit;
        }
        foreach (($j['ParsedResults'] ?? []) as $r) {
            $text .= ($r['ParsedText'] ?? '') . "\n";
        }
    }
} else {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $text = trim($body['text'] ?? '');
    $label = trim($body['label'] ?? '') ?: $label;
}

if (trim($text) === '') {
    echo json_encode(['error' => 'no text extracted (empty document or OCR failed)']);
    exit;
}

// ============================================================
// 2. Entity extraction via existing regex helper
// ============================================================
$entities = ontology_extract_entities($text);

// ============================================================
// 3. Create document meta-object + link all entities
// ============================================================
$stats = ['objects_created' => 0, 'links_created' => 0];

try {
    $docId = ontology_upsert_object('document', $label, [
        'imported_at' => date('c'),
        'text_length' => mb_strlen($text),
        'preview'     => mb_substr($text, 0, 500),
    ], null, 0.95);
    if (!$docId) throw new Exception('failed to create document object');
    $stats['objects_created']++;

    foreach (array_slice($entities['emails'], 0, 20) as $em) {
        $eId = ontology_upsert_object('email', $em, [], null, 0.6);
        if ($eId) {
            ontology_upsert_link($docId, $eId, 'extracted_from_doc', 'ocr', 0.7);
            $stats['objects_created']++; $stats['links_created']++;
        }
    }
    foreach (array_slice($entities['phones'], 0, 20) as $ph) {
        $pId = ontology_upsert_object('phone', $ph, [], null, 0.6);
        if ($pId) {
            ontology_upsert_link($docId, $pId, 'extracted_from_doc', 'ocr', 0.7);
            $stats['objects_created']++; $stats['links_created']++;
        }
    }
    foreach (array_slice($entities['urls'], 0, 10) as $url) {
        if (preg_match('#^https?://([^/]+)#i', $url, $m)) {
            $dom = strtolower($m[1]);
            $dId = ontology_upsert_object('domain', $dom, [], null, 0.6);
            if ($dId) {
                ontology_upsert_link($docId, $dId, 'extracted_from_doc', 'ocr', 0.6);
                $stats['objects_created']++; $stats['links_created']++;
            }
        }
    }
    foreach (array_slice($entities['handles'], 0, 10) as $h) {
        $hId = ontology_upsert_object('handle', $h, [], null, 0.5);
        if ($hId) {
            ontology_upsert_link($docId, $hId, 'extracted_from_doc', 'ocr', 0.6);
            $stats['objects_created']++; $stats['links_created']++;
        }
    }
    foreach (array_slice($entities['ibans'], 0, 5) as $iban) {
        // Store IBAN as a handle-like object with a recognizable type
        $iId = ontology_upsert_object('handle', 'IBAN: ' . $iban, [], null, 0.85);
        if ($iId) {
            ontology_upsert_link($docId, $iId, 'extracted_from_doc', 'ocr', 0.85);
            $stats['objects_created']++; $stats['links_created']++;
        }
    }

    // Timeline event
    ontology_add_event($docId, 'doc_imported', date('Y-m-d'),
        'Document imported and OCRed: ' . $label,
        ['entities' => array_map('count', $entities)],
        'gotham_ocr');

    echo json_encode([
        'success'      => true,
        'doc_obj_id'   => $docId,
        'label'        => $label,
        'text_length'  => mb_strlen($text),
        'text_excerpt' => mb_substr($text, 0, 600),
        'entities'     => array_map('count', $entities),
        'stats'        => $stats,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
