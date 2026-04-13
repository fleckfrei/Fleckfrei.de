<?php
/**
 * AI Airbnb Listing Analyzer
 * POST {url} → fetch public listing, extract meta/reviews, run Groq → Reinigungsplan-Vorschlag
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/llm-helpers.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$url = trim($input['url'] ?? $_POST['url'] ?? '');
$pastedText = trim($input['text'] ?? $_POST['text'] ?? '');

if (!$url && !$pastedText) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'URL oder Listing-Text erforderlich']);
    exit;
}
if ($url && !preg_match('~^https?://(www\.|de\.)?airbnb\.(com|de)/(rooms|h)/~i', $url)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültiger Airbnb-Link']);
    exit;
}

$cacheDir = sys_get_temp_dir() . '/airbnb_analyses';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
$cacheFile = $cacheDir . '/' . md5($url . '|' . $pastedText) . '.json';
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
    $cached = json_decode(@file_get_contents($cacheFile), true);
    if (is_array($cached)) {
        $cached['_cache_hit'] = true;
        echo json_encode($cached);
        exit;
    }
}

$meta = [];
$reviews = [];
$hints = [];
$scrapeMode = 'none';

if ($pastedText) {
    // Mode A: User pasted listing text directly — bypass scraping
    $meta['title'] = mb_substr(explode("\n", trim($pastedText))[0], 0, 200);
    $meta['description'] = mb_substr($pastedText, 0, 4000);
    $scrapeMode = 'text';
} elseif ($url) {
    // Mode B: Try to scrape. Airbnb blocks bots heavily — often only OG tags survive.
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept-Language: de-DE,de;q=0.9,en;q=0.8'],
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html && $httpCode < 400) {
        if (preg_match('~<meta property="og:title" content="([^"]+)"~i', $html, $m)) $meta['title'] = html_entity_decode($m[1], ENT_QUOTES);
        if (preg_match('~<meta property="og:description" content="([^"]+)"~i', $html, $m)) $meta['description'] = html_entity_decode($m[1], ENT_QUOTES);
        if (preg_match('~<meta property="og:image" content="([^"]+)"~i', $html, $m)) $meta['image'] = $m[1];
        if (preg_match('~<title>([^<]+)</title>~i', $html, $m) && empty($meta['title'])) $meta['title'] = html_entity_decode($m[1], ENT_QUOTES);

        if (preg_match_all('~"comments":"([^"]{40,400})"~', $html, $rm)) {
            foreach (array_slice($rm[1], 0, 8) as $r) $reviews[] = mb_substr(stripcslashes($r), 0, 300);
        }
        if (preg_match_all('~"([^"]*(?:Schlafzimmer|Badezimmer|Gäste|beds?|baths?|guests?)[^"]{0,40})"~i', $html, $hm)) {
            $hints = array_unique(array_slice($hm[1], 0, 10));
        }
        $scrapeMode = (empty($meta['title']) && empty($meta['description'])) ? 'blocked' : 'scraped';
    } else {
        $scrapeMode = 'fetch_failed';
    }

    if ($scrapeMode === 'blocked' || $scrapeMode === 'fetch_failed' || $scrapeMode === 'none') {
        // Fallback: ask LLM to extrapolate from URL pattern only (coarse estimate)
        $meta['title'] = $meta['title'] ?: basename(parse_url($url, PHP_URL_PATH));
        $meta['description'] = $meta['description'] ?: "Airbnb-Listing URL: $url (Seite konnte nicht direkt ausgelesen werden — bitte Beschreibung im Text-Modus einfügen für genauere Analyse)";
    }
}

$prompt = "Du bist Reinigungs-Experte für Airbnb-Turnover in Berlin.\n"
    . "Analysiere dieses Inserat und erstelle einen kompakten Reinigungsplan.\n\n"
    . "TITEL: " . ($meta['title'] ?? '-') . "\n"
    . "BESCHREIBUNG: " . ($meta['description'] ?? '-') . "\n"
    . "HINWEISE: " . (count($hints) ? implode(' | ', $hints) : '-') . "\n"
    . "REVIEW-AUSZÜGE: " . (count($reviews) ? implode(' || ', $reviews) : '-') . "\n\n"
    . "Antworte NUR als valides JSON ohne Kommentare mit folgenden Feldern:\n"
    . "{\n"
    . "  \"apartment_type\": \"studio|1-zimmer|2-zimmer|3-zimmer|villa\",\n"
    . "  \"estimated_sqm\": <int>,\n"
    . "  \"beds\": <int>,\n"
    . "  \"baths\": <int>,\n"
    . "  \"guests\": <int>,\n"
    . "  \"hot_spots\": [\"küche\",\"bad\",\"balkon\",...],\n"
    . "  \"special_tasks\": [\"Wäsche waschen\",\"Handtücher wechseln\",\"Pflegemittel auffüllen\"],\n"
    . "  \"review_risks\": [\"Staub auf Regalen häufig erwähnt\",\"Schimmel-Hinweis im Bad\"],\n"
    . "  \"recommended_hours\": <float>,\n"
    . "  \"recommended_addons\": [\"waesche\",\"pflegemittel\",\"fotodoku\"],\n"
    . "  \"summary_de\": \"<kurz in 2-3 Sätzen>\"\n"
    . "}";

$ai = groq_chat($prompt, 900);
if (!$ai || !empty($ai['error'])) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => $ai['error'] ?? 'LLM-Fehler', 'meta' => $meta]);
    exit;
}

$raw = $ai['content'] ?? '';
// Strip markdown fences if any
$clean = preg_replace('~^```(?:json)?\s*|\s*```$~m', '', trim($raw));
$plan = json_decode($clean, true);
if (!is_array($plan)) {
    // Fallback: store raw + return
    $plan = ['raw' => $raw, 'parse_error' => true];
}

$out = [
    'success' => true,
    'url' => $url,
    'scrape_mode' => $scrapeMode,
    'meta' => $meta,
    'hints' => $hints,
    'review_count' => count($reviews),
    'plan' => $plan,
    'model' => $ai['model'] ?? 'groq',
    'analyzed_at' => date('c'),
];

@file_put_contents($cacheFile, json_encode($out));

// Optional: store in DB for history
try {
    q("CREATE TABLE IF NOT EXISTS airbnb_analyses (
        aa_id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id_fk INT NULL,
        url VARCHAR(500) NOT NULL,
        url_hash CHAR(32) NOT NULL,
        title VARCHAR(500) NULL,
        plan_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_hash (url_hash),
        INDEX idx_customer (customer_id_fk)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cid = me()['id'] ?? null;
    q("INSERT INTO airbnb_analyses (customer_id_fk, url, url_hash, title, plan_json) VALUES (?,?,?,?,?)",
      [$cid, $url, md5($url), $meta['title'] ?? null, json_encode($plan)]);
} catch (Exception $e) { /* best-effort */ }

echo json_encode($out);
