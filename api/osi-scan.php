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
ob_start(); // Catch any warnings before JSON output
set_time_limit(120);
ini_set('memory_limit', '256M');
ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE); // Only fatal errors
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

// 1i. GOOGLE SHEETS — search in indexed sheet_index table (675 entries from 3 sheets)
$db_result['google_sheets'] = [];
try {
    $sheetHits = all("SELECT sheet_name, tab_name, row_data, searchable_text FROM sheet_index WHERE MATCH(searchable_text) AGAINST(? IN BOOLEAN MODE) LIMIT 15",
        [$query . '*']);
    // Fallback to LIKE if fulltext returns nothing
    if (empty($sheetHits)) {
        $sheetHits = all("SELECT sheet_name, tab_name, row_data FROM sheet_index WHERE searchable_text LIKE ? LIMIT 15", [$likeQ]);
    }
    foreach ($sheetHits as $sh) {
        $rowData = json_decode($sh['row_data'], true) ?: [];
        $db_result['google_sheets'][] = [
            'sheet' => $sh['sheet_name'],
            'name' => $rowData['Name'] ?? $rowData['name'] ?? array_values($rowData)[0] ?? '',
            'description' => $rowData['Ws ist das'] ?? $rowData['Was ist das'] ?? $rowData['wat_its'] ?? '',
            'link' => $rowData['Link'] ?? $rowData['website_link'] ?? '',
            'firma' => $rowData['Firma _aktiv'] ?? $rowData['Firma_aktiv'] ?? '',
        ];
    }
    $db_result['total_hits'] += count($db_result['google_sheets']);
} catch (Exception $e) {}

// 1j. GOOGLE SERVICES — Gmail, Contacts, Drive (if connected)
require_once __DIR__ . '/../includes/google-helpers.php';
if (google_is_connected()) {
    // Gmail
    try {
        $gmailResults = google_gmail_search($query, 5);
        $db_result['gmail'] = array_map(fn($m) => [
            'subject' => $m['subject'], 'from' => $m['from'],
            'date' => $m['date'], 'snippet' => mb_substr($m['snippet'], 0, 150),
        ], $gmailResults);
        $db_result['total_hits'] += count($db_result['gmail']);
    } catch (Exception $e) { $db_result['gmail'] = []; }

    // Contacts
    try {
        $contactResults = google_contacts_search($query, 5);
        $db_result['google_contacts'] = $contactResults;
        $db_result['total_hits'] += count($contactResults);
    } catch (Exception $e) { $db_result['google_contacts'] = []; }

    // Drive
    try {
        $driveResults = google_drive_search($query, 5);
        $db_result['google_drive'] = array_map(fn($f) => [
            'name' => $f['name'], 'type' => $f['mimeType'] ?? '',
            'link' => $f['webViewLink'] ?? '', 'modified' => $f['modifiedTime'] ?? '',
        ], $driveResults);
        $db_result['total_hits'] += count($db_result['google_drive']);
    } catch (Exception $e) { $db_result['google_drive'] = []; }
} else {
    $db_result['google_status'] = 'not_connected';
}

// 1k. PHOTO SCORES — AI cleanliness ratings for this customer's jobs
$db_result['photo_scores'] = [];
if (!empty($cust)) {
    try {
        $db_result['photo_scores'] = all("SELECT pa.pa_id, pa.score, pa.photo_type, pa.photo_path, pa.created_at,
            j.j_id, j.j_date FROM photo_analyses pa
            LEFT JOIN jobs j ON pa.job_id_fk=j.j_id
            WHERE j.customer_id_fk=? ORDER BY pa.created_at DESC LIMIT 5", [$cust['customer_id']]);
        $db_result['total_hits'] += count($db_result['photo_scores']);
    } catch (Exception $e) {}
}

// ============================================================
// MODULE 1L-1U: EXTENDED DATA SOURCES (all real-time)
// ============================================================

// 1L. GOOGLE CALENDAR — events matching target
if (google_is_connected()) {
    try {
        $calResult = google_api('https://www.googleapis.com/calendar/v3/calendars/primary/events?' . http_build_query([
            'q' => $query, 'maxResults' => 5, 'orderBy' => 'startTime', 'singleEvents' => 'true',
            'timeMin' => date('c', strtotime('-1 year')), 'timeMax' => date('c', strtotime('+1 year')),
        ]));
        $db_result['google_calendar'] = array_map(fn($e) => [
            'summary' => $e['summary'] ?? '', 'start' => $e['start']['dateTime'] ?? $e['start']['date'] ?? '',
            'location' => $e['location'] ?? '', 'status' => $e['status'] ?? '',
        ], $calResult['items'] ?? []);
        $db_result['total_hits'] += count($db_result['google_calendar']);
    } catch (Exception $e) { $db_result['google_calendar'] = []; }
}

// 1M. STRIPE — payments/customers matching target
try {
    $stripeKey = val("SELECT config_value FROM app_config WHERE config_key='stripe_live_key'");
    if (!$stripeKey && defined('STRIPE_SECRET_KEY')) $stripeKey = STRIPE_SECRET_KEY;
    if ($stripeKey) {
        $ch = curl_init('https://api.stripe.com/v1/customers/search?' . http_build_query(['query' => "email:\"$query\" OR name:\"$query\"", 'limit' => 5]));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $stripeKey], CURLOPT_TIMEOUT => 8]);
        $stripeResp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $db_result['stripe'] = array_map(fn($c) => [
            'id' => $c['id'], 'name' => $c['name'] ?? '', 'email' => $c['email'] ?? '',
            'balance' => ($c['balance'] ?? 0) / 100 . ' €', 'created' => date('d.m.Y', $c['created'] ?? 0),
        ], $stripeResp['data'] ?? []);
        $db_result['total_hits'] += count($db_result['stripe']);
    }
} catch (Exception $e) { $db_result['stripe'] = []; }

