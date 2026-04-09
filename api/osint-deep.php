<?php
/**
 * Fleckfrei OSI Deep Scan API
 * Intelligence gathering with DB cross-reference + external APIs.
 */
ini_set('max_execution_time', 120);
// Prevent web server timeout
if (function_exists('set_time_limit')) set_time_limit(120);
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
@ini_set('output_buffering', 'Off');
header('X-Accel-Buffering: no'); // nginx
// Send early headers to prevent proxy timeout
ignore_user_abort(true);
set_error_handler(function($errno, $errstr) {
    // Suppress warnings, continue execution
    return true;
});
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['success'=>false, 'error'=>'Server error: '.$e['message'], 'data'=>[]]);
    }
});
ob_start();

header('Content-Type: application/json');
$_osi_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$_osi_allowed = ['https://app.fleckfrei.de', 'https://fleckfrei.de'];
if (in_array($_osi_origin, $_osi_allowed)) { header('Access-Control-Allow-Origin: ' . $_osi_origin); }
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
$email   = trim($body['email'] ?? '');
$name    = trim($body['name'] ?? '');
$phone   = trim($body['phone'] ?? '');
$address = trim($body['address'] ?? '');
// Hard identifiers for verification
$dob       = trim($body['dob'] ?? '');        // Geburtsdatum (YYYY-MM-DD or DD.MM.YYYY)
$idNumber  = trim($body['id_number'] ?? '');   // Personalausweis-Nr
$passNumber = trim($body['passport'] ?? '');   // Reisepass-Nr
$serialNr  = trim($body['serial'] ?? '');      // Serien-Nr (Gewerbe/Handelsregister)
$taxId     = trim($body['tax_id'] ?? '');      // Steuernummer / USt-IdNr
$plate     = trim($body['plate'] ?? '');       // Kennzeichen (B-AB 1234)

// Normalize DOB to YYYY-MM-DD
if ($dob && preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $dob, $dm)) {
    $dob = $dm[3] . '-' . str_pad($dm[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($dm[1], 2, '0', STR_PAD_LEFT);
}

// Verification anchors — used to confirm identity across sources
$hardIds = array_filter(compact('email', 'phone', 'dob', 'idNumber', 'passNumber', 'serialNr', 'taxId', 'plate'));

$results = [];
$domain = '';
$scanStart = microtime(true);
$verifiedFindings = []; // Track confidence per module

// ============================================================
// CACHE CHECK — Return cached results if < 24h old
// ============================================================
$cacheKey = md5(json_encode([$email, $name, $phone, $address, $dob, $idNumber, $passNumber]));
try {
    $cached = one("SELECT scan_id, deep_scan_data, created_at FROM osint_scans
        WHERE MD5(CONCAT(COALESCE(scan_email,''), COALESCE(scan_name,''), COALESCE(scan_phone,''), COALESCE(scan_address,''))) = ?
        AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) AND deep_scan_data IS NOT NULL
        ORDER BY created_at DESC LIMIT 1", [$cacheKey]);
    if ($cached && $cached['deep_scan_data']) {
        $cachedData = json_decode($cached['deep_scan_data'], true);
        if ($cachedData) {
            $cachedData['_cache'] = [
                'hit' => true,
                'scan_id' => $cached['scan_id'],
                'age_minutes' => round((time() - strtotime($cached['created_at'])) / 60),
                'cached_at' => $cached['created_at'],
            ];
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'data' => $cachedData]);
            exit;
        }
    }
} catch (Exception $e) { /* cache miss is ok */ }

// ============================================================
// 0. DB CROSS-REFERENCE FIRST (before network calls drop connection)
// ============================================================
$dbProfile = [];
if ($name || $email || $phone) {
    $like = '%' . ($name ?: $email) . '%';
    $phoneLike = $phone ? '%' . substr(preg_replace('/[^0-9]/', '', $phone), -8) . '%' : '%impossible%';
    try {
        $customers = all("SELECT c.*, COUNT(j.j_id) as total_jobs,
            SUM(CASE WHEN j.job_status='COMPLETED' THEN 1 ELSE 0 END) as completed_jobs,
            SUM(CASE WHEN j.job_status='CANCELLED' THEN 1 ELSE 0 END) as cancelled_jobs,
            MAX(j.j_date) as last_job_date, MIN(j.j_date) as first_job_date
            FROM customer c LEFT JOIN jobs j ON j.customer_id_fk=c.customer_id AND j.status=1
            WHERE c.email=? OR c.name LIKE ? OR c.phone LIKE ?
            GROUP BY c.customer_id LIMIT 5", [$email ?: '', $like, $phoneLike]);
        if (!empty($customers)) {
            $cid = $customers[0]['customer_id'];
            $services = all("SELECT s_id, title, price, total_price, street, city FROM services WHERE customer_id_fk=? AND status=1", [$cid]);
            $invoices = all("SELECT invoice_number, issue_date, total_price, remaining_price, invoice_paid FROM invoices WHERE customer_id_fk=? ORDER BY issue_date DESC LIMIT 10", [$cid]);
            $addresses = all("SELECT street, number, postal_code, city, country FROM customer_address WHERE customer_id_fk=?", [$cid]);
            $recentJobs = all("SELECT j.j_id, j.j_date, j.j_time, j.job_status, j.j_hours, s.title as service, e.name as partner FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id WHERE j.customer_id_fk=? AND j.status=1 ORDER BY j.j_date DESC LIMIT 10", [$cid]);
            $totalRevenue = array_sum(array_column($invoices, 'total_price'));
            $totalOpen = array_sum(array_filter(array_column($invoices, 'remaining_price')));
            $cancelRate = $customers[0]['total_jobs'] > 0 ? round($customers[0]['cancelled_jobs'] / $customers[0]['total_jobs'] * 100, 1) : 0;
            $dbProfile = [
                'found' => true,
                'customer' => ['id'=>$cid,'name'=>$customers[0]['name'],'email'=>$customers[0]['email'],'phone'=>$customers[0]['phone'],'type'=>$customers[0]['customer_type']??'','status'=>$customers[0]['status']?'Aktiv':'Inaktiv','created'=>$customers[0]['created_at']??''],
                'stats' => ['total_jobs'=>(int)$customers[0]['total_jobs'],'completed'=>(int)$customers[0]['completed_jobs'],'cancelled'=>(int)$customers[0]['cancelled_jobs'],'cancel_rate'=>$cancelRate,'first_job'=>$customers[0]['first_job_date'],'last_job'=>$customers[0]['last_job_date'],'total_revenue'=>$totalRevenue,'open_balance'=>$totalOpen],
                'services' => $services, 'invoices' => $invoices, 'addresses' => $addresses, 'recent_jobs' => $recentJobs,
                'risk_flags' => [],
            ];
            if ($cancelRate > 20) $dbProfile['risk_flags'][] = 'Hohe Stornoquote: '.$cancelRate.'%';
            if ($totalOpen > 100) $dbProfile['risk_flags'][] = 'Offene Rechnungen: '.number_format($totalOpen,2).' €';
            if (!$customers[0]['email']) $dbProfile['risk_flags'][] = 'Keine E-Mail hinterlegt';
            if (!$customers[0]['phone']) $dbProfile['risk_flags'][] = 'Kein Telefon hinterlegt';
        }
    } catch (Exception $e) {}
    $results['db_profile'] = $dbProfile;
}

// ============================================================
// HELPER: Safe curl fetch with validation
// ============================================================
function safeFetch(string $url, int $timeout = 10, array $opts = []): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FleckfreiOSINT/2.0)',
    ] + $opts);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 400 || $response === false) return null;
    // Reject HTML error pages
    if (stripos($response, '<!doctype') !== false || stripos($response, '<html') !== false) return null;
    if (stripos($response, 'API count exceeded') !== false) return null;
    return $response;
}

