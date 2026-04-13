<?php
/**
 * PUBLIC Airbnb Deep-Check Endpoint (v2) — Business Dossier
 * Liefert: Listing-Audit, Market-Benchmark, Host-Profil, Review-Forensics,
 * Revenue-Impact, SWOT, Cleaning-Plan, Business-Case ROI, Action-Plan.
 * Rate-Limit 5/h per IP.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/llm-helpers.php';
header('Content-Type: application/json; charset=utf-8');

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$cacheRoot = sys_get_temp_dir() . '/airbnb_check_v2';
if (!is_dir($cacheRoot)) @mkdir($cacheRoot, 0700, true);

// Rate limit
$rlFile = $cacheRoot . '/rl_' . md5($ip) . '.json';
$now = time();
$entries = file_exists($rlFile) ? (json_decode(@file_get_contents($rlFile), true) ?: []) : [];
$entries = array_values(array_filter($entries, fn($t) => $t > $now - 3600));
if (count($entries) >= 5) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Zu viele Anfragen — max. 5/Stunde pro IP.']);
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

// ============================================================
// 1) BERLIN MARKET BENCHMARK (cached 6h)
// ============================================================
function fetchMarketData(string $location = 'Berlin'): array {
    $cache = sys_get_temp_dir() . '/airbnb_check_v2/market_' . md5($location) . '.json';
    if (file_exists($cache) && (time() - filemtime($cache)) < 21600) {
        return json_decode(@file_get_contents($cache), true) ?: [];
    }
    $url = 'https://www.airbnb.com/s/' . urlencode($location) . '/homes?adults=2';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/122.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.9,de;q=0.8'],
    ]);
    $html = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (!$html || $code >= 400) return [];

    $listings = [];
    preg_match_all('~"id":"(\d+)"[^{]*?"localizedStringWithTranslationPreference":"([^"]+)"[^{]*?"primaryLine":"([^"]*)"[^{]*?"avgRatingA11yLabel":"([^"]+)"[^{]*?"accessibilityLabel":"([^"]+)"~', $html, $m, PREG_SET_ORDER);
    foreach (array_slice($m, 0, 18) as $row) {
        $rating = null; $reviews = null;
        if (preg_match('~([\d.,]+)\s+out of 5\s*average rating,\s*([\d.,]+)\s+reviews~', $row[4], $rm)) {
            $rating = (float) str_replace(',', '.', $rm[1]);
            $reviews = (int) str_replace(',', '', $rm[2]);
        }
        $pricePerNight = null;
        if (preg_match('~\$([\d,]+)\s+for (\d+) nights~', $row[5], $pm)) {
            $total = (float) str_replace(',', '', $pm[1]);
            $nights = (int) $pm[2];
            if ($nights > 0) $pricePerNight = round($total / $nights, 0);
        }
        $listings[] = [
            'id' => $row[1], 'name' => $row[2], 'type' => $row[3],
            'rating' => $rating, 'reviews' => $reviews, 'price_per_night_usd' => $pricePerNight,
        ];
    }
    $ratings = array_filter(array_column($listings, 'rating'));
    $prices = array_filter(array_column($listings, 'price_per_night_usd'));
    $reviews = array_filter(array_column($listings, 'reviews'));
    $data = [
        'location' => $location,
        'sample_size' => count($listings),
        'avg_rating' => $ratings ? round(array_sum($ratings)/count($ratings), 2) : null,
        'avg_price_usd' => $prices ? round(array_sum($prices)/count($prices), 0) : null,
        'median_reviews' => $reviews ? (int)(array_sum($reviews)/count($reviews)) : null,
        'superhost_pct' => null, // Not parseable reliably from this query
        'listings_sample' => array_slice($listings, 0, 8),
        'cached_at' => date('c'),
    ];
    @file_put_contents($cache, json_encode($data));
    return $data;
}

$market = fetchMarketData('Berlin');

// ============================================================
// 2) LISTING SCRAPE (URL-mode)
// ============================================================
$meta = []; $reviews = []; $hints = []; $scrapeMode = 'none'; $listingId = null;
if ($url && preg_match('~/rooms/(\d+)~', $url, $m)) $listingId = $m[1];

if ($pastedText) {
    $meta['title'] = mb_substr(explode("\n", trim($pastedText))[0], 0, 200);
    $meta['description'] = mb_substr($pastedText, 0, 6000);
    $scrapeMode = 'text';
} elseif ($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>20,
        CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/122.0']);
    $html = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($html && $code < 400) {
        if (preg_match('~<meta property="og:title" content="([^"]+)"~i', $html, $m)) $meta['title'] = html_entity_decode($m[1]);
        if (preg_match('~<meta property="og:description" content="([^"]+)"~i', $html, $m)) $meta['description'] = html_entity_decode($m[1]);
        preg_match_all('~"comments":"([^"]{40,400})"~', $html, $rm);
        foreach (array_slice($rm[1] ?? [], 0, 10) as $r) $reviews[] = mb_substr(stripcslashes($r), 0, 300);
        $scrapeMode = (empty($meta['title']) && empty($meta['description'])) ? 'blocked' : 'scraped';
    }
    if ($scrapeMode !== 'scraped') {
        $meta['title'] = $meta['title'] ?: ('Airbnb Listing #' . $listingId);
        $meta['description'] = $meta['description'] ?: "Airbnb blockiert Server-Fetch dieser Listing-ID. Nutze den Text-Modus und kopiere die Listing-Beschreibung für präzisere Analyse.";
    }
}

// ============================================================
// 3) DEEP LLM ANALYSIS — Business Dossier
// ============================================================
$reviewsText = count($reviews) ? implode(' || ', array_slice($reviews, 0, 10)) : 'keine verfügbar';

$marketCtx = $market ? sprintf(
    "BERLIN-MARKT-BENCHMARK (Live-Daten, %d Listings Stichprobe):\n"
    . "- Durchschnitts-Rating: %s/5\n"
    . "- Durchschnitts-Preis/Nacht: $%s USD (~€%s)\n"
    . "- Median Review-Anzahl: %s Reviews",
    $market['sample_size'] ?? 0,
    $market['avg_rating'] ?? '?',
    $market['avg_price_usd'] ?? '?',
    $market['avg_price_usd'] ? round(($market['avg_price_usd'] ?? 0) * 0.92, 0) : '?',
    $market['median_reviews'] ?? '?'
) : "Berlin-Marktdaten nicht verfügbar.";

$prompt = <<<PROMPT
Du bist Senior-Reinigungs-Consultant für Airbnb-Hosts in Berlin. Deine Aufgabe: ein PROFESSIONELLES BUSINESS-DOSSIER für den Host erstellen, auf Basis seines Inserats + Live-Marktdaten. Ziel: den Host davon überzeugen, dass professionelle Reinigung (Fleckfrei) Pflicht ist, nicht Luxus. Nutze konkrete Zahlen, keine Floskeln.

$marketCtx

INSERAT DES HOSTS:
TITEL: {$meta['title']}
BESCHREIBUNG: {$meta['description']}
REVIEWS (Auszüge): {$reviewsText}

Antworte NUR als valides JSON (keine Kommentare, keine Markdown-Fences):
{
  "listing_audit": {
    "apartment_type": "studio|1-zimmer|2-zimmer|3-zimmer|4+zimmer|villa|loft|hotel-suite",
    "estimated_sqm": <int>,
    "beds": <int>,
    "baths": <int>,
    "guests": <int>,
    "estimated_price_per_night_eur": <int>,
    "apartment_class": "budget|mid-range|premium|luxury",
    "signal_quality": "low|medium|high (wie viel Info lieferte der Host)"
  },
  "market_position": {
    "price_vs_market": "under|market|over (+/- Prozent-Schätzung)",
    "price_comment_de": "2 Sätze: wo steht der Host preislich vs Berlin-Durchschnitt",
    "rating_benchmark_needed": "Ziel-Rating um Top-20%% in Berlin zu sein (z.B. 4.85+)",
    "competitive_advantage": "1 Satz: was hat dieser Host, was Konkurrenz nicht hat",
    "competitive_weakness": "1 Satz: wo ist er unterlegen"
  },
  "review_forensics": {
    "identified_complaints": ["konkrete Beschwerde 1", "Beschwerde 2", ...],
    "cleanliness_red_flags": ["spezifisches Sauberkeits-Problem aus Reviews"],
    "estimated_review_impact_pct": "wie viel % potentieller Reviews ging durch Sauberkeits-Issues verloren",
    "top_priority_fix": "DAS EINE Ding, das SOFORT gefixt werden muss"
  },
  "revenue_impact": {
    "lost_bookings_per_year_estimate": <int>,
    "lost_revenue_eur_per_year": <int>,
    "reasoning_de": "Rechnung: X% weniger Buchungen durch niedrigeres Rating × Y€ Durchschnittsbuchung × Z Monate = Verlust. Konkret!",
    "fleckfrei_roi_ratio": "z.B. 1:8 bedeutet 1€ Reinigung spart 8€ Umsatzverlust"
  },
  "swot": {
    "strengths": ["Stärke 1", "Stärke 2"],
    "weaknesses": ["Schwäche 1", "Schwäche 2"],
    "opportunities": ["Chance 1 (z.B. Premium-Pricing möglich wenn Reviews >4.9)", "Chance 2"],
    "threats": ["Bedrohung 1 (z.B. Airbnb-Listung-Ranking sinkt unter 4.5)", "Bedrohung 2"]
  },
  "cleaning_plan": {
    "recommended_hours": <float>,
    "cleaning_type": "basic-turnover|deep-clean|premium-turnover|deep+premium",
    "hot_spots": ["priorisierter Bereich 1", "2", "3"],
    "special_tasks": ["konkrete Aufgabe mit Zeit-Schätzung"],
    "recommended_addons": ["waesche","pflegemittel","fotodoku","handtuecher","schluesselbox"],
    "frequency": "per-turnover|weekly-deep|monthly-deep"
  },
  "business_case": {
    "fleckfrei_cost_per_turnover_eur": <int>,
    "fleckfrei_cost_per_month_estimate_eur": <int>,
    "break_even_bookings_per_month": <int>,
    "12_month_net_gain_eur": <int>,
    "summary_de": "2-3 Sätze: Warum Fleckfrei wirtschaftlich zwingend ist."
  },
  "action_plan": {
    "immediate": ["Sofort-Maßnahme 1", "2"],
    "within_30_days": ["30d Maßnahme"],
    "within_90_days": ["90d Maßnahme"],
    "kpis_to_track": ["KPI 1 mit Ziel-Wert", "KPI 2"]
  },
  "summary_de": "4-5 Sätze Executive Summary. Konkret, kein Marketing-Blabla. Host soll denken 'Scheiße, die haben Recht'.",
  "risk_score": "1-10 (1=alles top, 10=kritisch, Rating-Absturz droht)"
}
PROMPT;

$ai = groq_chat($prompt, 2500);
if (!$ai || !empty($ai['error'])) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'AI nicht erreichbar: ' . ($ai['error'] ?? 'unbekannt')]);
    exit;
}
$raw = $ai['content'] ?? '';
$clean = preg_replace('~^```(?:json)?\s*|\s*```$~m', '', trim($raw));
// Fix stray literal {(...)} patterns from template
$dossier = json_decode($clean, true) ?: ['raw' => $raw, 'parse_error' => true];

// Record rate-limit
$entries[] = $now;
@file_put_contents($rlFile, json_encode($entries));

// Store
try {
    q("CREATE TABLE IF NOT EXISTS airbnb_analyses (
        aa_id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id_fk INT NULL,
        url VARCHAR(500), url_hash CHAR(32),
        title VARCHAR(500), plan_json LONGTEXT,
        ip_address VARCHAR(45) NULL,
        version TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_hash (url_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { q("ALTER TABLE airbnb_analyses ADD COLUMN ip_address VARCHAR(45) NULL"); } catch (Exception $e) {}
    try { q("ALTER TABLE airbnb_analyses ADD COLUMN version TINYINT DEFAULT 1"); } catch (Exception $e) {}
    q("INSERT INTO airbnb_analyses (customer_id_fk, url, url_hash, title, plan_json, ip_address, version) VALUES (NULL, ?, ?, ?, ?, ?, 2)",
      [$url ?: '(text)', md5($url . $pastedText), $meta['title'] ?? null, json_encode($dossier), $ip]);
} catch (Exception $e) {}

echo json_encode([
    'success' => true,
    'url' => $url,
    'listing_id' => $listingId,
    'scrape_mode' => $scrapeMode,
    'reviews_captured' => count($reviews),
    'meta' => $meta,
    'market' => $market,
    'dossier' => $dossier,
    'generated_at' => date('c'),
]);
