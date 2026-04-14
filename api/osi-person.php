<?php
/**
 * OSI Person-Dossier — EU/US-regional routing, parallel tool-chain.
 * POST {name?, email?, phone?, username?, region?}
 * Returns: {identity, accounts, emails, phones, addresses, breaches, timeline, raw, summary}
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/llm-helpers.php';
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$name     = trim($in['name']     ?? '');
$email    = trim($in['email']    ?? '');
$phone    = preg_replace('~[^\d+]~', '', $in['phone'] ?? '');
$username = trim($in['username'] ?? '');
$region   = $in['region']        ?? 'auto';

if (!$name && !$email && !$phone && !$username) {
    http_response_code(400);
    echo json_encode(['success'=>false, 'error'=>'Mindestens ein Feld erforderlich: name/email/phone/username']);
    exit;
}

// -------- Region detection --------
function detectRegion(string $email, string $phone): string {
    if ($phone) {
        if (preg_match('~^\+?(49|43|41|40|33|34|39|31|32|30|44|45|46|47|48|351|353|354|356|357|358|359|370|371|372|380|385|386|420|421)~', $phone)) return 'EU';
        if (preg_match('~^\+?1\d{10}~', $phone)) return 'US';
    }
    if ($email) {
        $tld = strtolower(substr(strrchr($email, '.'), 1));
        if (in_array($tld, ['de','eu','at','ch','fr','it','es','nl','be','pl','ro','cz','uk','co.uk','pt','gr','dk','se','no','fi','hu','bg','ie','lu','is','li'])) return 'EU';
        if ($tld === 'gov' || $tld === 'mil' || $tld === 'edu') return 'US';
    }
    return 'AUTO';
}
$regionFinal = $region === 'auto' ? detectRegion($email, $phone) : strtoupper($region);

// -------- Parallel fetch helper --------
function vpsCall(string $tool, array $params, int $timeout = 15): ?array {
    $ch = curl_init('http://89.116.22.185:8900/' . ltrim($tool, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . (defined('API_KEY') ? API_KEY : '')],
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code < 400 && $r ? json_decode($r, true) : null;
}

function curlJson(string $url, array $headers = [], int $timeout = 12): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>$timeout,
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: Fleckfrei-OSI/2.0'], $headers),
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code < 400 && $r ? (json_decode($r, true) ?: ['_raw'=>$r]) : ['_code'=>$code];
}

// -------- Execute tool-chain --------
$findings = ['region' => $regionFinal, 'input' => compact('name','email','phone','username')];

// 1) Maigret (username across 400+ sites)
if ($username) {
    $m = vpsCall('maigret', ['username' => $username], 30);
    $findings['maigret'] = $m ? array_slice((array)$m, 0, 150) : ['error'=>'nicht erreichbar'];
}

// 2) Holehe (email → account existence across 120 services)
if ($email) {
    $h = vpsCall('holehe', ['email' => $email], 25);
    $findings['holehe'] = $h ?: ['error'=>'nicht erreichbar'];
}

// 3) Phoneinfoga
if ($phone) {
    $p = vpsCall('phoneinfoga', ['phone' => $phone], 15);
    $findings['phoneinfoga'] = $p ?: ['error'=>'nicht erreichbar'];
}

// 4) HaveIBeenPwned (breach history)
if ($email) {
    $hibpKey = defined('HIBP_API_KEY') ? HIBP_API_KEY : '';
    if ($hibpKey) {
        $b = curlJson('https://haveibeenpwned.com/api/v3/breachedaccount/' . urlencode($email) . '?truncateResponse=false',
            ['hibp-api-key: ' . $hibpKey], 10);
        $findings['breaches'] = is_array($b) && !isset($b['_code']) ? $b : [];
    }
}

// 5) Socialscan (username validity)
if ($username) {
    $s = vpsCall('socialscan', ['username' => $username], 20);
    $findings['socialscan'] = $s ?: null;
}

// 6) Google-dork queries (via VPS searxng)
$dorkQueries = [];
if ($name)     $dorkQueries[] = "\"$name\" site:linkedin.com OR site:xing.com";
if ($email)    $dorkQueries[] = "\"$email\"";
if ($username) $dorkQueries[] = "\"$username\" site:github.com OR site:reddit.com OR site:twitter.com";
$findings['dorks'] = [];
foreach (array_slice($dorkQueries, 0, 3) as $q) {
    $r = vpsCall('searxng', ['query' => $q], 10);
    if ($r && !empty($r['results'])) {
        $findings['dorks'][$q] = array_map(fn($x) => ['title'=>$x['title'] ?? '', 'url'=>$x['url'] ?? '', 'content'=>mb_substr($x['content'] ?? '', 0, 200)],
                                            array_slice($r['results'], 0, 5));
    }
}

// 7) Region-specific
if ($regionFinal === 'EU' && $name) {
    // Das Örtliche (DE), 11880 — rough fallback via Groq summarization
    $findings['eu_hint'] = [
        'dasoertliche' => "https://www.dasoertliche.de/?kw=" . urlencode($name),
        '11880'        => "https://www.11880.com/suche/" . urlencode($name) . "/deutschland",
        'xing'         => "https://www.xing.com/search/members?keywords=" . urlencode($name),
        'bundesanzeiger' => "https://www.bundesanzeiger.de/pub/de/suchergebnis?4&search=" . urlencode($name),
    ];
}
if ($regionFinal === 'US' && $name) {
    $findings['us_hint'] = [
        'truepeoplesearch' => "https://www.truepeoplesearch.com/results?name=" . urlencode($name),
        'fastpeoplesearch' => "https://www.fastpeoplesearch.com/name/" . urlencode(str_replace(' ', '-', $name)),
        'whitepages'       => "https://www.whitepages.com/name/" . urlencode(str_replace(' ', '-', $name)),
        'spokeo'           => "https://www.spokeo.com/" . urlencode(str_replace(' ', '-', $name)),
    ];
}

// 8) Profile-image search hints
if ($name || $username) {
    $q = $username ?: $name;
    $findings['image_search'] = [
        'google' => "https://www.google.com/search?q=" . urlencode($q) . "&tbm=isch",
        'yandex' => "https://yandex.com/images/search?text=" . urlencode($q),
        'bing'   => "https://www.bing.com/images/search?q=" . urlencode($q),
        'tineye' => "https://tineye.com/search?url=&q=" . urlencode($q),
    ];
}

// -------- Consolidate via LLM --------
$ctx = json_encode($findings, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$ctx = mb_substr($ctx, 0, 12000);
$prompt = <<<P
Du bist OSINT-Analyst. Konsolidiere die Rohbefunde zu einem kompakten, faktenbasierten Personen-Dossier.
Antworte NUR als valides JSON (keine Markdown-Fences):

{
  "identity": {
    "likely_full_name": "string",
    "confidence_pct": 0-100,
    "aliases": [],
    "age_estimate": "string oder null",
    "location_hints": []
  },
  "accounts_found": [
    {"platform":"string","url":"string","handle":"string","confidence":"high|medium|low"}
  ],
  "emails_associated": [],
  "phones_associated": [],
  "addresses_hints": [],
  "employment_hints": [],
  "breach_summary": {
    "count": 0,
    "worst_breach": "string oder null",
    "leaked_data_types": []
  },
  "risk_flags": [],
  "next_osint_steps": [],
  "summary_de": "4-5 Sätze: wer die Person wahrscheinlich ist + was als nächstes zu prüfen ist"
}

ROHBEFUNDE (JSON):
$ctx
P;

$ai = groq_chat($prompt, 1500);
$raw = $ai['content'] ?? '';
$clean = preg_replace('~^```(?:json)?\s*|\s*```$~m', '', trim($raw));
$dossier = json_decode($clean, true) ?: ['raw' => $raw, 'parse_error' => true];

// -------- Store in DB for history --------
try {
    q("CREATE TABLE IF NOT EXISTS osi_person_scans (
        op_id INT AUTO_INCREMENT PRIMARY KEY,
        query_json LONGTEXT, region VARCHAR(8), findings_json LONGTEXT, dossier_json LONGTEXT,
        created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    q("INSERT INTO osi_person_scans (query_json, region, findings_json, dossier_json, created_by) VALUES (?,?,?,?,?)",
      [json_encode($findings['input']), $regionFinal, json_encode($findings), json_encode($dossier), me()['id'] ?? null]);
    audit('osi_person_scan', 'person', 0, "name=$name email=$email phone=$phone user=$username region=$regionFinal");
} catch (Exception $e) {}

echo json_encode([
    'success' => true,
    'region' => $regionFinal,
    'findings' => $findings,
    'dossier' => $dossier,
    'generated_at' => date('c'),
]);