// ============================================================
// 1. EMAIL INTELLIGENCE
// ============================================================
if ($email && strpos($email, '@') !== false) {
    $domain = strtolower(substr($email, strpos($email, '@') + 1));

    // Email Security: SPF + DMARC
    $spfRecords = [];
    $spf = @dns_get_record($domain, DNS_TXT);
    if ($spf) foreach ($spf as $r) {
        if (isset($r['txt']) && stripos($r['txt'], 'spf') !== false) $spfRecords[] = $r['txt'];
    }
    $dmarc = @dns_get_record('_dmarc.' . $domain, DNS_TXT);
    $dmarcTxt = $dmarc ? ($dmarc[0]['txt'] ?? '') : '';
    $dkim = @dns_get_record('default._domainkey.' . $domain, DNS_TXT);
    $results['email_security'] = [
        'spf' => $spfRecords, 'dmarc' => $dmarcTxt,
        'has_spf' => !empty($spfRecords), 'has_dmarc' => !empty($dmarcTxt),
        'has_dkim' => !empty($dkim),
    ];

    // SPF Chain Resolution — reveal mail infrastructure
    if (!empty($spfRecords)) {
        $spfChain = [];
        $spfSeen = [];
        $spfResolve = function($dom, $depth = 0) use (&$spfResolve, &$spfSeen, &$spfChain) {
            if ($depth > 5 || isset($spfSeen[$dom])) return;
            $spfSeen[$dom] = true;
            $txt = @dns_get_record($dom, DNS_TXT);
            if (!$txt) return;
            foreach ($txt as $r) {
                if (!isset($r['txt']) || stripos($r['txt'], 'v=spf1') === false) continue;
                if (preg_match_all('/include:([^\s]+)/', $r['txt'], $m)) {
                    foreach ($m[1] as $inc) {
                        $service = 'Unknown';
                        if (str_contains($inc, 'google')) $service = 'Google Workspace';
                        elseif (str_contains($inc, 'outlook') || str_contains($inc, 'microsoft')) $service = 'Microsoft 365';
                        elseif (str_contains($inc, 'spf.protection')) $service = 'Microsoft 365';
                        elseif (str_contains($inc, 'amazonses')) $service = 'Amazon SES';
                        elseif (str_contains($inc, 'sendgrid')) $service = 'SendGrid';
                        elseif (str_contains($inc, 'mailgun')) $service = 'Mailgun';
                        elseif (str_contains($inc, 'mailchimp') || str_contains($inc, 'mandrillapp')) $service = 'Mailchimp';
                        elseif (str_contains($inc, 'zendesk')) $service = 'Zendesk';
                        elseif (str_contains($inc, 'freshdesk')) $service = 'Freshdesk';
                        elseif (str_contains($inc, 'hubspot')) $service = 'HubSpot';
                        elseif (str_contains($inc, 'zoho')) $service = 'Zoho';
                        $spfChain[] = ['domain' => $inc, 'service' => $service, 'depth' => $depth];
                        $spfResolve($inc, $depth + 1);
                    }
                }
                if (preg_match_all('/(ip[46]):([^\s]+)/', $r['txt'], $m)) {
                    foreach ($m[2] as $i => $ipRange) {
                        $spfChain[] = ['ip' => $ipRange, 'type' => $m[1][$i], 'depth' => $depth];
                    }
                }
            }
        };
        $spfResolve($domain);
        if (!empty($spfChain)) {
            $results['email_security']['spf_chain'] = $spfChain;
            $services = array_unique(array_filter(array_column($spfChain, 'service')));
            $results['email_security']['mail_services'] = array_values($services);
        }
    }

    // ---- Parallel curl requests for domain intel ----
    $mh = curl_multi_init();
    $handles = [];

    // crt.sh subdomains
    $handles['crt'] = curl_init("https://crt.sh/?q=%25.{$domain}&output=json");
    curl_setopt_array($handles['crt'], [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>0]);
    curl_multi_add_handle($mh, $handles['crt']);

    // WHOIS
    $handles['whois'] = curl_init("https://api.hackertarget.com/whois/?q={$domain}");
    curl_setopt_array($handles['whois'], [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>10]);
    curl_multi_add_handle($mh, $handles['whois']);

    // Reverse IP
    $aRecords = @dns_get_record($domain, DNS_A);
    $ip = !empty($aRecords[0]['ip']) ? $aRecords[0]['ip'] : null;
    if ($ip) {
        $handles['revip'] = curl_init("https://api.hackertarget.com/reverseiplookup/?q={$ip}");
        curl_setopt_array($handles['revip'], [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>10]);
        curl_multi_add_handle($mh, $handles['revip']);
    }

    // Wayback Machine
    $handles['wayback'] = curl_init("https://archive.org/wayback/available?url={$domain}");
    curl_setopt_array($handles['wayback'], [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>10]);
    curl_multi_add_handle($mh, $handles['wayback']);

    // Execute all in parallel
    $running = 0;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 1); } while ($running > 0);

    // Process crt.sh
    $crtRaw = curl_multi_getcontent($handles['crt']);
    $crtCode = curl_getinfo($handles['crt'], CURLINFO_HTTP_CODE);
    if ($crtCode >= 200 && $crtCode < 400 && $crtRaw) {
        $certs = json_decode($crtRaw, true);
        if (is_array($certs) && !empty($certs)) {
            $subdomains = array_unique(array_column(array_slice($certs, 0, 100), 'common_name'));
            $subdomains = array_filter($subdomains, function($s) use ($domain) {
                // Filter out: wildcards, the domain itself, and spam/garbage entries
                if ($s === $domain || str_starts_with($s, '*.')) return false;
                if (strlen($s) > 80) return false; // Too long = spam
                if (preg_match('/[A-Z]{3,}/', $s)) return false; // ALL CAPS names = spam certs
                if (str_contains($s, ' ')) return false; // Spaces = not a real subdomain
                if (!str_ends_with($s, '.'.$domain) && !str_contains($s, $domain)) return false; // Must belong to domain
                return true;
            });
            if (!empty($subdomains)) {
                $results['subdomains'] = ['source' => 'crt.sh', 'count' => count($subdomains), 'data' => array_values(array_slice($subdomains, 0, 20))];
            }
        }
    }

    // Process WHOIS
    $whoisRaw = curl_multi_getcontent($handles['whois']);
    $whoisCode = curl_getinfo($handles['whois'], CURLINFO_HTTP_CODE);
    if ($whoisCode >= 200 && $whoisCode < 400 && $whoisRaw && strlen($whoisRaw) > 50 && stripos($whoisRaw, '<html') === false && stripos($whoisRaw, 'API count') === false) {
        $w = [];
        if (preg_match('/Registrar:\s*(.+)/i', $whoisRaw, $m)) $w['registrar'] = trim($m[1]);
        if (preg_match('/Creation Date:\s*(.+)/i', $whoisRaw, $m)) $w['created'] = trim($m[1]);
        if (preg_match('/Updated Date:\s*(.+)/i', $whoisRaw, $m)) $w['updated'] = trim($m[1]);
        if (preg_match('/Registry Expiry Date:\s*(.+)/i', $whoisRaw, $m)) $w['expires'] = trim($m[1]);
        if (preg_match('/Registrant Organization:\s*(.+)/i', $whoisRaw, $m)) $w['org'] = trim($m[1]);
        if (preg_match('/Registrant Country:\s*(.+)/i', $whoisRaw, $m)) $w['country'] = trim($m[1]);
        if (preg_match('/Registrant Name:\s*(.+)/i', $whoisRaw, $m)) $w['registrant'] = trim($m[1]);
        if (preg_match('/Registrant State.*?:\s*(.+)/i', $whoisRaw, $m)) $w['state'] = trim($m[1]);
        if (!empty($w)) $results['whois'] = $w;
    }

    // Process Reverse IP
    if (isset($handles['revip'])) {
        $revRaw = curl_multi_getcontent($handles['revip']);
        $revCode = curl_getinfo($handles['revip'], CURLINFO_HTTP_CODE);
        if ($revCode >= 200 && $revCode < 400 && $revRaw && stripos($revRaw, 'error') === false && stripos($revRaw, '<html') === false && stripos($revRaw, 'API count') === false) {
            $hosts = array_filter(explode("\n", trim($revRaw)));
            $results['reverse_ip'] = ['ip' => $ip, 'count' => count($hosts), 'hosts' => array_slice($hosts, 0, 15)];
        }
    }

    // Process Wayback
    $wbRaw = curl_multi_getcontent($handles['wayback']);
    $wbCode = curl_getinfo($handles['wayback'], CURLINFO_HTTP_CODE);
    if ($wbCode >= 200 && $wbCode < 400 && $wbRaw) {
        $wb = json_decode($wbRaw, true);
        if (!empty($wb['archived_snapshots']['closest'])) {
            $results['wayback'] = $wb['archived_snapshots']['closest'];
        }
    }

    // Cleanup
    foreach ($handles as $h) { curl_multi_remove_handle($mh, $h); curl_close($h); }
    curl_multi_close($mh);

    // Domain recon (HTTP, SSL, tech)
    $domainRecon = [];
    $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false]]);
    $s = @stream_socket_client("ssl://{$domain}:443", $e, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
    if ($s) {
        $c = openssl_x509_parse(stream_context_get_params($s)['options']['ssl']['peer_certificate']);
        $domainRecon['ssl'] = ['issuer' => $c['issuer']['O'] ?? '?', 'expires' => date('Y-m-d', $c['validTo_time_t']), 'days' => floor(($c['validTo_time_t'] - time()) / 86400)];
        fclose($s);
    }
    $ch = curl_init("https://{$domain}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => 1, CURLOPT_SSL_VERIFYPEER => 0]);
    $html = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $domainRecon['http'] = $httpCode;
    $domainRecon['ip'] = $ip;
    if ($html) {
        preg_match('/<title[^>]*>([^<]+)</', $html, $m);
        $domainRecon['title'] = $m[1] ?? '';
        $tech = [];
        $techPatterns = ['WordPress'=>'wp-content','Shopify'=>'shopify','Wix'=>'wix.com','Bootstrap'=>'bootstrap','Tailwind'=>'tailwind','React'=>'react','Next.js'=>'_next','Vue'=>'vue','Angular'=>'angular','jQuery'=>'jquery','Elementor'=>'elementor','Analytics'=>'gtag','Tag Manager'=>'googletagmanager','Cloudflare'=>'__cf_bm','LiteSpeed'=>'litespeed'];
        foreach ($techPatterns as $label => $sig) {
            if (stripos($html, $sig) !== false) $tech[] = $label;
        }
        $domainRecon['tech'] = $tech;
    }
    if (!empty($domainRecon)) $results['domain_recon'] = $domainRecon;
}

// ============================================================
// 2. SOCIAL PROFILES (search URLs — always work)
// ============================================================
if ($name) {
    $slug = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
    $slugDot = strtolower(str_replace(' ', '.', trim($name)));
    $slugDash = strtolower(str_replace(' ', '-', trim($name)));
    $n = urlencode('"' . $name . '"');
    $e = $email ? urlencode('"' . $email . '"') : '';
    $combined = $n . ($e ? '+OR+' . $e : '');

    $results['profiles'] = [
        'Instagram' => ['type'=>'search_url', 'url'=>"https://www.google.com/search?q=site:instagram.com+{$combined}", 'color'=>'#e1306c'],
        'TikTok'    => ['type'=>'search_url', 'url'=>"https://www.google.com/search?q=site:tiktok.com+{$combined}", 'color'=>'#000000'],
        'Facebook'  => ['type'=>'search_url', 'url'=>"https://www.facebook.com/search/people?q=" . urlencode($name), 'color'=>'#1877f2'],
        'X/Twitter' => ['type'=>'search_url', 'url'=>"https://www.google.com/search?q=site:x.com+OR+site:twitter.com+{$combined}", 'color'=>'#000000'],
        'LinkedIn'  => ['type'=>'search_url', 'url'=>"https://www.google.com/search?q=site:linkedin.com/in+{$combined}", 'color'=>'#0a66c2'],
        'GitHub'    => ['type'=>'search_url', 'url'=>"https://github.com/search?q=" . urlencode($email ?: $name) . "&type=users", 'color'=>'#333333'],
        'XING'      => ['type'=>'search_url', 'url'=>"https://www.xing.com/search/members?keywords=" . urlencode($name), 'color'=>'#006567'],
        'Reddit'    => ['type'=>'search_url', 'url'=>"https://www.google.com/search?q=site:reddit.com+author:{$slug}+OR+{$combined}", 'color'=>'#ff4500'],
        'YouTube'   => ['type'=>'search_url', 'url'=>"https://www.youtube.com/results?search_query=" . urlencode($name), 'color'=>'#ff0000'],
        'Pinterest' => ['type'=>'search_url', 'url'=>"https://www.pinterest.com/search/users/?q=" . urlencode($name), 'color'=>'#e60023'],
        'Telegram'  => ['type'=>'search_url', 'url'=>"https://t.me/{$slug}", 'color'=>'#0088cc'],
        'Kleinanzeigen' => ['type'=>'search_url', 'url'=>"https://www.google.com/search?q=site:kleinanzeigen.de+{$combined}", 'color'=>'#86b817'],
    ];
}

// ============================================================
// 3. USERNAME SEARCH (check GitHub — reliable API)
// ============================================================
if ($name) {
    $parts = preg_split('/\s+/', trim($name));
    $first = strtolower($parts[0] ?? '');
    $last = strtolower(end($parts) ?: '');
    $variations = array_unique(array_filter([
        strtolower(preg_replace('/[^a-z0-9]/i', '', $name)),
        $first . '.' . $last,
        $first . '_' . $last,
        $first . $last,
        $first . '-' . $last,
        substr($first, 0, 1) . $last,
        $last . $first,
    ]));

    // Sherlock-style: check username across 20+ platforms via HTTP status
    $platforms = [
        'GitHub' => ['url' => 'https://api.github.com/users/{u}', 'check' => 'api', 'ua' => 'FleckfreiOSINT'],
        'Instagram' => ['url' => 'https://www.instagram.com/{u}/', 'check' => 'status'],
        'Twitter/X' => ['url' => 'https://x.com/{u}', 'check' => 'status'],
        'TikTok' => ['url' => 'https://www.tiktok.com/@{u}', 'check' => 'status'],
        'Reddit' => ['url' => 'https://www.reddit.com/user/{u}/about.json', 'check' => 'api'],
        'Pinterest' => ['url' => 'https://www.pinterest.com/{u}/', 'check' => 'status'],
        'LinkedIn' => ['url' => 'https://www.linkedin.com/in/{u}/', 'check' => 'status'],
        'Medium' => ['url' => 'https://medium.com/@{u}', 'check' => 'status'],
        'Telegram' => ['url' => 'https://t.me/{u}', 'check' => 'status'],
        'YouTube' => ['url' => 'https://www.youtube.com/@{u}', 'check' => 'status'],
        'Twitch' => ['url' => 'https://www.twitch.tv/{u}', 'check' => 'status'],
        'SoundCloud' => ['url' => 'https://soundcloud.com/{u}', 'check' => 'status'],
        'DeviantArt' => ['url' => 'https://www.deviantart.com/{u}', 'check' => 'status'],
        'Flickr' => ['url' => 'https://www.flickr.com/people/{u}/', 'check' => 'status'],
        'Vimeo' => ['url' => 'https://vimeo.com/{u}', 'check' => 'status'],
        'Spotify' => ['url' => 'https://open.spotify.com/user/{u}', 'check' => 'status'],
        'GitLab' => ['url' => 'https://gitlab.com/{u}', 'check' => 'status'],
        'Bitbucket' => ['url' => 'https://bitbucket.org/{u}/', 'check' => 'status'],
        'HackerNews' => ['url' => 'https://hacker-news.firebaseio.com/v0/user/{u}.json', 'check' => 'api'],
        'Keybase' => ['url' => 'https://keybase.io/{u}', 'check' => 'status'],
        'About.me' => ['url' => 'https://about.me/{u}', 'check' => 'status'],
    ];

    // Check top 3 variations in parallel (more coverage, same time due to curl_multi)
    $checkUsers = array_slice($variations, 0, 3);
    $found = [];

    foreach ($checkUsers as $v) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($platforms as $pname => $pconf) {
            $url = str_replace('{u}', $v, $pconf['url']);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_NOBODY => ($pconf['check'] === 'status'),
                CURLOPT_USERAGENT => $pconf['ua'] ?? 'Mozilla/5.0 (compatible; FleckfreiOSINT/2.0)',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$pname] = $ch;
        }

        $running = 0;
        do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.3); } while ($running > 0);

        foreach ($handles as $pname => $ch) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $pconf = $platforms[$pname];

            if ($pconf['check'] === 'api') {
                $content = curl_multi_getcontent($ch);
                if ($code === 200 && $content && $content !== 'null' && $content !== '{}') {
                    $data = json_decode($content, true);
                    $entry = ['platform' => $pname, 'username' => $v, 'url' => str_replace('{u}', $v, $pconf['url'])];
                    if ($pname === 'GitHub' && $data) {
                        $entry['url'] = $data['html_url'] ?? $entry['url'];
                        $entry['avatar'] = $data['avatar_url'] ?? null;
                        $entry['bio'] = $data['bio'] ?? null;
                        $entry['repos'] = $data['public_repos'] ?? 0;
                    }
                    $found[] = $entry;
                }
            } else {
                // Status-based: 200 = exists, 404 = not found
                if ($code >= 200 && $code < 400) {
                    $profileUrl = str_replace('{u}', $v, $pconf['url']);
                    $found[] = ['platform' => $pname, 'username' => $v, 'url' => $profileUrl];
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }

    // Deduplicate by platform
    $seen = [];
    $unique = [];
    foreach ($found as $f) {
        $key = $f['platform'] . ':' . $f['username'];
        if (!isset($seen[$key])) { $seen[$key] = true; $unique[] = $f; }
    }
    if (!empty($unique)) $results['username_search'] = $unique;
}

// ============================================================
// 4. BREACH CHECK (HIBP k-Anonymity — FREE, no API key)
// ============================================================
if ($email) {
    $sha1 = strtoupper(sha1(strtolower(trim($email))));
    $prefix = substr($sha1, 0, 5);
    $suffix = substr($sha1, 5);
    $response = safeFetch("https://api.pwnedpasswords.com/range/{$prefix}", 5);
    if ($response) {
        $lines = explode("\n", trim($response));
        $breached = false;
        $count = 0;
        foreach ($lines as $line) {
            $parts = explode(':', trim($line));
            if (strtoupper($parts[0]) === $suffix) {
                $breached = true;
                $count = (int)($parts[1] ?? 0);
                break;
            }
        }
        $results['breach_check'] = ['breached' => $breached, 'count' => $count, 'source' => 'HaveIBeenPwned k-Anonymity'];
    }
}

// ============================================================
// 5. PHONE OSINT
// ============================================================
if ($phone) {
    $clean = preg_replace('/[^+0-9]/', '', $phone);
    $isDE = str_starts_with($clean, '+49') || str_starts_with($clean, '0049') || (str_starts_with($clean, '0') && strlen($clean) >= 10 && !str_starts_with($clean, '00'));
    $national = $clean;
    if (str_starts_with($clean, '+49')) $national = '0' . substr($clean, 3);
    elseif (str_starts_with($clean, '0049')) $national = '0' . substr($clean, 4);

    // Detect carrier from prefix (German mobile)
    $carrier = null; $type = 'Unbekannt';
    if ($isDE && strlen($national) >= 4) {
        $prefix3 = substr($national, 0, 4);
        $mobileMap = ['0151'=>'Telekom','0152'=>'Vodafone','0157'=>'E-Plus/o2','0159'=>'o2','0160'=>'Telekom','0162'=>'Vodafone','0163'=>'E-Plus/o2','0170'=>'Telekom','0171'=>'Telekom','0172'=>'Vodafone','0173'=>'Vodafone','0174'=>'Vodafone','0175'=>'Telekom','0176'=>'o2','0177'=>'E-Plus/o2','0178'=>'E-Plus/o2','0179'=>'o2','0155'=>'Congstar','0156'=>'Mobilcom'];
        $carrier = $mobileMap[$prefix3] ?? null;
        if ($carrier) $type = 'Mobil';
        elseif (str_starts_with($national, '030')) { $type = 'Festnetz Berlin'; $carrier = 'Festnetz'; }
        elseif (str_starts_with($national, '040')) { $type = 'Festnetz Hamburg'; $carrier = 'Festnetz'; }
        elseif (str_starts_with($national, '089')) { $type = 'Festnetz München'; $carrier = 'Festnetz'; }
        elseif (str_starts_with($national, '069')) { $type = 'Festnetz Frankfurt'; $carrier = 'Festnetz'; }
        elseif (str_starts_with($national, '0800')) { $type = 'Kostenlose Hotline'; }
        elseif (str_starts_with($national, '0900')) { $type = 'Premium-Dienst (kostenpflichtig)'; }
    }

    $results['phone_osint'] = [
        'formatted' => $clean,
        'national' => $national,
        'country' => $isDE ? 'Deutschland (+49)' : 'International',
        'type' => $type,
        'carrier' => $carrier,
        'search_links' => [
            'Tellows' => 'https://www.tellows.de/num/' . urlencode($national),
            'Das Örtliche' => $isDE ? 'https://www.dasoertliche.de/Themen/Rückwärtssuche/' . urlencode($national) : null,
            'Google' => 'https://www.google.com/search?q="' . urlencode($clean) . '"',
            'Sync.me' => 'https://sync.me/search/?number=' . urlencode($clean),
            'Truecaller' => 'https://www.truecaller.com/search/de/' . urlencode(ltrim($national, '0')),
            'WhatsApp' => 'https://wa.me/' . ltrim($clean, '+'),
            'Telegram' => 'https://t.me/+' . ltrim($clean, '+'),
        ],
    ];
    // Remove null links
    $results['phone_osint']['search_links'] = array_filter($results['phone_osint']['search_links']);
}

// ============================================================
// 6. ADDRESS GEOCODING (Nominatim — free, 1 req/s)
// ============================================================
if ($address) {
    $geoUrl = 'https://nominatim.openstreetmap.org/search?q=' . urlencode($address) . '&format=json&limit=1&addressdetails=1';
    $geoRaw = safeFetch($geoUrl, 8, [CURLOPT_HTTPHEADER => ['User-Agent: FleckfreiOSINT/2.0 info@fleckfrei.de']]);
    if ($geoRaw) {
        $geo = json_decode($geoRaw, true);
        if (is_array($geo) && !empty($geo[0])) {
            $g = $geo[0];
            $results['geocoding'] = [
                'lat' => $g['lat'],
                'lon' => $g['lon'],
                'display_name' => $g['display_name'] ?? '',
                'type' => $g['type'] ?? '',
                'importance' => $g['importance'] ?? 0,
                'address' => $g['address'] ?? [],
            ];
        }
    }
}

// ============================================================
// 7. EMAIL EXPOSURE (fixed: validate response)
// ============================================================
if ($email && $domain) {
    $expRaw = safeFetch("https://api.hackertarget.com/emailsearch/?q=" . urlencode($domain), 10);
    if ($expRaw && strlen($expRaw) > 5) {
        $found = array_filter(explode("\n", trim($expRaw)), function($line) {
            return strpos($line, '@') !== false; // Only keep lines that look like emails
        });
        if (!empty($found)) {
            $results['email_exposure'] = ['count' => count($found), 'emails' => array_slice(array_values($found), 0, 15)];
        }
    }
}

