<?php
/**
 * Gotham Daily Report — Telegram summary cron
 *
 * Sends a formatted HTML Telegram message with:
 *   - Watchlist hits + deltas since yesterday
 *   - New merge candidates since yesterday
 *   - Objects added in last 24h (top 10 by source count)
 *   - Timeline events in last 24h
 *   - Current ontology totals
 *
 * Called by api/cron.php task 6, runs once per 24h.
 * Manual trigger: GET /api/gotham-daily-report.php?key=<API_KEY>&run=1
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ontology.php';

db_ping_reconnect();

// Auth — session OR api key
session_start();
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
if (empty($_SESSION['uid']) && $apiKey !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Run = actually send to Telegram. Preview = return JSON payload only.
$actuallyRun = !empty($_GET['run']) || !empty($_POST['run']);

// ============================================================
// Collect stats
// ============================================================
$report = [
    'generated_at' => date('Y-m-d H:i'),
    'ontology_totals' => [
        'objects'  => (int)valLocal("SELECT COUNT(*) FROM ontology_objects"),
        'verified' => (int)valLocal("SELECT COUNT(*) FROM ontology_objects WHERE verified = 1"),
        'links'    => (int)valLocal("SELECT COUNT(*) FROM ontology_links"),
        'events'   => (int)valLocal("SELECT COUNT(*) FROM ontology_events"),
    ],
];

// Last 24h activity
try {
    $report['new_objects_24h'] = (int)valLocal(
        "SELECT COUNT(*) FROM ontology_objects WHERE first_seen >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
    $report['new_links_24h'] = (int)valLocal(
        "SELECT COUNT(*) FROM ontology_links WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
    $report['events_24h'] = (int)valLocal(
        "SELECT COUNT(*) FROM ontology_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );

    // Top 5 most-attested new objects
    $report['top_new_objects'] = allLocal(
        "SELECT obj_id, obj_type, display_name, confidence, verified, source_count
         FROM ontology_objects
         WHERE first_seen >= DATE_SUB(NOW(), INTERVAL 1 DAY)
         ORDER BY source_count DESC, confidence DESC LIMIT 5"
    );

    // Top event types
    $report['events_by_type'] = allLocal(
        "SELECT event_type, COUNT(*) as n FROM ontology_events
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
         GROUP BY event_type ORDER BY n DESC LIMIT 5"
    );
} catch (Exception $e) {
    $report['stats_error'] = $e->getMessage();
}

// Watchlist hits with deltas
$report['watchlist'] = [];
try {
    $watches = allLocal("SELECT * FROM ontology_watchlist WHERE active = 1 ORDER BY last_hit_count DESC LIMIT 20");
    foreach ($watches as $w) {
        $results = ontology_search($w['query'], $w['query_type'] === 'any' ? null : $w['query_type'], 100);
        $scanHits = (int)valLocal(
            "SELECT COUNT(*) FROM osint_scans
             WHERE scan_name LIKE ? OR scan_email LIKE ? OR scan_phone LIKE ?",
            ['%' . $w['query'] . '%', '%' . $w['query'] . '%', '%' . $w['query'] . '%']
        );
        $current = count($results) + $scanHits;
        $delta = $current - (int)$w['last_hit_count'];
        $report['watchlist'][] = [
            'label'   => $w['label'],
            'query'   => $w['query'],
            'current' => $current,
            'delta'   => $delta,
        ];
    }
} catch (Exception $e) {
    $report['watchlist_error'] = $e->getMessage();
}

// Merge candidates count
try {
    $cands = ontology_find_merge_candidates(50);
    $report['merge_candidates_total'] = count($cands);
    $report['merge_candidates_sample'] = array_slice(array_map(fn($c) => [
        'a' => $c['person_a']['name'] ?? '',
        'b' => $c['person_b']['name'] ?? '',
        'reason' => $c['reason'] ?? '',
    ], $cands), 0, 3);
} catch (Exception $e) {
    $report['merge_candidates_total'] = 0;
}

// ============================================================
// Format as Telegram HTML message (max ~4000 chars)
// ============================================================
function escape_tg(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$lines = [];
$lines[] = '🦅 <b>GOTHAM DAILY REPORT</b>';
$lines[] = '<i>' . escape_tg($report['generated_at']) . '</i>';
$lines[] = '';

$t = $report['ontology_totals'];
$lines[] = '📊 <b>Totals</b>';
$lines[] = "  • Objects: <code>{$t['objects']}</code>  (<code>{$t['verified']}</code> verified)";
$lines[] = "  • Links: <code>{$t['links']}</code>  Events: <code>{$t['events']}</code>";
$lines[] = '';

$lines[] = '🆕 <b>Last 24h</b>';
$lines[] = "  • New objects: <code>" . ($report['new_objects_24h'] ?? 0) . "</code>";
$lines[] = "  • New links: <code>" . ($report['new_links_24h'] ?? 0) . "</code>";
$lines[] = "  • Events: <code>" . ($report['events_24h'] ?? 0) . "</code>";

if (!empty($report['top_new_objects'])) {
    $lines[] = '';
    $lines[] = '⭐ <b>Top new objects</b>';
    foreach ($report['top_new_objects'] as $o) {
        $v = $o['verified'] ? ' ✓' : '';
        $lines[] = '  • <code>' . escape_tg($o['obj_type']) . '</code> ' .
                   escape_tg(mb_substr($o['display_name'], 0, 50)) . $v .
                   ' <i>(' . round($o['confidence'] * 100) . '%, ' . $o['source_count'] . ' src)</i>';
    }
}

// Watchlist with deltas
$withDeltas = array_filter($report['watchlist'] ?? [], fn($w) => abs($w['delta']) > 0);
if (!empty($withDeltas)) {
    $lines[] = '';
    $lines[] = '👁 <b>Watchlist movements</b>';
    foreach (array_slice($withDeltas, 0, 8) as $w) {
        $arrow = $w['delta'] > 0 ? '🔺' : '🔻';
        $lines[] = '  ' . $arrow . ' <b>' . escape_tg($w['label']) . '</b> ' .
                   '<code>' . escape_tg($w['query']) . '</code> → ' .
                   $w['current'] . ' (' . ($w['delta'] > 0 ? '+' : '') . $w['delta'] . ')';
    }
} elseif (!empty($report['watchlist'])) {
    $lines[] = '';
    $lines[] = '👁 Watchlist: ' . count($report['watchlist']) . ' active, no movement';
}

// Merge candidates
if (!empty($report['merge_candidates_total'])) {
    $lines[] = '';
    $lines[] = '🔗 <b>Merge candidates:</b> <code>' . $report['merge_candidates_total'] . '</code>';
    foreach ($report['merge_candidates_sample'] as $c) {
        $lines[] = '  • ' . escape_tg(mb_substr($c['a'], 0, 30)) . ' ≡ ' .
                   escape_tg(mb_substr($c['b'], 0, 30));
    }
}

$lines[] = '';
$lines[] = '🔗 <a href="https://app.' . SITE_DOMAIN . '/admin/scanner.php">Open Scanner</a>';

$msg = implode("\n", $lines);
$report['telegram_preview'] = $msg;

// ============================================================
// Send (or preview)
// ============================================================
if ($actuallyRun && function_exists('telegramNotify')) {
    try {
        telegramNotify($msg);
        $report['sent'] = true;
    } catch (Exception $e) {
        $report['send_error'] = $e->getMessage();
    }
} else {
    $report['sent'] = false;
    $report['hint'] = 'Add ?run=1 to actually send to Telegram';
}

echo json_encode(['success' => true, 'report' => $report], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
