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
/**
 * Fetch single ad page and extract contact info + full description.
 * Returns ['email','phone','name','district','full_text'] or null on failure.
 */
// Volle Browser-Headers damit Kleinanzeigen/Cloudflare nicht blockt
function browserHeaders(): array {
    return [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
        'Accept-Language: de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7',
        'Sec-Ch-Ua: "Chromium";v="120", "Not_A Brand";v="8"',
        'Sec-Ch-Ua-Mobile: ?0',
        'Sec-Ch-Ua-Platform: "macOS"',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'Upgrade-Insecure-Requests: 1',
    ];
}

/**
 * Primary: IPRoyal ISP-Proxy (statische DE-IPs, schnellster Fetch ~1s).
 * Round-robin zwischen allen konfigurierten Proxies.
 */
function fetchViaIproyal(string $url, int $timeoutSec = 20): ?string {
    if (!defined('IPROYAL_PROXIES') || !IPROYAL_PROXIES) return null;
    $list = is_string(IPROYAL_PROXIES) ? json_decode(IPROYAL_PROXIES, true) : IPROYAL_PROXIES;
    if (!is_array($list) || empty($list)) return null;
    // Random proxy pick (round-robin-artig)
    $p = $list[array_rand($list)];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_ENCODING => '',
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => browserHeaders(),
        CURLOPT_PROXY => "http://{$p['host']}:{$p['port']}",
        CURLOPT_PROXYUSERPWD => "{$p['user']}:{$p['pass']}",
        CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $html) ? $html : null;
}

/**
 * Fallback: BrightData Web Unlocker API (handles JS, CAPTCHA, auto-retries).
 * Etwas langsamer (~5s) aber fast immer erfolgreich.
 */
function fetchViaBrightData(string $url, int $timeoutSec = 30): ?string {
    if (!defined('BRIGHTDATA_API_KEY') || !BRIGHTDATA_API_KEY) return null;
    $zone = defined('BRIGHTDATA_ZONE') ? BRIGHTDATA_ZONE : 'n8n_unblocker';
    $ch = curl_init('https://api.brightdata.com/request');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . BRIGHTDATA_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'zone' => $zone,
            'url' => $url,
            'format' => 'raw',
            'country' => 'de',
        ]),
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $html) ? $html : null;
}

/**
 * Smart Fetch Chain mit KOSTEN-OPTIMIERUNG:
 *   1. Disk-Cache (24h TTL) — 0$ Kosten, 0ms Latenz
 *   2. IPRoyal ISP — Subscription (= free je Request)
 *   3. BrightData Web Unlocker — pay-per-request (~0.002$/req)
 *   4. Apify Actor — compute + proxy (~0.005$/req)
 * Cache schont Provider-Limits UND spart $$.
 */
function fetchSmart(string $url, int $cacheSec = 86400): ?string {
    // Cache-Read
    $cDir = sys_get_temp_dir() . '/flk_scrape_cache';
    if (!is_dir($cDir)) @mkdir($cDir, 0700, true);
    $cFile = $cDir . '/' . md5($url) . '.html';
    if (file_exists($cFile) && (time() - filemtime($cFile)) < $cacheSec) {
        $cached = @file_get_contents($cFile);
        if ($cached && strlen($cached) > 500) return $cached;
    }

    // Provider-Chain
    $html = fetchViaIproyal($url, 15);
    if (!$html) $html = fetchViaBrightData($url, 25);
    if (!$html) $html = fetchViaApify($url, 30);

    if ($html && strlen($html) > 500) @file_put_contents($cFile, $html);
    return $html;
}

/**
 * Apify Actor-basiertes URL-Fetching (last-resort fallback).
 */
function fetchViaApify(string $url, int $timeoutSec = 45): ?string {
    if (!defined('APIFY_API_TOKEN') || !APIFY_API_TOKEN) return null;
    $apiUrl = "https://api.apify.com/v2/acts/apify~cheerio-scraper/run-sync-get-dataset-items?token=" . APIFY_API_TOKEN . "&timeout=$timeoutSec";
    $payload = [
        'startUrls' => [['url' => $url]],
        'maxRequestsPerCrawl' => 1,
        'maxConcurrency' => 1,
        'proxyConfiguration' => ['useApifyProxy' => true, 'apifyProxyGroups' => ['RESIDENTIAL'], 'apifyProxyCountry' => 'DE'],
        'pageFunction' => 'async function pageFunction(ctx) { return { url: ctx.request.url, html: ctx.$.html() }; }',
    ];
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => $timeoutSec + 5,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) return null;
    $items = json_decode($resp, true);
    return $items[0]['html'] ?? null;
}

/**
 * Legacy proxy-mode (für Scripts die nicht auf Apify Actor wechseln wollen).
 * Bleibt als Fallback wenn APIFY_PROXY_PASSWORD gesetzt wird.
 */