// ============================================================
// 8. SHODAN — Port/Service Scan (API key required)
// ============================================================
if ($domain && defined('SHODAN_API_KEY') && SHODAN_API_KEY) {
    // First resolve domain to IP
    $aRec = @dns_get_record($domain, DNS_A);
    $targetIp = !empty($aRec[0]['ip']) ? $aRec[0]['ip'] : null;
    if ($targetIp) {
        $shodanRaw = safeFetch('https://api.shodan.io/shodan/host/'.$targetIp.'?key='.SHODAN_API_KEY, 10);
        if (!$shodanRaw) {
            // safeFetch rejects HTML, but Shodan returns JSON — fetch directly
            $ch = curl_init('https://api.shodan.io/shodan/host/'.$targetIp.'?key='.SHODAN_API_KEY);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>0]);
            $shodanRaw = curl_exec($ch); curl_close($ch);
        }
        if ($shodanRaw) {
            $shodan = json_decode($shodanRaw, true);
            if (!empty($shodan['ip_str'])) {
                $ports = array_column($shodan['data'] ?? [], 'port');
                $services = [];
                foreach ($shodan['data'] ?? [] as $s) {
                    $services[] = [
                        'port' => $s['port'],
                        'transport' => $s['transport'] ?? 'tcp',
                        'product' => $s['product'] ?? ($s['_shodan']['module'] ?? ''),
                        'version' => $s['version'] ?? '',
                    ];
                }
                $results['shodan'] = [
                    'ip' => $shodan['ip_str'],
                    'org' => $shodan['org'] ?? '',
                    'os' => $shodan['os'] ?? '',
                    'isp' => $shodan['isp'] ?? '',
                    'country' => $shodan['country_name'] ?? '',
                    'city' => $shodan['city'] ?? '',
                    'ports' => $ports,
                    'services' => array_slice($services, 0, 10),
                    'vulns' => array_keys($shodan['vulns'] ?? []),
                    'last_update' => $shodan['last_update'] ?? '',
                ];
            }
        }
    }
}

// ============================================================
// 8b. VIRUSTOTAL — Domain reputation (500 req/day)
// ============================================================
if ($domain && defined('VT_API_KEY') && VT_API_KEY) {
    $ch = curl_init('https://www.virustotal.com/api/v3/domains/'.$domain);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_HTTPHEADER=>['x-apikey: '.VT_API_KEY]]);
    $vtRaw = curl_exec($ch); $vtCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($vtCode === 200 && $vtRaw) {
        $vt = json_decode($vtRaw, true);
        $attrs = $vt['data']['attributes'] ?? [];
        $stats = $attrs['last_analysis_stats'] ?? [];
        $results['virustotal'] = [
            'domain' => $domain,
            'reputation' => $attrs['reputation'] ?? 0,
            'malicious' => $stats['malicious'] ?? 0,
            'suspicious' => $stats['suspicious'] ?? 0,
            'harmless' => $stats['harmless'] ?? 0,
            'undetected' => $stats['undetected'] ?? 0,
            'categories' => array_values($attrs['categories'] ?? []),
            'registrar' => $attrs['registrar'] ?? '',
            'creation_date' => isset($attrs['creation_date']) ? date('Y-m-d', $attrs['creation_date']) : '',
            'last_update' => isset($attrs['last_modification_date']) ? date('Y-m-d', $attrs['last_modification_date']) : '',
            'whois' => substr($attrs['whois'] ?? '', 0, 500),
        ];
    }
}

// ============================================================
// 8c. HUNTER.IO — Email verification + company data (25/month)
// ============================================================
if ($email && defined('HUNTER_API_KEY') && HUNTER_API_KEY) {
    $ch = curl_init('https://api.hunter.io/v2/email-verifier?email='.urlencode($email).'&api_key='.HUNTER_API_KEY);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>0]);
    $hunterRaw = curl_exec($ch); $hunterCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($hunterCode === 200 && $hunterRaw) {
        $hunter = json_decode($hunterRaw, true);
        $hd = $hunter['data'] ?? [];
        $results['hunter'] = [
            'email' => $hd['email'] ?? $email,
            'result' => $hd['result'] ?? 'unknown',
            'score' => $hd['score'] ?? 0,
            'status' => $hd['status'] ?? '',
            'disposable' => $hd['disposable'] ?? false,
            'webmail' => $hd['webmail'] ?? false,
            'mx_records' => $hd['mx_records'] ?? false,
            'smtp_server' => $hd['smtp_server'] ?? false,
            'smtp_check' => $hd['smtp_check'] ?? false,
            'accept_all' => $hd['accept_all'] ?? false,
            'first_name' => $hd['first_name'] ?? '',
            'last_name' => $hd['last_name'] ?? '',
            'sources' => count($hd['sources'] ?? []),
        ];
    }
}
// Also get domain search from Hunter (find other emails at same domain)
if ($domain && !in_array($domain, ['gmail.com','gmx.de','web.de','yahoo.com','hotmail.com','outlook.com','t-online.de']) && defined('HUNTER_API_KEY') && HUNTER_API_KEY) {
    $ch = curl_init('https://api.hunter.io/v2/domain-search?domain='.$domain.'&api_key='.HUNTER_API_KEY.'&limit=5');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>0]);
    $hdsRaw = curl_exec($ch); curl_close($ch);
    if ($hdsRaw) {
        $hds = json_decode($hdsRaw, true);
        $hdsData = $hds['data'] ?? [];
        if (!empty($hdsData['emails'])) {
            $results['hunter_domain'] = [
                'domain' => $domain,
                'organization' => $hdsData['organization'] ?? '',
                'emails_found' => count($hdsData['emails']),
                'emails' => array_map(fn($e) => ['email'=>$e['value']??'','name'=>trim(($e['first_name']??'').' '.($e['last_name']??'')),'position'=>$e['position']??'','confidence'=>$e['confidence']??0], array_slice($hdsData['emails'], 0, 5)),
            ];
        }
    }
}

// ============================================================
// 9. GLEIF LEI — Legal Entity Identifier (FREE, no key)
// ============================================================
if ($name) {
    $leiUrl = 'https://api.gleif.org/api/v1/lei-records?filter[entity.legalName]=' . urlencode($name) . '&page[size]=5';
    $leiRaw = safeFetch($leiUrl, 10);
    // GLEIF returns JSON even on success, override HTML check
    if (!$leiRaw) {
        $ch = curl_init($leiUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'FleckfreiOSINT/2.0']);
        $leiRaw = curl_exec($ch); curl_close($ch);
    }
    if ($leiRaw) {
        $leiData = json_decode($leiRaw, true);
        if (!empty($leiData['data'])) {
            $leiResults = [];
            foreach ($leiData['data'] as $rec) {
                $ent = $rec['attributes']['entity'] ?? [];
                $reg = $rec['attributes']['registration'] ?? [];
                $leiResults[] = [
                    'lei' => $rec['attributes']['lei'] ?? '',
                    'name' => $ent['legalName']['name'] ?? '',
                    'status' => $ent['status'] ?? '',
                    'jurisdiction' => $ent['jurisdiction'] ?? '',
                    'address' => implode(', ', $ent['legalAddress']['addressLines'] ?? []) . ', ' . ($ent['legalAddress']['city'] ?? '') . ' ' . ($ent['legalAddress']['country'] ?? ''),
                    'registered' => $reg['initialRegistrationDate'] ?? '',
                    'next_renewal' => $reg['nextRenewalDate'] ?? '',
                ];
            }
            $results['gleif_lei'] = ['count' => count($leiResults), 'records' => $leiResults];
        }
    }
}

// ============================================================
// 9. OpenCorporates — Company Search (FREE)
// ============================================================
if ($name) {
    $ocRaw = safeFetch('https://api.opencorporates.com/v0.4/companies/search?q=' . urlencode($name) . '&per_page=5', 10);
    if ($ocRaw) {
        $ocData = json_decode($ocRaw, true);
        if (!empty($ocData['results']['companies'])) {
            $ocResults = [];
            foreach ($ocData['results']['companies'] as $c) {
                $co = $c['company'] ?? [];
                $ocResults[] = [
                    'name' => $co['name'] ?? '',
                    'number' => $co['company_number'] ?? '',
                    'jurisdiction' => $co['jurisdiction_code'] ?? '',
                    'status' => $co['current_status'] ?? '',
                    'type' => $co['company_type'] ?? '',
                    'incorporated' => $co['incorporation_date'] ?? '',
                    'address' => $co['registered_address_in_full'] ?? '',
                    'url' => $co['opencorporates_url'] ?? '',
                ];
            }
            $results['opencorporates'] = ['count' => count($ocResults), 'companies' => $ocResults];
        }
    }
}

// DB profile already loaded in section 0 (before network calls)

// ============================================================
// 9c. DNS SERVICE DISCOVERY — SRV Records (FREE, no key)
// ============================================================
if ($domain) {
    $srvPrefixes = ['_sip._tcp','_sips._tcp','_xmpp-client._tcp','_xmpp-server._tcp','_caldav._tcp','_carddav._tcp','_ldap._tcp','_kerberos._tcp','_http._tcp','_https._tcp','_imaps._tcp','_submission._tcp','_autodiscover._tcp','_matrix._tcp'];
    $srvResults = [];
    $detectedServices = [];
    foreach ($srvPrefixes as $prefix) {
        $srvDomain = $prefix . '.' . $domain;
        $recs = @dns_get_record($srvDomain, DNS_SRV);
        if (!empty($recs)) {
            foreach ($recs as $r) {
                $target = $r['target'] ?? '';
                $service = 'Unknown';
                if (str_contains($target, 'google') || str_contains($target, 'gmail')) $service = 'Google Workspace';
                elseif (str_contains($target, 'outlook') || str_contains($target, 'microsoft') || str_contains($target, 'lync')) $service = 'Microsoft 365';
                elseif (str_contains($target, 'zoom')) $service = 'Zoom';
                elseif (str_contains($target, 'matrix')) $service = 'Matrix';
                elseif (str_contains($target, 'jabber') || str_contains($target, 'xmpp')) $service = 'XMPP/Jabber';
                elseif (str_contains($target, 'sipgate')) $service = 'Sipgate';
                $srvResults[] = ['record' => $srvDomain, 'target' => $target, 'port' => $r['port'] ?? 0, 'priority' => $r['pri'] ?? 0, 'weight' => $r['weight'] ?? 0, 'service' => $service];
                if ($service !== 'Unknown') $detectedServices[] = $service;
            }
        }
    }
    // MTA-STS + BIMI TXT records
    $extraTxt = [];
    foreach (['_mta-sts','_bimi','_smtp._tls'] as $sub) {
        $txt = @dns_get_record($sub . '.' . $domain, DNS_TXT);
        if (!empty($txt[0]['txt'])) $extraTxt[$sub] = $txt[0]['txt'];
    }
    if (!empty($srvResults) || !empty($extraTxt)) {
        $results['dns_services'] = [
            'srv_records' => $srvResults,
            'txt_policies' => $extraTxt,
            'detected_services' => array_values(array_unique($detectedServices)),
            'total_records' => count($srvResults),
        ];
    }
}

// ============================================================
// 9d. BGP/ASN LOOKUP — Network Intelligence (FREE)
// ============================================================
if ($domain) {
    $bgpIp = $ip ?? null;
    if (!$bgpIp) { $aRec = @dns_get_record($domain, DNS_A); $bgpIp = $aRec[0]['ip'] ?? null; }
    if ($bgpIp) {
        $rev = implode('.', array_reverse(explode('.', $bgpIp)));
        $cymruOrigin = @dns_get_record($rev . '.origin.asn.cymru.com', DNS_TXT);
        $asn = null; $prefix = ''; $country = '';
        if (!empty($cymruOrigin[0]['txt'])) {
            $parts = array_map('trim', explode('|', $cymruOrigin[0]['txt']));
            $asn = (int)($parts[0] ?? 0);
            $prefix = $parts[1] ?? '';
            $country = $parts[2] ?? '';
        }
        $asnName = '';
        if ($asn) {
            $cymruAsn = @dns_get_record('AS' . $asn . '.asn.cymru.com', DNS_TXT);
            if (!empty($cymruAsn[0]['txt'])) {
                $parts = array_map('trim', explode('|', $cymruAsn[0]['txt']));
                $asnName = $parts[4] ?? '';
            }
        }
        // RDAP fallback for extra info
        $rdapData = [];
        if ($bgpIp) {
            $rdapRaw = safeFetch('https://rdap.arin.net/registry/ip/' . $bgpIp, 8);
            if ($rdapRaw) {
                $rdap = json_decode($rdapRaw, true);
                if ($rdap) {
                    $rdapData = ['name' => $rdap['name'] ?? '', 'handle' => $rdap['handle'] ?? '', 'type' => $rdap['type'] ?? '', 'start' => $rdap['startAddress'] ?? '', 'end' => $rdap['endAddress'] ?? ''];
                }
            }
        }
        if ($asn || !empty($rdapData)) {
            $results['bgp_asn'] = ['ip' => $bgpIp, 'asn' => $asn, 'asn_name' => $asnName, 'prefix' => $prefix, 'country' => strtoupper($country), 'rdap' => $rdapData];
        }
    }
}