// 1N. WHATSAPP (Evolution API on VPS) — message search
try {
    $waToken = val("SELECT config_value FROM app_config WHERE config_key='evolution_api_token'");
    if (!$waToken) {
        // Try from Google Sheet data
        $waRow = one("SELECT row_data FROM sheet_index WHERE searchable_text LIKE '%Evolution%' AND sheet_name='Fleckfrei_pass' LIMIT 1");
        if ($waRow) { $wd = json_decode($waRow['row_data'], true); $waToken = $wd['User'] ?? ''; }
    }
    if ($waToken && str_contains($waToken, 'http')) {
        // Evolution API: search contacts
        $ch = curl_init($waToken . '/chat/findContacts/fleckfrei');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['apikey: ' . ($wd['Link'] ?? '')]]);
        $waResp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (is_array($waResp)) {
            $db_result['whatsapp'] = array_filter(array_map(fn($c) => [
                'name' => $c['pushName'] ?? $c['name'] ?? '',
                'number' => $c['id'] ?? '',
            ], array_slice($waResp, 0, 20)), fn($c) => stripos($c['name'] . $c['number'], $query) !== false);
            $db_result['whatsapp'] = array_values(array_slice($db_result['whatsapp'], 0, 5));
            $db_result['total_hits'] += count($db_result['whatsapp']);
        }
    }
} catch (Exception $e) { $db_result['whatsapp'] = []; }

// 1O. TELEGRAM BOT — search in bot messages (via Telegram API)
try {
    $tgToken = defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '';
    if ($tgToken) {
        // Get recent updates and filter by query
        $ch = curl_init("https://api.telegram.org/bot{$tgToken}/getUpdates?limit=100");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $tgResp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $tgMatches = [];
        foreach ($tgResp['result'] ?? [] as $upd) {
            $msg = $upd['message'] ?? $upd['edited_message'] ?? null;
            if (!$msg) continue;
            $text = $msg['text'] ?? '';
            $from = ($msg['from']['first_name'] ?? '') . ' ' . ($msg['from']['last_name'] ?? '');
            if (stripos($text . $from, $query) !== false) {
                $tgMatches[] = ['from' => trim($from), 'text' => mb_substr($text, 0, 100), 'date' => date('d.m.Y H:i', $msg['date'] ?? 0)];
            }
        }
        $db_result['telegram'] = array_slice($tgMatches, 0, 5);
        $db_result['total_hits'] += count($db_result['telegram']);
    }
} catch (Exception $e) { $db_result['telegram'] = []; }

// 1P. N8N WORKFLOWS — search workflow names/descriptions
try {
    $n8nUrl = defined('N8N_URL') ? N8N_URL : 'https://n8n.la-renting.com';
    $n8nKey = val("SELECT config_value FROM app_config WHERE config_key='n8n_api_key'");
    if ($n8nKey) {
        $ch = curl_init($n8nUrl . '/api/v1/workflows');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['X-N8N-API-KEY: ' . $n8nKey]]);
        $n8nResp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $db_result['n8n'] = array_filter(array_map(fn($w) => [
            'name' => $w['name'] ?? '', 'active' => $w['active'] ?? false, 'id' => $w['id'] ?? '',
        ], $n8nResp['data'] ?? []), fn($w) => stripos($w['name'], $query) !== false);
        $db_result['n8n'] = array_values(array_slice($db_result['n8n'], 0, 5));
        $db_result['total_hits'] += count($db_result['n8n']);
    }
} catch (Exception $e) { $db_result['n8n'] = []; }

