<?php
/**
 * Photo Analysis API — AI-powered cleanliness verification
 * POST /api/photo-analysis.php
 *
 * Accepts: multipart/form-data with 'photo' file + optional 'job_id', 'type' (before/after/damage)
 * Returns: JSON with score, issues, verdict, AI analysis
 *
 * Uses Groq Vision (llama-3.2-90b-vision-preview) — free tier.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/llm-helpers.php';
header('Content-Type: application/json; charset=utf-8');

// Auth: admin, employee, or customer
$utype = $_SESSION['utype'] ?? '';
$uid = (int)($_SESSION['uid'] ?? 0);
if (!$uid || !in_array($utype, ['admin', 'employee', 'customer'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST required']);
    exit;
}

$jobId = (int)($_POST['job_id'] ?? 0);
$photoType = in_array($_POST['type'] ?? '', ['before', 'after', 'damage', 'general'], true) ? $_POST['type'] : 'general';

// Accept file upload or base64
$imageBase64 = null;
$savedPath = null;

if (!empty($_FILES['photo']['tmp_name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $tmp = $_FILES['photo']['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        echo json_encode(['error' => 'Only JPG/PNG/WebP allowed']);
        exit;
    }
    if ($_FILES['photo']['size'] > 10 * 1024 * 1024) {
        echo json_encode(['error' => 'Max 10 MB']);
        exit;
    }
    $imageBase64 = base64_encode(file_get_contents($tmp));

    // Save to uploads
    $dir = __DIR__ . '/../uploads/analysis/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fname = date('Ymd_His') . '_' . $photoType . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    move_uploaded_file($tmp, $dir . $fname);
    $savedPath = '/uploads/analysis/' . $fname;
} elseif (!empty($_POST['image_base64'])) {
    $imageBase64 = $_POST['image_base64'];
} elseif (!empty($_POST['image_url'])) {
    $imageBase64 = $_POST['image_url']; // groq_vision handles URLs too
}

if (!$imageBase64) {
    echo json_encode(['error' => 'No image provided']);
    exit;
}

// Build AI prompt based on photo type
$prompts = [
    'before' => 'Du bist ein professioneller Reinigungsinspektor. Analysiere dieses VORHER-Foto einer Wohnung/Unterkunft.
Bewerte auf einer Skala 1-10:
- Sauberkeit (1=sehr schmutzig, 10=makellos)
- Ordnung (aufgeräumt vs chaotisch)
- Schäden (sichtbare Beschädigungen)

Identifiziere konkret:
- Verschmutzungen (Flecken, Staub, Dreck)
- Unordnung (herumliegende Gegenstände)
- Schäden (Kratzer, Risse, kaputte Dinge)
- Fehlende Standard-Items (Handtücher, Bettwäsche, Toilettenpapier)

Antworte NUR als JSON:
{"score": 1-10, "sauberkeit": 1-10, "ordnung": 1-10, "schaeden": 1-10, "issues": ["..."], "verdict": "kurze Zusammenfassung", "empfehlung": "was muss gereinigt werden"}',

    'after' => 'Du bist ein professioneller Reinigungsinspektor. Analysiere dieses NACHHER-Foto nach einer professionellen Reinigung.
Bewerte auf einer Skala 1-10:
- Sauberkeit (1=nicht gereinigt, 10=makellos)
- Ordnung (Standard-Arrangement für Gäste)
- Vollständigkeit (alle Standard-Items vorhanden)

Prüfe speziell:
- Sind Oberflächen streifenfrei?
- Ist das Bett frisch bezogen?
- Sind Handtücher gefaltet und platziert?
- Ist der Boden sauber (keine Haare, Krümel)?
- Sind Bad/WC sichtbar gereinigt?
- Gibt es noch sichtbare Verschmutzungen?

Antworte NUR als JSON:
{"score": 1-10, "sauberkeit": 1-10, "ordnung": 1-10, "vollstaendigkeit": 1-10, "issues": ["..."], "passed": true/false, "verdict": "Zusammenfassung", "bonus_worthy": true/false}',

    'damage' => 'Du bist ein Schadensgutachter. Analysiere dieses Foto auf Schäden in einer Mietwohnung/Ferienwohnung.

Identifiziere:
- Art des Schadens (Kratzer, Riss, Fleck, Bruch, Wasserschaden, Brandfleck)
- Schweregrad (leicht/mittel/schwer)
- Betroffener Bereich (Boden, Wand, Möbel, Gerät)
- Geschätzter Reparaturaufwand

Antworte NUR als JSON:
{"schaeden_gefunden": true/false, "anzahl": 0, "details": [{"typ": "...", "schwere": "leicht/mittel/schwer", "bereich": "...", "beschreibung": "..."}], "geschaetzter_aufwand": "€ Bereich", "empfehlung": "..."}',

    'general' => 'Du bist ein professioneller Reinigungsinspektor. Analysiere dieses Foto einer Wohnung/Unterkunft.
Bewerte Sauberkeit (1-10), identifiziere Probleme.
Antworte NUR als JSON:
{"score": 1-10, "issues": ["..."], "verdict": "kurze Zusammenfassung"}',
];

// Build DYNAMIC prompt — merge checklist items + custom rules
$prompt = $prompts[$photoType];

// Load checklist items for this job's service
$checklistContext = '';
if ($jobId) {
    $job = one("SELECT s_id_fk, customer_id_fk FROM jobs WHERE j_id=?", [$jobId]);
    if ($job && $job['s_id_fk']) {
        $clItems = all("SELECT title, description, priority FROM service_checklists WHERE s_id_fk=? AND is_active=1 ORDER BY position", [$job['s_id_fk']]);
        if (!empty($clItems)) {
            $checklistContext = "\n\nWICHTIG — Der Kunde hat folgende Checkliste definiert. Prüfe jedes Item auf dem Foto:\n";
            foreach ($clItems as $i => $cl) {
                $prio = $cl['priority'] === 'critical' ? '⚠ KRITISCH' : ($cl['priority'] === 'high' ? '! WICHTIG' : '');
                $checklistContext .= ($i + 1) . ". " . $cl['title'] . ($cl['description'] ? ' — ' . $cl['description'] : '') . ($prio ? " [$prio]" : "") . "\n";
            }
            $checklistContext .= "\nBewerte auch: Wurden alle Checklist-Items sichtbar erledigt? Füge in 'issues' jedes Item das NICHT erledigt aussieht.";
        }
    }
}

// Load custom AI rules from admin settings (if configured)
$customRules = '';
try {
    $rules = val("SELECT config_value FROM app_config WHERE config_key='photo_ai_rules'");
    if ($rules) $customRules = "\n\nZUSÄTZLICHE REGELN VOM ADMIN:\n" . $rules;
} catch (Exception $e) {}

$prompt .= $checklistContext . $customRules;
$isUrl = str_starts_with($imageBase64, 'http');
$result = groq_vision($imageBase64, $prompt, 1000);

if (!$result || !empty($result['error'])) {
    echo json_encode([
        'success' => false,
        'error' => $result['error'] ?? 'Vision API failed',
        'photo_path' => $savedPath,
    ]);
    exit;
}

// Parse AI response — extract JSON from content
$content = $result['content'] ?? '';
$analysisJson = null;

// Try to extract JSON from response (may be wrapped in markdown code blocks)
if (preg_match('/\{[\s\S]*\}/u', $content, $jsonMatch)) {
    $analysisJson = json_decode($jsonMatch[0], true);
}

// Store analysis result in DB
if ($jobId && $analysisJson) {
    try {
        q("INSERT INTO photo_analyses (job_id_fk, photo_type, photo_path, score, analysis_json, ai_model, analyzed_by, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
          [$jobId, $photoType, $savedPath, $analysisJson['score'] ?? 0, json_encode($analysisJson, JSON_UNESCAPED_UNICODE), $result['model'] ?? '', $uid]);
    } catch (Exception $e) {
        // Table might not exist yet — create it
        try {
            q("CREATE TABLE IF NOT EXISTS photo_analyses (
                pa_id INT AUTO_INCREMENT PRIMARY KEY,
                job_id_fk INT,
                photo_type ENUM('before','after','damage','general') DEFAULT 'general',
                photo_path VARCHAR(255),
                score TINYINT DEFAULT 0,
                analysis_json TEXT,
                ai_model VARCHAR(100),
                analyzed_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_job (job_id_fk),
                INDEX idx_type (photo_type)
            )");
            q("INSERT INTO photo_analyses (job_id_fk, photo_type, photo_path, score, analysis_json, ai_model, analyzed_by, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
              [$jobId, $photoType, $savedPath, $analysisJson['score'] ?? 0, json_encode($analysisJson, JSON_UNESCAPED_UNICODE), $result['model'] ?? '', $uid]);
        } catch (Exception $e2) { /* silent */ }
    }
}

echo json_encode([
    'success' => true,
    'photo_type' => $photoType,
    'photo_path' => $savedPath,
    'analysis' => $analysisJson,
    'raw_content' => $content,
    'model' => $result['model'] ?? '',
    'usage' => $result['usage'] ?? null,
    'job_id' => $jobId,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