// ============================================================
// 9d2. LICENSE PLATE SEARCH — Kennzeichen (FREE)
// ============================================================
if ($plate) {
    $plateClean = strtoupper(preg_replace('/\s+/', ' ', trim($plate)));
    $plateNoSpace = str_replace([' ', '-'], '', $plateClean);
    $mh = curl_multi_init();
    $handles = [];
    $plateQueries = [
        'exact' => '"' . $plateClean . '"',
        'compact' => '"' . $plateNoSpace . '"',
        'auto' => '"' . $plateClean . '" Auto OR Fahrzeug OR KFZ OR PKW',
        'anzeigen' => '"' . $plateClean . '" site:kleinanzeigen.de OR site:mobile.de OR site:autoscout24.de',
        'halter' => '"' . $plateClean . '" Halter OR Eigentümer OR Zulassung',
        'unfall' => '"' . $plateClean . '" Unfall OR Polizei OR Blitzer OR Strafzettel',
    ];
    foreach ($plateQueries as $qType => $query) {
        $ch = curl_init('https://html.duckduckgo.com/html/?q=' . urlencode($query));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>4, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36']);
        curl_multi_add_handle($mh, $ch);
        $handles[$qType] = $ch;
    }
    $running = 0;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.3); } while ($running > 0);
    $plateResults = [];
    foreach ($handles as $qType => $ch) {
        $html = curl_multi_getcontent($ch);
        if ($html && preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?class="result__snippet"[^>]*>(.*?)<\/span>/s', $html, $dm, PREG_SET_ORDER)) {
            foreach (array_slice($dm, 0, 5) as $m) {
                $rUrl = $m[1]; if (preg_match('/uddg=([^&]+)/', $rUrl, $u)) $rUrl = urldecode($u[1]);
                $plateResults[$qType][] = ['title' => trim(strip_tags($m[2])), 'url' => $rUrl, 'snippet' => trim(strip_tags($m[3]))];
            }
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    // Detect plate region
    $region = '';
    $regionMap = ['B'=>'Berlin','M'=>'München','HH'=>'Hamburg','K'=>'Köln','F'=>'Frankfurt','S'=>'Stuttgart','D'=>'Düsseldorf','DD'=>'Dresden','L'=>'Leipzig','N'=>'Nürnberg','DO'=>'Dortmund','E'=>'Essen','HB'=>'Bremen','H'=>'Hannover','DU'=>'Duisburg','BO'=>'Bochum','W'=>'Wuppertal','BI'=>'Bielefeld','MA'=>'Mannheim','KA'=>'Karlsruhe'];
    $platePrefix = strtoupper(explode('-', str_replace(' ', '-', $plateClean))[0] ?? '');
    $region = $regionMap[$platePrefix] ?? $platePrefix;

    // Direct car data APIs (free/public)
    $vehicleData = [];
    // 1. AutoDNA — free VIN/plate history check
    $adnaRaw = safeFetch('https://www.autodna.de/api/v1/search?plate=' . urlencode($plateNoSpace) . '&country=DE', 8);
    if ($adnaRaw) { $adna = json_decode($adnaRaw, true); if (!empty($adna)) $vehicleData['autodna'] = $adna; }
    // 2. NHTSA-style: try vin.guru for German plates
    $vgRaw = safeFetch('https://www.vin.guru/api/plate/' . urlencode($plateNoSpace) . '?country=de', 5);
    if ($vgRaw) { $vg = json_decode($vgRaw, true); if (!empty($vg['make'] ?? $vg['model'] ?? null)) $vehicleData['vin_guru'] = $vg; }
    // 3. Scrape mobile.de search for this plate's region to find car listings
    $mobileDe = safeFetch('https://suchen.mobile.de/fahrzeuge/search.html?isSearchRequest=true&q=' . urlencode($plateClean), 8);
    if (!$mobileDe) {
        $ch = curl_init('https://suchen.mobile.de/fahrzeuge/search.html?isSearchRequest=true&q=' . urlencode($plateClean));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>4, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36']);
        $mobileDe = curl_exec($ch); curl_close($ch);
    }
    if ($mobileDe && preg_match('/(\d+)\s*Ergebnis/', $mobileDe, $mrm)) {
        $vehicleData['mobile_de_results'] = (int)$mrm[1];
    }

    // 4. GDV Zentralruf — Which insurance company covers this plate (FREE, LEGAL)
    $insuranceInfo = [];
    $gdvUrl = 'https://www.gdv-dl.de/zentralruf/kfz-versicherer-ermitteln';
    // Try to scrape the GDV form result
    $ch = curl_init('https://www.gdv-dl.de/zentralruf/api/vehicle?licensePlate=' . urlencode($plateNoSpace) . '&country=DE');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>4, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0', CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $gdvRaw = curl_exec($ch); $gdvCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($gdvRaw && $gdvCode === 200) {
        $gdvData = json_decode($gdvRaw, true);
        if ($gdvData) $insuranceInfo['gdv_api'] = $gdvData;
    }
    // Also search for insurance info via web
    $insSearch = safeFetch('https://html.duckduckgo.com/html/?q=' . urlencode('"' . $plateClean . '" Versicherung OR versichert OR Haftpflicht OR Kfz-Versicherung'), 8);
    if (!$insSearch) {
        $ch = curl_init('https://html.duckduckgo.com/html/?q=' . urlencode('"' . $plateClean . '" Versicherung OR Haftpflicht'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>4, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36']);
        $insSearch = curl_exec($ch); curl_close($ch);
    }
    if ($insSearch && preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?class="result__snippet"[^>]*>(.*?)<\/span>/s', $insSearch, $dm, PREG_SET_ORDER)) {
        foreach (array_slice($dm, 0, 3) as $m) {
            $rUrl = $m[1]; if (preg_match('/uddg=([^&]+)/', $rUrl, $u)) $rUrl = urldecode($u[1]);
            $insuranceInfo['web_results'][] = ['title' => trim(strip_tags($m[2])), 'url' => $rUrl, 'snippet' => trim(strip_tags($m[3]))];
        }
    }

    // 5. Pan-European plate search — every country, every free source
    // Detect country from plate format
    $plateCountry = 'DE';
    $plateFormats = [
        'RO' => '/^(B|CJ|TM|IS|CT|SB|BV|MM|HD|BC|MS|AG|DJ|GL|HR|IL|OT|PH|SV|VL)\s?\d{2,3}\s?[A-Z]{3}$/i',
        'MD' => '/^(C|KH|AN|BA|BD|BR|CH|CL|CM|CS|CT|DR|DN|ED|FL|GL|HI|IA|NH|OR|RS|SI|SN|SO|ST|TA|UG|UN)\s?\d{3}\s?[A-Z]{3}$/i',
        'CH' => '/^(ZH|BE|LU|UR|SZ|OW|NW|GL|ZG|FR|SO|BS|BL|SH|AR|AI|SG|GR|AG|TG|TI|VD|VS|NE|GE|JU)\s?\d+$/i',
        'AT' => '/^[A-Z]{1,2}\s?\d{1,5}\s?[A-Z]{1,3}$/i',
        'PL' => '/^[A-Z]{2,3}\s?\d{3,5}[A-Z]{0,2}$/i',
        'CZ' => '/^\d[A-Z]\d\s?\d{4}$/i',
        'HU' => '/^[A-Z]{3}\s?\d{3}$/i',
        'NL' => '/^\d{1,2}-[A-Z]{2,3}-\d{1,2}$/i',
        'FR' => '/^[A-Z]{2}-\d{3}-[A-Z]{2}$/i',
        'IT' => '/^[A-Z]{2}\s?\d{3}\s?[A-Z]{2}$/i',
        'ES' => '/^\d{4}\s?[A-Z]{3}$/i',
        'UK' => '/^[A-Z]{2}\d{2}\s?[A-Z]{3}$/i',
    ];
    foreach ($plateFormats as $cc => $regex) {
        if (preg_match($regex, $plateNoSpace) || preg_match($regex, $plateClean)) { $plateCountry = $cc; break; }
    }

    // EU-wide free searches
    $euSearches = [];
    $mhEU = curl_multi_init();
    $hEU = [];
    $euQueries = [
        'eu_exact' => '"' . $plateClean . '"',
        'eu_car' => '"' . $plateClean . '" car OR auto OR vehicle OR masina OR voiture',
        'eu_images' => '"' . $plateClean . '" site:flickr.com OR site:imgur.com OR site:photobucket.com',
        'eu_forums' => '"' . $plateClean . '" forum OR community OR club',
        'eu_repair' => '"' . $plateClean . '" Werkstatt OR repair OR reparatur OR service OR TÜV OR MOT',
        'eu_accident' => '"' . $plateClean . '" accident OR Unfall OR crash OR collision',
        'eu_sale' => '"' . $plateClean . '" sale OR verkauf OR vanzare OR prodej',
        'eu_social' => '"' . $plateClean . '" site:facebook.com OR site:instagram.com OR site:youtube.com',
    ];
    // Country-specific searches
    if ($plateCountry === 'RO') {
        $euQueries['ro_olx'] = '"' . $plateClean . '" site:olx.ro OR site:autovit.ro OR site:mobile.de';
        $euQueries['ro_registrul'] = '"' . $plateClean . '" ONRC OR "Registrul Comertului" OR CUI';
    } elseif ($plateCountry === 'MD') {
        $euQueries['md_search'] = '"' . $plateClean . '" site:999.md OR site:makler.md OR site:point.md';
    } elseif ($plateCountry === 'CH') {
        $euQueries['ch_search'] = '"' . $plateClean . '" site:autoscout24.ch OR site:car4you.ch OR site:tutti.ch';
        $euQueries['ch_strassenverkehr'] = '"' . $plateClean . '" Strassenverkehrsamt OR Fahrzeugausweis';
    } elseif ($plateCountry === 'AT') {
        $euQueries['at_search'] = '"' . $plateClean . '" site:willhaben.at OR site:autoscout24.at';
    } elseif ($plateCountry === 'PL') {
        $euQueries['pl_search'] = '"' . $plateClean . '" site:otomoto.pl OR site:olx.pl';
        $euQueries['pl_history'] = '"' . $plateClean . '" historiapojazdu OR CEPiK';
    }
    foreach ($euQueries as $qType => $query) {
        $ch = curl_init('https://html.duckduckgo.com/html/?q=' . urlencode($query));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>3, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36']);
        curl_multi_add_handle($mhEU, $ch);
        $hEU[$qType] = $ch;
    }
    $running = 0;
    do { curl_multi_exec($mhEU, $running); curl_multi_select($mhEU, 0.3); } while ($running > 0);
    foreach ($hEU as $qType => $ch) {
        $html = curl_multi_getcontent($ch);
        if ($html && preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?class="result__snippet"[^>]*>(.*?)<\/span>/s', $html, $dm, PREG_SET_ORDER)) {
            foreach (array_slice($dm, 0, 5) as $m) {
                $rUrl = $m[1]; if (preg_match('/uddg=([^&]+)/', $rUrl, $u)) $rUrl = urldecode($u[1]);
                $euSearches[$qType][] = ['title' => trim(strip_tags($m[2])), 'url' => $rUrl, 'snippet' => trim(strip_tags($m[3]))];
            }
        }
        curl_multi_remove_handle($mhEU, $ch);
        curl_close($ch);
    }
    curl_multi_close($mhEU);

    // Free EU vehicle APIs
    // EUCARIS-light: check if plate image appears anywhere (Google Images reverse)
    $plateImageSearch = [];
    $imgCh = curl_init('https://html.duckduckgo.com/html/?q=' . urlencode('"' . $plateNoSpace . '" OR "' . $plateClean . '" filetype:jpg OR filetype:png'));
    curl_setopt_array($imgCh, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>3, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0']);
    $imgHtml = curl_exec($imgCh); curl_close($imgCh);
    if ($imgHtml && preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/s', $imgHtml, $dm, PREG_SET_ORDER)) {
        foreach (array_slice($dm, 0, 5) as $m) {
            $rUrl = $m[1]; if (preg_match('/uddg=([^&]+)/', $rUrl, $u)) $rUrl = urldecode($u[1]);
            $plateImageSearch[] = ['title' => trim(strip_tags($m[2])), 'url' => $rUrl];
        }
    }

    $halterServices = [
        ['name' => 'GDV Zentralruf (DE)', 'url' => 'https://www.gdv-dl.de/zentralruf/', 'info' => 'Versicherung ermitteln', 'type' => 'free'],
        ['name' => 'Unfallhelden (DE)', 'url' => 'https://www.unfallhelden.de/', 'info' => 'Unfallgegner bei Schaden', 'type' => 'free'],
        ['name' => 'AutoDNA (EU)', 'url' => 'https://www.autodna.de/kennzeichen/' . urlencode($plateNoSpace), 'info' => 'Fahrzeughistorie EU-weit', 'type' => 'free'],
        ['name' => 'Carfax EU', 'url' => 'https://www.carfax.eu/de/fahrzeughistorie?registration=' . urlencode($plateNoSpace), 'info' => 'Internationale Historie', 'type' => 'free'],
        ['name' => 'CarVertical (EU)', 'url' => 'https://www.carvertical.com/de/prufung/' . urlencode($plateNoSpace), 'info' => 'VIN + Historie', 'type' => 'free'],
    ];
    if ($plateCountry === 'RO') {
        $halterServices[] = ['name' => 'RAR.ro (RO)', 'url' => 'https://pro.rarom.ro/istoric_vehicul/', 'info' => 'Fahrzeughistorie Rumänien', 'type' => 'free'];
        $halterServices[] = ['name' => 'ONRC (RO)', 'url' => 'https://www.onrc.ro/index.php/en/', 'info' => 'Handelsregister Rumänien', 'type' => 'free'];
    }
    if ($plateCountry === 'CH') {
        $halterServices[] = ['name' => 'ASTRA (CH)', 'url' => 'https://www.astra.admin.ch/', 'info' => 'Strassenverkehrsamt Schweiz', 'type' => 'free'];
        $halterServices[] = ['name' => 'AutoScout CH', 'url' => 'https://www.autoscout24.ch/de/auto?vehtyp=10&q=' . urlencode($plateClean), 'info' => 'Fahrzeugsuche Schweiz', 'type' => 'free'];
    }
    if ($plateCountry === 'PL') {
        $halterServices[] = ['name' => 'HistoriaPojazdu (PL)', 'url' => 'https://historiapojazdu.gov.pl/', 'info' => 'Offizielle Fahrzeughistorie PL', 'type' => 'free'];
    }
    if ($plateCountry === 'AT') {
        $halterServices[] = ['name' => 'Eurotax (AT)', 'url' => 'https://www.eurotaxglass.at/', 'info' => 'Fahrzeugbewertung AT', 'type' => 'free'];
    }

    $results['plate_search'] = [
        'plate' => $plateClean,
        'region' => $region,
        'country' => $plateCountry,
        'results' => $plateResults,
        'eu_searches' => $euSearches,
        'plate_images' => $plateImageSearch,
        'vehicle_data' => $vehicleData,
        'insurance' => $insuranceInfo,
        'halter_services' => $halterServices,
        'links' => [
            'gdv_zentralruf' => 'https://www.gdv-dl.de/zentralruf/',
            'mobile_de' => 'https://suchen.mobile.de/fahrzeuge/search.html?q=' . urlencode($plateClean),
            'autoscout' => 'https://www.autoscout24.de/lst?query=' . urlencode($plateClean),
            'autodna' => 'https://www.autodna.de/kennzeichen/' . urlencode($plateNoSpace),
            'carvertical' => 'https://www.carvertical.com/de/prufung/' . urlencode($plateNoSpace),
            'google_img' => 'https://www.google.com/search?q="' . urlencode($plateClean) . '"&tbm=isch',
            'google' => 'https://www.google.com/search?q="' . urlencode($plateClean) . '"',
        ],
    ];
}

// ============================================================
// 9e. REGISTRATION NUMBER SEARCH — Steuernummer, Aktenzeichen, HRB
// ============================================================
$regNumbers = array_filter([$serialNr, $taxId, $idNumber, $passNumber]);
if (!empty($regNumbers) || preg_match('/\d{2,}\/\d{2,}\/\d{2,}/', $name . ' ' . $address)) {
    // Also detect registration numbers in name/address fields
    if (preg_match('/(\d{2,}\/\d{2,}\/\d{2,})/', $name . ' ' . $address, $regMatch)) {
        $regNumbers[] = $regMatch[1];
    }
    $regResults = [];
    $mh = curl_multi_init();
    $handles = [];
    foreach (array_unique($regNumbers) as $idx => $regNr) {
        if (!$regNr) continue;
        $queries = [
            'google' => 'https://html.duckduckgo.com/html/?q=' . urlencode('"' . $regNr . '"'),
            'register' => 'https://html.duckduckgo.com/html/?q=' . urlencode('"' . $regNr . '" Handelsregister OR Amtsgericht OR Finanzamt OR Aktenzeichen'),
            'legal' => 'https://html.duckduckgo.com/html/?q=' . urlencode('"' . $regNr . '" Insolvenz OR Vollstreckung OR Gericht OR Urteil'),
        ];
        foreach ($queries as $qType => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>4, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36']);
            curl_multi_add_handle($mh, $ch);
            $handles[$regNr . '|' . $qType] = $ch;
        }
    }
    $running = 0;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.3); } while ($running > 0);
    foreach ($handles as $key => $ch) {
        [$nr, $qType] = explode('|', $key, 2);
        $html = curl_multi_getcontent($ch);
        if ($html && preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?class="result__snippet"[^>]*>(.*?)<\/span>/s', $html, $dm, PREG_SET_ORDER)) {
            foreach (array_slice($dm, 0, 5) as $m) {
                $rUrl = $m[1]; if (preg_match('/uddg=([^&]+)/', $rUrl, $u)) $rUrl = urldecode($u[1]);
                $regResults[$nr][$qType][] = ['title' => trim(strip_tags($m[2])), 'url' => $rUrl, 'snippet' => trim(strip_tags($m[3]))];
            }
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    if (!empty($regResults)) {
        $results['registration_search'] = $regResults;
        // Detect number type
        foreach ($regResults as $nr => $types) {
            $allSnippets = '';
            foreach ($types as $hits) foreach ($hits as $h) $allSnippets .= ' ' . ($h['snippet'] ?? '');
            $nrType = 'Unbekannt';
            if (preg_match('/Handelsregister|HRB|HRA/i', $allSnippets)) $nrType = 'Handelsregister';
            elseif (preg_match('/Steuernummer|Finanzamt|USt/i', $allSnippets)) $nrType = 'Steuernummer';
            elseif (preg_match('/Aktenzeichen|Gericht|Az\./i', $allSnippets)) $nrType = 'Aktenzeichen';
            elseif (preg_match('/Insolvenz/i', $allSnippets)) $nrType = 'Insolvenz-Aktenzeichen';
            elseif (preg_match('/Gewerbe/i', $allSnippets)) $nrType = 'Gewerbeschein';
            $results['registration_search'][$nr]['_type'] = $nrType;
        }
    }
}

// ============================================================
// 9e2. GERMAN HANDELSREGISTER — Company Registry (FREE)
// ============================================================
if ($name) {
    $hrUrl = 'https://db.offeneregister.de/openregister.json?sql=select+*+from+company+where+company_name+like+%27%25' . urlencode($name) . '%25%27+limit+10&_shape=objects';
    $hrRaw = safeFetch($hrUrl, 10);
    if ($hrRaw) {
        $hrData = json_decode($hrRaw, true);
        $rows = $hrData['rows'] ?? $hrData['objects'] ?? [];
        if (!empty($rows)) {
            $hrResults = [];
            foreach ($rows as $row) {
                $hrResults[] = [
                    'name' => $row['company_name'] ?? $row['name'] ?? '',
                    'office' => $row['registered_office'] ?? $row['current_status_detail'] ?? '',
                    'register_type' => $row['register_type'] ?? '',
                    'register_number' => $row['register_number'] ?? '',
                    'register_court' => $row['register_court'] ?? '',
                    'status' => $row['current_status'] ?? '',
                    'native_number' => $row['native_company_number'] ?? '',
                ];
            }
            $results['handelsregister'] = [
                'count' => count($hrResults),
                'companies' => $hrResults,
                'links' => [
                    'handelsregister' => 'https://www.handelsregister.de/rp_web/search.xhtml',
                    'northdata' => 'https://www.northdata.de/' . urlencode($name),
                    'unternehmensregister' => 'https://www.unternehmensregister.de/ureg/?submitaction=language&language=de',
                ],
            ];
        }
    }
}

// ============================================================
// 9f. AIRBNB PROFILE SCRAPER — Find hidden vacation rentals
// ============================================================
if ($name || $address) {
    $airbnbResults = [];
    $mh = curl_multi_init();
    $handles = [];
    $airbnbQueries = [];
    if ($name) {
        $airbnbQueries['host_profile'] = 'site:airbnb.de OR site:airbnb.com "' . $name . '" Gastgeber OR Host';
        $airbnbQueries['host_listings'] = 'site:airbnb.de "' . $name . '" Wohnung OR Apartment OR Unterkunft';
    }
    if ($address) {
        $airbnbQueries['address_listing'] = 'site:airbnb.de OR site:airbnb.com "' . $address . '"';
        $airbnbQueries['address_booking'] = 'site:booking.com OR site:vrbo.com "' . $address . '"';
    }
    if ($phone) $airbnbQueries['phone_listing'] = 'site:airbnb.de OR site:booking.com "' . preg_replace('/[^+0-9]/', '', $phone) . '"';
    foreach ($airbnbQueries as $qType => $query) {
        $ch = curl_init('https://html.duckduckgo.com/html/?q=' . urlencode($query));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>4, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36']);
        curl_multi_add_handle($mh, $ch);
        $handles[$qType] = $ch;
    }
    $running = 0;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.3); } while ($running > 0);
    foreach ($handles as $qType => $ch) {
        $html = curl_multi_getcontent($ch);
        if ($html && preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?class="result__snippet"[^>]*>(.*?)<\/span>/s', $html, $dm, PREG_SET_ORDER)) {
            foreach (array_slice($dm, 0, 5) as $m) {
                $rUrl = $m[1]; if (preg_match('/uddg=([^&]+)/', $rUrl, $u)) $rUrl = urldecode($u[1]);
                $airbnbResults[$qType][] = ['title' => trim(strip_tags($m[2])), 'url' => $rUrl, 'snippet' => trim(strip_tags($m[3]))];
            }
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    if (!empty($airbnbResults)) {
        $results['airbnb_scan'] = [
            'results' => $airbnbResults,
            'links' => [
                'airbnb_search' => 'https://www.airbnb.de/s/' . urlencode($address ?: $name) . '/homes',
                'booking_search' => 'https://www.booking.com/searchresults.html?ss=' . urlencode($address ?: $name),
                'vrbo_search' => 'https://www.vrbo.com/search?query=' . urlencode($address ?: $name),
            ],
        ];
    }
}

// ============================================================
// 9f2. IMPRESSUM SCRAPER + VALIDATION — Verify business data
// ============================================================
if ($domain && !in_array($domain, ['gmail.com','gmx.de','web.de','yahoo.com','hotmail.com','outlook.com','t-online.de','icloud.com','protonmail.com'])) {
    $impressumData = ['found' => false, 'valid' => false, 'issues' => []];
    // Try common Impressum URLs
    $impUrls = ["https://{$domain}/impressum", "https://{$domain}/impressum.html", "https://{$domain}/imprint", "https://www.{$domain}/impressum", "https://{$domain}/legal", "https://{$domain}/about"];
    $impHtml = null;
    $impFoundUrl = '';
    foreach ($impUrls as $iUrl) {
        $ch = curl_init($iUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>3, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0']);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && $resp && strlen($resp) > 500 && (stripos($resp, 'Impressum') !== false || stripos($resp, 'Imprint') !== false || stripos($resp, 'Angaben gemäß') !== false)) {
            $impHtml = $resp;
            $impFoundUrl = $iUrl;
            break;
        }
    }
    if ($impHtml) {
        $impressumData['found'] = true;
        $impressumData['url'] = $impFoundUrl;
        // Strip HTML tags, keep text
        $impText = strip_tags(preg_replace('/<(script|style|nav|footer|header)[^>]*>.*?<\/\1>/si', '', $impHtml));
        $impText = preg_replace('/\s+/', ' ', $impText);
        // Extract structured data
        $extracted = [];
        if (preg_match('/(?:Geschäftsführer|Inhaber|Vertreten durch|Managing Director)[:\s]+([A-ZÄÖÜa-zäöüß\s\.\-]{3,50})/u', $impText, $m)) $extracted['owner'] = trim($m[1]);
        if (preg_match('/(?:Handelsregister|HRB|HRA)[:\s]*([A-Z]+ ?\d+[^\s,]{0,20})/i', $impText, $m)) $extracted['hrb'] = trim($m[1]);
        if (preg_match('/(?:USt-IdNr|USt\.?-?Id|VAT)[.:\s]*([A-Z]{2}\d{5,11})/i', $impText, $m)) $extracted['vat'] = trim($m[1]);
        if (preg_match('/(?:Steuernummer|St\.?-?Nr)[.:\s]*([\d\/\s]{8,20})/i', $impText, $m)) $extracted['tax'] = trim($m[1]);
        if (preg_match('/(\d{5})\s+([A-ZÄÖÜa-zäöüß\-]+)/u', $impText, $m)) $extracted['city'] = trim($m[1] . ' ' . $m[2]);
        if (preg_match('/(?:Tel|Telefon|Phone|Fon)[.:\s]*([\+\d\s\-\/\(\)]{8,20})/i', $impText, $m)) $extracted['phone'] = trim($m[1]);
        if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $impText, $m)) $extracted['email'] = trim($m[0]);
        $impressumData['extracted'] = $extracted;
        // Validate
        if ($name && !empty($extracted['owner']) && stripos($extracted['owner'], explode(' ', $name)[0]) === false) {
            $impressumData['issues'][] = 'Impressum-Name (' . $extracted['owner'] . ') stimmt nicht mit Scan-Name (' . $name . ') überein';
        }
        if ($email && !empty($extracted['email']) && strtolower($extracted['email']) !== strtolower($email)) {
            $impressumData['issues'][] = 'Impressum-Email (' . $extracted['email'] . ') weicht ab';
        }
        if ($phone && !empty($extracted['phone'])) {
            $ph1 = preg_replace('/[^0-9]/', '', $phone);
            $ph2 = preg_replace('/[^0-9]/', '', $extracted['phone']);
            if (substr($ph1, -8) !== substr($ph2, -8)) $impressumData['issues'][] = 'Impressum-Telefon (' . $extracted['phone'] . ') weicht ab';
        }
        if (empty($extracted['hrb']) && empty($extracted['vat'])) $impressumData['issues'][] = 'Kein HRB oder USt-IdNr im Impressum';
        if (empty($extracted['owner'])) $impressumData['issues'][] = 'Kein Geschäftsführer/Inhaber erkannt';
        $impressumData['valid'] = empty($impressumData['issues']);
        // Cross-check with Handelsregister results
        if (!empty($results['handelsregister']['companies']) && !empty($extracted['hrb'])) {
            $hrMatch = false;
            foreach ($results['handelsregister']['companies'] as $hrc) {
                if (str_contains($extracted['hrb'], $hrc['register_number'] ?? '---')) { $hrMatch = true; break; }
            }
            if (!$hrMatch) $impressumData['issues'][] = 'HRB-Nummer aus Impressum nicht im Handelsregister verifiziert';
        }
    } else {
        $impressumData['issues'][] = 'Kein Impressum auf ' . $domain . ' gefunden';
    }
    $results['impressum_validation'] = $impressumData;
}

// ============================================================
// 9f3. WEBSITE DEEP SCAN — Subpages, metadata, tech, links
// ============================================================
if ($domain && !in_array($domain, ['gmail.com','gmx.de','web.de','yahoo.com','hotmail.com','outlook.com','t-online.de'])) {
    $webDeep = [];
    // Scan key subpages in parallel
    $subpages = ['/','about','/ueber-uns','/team','/kontakt','/contact','/datenschutz','/agb','/preise','/services','/portfolio'];
    $mh = curl_multi_init();
    $handles = [];
    foreach (array_slice($subpages, 0, 6) as $sp) {
        $url = "https://{$domain}{$sp}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>3, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_NOBODY=>0, CURLOPT_USERAGENT=>'Mozilla/5.0']);
        curl_multi_add_handle($mh, $ch);
        $handles[$sp] = $ch;
    }
    $running = 0;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.3); } while ($running > 0);
    $foundPages = [];
    $allEmails = []; $allPhones = []; $allSocial = [];
    foreach ($handles as $sp => $ch) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code >= 200 && $code < 400) {
            $html = curl_multi_getcontent($ch);
            if ($html && strlen($html) > 200) {
                $title = ''; if (preg_match('/<title[^>]*>([^<]+)</', $html, $tm)) $title = trim($tm[1]);
                $foundPages[] = ['path' => $sp, 'title' => $title, 'size' => strlen($html)];
                // Extract emails/phones from all pages
                preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $html, $em);
                $allEmails = array_merge($allEmails, $em[0] ?? []);
                preg_match_all('/(?:tel:|href="tel:)([\+\d\s\-\/\(\)]{8,20})/', $html, $pm);
                $allPhones = array_merge($allPhones, $pm[1] ?? []);
                // Social links
                preg_match_all('/href="(https?:\/\/(?:www\.)?(?:facebook|instagram|twitter|x|linkedin|youtube|tiktok)\.[a-z]+\/[^"]+)"/i', $html, $sm);
                $allSocial = array_merge($allSocial, $sm[1] ?? []);
            }
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    $webDeep['pages'] = $foundPages;
    $webDeep['emails_found'] = array_values(array_unique(array_filter($allEmails, fn($e) => !str_contains($e, 'example') && !str_contains($e, 'wix') && !str_contains($e, 'wordpress'))));
    $webDeep['phones_found'] = array_values(array_unique($allPhones));
    $webDeep['social_links'] = array_values(array_unique($allSocial));
    if (!empty($foundPages)) $results['website_deep'] = $webDeep;
}

