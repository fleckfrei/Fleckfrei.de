<?php
/**
 * VULTURE-CORE V3.0 — Recursive Cascade Orchestrator
 *
 * Wraps osint-deep.php with:
 *  - Autonomous seed generation from findings
 *  - Recursive cascades (depth 1-5)
 *  - 3-source triangulation (verified fact rule)
 *  - Confidence scoring toward 99% threshold
 *  - Sanitized profile anomaly detection
 *  - Palantir-style synthetic narrative output
 *
 * POST body:
 *   {name, email, phone, address, dob, ...osint-deep fields,
 *    depth: 1-5 (default 3),
 *    mode: "stealth"|"fast"|"deep" (default "fast"),
 *    context: "why this scan" (required, audit trail)}
 */

ini_set('max_execution_time', 600);
if (function_exists('set_time_limit')) set_time_limit(600);
ignore_user_abort(true);
ob_start();

header('Content-Type: application/json');
$_v_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$_v_allowed = ['https://app.fleckfrei.de', 'https://fleckfrei.de'];
if (in_array($_v_origin, $_v_allowed)) { header('Access-Control-Allow-Origin: ' . $_v_origin); }
else { header('Access-Control-Allow-Origin: https://app.fleckfrei.de'); }
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST only']); exit; }

require_once __DIR__ . '/../includes/config.php';