function applyApifyProxy($ch): void {
    if (!defined('APIFY_PROXY_PASSWORD') || !APIFY_PROXY_PASSWORD) return;
    curl_setopt($ch, CURLOPT_PROXY, 'http://proxy.apify.com:8000');
    curl_setopt($ch, CURLOPT_PROXYUSERPWD, 'groups-RESIDENTIAL,country-DE:' . APIFY_PROXY_PASSWORD);
    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
}

function fetchLeadDetails(string $url): ?array {
    // Smart chain: Cache → IPRoyal → BrightData → Apify
    $html = fetchSmart($url);
    if (!$html) return null;

    // Beschreibung aus #viewad-description-text
    $fullText = '';
    if (preg_match('#<p[^>]*id="viewad-description-text"[^>]*>(.*?)</p>#is', $html, $m)) {
        $fullText = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
    } elseif (preg_match('#<section[^>]*class="[^"]*viewad-description[^"]*"[^>]*>(.*?)</section>#is', $html, $m)) {
        $fullText = html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES, 'UTF-8');
    }

    // Kontakt-Name
    $name = null;
    if (preg_match('#<span[^>]*class="[^"]*userprofile-vip[^"]*"[^>]*>(.*?)</span>#is', $html, $m)) {
        $name = trim(strip_tags($m[1]));
    } elseif (preg_match('#<a[^>]*href="/s-bestandsliste\.html[^"]*"[^>]*>([^<]+)</a>#i', $html, $m)) {
        $name = trim($m[1]);
    }

    // Bezirk / Ort
    $district = null;
    if (preg_match('#<span[^>]*id="viewad-locality"[^>]*>(.*?)</span>#is', $html, $m)) {
        $district = trim(strip_tags($m[1]));
    }

    // Email + Phone aus Volltext (oft im Body versteckt)
    $combined = $fullText . ' ' . $html;
    $email = extractEmail($fullText); // nur aus sichtbarem Text
    $phone = extractPhone($fullText);

    return [
        'email' => $email,
        'phone' => $phone,
        'name'  => $name,
        'district' => $district,
        'full_text' => $fullText,
    ];
}

// ============================================================
// Run scraping
// ============================================================
@set_time_limit(180);
$totalNew = 0;
$totalSeen = 0;
$totalDead = 0;
$results = [];

// AUTO-DEAD-CHECK: bevor wir neue Leads scrapen, prüfe ob bestehende Leads
// noch erreichbar sind. Max 80 pro Run (die ältesten-verifizierten zuerst).
$deadMarkers = [
    'Anzeige nicht mehr verfügbar', 'Die Anzeige wurde entfernt', 'Anzeige existiert nicht',
    'wurde gelöscht', 'bereits reserviert', 'no longer available', 'viewad-not-found',
];
$checkBatch = all("SELECT lead_id, source_url, notes FROM leads
                   WHERE status='new' AND source_url LIKE 'http%'
                   AND (notes NOT LIKE '%[VERIFIED:%' OR notes IS NULL OR
                        notes LIKE CONCAT('%[VERIFIED:', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 3 DAY), '%Y-%m-%d'), '%')
                        OR notes REGEXP '\\\\[VERIFIED:20[0-9]{2}-[0-1][0-9]-[0-3][0-9]\\\\]')
                   ORDER BY lead_id ASC LIMIT 80");
foreach ($checkBatch as $ld) {
    // Dead-Check nutzt Cache wenn vorhanden (spart 0.002$+ pro Request bei BrightData/Apify)
    $h = fetchSmart($ld['source_url'], 43200); // 12h cache für dead-check
    $dead = false;
    if (!$h) $dead = true; // gar kein HTML → wahrscheinlich tot
    if ($h) foreach ($deadMarkers as $m) { if (stripos($h, $m) !== false) { $dead = true; break; } }
    if ($dead) {
        q("DELETE FROM leads WHERE lead_id=?", [$ld['lead_id']]);
        $totalDead++;
    } else if ($h) {
        // Mark as verified (remove old verified stamp, add new one)
        $newNotes = preg_replace('/\s*\[VERIFIED:[^\]]+\]/', '', $ld['notes'] ?? '') . ' [VERIFIED:' . date('Y-m-d') . ']';
        q("UPDATE leads SET notes=? WHERE lead_id=?", [trim($newNotes), $ld['lead_id']]);
    }
    usleep(120000);
}

