<?php
/**
 * Lead Radar — real-time scan for potential customers in Berlin
 * Searches: Kleinanzeigen, Google, Social Media, Competitor reviews
 * Finds: people actively looking for cleaning services RIGHT NOW
 *
 * GET /api/lead-radar.php?cron=flk_scrape_2026  (cron: every 6 hours)
 * GET /api/lead-radar.php                        (admin: manual trigger)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/llm-helpers.php';
header('Content-Type: application/json; charset=utf-8');

session_start();
$isAdmin = (($_SESSION['utype'] ?? '') === 'admin');
$isCron = ($_GET['cron'] ?? '') === (defined('CRON_SECRET') ? CRON_SECRET : 'flk_scrape_2026');
if (!$isAdmin && !$isCron && php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$startTime = microtime(true);

// ============================================================
// SEARCH QUERIES — what potential customers post online
// ============================================================
$queries = [
    // Direct demand signals (German)
    'Reinigungskraft gesucht Berlin',
    'Putzfrau gesucht Berlin',
    'Reinigung Wohnung Berlin kurzfristig',
    'Airbnb Reinigung Berlin Turnover',
    'Ferienwohnung Reinigung Berlin',
    'Büroreinigung Berlin gesucht',
    'Unterhaltsreinigung Berlin Angebot',
    'Hausmeisterservice Berlin gesucht',
    // Competitor dissatisfaction
    'Helpling Erfahrung schlecht Berlin',
    'Book a Tiger Alternative Berlin',
    'Reinigungsfirma wechseln Berlin',
    // Kleinanzeigen / Marktplatz
    'site:kleinanzeigen.de Reinigungskraft Berlin',
    'site:kleinanzeigen.de Putzfrau Berlin',
    // Social media demand
    'suche Putzfrau Berlin Facebook',
    'Reinigungskraft empfehlung Berlin',
];

$allLeads = [];
$errors = [];

// Run searches via VPS SearXNG
foreach (array_slice($queries, 0, 8) as $qi => $searchQuery) {
    try {
        $resp = vps_call('searxng', [
            'query' => $searchQuery,
            'categories' => 'general',
            'limit' => 15,
            'time_range' => 'week', // Only last 7 days
        ], false); // No cache — we want fresh results

        if (!is_array($resp) || empty($resp['results'])) continue;

        foreach ($resp['results'] as $r) {
            $title = $r['title'] ?? '';
            $snippet = $r['snippet'] ?? $r['content'] ?? '';
            $url = $r['url'] ?? '';
            if (!$url || !$title) continue;

            // Skip our own site
            if (str_contains($url, 'fleckfrei.de')) continue;

            // Categorize the lead
            $category = 'general';
            $priority = 'low';
            $source = parse_url($url, PHP_URL_HOST) ?: '';

            // High priority: direct requests for cleaning
            if (preg_match('/gesucht|suche|brauche|dringend|sofort|kurzfristig/i', $title . $snippet)) {
                $priority = 'high';
                $category = 'direct_demand';
            }
            // Medium: competitor complaints
            elseif (preg_match('/schlecht|enttäuscht|Alternative|wechsel|teuer|unzufrieden/i', $title . $snippet)) {
                $priority = 'medium';
                $category = 'competitor_switch';
            }
            // Kleinanzeigen posts
            elseif (str_contains($url, 'kleinanzeigen.de')) {
                $priority = 'high';
                $category = 'marketplace';
            }
            // Airbnb/Host specific
            elseif (preg_match('/airbnb|ferienwohnung|turnover|check-out/i', $title . $snippet)) {
                $priority = 'medium';
                $category = 'host_demand';
            }

            // Extract contact info from snippet
            $phone = null; $email = null;
            if (preg_match('/(\+?49[\s\-]?\d[\d\s\-]{8,})/', $snippet, $pm)) $phone = trim($pm[1]);
            if (preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $snippet, $em)) $email = $em[0];

            $allLeads[] = [
                'title' => mb_substr($title, 0, 150),
                'snippet' => mb_substr($snippet, 0, 300),
                'url' => $url,
                'source' => $source,
                'category' => $category,
                'priority' => $priority,
                'phone' => $phone,
                'email' => $email,
                'search_query' => $searchQuery,
                'found_at' => date('Y-m-d H:i:s'),
            ];
        }
    } catch (Exception $e) {
        $errors[] = "Query '$searchQuery': " . $e->getMessage();
    }

    usleep(500000); // 0.5s between queries to avoid rate limiting
}

// Deduplicate by URL
$seen = [];
$uniqueLeads = [];
foreach ($allLeads as $lead) {
    $key = md5($lead['url']);
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        $uniqueLeads[] = $lead;
    }
}

// Sort: high priority first
usort($uniqueLeads, function($a, $b) {
    $prio = ['high' => 0, 'medium' => 1, 'low' => 2];
    return ($prio[$a['priority']] ?? 3) - ($prio[$b['priority']] ?? 3);
});

// Save new leads to DB (avoid duplicates by URL)
$saved = 0;
foreach (array_slice($uniqueLeads, 0, 50) as $lead) {
    // Check if this URL was already captured
    $exists = val("SELECT COUNT(*) FROM leads WHERE source LIKE ?", ['%' . md5($lead['url']) . '%']);
    if (!$exists && $lead['priority'] !== 'low') {
        try {
            q("INSERT INTO leads (name, email, phone, source, status, notes, created_at) VALUES (?, ?, ?, ?, 'new', ?, NOW())",
              [
                mb_substr($lead['title'], 0, 200),
                $lead['email'],
                $lead['phone'],
                'radar:' . $lead['category'] . ':' . md5($lead['url']),
                json_encode(['url' => $lead['url'], 'snippet' => $lead['snippet'], 'source' => $lead['source'], 'priority' => $lead['priority']], JSON_UNESCAPED_UNICODE),
              ]);
            $saved++;
        } catch (Exception $e) { /* duplicate or schema issue */ }
    }
}

// Telegram notification for high-priority leads
$highLeads = array_filter($uniqueLeads, fn($l) => $l['priority'] === 'high');
if (!empty($highLeads) && function_exists('telegramNotify')) {
    $msg = "🎯 <b>Lead Radar: " . count($highLeads) . " Hot Leads</b>\n\n";
    foreach (array_slice($highLeads, 0, 5) as $hl) {
        $msg .= "• " . e(mb_substr($hl['title'], 0, 60)) . "\n";
        $msg .= "  <a href=\"" . e($hl['url']) . "\">→ Link</a>\n";
        if ($hl['phone']) $msg .= "  📞 " . e($hl['phone']) . "\n";
    }
    telegramNotify($msg);
}

$elapsed = round(microtime(true) - $startTime, 2);

echo json_encode([
    'success' => true,
    'scan_time' => $elapsed,
    'total_found' => count($uniqueLeads),
    'high_priority' => count($highLeads),
    'saved_to_db' => $saved,
    'leads' => $uniqueLeads,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
