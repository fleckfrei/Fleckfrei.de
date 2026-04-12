<?php
/**
 * OSI Unified Scan API — triggers all OSINT modules in parallel
 * GET /api/osi-scan.php?q=target
 *
 * Modules:
 * 1. DB Cross-Reference (customer, employee, jobs, invoices)
 * 2. Email Intelligence (MX, Gravatar, domain analysis)
 * 3. Deep Scan (VPS SearXNG via osint-deep.php)
 * 4. OSINT Links (social, firmen, leaks, recht)
 * 5. AI Synthesis (Groq Llama 3.3 → risk score + summary)
 * 6. Persistence (save to osint_scans, never overwrite)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/llm-helpers.php';
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

$startTime = microtime(true);
$query = trim($_GET['q'] ?? $_POST['q'] ?? '');
if (!$query) {
    echo json_encode(['success' => false, 'error' => 'q parameter required']);
    exit;
}

// Detect input type
$isEmail = str_contains($query, '@');
$phoneClean = preg_replace('/[^0-9]/', '', $query);
$isPhone = strlen($phoneClean) >= 8 && !$isEmail;
$isDomain = !$isEmail && !$isPhone && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z]{2,})+$/i', $query);

$result = [
    'success' => true,
    'target' => $query,
    'input_type' => $isEmail ? 'email' : ($isPhone ? 'phone' : ($isDomain ? 'domain' : 'name')),
];

// ============================================================
// MODULE 1: FULL DB Cross-Reference — searches ALL tables
// ============================================================
$db_result = ['customer' => null, 'employee' => null, 'leads' => [], 'users' => [],
              'stats' => null, 'jobs' => [], 'invoices' => [], 'addresses' => [],
              'services' => [], 'osint_history' => [], 'ontology' => [], 'social' => [],
              'messages' => [], 'total_hits' => 0];

// Build flexible WHERE for name/email/phone search
$likeQ = '%'.$query.'%';
$phoneLike = $isPhone ? '%'.substr($phoneClean, -8).'%' : '%impossible%';

// 1a. CUSTOMER — all fields
$cust = one("SELECT c.*, COUNT(j.j_id) as total_jobs, MAX(j.j_date) as last_job, MIN(j.j_date) as first_job
             FROM customer c LEFT JOIN jobs j ON j.customer_id_fk=c.customer_id AND j.status=1
             WHERE c.email LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.surname LIKE ?
             GROUP BY c.customer_id LIMIT 1",
            [$likeQ, $likeQ, $phoneLike, $likeQ]);
if ($cust) {
    $cid = $cust['customer_id'];
    $db_result['customer'] = [
        'id' => $cid, 'name' => $cust['name'], 'surname' => $cust['surname'] ?? '',
        'email' => $cust['email'], 'phone' => $cust['phone'],
        'type' => $cust['customer_type'] ?? '', 'status' => $cust['status'] ? 'Aktiv' : 'Inaktiv',
        'since' => $cust['created_at'] ? date('d.m.Y', strtotime($cust['created_at'])) : '',
        'last_job' => $cust['last_job'] ? date('d.m.Y', strtotime($cust['last_job'])) : '',
        'total_jobs' => (int)$cust['total_jobs'],
    ];
    $stats = one("SELECT
        (SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1) as total_jobs,
        (SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='COMPLETED') as done,
        (SELECT COALESCE(SUM(total_price),0) FROM invoices WHERE customer_id_fk=?) as revenue,
        (SELECT COALESCE(SUM(remaining_price),0) FROM invoices WHERE customer_id_fk=? AND invoice_paid='no') as open_balance",
        [$cid, $cid, $cid, $cid]);
    $db_result['stats'] = [
        'total_jobs' => (int)$stats['total_jobs'], 'completed' => (int)$stats['done'],
        'revenue' => number_format($stats['revenue'], 2, ',', '.') . ' €',
        'open_balance' => number_format($stats['open_balance'], 2, ',', '.') . ' €',
    ];
    $db_result['jobs'] = all("SELECT j_id, j_date, job_status, j_hours, address FROM jobs WHERE customer_id_fk=? AND status=1 ORDER BY j_date DESC LIMIT 10", [$cid]);
    $db_result['invoices'] = all("SELECT inv_id, invoice_number, issue_date, total_price, remaining_price, invoice_paid FROM invoices WHERE customer_id_fk=? ORDER BY issue_date DESC LIMIT 10", [$cid]);
    $db_result['addresses'] = all("SELECT * FROM addresses WHERE entity_type='customer' AND entity_id=?", [$cid]);
    $db_result['services'] = all("SELECT s_id, title, street, city, total_price FROM services WHERE customer_id_fk=? AND status=1", [$cid]);
    try { $db_result['messages'] = all("SELECT msg_id, sender_type, sender_name, message, created_at FROM messages WHERE (sender_type='customer' AND sender_id=?) OR (recipient_type='customer' AND recipient_id=?) ORDER BY created_at DESC LIMIT 10", [$cid, $cid]); } catch (Exception $e) {}
    $db_result['total_hits']++;
}

// 1b. EMPLOYEE — all fields
$emp = one("SELECT * FROM employee WHERE email LIKE ? OR name LIKE ? OR phone LIKE ? OR surname LIKE ? LIMIT 1",
           [$likeQ, $likeQ, $phoneLike, $likeQ]);
if ($emp) {
    $db_result['employee'] = [
        'id' => $emp['emp_id'], 'name' => trim(($emp['name'] ?? '') . ' ' . ($emp['surname'] ?? '')),
        'email' => $emp['email'] ?? '', 'phone' => $emp['phone'] ?? '',
        'type' => $emp['employee_type'] ?? '', 'status' => ($emp['status'] ?? 0) ? 'Aktiv' : 'Inaktiv',
        'language' => $emp['language'] ?? '',
    ];
    $db_result['total_hits']++;
}

// 1c. LEADS — search in leads table (1420 entries!)
$db_result['leads'] = all("SELECT lead_id, name, email, phone, source, status, created_at FROM leads WHERE name LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY created_at DESC LIMIT 5",
    [$likeQ, $likeQ, $phoneLike]);
$db_result['total_hits'] += count($db_result['leads']);

// 1d. USERS — all registered accounts
$db_result['users'] = all("SELECT user_id as id, email, type FROM users WHERE email LIKE ? LIMIT 5", [$likeQ]);
$db_result['total_hits'] += count($db_result['users']);

// 1e. OSINT HISTORY — all previous scans for this target
$db_result['osint_history'] = all("SELECT scan_id, scan_name, scan_email, scan_phone, created_at,
    LENGTH(scan_data) as data_size, LENGTH(deep_scan_data) as deep_size
    FROM osint_scans WHERE scan_name LIKE ? OR scan_email LIKE ? OR scan_phone LIKE ?
    ORDER BY created_at DESC LIMIT 10",
    [$likeQ, $likeQ, $phoneLike]);
$db_result['total_hits'] += count($db_result['osint_history']);

// 1f. ONTOLOGY — graph objects matching this target
$db_result['ontology'] = all("SELECT obj_id, obj_type, display_name, confidence, source_count FROM ontology_objects WHERE display_name LIKE ? OR obj_key LIKE ? LIMIT 10",
    [$likeQ, $likeQ]);
$db_result['total_hits'] += count($db_result['ontology']);

// 1g. SOCIAL LINKS — any social profiles
try {
    $db_result['social'] = all("SELECT * FROM social_links WHERE url LIKE ? OR platform LIKE ? LIMIT 10", [$likeQ, $likeQ]);
    $db_result['total_hits'] += count($db_result['social']);
} catch (Exception $e) {}

// 1h. JOBS — search by address or note containing target name
$db_result['related_jobs'] = all("SELECT j_id, j_date, job_status, address, job_note FROM jobs WHERE (address LIKE ? OR job_note LIKE ?) AND status=1 ORDER BY j_date DESC LIMIT 5",
    [$likeQ, $likeQ]);
$db_result['total_hits'] += count($db_result['related_jobs']);

$result['db'] = $db_result;

// ============================================================
// MODULE 2: Email Intelligence
// ============================================================
$email_result = null;
$primaryEmail = $isEmail ? $query : ($cust['email'] ?? '');
if ($primaryEmail) {
    $domain = strtolower(substr($primaryEmail, strpos($primaryEmail, '@') + 1));
    $local = strtolower(substr($primaryEmail, 0, strpos($primaryEmail, '@')));
    $mx = @dns_get_record($domain, DNS_MX);
    $free = in_array($domain, ['gmail.com','yahoo.com','hotmail.com','outlook.com','gmx.de','web.de','t-online.de','icloud.com','protonmail.com']);
    $email_result = [
        'email' => $primaryEmail,
        'domain' => $domain,
        'local' => $local,
        'mx' => $mx ? count($mx) : 0,
        'mx_host' => !empty($mx) ? $mx[0]['target'] : null,
        'free' => $free,
        'business' => !$free,
        'gravatar_url' => 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($primaryEmail))) . '?d=404&s=120',
    ];
}
$result['email'] = $email_result;

// ============================================================
// MODULE 3: Deep Scan (via VPS SearXNG)
// ============================================================
$deep_result = ['findings' => [], 'raw' => null];
$searchName = $cust['name'] ?? ($isEmail ? $local : $query);
try {
    $deepResp = vps_call('searxng', ['query' => $searchName . ' ' . ($primaryEmail ?: '') . ' Berlin', 'categories' => 'general', 'limit' => 30], false);
    if (is_array($deepResp) && !empty($deepResp['results'])) {
        foreach ($deepResp['results'] as $r) {
            $title = $r['title'] ?? '';
            $snippet = $r['snippet'] ?? $r['content'] ?? '';
            $url = $r['url'] ?? '';
            // Categorize findings
            $severity = 'info';
            $category = 'Web';
            if (preg_match('/insolvenz|bankrott|vollstreckung|pfändung/i', $title . $snippet)) { $severity = 'critical'; $category = 'Recht'; }
            elseif (preg_match('/betrug|scam|warnung|fraud|anzeige/i', $title . $snippet)) { $severity = 'high'; $category = 'Warnung'; }
            elseif (preg_match('/linkedin|xing|facebook|instagram/i', $url)) { $severity = 'info'; $category = 'Social'; }
            elseif (preg_match('/handelsregister|northdata|bundesanzeiger/i', $url)) { $severity = 'info'; $category = 'Firma'; }
            elseif (preg_match('/leak|breach|pwned|pastebin/i', $title . $snippet)) { $severity = 'high'; $category = 'Leak'; }

            $deep_result['findings'][] = [
                'title' => mb_substr($title, 0, 120),
                'url' => $url,
                'snippet' => mb_substr($snippet, 0, 200),
                'category' => $category,
                'severity' => $severity,
            ];
        }
    }
} catch (Exception $e) {
    $deep_result['error'] = $e->getMessage();
}
$result['deep_scan'] = $deep_result;

// ============================================================
// MODULE 4: OSINT Quick Links
// ============================================================
$ne = urlencode($searchName);
$ee = urlencode($primaryEmail);
$result['osint_links'] = [
    'Social' => [
        ['label' => 'Google', 'url' => 'https://www.google.com/search?q='.$ne],
        ['label' => 'Facebook', 'url' => 'https://www.facebook.com/search/top/?q='.$ne],
        ['label' => 'Instagram', 'url' => 'https://www.instagram.com/'.$ne.'/'],
        ['label' => 'LinkedIn', 'url' => 'https://www.google.com/search?q=site:linkedin.com/in+'.$ne],
        ['label' => 'XING', 'url' => 'https://www.xing.com/search/members?keywords='.$ne],
        ['label' => 'TikTok', 'url' => 'https://www.tiktok.com/search?q='.$ne],
    ],
    'Firmen' => [
        ['label' => 'Northdata', 'url' => 'https://www.northdata.de/'.$ne],
        ['label' => 'Handelsregister', 'url' => 'https://www.handelsregister.de/rp_web/search.xhtml?schlagwoerter='.$ne],
        ['label' => 'Bundesanzeiger', 'url' => 'https://www.bundesanzeiger.de/pub/de/to_nlp_start?destatis_nlp_q='.$ne],
        ['label' => 'OpenCorporates', 'url' => 'https://opencorporates.com/companies?q='.$ne],
    ],
    'Leaks' => [
        ['label' => 'HIBP', 'url' => 'https://haveibeenpwned.com/account/'.urlencode($primaryEmail)],
        ['label' => 'DeHashed', 'url' => 'https://www.dehashed.com/search?query='.$ne],
        ['label' => 'IntelX', 'url' => 'https://intelx.io/?s='.$ne],
    ],
    'Recht' => [
        ['label' => 'Insolvenz', 'url' => 'https://www.google.com/search?q='.$ne.'+Insolvenz'],
        ['label' => 'EU Sanctions', 'url' => 'https://www.sanctionsmap.eu/#/main?search=%7B%22value%22:'.$ne.'%7D'],
        ['label' => 'Betrug/Scam', 'url' => 'https://www.google.com/search?q='.$ne.'+Betrug+OR+Scam'],
    ],
    'Telefonbuch' => [
        ['label' => 'Das Örtliche', 'url' => 'https://www.dasoertliche.de/Themen/'.$ne],
        ['label' => '11880', 'url' => 'https://www.11880.com/suche/'.$ne.'/bundesweit'],
        ['label' => 'Tellows', 'url' => 'https://www.tellows.de/num/'.urlencode($phoneClean ?: '')],
    ],
];

// ============================================================
// MODULE 5: AI Synthesis (Groq Llama 3.3)
// ============================================================
$aiData = [
    'target' => $query,
    'is_customer' => !empty($db_result['customer']),
    'customer_type' => $db_result['customer']['type'] ?? 'unknown',
    'total_jobs' => $db_result['stats']['total_jobs'] ?? 0,
    'revenue' => $db_result['stats']['revenue'] ?? '0',
    'open_balance' => $db_result['stats']['open_balance'] ?? '0',
    'email_business' => $email_result['business'] ?? false,
    'email_domain' => $email_result['domain'] ?? '',
    'deep_findings_count' => count($deep_result['findings']),
    'critical_findings' => count(array_filter($deep_result['findings'], fn($f) => $f['severity'] === 'critical')),
    'high_findings' => count(array_filter($deep_result['findings'], fn($f) => $f['severity'] === 'high')),
];

$aiPrompt = "Du bist ein OSINT-Analyst. Analysiere diese Daten über eine Person/Firma und erstelle eine Bewertung.

DATEN:
" . json_encode($aiData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

Deep-Scan Funde (Top 5):
" . json_encode(array_slice($deep_result['findings'], 0, 5), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "

Antworte NUR als JSON:
{
  \"score\": 0-100 (0=sehr riskant, 100=vertrauenswürdig),
  \"risk_level\": \"LOW\" | \"MEDIUM\" | \"HIGH\",
  \"summary\": \"2-3 Sätze Zusammenfassung auf Deutsch\",
  \"opportunities\": [\"Chancen/positive Punkte\"],
  \"risks\": [\"Risiken/negative Punkte\"],
  \"recommendation\": \"Empfehlung für den Umgang mit dieser Person/Firma\"
}";

$aiResult = groq_chat($aiPrompt, 600);
$aiParsed = null;
if ($aiResult && !empty($aiResult['content'])) {
    if (preg_match('/\{[\s\S]*\}/u', $aiResult['content'], $jsonMatch)) {
        $aiParsed = json_decode($jsonMatch[0], true);
    }
}

$result['score'] = $aiParsed['score'] ?? 50;
$result['risk_level'] = $aiParsed['risk_level'] ?? 'MEDIUM';
$result['ai_summary'] = $aiParsed['summary'] ?? null;
$result['ai_opportunities'] = $aiParsed['opportunities'] ?? [];
$result['ai_risks'] = $aiParsed['risks'] ?? [];
$result['ai_recommendation'] = $aiParsed['recommendation'] ?? null;
$result['total_findings'] = count($deep_result['findings']);
$result['modules_count'] = 6;

// ============================================================
// MODULE 6: Persistence — save to osint_scans (NEVER overwrite)
// ============================================================
$scanTime = round(microtime(true) - $startTime, 2);
$result['scan_time'] = $scanTime;

$scanData = json_encode([
    'input_type' => $result['input_type'],
    'db' => $db_result,
    'email' => $email_result,
    'score' => $result['score'],
    'risk_level' => $result['risk_level'],
    'ai' => $aiParsed,
    'findings_count' => count($deep_result['findings']),
], JSON_UNESCAPED_UNICODE);

$deepScanData = json_encode([
    'deep_scan' => $deep_result,
    'osint_links' => $result['osint_links'],
], JSON_UNESCAPED_UNICODE);

q("INSERT INTO osint_scans (scan_name, scan_email, scan_phone, scan_data, deep_scan_data, scanned_by, created_at)
   VALUES (?, ?, ?, ?, ?, ?, NOW())",
  [
    !$isEmail ? $query : ($cust['name'] ?? $local),
    $isEmail ? $query : ($cust['email'] ?? ''),
    $isPhone ? $query : ($cust['phone'] ?? ''),
    $scanData,
    $deepScanData,
    $_SESSION['uid'] ?? 0,
  ]);

$result['scan_id'] = (int)lastInsertId();

// History for this target
$result['history'] = array_map(fn($h) => [
    'scan_id' => $h['scan_id'],
    'date' => date('d.m.Y H:i', strtotime($h['created_at'])),
    'data_size' => round(($h['data_size'] + $h['deep_size']) / 1024, 1) . ' KB',
], $targetHistory ?? []);

// Messages
$result['messages'] = [];
if (!empty($cust)) {
    try {
        $msgs = all("SELECT msg_id, sender_type, sender_name, message, created_at FROM messages WHERE (sender_type='customer' AND sender_id=?) OR (recipient_type='customer' AND recipient_id=?) ORDER BY created_at DESC LIMIT 10",
            [$cust['customer_id'], $cust['customer_id']]);
        $result['messages'] = array_map(fn($m) => [
            'id' => $m['msg_id'],
            'sender' => $m['sender_name'] ?: $m['sender_type'],
            'text' => mb_substr($m['message'], 0, 200),
            'date' => date('d.m. H:i', strtotime($m['created_at'])),
            'mine' => $m['sender_type'] === 'admin',
        ], $msgs);
    } catch (Exception $e) {}
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
