<?php
/**
 * PUBLIC Airbnb Check Endpoint (no auth) — wrapper für airbnb-analyze mit IP-Rate-Limit
 * 5 Analysen pro IP pro Stunde. Writes to airbnb_analyses with customer_id_fk=NULL.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/llm-helpers.php';
header('Content-Type: application/json; charset=utf-8');

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$cacheDir = sys_get_temp_dir() . '/airbnb_public_ratelimit';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
$rlFile = $cacheDir . '/' . md5($ip) . '.json';
$now = time();
$entries = file_exists($rlFile) ? (json_decode(@file_get_contents($rlFile), true) ?: []) : [];
$entries = array_values(array_filter($entries, fn($t) => $t > $now - 3600));
if (count($entries) >= 5) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Zu viele Anfragen — max. 5/Stunde pro IP. Bitte später erneut probieren.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$url = trim($input['url'] ?? '');
$pastedText = trim($input['text'] ?? '');

if (!$url && !$pastedText) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'URL oder Text erforderlich']);
    exit;
}
if ($url && !preg_match('~^https?://(www\.|de\.)?airbnb\.(com|de)/(rooms|h)/~i', $url)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültiger Airbnb-Link']);
    exit;
}

$meta = []; $reviews = []; $hints = []; $scrapeMode = 'none';
if ($pastedText) {
    $meta['title'] = mb_substr(explode("\n", trim($pastedText))[0], 0, 200);
    $meta['description'] = mb_substr($pastedText, 0, 4000);
    $scrapeMode = 'text';
} else {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>20,
      CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/122.0']);
    $html = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($html && $code < 400) {
        if (preg_match('~<meta property="og:title" content="([^"]+)"~i', $html, $m)) $meta['title'] = html_entity_decode($m[1]);
        if (preg_match('~<meta property="og:description" content="([^"]+)"~i', $html, $m)) $meta['description'] = html_entity_decode($m[1]);
        preg_match_all('~"comments":"([^"]{40,400})"~', $html, $rm);
        foreach (array_slice($rm[1] ?? [], 0, 8) as $r) $reviews[] = mb_substr(stripcslashes($r), 0, 300);
        $scrapeMode = (empty($meta['title']) && empty($meta['description'])) ? 'blocked' : 'scraped';
    }
    if ($scrapeMode !== 'scraped') {
        $meta['title'] = $meta['title'] ?: basename(parse_url($url, PHP_URL_PATH));
        $meta['description'] = $meta['description'] ?: "URL: $url (bitte Text-Modus für bessere Analyse)";
    }
}

$prompt = "Du bist Reinigungs-Experte für Airbnb-Turnover in Berlin.\n"
    . "Analysiere dieses Inserat und erstelle einen kompakten Reinigungsplan.\n\n"
    . "TITEL: " . ($meta['title'] ?? '-') . "\n"
    . "BESCHREIBUNG: " . ($meta['description'] ?? '-') . "\n"
    . "REVIEWS: " . (count($reviews) ? implode(' || ', $reviews) : '-') . "\n\n"
    . "Antworte NUR als JSON:\n"
    . "{\n  \"apartment_type\":\"studio|1-zimmer|2-zimmer|3-zimmer|villa\",\n"
    . "  \"estimated_sqm\":<int>, \"beds\":<int>, \"baths\":<int>, \"guests\":<int>,\n"
    . "  \"hot_spots\":[...], \"special_tasks\":[...], \"review_risks\":[...],\n"
    . "  \"recommended_hours\":<float>, \"recommended_addons\":[...], \"summary_de\":\"...\"\n}";

$ai = groq_chat($prompt, 900);
if (!$ai || !empty($ai['error'])) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'AI nicht erreichbar']);
    exit;
}
$raw = $ai['content'] ?? '';
$clean = preg_replace('~^```(?:json)?\s*|\s*```$~m', '', trim($raw));
$plan = json_decode($clean, true) ?: ['raw' => $raw, 'parse_error' => true];

// Record rate-limit usage
$entries[] = $now;
@file_put_contents($rlFile, json_encode($entries));

// Store as anonymous analysis
try {
    q("CREATE TABLE IF NOT EXISTS airbnb_analyses (
        aa_id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id_fk INT NULL,
        url VARCHAR(500), url_hash CHAR(32),
        title VARCHAR(500), plan_json LONGTEXT,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_hash (url_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Add column if it doesn't exist
    try { q("ALTER TABLE airbnb_analyses ADD COLUMN ip_address VARCHAR(45) NULL"); } catch (Exception $e) {}
    q("INSERT INTO airbnb_analyses (customer_id_fk, url, url_hash, title, plan_json, ip_address) VALUES (NULL, ?, ?, ?, ?, ?)",
      [$url ?: '(text)', md5($url . $pastedText), $meta['title'] ?? null, json_encode($plan), $ip]);
} catch (Exception $e) {}

echo json_encode([
    'success' => true,
    'url' => $url,
    'scrape_mode' => $scrapeMode,
    'meta' => $meta,
    'plan' => $plan,
    'analyzed_at' => date('c'),
]);
