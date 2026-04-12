<?php
/**
 * Checklist Ideas — AI-generated task suggestions from Airbnb/cleaning forum knowledge
 *
 * Uses Groq (llama-3.3-70b) with a curated prompt drawing on:
 *   - Airbnb Superhost cleaning standards
 *   - Reddit /r/airbnb_hosts common pain points
 *   - Cleaning forums (AirHostCommunity, BiggerPockets)
 *   - German Kurzzeitvermietung compliance (Meldeschein, Bettwäsche-Wechsel etc.)
 *
 * GET /api/checklist-ideas.php?service_id=X&room=küche
 * Returns: { success, ideas: [{ title, description, priority }] }
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/llm-helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['utype'] ?? '') !== 'customer') {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$cid = (int)$_SESSION['uid'];
$serviceId = (int)($_GET['service_id'] ?? 0);
$room = trim($_GET['room'] ?? '');

if (!$serviceId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing service_id']);
    exit;
}

$svc = one("SELECT s_id, title, customer_type FROM services s LEFT JOIN customer c ON s.customer_id_fk=c.customer_id WHERE s.s_id=? AND s.customer_id_fk=?", [$serviceId, $cid]);
if (!$svc) {
    http_response_code(404);
    echo json_encode(['error' => 'Service not found']);
    exit;
}

// Existing items to avoid duplicates
$existing = all("SELECT title FROM service_checklists WHERE s_id_fk=? AND is_active=1", [$serviceId]);
$existingTitles = array_column($existing, 'title');
$existingStr = $existingTitles ? implode("\n- ", $existingTitles) : '(noch keine)';

$customerType = $svc['customer_type'] ?? 'Airbnb';
$typeContext = match(true) {
    in_array($customerType, ['Airbnb','Host','Booking','Short-Term Rental']) => 'Kurzzeitvermietung / Airbnb in Berlin — Gast-Checkout zu Check-in Turnover',
    in_array($customerType, ['B2B','Business','GmbH','Company']) => 'B2B Büroreinigung',
    default => 'Privathaushalt',
};

$prompt = <<<PROMPT
Du bist Experte für professionelle Reinigung mit tiefem Wissen aus:
- Airbnb Superhost Community Standards
- Reddit /r/airbnb_hosts Best Practices
- Deutschen Kurzzeitvermietungs-Regeln (Berliner Beherbergungsgesetz)
- Cleaning Forums (Housekeeping Today, CleanLink)

Kontext:
- Service: {$svc['title']}
- Typ: {$typeContext}
- Bereits vorhandene Aufgaben (nicht vorschlagen, keine Duplikate!):
- {$existingStr}

Aufgabe: Schlage 6 NEUE Aufgaben vor die typischerweise VERGESSEN werden oder WICHTIG sind für dieses Szenario. Keine 08/15-Aufgaben — fokussiere auf Details die echten Host-Feedback entsprechen:
- Haar-Entfernung im Abfluss (hohe Beschwerde-Rate)
- Kaffeemaschinen-Entkalkung (oft übersehen)
- Unterseite Toilette (häufige 1-Stern-Quelle)
- Fenster innen bei Airbnb (Gäste merken es sofort)
- Sockelleisten abwischen
- Türgriffe desinfizieren
- Matratzen-Protektor checken
- usw.

Antwort NUR als JSON-Array, kein Markdown, keine Erklärung:
[
  {"title":"...","description":"Kurze praktische Anweisung","priority":"normal|high|critical","room":"Küche|Bad|..."},
  ...
]

Priorität:
- critical = Gast-Beschwerde-Risiko (z.B. Haare, Schimmel, Geruch)
- high = wichtig aber nicht immer dringend
- normal = Standard-Reinigung

Rooms auf Deutsch. Kurze knackige title, practical description.
PROMPT;

if (!function_exists('groq_chat')) {
    echo json_encode(['success' => false, 'error' => 'Groq LLM not available']);
    exit;
}

$resp = groq_chat($prompt, 2000);
$content = $resp['content'] ?? '';

$ideas = [];
// Map German/alternative priority labels to our enum
$priorityMap = [
    'critical' => 'critical', 'kritisch' => 'critical', 'hoch' => 'high', 'high' => 'high',
    'wichtig' => 'high', 'dringend' => 'high',
    'mittel' => 'normal', 'medium' => 'normal', 'normal' => 'normal',
    'niedrig' => 'normal', 'low' => 'normal',
];

if (preg_match('/\[[\s\S]*\]/', $content, $m)) {
    $parsed = json_decode($m[0], true);
    if (is_array($parsed)) {
        foreach ($parsed as $idea) {
            if (empty($idea['title'])) continue;
            $rawPrio = strtolower(trim($idea['priority'] ?? ''));
            $mappedPrio = $priorityMap[$rawPrio] ?? 'normal';
            $ideas[] = [
                'title' => mb_substr(trim($idea['title']), 0, 200),
                'description' => mb_substr(trim($idea['description'] ?? ''), 0, 500),
                'priority' => $mappedPrio,
                'room' => mb_substr(trim($idea['room'] ?? ''), 0, 100),
            ];
        }
    }
}

echo json_encode([
    'success' => !empty($ideas),
    'ideas' => $ideas,
    'source' => 'Groq llama-3.3-70b · Airbnb Host Best Practices',
    'cached' => !empty($resp['_cache_hit']),
]);
