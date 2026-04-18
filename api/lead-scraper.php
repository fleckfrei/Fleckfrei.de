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
// Kleinanzeigen-Scrape ENTFERNT (2026-04-18):
// Die Suche dort mischt Angebote (Konkurrenten/Jobsucher) mit Gesuchen
// und bringt zu 90%+ Rauschen. Fleckfrei = Dienstleister und sucht
// zahlende Kunden (Hosts, Vermieter, Hotels, Ferienwohnungen) —
// NICHT andere Reinigungsfirmen oder Putzhilfen die sich selbst anbieten.
// Statt dessen: Customer-Hunting weiter unten (Apify Airbnb/Booking +
// Google Places + Manuell).
// ============================================================

// ============================================================
// CUSTOMER HUNTING (das was Geld bringt): Airbnb-Hosts, Booking-Properties,
// Hotels, Ferienwohnungen, Business-Adressen in Berlin die REGELMÄSSIG
// Reinigung/Transport/Reparatur brauchen.
// Fleckfrei.de verkauft — sucht nicht Subcontractoren.
// ============================================================

// --- AIRBNB Berlin Hosts via Apify (jedes Listing = potentieller Kunde)
if (defined('APIFY_API_TOKEN') && APIFY_API_TOKEN) {
    // tri_angle/new-fast-airbnb-scraper — Berlin, ersten 100 Listings
    $apiUrl = 'https://api.apify.com/v2/acts/tri_angle~new-fast-airbnb-scraper/run-sync-get-dataset-items?token=' . APIFY_API_TOKEN . '&timeout=120';
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 130,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'startUrls' => [['url' => 'https://www.airbnb.de/s/Berlin--Germany/homes']],
            'currency' => 'EUR', 'locale' => 'de-DE', 'maxListings' => 120,
            'proxyConfig' => ['useApifyProxy' => true, 'apifyProxyGroups' => ['RESIDENTIAL']],
        ]),
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    $items = json_decode($resp, true);
    if (is_array($items)) {
        foreach ($items as $p) {
            $totalSeen++;
            $listUrl = $p['url'] ?? ($p['link'] ?? '');
            if (!$listUrl) continue;
            if (val("SELECT lead_id FROM leads WHERE source_url=? LIMIT 1", [$listUrl])) continue;
            $title = $p['name'] ?? $p['title'] ?? 'Airbnb Berlin';
            $hostName = $p['primaryHost']['name'] ?? $p['host']['name'] ?? '';
            $district = $p['location']['city'] ?? ($p['locationDetails']['subdistrict'] ?? 'Berlin');
            $price = $p['pricing']['price'] ?? ($p['price'] ?? 0);
            $beds = $p['beds'] ?? 0;
            $rating = $p['stars'] ?? ($p['rating'] ?? 0);
            $reviews = $p['numberOfReviews'] ?? ($p['reviewsCount'] ?? 0);
            $snippet = "$title · Host: $hostName · $beds Beds · €$price/Nacht · ⭐$rating ($reviews Bewertungen)";
            $notes = "[B2C] [SOURCE:airbnb]" . ($hostName ? " [HOST:$hostName]" : '') . ($district ? " [BEZIRK:$district]" : '');
            try {
                q("INSERT INTO leads (source, source_url, category, name, email, phone, city, notes, raw_snippet) VALUES ('airbnb', ?, 'airbnb', ?, NULL, NULL, 'Berlin', ?, ?)",
                  [$listUrl, substr($title, 0, 200), $notes, substr($snippet, 0, 600)]);
                $totalNew++;
                $results[] = ['category'=>'airbnb','title'=>$title,'url'=>$listUrl,'source'=>'airbnb','has_contact'=>false];
            } catch (Exception $e) {}
        }
    }
}

// --- BOOKING.COM Berlin Properties via Apify
if (defined('APIFY_API_TOKEN') && APIFY_API_TOKEN) {
    $apiUrl = 'https://api.apify.com/v2/acts/voyager~booking-scraper/run-sync-get-dataset-items?token=' . APIFY_API_TOKEN . '&timeout=120';
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 130,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'search' => 'Berlin', 'destType' => 'city', 'maxPages' => 4,
            'currency' => 'EUR', 'language' => 'de',
            'proxyConfig' => ['useApifyProxy' => true, 'apifyProxyGroups' => ['RESIDENTIAL']],
        ]),
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    $items = json_decode($resp, true);
    if (is_array($items)) {
        foreach ($items as $p) {
            $totalSeen++;
            $pUrl = $p['url'] ?? '';
            if (!$pUrl) continue;
            if (val("SELECT lead_id FROM leads WHERE source_url=? LIMIT 1", [$pUrl])) continue;
            $title = $p['name'] ?? 'Booking-Property Berlin';
            $type = $p['type'] ?? '';
            $stars = $p['stars'] ?? 0;
            $rating = $p['rating'] ?? 0;
            $price = $p['price'] ?? 0;
            $addr = $p['address']['full'] ?? ($p['address']['street'] ?? '');
            $district = $p['address']['city'] ?? 'Berlin';
            $category = $type && stripos($type, 'hotel') !== false ? 'buero' : 'airbnb';
            $snippet = "$title · $type · $district · €$price · ⭐$rating · $stars★";
            $notes = "[B2B] [SOURCE:booking]" . ($district ? " [BEZIRK:$district]" : '') . ($addr ? " [ADDR:$addr]" : '');
            try {
                q("INSERT INTO leads (source, source_url, category, name, email, phone, city, notes, raw_snippet) VALUES ('booking.com', ?, ?, ?, NULL, NULL, 'Berlin', ?, ?)",
                  [$pUrl, $category, substr($title, 0, 200), $notes, substr($snippet, 0, 600)]);
                $totalNew++;
                $results[] = ['category'=>$category,'title'=>$title,'url'=>$pUrl,'source'=>'booking.com','has_contact'=>false];
            } catch (Exception $e) {}
        }
    }
}

// --- GOOGLE PLACES (Business-Leads): Hotels, Ferienwohnungen, Coworking, Praxen, Kanzleien
if (defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY) {
    $placesSearches = [
        'airbnb' => ['Ferienwohnung Berlin', 'Apartments Berlin Mitte', 'Short Term Rental Berlin', 'Aparthotel Berlin', 'Serviced Apartments Berlin'],
        'buero'  => ['Hotel Berlin Mitte', 'Hotel Berlin Charlottenburg', 'Hostel Berlin', 'Pension Berlin', 'Coworking Berlin', 'Arztpraxis Berlin Mitte', 'Anwaltskanzlei Berlin', 'Yoga Studio Berlin'],
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

// SearXNG-Such-Pfad ENTFERNT (2026-04-18):
// Brachte 90%+ Müll: Konkurrenten, Jobsucher, Behörden, SEO-Artikel,
// Branchen-Portale. Customer-Hunting (Airbnb/Booking/Places oben)
// liefert 100% echte B2B/B2C-Targets die Reinigung brauchen.
// $queries-Variable wird nicht mehr verwendet — nur noch als Referenz
// für manuell gepflegte Keywords falls jemand das reaktivieren will.

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
