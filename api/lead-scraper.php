<?php
/**
 * Lead Scraper — finds new potential cleaning customers in Berlin.
 * Uses VPS SearXNG to scan public posts/comments about cleaning needs:
 * - Haushaltsreinigung
 * - Airbnb Turnover Cleaning
 * - Büroreinigung
 *
 * Extracts: source URL, snippet, contact hints (phone/email).
 * OSINT-enriched per lead, saved to `leads` table.
 *
 * GET /api/lead-scraper.php?cron=flk_scrape_2026
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/llm-helpers.php';

header('Content-Type: application/json; charset=utf-8');

session_start();
$isAdmin = (($_SESSION['utype'] ?? '') === 'admin');
$cronSecret = $_GET['cron'] ?? '';
if (!$isAdmin && $cronSecret !== 'flk_scrape_2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// ============================================================
// Search queries by category
// ============================================================
// Gezielt auf Kleinanzeigen-Plattformen, wo echte Leute Hilfe suchen
$queries = [
    'haushalt' => [
        'site:kleinanzeigen.de "Putzhilfe" Berlin',
        'site:kleinanzeigen.de "Reinigungskraft" Berlin gesucht',
        'site:kleinanzeigen.de "Haushaltshilfe" Berlin',
        'site:quoka.de Reinigung Berlin gesucht',
        'site:nebenan.de "Putzhilfe gesucht" Berlin',
        '"suche Putzhilfe" Berlin 2026',
    ],
    'airbnb' => [
        'site:kleinanzeigen.de Airbnb Reinigung Berlin',
        '"Turnover cleaning" Berlin Airbnb 2026',
        '"Ferienwohnung Reinigung" Berlin gesucht',
        'site:facebook.com/groups Berlin Airbnb Reinigung',
    ],
    'buero' => [
        'site:kleinanzeigen.de Büroreinigung Berlin',
        '"Büroreinigung gesucht" Berlin',
        '"Praxis Reinigung" Berlin gesucht',
        'site:gelbeseiten.de Büroreinigung Berlin new',
    ],
    'event' => [
        '"Event Reinigung" Berlin gesucht',
        '"Veranstaltung Reinigung" Berlin',
    ],
];

// ============================================================
// Helpers
// ============================================================
function extractEmail(string $text): ?string {
    if (preg_match('/[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $m)) return $m[0];
    return null;
}
function extractPhone(string $text): ?string {
    // German phone: +49, 030, 0151, etc
    if (preg_match('/(\+49|0)\s?[\d\s\-\/]{8,}/', $text, $m)) return trim($m[0]);
    return null;
}

// ============================================================
// Run scraping
// ============================================================
$totalNew = 0;
$totalSeen = 0;
$results = [];

foreach ($queries as $category => $queryList) {
    foreach ($queryList as $q) {
        $sx = vps_call('searxng', [
            'query' => $q,
            'categories' => 'general',
            'limit' => 10,
        ], false);

        if (!is_array($sx) || empty($sx['results'])) continue;

        foreach ($sx['results'] as $r) {
            $totalSeen++;
            $url = $r['url'] ?? '';
            $title = $r['title'] ?? '';
            $snippet = $r['snippet'] ?? $r['content'] ?? '';
            if ($url === '' || $title === '') continue;

            // Skip aggregator/spam domains
            $skipDomains = ['google.com', 'wikipedia', 'youtube.com', 'helpling.de', 'book-a-tiger.com', 'amazon.', 'pinterest.', 'facebook.com/marketplace'];
            $skip = false;
            foreach ($skipDomains as $d) if (strpos($url, $d) !== false) $skip = true;
            if ($skip) continue;

            // Skip if already in DB
            $exists = val("SELECT lead_id FROM leads WHERE source_url = ? LIMIT 1", [$url]);
            if ($exists) continue;

            // Extract contact hints from snippet+title
            $combined = $title . ' ' . $snippet;
            $email = extractEmail($combined);
            $phone = extractPhone($combined);

            try {
                q("INSERT INTO leads (source, source_url, category, name, email, phone, city, notes, raw_snippet) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                  [parse_url($url, PHP_URL_HOST) ?: 'unknown', $url, $category, $title, $email, $phone, 'Berlin', '', $snippet]);
                $totalNew++;
                $results[] = [
                    'category' => $category,
                    'title' => substr($title, 0, 80),
                    'url' => $url,
                    'has_contact' => $email || $phone ? true : false,
                ];
            } catch (Exception $e) {
                // Skip duplicates / errors
            }
        }

        sleep(1); // throttle between queries
    }
}

if (function_exists('audit')) {
    audit('scrape', 'leads', 0, "Lead-Scrape: $totalNew neu von $totalSeen geprüft");
}

echo json_encode([
    'success' => true,
    'scraped_at' => date('Y-m-d H:i:s'),
    'total_seen' => $totalSeen,
    'total_new' => $totalNew,
    'results' => array_slice($results, 0, 30),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