// Auth
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
session_start();
if (empty($_SESSION['uid']) && $apiKey !== API_KEY) {
    http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Config
$depth   = max(1, min(5, (int)($body['depth'] ?? 3)));
$mode    = in_array($body['mode'] ?? '', ['stealth','fast','deep']) ? $body['mode'] : 'fast';
$context = trim($body['context'] ?? '');
if ($context === '') {
    http_response_code(400);
    echo json_encode(['error' => 'context required — one-line scan justification for audit trail']);
    exit;
}

$initialSeed = array_intersect_key($body, array_flip([
    'name','email','phone','address','dob','id_number','passport','serial','tax_id','plate','company','domain','handle'
]));
$initialSeed = array_filter(array_map('trim', $initialSeed));
if (empty($initialSeed)) {
    http_response_code(400);
    echo json_encode(['error' => 'seed required — need at least one of: name, email, phone, domain, handle']);
    exit;
}

$cascadeStart = microtime(true);

// ============================================================
// GRAPH STATE — nodes, edges, confidence ledger
// ============================================================
$graph = [
    'nodes'        => [],  // id => ['type'=>x, 'value'=>y, 'sources'=>[], 'confidence'=>0]
    'edges'        => [],  // [from, to, relation, source]
    'seeds_seen'   => [],  // dedup seeds across layers
    'cascade_log'  => [],  // per-layer scan result summaries
    'raw_layers'   => [],  // full osint-deep responses per layer
];
$auditTrail = [];

function node_id(string $type, string $value): string {
    return $type . ':' . strtolower(trim($value));
}

function add_node(array &$g, string $type, string $value, string $source, float $weight = 1.0): string {
    $id = node_id($type, $value);
    if (!isset($g['nodes'][$id])) {
        $g['nodes'][$id] = [
            'type' => $type,
            'value' => $value,
            'sources' => [],
            'confidence' => 0.0,
            'verified' => false,
        ];
    }
    if (!in_array($source, $g['nodes'][$id]['sources'], true)) {
        $g['nodes'][$id]['sources'][] = $source;
    }
    // Confidence formula: 1 - (1 - 0.33)^n_sources, capped via 3-source rule
    $n = count($g['nodes'][$id]['sources']);
    $g['nodes'][$id]['confidence'] = min(0.99, 1 - pow(0.67, $n));
    $g['nodes'][$id]['verified'] = $n >= 3;
    return $id;
}

function add_edge(array &$g, string $from, string $to, string $relation, string $source): void {
    $g['edges'][] = compact('from','to','relation','source');
}

// ============================================================
// INTERNAL CALL — osint-deep.php via loopback
// ============================================================
function run_deep_scan(array $seed, string $apiKey, string $mode): array {
    // Auto-detect same-host URL (Hostinger: https://app.fleckfrei.de/api/osint-deep.php)
    $host   = $_SERVER['HTTP_HOST'] ?? 'app.fleckfrei.de';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
    $url    = "$scheme://$host/api/osint-deep.php";
    $ch = curl_init($url);
    $proxyOpts = [];
    if ($mode === 'stealth' || $mode === 'deep') {
        // Route outbound scans through VPS Tor proxy for anonymity
        $proxyOpts[CURLOPT_PROXY] = '89.116.22.185:9050';
        $proxyOpts[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($seed),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $apiKey],
        CURLOPT_TIMEOUT => 180,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) return ['success' => false, 'error' => "deep scan HTTP $code"];
    $json = json_decode($resp, true);
    return $json ?: ['success' => false, 'error' => 'invalid JSON from osint-deep'];
}

// ============================================================
// VPS API V4 — direct calls for tools not in osint-deep
// Endpoint: http://89.116.22.185:8900  (auth via X-API-Key)
// ============================================================
function vps_call(string $tool, array $params): ?array {
    $url = 'http://89.116.22.185:8900/' . ltrim($tool, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . API_KEY,
        ],
        CURLOPT_TIMEOUT => 90,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp ? json_decode($resp, true) : null;
}

// ============================================================
// AI CROSS-CHECK — independent LLM verification via Perplexity
// (and Google-OSINT) on VPS. Used as 3rd triangulation source:
// if Perplexity confirms a claim that osint-deep also found,
// that's an independent signal that deserves extra confidence.
// ============================================================
function ai_crosscheck(array $initialSeed, array $rawLayers): array {
    $name    = trim($initialSeed['name'] ?? '');
    $email   = trim($initialSeed['email'] ?? '');
    $phone   = trim($initialSeed['phone'] ?? '');
    $domain  = trim($initialSeed['domain'] ?? '');
    $address = trim($initialSeed['address'] ?? '');
    $company = trim($initialSeed['company'] ?? '');

    $anchor = $company ?: $name ?: $domain ?: $email;
    if ($anchor === '') return ['available' => false, 'reason' => 'no anchor identifier to query'];

    // Build 2 targeted queries — kept short to minimize tokens / cost
    $loc = $address ? " ($address)" : '';
    $q1 = "Who is $anchor$loc? Give a short factual profile with verifiable public sources. If business: registry number, directors, address. If person: professional role, location, known companies. Answer in 4 sentences max.";
    $q2 = "For '$anchor'$loc — list any publicly documented fraud reports, insolvencies, court cases, or negative reviews. Answer 'none found' if nothing public exists. Max 3 sentences.";

    $pplx1 = vps_call('perplexity', ['query' => $q1]);
    $pplx2 = vps_call('perplexity', ['query' => $q2]);
    // SearXNG = local aggregator over 250 engines — independent of Google rate limits.
    // Two queries: one profile-oriented, one risk-oriented.
    $sx1 = vps_call('searxng', ['query' => $anchor, 'categories' => 'general', 'limit' => 10]);
    $sx2 = vps_call('searxng', ['query' => $anchor . ' Betrug OR Insolvenz OR Beschwerde OR Klage', 'categories' => 'general', 'limit' => 5]);

    $out = [
        'available' => true,
        'anchor'    => $anchor,
        'sources_queried' => [],
        'errors' => [],
    ];

    if ($pplx1 && !isset($pplx1['error'])) {
        $content = $pplx1['content'] ?? ($pplx1['choices'][0]['message']['content'] ?? '');
        if ($content) {
            $out['perplexity_profile'] = trim($content);
            $out['sources_queried'][] = 'perplexity_profile';
        }
    } elseif (isset($pplx1['error'])) {
        $out['errors']['perplexity'] = $pplx1['error'];
    }
    if ($pplx2 && !isset($pplx2['error'])) {
        $content = $pplx2['content'] ?? ($pplx2['choices'][0]['message']['content'] ?? '');
        if ($content) {
            $out['perplexity_risk'] = trim($content);
            $out['sources_queried'][] = 'perplexity_risk';
            if (preg_match('/\b(fraud|insolvency|lawsuit|court|negative|complaint|Betrug|Insolvenz|Klage|Beschwerde)\b/i', $content)
                && !preg_match('/\bnone\b|\bnichts\b|\bkeine\b/i', $content)) {
                $out['risk_signal'] = 'AI detected public negative mentions — manual review advised';
            }
        }
    }

    // SearXNG profile aggregator — always works (local container, 250 engines)
    if ($sx1 && !empty($sx1['results']) && is_array($sx1['results'])) {
        $out['searxng_hits'] = count($sx1['results']);
        $out['searxng_top'] = array_slice(array_map(fn($r) => [
            'title'   => $r['title'] ?? '',
            'url'     => $r['url'] ?? '',
            'snippet' => mb_substr($r['snippet'] ?? '', 0, 200),
        ], $sx1['results']), 0, 5);
        $out['sources_queried'][] = 'searxng_profile';
    } elseif (isset($sx1['error'])) {
        $out['errors']['searxng'] = $sx1['error'];
    }

    // SearXNG risk sweep — negative-keyword query
    if ($sx2 && !empty($sx2['results']) && is_array($sx2['results'])) {
        $out['searxng_risk_hits'] = count($sx2['results']);
        $riskTitles = array_column($sx2['results'], 'title');
        $out['searxng_risk_titles'] = array_slice($riskTitles, 0, 3);
        if (count($riskTitles) >= 2) {
            $out['risk_signal'] = ($out['risk_signal'] ?? '') .
                ' | SearXNG risk sweep found ' . count($riskTitles) . ' hits for negative keywords';
        }
        $out['sources_queried'][] = 'searxng_risk';
    }

    // Triangulation: do the AI answers + SearXNG snippets mention facts also found by osint-deep?
    $matches = 0;
    $scanBlob = '';
    foreach ($rawLayers as $layer) { $scanBlob .= json_encode($layer['data'] ?? []); }
    $aiBlob = ($out['perplexity_profile'] ?? '') . ' ' . ($out['perplexity_risk'] ?? '');
    foreach (($out['searxng_top'] ?? []) as $r) {
        $aiBlob .= ' ' . ($r['title'] ?? '') . ' ' . ($r['snippet'] ?? '');
    }
    if (trim($aiBlob) !== '' && $scanBlob !== '') {
        // Extract capitalized words >5 chars from AI/SearXNG answer, check how many appear in scan results
        preg_match_all('/\b[A-ZÄÖÜ][a-zäöüß]{5,}\b/u', $aiBlob, $m);
        $uniqueTerms = array_unique($m[0] ?? []);
        foreach ($uniqueTerms as $term) {
            if (stripos($scanBlob, $term) !== false) $matches++;
        }
        $out['triangulation_matches'] = $matches;
        $out['triangulation_strength'] = $matches >= 5 ? 'strong' : ($matches >= 2 ? 'moderate' : 'weak');
    }

    if (empty($out['errors'])) unset($out['errors']);
    return $out;
}

// ============================================================
// NOISE BLACKLIST — search-engine/platform hosts that leak into
// result URLs and would otherwise pollute the domain node pool.
// These are NEVER leads; they are always just where the result
// was linked, not a finding about the target.
// ============================================================
const VULTURE_NOISE_DOMAINS = [
    'google.com','google.de','bing.com','duckduckgo.com','yandex.com','yahoo.com',
    'facebook.com','instagram.com','twitter.com','x.com','tiktok.com','youtube.com',
    'linkedin.com','xing.com','pinterest.com','reddit.com','snapchat.com',
    'github.com','githubusercontent.com','gitlab.com','stackoverflow.com',
    'wikipedia.org','wikimedia.org',
    'kleinanzeigen.de','ebay.de','ebay.com','markt.de','quoka.de',
    'web.archive.org','archive.org',
    'insolvenzbekanntmachungen.de','bundesanzeiger.de','northdata.de','handelsregister.de',
    'airbnb.com','booking.com','tripadvisor.com','vrbo.com',
    'immobilienscout24.de','immowelt.de',
    't.me','telegram.org','whatsapp.com',
];

function is_noise_domain(string $domain): bool {
    $d = strtolower(ltrim($domain, '.'));
    if (in_array($d, VULTURE_NOISE_DOMAINS, true)) return true;
    foreach (VULTURE_NOISE_DOMAINS as $noise) {
        if (str_ends_with($d, '.' . $noise)) return true;
    }
    return false;
}

// ============================================================
// SEED EXTRACTOR — harvest new leads from scan results
// ============================================================
function extract_seeds(array $scan, array &$graph, int $layer, array $initialSeed): array {
    $new = [];
    $data = $scan['data'] ?? $scan;
    $initialEmail = strtolower($initialSeed['email'] ?? '');
    $initialDomain = $initialEmail && strpos($initialEmail, '@') !== false
        ? substr($initialEmail, strpos($initialEmail, '@') + 1)
        : strtolower($initialSeed['domain'] ?? '');

    // Walk results recursively and harvest identifiers
    $harvest = function($node, $path = '') use (&$harvest, &$new, &$graph, $initialDomain) {
        if (is_array($node)) {
            foreach ($node as $k => $v) { $harvest($v, $path . '.' . $k); }
            return;
        }
        if (!is_string($node) || strlen($node) > 500) return;

        // Email pattern
        if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $node, $m)) {
            foreach ($m[0] as $email) {
                $emailDomain = strtolower(substr($email, strpos($email, '@') + 1));
                if (is_noise_domain($emailDomain)) continue;
                add_node($graph, 'email', $email, "osint_deep:$path", 1.0);
                if (!isset($graph['seeds_seen']["email:$email"])) {
                    $graph['seeds_seen']["email:$email"] = true;
                    $new[] = ['email' => $email];
                }
            }
        }
        // Phone pattern (E.164)
        if (preg_match_all('/\+\d{7,15}/', $node, $m)) {
            foreach ($m[0] as $phone) {
                add_node($graph, 'phone', $phone, "osint_deep:$path", 1.0);
                if (!isset($graph['seeds_seen']["phone:$phone"])) {
                    $graph['seeds_seen']["phone:$phone"] = true;
                    $new[] = ['phone' => $phone];
                }
            }
        }
        // Domain pattern — skip noise hosts and the seed domain itself
        if (preg_match_all('/\b([a-z0-9][a-z0-9-]{1,62}\.(?:com|de|org|net|io|co|eu|at|ch|ro|uk|fr|es|it|nl|be))\b/i', $node, $m)) {
            foreach ($m[1] as $domain) {
                $d = strtolower($domain);
                if (is_noise_domain($d)) continue;
                if ($d === $initialDomain) continue;
                add_node($graph, 'domain', $d, "osint_deep:$path", 0.5);
            }
        }
    };
    $harvest($data);

    // Extract structured fields — osint-deep has known schemas
    foreach (['username_search','social_profiles','handelsregister','opencorporates','gleif_lei'] as $mod) {
        if (!empty($data[$mod]) && is_array($data[$mod])) {
            foreach ($data[$mod] as $entry) {
                if (is_array($entry)) {
                    if (!empty($entry['username'])) add_node($graph, 'handle', $entry['username'], $mod, 1.0);
                    if (!empty($entry['company'])) add_node($graph, 'company', $entry['company'], $mod, 1.0);
                    if (!empty($entry['name']))    add_node($graph, 'name_variant', $entry['name'], $mod, 0.8);
                }
            }
        }
    }

    // Layer-cap to prevent exponential blowup
    return array_slice($new, 0, 8);
}