// ============================================================
// Direkt-Scrape von kleinanzeigen.de (unabhängig von VPS SearXNG)
// Kleinanzeigen-Suche liefert deutlich verlässlicher echte Leads.
// ============================================================
// Kleinanzeigen-Suche nur in "Gesuche" (adType=REQUEST) — das sind Leute die
// Dienstleistungen SUCHEN, nicht anbieten. l3331 = Berlin. Mit "gesucht"-Keyword.
$kleinanzeigenSearches = [
    'haushalt' => ['reinigungsfirma+gesucht', 'putzhilfe+gesucht', 'haushaltshilfe+gesucht', 'wohnungsreinigung+gesucht', 'endreinigung+wohnung'],
    'airbnb'   => ['airbnb+reinigung', 'ferienwohnung+reinigung+gesucht', 'turnover+cleaning', 'gästewechsel+reinigung'],
    'cohost'   => ['airbnb+co-host', 'ferienwohnung+verwaltung', 'airbnb+management'],
    'buero'    => ['büroreinigung+gesucht', 'gewerbereinigung+gesucht', 'praxisreinigung+gesucht'],
];
foreach ($kleinanzeigenSearches as $category => $terms) {
    foreach ($terms as $term) {
        // adType=REQUEST = nur Gesuche; l3331 = Berlin; sortingField=SORTING_DATE = neueste zuerst
        $url = 'https://www.kleinanzeigen.de/s-berlin/' . $term . '/k0l3331?adType=REQUEST&sortingField=SORTING_DATE';
        // Search-Pages kurze Cache-TTL (2h) — Details länger (24h)
        $html = fetchSmart($url, 7200);
        if (!$html) continue;

        // Pro <article class="aditem"> parsen: Titel + posted-Date ("Heute, 14:32" | "Gestern, 09:15" | "15.04.2026")
        if (preg_match_all('#<article[^>]*class="[^"]*aditem[^"]*"[^>]*>(.*?)</article>#is', $html, $articles)) {
            foreach (array_slice($articles[1], 0, 25) as $art) {
                // URL + Titel
                if (!preg_match('#href="(/s-anzeige/[^"]+)"[^>]*>([^<]+)#i', $art, $tMatch)) continue;
                $leadUrl = 'https://www.kleinanzeigen.de' . $tMatch[1];
                $title = html_entity_decode(trim($tMatch[2]), ENT_QUOTES, 'UTF-8');
                $totalSeen++;
                if (val("SELECT lead_id FROM leads WHERE source_url=? LIMIT 1", [$leadUrl])) continue;

                // Posted-Date extrahieren
                $postedAt = null;
                if (preg_match('#(Heute|Gestern|\d{2}\.\d{2}\.\d{4})(?:,?\s*(\d{2}:\d{2}))?#u', $art, $dMatch)) {
                    $dStr = $dMatch[1];
                    $tStr = $dMatch[2] ?? '00:00';
                    if ($dStr === 'Heute') $postedAt = date('Y-m-d') . ' ' . $tStr . ':00';
                    elseif ($dStr === 'Gestern') $postedAt = date('Y-m-d', strtotime('-1 day')) . ' ' . $tStr . ':00';
                    else $postedAt = date('Y-m-d', strtotime(str_replace('.', '-', $dStr) . ' 2026')) . ' ' . $tStr . ':00';
                }

                // Snippet aus dem Artikel (description)
                $snippet = $title;
                if (preg_match('#<p[^>]*aditem-main--middle--description[^>]*>(.*?)</p>#is', $art, $sMatch)) {
                    $snippet = html_entity_decode(trim(strip_tags($sMatch[1])), ENT_QUOTES, 'UTF-8');
                }

                try {
                    // OSINT-Enrichment: Ad-Seite fetchen und Kontaktdaten extrahieren
                    $details = fetchLeadDetails($leadUrl);
                    usleep(300000); // 0.3s throttle pro Ad-Fetch
                    $email = $details['email'] ?? null;
                    $phone = $details['phone'] ?? null;
                    $district = $details['district'] ?? null;
                    $contactName = $details['name'] ?? null;
                    $fullText = $details['full_text'] ?? $snippet;

                    $seg = ($category === 'buero') ? 'B2B' : 'B2C';
                    $notes = "[$seg]" . ($postedAt ? " [POSTED:$postedAt]" : '') . ($district ? " [BEZIRK:$district]" : '') . ($contactName ? " [KONTAKT:$contactName]" : '');
                    q("INSERT INTO leads (source, source_url, category, name, email, phone, city, notes, raw_snippet) VALUES (?, ?, ?, ?, ?, ?, 'Berlin', ?, ?)",
                      ['kleinanzeigen.de', $leadUrl, $category, $title, $email, $phone, $notes, substr($fullText, 0, 1500)]);
                    $totalNew++;
                    $results[] = ['category'=>$category, 'title'=>substr($title,0,80), 'url'=>$leadUrl, 'posted_at'=>$postedAt, 'has_contact'=>(bool)($email || $phone)];
                } catch (Exception $e) {}
            }
        }
        usleep(500000); // 0.5s throttle
    }
}