// ============================================================
// 9f4. NORTHDATA + BUSINESS REGISTRIES — Scrape public data
// ============================================================
if ($name) {
    $bizSearches = [];
    $mh = curl_multi_init();
    $handles = [];
    $bizQueries = [
        'northdata' => 'site:northdata.de "' . $name . '"',
        'bundesanzeiger' => 'site:bundesanzeiger.de "' . $name . '"',
        'creditreform' => 'site:creditreform.de "' . $name . '"',
        'firmenwissen' => 'site:firmenwissen.de "' . $name . '"',
        'werzuwem' => 'site:wer-zu-wem.de "' . $name . '"',
        'genios' => 'site:genios.de "' . $name . '"',
        'unternehmensreg' => 'site:unternehmensregister.de "' . $name . '"',
        'transparency' => '"' . $name . '" Transparenzregister OR "wirtschaftlich Berechtigter"',
        'versicherung' => '"' . $name . '" Versicherung OR Haftpflicht OR Police OR versichert',
        'bewertungen' => '"' . $name . '" Bewertung OR Review site:google.com OR site:trustpilot.com OR site:provenexpert.com OR site:kununu.de',
    ];
    foreach ($bizQueries as $qType => $query) {
        $ch = curl_init('https://html.duckduckgo.com/html/?q=' . urlencode($query));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>4, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36']);
        curl_multi_add_handle($mh, $ch);
        $handles[$qType] = $ch;
    }
    $running = 0;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.3); } while ($running > 0);
    foreach ($handles as $qType => $ch) {
        $html = curl_multi_getcontent($ch);
        if ($html && preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?class="result__snippet"[^>]*>(.*?)<\/span>/s', $html, $dm, PREG_SET_ORDER)) {
            foreach (array_slice($dm, 0, 5) as $m) {
                $rUrl = $m[1]; if (preg_match('/uddg=([^&]+)/', $rUrl, $u)) $rUrl = urldecode($u[1]);
                $bizSearches[$qType][] = ['title' => trim(strip_tags($m[2])), 'url' => $rUrl, 'snippet' => trim(strip_tags($m[3]))];
            }
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    if (!empty($bizSearches)) $results['business_intel'] = $bizSearches;
}

// ============================================================
// 9f5. NAME PERMUTATION ENGINE + USERNAME GENERATOR
// ============================================================
if ($name) {
    $parts = preg_split('/\s+/', trim($name));
    $first = strtolower($parts[0] ?? '');
    $last = strtolower(end($parts) ?: '');
    $middle = count($parts) > 2 ? strtolower($parts[1]) : '';
    $initials = implode('', array_map(fn($p) => substr(strtolower($p), 0, 1), $parts));

    // === NAME PERMUTATIONS — every word alone, every pair, every combo ===
    $namePerms = [];
    // Each word alone
    foreach ($parts as $p) $namePerms[] = $p;
    // Every pair (order matters)
    for ($i=0; $i<count($parts); $i++) {
        for ($j=0; $j<count($parts); $j++) {
            if ($i !== $j) $namePerms[] = $parts[$i] . ' ' . $parts[$j];
        }
    }
    // Every consecutive pair
    for ($i=0; $i<count($parts)-1; $i++) {
        $namePerms[] = $parts[$i] . ' ' . $parts[$i+1];
    }
    // Full name + reversed
    $namePerms[] = $name;
    $namePerms[] = implode(' ', array_reverse($parts));
    // First + last only (skip middle)
    if (count($parts) > 2) $namePerms[] = $parts[0] . ' ' . end($parts);
    $namePerms = array_values(array_unique(array_filter($namePerms)));
    $results['name_permutations'] = ['count' => count($namePerms), 'variations' => $namePerms];

    // === PARALLEL SEARCH — Search every permutation across web ===
    $permSearches = [];
    $mh = curl_multi_init();
    $handles = [];
    // Search top 6 most promising permutations (full name + each word + key combos)
    $searchPerms = array_slice($namePerms, 0, min(8, count($namePerms)));
    foreach ($searchPerms as $idx => $perm) {
        $url = 'https://html.duckduckgo.com/html/?q=' . urlencode('"' . $perm . '"' . ($email ? ' OR "' . $email . '"' : ''));
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>3, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36']);
        curl_multi_add_handle($mh, $ch);
        $handles[$perm] = $ch;
    }
    $running = 0;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.3); } while ($running > 0);
    foreach ($handles as $perm => $ch) {
        $html = curl_multi_getcontent($ch);
        $parsed = [];
        if ($html && preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?class="result__snippet"[^>]*>(.*?)<\/span>/s', $html, $dm, PREG_SET_ORDER)) {
            foreach (array_slice($dm, 0, 5) as $m) {
                $rUrl = $m[1]; if (preg_match('/uddg=([^&]+)/', $rUrl, $u)) $rUrl = urldecode($u[1]);
                $parsed[] = ['title' => trim(strip_tags($m[2])), 'url' => $rUrl, 'snippet' => trim(strip_tags($m[3]))];
            }
        }
        if (!empty($parsed)) $permSearches[$perm] = ['query' => $perm, 'count' => count($parsed), 'results' => $parsed];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    if (!empty($permSearches)) $results['permutation_search'] = $permSearches;

    // === USERNAME GENERATOR — 30+ patterns ===
    $genUsernames = array_unique(array_filter([
        $first . $last, $first . '.' . $last, $first . '_' . $last, $first . '-' . $last,
        $last . $first, $last . '.' . $first, $last . '_' . $first,
        substr($first, 0, 1) . $last, substr($first, 0, 1) . '.' . $last, substr($first, 0, 1) . '_' . $last,
        $first . substr($last, 0, 1), $first . substr($last, 0, 3),
        $initials, $last . $initials,
        $first . $last . '1', $first . $last . '01', $first . $last . '123',
        $first . $last . date('y'), $first . $last . date('Y'),
        $first . '.' . $last . date('y'),
        $first . $last . 'de', $last . '.' . $first . '.de',
        $email ? substr($email, 0, strpos($email, '@') ?: 0) : '',
        strlen($first) > 3 ? substr($first, 0, 3) . $last : '',
        $middle ? $first . $middle[0] . $last : '',
        // Name part combinations for usernames
        $middle ? $first . $middle : '',
        $middle ? $middle . $last : '',
        $middle ? $first . '.' . $middle . '.' . $last : '',
        $middle ? substr($first,0,1) . substr($middle,0,1) . $last : '',
    ]));
    $results['generated_usernames'] = ['count' => count($genUsernames), 'usernames' => array_values($genUsernames)];
}