// ============================================================
// BEHAVIORAL FINGERPRINTING — writing style / activity hints
// Collects text fragments that osint-deep returned (bios,
// descriptions, impressum text) and extracts simple features
// for alias-unmasking. Not a full NLP model — heuristics only.
// ============================================================
function behavioral_fingerprint(array $rawLayers): array {
    $allText = '';
    foreach ($rawLayers as $layer) {
        $d = $layer['data'] ?? [];
        foreach (['deep_intel','web_intel','dossier','handelsregister'] as $mod) {
            if (!empty($d[$mod])) {
                $allText .= ' ' . (is_string($d[$mod]) ? $d[$mod] : json_encode($d[$mod]));
            }
        }
    }
    $allText = mb_substr($allText, 0, 50000);

    if (trim($allText) === '') {
        return ['available' => false, 'reason' => 'no bio/impressum/description text harvested'];
    }

    // Language hint
    $langScore = ['de' => 0, 'en' => 0, 'ro' => 0];
    if (preg_match_all('/\b(und|der|die|das|mit|für|von|ist|nicht|sind)\b/i', $allText, $m)) $langScore['de'] += count($m[0]);
    if (preg_match_all('/\b(the|and|with|for|from|not|are|this|that|which)\b/i', $allText, $m)) $langScore['en'] += count($m[0]);
    if (preg_match_all('/\b(și|este|sunt|pentru|care|dar|nu|cu|în)\b/i', $allText, $m)) $langScore['ro'] += count($m[0]);
    arsort($langScore);
    $dominantLang = array_key_first($langScore);

    // Formality heuristic (Sie/Du, first-person pronoun ratio)
    $formal = preg_match_all('/\b(Sie|Ihnen|Ihre)\b/', $allText);
    $casual = preg_match_all('/\b(du|dir|dein|dich|hey|hi)\b/i', $allText);
    $tone = $formal > $casual * 2 ? 'formal' : ($casual > $formal * 2 ? 'casual' : 'neutral');

    // Business vs personal markers
    $business = preg_match_all('/\b(GmbH|UG|AG|Firma|Unternehmen|Gesellschaft|Geschäftsführer|Inhaber|Impressum|USt|Steuernummer|HRB|HRA)\b/i', $allText);
    $personal = preg_match_all('/\b(ich|mein|meine|Familie|Hobby|privat|persönlich)\b/i', $allText);

    // Active-hours hint from any timestamp-looking strings
    $hours = [];
    if (preg_match_all('/\b(\d{1,2}):\d{2}\b/', $allText, $m)) {
        foreach ($m[1] as $h) { $hh = (int)$h; if ($hh >= 0 && $hh <= 23) $hours[] = $hh; }
    }
    $activityProfile = 'unknown';
    if ($hours) {
        $avg = array_sum($hours) / count($hours);
        $activityProfile = $avg < 9 ? 'early_bird' : ($avg > 18 ? 'night_owl' : 'office_hours');
    }

    // Distinctive vocabulary (top 5 words >6 chars, not in stopwords)
    $stopwords = ['der','die','das','und','oder','aber','auch','nicht','eine','einer','mein','sein','werden','haben','dass','wird','kann','sollte','würde','between','about','other','could','their','there','which','where','these','those','please','thank','company','service','services'];
    $words = preg_split('/\W+/u', mb_strtolower($allText));
    $freq = [];
    foreach ($words as $w) {
        if (mb_strlen($w) < 7) continue;
        if (in_array($w, $stopwords, true)) continue;
        if (preg_match('/^\d+$/', $w)) continue;
        $freq[$w] = ($freq[$w] ?? 0) + 1;
    }
    arsort($freq);
    $vocabTop = array_slice(array_keys($freq), 0, 5);

    return [
        'available'         => true,
        'sample_length'     => mb_strlen($allText),
        'dominant_language' => $dominantLang,
        'lang_scores'       => $langScore,
        'tone'              => $tone,
        'business_markers'  => $business,
        'personal_markers'  => $personal,
        'profile_type'      => $business > $personal ? 'business' : ($personal > $business ? 'personal' : 'mixed'),
        'activity_hint'     => $activityProfile,
        'distinctive_vocab' => $vocabTop,
    ];
}