// ============================================================
// GOOGLE PLACES (B2B-Leads): Berlin-Businesses die potentiell
// Reinigung brauchen — Airbnb/Hotels/Büros/Praxen. Outbound-Leads.
// Benötigt GOOGLE_PLACES_API_KEY in includes/google-keys.php.
// ============================================================
if (defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY) {
    $placesSearches = [
        'airbnb' => ['Ferienwohnung Berlin', 'Airbnb Berlin Mitte', 'Apartments kurzzeit Berlin'],
        'buero'  => ['Coworking Berlin', 'Bürogemeinschaft Berlin Mitte', 'Arztpraxis Berlin Mitte', 'Anwaltskanzlei Berlin'],
    ];
    foreach ($placesSearches as $category => $qs) {
        foreach ($qs as $q) {
            $url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?query=' . urlencode($q) . '&region=de&key=' . GOOGLE_PLACES_API_KEY;
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
            $resp = curl_exec($ch); curl_close($ch);
            $d = json_decode($resp, true);
            if (!is_array($d) || empty($d['results'])) continue;
            foreach (array_slice($d['results'], 0, 15) as $p) {
                $totalSeen++;
                $placeId = $p['place_id'] ?? '';
                $placeUrl = $placeId ? "https://www.google.com/maps/place/?q=place_id:$placeId" : '';
                if (!$placeUrl) continue;
                if (val("SELECT lead_id FROM leads WHERE source_url=? LIMIT 1", [$placeUrl])) continue;
                // Details holen für Phone
                $phone = null;
                if ($placeId) {
                    $detUrl = 'https://maps.googleapis.com/maps/api/place/details/json?place_id=' . $placeId . '&fields=formatted_phone_number,international_phone_number,website&key=' . GOOGLE_PLACES_API_KEY;
                    $ch2 = curl_init($detUrl);
                    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false]);
                    $dresp = curl_exec($ch2); curl_close($ch2);
                    $dd = json_decode($dresp, true);
                    $phone = $dd['result']['international_phone_number'] ?? $dd['result']['formatted_phone_number'] ?? null;
                    $website = $dd['result']['website'] ?? null;
                }
                $title = $p['name'] ?? 'Berlin Business';
                $addr = $p['formatted_address'] ?? '';
                $seg = 'B2B';
                $notes = "[$seg] [SOURCE:google_places]" . ($website ? " [WEBSITE:$website]" : '');
                $snippet = $title . ' · ' . $addr . ($p['rating'] ?? '' ? ' · ⭐' . $p['rating'] : '');
                try {
                    q("INSERT INTO leads (source, source_url, category, name, email, phone, city, notes, raw_snippet) VALUES ('google_places', ?, ?, ?, NULL, ?, 'Berlin', ?, ?)",
                      [$placeUrl, $category, $title, $phone, $notes, substr($snippet, 0, 500)]);
                    $totalNew++;
                    $results[] = ['category'=>$category, 'title'=>$title, 'url'=>$placeUrl, 'source'=>'google_places', 'has_contact'=>(bool)$phone];
                } catch (Exception $e) {}
            }
            usleep(300000);
        }
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

            // Skip aggregator/spam/competitor/government/job-board domains
            $skipDomains = [
                // Suchmaschinen + Portale
                'google.com', 'wikipedia', 'youtube.com', 'amazon.', 'pinterest.', 'facebook.com/marketplace',
                // Job-Boards (Leute die Arbeit suchen, nicht Kunden)
                'jooble.org', 'indeed.', 'stepstone.de', 'stellenanzeigen.de', 'monster.de', 'xing.com', 'linkedin.com',
                'adzuna.de', 'kimeta.de', 'meinestadt.de', 'jobruf.de', 'joblift.de', 'stellenmarkt.de',
                // Behörden / Gesetze / Gewerkschaften
                'rki.de', 'bmg.bund.de', 'bundesregierung.de', 'gesetze-im-internet.de', 'bzga.de',
                'dpolg.', 'verdi.de', 'dgb.de', 'beamtenbund.de',
                // Konkurrenten Reinigungsfirmen
                'helpling.de', 'book-a-tiger.com', 'desomax.de', 'alfa24.de', 'swc-berlin.de',
                'clean4you.', 'cleanpower', 'reinigungsfirma-berlin.de', 'gebäudereinigung.',
                // Branchen-Portale
                'gelbeseiten.de', 'dasoertliche.de', '11880.com', 'wlw.de', 'gewerbe24.de',
                'praxiswaesche.de', 'berufskleidung.', 'hygieneplan.de',
            ];
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
    'total_dead' => $totalDead,
    'results' => array_slice($results, 0, 30),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