// 1Q. DNS/WHOIS — domain intelligence (if query looks like domain)
if ($isDomain || ($emailInfo && $emailInfo['business'])) {
    $domainToCheck = $isDomain ? $query : ($emailInfo['domain'] ?? '');
    if ($domainToCheck) {
        try {
            // DNS records
            $dns = @dns_get_record($domainToCheck, DNS_A + DNS_MX + DNS_TXT);
            $dnsInfo = ['a' => [], 'mx' => [], 'txt' => [], 'spf' => null, 'dmarc' => null];
            foreach ($dns ?: [] as $r) {
                if ($r['type'] === 'A') $dnsInfo['a'][] = $r['ip'];
                if ($r['type'] === 'MX') $dnsInfo['mx'][] = $r['target'] . ' (pri ' . $r['pri'] . ')';
                if ($r['type'] === 'TXT') {
                    $txt = $r['txt'] ?? '';
                    if (str_starts_with($txt, 'v=spf1')) $dnsInfo['spf'] = $txt;
                    if (str_starts_with($txt, 'v=DMARC1')) $dnsInfo['dmarc'] = $txt;
                    $dnsInfo['txt'][] = mb_substr($txt, 0, 80);
                }
            }
            // SSL cert check
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false]]);
            $s = @stream_socket_client("ssl://$domainToCheck:443", $e, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
            if ($s) {
                $cert = openssl_x509_parse(stream_context_get_params($s)['options']['ssl']['peer_certificate']);
                $dnsInfo['ssl'] = [
                    'issuer' => $cert['issuer']['O'] ?? '?',
                    'expires' => date('d.m.Y', $cert['validTo_time_t'] ?? 0),
                    'days_left' => (int)floor(($cert['validTo_time_t'] - time()) / 86400),
                ];
                fclose($s);
            }
            // HTTP check
            $ch = curl_init("https://$domainToCheck");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false]);
            curl_exec($ch);
            $dnsInfo['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $dnsInfo['redirect'] = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            $db_result['dns'] = $dnsInfo;
            $db_result['total_hits']++;
        } catch (Exception $e) {}
    }
}

// 1R. HAVEIBEENPWNED — data breach check (if email)
if ($primaryEmail) {
    try {
        $ch = curl_init('https://haveibeenpwned.com/api/v3/breachedaccount/' . urlencode($primaryEmail) . '?truncateResponse=true');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['hibp-api-key: ' . (defined('HIBP_API_KEY') ? HIBP_API_KEY : ''), 'user-agent: Fleckfrei-OSI']]);
        $hibpResp = curl_exec($ch);
        $hibpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($hibpCode === 200) {
            $breaches = json_decode($hibpResp, true) ?: [];
            $db_result['data_breaches'] = array_map(fn($b) => $b['Name'] ?? $b, array_slice($breaches, 0, 10));
            $db_result['total_hits'] += count($db_result['data_breaches']);
        } elseif ($hibpCode === 404) {
            $db_result['data_breaches'] = []; // clean — no breaches
        }
    } catch (Exception $e) {}
}

// 1S. FINANZ-CHECK — Insolvenz, Schulden, Handelsregister, Bonität (KOSTENLOS)
$db_result['finance'] = ['insolvenz' => [], 'handelsregister' => [], 'schulden_hinweise' => [], 'bewertungen' => []];
$searchName = $cust['name'] ?? ($isEmail ? '' : $query);
$searchSurname = $cust['surname'] ?? '';
$searchFullName = trim($searchName . ' ' . $searchSurname);

// Get address for precise identification
$searchAddress = '';
$searchCity = 'Berlin';
$searchPostal = '';
if (!empty($cust)) {
    $addr = one("SELECT street, number, postal_code, city FROM addresses WHERE entity_type='customer' AND entity_id=? LIMIT 1", [$cust['customer_id']]);
    if ($addr) {
        $searchAddress = trim(($addr['street'] ?? '') . ' ' . ($addr['number'] ?? ''));
        $searchCity = $addr['city'] ?: 'Berlin';
        $searchPostal = $addr['postal_code'] ?? '';
    }
}