// ============================================================
// PREDICTIVE VECTORING — targeted follow-up seeds based on
// what the cascade found. If target appears on Booking.com,
// auto-queue ImmoScout24. If found on Airbnb, queue Northdata.
// These are SEARCH REFINEMENTS not new identifiers, so they
// bypass the normal seed queue and issue direct sub-queries.
// ============================================================
function predictive_vectors(array $rawLayers, array $initialSeed): array {
    $vectors = [];
    $name = $initialSeed['name'] ?? '';
    $addr = $initialSeed['address'] ?? '';

    $jsonBlob = '';
    foreach ($rawLayers as $layer) { $jsonBlob .= json_encode($layer['data'] ?? []); }
    $lc = strtolower($jsonBlob);

    $rules = [
        // trigger_keyword  => [predicted_domain, rationale, query_template]
        'booking.com'       => ['immobilienscout24.de', 'Host on Booking → check for property ownership',
                                 fn($n,$a) => 'https://www.immobilienscout24.de/Suche/?searchQuery=' . urlencode($n ?: $a)],
        'airbnb.com'        => ['northdata.de', 'Airbnb host → check for registered business',
                                 fn($n,$a) => 'https://www.northdata.de/' . urlencode($n ?: $a)],
        'handelsregister'   => ['linkedin.com', 'Company registered → check owner LinkedIn',
                                 fn($n,$a) => 'https://www.google.com/search?q=site%3Alinkedin.com+%22' . urlencode($n) . '%22'],
        'northdata'         => ['bundesanzeiger.de', 'In Northdata → check Bundesanzeiger filings',
                                 fn($n,$a) => 'https://www.bundesanzeiger.de/pub/de/suchergebnis?searchTerm=' . urlencode($n)],
        'impressum'         => ['google.com/maps', 'Impressum detected → verify physical address',
                                 fn($n,$a) => 'https://www.google.com/maps/search/' . urlencode($a ?: $n)],
        'tripadvisor'       => ['google.com reviews', 'TripAdvisor hit → sweep Google Reviews',
                                 fn($n,$a) => 'https://www.google.com/search?q=%22' . urlencode($n) . '%22+review'],
    ];

    foreach ($rules as $trigger => [$target, $reason, $tpl]) {
        if (strpos($lc, $trigger) !== false) {
            $vectors[] = [
                'trigger' => $trigger,
                'target'  => $target,
                'reason'  => $reason,
                'query_url' => $tpl($name, $addr),
                'priority' => 'medium',
            ];
        }
    }

    return $vectors;
}

