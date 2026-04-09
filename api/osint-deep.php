<?php
/**
 * Fleckfrei OSI Deep Scan API
 * Intelligence gathering with DB cross-reference + external APIs.
 */
ini_set('max_execution_time', 45);
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

$results = [];
$domain = '';
$scanStart = microtime(true);

// ============================================================
// CACHE CHECK — Return cached results if < 24h old
// ============================================================
$cacheKey = md5(json_encode([$email, $name, $phone, $address]));
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
if (count($dossier['risk_factors']) >= 3) $dossier['risk_level'] = 'HIGH';

$results['dossier'] = $dossier;

// Save scan to DB (reconnect if needed)
try {
    global $db;
    $db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    q("INSERT INTO osint_scans (customer_id_fk, scan_name, scan_email, scan_phone, scan_address, scan_data, deep_scan_data, scanned_by)
       VALUES (?,?,?,?,?,?,?,?)",
      [$dbProfile['customer']['id'] ?? null, $name, $email, $phone, $address, '{}', json_encode($results), $_SESSION['uid'] ?? null]);
} catch (Exception $e) {}

// Add timing + cache info
$results['_meta'] = [
    'scan_time_seconds' => round(microtime(true) - $scanStart, 2),
    'cache' => ['hit' => false, 'key' => $cacheKey],
    'modules_run' => count(array_filter(array_keys($results), fn($k) => !str_starts_with($k, '_'))),
];

ob_end_clean();
echo json_encode(['success' => true, 'data' => $results]);
