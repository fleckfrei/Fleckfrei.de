<?php
/**
 * Fleckfrei OSINT API — Public SaaS Endpoint
 *
 * Authentication: Bearer token or X-API-Key header
 * Rate limiting: per-key, configurable
 *
 * Endpoints:
 *   POST /api/osint-api.php?action=scan          Full deep scan
 *   POST /api/osint-api.php?action=quick          Quick scan (email/phone only)
 *   POST /api/osint-api.php?action=search         SearXNG search
 *   POST /api/osint-api.php?action=verify-email   Email verification only
 *   POST /api/osint-api.php?action=verify-phone   Phone verification only
 *   GET  /api/osint-api.php?action=usage          API usage stats
 *   GET  /api/osint-api.php?action=health         Health check
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../includes/config.php';

// === AUTH ===
$authKey = '';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $authKey = substr($authHeader, 7);
} else {
    $authKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
}

if (!$authKey) {
    http_response_code(401);
    echo json_encode(['error' => 'API key required', 'docs' => 'Use Authorization: Bearer <key> or X-API-Key header']);
    exit;
}

// === API KEY VALIDATION ===
// Keys stored in local DB: osint_api_keys table
global $dbLocal;
$keyData = null;
try {
    // Create table if not exists
    $dbLocal->exec("CREATE TABLE IF NOT EXISTS osint_api_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        api_key VARCHAR(128) UNIQUE NOT NULL,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255),
        tier VARCHAR(32) DEFAULT 'free',
        rate_limit INT DEFAULT 10,
        daily_limit INT DEFAULT 50,
        monthly_limit INT DEFAULT 500,
        active TINYINT DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_used DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $dbLocal->exec("CREATE TABLE IF NOT EXISTS osint_api_usage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        api_key_id INT,
        action VARCHAR(100),
        target VARCHAR(255),
        ip VARCHAR(64),
        response_time_ms INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_key (api_key_id), INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Check key
    $stmt = $dbLocal->prepare("SELECT * FROM osint_api_keys WHERE api_key=? AND active=1");
    $stmt->execute([$authKey]);
    $keyData = $stmt->fetch(PDO::FETCH_ASSOC);

    // Also allow internal API key
    if (!$keyData && $authKey === API_KEY) {
        $keyData = ['id' => 0, 'name' => 'Internal', 'tier' => 'unlimited', 'rate_limit' => 999, 'daily_limit' => 99999, 'monthly_limit' => 999999];
    }
} catch (Exception $e) {
    // If DB fails, still allow internal key
    if ($authKey === API_KEY) {
        $keyData = ['id' => 0, 'name' => 'Internal', 'tier' => 'unlimited', 'rate_limit' => 999, 'daily_limit' => 99999, 'monthly_limit' => 999999];
    }
}

if (!$keyData) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

// === RATE LIMITING ===
$keyId = $keyData['id'] ?? 0;
if ($keyId > 0) {
    try {
        // Check daily usage
        $dailyUsage = $dbLocal->prepare("SELECT COUNT(*) FROM osint_api_usage WHERE api_key_id=? AND created_at > datetime('now', '-1 day')");
        $dailyUsage->execute([$keyId]);
        $dailyCount = $dailyUsage->fetchColumn();

        if ($dailyCount >= ($keyData['daily_limit'] ?? 50)) {
            http_response_code(429);
            echo json_encode(['error' => 'Daily limit reached', 'limit' => $keyData['daily_limit'], 'used' => $dailyCount, 'reset' => 'in 24h']);
            exit;
        }

        // Update last used
        $dbLocal->prepare("UPDATE osint_api_keys SET last_used=CURRENT_TIMESTAMP WHERE id=?")->execute([$keyId]);
    } catch (Exception $e) {}
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$startTime = microtime(true);

$vpsApi = 'http://89.116.22.185:8900';
$vpsHeaders = ['X-API-Key: ' . API_KEY, 'Content-Type: application/json'];

// === ROUTING ===
$result = match($action) {
    'health' => [
        'status' => 'ok',
        'version' => '1.0',
        'tools' => ['deep-scan', 'quick-scan', 'search', 'verify-email', 'verify-phone'],
        'vps_tools' => ['holehe', 'maigret', 'phoneinfoga', 'socialscan', 'whois', 'searxng', 'ghunt', 'intelx', 'perplexity'],
    ],

    'usage' => (function() use ($dbLocal, $keyId, $keyData) {
        if ($keyId === 0) return ['tier' => 'unlimited', 'message' => 'Internal key'];
        try {
            $today = $dbLocal->prepare("SELECT COUNT(*) FROM osint_api_usage WHERE api_key_id=? AND created_at > datetime('now', '-1 day')");
            $today->execute([$keyId]);
            $month = $dbLocal->prepare("SELECT COUNT(*) FROM osint_api_usage WHERE api_key_id=? AND created_at > datetime('now', '-30 day')");
            $month->execute([$keyId]);
            return [
                'tier' => $keyData['tier'],
                'daily' => ['used' => $today->fetchColumn(), 'limit' => $keyData['daily_limit']],
                'monthly' => ['used' => $month->fetchColumn(), 'limit' => $keyData['monthly_limit']],
            ];
        } catch (Exception $e) { return ['error' => 'Usage data unavailable']; }
    })(),

    'search' => (function() use ($body, $vpsApi, $vpsHeaders) {
        $q = trim($body['query'] ?? '');
        if (!$q) return ['error' => 'query required'];
        $ch = curl_init($vpsApi . '/searxng');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>15, CURLOPT_POST=>1,
            CURLOPT_POSTFIELDS=>json_encode(['query'=>$q, 'categories'=>$body['categories']??'general', 'limit'=>$body['limit']??10]),
            CURLOPT_HTTPHEADER=>$vpsHeaders]);
        $raw = curl_exec($ch); curl_close($ch);
        return $raw ? json_decode($raw, true) : ['error' => 'Search engine unavailable'];
    })(),

    'verify-email' => (function() use ($body) {
        $email = trim($body['email'] ?? '');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['error' => 'Valid email required'];
        $domain = substr($email, strpos($email, '@') + 1);
        $mx = @dns_get_record($domain, DNS_MX);
        $free = in_array($domain, ['gmail.com','yahoo.com','hotmail.com','outlook.com','gmx.de','web.de','t-online.de','protonmail.com','icloud.com']);
        $disposable = in_array($domain, ['guerrillamail.com','tempmail.com','mailinator.com','10minutemail.com','throwaway.email','yopmail.com','sharklasers.com']);
        return [
            'email' => $email,
            'domain' => $domain,
            'mx_records' => $mx ? count($mx) : 0,
            'mx_host' => !empty($mx) ? $mx[0]['target'] : null,
            'is_free' => $free,
            'is_disposable' => $disposable,
            'is_business' => !$free && !$disposable,
            'gravatar' => 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($email))) . '?d=404',
        ];
    })(),

    'verify-phone' => (function() use ($body) {
        $phone = trim($body['phone'] ?? '');
        if (!$phone) return ['error' => 'phone required'];
        $ph = preg_replace('/[^+0-9]/', '', $phone);
        if (!str_starts_with($ph, '+') && str_starts_with($ph, '00')) $ph = '+' . substr($ph, 2);
        elseif (!str_starts_with($ph, '+') && str_starts_with($ph, '0')) $ph = '+49' . substr($ph, 1);
        elseif (!str_starts_with($ph, '+') && strlen($ph) > 10) $ph = '+' . $ph;
        $countries = ['+49'=>'DE','+40'=>'RO','+373'=>'MD','+41'=>'CH','+43'=>'AT','+48'=>'PL','+44'=>'GB','+1'=>'US','+33'=>'FR','+39'=>'IT','+34'=>'ES','+90'=>'TR'];
        $cc = ''; $country = 'Unknown';
        foreach ($countries as $pfx => $c) { if (str_starts_with($ph, $pfx)) { $cc = $pfx; $country = $c; break; } }
        return ['phone' => $ph, 'country' => $country, 'country_code' => $cc, 'digits' => strlen(preg_replace('/[^0-9]/', '', $ph))];
    })(),

    'quick' => (function() use ($body, $vpsApi, $vpsHeaders) {
        $email = trim($body['email'] ?? '');
        $phone = trim($body['phone'] ?? '');
        $results = [];
        if ($email) {
            // Holehe check
            $ch = curl_init($vpsApi . '/holehe');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>20, CURLOPT_POST=>1,
                CURLOPT_POSTFIELDS=>json_encode(['email'=>$email]), CURLOPT_HTTPHEADER=>$vpsHeaders]);
            $raw = curl_exec($ch); curl_close($ch);
            if ($raw) $results['holehe'] = json_decode($raw, true);
        }
        if ($phone) {
            $ch = curl_init($vpsApi . '/phoneinfoga');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>20, CURLOPT_POST=>1,
                CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone]), CURLOPT_HTTPHEADER=>$vpsHeaders]);
            $raw = curl_exec($ch); curl_close($ch);
            if ($raw) $results['phoneinfoga'] = json_decode($raw, true);
        }
        return $results ?: ['error' => 'email or phone required'];
    })(),

    'scan' => (function() use ($body) {
        // Proxy to full deep scan
        $ch = curl_init('https://app.fleckfrei.de/api/osint-deep.php');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>30, CURLOPT_POST=>1,
            CURLOPT_POSTFIELDS=>json_encode($body),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'X-API-Key: ' . API_KEY]]);
        $raw = curl_exec($ch); curl_close($ch);
        return $raw ? json_decode($raw, true) : ['error' => 'Scan engine unavailable'];
    })(),

    default => ['error' => 'Unknown action', 'available' => ['scan', 'quick', 'search', 'verify-email', 'verify-phone', 'usage', 'health']],
};

// === LOG USAGE ===
$elapsed = round((microtime(true) - $startTime) * 1000);
if ($keyId > 0 && $action !== 'health') {
    try {
        $target = $body['email'] ?? $body['phone'] ?? $body['name'] ?? $body['query'] ?? '';
        $dbLocal->prepare("INSERT INTO osint_api_usage (api_key_id, action, target, ip, response_time_ms) VALUES (?,?,?,?,?)")
            ->execute([$keyId, $action, substr($target, 0, 100), $_SERVER['REMOTE_ADDR'] ?? '', $elapsed]);
    } catch (Exception $e) {}
}

echo json_encode([
    'success' => !isset($result['error']),
    'data' => $result,
    '_meta' => ['action' => $action, 'response_ms' => $elapsed, 'tier' => $keyData['tier'] ?? 'unknown'],
]);
