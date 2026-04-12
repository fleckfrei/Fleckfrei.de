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
    [
        'name' => 'Helpling',
        'queries' => [
            'Helpling Berlin Preis pro Stunde Reinigung',
            'Helpling Berlin Stundenlohn Reinigungskraft',
        ],
        'fallback' => 16.90,
        'source_url' => 'https://www.helpling.de',
    ],
    [
        'name' => 'Book-a-Tiger',
        'queries' => [
            'Book a Tiger Berlin Preis Stunde Reinigung',
            '"Book a Tiger" Berlin Stundenpreis',
        ],
        'fallback' => 23.90,
        'source_url' => 'https://www.book-a-tiger.com',
    ],
    [
        'name' => 'Batmaid',
        'queries' => [
            'Batmaid Berlin Preis Stunde',
            'Batmaid Berlin Stundenlohn',
        ],
        'fallback' => 29.90,
        'source_url' => 'https://www.batmaid.de',
    ],
    [
        'name' => 'Holmes Cleaning',
        'queries' => [
            'Holmes Cleaning Berlin Preis Stunde',
            'Holmes Cleaning Stundenlohn',
        ],
        'fallback' => 24.90,
        'source_url' => 'https://www.holmes-cleaning.de',
    ],
    [
        'name' => 'Putzperle',
        'queries' => [
            'Putzperle Berlin Preis Stunde',
        ],
        'fallback' => 22.50,
        'source_url' => 'https://www.putzperle.de',
    ],
    [
        'name' => 'Jobruf',
        'queries' => [
            'Jobruf Berlin Reinigungskraft Stundenlohn',
        ],
        'fallback' => 15.50,
        'source_url' => 'https://www.jobruf.de',
    ],
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