// ============================================================
// 9g. DARK WEB & LEAK INTELLIGENCE — Paste sites, breach DBs
// ============================================================
$darkIntel = [];

// 1. Gravatar — reveals avatar + profile across platforms
if ($email) {
    $gHash = md5(strtolower(trim($email)));
    $gUrl = "https://www.gravatar.com/{$gHash}.json";
    $gRaw = safeFetch($gUrl, 5);
    if ($gRaw) {
        $gData = json_decode($gRaw, true);
        $entry = $gData['entry'][0] ?? [];
        if ($entry) {
            $darkIntel['gravatar'] = [
                'found' => true,
                'display_name' => $entry['displayName'] ?? '',
                'about' => $entry['aboutMe'] ?? '',
                'location' => $entry['currentLocation'] ?? '',
                'avatar' => "https://www.gravatar.com/avatar/{$gHash}?s=200",
                'urls' => array_map(fn($u) => ['title' => $u['title'] ?? '', 'url' => $u['value'] ?? ''], $entry['urls'] ?? []),
                'accounts' => array_map(fn($a) => ['shortname' => $a['shortname'] ?? '', 'url' => $a['url'] ?? '', 'username' => $a['username'] ?? ''], $entry['accounts'] ?? []),
            ];
        }
    }
}

// 2. Keybase — crypto identity, PGP keys, verified accounts
if ($name || $email) {
    $kbQuery = $email ?: str_replace(' ', '+', $name);
    $kbRaw = safeFetch("https://keybase.io/_/api/1.0/user/lookup.json?usernames=" . urlencode($kbQuery), 5);
    if (!$kbRaw && $name) {
        // Try username search
        $kbUser = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
        $kbRaw = safeFetch("https://keybase.io/_/api/1.0/user/lookup.json?usernames={$kbUser}", 5);
    }
    if ($kbRaw) {
        $kb = json_decode($kbRaw, true);
        $kbUser = $kb['them'][0] ?? null;
        if ($kbUser && !empty($kbUser['basics'])) {
            $proofs = [];
            foreach ($kbUser['proofs_summary']['all'] ?? [] as $proof) {
                $proofs[] = ['service' => $proof['proof_type'] ?? '', 'username' => $proof['nametag'] ?? '', 'url' => $proof['human_url'] ?? ''];
            }
            $darkIntel['keybase'] = [
                'found' => true,
                'username' => $kbUser['basics']['username'] ?? '',
                'full_name' => $kbUser['profile']['full_name'] ?? '',
                'bio' => $kbUser['profile']['bio'] ?? '',
                'location' => $kbUser['profile']['location'] ?? '',
                'has_pgp' => !empty($kbUser['public_keys']['pgp_public_keys']),
                'verified_proofs' => $proofs,
            ];
        }
    }
}

