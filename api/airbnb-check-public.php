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

// Detect STR platform
function detectPlatform(string $url): ?string {
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    if (str_contains($host, 'airbnb.'))           return 'airbnb';
    if (str_contains($host, 'booking.'))          return 'booking';
    if (str_contains($host, 'vrbo.'))             return 'vrbo';
    if (str_contains($host, 'agoda.'))            return 'agoda';
    if (str_contains($host, 'fewo-direkt.'))      return 'fewo-direkt';
    if (str_contains($host, 'expedia.'))          return 'expedia';
    if (str_contains($host, 'hotels.com'))        return 'hotels-com';
    if (str_contains($host, 'hometogo.'))         return 'hometogo';
    if (str_contains($host, 'tripadvisor.'))      return 'tripadvisor';
    if (str_contains($host, 'holidu.'))           return 'holidu';
    if (str_contains($host, 'trivago.'))          return 'trivago';
    return null;
}

$platform = null;
if ($url) {
    $platform = detectPlatform($url);
    if (!$platform) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unbekannte Plattform. Unterstützt: Airbnb, Booking.com, VRBO, Agoda, FeWo-direkt, Expedia, Hotels.com, HomeToGo, TripAdvisor, Holidu, Trivago. Oder nutze den Text-Modus.']);
        exit;
    }
}

// ============================================================
// 1) BERLIN MARKET BENCHMARK (cached 6h)
// ============================================================
function fetchMarketData(string $location = 'Berlin'): array {
    $cache = sys_get_temp_dir() . '/airbnb_check_v2/market_' . md5($location) . '.json';
    if (file_exists($cache) && (time() - filemtime($cache)) < 21600) {
        return json_decode(@file_get_contents($cache), true) ?: [];
    }
    $searchUrl = 'https://www.airbnb.com/s/' . urlencode($location) . '/homes?adults=2';
    $r = fetchWithFallback($searchUrl);
    $html = $r['html'] ?? ''; $code = $r['code'] ?? 0;
    if (!$html || $code >= 400) return [];

    $listings = [];
    // Airbnb EU: supports "€ NNN", "€\xc2\xa0NNN", "NNN €" (DE), etc.
    preg_match_all('~"avgRatingA11yLabel":"([^"]+)"~', $html, $ratingMatches);
    // Match "discountedPrice":"490 €" OR "discountedPrice":"€ 490" OR NBSP variants
    preg_match_all('~"discountedPrice":"(?:€[\s\xc2\xa0]*)?([\d.,]+)(?:[\s\xc2\xa0]*€)?"~u', $html, $discountedMatches);
    if (empty($discountedMatches[1])) {
        preg_match_all('~"(?:price|totalPrice)":"(?:€[\s\xc2\xa0]*)?([\d.,]+)(?:[\s\xc2\xa0]*€)?"~u', $html, $discountedMatches);
    }
    preg_match_all('~"accessibilityLabel":"(?:€[\s\xc2\xa0]*)?([\d.,]+)[\s\xc2\xa0]*€[^"]*(?:total|Gesamtpreis|insgesamt)[^"]*"~ui', $html, $priceMatches);
    preg_match_all('~"localizedStringWithTranslationPreference":"([^"]{5,200})"~', $html, $nameMatches);

    // Assume default search window = 5 nights unless we see otherwise
    $defaultNights = 5;
    $count = max(count($ratingMatches[1] ?? []), count($discountedMatches[1] ?? []));
    for ($i = 0; $i < min($count, 18); $i++) {
        $rating = null; $reviews = null; $pricePerNight = null;
        $ratingLabel = $ratingMatches[1][$i] ?? '';
        if (preg_match('~([\d.,]+)\s+out of 5\s*average rating,?\s*([\d.,]+)\s+reviews~i', $ratingLabel, $rm)) {
            $rating = (float) str_replace(',', '.', $rm[1]);
            $reviews = (int) str_replace(',', '', $rm[2]);
        } elseif (preg_match('~Bewertung:\s*([\d.,]+)\s*von\s*5,?\s*([\d.,]+)\s+Bewertungen~iu', $ratingLabel, $rm)) {
            $rating = (float) str_replace(',', '.', $rm[1]);
            $reviews = (int) str_replace(['.',','], '', $rm[2]);
        }
        $priceTotal = null;
        if (isset($discountedMatches[1][$i])) $priceTotal = (float) str_replace(',', '', $discountedMatches[1][$i]);
        elseif (isset($priceMatches[1][$i])) $priceTotal = (float) str_replace(',', '', $priceMatches[1][$i]);
        if ($priceTotal) $pricePerNight = round($priceTotal / $defaultNights, 0);

        if ($rating || $pricePerNight) {
            $listings[] = ['name' => $nameMatches[1][$i] ?? '', 'rating' => $rating, 'reviews' => $reviews, 'price_per_night_eur' => $pricePerNight];
        }
    }
    $ratings = array_filter(array_column($listings, 'rating'));
    $prices = array_filter(array_column($listings, 'price_per_night_eur'));
    $reviewsCol = array_filter(array_column($listings, 'reviews'));
    $data = [
        'location' => $location,
        'sample_size' => count($listings),
        'avg_rating' => $ratings ? round(array_sum($ratings)/count($ratings), 2) : null,
        'avg_price_eur' => $prices ? round(array_sum($prices)/count($prices), 0) : null,
        'median_reviews' => $reviewsCol ? (int)(array_sum($reviewsCol)/count($reviewsCol)) : null,
        'listings_sample' => array_slice($listings, 0, 8),
        'cached_at' => date('c'),
    ];
    @file_put_contents($cache, json_encode($data));
    return $data;
}