// Identity proof — store exactly what was searched
$db_result['finance']['identity'] = [
    'name' => $searchFullName,
    'address' => $searchAddress,
    'postal_code' => $searchPostal,
    'city' => $searchCity,
    'email' => $primaryEmail,
    'phone' => $cust['phone'] ?? '',
    'customer_id' => $cust['customer_id'] ?? null,
    'verified_by' => $searchAddress ? 'Name + Adresse + PLZ' : ($primaryEmail ? 'Name + Email' : 'Nur Name (ungenau)'),
    'confidence' => $searchAddress ? 'HOCH' : ($primaryEmail ? 'MITTEL' : 'NIEDRIG'),
];

$vpsAvailable = $deep_result['error'] === null; // Set by deep scan check above
if ($searchFullName && strlen($searchFullName) > 2 && $vpsAvailable) {
    // Use full name + city + address for precise search
    $exactName = '"' . $searchFullName . '"';
    $locationFilter = $searchCity ? ' ' . $searchCity : '';
    $addressFilter = $searchAddress ? ' "' . $searchAddress . '"' : '';
    $financeQueries = [
        'insolvenz' => $exactName . $locationFilter . ' Insolvenz site:insolvenzbekanntmachungen.de',
        'vollstreckung' => $exactName . $locationFilter . ' Vollstreckung OR Pfändung OR Schuldner',
        'handelsregister' => $exactName . ' site:northdata.de OR site:handelsregister.de OR site:unternehmensregister.de',
        'schufa' => $exactName . $locationFilter . ' Schufa OR Inkasso OR Mahnung OR Zahlungsverzug',
        'betrug' => $exactName . $locationFilter . ' Betrug OR Scam OR Warnung OR "nicht bezahlt"',
    ];

    foreach ($financeQueries as $type => $fQuery) {
        try {
            $fResp = vps_call('searxng', ['query' => $fQuery, 'categories' => 'general', 'limit' => 5], true);
            if (is_array($fResp) && !empty($fResp['results'])) {
                foreach (array_slice($fResp['results'], 0, 5) as $r) {
                    $title = $r['title'] ?? '';
                    $url = $r['url'] ?? '';
                    $snippet = $r['snippet'] ?? $r['content'] ?? '';
                    // Skip irrelevant results — name MUST appear in title or snippet
                    if (!$title || str_contains($url, 'fleckfrei.de')) continue;
                    $nameLower = mb_strtolower($searchName);
                    $contentLower = mb_strtolower($title . ' ' . $snippet);
                    // Check if at least the surname or full name appears
                    $nameParts = explode(' ', $nameLower);
                    $lastPart = end($nameParts); // surname usually last
                    if (!str_contains($contentLower, $nameLower) && !str_contains($contentLower, $lastPart)) continue;

                    $category = match($type) {
                        'insolvenz' => 'insolvenz',
                        'vollstreckung' => 'schulden_hinweise',
                        'handelsregister' => 'handelsregister',
                        'schufa' => 'schulden_hinweise',
                        'betrug' => 'bewertungen',
                    };
                    $severity = 'info';
                    if (preg_match('/insolvenz|bankrott|pleite/i', $title . $snippet)) $severity = 'critical';
                    elseif (preg_match('/vollstreckung|pfändung|mahnung|inkasso|schuld/i', $title . $snippet)) $severity = 'high';
                    elseif (preg_match('/betrug|scam|warnung|abzocke/i', $title . $snippet)) $severity = 'high';

                    $db_result['finance'][$category][] = [
                        'title' => mb_substr($title, 0, 120),
                        'url' => $url,
                        'snippet' => mb_substr($snippet, 0, 200),
                        'severity' => $severity,
                        'source' => parse_url($url, PHP_URL_HOST) ?: '',
                    ];
                }
            }
        } catch (Exception $e) {}
    }

    $totalFinance = array_sum(array_map('count', $db_result['finance']));
    $db_result['total_hits'] += $totalFinance;

    // Quick Bonität-Score based on findings
    $criticalCount = 0; $highCount = 0;
    foreach ($db_result['finance'] as $cat => $items) {
        foreach ($items as $item) {
            if ($item['severity'] === 'critical') $criticalCount++;
            if ($item['severity'] === 'high') $highCount++;
        }
    }
    // Track which sources were actually checked
    $checkedSources = [];
    foreach ($financeQueries as $type => $fq) {
        $checkedSources[] = match($type) {
            'insolvenz' => 'Insolvenzbekanntmachungen.de',
            'vollstreckung' => 'Vollstreckungsportal / Google',
            'handelsregister' => 'Northdata / Handelsregister / Unternehmensregister',
            'schufa' => 'Google (Schufa/Inkasso/Mahnung Erwähnungen)',
            'betrug' => 'Google (Betrug/Scam/Warnung)',
        };
    }

    $db_result['finance']['bonitaet_score'] = match(true) {
        $criticalCount > 0 => ['level' => 'KRITISCH', 'color' => 'red', 'text' => 'Insolvenz/Vollstreckung gefunden — SOFORT PRÜFEN'],
        $highCount > 0 => ['level' => 'WARNUNG', 'color' => 'amber', 'text' => 'Negative Einträge in öffentlichen Quellen gefunden'],
        $totalFinance > 0 => ['level' => 'NEUTRAL', 'color' => 'gray', 'text' => 'Erwähnungen gefunden, keine direkten Risiken'],
        default => ['level' => 'KEINE TREFFER', 'color' => 'green', 'text' => 'In öffentlichen Online-Quellen nichts Negatives gefunden'],
    };
    $db_result['finance']['checked_sources'] = $checkedSources;
    $db_result['finance']['disclaimer'] = 'Automatische Suche in öffentlichen Quellen. KEIN Ersatz für eine echte Schufa-Auskunft oder Bonitätsprüfung. Zeigt nur was öffentlich im Internet auffindbar ist.';
    $db_result['finance']['searched_name'] = $searchName;
}

