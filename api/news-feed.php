<?php
/**
 * Berlin News Feed — server-side RSS fetcher with cache.
 * Eliminates dependency on rss2json (which is rate-limited / unreliable).
 *
 * GET /api/news-feed.php
 * Returns: { success, source, items: [{ title, link, source, date }] }
 *
 * Cache: /tmp/fleckfrei_news_cache.json (15 minutes TTL)
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=900');

$cacheTtl = 15 * 60; // 15 minutes

// Optional Bezirk filter — matches article titles against keyword list
$bezirk = strtolower(trim($_GET['bezirk'] ?? ''));
$bezirkMap = [
    'mitte'           => ['mitte', 'alexanderplatz', 'hackescher', 'museumsinsel', 'brandenburger tor', 'regierungsviertel', 'tiergarten'],
    'kreuzberg'       => ['kreuzberg', 'bergmannkiez', 'oranienstraße', 'görli', 'görlitzer park', 'checkpoint charlie'],
    'friedrichshain'  => ['friedrichshain', 'simon-dach', 'boxhagener', 'mercedes-benz-arena', 'rummelsburg', 'ostbahnhof'],
    'prenzlauer_berg' => ['prenzlauer berg', 'prenzlberg', 'kollwitzplatz', 'helmholtzplatz', 'mauerpark'],
    'charlottenburg'  => ['charlottenburg', 'kurfürstendamm', 'kudamm', 'savignyplatz', 'schloss charlottenburg'],
    'wilmersdorf'     => ['wilmersdorf', 'fasanenstraße', 'preußenpark'],
    'neukoelln'       => ['neukölln', 'neukoelln', 'sonnenallee', 'weserstraße', 'tempelhofer feld', 'hermannplatz'],
    'schoeneberg'     => ['schöneberg', 'schoeneberg', 'nollendorfplatz', 'winterfeldtplatz'],
    'pankow'          => ['pankow'],
    'tempelhof'       => ['tempelhof', 'tempelhofer feld'],
    'steglitz'        => ['steglitz', 'schloßstraße'],
    'spandau'         => ['spandau', 'altstadt spandau'],
    'reinickendorf'   => ['reinickendorf', 'tegel'],
    'marzahn'         => ['marzahn', 'hellersdorf'],
    'lichtenberg'     => ['lichtenberg', 'rummelsburg', 'karlshorst'],
    'treptow'         => ['treptow', 'köpenick', 'koepenick', 'treptower park'],
];
$bezirkKeywords = $bezirkMap[$bezirk] ?? [];

// Cache key includes bezirk so filtered results get their own cache
$cacheFile = sys_get_temp_dir() . '/fleckfrei_news_cache' . ($bezirk ? '_' . $bezirk : '') . '.json';

// Serve cached result if fresh
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    readfile($cacheFile);
    exit;
}

// Berlin-focused RSS sources (in order of preference)
$sources = [
    ['url' => 'https://www.tagesspiegel.de/contentexport/feed/berlin',     'name' => 'Tagesspiegel Berlin'],
    ['url' => 'https://www.berliner-zeitung.de/feed.xml',                  'name' => 'Berliner Zeitung'],
    ['url' => 'https://www.welt.de/feeds/topnews.rss',                     'name' => 'Welt'],
];

function fetchUrl(string $url, int $timeout = 8): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FleckfreiNewsBot/1.0)',
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300 && $resp) ? $resp : null;
}

function parseRss(string $xml): array {
    $items = [];
    // Suppress XML parsing errors, use libxml internal errors
    $prevErr = libxml_use_internal_errors(true);
    try {
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
        if (!$doc) return [];
        // RSS 2.0
        if (isset($doc->channel->item)) {
            foreach ($doc->channel->item as $item) {
                $items[] = [
                    'title' => trim(strip_tags((string)$item->title)),
                    'link'  => (string)$item->link,
                    'pubDate' => (string)$item->pubDate,
                ];
            }
        }
        // Atom
        elseif (isset($doc->entry)) {
            foreach ($doc->entry as $entry) {
                $link = '';
                foreach ($entry->link as $l) {
                    if ((string)$l['rel'] === 'alternate' || empty($link)) $link = (string)$l['href'];
                }
                $items[] = [
                    'title' => trim(strip_tags((string)$entry->title)),
                    'link'  => $link,
                    'pubDate' => (string)($entry->published ?? $entry->updated),
                ];
            }
        }
    } catch (Exception $e) {}
    libxml_clear_errors();
    libxml_use_internal_errors($prevErr);
    return $items;
}

// Fetch ALL sources and merge — better diversity than single-source fallback
$merged = [];
$usedSources = [];

// When filtering by bezirk, we take more items per source so we have enough after filtering
$perSourceLimit = $bezirkKeywords ? 50 : 8;

foreach ($sources as $src) {
    $xml = fetchUrl($src['url']);
    if (!$xml) continue;
    $items = parseRss($xml);
    if (empty($items)) continue;
    $usedSources[] = $src['name'];

    foreach (array_slice($items, 0, $perSourceLimit) as $it) {
        $ts = $it['pubDate'] ? strtotime($it['pubDate']) : false;
        // Skip items without a real timestamp so merge ordering stays correct
        if (!$ts) continue;
        // Skip obvious non-articles (empty titles, stub "vorlagen")
        $title = $it['title'];
        if (strlen($title) < 8) continue;
        if (stripos($title, 'vorlage') !== false) continue;

        // Bezirk filter — match any keyword in title (case-insensitive)
        if ($bezirkKeywords) {
            $titleLower = mb_strtolower($title);
            $matched = false;
            foreach ($bezirkKeywords as $kw) {
                if (mb_strpos($titleLower, $kw) !== false) { $matched = true; break; }
            }
            if (!$matched) continue;
        }

        $merged[] = [
            'title'     => $title,
            'link'      => $it['link'],
            'source'    => $src['name'],
            'date'      => date('d.m. H:i', $ts),
            'timestamp' => $ts,
        ];
    }
}

if (!empty($merged)) {
    // Newest first, limit 18
    usort($merged, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
    $merged = array_slice($merged, 0, 18);

    $result = [
        'success'    => true,
        'source'     => implode(' · ', $usedSources),
        'sources'    => $usedSources,
        'bezirk'     => $bezirk ?: null,
        'items'      => $merged,
        'fetched_at' => date('Y-m-d H:i:s'),
    ];
} else {
    $result = ['success' => false, 'items' => [], 'source' => 'offline', 'bezirk' => $bezirk ?: null];
}

$json = json_encode($result, JSON_UNESCAPED_UNICODE);
@file_put_contents($cacheFile, $json);
echo $json;
