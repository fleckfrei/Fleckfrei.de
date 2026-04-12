<?php
/**
 * Market Price Scraper — fetches current competitor prices for Berlin cleaning services.
 * Uses VPS SearXNG OSINT API (port 8900) with 30+ search engine backends.
 * Falls back to Brave Search HTML if VPS unreachable.
 *
 * Usage:
 *   GET /api/market-scraper.php           — manual run (admin only)
 *   GET /api/market-scraper.php?cron=1    — cron mode (with secret)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/llm-helpers.php';

header('Content-Type: application/json; charset=utf-8');

// Auth: admin session OR cron secret
session_start();
$isAdmin = (($_SESSION['utype'] ?? '') === 'admin');
$cronSecret = $_GET['cron'] ?? '';
if (!$isAdmin && $cronSecret !== (defined('CRON_SECRET') ? CRON_SECRET : 'flk_scrape_2026')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// ============================================================
// Known competitors in Berlin — search queries + fallback prices
// ============================================================
$competitors = [
    // Plattform-Vermittler (Privat)
    ['name' => 'Helpling',          'queries' => ['Helpling Berlin Preis pro Stunde Reinigung 2026'],       'fallback' => 18.50, 'source_url' => 'https://www.helpling.de',       'type' => 'platform'],
    ['name' => 'Book a Tiger',      'queries' => ['Book a Tiger Berlin Preis Stunde Reinigung 2026'],       'fallback' => 19.90, 'source_url' => 'https://www.bookatiger.com',    'type' => 'platform'],
    ['name' => 'Batmaid',           'queries' => ['Batmaid Berlin Preis Stunde 2026'],                      'fallback' => 24.90, 'source_url' => 'https://www.batmaid.de',        'type' => 'platform'],
    ['name' => 'Wecasa',            'queries' => ['Wecasa Berlin Putzfrau Preis Stunde 2026'],              'fallback' => 24.90, 'source_url' => 'https://www.wecasa.de',         'type' => 'platform'],
    ['name' => 'Putzperle',         'queries' => ['Putzperle Berlin Preis Stunde'],                         'fallback' => 14.50, 'source_url' => 'https://www.putzperle.de',      'type' => 'platform'],
    ['name' => 'CleanWhale',        'queries' => ['CleanWhale Berlin Reinigung Preis Stunde'],              'fallback' => 22.00, 'source_url' => 'https://www.cleanwhale.de',     'type' => 'platform'],
    // Professionelle Reinigungsfirmen
    ['name' => 'Immo Clean',        'queries' => ['Immo Clean Berlin Reinigungsfirma Preis Stunde'],        'fallback' => 30.00, 'source_url' => 'https://www.immo-clean.de',     'type' => 'company'],
    ['name' => 'WMS Gebäudereinigung', 'queries' => ['WMS Gebäudereinigung Berlin Stundensatz'],            'fallback' => 25.00, 'source_url' => 'https://www.wms-gebaeudereinigung.de', 'type' => 'company'],
    // Airbnb/Turnover-Spezialisten
    ['name' => 'A4ord (Airbnb)',    'queries' => ['A4ord Berlin Airbnb Cleaning Preis Stunde'],             'fallback' => 20.00, 'source_url' => 'https://a4ord.de',              'type' => 'airbnb'],
    ['name' => 'ECO Hotelservice',  'queries' => ['ECO Hotelservice Airbnb Turnover Berlin Preis'],         'fallback' => 25.00, 'source_url' => 'https://eco-hotelservice.de',   'type' => 'airbnb'],
    // Privat/Kleinanzeigen
    ['name' => 'Privatmarkt (Kleinanzeigen)', 'queries' => ['Reinigungskraft Berlin privat Stundenlohn'],   'fallback' => 15.00, 'source_url' => 'https://www.kleinanzeigen.de',  'type' => 'private'],
];

// ============================================================
// Helpers
// ============================================================
/**
 * Fetch search results via VPS SearXNG OSINT API (89.116.22.185:8900).
 * SearXNG aggregates 30+ search engines and has no IP rate limits.
 * Returns concatenated title + content + snippets for regex extraction.
 */
function fetchSearch(string $query): string {
    $result = vps_call('searxng', [
        'query' => $query,
        'categories' => 'general',
        'limit' => 20,
    ], false); // no cache — we want fresh market data

    if (!is_array($result) || empty($result['results'])) return '';

    // Concatenate all titles + snippets for regex extraction
    // Field is 'snippet' in our VPS SearXNG wrapper (not 'content')
    $buf = '';
    foreach ($result['results'] as $r) {
        $buf .= ($r['title'] ?? '') . "\n";
        $buf .= ($r['snippet'] ?? $r['content'] ?? '') . "\n";
        $buf .= ($r['url'] ?? '') . "\n";
    }
    return $buf;
}