// 3. PasteBin/GitHub Gist — leaked data search (via DDG)
if ($email) {
    $pasteResults = [];
    $pasteUrl = 'https://html.duckduckgo.com/html/?q=' . urlencode('"' . $email . '" site:pastebin.com OR site:gist.github.com OR site:ghostbin.com OR site:paste.ee');
    $pasteHtml = safeFetch($pasteUrl, 8);
    if (!$pasteHtml) {
        $ch = curl_init($pasteUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>4, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36']);
        $pasteHtml = curl_exec($ch); curl_close($ch);
    }
    if ($pasteHtml && preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/s', $pasteHtml, $pm, PREG_SET_ORDER)) {
        foreach (array_slice($pm, 0, 10) as $m) {
            $pUrl = $m[1];
            if (preg_match('/uddg=([^&]+)/', $pUrl, $u)) $pUrl = urldecode($u[1]);
            $pasteResults[] = ['title' => trim(strip_tags($m[2])), 'url' => $pUrl];
        }
    }
    if (!empty($pasteResults)) $darkIntel['paste_leaks'] = ['count' => count($pasteResults), 'results' => $pasteResults];
}

// 4. Telegram OSINT — check phone on Telegram + username
if ($phone) {
    $cleanPhone = preg_replace('/[^+0-9]/', '', $phone);
    // Telegram deep links
    $darkIntel['telegram_osint'] = [
        'phone_link' => 'https://t.me/+' . ltrim($cleanPhone, '+'),
        'wa_link' => 'https://wa.me/' . ltrim($cleanPhone, '+'),
        'signal_check' => 'Signal: manuell prüfen mit Nummer ' . $cleanPhone,
    ];
}
if (isset($genUsernames)) {
    // Check first 5 generated usernames on Telegram via curl_multi
    $tgChecks = array_slice($genUsernames, 0, 5);
    $mh = curl_multi_init();
    $handles = [];
    foreach ($tgChecks as $tgUser) {
        $ch = curl_init("https://t.me/{$tgUser}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>4, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0']);
        curl_multi_add_handle($mh, $ch);
        $handles[$tgUser] = $ch;
    }
    $running = 0;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.3); } while ($running > 0);
    $tgFound = [];
    foreach ($handles as $tgUser => $ch) {
        $html = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // Telegram returns 200 with user info if exists, different page if not
        if ($code === 200 && $html && !str_contains($html, 'tgme_page_icon') && str_contains($html, 'tgme_page_title')) {
            $tgName = '';
            if (preg_match('/tgme_page_title[^>]*>([^<]+)</', $html, $tm)) $tgName = trim($tm[1]);
            $tgFound[] = ['username' => $tgUser, 'display_name' => $tgName, 'url' => "https://t.me/{$tgUser}"];
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    if (!empty($tgFound)) $darkIntel['telegram_profiles'] = $tgFound;
}

// 5. Impressum Scraper — find Impressum pages mentioning the person
if ($name) {
    $impUrl = 'https://html.duckduckgo.com/html/?q=' . urlencode('"' . $name . '" Impressum Geschäftsführer OR Inhaber OR Verantwortlich');
    $ch = curl_init($impUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>4, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36']);
    $impHtml = curl_exec($ch); curl_close($ch);
    if ($impHtml && preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?class="result__snippet"[^>]*>(.*?)<\/span>/s', $impHtml, $im, PREG_SET_ORDER)) {
        $impResults = [];
        foreach (array_slice($im, 0, 8) as $m) {
            $iUrl = $m[1]; if (preg_match('/uddg=([^&]+)/', $iUrl, $u)) $iUrl = urldecode($u[1]);
            $impResults[] = ['title' => trim(strip_tags($m[2])), 'url' => $iUrl, 'snippet' => trim(strip_tags($m[3]))];
        }
        if (!empty($impResults)) $darkIntel['impressum_mentions'] = ['count' => count($impResults), 'results' => $impResults];
    }
}

// 6. Insolvenzbekanntmachungen (German insolvency announcements — FREE)
if ($name) {
    $insolvUrl = 'https://html.duckduckgo.com/html/?q=' . urlencode('"' . $name . '" site:insolvenzbekanntmachungen.de OR site:neu.insolvenzbekanntmachungen.de');
    $ch = curl_init($insolvUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>4, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36']);
    $insHtml = curl_exec($ch); curl_close($ch);
    $insResults = [];
    if ($insHtml && preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?class="result__snippet"[^>]*>(.*?)<\/span>/s', $insHtml, $im2, PREG_SET_ORDER)) {
        foreach (array_slice($im2, 0, 5) as $m) {
            $iUrl = $m[1]; if (preg_match('/uddg=([^&]+)/', $iUrl, $u)) $iUrl = urldecode($u[1]);
            $insResults[] = ['title' => trim(strip_tags($m[2])), 'url' => $iUrl, 'snippet' => trim(strip_tags($m[3]))];
        }
    }
    if (!empty($insResults)) {
        $darkIntel['insolvency'] = ['count' => count($insResults), 'results' => $insResults];
    }
    $darkIntel['insolvency_links'] = [
        'insolvenzbekanntmachungen' => 'https://neu.insolvenzbekanntmachungen.de/ap/suche.jsf',
        'bundesanzeiger' => 'https://www.bundesanzeiger.de/pub/de/suche?10&query=' . urlencode($name),
        'northdata_insolvenz' => 'https://www.northdata.de/' . urlencode($name),
    ];
}

// 6b. INSTAGRAM + LINKEDIN DEEP SCAN
if ($name) {
    $socialDeep = [];
    $mh = curl_multi_init();
    $handles = [];
    $socialQueries = [
        'instagram_profile' => 'site:instagram.com "' . $name . '"',
        'instagram_tagged' => 'site:instagram.com "' . $name . '" tagged OR getaggt OR erwähnt',
        'linkedin_profile' => 'site:linkedin.com/in "' . $name . '"',
        'linkedin_company' => 'site:linkedin.com/company "' . $name . '"',
        'xing_profile' => 'site:xing.com "' . $name . '"',
        'facebook_profile' => 'site:facebook.com "' . $name . '"',
        'tiktok_profile' => 'site:tiktok.com "' . $name . '"',
    ];
    if ($email) {
        $socialQueries['social_email'] = '"' . $email . '" site:instagram.com OR site:linkedin.com OR site:facebook.com OR site:twitter.com';
    }
    foreach ($socialQueries as $qType => $query) {
        $ch = curl_init('https://html.duckduckgo.com/html/?q=' . urlencode($query));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>3, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36']);
        curl_multi_add_handle($mh, $ch);
        $handles[$qType] = $ch;
    }
    $running = 0;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.3); } while ($running > 0);
    foreach ($handles as $qType => $ch) {
        $html = curl_multi_getcontent($ch);
        if ($html && preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?class="result__snippet"[^>]*>(.*?)<\/span>/s', $html, $dm, PREG_SET_ORDER)) {
            foreach (array_slice($dm, 0, 5) as $m) {
                $rUrl = $m[1]; if (preg_match('/uddg=([^&]+)/', $rUrl, $u)) $rUrl = urldecode($u[1]);
                $socialDeep[$qType][] = ['title' => trim(strip_tags($m[2])), 'url' => $rUrl, 'snippet' => trim(strip_tags($m[3]))];
            }
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    if (!empty($socialDeep)) $darkIntel['social_deep'] = $socialDeep;
}

// 7. Document & File Leak Search
if ($name || $email) {
    $q = $email ?: $name;
    $docSearches = [
        'leaked_docs' => '"' . $q . '" filetype:pdf OR filetype:xlsx OR filetype:docx OR filetype:csv',
        'forum_mentions' => '"' . $q . '" site:forum.* OR site:community.* OR site:board.*',
    ];
    $mh = curl_multi_init();
    $handles = [];
    foreach ($docSearches as $key => $query) {
        $ch = curl_init('https://html.duckduckgo.com/html/?q=' . urlencode($query));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>4, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36']);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    $running = 0;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.3); } while ($running > 0);
    foreach ($handles as $key => $ch) {
        $html = curl_multi_getcontent($ch);
        $parsed = [];
        if ($html && preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?class="result__snippet"[^>]*>(.*?)<\/span>/s', $html, $dm, PREG_SET_ORDER)) {
            foreach (array_slice($dm, 0, 5) as $m) {
                $dUrl = $m[1]; if (preg_match('/uddg=([^&]+)/', $dUrl, $u)) $dUrl = urldecode($u[1]);
                $parsed[] = ['title' => trim(strip_tags($m[2])), 'url' => $dUrl, 'snippet' => trim(strip_tags($m[3]))];
            }
        }
        if (!empty($parsed)) $darkIntel[$key] = ['count' => count($parsed), 'results' => $parsed];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
}

if (!empty($darkIntel)) $results['deep_intel'] = $darkIntel;

// ============================================================
// 9h. CORRELATION ENGINE — Cross-reference all data points
// ============================================================
$correlation = ['score' => 0, 'confidence' => 'LOW', 'connections' => [], 'identity_graph' => []];
$dataPoints = ['emails' => [], 'phones' => [], 'usernames' => [], 'domains' => [], 'ips' => [], 'names' => [], 'locations' => []];

// Collect all found identifiers
if ($email) $dataPoints['emails'][] = $email;
if ($phone) $dataPoints['phones'][] = preg_replace('/[^+0-9]/', '', $phone);
if ($name) $dataPoints['names'][] = $name;
if ($domain) $dataPoints['domains'][] = $domain;

// From username search
if (!empty($results['username_search'])) {
    foreach ($results['username_search'] as $u) {
        $dataPoints['usernames'][] = $u['username'] ?? '';
        $correlation['identity_graph'][] = ['type' => 'social', 'platform' => $u['platform'], 'username' => $u['username'] ?? '', 'url' => $u['url'] ?? ''];
    }
}
// From Gravatar
if (!empty($darkIntel['gravatar']['accounts'])) {
    foreach ($darkIntel['gravatar']['accounts'] as $ga) {
        $dataPoints['usernames'][] = $ga['username'];
        $correlation['identity_graph'][] = ['type' => 'gravatar', 'platform' => $ga['shortname'], 'username' => $ga['username'], 'url' => $ga['url']];
    }
}
// From Keybase
if (!empty($darkIntel['keybase']['verified_proofs'])) {
    foreach ($darkIntel['keybase']['verified_proofs'] as $kp) {
        $correlation['identity_graph'][] = ['type' => 'keybase_proof', 'platform' => $kp['service'], 'username' => $kp['username'], 'url' => $kp['url']];
    }
}
// From Telegram
if (!empty($darkIntel['telegram_profiles'])) {
    foreach ($darkIntel['telegram_profiles'] as $tp) {
        $correlation['identity_graph'][] = ['type' => 'telegram', 'platform' => 'Telegram', 'username' => $tp['username'], 'url' => $tp['url']];
    }
}
// From email exposure
if (!empty($results['email_exposure']['emails'])) {
    foreach ($results['email_exposure']['emails'] as $foundEmail) {
        if (trim($foundEmail) !== $email) $dataPoints['emails'][] = trim($foundEmail);
    }
}
// From Hunter domain
if (!empty($results['hunter_domain']['emails'])) {
    foreach ($results['hunter_domain']['emails'] as $he) {
        $dataPoints['emails'][] = $he['email'];
        $dataPoints['names'][] = $he['name'];
    }
}
// From DB profile
if (!empty($dbProfile['found'])) {
    $c = $dbProfile['customer'];
    if ($c['email'] && $c['email'] !== $email) $dataPoints['emails'][] = $c['email'];
    if ($c['phone']) $dataPoints['phones'][] = $c['phone'];
    foreach ($dbProfile['addresses'] ?? [] as $addr) {
        $dataPoints['locations'][] = trim(($addr['street'] ?? '') . ' ' . ($addr['number'] ?? '') . ', ' . ($addr['postal_code'] ?? '') . ' ' . ($addr['city'] ?? ''));
    }
}
// IPs
if (!empty($results['bgp_asn']['ip'])) $dataPoints['ips'][] = $results['bgp_asn']['ip'];
if (!empty($results['reverse_ip']['ip'])) $dataPoints['ips'][] = $results['reverse_ip']['ip'];

// Deduplicate all
foreach ($dataPoints as &$arr) $arr = array_values(array_unique(array_filter($arr)));
unset($arr);

// Score calculation
$score = 0;
$score += count($dataPoints['emails']) * 10;
$score += count($dataPoints['phones']) * 15;
$score += count($dataPoints['usernames']) * 5;
$score += count($correlation['identity_graph']) * 8;
$score += !empty($results['breach_check']['breached']) ? 20 : 0;
$score += !empty($darkIntel['paste_leaks']) ? 25 : 0;
$score += !empty($darkIntel['gravatar']['found']) ? 10 : 0;
$score += !empty($darkIntel['keybase']['found']) ? 15 : 0;
$score += !empty($darkIntel['insolvency']) ? 30 : 0;
$score += !empty($darkIntel['impressum_mentions']) ? 15 : 0;
$score += !empty($results['handelsregister']) ? 10 : 0;
$score += !empty($dbProfile['found']) ? 20 : 0;

$correlation['score'] = $score;
$correlation['confidence'] = $score > 100 ? 'HIGH' : ($score > 40 ? 'MEDIUM' : 'LOW');
$correlation['data_points'] = $dataPoints;
$correlation['total_identifiers'] = array_sum(array_map('count', $dataPoints));

// Cross-reference connections
if (count($dataPoints['emails']) > 1) {
    $correlation['connections'][] = ['type' => 'multi_email', 'detail' => count($dataPoints['emails']) . ' Email-Adressen gefunden: ' . implode(', ', array_slice($dataPoints['emails'], 0, 5))];
}
if (count($dataPoints['usernames']) > 3) {
    $correlation['connections'][] = ['type' => 'multi_platform', 'detail' => 'Aktiv auf ' . count($dataPoints['usernames']) . ' Plattformen mit verifizierten Accounts'];
}
if (!empty($darkIntel['impressum_mentions']) && !empty($results['handelsregister'])) {
    $correlation['connections'][] = ['type' => 'business_verified', 'detail' => 'Firma im Handelsregister UND Impressum online — verifizierter Geschäftsinhaber'];
}
if (!empty($darkIntel['insolvency'])) {
    $correlation['connections'][] = ['type' => 'insolvency_warning', 'detail' => 'Insolvenzbekanntmachung gefunden — VORSICHT'];
}
if (!empty($darkIntel['paste_leaks'])) {
    $correlation['connections'][] = ['type' => 'data_leak', 'detail' => 'Daten auf Paste-Sites gefunden — möglicher Leak'];
}
if (!empty($results['breach_check']['breached']) && !empty($darkIntel['paste_leaks'])) {
    $correlation['connections'][] = ['type' => 'double_exposure', 'detail' => 'Email in Breach-DB UND auf Paste-Sites — hohe Exposure'];
}

$results['correlation'] = $correlation;

// ============================================================
// ============================================================
// 10. WEB INTELLIGENCE — Actual search results fetched inline
// ============================================================
function fetchSearchResults(string $query, int $limit = 5): array {
    $url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) return [];

    $results = [];
    // Parse DuckDuckGo HTML results
    if (preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?class="result__snippet"[^>]*>(.*?)<\/span>/s', $html, $matches, PREG_SET_ORDER)) {
        foreach (array_slice($matches, 0, $limit) as $m) {
            $url = $m[1];
            // Extract real URL from DDG redirect
            if (preg_match('/uddg=([^&]+)/', $url, $u)) {
                $url = urldecode($u[1]);
            }
            $title = strip_tags($m[2]);
            $snippet = strip_tags($m[3]);
            if ($title && $url) {
                $results[] = ['title' => trim($title), 'url' => $url, 'snippet' => trim($snippet)];
            }
        }
    }
    return $results;
}

$webIntel = [];
if ($name) {
    // Core searches — simple exact queries work best with DDG
    $searches = [];
    $searches['person'] = '"' . $name . '"' . ($address ? ' ' . $address : '');
    if ($email) $searches['email'] = $email;
    if ($phone) $searches['phone'] = preg_replace('/[^+0-9]/', '', $phone);
    $searches['social'] = '"' . $name . '" site:facebook.com site:instagram.com site:linkedin.com';
    $searches['business'] = '"' . $name . '" Firma GmbH Geschäftsführer';
    $searches['legal'] = '"' . $name . '" Gericht Insolvenz Polizei';
    $searches['property'] = '"' . $name . '" Wohnung Airbnb Immobilie';
    $searches['reviews'] = '"' . $name . '" Bewertung Erfahrung';

    // Run searches in parallel with curl_multi
    $mh = curl_multi_init();
    $handles = [];
    foreach ($searches as $key => $query) {
        if (!$query) continue;
        $url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    $running = 0;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.3); } while ($running > 0);

    foreach ($handles as $key => $ch) {
        $html = curl_multi_getcontent($ch);
        $parsed = [];
        if ($html && preg_match_all('/class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?class="result__snippet"[^>]*>(.*?)<\/span>/s', $html, $matches, PREG_SET_ORDER)) {
            foreach (array_slice($matches, 0, 5) as $m) {
                $rurl = $m[1];
                if (preg_match('/uddg=([^&]+)/', $rurl, $u)) $rurl = urldecode($u[1]);
                $parsed[] = ['title' => trim(strip_tags($m[2])), 'url' => $rurl, 'snippet' => trim(strip_tags($m[3]))];
            }
        }
        if (!empty($parsed)) $webIntel[$key] = ['query' => $searches[$key], 'results' => $parsed, 'count' => count($parsed)];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
}
if (!empty($webIntel)) $results['web_intel'] = $webIntel;

// ============================================================
// 10b. EXTENDED SEARCHES — Direct links for manual investigation
// ============================================================
$extendedSearches = [];
if ($name) {
    $nameEnc = urlencode($name);
    $extendedSearches = [
        'classifieds' => "https://www.google.com/search?q=%22{$nameEnc}%22+site:kleinanzeigen.de+OR+site:markt.de+OR+site:quoka.de",
        'obituary' => "https://www.google.com/search?q=%22{$nameEnc}%22+Nachruf+OR+Todesanzeige+OR+verstorben+OR+obituary",
        'crypto' => "https://www.google.com/search?q=%22{$nameEnc}%22+Bitcoin+OR+Ethereum+OR+Krypto+OR+Wallet+OR+blockchain",
        'court' => "https://www.google.com/search?q=%22{$nameEnc}%22+Gericht+OR+Urteil+OR+Verfahren+OR+Klage+OR+Beschluss",
        'police' => "https://www.google.com/search?q=%22{$nameEnc}%22+Polizei+OR+Festnahme+OR+Straftat+OR+Ermittlung",
        'images' => "https://www.google.com/search?q=%22{$nameEnc}%22&tbm=isch",
        'news' => "https://www.google.com/search?q=%22{$nameEnc}%22&tbm=nws",
        'videos' => "https://www.google.com/search?q=%22{$nameEnc}%22&tbm=vid",
        'reviews' => "https://www.google.com/search?q=%22{$nameEnc}%22+Bewertung+OR+Review+OR+Erfahrung+OR+Rezension",
        'documents' => "https://www.google.com/search?q=%22{$nameEnc}%22+filetype:pdf+OR+filetype:doc+OR+filetype:xls",
        'social_all' => "https://www.google.com/search?q=%22{$nameEnc}%22+site:facebook.com+OR+site:instagram.com+OR+site:linkedin.com+OR+site:x.com",
    ];
    if ($phone) {
        $phoneEnc = urlencode(preg_replace('/[^+0-9]/','',$phone));
        $extendedSearches['phone_trace'] = "https://www.google.com/search?q=%22{$phoneEnc}%22";
        $extendedSearches['phone_ads'] = "https://www.google.com/search?q=%22{$phoneEnc}%22+site:kleinanzeigen.de+OR+site:immobilienscout24.de+OR+site:airbnb.com";
    }
    if ($email) {
        $emailEnc = urlencode($email);
        $extendedSearches['email_trace'] = "https://www.google.com/search?q=%22{$emailEnc}%22";
        $extendedSearches['email_registrations'] = "https://www.google.com/search?q=%22{$emailEnc}%22+site:github.com+OR+site:stackoverflow.com+OR+site:reddit.com";
    }
}
$results['search_links'] = $extendedSearches;

// ============================================================
// 11. INTELLIGENCE DOSSIER — Correlate all findings
// ============================================================
$dossier = [
    'subject' => $name ?: $email ?: $phone,
    'scan_date' => date('Y-m-d H:i:s'),
    'data_sources' => array_keys($results),
    'findings' => [],
    'risk_level' => 'LOW',
    'risk_factors' => [],
];

// Correlate findings
if (!empty($results['breach_check']['breached'])) {
    $dossier['risk_factors'][] = 'Email in ' . ($results['breach_check']['count'] ?? '?') . ' Data Breaches gefunden';
    $dossier['risk_level'] = 'MEDIUM';
}
if (!empty($results['email_security']) && !$results['email_security']['has_spf']) {
    $dossier['risk_factors'][] = 'Email-Domain ohne SPF-Schutz (spoofbar)';
}
if (!empty($dbProfile['risk_flags'])) {
    $dossier['risk_factors'] = array_merge($dossier['risk_factors'], $dbProfile['risk_flags']);
    if (count($dbProfile['risk_flags']) >= 2) $dossier['risk_level'] = 'MEDIUM';
}
if (!empty($results['username_search']) && count($results['username_search']) > 5) {
    $platformNames = array_map(fn($u) => $u['platform'], $results['username_search']);
    $dossier['findings'][] = 'Aktiv auf ' . count($platformNames) . ' Plattformen: ' . implode(', ', $platformNames);
}
if (!empty($results['gleif_lei'])) {
    $dossier['findings'][] = 'LEI registriert: ' . $results['gleif_lei']['count'] . ' Eintrag(e)';
}
if (!empty($results['opencorporates'])) {
    $dossier['findings'][] = 'OpenCorporates: ' . $results['opencorporates']['count'] . ' Firmen gefunden';
}
if (!empty($dbProfile['found'])) {
    $dossier['findings'][] = 'Interner Kunde seit ' . ($dbProfile['stats']['first_job'] ?? '?') . ', ' . $dbProfile['stats']['total_jobs'] . ' Jobs, ' . number_format($dbProfile['stats']['total_revenue'], 2) . ' € Umsatz';
}
if (!empty($results['virustotal'])) {
    $vt = $results['virustotal'];
    if ($vt['malicious'] > 0) {
        $dossier['risk_factors'][] = 'VirusTotal: Domain als malicious gemeldet (' . $vt['malicious'] . ' Engines)';
        $dossier['risk_level'] = 'HIGH';
    } else {
        $dossier['findings'][] = 'VirusTotal: Domain clean (Reputation: ' . $vt['reputation'] . ')';
    }
}
if (!empty($results['hunter'])) {
    $hu = $results['hunter'];
    $dossier['findings'][] = 'Hunter.io: Email ' . strtoupper($hu['result']) . ' (Score: ' . $hu['score'] . '%)';
    if ($hu['disposable']) $dossier['risk_factors'][] = 'Wegwerf-Email erkannt (Hunter.io)';
    if ($hu['result'] === 'undeliverable') $dossier['risk_factors'][] = 'Email nicht zustellbar (Hunter.io)';
}
if (!empty($results['shodan'])) {
    $sh = $results['shodan'];
    $dossier['findings'][] = 'Shodan: ' . count($sh['ports']) . ' offene Ports, ISP: ' . $sh['isp'];
    if (!empty($sh['vulns'])) $dossier['risk_factors'][] = 'Shodan: ' . count($sh['vulns']) . ' Schwachstellen gefunden';
}
if (!empty($results['dns_services']['detected_services'])) {
    $dossier['findings'][] = 'DNS Services: ' . count($results['dns_services']['detected_services']) . ' Dienste erkannt: ' . implode(', ', $results['dns_services']['detected_services']);
}
if (!empty($results['bgp_asn']['asn'])) {
    $ba = $results['bgp_asn'];
    $dossier['findings'][] = 'Netzwerk: ASN ' . $ba['asn'] . ' (' . $ba['asn_name'] . '), ' . $ba['country'];
}
if (!empty($results['handelsregister'])) {
    $dossier['findings'][] = 'Handelsregister: ' . $results['handelsregister']['count'] . ' Einträge gefunden';
}
// Deep Intel findings
if (!empty($results['deep_intel']['gravatar']['found'])) {
    $dossier['findings'][] = 'Gravatar: Profil gefunden (' . ($results['deep_intel']['gravatar']['display_name'] ?: 'anonym') . ')';
}
if (!empty($results['deep_intel']['keybase']['found'])) {
    $kb = $results['deep_intel']['keybase'];
    $dossier['findings'][] = 'Keybase: ' . $kb['username'] . ($kb['has_pgp'] ? ' (PGP-Key vorhanden)' : '') . ', ' . count($kb['verified_proofs']) . ' verifizierte Accounts';
}
if (!empty($results['deep_intel']['paste_leaks'])) {
    $dossier['risk_factors'][] = 'Daten auf ' . $results['deep_intel']['paste_leaks']['count'] . ' Paste-Sites gefunden';
    $dossier['risk_level'] = 'MEDIUM';
}
if (!empty($results['deep_intel']['telegram_profiles'])) {
    $tgNames = array_map(fn($t) => '@' . $t['username'], $results['deep_intel']['telegram_profiles']);
    $dossier['findings'][] = 'Telegram: ' . implode(', ', $tgNames);
}
if (!empty($results['deep_intel']['impressum_mentions'])) {
    $dossier['findings'][] = 'Impressum: In ' . $results['deep_intel']['impressum_mentions']['count'] . ' Webseiten als Inhaber/GF erwähnt';
}
if (!empty($results['deep_intel']['insolvency'])) {
    $dossier['risk_factors'][] = 'INSOLVENZ: ' . $results['deep_intel']['insolvency']['count'] . ' Bekanntmachung(en) gefunden';
    $dossier['risk_level'] = 'HIGH';
}
if (!empty($results['deep_intel']['leaked_docs'])) {
    $dossier['risk_factors'][] = 'Dokumente öffentlich auffindbar: ' . $results['deep_intel']['leaked_docs']['count'] . ' Treffer';
}
if (!empty($results['airbnb_scan'])) {
    $totalAirbnb = array_sum(array_map('count', $results['airbnb_scan']['results']));
    $dossier['findings'][] = 'Airbnb/Booking: ' . $totalAirbnb . ' Listings/Profile gefunden';
}
if (!empty($results['impressum_validation'])) {
    $iv = $results['impressum_validation'];
    if ($iv['found'] && $iv['valid']) $dossier['findings'][] = 'Impressum: Verifiziert, alle Daten konsistent';
    elseif ($iv['found'] && !$iv['valid']) { $dossier['risk_factors'][] = 'Impressum: ' . count($iv['issues']) . ' Problem(e) — ' . implode(', ', array_slice($iv['issues'], 0, 2)); }
    else $dossier['risk_factors'][] = 'Kein Impressum auf Domain gefunden';
}
if (!empty($results['website_deep'])) {
    $wd = $results['website_deep'];
    $dossier['findings'][] = 'Website: ' . count($wd['pages']) . ' Seiten, ' . count($wd['emails_found']) . ' Emails, ' . count($wd['social_links']) . ' Social Links';
}
if (!empty($results['plate_search'])) {
    $totalPlate = array_sum(array_map('count', $results['plate_search']['results']));
    if ($totalPlate > 0) $dossier['findings'][] = 'Kennzeichen ' . $results['plate_search']['plate'] . ': ' . $totalPlate . ' Treffer (' . $results['plate_search']['region'] . ')';
}
if (!empty($results['business_intel']['versicherung'])) {
    $dossier['findings'][] = 'Versicherung: ' . count($results['business_intel']['versicherung']) . ' Treffer gefunden';
}
if (!empty($results['business_intel']['bewertungen'])) {
    $dossier['findings'][] = 'Bewertungen: ' . count($results['business_intel']['bewertungen']) . ' Online-Bewertungen gefunden';
}
// Correlation score
if (!empty($results['correlation'])) {
    $cor = $results['correlation'];
    $dossier['findings'][] = 'Korrelation: ' . $cor['total_identifiers'] . ' Identifier, Score ' . $cor['score'] . ' (' . $cor['confidence'] . ')';
    foreach ($cor['connections'] as $conn) {
        if ($conn['type'] === 'insolvency_warning') $dossier['risk_factors'][] = $conn['detail'];
        elseif ($conn['type'] === 'double_exposure') $dossier['risk_factors'][] = $conn['detail'];
        else $dossier['findings'][] = $conn['detail'];
    }
}
if (count($dossier['risk_factors']) >= 3) $dossier['risk_level'] = 'HIGH';

// ============================================================
// VERIFICATION ENGINE — Rate each finding's confidence
// ============================================================
$verification = ['overall_confidence' => 0, 'verified_count' => 0, 'total_count' => 0, 'modules' => []];

// Rate each module based on match type
$moduleRatings = [
    'db_profile' => ['confidence' => !empty($dbProfile['found']) ? 99 : 0, 'match' => 'EXACT', 'reason' => 'Exakter Match in eigener Datenbank (Email/Name/Telefon)'],
    'breach_check' => ['confidence' => !empty($results['breach_check']) ? ($results['breach_check']['breached'] ? 99 : 95) : 0, 'match' => 'EXACT', 'reason' => 'HIBP k-Anonymity — kryptografisch exakter Email-Hash-Match'],
    'email_security' => ['confidence' => !empty($results['email_security']) ? 99 : 0, 'match' => 'EXACT', 'reason' => 'DNS-Abfrage der exakten Email-Domain'],
    'hunter' => ['confidence' => !empty($results['hunter']) ? (int)($results['hunter']['score'] ?? 0) : 0, 'match' => 'EXACT', 'reason' => 'Hunter.io SMTP-Verifikation der exakten Email'],
    'bgp_asn' => ['confidence' => !empty($results['bgp_asn']['asn']) ? 99 : 0, 'match' => 'EXACT', 'reason' => 'Team Cymru ASN-Lookup der exakten IP'],
    'shodan' => ['confidence' => !empty($results['shodan']) ? 99 : 0, 'match' => 'EXACT', 'reason' => 'Shodan API — exakter IP-Scan'],
    'virustotal' => ['confidence' => !empty($results['virustotal']) ? 95 : 0, 'match' => 'EXACT', 'reason' => 'VirusTotal — exakte Domain-Analyse'],
    'whois' => ['confidence' => !empty($results['whois']) ? 95 : 0, 'match' => 'EXACT', 'reason' => 'WHOIS — offizielle Domain-Registrierung'],
    'subdomains' => ['confidence' => !empty($results['subdomains']) ? 95 : 0, 'match' => 'EXACT', 'reason' => 'crt.sh — SSL-Zertifikat-Transparenz'],
    'dns_services' => ['confidence' => !empty($results['dns_services']) ? 99 : 0, 'match' => 'EXACT', 'reason' => 'DNS SRV — offizielle Service-Records'],
    'geocoding' => ['confidence' => !empty($results['geocoding']) ? 90 : 0, 'match' => 'FUZZY', 'reason' => 'Nominatim Geocoding — Adress-Approximation'],
    'phone_osint' => ['confidence' => !empty($results['phone_osint']) ? 95 : 0, 'match' => 'EXACT', 'reason' => 'Carrier-Erkennung via Vorwahl (deterministisch)'],
    'gleif_lei' => ['confidence' => !empty($results['gleif_lei']) ? 90 : 0, 'match' => 'FUZZY', 'reason' => 'GLEIF — Name-basierte Suche (Fuzzy-Match)'],
    'opencorporates' => ['confidence' => !empty($results['opencorporates']) ? 85 : 0, 'match' => 'FUZZY', 'reason' => 'OpenCorporates — Name-basierte Suche'],
    'handelsregister' => ['confidence' => !empty($results['handelsregister']) ? 85 : 0, 'match' => 'FUZZY', 'reason' => 'Offenes Register — Name-basierte Suche'],
    'username_search' => ['confidence' => !empty($results['username_search']) ? 70 : 0, 'match' => 'HEURISTIC', 'reason' => 'Username-Variationen — heuristisch, nicht verifiziert'],
    'web_intel' => ['confidence' => !empty($results['web_intel']) ? 60 : 0, 'match' => 'HEURISTIC', 'reason' => 'Web-Suche — kann andere Person mit gleichem Namen sein'],
];

// Deep Intel module ratings
if (!empty($results['deep_intel'])) {
    $di = $results['deep_intel'];
    if (!empty($di['gravatar'])) $moduleRatings['gravatar'] = ['confidence' => 99, 'match' => 'EXACT', 'reason' => 'Gravatar — exakter Email-MD5-Hash-Match'];
    if (!empty($di['keybase'])) $moduleRatings['keybase'] = ['confidence' => 95, 'match' => 'EXACT', 'reason' => 'Keybase — kryptografisch verifizierte Identitäten'];
    if (!empty($di['paste_leaks'])) $moduleRatings['paste_leaks'] = ['confidence' => 90, 'match' => 'EXACT', 'reason' => 'Paste-Sites — exakte Email-Suche'];
    if (!empty($di['telegram_profiles'])) $moduleRatings['telegram'] = ['confidence' => 70, 'match' => 'HEURISTIC', 'reason' => 'Telegram — Username-Heuristik'];
    if (!empty($di['impressum_mentions'])) $moduleRatings['impressum'] = ['confidence' => 75, 'match' => 'FUZZY', 'reason' => 'Impressum — Name-Match, kann Namensgleichheit sein'];
    if (!empty($di['insolvency'])) $moduleRatings['insolvency'] = ['confidence' => 80, 'match' => 'FUZZY', 'reason' => 'Insolvenz — Name-Match, Verifizierung mit Adresse/Gericht empfohlen'];
}

// Boost confidence when multiple hard identifiers match
$hardIdCount = count($hardIds);
$verifiedModules = 0; $totalModules = 0;
foreach ($moduleRatings as $mod => $rating) {
    if ($rating['confidence'] > 0) {
        $totalModules++;
        // Boost: if DOB provided and DB profile has same customer → 99%
        if ($mod === 'db_profile' && $dob && !empty($dbProfile['found'])) $rating['confidence'] = 99;
        // EXACT matches on email/phone are always 95%+
        if ($rating['match'] === 'EXACT' && $rating['confidence'] >= 90) $verifiedModules++;
        $verification['modules'][$mod] = $rating;
    }
}

$verification['verified_count'] = $verifiedModules;
$verification['total_count'] = $totalModules;
$verification['overall_confidence'] = $totalModules > 0 ? round(($verifiedModules / $totalModules) * 100) : 0;
$verification['hard_identifiers_provided'] = $hardIdCount;
$verification['match_types'] = [
    'EXACT' => 'Kryptografisch/deterministisch verifiziert (99%+ Sicherheit)',
    'FUZZY' => 'Name/Adress-basiert, kann Namensgleichheit sein (80-90%)',
    'HEURISTIC' => 'Algorithmisch generiert, manuelle Prüfung empfohlen (60-75%)',
];

// Add verification advice
$verification['advice'] = [];
if (empty($email)) $verification['advice'][] = 'Email fehlt — stärkster Identifier. Mit Email steigt Genauigkeit auf 95%+';
if (empty($phone)) $verification['advice'][] = 'Telefonnummer fehlt — wichtig für Telegram/WhatsApp/Carrier-Verifikation';
if (empty($dob)) $verification['advice'][] = 'Geburtsdatum fehlt — würde Namens-Matches verifizieren';
if (empty($address)) $verification['advice'][] = 'Adresse fehlt — nötig für Geocoding und Handelsregister-Verifizierung';
if ($hardIdCount >= 3) $verification['advice'][] = '3+ harte Identifier — hohe Verifikationssicherheit';

$dossier['verification'] = $verification;

$results['dossier'] = $dossier;
$results['hard_identifiers'] = ['provided' => array_keys($hardIds), 'count' => $hardIdCount];

// ============================================================
// SELF-LEARNING SCORE — Analyze past scans to improve accuracy
// ============================================================
$learning = ['enabled' => true, 'past_scans' => 0, 'source_effectiveness' => [], 'recommendations' => []];
try {
    // Count how many scans we've done and which sources yielded results
    $pastScans = all("SELECT deep_scan_data FROM osint_scans WHERE deep_scan_data IS NOT NULL AND deep_scan_data != '{}' ORDER BY created_at DESC LIMIT 50");
    $learning['past_scans'] = count($pastScans);

    if (count($pastScans) >= 3) {
        // Aggregate: which modules found data most often?
        $moduleHits = [];
        $moduleTotal = [];
        foreach ($pastScans as $ps) {
            $pd = json_decode($ps['deep_scan_data'], true);
            if (!$pd) continue;
            $checkModules = ['breach_check','email_security','username_search','phone_osint','geocoding','gleif_lei','opencorporates','handelsregister','shodan','virustotal','hunter','web_intel','deep_intel','bgp_asn','dns_services','permutation_search','correlation'];
            foreach ($checkModules as $mod) {
                if (!isset($moduleTotal[$mod])) $moduleTotal[$mod] = 0;
                if (!isset($moduleHits[$mod])) $moduleHits[$mod] = 0;
                $moduleTotal[$mod]++;
                if (!empty($pd[$mod])) $moduleHits[$mod]++;
            }
        }
        // Calculate effectiveness rate per module
        foreach ($moduleTotal as $mod => $total) {
            if ($total > 0) {
                $rate = round(($moduleHits[$mod] / $total) * 100);
                $learning['source_effectiveness'][$mod] = ['hit_rate' => $rate, 'hits' => $moduleHits[$mod], 'total' => $total];
            }
        }
        // Sort by effectiveness
        arsort($learning['source_effectiveness']);

        // Generate recommendations based on learning
        foreach ($learning['source_effectiveness'] as $mod => $stats) {
            if ($stats['hit_rate'] >= 80) {
                $learning['recommendations'][] = "'{$mod}' liefert in {$stats['hit_rate']}% der Scans Ergebnisse — zuverlässige Quelle";
            } elseif ($stats['hit_rate'] <= 20 && $stats['total'] >= 5) {
                $learning['recommendations'][] = "'{$mod}' liefert selten Ergebnisse ({$stats['hit_rate']}%) — niedrige Priorität";
            }
        }

        // Learn from current scan: which person type is this?
        $personType = 'unknown';
        if (!empty($results['handelsregister']) || !empty($results['opencorporates'])) $personType = 'business';
        elseif (!empty($results['deep_intel']['impressum_mentions'])) $personType = 'self_employed';
        elseif (!empty($dbProfile['found'])) $personType = 'customer';
        else $personType = 'private';
        $learning['detected_person_type'] = $personType;
        $learning['type_advice'] = match($personType) {
            'business' => 'Firma erkannt — Handelsregister, Bundesanzeiger, NorthData und Creditreform sind die besten Quellen',
            'self_employed' => 'Selbständig — Impressum, Kleinanzeigen, Bewertungsportale und Gewerberegister prüfen',
            'customer' => 'Bestandskunde — interne Daten + externe Verifikation kombinieren',
            default => 'Privatperson — Social Media, Telefonbuch und Adressverifikation am relevantesten',
        };
    }
} catch (Exception $e) { /* learning is optional */ }
$results['learning'] = $learning;

// Save scan to DB (reconnect if needed)
try {
    global $db;
    $db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    q("INSERT INTO osint_scans (customer_id_fk, scan_name, scan_email, scan_phone, scan_address, scan_data, deep_scan_data, scanned_by)
       VALUES (?,?,?,?,?,?,?,?)",
      [$dbProfile['customer']['id'] ?? null, $name, $email, $phone, $address,
       json_encode(['dob'=>$dob,'id_number'=>$idNumber,'passport'=>$passNumber,'serial'=>$serialNr,'tax_id'=>$taxId]),
       json_encode($results), $_SESSION['uid'] ?? null]);
} catch (Exception $e) {}

// Add timing + cache info
$results['_meta'] = [
    'scan_time_seconds' => round(microtime(true) - $scanStart, 2),
    'cache' => ['hit' => false, 'key' => $cacheKey],
    'modules_run' => count(array_filter(array_keys($results), fn($k) => !str_starts_with($k, '_'))),
];

ob_end_clean();
echo json_encode(['success' => true, 'data' => $results]);
