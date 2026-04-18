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
// Echte Berlin-Market-Keywords: was Leute tippen, wenn sie eine Reinigungs-
// oder Co-Host-Firma suchen. Fokus: Kleinanzeigen + Community-Foren wo Anfragen
// wirklich gepostet werden.
$queries = [
    // Privat-Wohnung — Leute die eine Firma/Hilfe suchen
    'haushalt' => [
        'site:kleinanzeigen.de "Reinigungsfirma gesucht" Berlin',
        'site:kleinanzeigen.de "Putzhilfe gesucht" Berlin',
        'site:kleinanzeigen.de "Haushaltshilfe gesucht" Berlin',
        'site:kleinanzeigen.de "Wohnungsreinigung" Berlin gesucht',
        'site:kleinanzeigen.de "Grundreinigung" Berlin gesucht',
        'site:kleinanzeigen.de "Endreinigung Wohnung" Berlin',
        'site:kleinanzeigen.de "Fensterputzer" Berlin gesucht',
        'site:quoka.de Reinigungsfirma Berlin gesucht',
        'site:nebenan.de "Putzhilfe" Berlin',
        '"suche Reinigungsfirma" Berlin Wohnung',
        '"brauche Putzfrau" Berlin privat',
    ],
    // Short-Term Rental / Airbnb — Hosts die Turnover-Cleaning suchen
    'airbnb' => [
        'site:kleinanzeigen.de "Airbnb Reinigung" Berlin gesucht',
        'site:kleinanzeigen.de "Ferienwohnung Reinigung" Berlin',
        'site:kleinanzeigen.de "Turnover" Berlin Gästewechsel',
        '"Airbnb Reinigungsservice" Berlin gesucht',
        '"Ferienwohnung Putzkraft" Berlin',
        '"STR cleaning" Berlin gesucht',
        '"Gästewechsel Reinigung" Berlin',
        '"Endreinigung Ferienwohnung" Berlin',
        'site:nebenan.de Airbnb Reinigung Berlin',
        'facebook.com Berlin Airbnb hosts Reinigung gesucht',
    ],
    // Co-Host Services — Vermieter die Airbnb-Management suchen
    'cohost' => [
        '"Airbnb Co-Host" Berlin gesucht',
        '"Airbnb Management" Berlin Vermieter',
        '"Ferienwohnung Verwaltung" Berlin gesucht',
        '"Short-Term Rental Management" Berlin',
        '"Airbnb Betreuung" Berlin gesucht',
        'site:kleinanzeigen.de "Co-Host" Berlin',
        'site:kleinanzeigen.de "Airbnb Verwaltung" Berlin',
        '"Gastgeber Service" Berlin Airbnb',
    ],
    // Büro / B2B
    'buero' => [
        'site:kleinanzeigen.de "Büroreinigung" Berlin gesucht',
        'site:kleinanzeigen.de "Praxisreinigung" Berlin gesucht',
        '"Büroreinigung Firma" Berlin gesucht',
        '"Gewerbereinigung" Berlin gesucht',
        '"Unterhaltsreinigung Büro" Berlin',
        '"Reinigungsfirma Büro" Berlin gesucht',
    ],
    // Events / Baustellen / Umzug
    'event' => [
        '"Eventreinigung" Berlin gesucht',
        '"Baustellenreinigung" Berlin gesucht',
        '"Umzugsreinigung" Berlin gesucht',
        'site:kleinanzeigen.de Umzugsreinigung Berlin',
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

// ============================================================
// Direkt-Scrape von kleinanzeigen.de (unabhängig von VPS SearXNG)
// Kleinanzeigen-Suche liefert deutlich verlässlicher echte Leads.
// ============================================================
$kleinanzeigenCategories = [
    'haushalt' => ['reinigungsfirma-berlin','putzhilfe-berlin','haushaltshilfe-berlin','wohnungsreinigung-berlin'],
    'airbnb'   => ['airbnb-reinigung-berlin','ferienwohnung-reinigung-berlin'],
    'cohost'   => ['airbnb-co-host-berlin','airbnb-verwaltung-berlin'],
    'buero'    => ['bueroreinigung-berlin','gewerbereinigung-berlin'],
];
foreach ($kleinanzeigenCategories as $category => $slugs) {
    foreach ($slugs as $slug) {
        $url = 'https://www.kleinanzeigen.de/s-' . urlencode($slug) . '/k0l3331';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['Accept-Language: de-DE,de;q=0.9'],
        ]);
        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$html) continue;

        // Einfach: <a class="ellipsis" href="/s-anzeige/...">Titel</a>
        if (preg_match_all('#<a class="ellipsis"[^>]+href="(/s-anzeige/[^"]+)"[^>]*>([^<]+)</a>#i', $html, $m, PREG_SET_ORDER)) {
            foreach (array_slice($m, 0, 20) as $match) {
                $totalSeen++;
                $leadUrl = 'https://www.kleinanzeigen.de' . $match[1];
                $title = html_entity_decode(trim($match[2]), ENT_QUOTES, 'UTF-8');
                if (val("SELECT lead_id FROM leads WHERE source_url=? LIMIT 1", [$leadUrl])) continue;
                try {
                    $seg = ($category === 'buero') ? 'B2B' : 'B2C';
                    q("INSERT INTO leads (source, source_url, category, name, email, phone, city, notes, raw_snippet) VALUES (?, ?, ?, ?, NULL, NULL, 'Berlin', ?, ?)",
                      ['kleinanzeigen.de', $leadUrl, $category, $title, "[$seg]", substr($title, 0, 500)]);
                    $totalNew++;
                    $results[] = ['category'=>$category, 'title'=>substr($title,0,80), 'url'=>$leadUrl, 'has_contact'=>false];
                } catch (Exception $e) {}
            }
        }
        usleep(500000); // 0.5s throttle
    }
}

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

            // B2B vs B2C-Segment
            $segmentMap = ['haushalt'=>'B2C','airbnb'=>'B2C','cohost'=>'B2C','buero'=>'B2B','event'=>'B2B','umzug'=>'B2C'];
            $segment = $segmentMap[$category] ?? 'B2C';
            $notes = "[$segment]";

            try {
                q("INSERT INTO leads (source, source_url, category, name, email, phone, city, notes, raw_snippet) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                  [parse_url($url, PHP_URL_HOST) ?: 'unknown', $url, $category, $title, $email, $phone, 'Berlin', $notes, $snippet]);
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