$market = fetchMarketData('Berlin');

// ============================================================
// PROXY ROTATION — residential IPs to bypass anti-bot
// ============================================================
$PROXY_POOL = [
    'http://14a9a56c11929:275dc8b9cc@80.96.36.156:12323',   // RO
    'http://14ae2d3d95c2d:fb9d8dfc5e@88.223.20.2:12323',    // DE
    'http://14af0579ea9f8:d3d0ae255e@178.92.252.24:12323',  // ?
];

function fetchViaProxy(string $url, string $proxy, int $timeout = 25): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_PROXY => $proxy,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Upgrade-Insecure-Requests: 1',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['html' => $html, 'code' => $code];
}

function fetchWithFallback(string $url): array {
    global $PROXY_POOL;
    // Try direct first
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>15,
        CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/122.0',
        CURLOPT_HTTPHEADER=>['Accept-Language: de-DE,de;q=0.9,en;q=0.8']]);
    $html = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    // Accept direct only if it looks substantial + has OG tags
    if ($html && $code < 400 && strlen($html) > 20000 && str_contains($html, 'og:title')) {
        return ['html' => $html, 'code' => $code, 'via' => 'direct'];
    }
    // Try proxies in order
    $proxies = $PROXY_POOL;
    shuffle($proxies);
    foreach ($proxies as $i => $proxy) {
        $res = fetchViaProxy($url, $proxy, 25);
        if ($res['html'] && $res['code'] < 400 && strlen($res['html']) > 10000 && str_contains($res['html'], 'og:')) {
            return $res + ['via' => 'proxy_' . $i];
        }
    }
    // Return last attempt (even if poor) for graceful degradation
    return $res ?? ['html' => $html, 'code' => $code, 'via' => 'failed'];
}

// ============================================================
// 2) LISTING SCRAPE (URL-mode)
// ============================================================
$meta = []; $reviews = []; $hints = []; $scrapeMode = 'none'; $listingId = null;
if ($url) {
    if (preg_match('~/rooms/(\d+)~', $url, $m)) $listingId = $m[1];
    elseif (preg_match('~/hotel/[a-z]{2}/([^.?/]+)~', $url, $m)) $listingId = $m[1]; // booking.com
    elseif (preg_match('~/home/(\d+)~', $url, $m)) $listingId = $m[1]; // vrbo
}