// ============================================================
// SANITIZED PROFILE DETECTION — anomaly heuristic
// ============================================================
function detect_sanitized(array $graph, array $rawLayers): array {
    $flags = [];
    $nodeCount = count($graph['nodes']);
    $emailNodes   = array_filter($graph['nodes'], fn($n) => $n['type'] === 'email');
    $handleNodes  = array_filter($graph['nodes'], fn($n) => $n['type'] === 'handle');
    $breachHits   = 0;
    $hasLinkedIn  = false;
    foreach ($rawLayers as $layer) {
        $d = $layer['data'] ?? [];
        if (!empty($d['breach_check']['breaches'])) $breachHits += count($d['breach_check']['breaches']);
        if (stripos(json_encode($d['social_profiles'] ?? ''), 'linkedin') !== false) $hasLinkedIn = true;
    }
    // Rule: LinkedIn exists but zero organic footprint + zero breaches = too clean
    if ($hasLinkedIn && $breachHits === 0 && count($emailNodes) <= 1 && count($handleNodes) === 0) {
        $flags[] = 'HIGH_RISK: Identity Fabrication — LinkedIn present but no organic digital trace';
    }
    if ($nodeCount < 3) {
        $flags[] = 'LOW_FOOTPRINT: fewer than 3 verified nodes — sparse or fresh identity';
    }
    return $flags;
}