// 1T. OPENCORPORATES — free company API (200 requests/day)
try {
    if ($searchName && strlen($searchName) > 2) {
        $ocUrl = 'https://api.opencorporates.com/v0.4/companies/search?' . http_build_query([
            'q' => $searchName, 'jurisdiction_code' => 'de', 'per_page' => 3,
        ]);
        $ch = curl_init($ocUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        $ocResp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $db_result['opencorporates'] = array_map(fn($c) => [
            'name' => $c['company']['name'] ?? '',
            'number' => $c['company']['company_number'] ?? '',
            'status' => $c['company']['current_status'] ?? '',
            'jurisdiction' => $c['company']['jurisdiction_code'] ?? '',
            'url' => $c['company']['opencorporates_url'] ?? '',
            'incorporation_date' => $c['company']['incorporation_date'] ?? '',
        ], $ocResp['results']['companies'] ?? []);
        $db_result['total_hits'] += count($db_result['opencorporates']);
    }
} catch (Exception $e) { $db_result['opencorporates'] = []; }

// 1U. WAYBACK MACHINE — historical snapshots
if ($isDomain || ($emailInfo && $emailInfo['business'])) {
    $wbDomain = $isDomain ? $query : ($emailInfo['domain'] ?? '');
    if ($wbDomain) {
        try {
            $ch = curl_init("https://web.archive.org/wayback/available?url=$wbDomain&timestamp=" . date('Ymd'));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
            $wbResp = json_decode(curl_exec($ch), true);
            curl_close($ch);
            $snapshot = $wbResp['archived_snapshots']['closest'] ?? null;
            if ($snapshot) {
                $db_result['wayback'] = [
                    'url' => $snapshot['url'],
                    'timestamp' => $snapshot['timestamp'],
                    'status' => $snapshot['status'],
                    'available' => $snapshot['available'] ?? false,
                ];
                $db_result['total_hits']++;
            }
        } catch (Exception $e) {}
    }
}

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
$deep_result = ['findings' => [], 'raw' => null, 'error' => null];
$searchName = $cust['name'] ?? ($isEmail ? $local : $query);
try {
    // Quick check: is VPS reachable? (connect-only test)
    $testCh = curl_init('http://89.116.22.185:8900/');
    curl_setopt_array($testCh, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_NOBODY => false,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    curl_exec($testCh);
    $vpsCode = curl_getinfo($testCh, CURLINFO_HTTP_CODE);
    $vpsReachable = $vpsCode > 0; // Any response = reachable (even 4xx/5xx)
    curl_close($testCh);
    if (!$vpsReachable) { $deep_result['error'] = 'VPS nicht erreichbar'; throw new Exception('VPS offline'); }
    $deepResp = vps_call('searxng', ['query' => $searchName . ' ' . ($primaryEmail ?: '') . ' Berlin', 'categories' => 'general', 'limit' => 15], true);
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

$aiParsed = null;
try {
    $aiResult = groq_chat($aiPrompt, 600);
    if ($aiResult && !empty($aiResult['content'])) {
        if (preg_match('/\{[\s\S]*\}/u', $aiResult['content'], $jsonMatch)) {
            $aiParsed = json_decode($jsonMatch[0], true);
        }
    }
} catch (Exception $e) { /* AI timeout — continue without AI */ }

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

// Ensure clean JSON output — no PHP warnings/notices breaking the response
ob_end_clean(); // Clear any buffered warnings
ob_start();
echo json_encode($result, JSON_UNESCAPED_UNICODE);
ob_end_flush();