function extractPrices(string $html): array {
    $prices = [];
    // Primary: "XX,YY €" or "XX.YY €" — German format with Euro sign
    if (preg_match_all('/\b(\d{1,2}[,.]?\d{0,2})\s*(€|EUR)/u', $html, $m)) {
        foreach ($m[1] as $p) {
            $val = (float) str_replace(',', '.', $p);
            if ($val >= 10 && $val <= 60) $prices[] = $val;
        }
    }
    // Secondary: "XX,YY Euro"
    if (preg_match_all('/\b(\d{1,2}[,.]?\d{0,2})\s*Euro/ui', $html, $m)) {
        foreach ($m[1] as $p) {
            $val = (float) str_replace(',', '.', $p);
            if ($val >= 10 && $val <= 60) $prices[] = $val;
        }
    }
    return $prices;
}

function medianPrice(array $prices): ?float {
    if (empty($prices)) return null;
    sort($prices);
    $n = count($prices);
    return $prices[intdiv($n, 2)];
}

// ============================================================
// Run scrape
// ============================================================
$results = [];
$savedCount = 0;

// Strategy: ONE big market-wide search, then extract prices near competitor names.
// This avoids rate limiting from per-competitor searches.
$megaQuery = 'Reinigungskraft Berlin Stundenlohn Helpling Book-a-Tiger Batmaid Preise Vergleich';
$megaHtml = fetchSearch($megaQuery);
$megaHtmlLen = strlen($megaHtml);

// Extract all prices globally from the result page
$globalPrices = extractPrices($megaHtml);
sort($globalPrices);

$debug = ['mega_query' => $megaQuery, 'mega_html_len' => $megaHtmlLen, 'global_prices' => $globalPrices];

foreach ($competitors as $i => $comp) {
    $allPrices = [];
    $sourceUsed = 'fallback';

    // 1) Try to find prices mentioned near competitor name in the mega-result
    if ($megaHtml) {
        $name = preg_quote($comp['name'], '/');
        // Look for prices in a 300-char window around competitor name mentions
        if (preg_match_all('/' . $name . '.{0,300}?(\d{1,2}[,.]?\d{0,2})\s*(€|EUR)/siu', $megaHtml, $m)) {
            foreach ($m[1] as $p) {
                $val = (float) str_replace(',', '.', $p);
                if ($val >= 10 && $val <= 60) $allPrices[] = $val;
            }
            if (!empty($allPrices)) $sourceUsed = 'brave_contextual';
        }
    }

    // 2) Fallback: dedicated single search per competitor (rate-limited via mojeek fallback)
    if (empty($allPrices)) {
        sleep(1);
        $html = fetchSearch($comp['queries'][0]);
        $p = extractPrices($html);
        if (!empty($p)) {
            $allPrices = $p;
            $sourceUsed = 'search_direct';
        }
    }

    $debug[$comp['name']] = ['prices_count' => count($allPrices), 'source' => $sourceUsed];
    $price = medianPrice($allPrices) ?? $comp['fallback'];

    // Delete old entry for this competitor (keep only latest)
    q("DELETE FROM market_competitors WHERE competitor = ? AND city = 'Berlin'", [$comp['name']]);

    q("INSERT INTO market_competitors (source, competitor, hourly_price, city) VALUES (?, ?, ?, ?)",
      [$sourceUsed . ' | ' . $comp['source_url'], $comp['name'], $price, 'Berlin']);

    $results[] = [
        'competitor' => $comp['name'],
        'price' => $price,
        'source' => $sourceUsed,
        'samples_found' => count($allPrices),
        'url' => $comp['source_url'],
    ];
    $savedCount++;
}

// Update pricing_config with the new cheapest market price
$cheapest = !empty($results) ? min(array_column($results, 'price')) : null;
if ($cheapest !== null) {
    q("UPDATE pricing_config SET market_price_reference = ?, market_scan_at = NOW() WHERE customer_type = 'all'", [$cheapest]);
}

if (function_exists('audit')) {
    audit('scrape', 'market_competitors', 0, "$savedCount Wettbewerber gescraped, Günstigster: " . number_format($cheapest, 2, ',', '.') . ' €');
}

echo json_encode([
    'success' => true,
    'scraped_at' => date('Y-m-d H:i:s'),
    'competitors_count' => $savedCount,
    'cheapest_price' => $cheapest,
    'results' => $results,
    'debug' => $debug,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