// ============================================================
// PALANTIR-STYLE SYNTHETIC NARRATIVE
// ============================================================
function synthesize(array $graph, array $rawLayers, array $initialSeed, array $anomalies, float $elapsed): array {
    $verified = array_filter($graph['nodes'], fn($n) => $n['verified']);
    $overall = $verified ? min(0.99, array_sum(array_column($verified, 'confidence')) / max(1, count($verified))) : 0.0;
    if (!$verified) {
        $overall = $graph['nodes']
            ? array_sum(array_column($graph['nodes'], 'confidence')) / count($graph['nodes']) * 0.6
            : 0.0;
    }

    $byType = [];
    foreach ($graph['nodes'] as $id => $n) {
        $byType[$n['type']][] = $n;
    }
    foreach ($byType as &$arr) {
        usort($arr, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
    }

    // Identity block
    $identity = [
        'primary_name'   => $initialSeed['name'] ?? ($byType['name_variant'][0]['value'] ?? 'UNKNOWN'),
        'aliases'        => array_slice(array_column($byType['name_variant'] ?? [], 'value'), 0, 5),
        'emails_verified'=> array_map(fn($n) => ['value'=>$n['value'],'confidence'=>$n['confidence']],
                                       array_filter($byType['email'] ?? [], fn($n)=>$n['verified'])),
        'phones_verified'=> array_map(fn($n) => ['value'=>$n['value'],'confidence'=>$n['confidence']],
                                       array_filter($byType['phone'] ?? [], fn($n)=>$n['verified'])),
        'handles'        => array_slice(array_column($byType['handle'] ?? [], 'value'), 0, 10),
    ];

    // Network block — from handelsregister/opencorporates across layers
    $network = ['companies' => [], 'professional_ties' => [], 'business_registries' => []];
    foreach ($rawLayers as $layer) {
        $d = $layer['data'] ?? [];
        foreach (['handelsregister','opencorporates','gleif_lei','dossier'] as $mod) {
            if (!empty($d[$mod])) {
                $network['business_registries'][$mod] = true;
                if (is_array($d[$mod])) {
                    foreach ($d[$mod] as $entry) {
                        if (is_array($entry) && !empty($entry['company'])) {
                            $network['companies'][] = $entry['company'];
                        }
                    }
                }
            }
        }
    }
    $network['companies'] = array_values(array_unique($network['companies']));
    $network['business_registries'] = array_keys($network['business_registries']);

    // Digital footprint
    $digital = [
        'active_platforms' => $identity['handles'],
        'domains_linked'   => array_column($byType['domain'] ?? [], 'value'),
        'breaches_found'   => 0,
        'historical_snapshots' => 0,
    ];
    foreach ($rawLayers as $layer) {
        $d = $layer['data'] ?? [];
        if (!empty($d['breach_check']['breaches'])) $digital['breaches_found'] += count($d['breach_check']['breaches']);
        if (!empty($d['wayback'])) $digital['historical_snapshots'] += is_array($d['wayback']) ? count($d['wayback']) : 1;
    }

    // Risk assessment
    $riskScore = 0;
    if (in_array('HIGH_RISK', array_map(fn($f) => explode(':', $f)[0], $anomalies))) $riskScore += 50;
    if ($digital['breaches_found'] >= 3) $riskScore += 15;
    if ($overall < 0.5) $riskScore += 20;
    if (empty($network['companies']) && empty($identity['emails_verified'])) $riskScore += 15;
    $riskLevel = $riskScore >= 50 ? 'HIGH' : ($riskScore >= 25 ? 'MEDIUM' : 'LOW');

    // Narrative
    $narrative = sprintf(
        "Target '%s' analyzed across %d cascade layers (%d total nodes, %d verified 3-source). " .
        "Overall identity confidence: %.1f%%. " .
        "Network: %d companies linked. " .
        "Digital footprint: %d platforms, %d breaches, %d historical snapshots. " .
        "Risk: %s (%d/100). Scan completed in %.1fs.",
        $identity['primary_name'],
        count($rawLayers),
        count($graph['nodes']),
        count($verified),
        $overall * 100,
        count($network['companies']),
        count($digital['active_platforms']),
        $digital['breaches_found'],
        $digital['historical_snapshots'],
        $riskLevel,
        $riskScore,
        $elapsed
    );

    return [
        'confidence_overall' => round($overall, 3),
        'threshold_reached'  => $overall >= 0.99,
        'narrative'          => $narrative,
        'identity'           => $identity,
        'network'            => $network,
        'digital_footprint'  => $digital,
        'risk_assessment'    => ['score' => $riskScore, 'level' => $riskLevel, 'anomalies' => $anomalies],
    ];
}

// ============================================================
// MAIN CASCADE LOOP
// ============================================================
$queue = [$initialSeed];
$auditTrail[] = ['layer' => 0, 'seed' => $initialSeed, 'ts' => date('c')];

for ($layer = 0; $layer < $depth; $layer++) {
    if (empty($queue)) break;
    $currentLayer = $queue;
    $queue = [];

    foreach ($currentLayer as $seed) {
        $scan = run_deep_scan($seed, $apiKey, $mode);
        if (!($scan['success'] ?? false)) {
            $graph['cascade_log'][] = ['layer' => $layer, 'seed' => $seed, 'error' => $scan['error'] ?? 'unknown'];
            continue;
        }
        $graph['raw_layers'][] = $scan;
        $graph['cascade_log'][] = [
            'layer' => $layer,
            'seed' => $seed,
            'modules_hit' => count($scan['data'] ?? []),
        ];

        // Register provided seed values as confirmed nodes (self-attestation source)
        foreach ($seed as $k => $v) {
            $type = match($k) {
                'email' => 'email', 'phone' => 'phone', 'domain' => 'domain',
                'handle' => 'handle', 'name' => 'name_variant', 'company' => 'company',
                default => null,
            };
            if ($type) add_node($graph, $type, (string)$v, 'user_provided', 1.0);
        }

        // Harvest new seeds for next layer
        $newSeeds = extract_seeds($scan, $graph, $layer, $initialSeed);
        foreach ($newSeeds as $ns) $queue[] = $ns;

        // Early-exit if 99% threshold reached globally
        $verified = array_filter($graph['nodes'], fn($n) => $n['verified']);
        if ($verified) {
            $avg = array_sum(array_column($verified, 'confidence')) / count($verified);
            if ($avg >= 0.99 && count($verified) >= 5) break 2;
        }
    }
    $auditTrail[] = ['layer' => $layer + 1, 'queued' => count($queue), 'ts' => date('c')];
}

// ============================================================
// ANOMALY + BEHAVIORAL + PREDICTIVE + SYNTHESIS
// ============================================================
$anomalies = detect_sanitized($graph, $graph['raw_layers']);
$fingerprint = behavioral_fingerprint($graph['raw_layers']);
$predictive = predictive_vectors($graph['raw_layers'], $initialSeed);
// AI cross-check: only on deep mode (costs tokens, adds ~10s)
$aiCheck = ($mode === 'deep')
    ? ai_crosscheck($initialSeed, $graph['raw_layers'])
    : ['available' => false, 'reason' => 'mode != deep — enable via mode:"deep" in request'];
$elapsed = microtime(true) - $cascadeStart;
$report = synthesize($graph, $graph['raw_layers'], $initialSeed, $anomalies, $elapsed);
$report['behavioral_fingerprint'] = $fingerprint;
$report['predictive_vectors'] = $predictive;
$report['ai_crosscheck'] = $aiCheck;

// Boost confidence if AI cross-check triangulates strongly with osint-deep findings
if (($aiCheck['triangulation_strength'] ?? '') === 'strong') {
    $report['confidence_overall'] = min(0.99, ($report['confidence_overall'] ?? 0) + 0.15);
    $report['confidence_boost_reason'] = 'AI cross-check triangulated strongly with primary scan (3rd independent source rule)';
}

// ============================================================
// PERSIST — cascade audit trail for learning
// ============================================================
try {
    q("INSERT INTO osint_scans (customer_id_fk, scan_name, scan_email, scan_phone, scan_address, scan_data, deep_scan_data, scanned_by)
       VALUES (?,?,?,?,?,?,?,?)",
      [null,
       $initialSeed['name'] ?? '',
       $initialSeed['email'] ?? '',
       $initialSeed['phone'] ?? '',
       $initialSeed['address'] ?? '',
       json_encode(['vulture_core' => true, 'depth' => $depth, 'mode' => $mode, 'context' => $context]),
       json_encode([
           'report' => $report,
           'graph_summary' => [
               'nodes' => count($graph['nodes']),
               'edges' => count($graph['edges']),
               'layers' => count($graph['raw_layers']),
           ],
           'cascade_log' => $graph['cascade_log'],
           'audit' => $auditTrail,
       ]),
       $_SESSION['uid'] ?? null,
      ]);
} catch (Exception $e) { /* audit persistence best-effort */ }

ob_end_clean();
echo json_encode([
    'success' => true,
    'vulture_core' => '3.0',
    'config' => ['depth' => $depth, 'mode' => $mode, 'context' => $context],
    'report' => $report,
    'graph' => [
        'nodes' => array_values($graph['nodes']),
        'edge_count' => count($graph['edges']),
        'layers_executed' => count($graph['raw_layers']),
    ],
    'cascade_log' => $graph['cascade_log'],
    'elapsed_seconds' => round($elapsed, 2),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
