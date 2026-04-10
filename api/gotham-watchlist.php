<?php
/**
 * Gotham Watchlist — CRUD + cron-check endpoint.
 *
 * Actions (via ?action= or POST body):
 *   list            → all watchlist rows
 *   add             → {label, query, query_type?} returns {watch_id}
 *   remove          → {watch_id}
 *   check_all       → run every active watch, alert on new hits, mark last_checked
 *   check_now       → {watch_id} run immediately and return current hits
 *
 * Telegram alerts fire via telegramNotify() when check finds NEW hits
 * (last_hit_count grew). Called by api/cron.php hourly.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ontology.php';

// Allow either session auth OR cron-key auth (for unattended runs)
session_start();
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['key'] ?? '';
$isCron = ($apiKey === API_KEY);
if (empty($_SESSION['uid']) && !$isCron) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: $_POST) : [];

// ============================================================
// LIST
// ============================================================
if ($action === 'list') {
    $rows = allLocal("SELECT * FROM ontology_watchlist ORDER BY active DESC, last_checked DESC LIMIT 100");
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

// ============================================================
// ADD
// ============================================================
if ($action === 'add') {
    $label = trim($body['label'] ?? '');
    $query = trim($body['query'] ?? '');
    $queryType = $body['query_type'] ?? 'any';
    if ($label === '' || $query === '') {
        echo json_encode(['error' => 'label + query required']);
        exit;
    }
    qLocal("INSERT INTO ontology_watchlist (label, query, query_type, created_by)
            VALUES (?,?,?,?)",
           [$label, $query, $queryType, $_SESSION['uid'] ?? null]);
    global $dbLocal;
    echo json_encode(['success' => true, 'watch_id' => (int)$dbLocal->lastInsertId()]);
    exit;
}

// ============================================================
// REMOVE
// ============================================================
if ($action === 'remove') {
    $watchId = (int)($body['watch_id'] ?? 0);
    if ($watchId <= 0) { echo json_encode(['error' => 'watch_id required']); exit; }
    qLocal("DELETE FROM ontology_watchlist WHERE watch_id = ?", [$watchId]);
    echo json_encode(['success' => true]);
    exit;
}

// ============================================================
// CHECK_ALL — iterate active watches, compare hit counts, alert
// ============================================================
if ($action === 'check_all') {
    $watches = allLocal("SELECT * FROM ontology_watchlist WHERE active = 1");
    $alertsSent = 0;
    $checked = 0;

    foreach ($watches as $w) {
        $checked++;
        $typeFilter = $w['query_type'] === 'any' ? null : $w['query_type'];
        $results = ontology_search($w['query'], $typeFilter, 100);
        // Also count past osint_scans mentions
        $scanCount = (int)valLocal(
            "SELECT COUNT(*) FROM osint_scans
             WHERE scan_name LIKE ? OR scan_email LIKE ? OR scan_phone LIKE ? OR scan_address LIKE ?",
            ['%' . $w['query'] . '%', '%' . $w['query'] . '%', '%' . $w['query'] . '%', '%' . $w['query'] . '%']
        );
        $currentHits = count($results) + $scanCount;
        $prevHits = (int)$w['last_hit_count'];
        $newHits = $currentHits - $prevHits;

        if ($newHits > 0 && !empty($w['notify_telegram']) && function_exists('telegramNotify')) {
            $msg = "🦅 <b>Gotham Watchlist Hit</b>\n" .
                   "Label: <code>" . htmlspecialchars($w['label']) . "</code>\n" .
                   "Query: <code>" . htmlspecialchars($w['query']) . "</code>\n" .
                   "New hits: <b>$newHits</b> (total now: $currentHits)\n" .
                   "🔗 /admin/scanner.php";
            @telegramNotify($msg);
            $alertsSent++;
        }

        qLocal("UPDATE ontology_watchlist SET last_checked = NOW(), last_hit_count = ? WHERE watch_id = ?",
               [$currentHits, $w['watch_id']]);
    }
    echo json_encode(['success' => true, 'checked' => $checked, 'alerts_sent' => $alertsSent]);
    exit;
}

// ============================================================
// CHECK_NOW — single watch, return hits immediately
// ============================================================
if ($action === 'check_now') {
    $watchId = (int)($body['watch_id'] ?? $_GET['watch_id'] ?? 0);
    if ($watchId <= 0) { echo json_encode(['error' => 'watch_id required']); exit; }
    $w = oneLocal("SELECT * FROM ontology_watchlist WHERE watch_id = ?", [$watchId]);
    if (!$w) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }
    $typeFilter = $w['query_type'] === 'any' ? null : $w['query_type'];
    $results = ontology_search($w['query'], $typeFilter, 100);
    echo json_encode([
        'success' => true,
        'watch' => $w,
        'current_hits' => count($results),
        'results' => array_slice($results, 0, 20),
    ]);
    exit;
}

echo json_encode(['error' => 'unknown action', 'valid' => ['list','add','remove','check_all','check_now']]);