if ($pastedText) {
    $meta['title'] = mb_substr(explode("\n", trim($pastedText))[0], 0, 200);
    $meta['description'] = mb_substr($pastedText, 0, 6000);
    $scrapeMode = 'text';
} elseif ($url) {
    $res = fetchWithFallback($url);
    $html = $res['html'] ?? ''; $code = $res['code'] ?? 0; $scrapeVia = $res['via'] ?? 'unknown';
    if ($html && $code < 400) {
        // Universal OG tags
        if (preg_match('~<meta property="og:title" content="([^"]+)"~i', $html, $m)) $meta['title'] = html_entity_decode($m[1]);
        if (preg_match('~<meta property="og:description" content="([^"]+)"~i', $html, $m)) $meta['description'] = html_entity_decode($m[1]);
        if (preg_match('~<meta property="og:image" content="([^"]+)"~i', $html, $m)) $meta['image'] = $m[1];
        if (!$meta['title'] && preg_match('~<title>([^<]+)</title>~i', $html, $m)) $meta['title'] = trim(html_entity_decode($m[1]));

        // Platform-specific review extraction
        if ($platform === 'airbnb') {
            preg_match_all('~"comments":"([^"]{40,400})"~', $html, $rm);
        } elseif ($platform === 'booking') {
            // Booking.com: reviews in data-testid="review-card" or rawText fields
            preg_match_all('~"negativeText":"([^"]{30,300})"~', $html, $neg);
            preg_match_all('~"positiveText":"([^"]{30,300})"~', $html, $pos);
            $rm = [[]];
            foreach ($neg[1] ?? [] as $n) $rm[1][] = '[NEGATIVE] ' . $n;
            foreach ($pos[1] ?? [] as $p) $rm[1][] = '[POSITIVE] ' . $p;
        } elseif ($platform === 'vrbo' || $platform === 'fewo-direkt') {
            preg_match_all('~"reviewText":"([^"]{40,400})"~', $html, $rm);
        } elseif ($platform === 'agoda') {
            preg_match_all('~"reviewComment":"([^"]{40,400})"~', $html, $rm);
        } else {
            // Generic: try multiple patterns
            preg_match_all('~"(?:review|comment|text|reviewText|description)":"([^"]{60,400})"~', $html, $rm);
        }
        foreach (array_slice($rm[1] ?? [], 0, 10) as $r) $reviews[] = mb_substr(stripcslashes($r), 0, 300);

        // Extract rating+review-count universally
        if (preg_match('~"aggregateRating"[^}]*"ratingValue"[^"0-9]*(\d+[.,]?\d*)[^}]*"reviewCount"[^0-9]*(\d+)~', $html, $ar)) {
            $meta['rating'] = (float) str_replace(',', '.', $ar[1]);
            $meta['review_count'] = (int) $ar[2];
        }
        $scrapeMode = (empty($meta['title']) && empty($meta['description'])) ? 'blocked' : 'scraped';
    }
    if ($scrapeMode !== 'scraped') {
        $meta['title'] = $meta['title'] ?: (ucfirst($platform) . ' Listing ' . ($listingId ?: ''));
        $meta['description'] = $meta['description'] ?: ($platform . " blockiert Server-Fetch. Nutze den Text-Modus und kopiere Listing-Beschreibung + Reviews für präzisere Analyse.");
    }
}

// ============================================================
// 3) DEEP LLM ANALYSIS — Business Dossier
// ============================================================
$reviewsText = count($reviews) ? implode(' || ', array_slice($reviews, 0, 10)) : 'keine verfügbar';

$marketCtx = ($market && !empty($market['avg_rating'])) ? sprintf(
    "BERLIN-MARKT-BENCHMARK (Live-Daten, %d Listings Stichprobe):\n"
    . "- Durchschnitts-Rating: %s/5\n"
    . "- Durchschnitts-Preis/Nacht: €%s\n"
    . "- Median Review-Anzahl: %s Reviews",
    $market['sample_size'] ?? 0,
    $market['avg_rating'] ?? '?',
    $market['avg_price_eur'] ?? '?',
    $market['median_reviews'] ?? '?'
) : "Berlin-Marktdaten (Live) nicht verfügbar — arbeite mit Schätzungen.";

$platformLabel = $platform ? strtoupper($platform) : 'STR (Text-Input)';
$existingRating = !empty($meta['rating']) ? sprintf("AKTUELL: %.2f/5 aus %d Reviews (Plattform-Angabe)", $meta['rating'], $meta['review_count'] ?? 0) : '';

$prompt = <<<PROMPT
Du bist Senior-Reinigungs-Consultant für Short-Term-Rental-Hosts (Airbnb/Booking.com/VRBO/Agoda etc.) in Berlin. Deine Aufgabe: ein PROFESSIONELLES BUSINESS-DOSSIER für den Host erstellen, auf Basis seines Inserats + Live-Marktdaten. Ziel: den Host davon überzeugen, dass professionelle Reinigung (Fleckfrei) Pflicht ist, nicht Luxus. Nutze konkrete Zahlen, keine Floskeln. Die Marktdaten kommen von Airbnb — nutze sie als Benchmark auch für andere Plattformen (Booking/VRBO haben meist höhere Preise + striktere Review-Kultur).

$marketCtx

INSERAT DES HOSTS (Plattform: $platformLabel):
TITEL: {$meta['title']}
BESCHREIBUNG: {$meta['description']}
$existingRating
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
    "fleckfrei_roi_ratio": "<IMMER STRING wie '1:8' oder '1:12' — NIE unquoted>"
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
// Auto-repair common LLM JSON glitches
$clean = preg_replace('~("fleckfrei_roi_ratio"\s*:\s*)(\d+:\d+)~', '$1"$2"', $clean);
$clean = preg_replace('~("price_vs_market"\s*:\s*)([+\-]?\d+%?)~', '$1"$2"', $clean);
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
    'platform' => $platform,
    'listing_id' => $listingId,
    'scrape_mode' => $scrapeMode,
    'scrape_via' => $scrapeVia ?? null,
    'reviews_captured' => count($reviews),
    'meta' => $meta,
    'market' => $market,
    'dossier' => $dossier,
    'generated_at' => date('c'),
]);
