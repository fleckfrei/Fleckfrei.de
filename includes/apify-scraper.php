<?php
/**
 * Apify Scraper Helper — 100% undetectable scraping for STR platforms.
 * Uses Apify Actors (pre-built anti-bot solutions).
 * Actors used:
 *   - tri_angle/new-fast-airbnb-scraper (Airbnb)
 *   - voyager/booking-scraper (Booking.com)
 *   - voyager/vrbo-scraper (VRBO)
 *   - agoda/agoda-scraper (Agoda — fallback via Browse & save)
 *
 * Usage: apifyScrape($platform, $url) → array with title, description, reviews, etc.
 */

if (!defined('APIFY_LOADED')) {
    define('APIFY_LOADED', true);

    // Actor-Map per platform. Airbnb is intentionally SKIPPED (direct OG-scrape works + is faster)
    function apifyActor(string $platform): ?string {
        return match ($platform) {
            'booking'      => 'voyager/booking-scraper',
            'vrbo'         => 'voyager/vrbo-scraper',
            'fewo-direkt'  => 'voyager/vrbo-scraper',
            'expedia'      => 'apify/expedia-scraper',
            'agoda'        => 'tri_angle/agoda-scraper',
            default        => null,   // airbnb, travelminit, etc. → fallback to direct scrape
        };
    }

    /**
     * Run Apify Actor synchronously + wait for result
     * Returns [title, description, reviews[], price_per_night, rating, reviews_count, beds, baths, guests, sqm]
     */
    function apifyScrape(string $platform, string $url, int $timeout = 90): ?array {
        if (!defined('APIFY_API_TOKEN') || !APIFY_API_TOKEN) {
            return ['error' => 'APIFY_API_TOKEN not configured'];
        }
        $actorId = apifyActor($platform);
        if (!$actorId) return ['error' => "No actor for platform: $platform"];

        // Platform-specific input payload
        $input = match ($platform) {
            'airbnb' => [
                'startUrls' => [['url' => $url]],
                'currency' => 'EUR',
                'locale' => 'de-DE',
                'maxListings' => 1,
                'proxyConfig' => ['useApifyProxy' => true, 'apifyProxyGroups' => ['RESIDENTIAL']],
            ],
            'booking' => [
                'startUrls' => [['url' => $url]],
                'currency' => 'EUR',
                'language' => 'de',
                'maxPagesPerQuery' => 1,
                'proxyConfig' => ['useApifyProxy' => true, 'apifyProxyGroups' => ['RESIDENTIAL']],
            ],
            'vrbo', 'fewo-direkt' => [
                'startUrls' => [['url' => $url]],
                'maxItems' => 1,
                'proxyConfig' => ['useApifyProxy' => true],
            ],
            default => ['startUrls' => [['url' => $url]]],
        };

        // Start run (sync = waits until finished)
        $apiUrl = "https://api.apify.com/v2/acts/" . str_replace('/', '~', $actorId) . "/run-sync-get-dataset-items?token=" . APIFY_API_TOKEN . "&timeout=$timeout&memory=1024";

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($input),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $timeout + 5,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$resp || $code >= 400) {
            return ['error' => "Apify HTTP $code", 'raw' => substr($resp ?? '', 0, 500)];
        }
        $items = json_decode($resp, true);
        if (!is_array($items) || empty($items)) return ['error' => 'empty result'];

        // Normalize first item
        $item = $items[0];
        return normalizeApifyResult($platform, $item);
    }

    function normalizeApifyResult(string $platform, array $item): array {
        // Different actors return different schemas — normalize to common shape
        $out = [
            'platform' => $platform,
            'title' => null,
            'description' => null,
            'price_per_night_eur' => null,
            'rating' => null,
            'reviews_count' => null,
            'beds' => null,
            'baths' => null,
            'guests' => null,
            'sqm' => null,
            'reviews' => [],
            '_raw_keys' => array_keys($item),
        ];

        if ($platform === 'airbnb') {
            $out['title'] = $item['name'] ?? $item['title'] ?? null;
            $out['description'] = $item['description'] ?? $item['summary'] ?? null;
            $out['rating'] = (float) ($item['stars'] ?? $item['rating'] ?? 0) ?: null;
            $out['reviews_count'] = (int) ($item['numberOfReviews'] ?? $item['reviewsCount'] ?? 0) ?: null;
            $out['price_per_night_eur'] = (float) ($item['pricing']['price'] ?? $item['price'] ?? 0) ?: null;
            $out['beds'] = (int) ($item['beds'] ?? 0) ?: null;
            $out['baths'] = (float) ($item['baths'] ?? $item['bathrooms'] ?? 0) ?: null;
            $out['guests'] = (int) ($item['personCapacity'] ?? $item['guestCapacity'] ?? 0) ?: null;
            if (!empty($item['reviews']) && is_array($item['reviews'])) {
                foreach (array_slice($item['reviews'], 0, 15) as $r) {
                    $out['reviews'][] = mb_substr($r['comments'] ?? $r['text'] ?? '', 0, 400);
                }
            }
        } elseif ($platform === 'booking') {
            $out['title'] = $item['name'] ?? null;
            $out['description'] = $item['description'] ?? null;
            // Booking uses 0-10 scale → convert to 0-5
            $bookingRating = (float) ($item['rating'] ?? 0);
            $out['rating'] = $bookingRating ? round($bookingRating / 2, 2) : null;
            $out['price_per_night_eur'] = null;
            if (isset($item['price']) && is_numeric($item['price'])) $out['price_per_night_eur'] = (float)$item['price'];
            if (!empty($item['address']['city'])) $out['location_hint'] = $item['address']['city'];
            if (!empty($item['stars'])) $out['hotel_stars'] = (int)$item['stars'];
            $out['type'] = $item['type'] ?? null;
            if (!empty($item['reviews']) && is_array($item['reviews'])) {
                foreach (array_slice($item['reviews'], 0, 15) as $r) {
                    $neg = $r['negative'] ?? $r['negativeText'] ?? '';
                    $pos = $r['positive'] ?? $r['positiveText'] ?? '';
                    $txt = trim(($neg ? "[NEG] $neg " : '') . ($pos ? "[POS] $pos" : ''));
                    if ($txt) $out['reviews'][] = $txt;
                }
            }
            if (!empty($item['rooms']) && is_array($item['rooms']) && isset($item['rooms'][0]['beds'])) {
                $out['beds'] = (int)$item['rooms'][0]['beds'];
            }
        } else {
            $out['title'] = $item['title'] ?? $item['name'] ?? null;
            $out['description'] = $item['description'] ?? null;
            $out['rating'] = (float) ($item['rating'] ?? 0) ?: null;
        }

        return $out;
    }
}
