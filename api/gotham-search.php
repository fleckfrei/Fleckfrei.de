<?php
/**
 * Gotham Unified Search — Palantir-Lite
 *
 * POST {query, type_filter?, include_live?}
 *
 * Sources:
 *   1. Ontology objects (indexed from past scans)
 *   2. past osint_scans rows (fuzzy name/email/phone match)
 *   3. Live SearXNG (optional, when include_live=true)
 *
 * Returns merged ranked result list with obj_id links into Gotham view.
 */

ini_set('max_execution_time', 60);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ontology.php';

db_ping_reconnect();
session_start();
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($_SESSION['uid']) && $apiKey !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$body = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : [];
$action = $_GET['action'] ?? $body['action'] ?? '';

// stats + heatmap are GET-allowed; everything else requires POST
if (!in_array($action, ['stats','heatmap'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

// ============================================================
// HEATMAP mode — per-day event activity for last N days
// ============================================================
if ($action === 'heatmap') {
    $days = max(7, min(365, (int)($_GET['days'] ?? 90)));
    try {
        $rows = allLocal(
            "SELECT DATE(COALESCE(event_date, created_at)) as d, COUNT(*) as n
             FROM ontology_events
             WHERE COALESCE(event_date, created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(COALESCE(event_date, created_at))
             ORDER BY d",
            [$days]
        );
        $typeRows = allLocal(
            "SELECT event_type, COUNT(*) as n
             FROM ontology_events
             WHERE COALESCE(event_date, created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY event_type ORDER BY n DESC",
            [$days]
        );
        $topObjects = allLocal(
            "SELECT o.obj_id, o.obj_type, o.display_name, COUNT(e.event_id) as events
             FROM ontology_events e
             JOIN ontology_objects o ON o.obj_id = e.obj_id
             WHERE COALESCE(e.event_date, e.created_at) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY o.obj_id ORDER BY events DESC LIMIT 10",
            [$days]
        );
        echo json_encode(['success' => true, 'data' => [
            'days'        => $days,
            'by_date'     => $rows,
            'by_type'     => $typeRows,
            'top_objects' => $topObjects,
        ]]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// STATS-only mode — no query needed, returns global counts
// ============================================================
if ($action === 'stats' || ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['stats']))) {
    try {
        $totalObjects  = (int)valLocal("SELECT COUNT(*) FROM ontology_objects");
        $verified      = (int)valLocal("SELECT COUNT(*) FROM ontology_objects WHERE verified = 1");
        $totalLinks    = (int)valLocal("SELECT COUNT(*) FROM ontology_links");
        $totalEvents   = (int)valLocal("SELECT COUNT(*) FROM ontology_events");
        $totalScans    = (int)valLocal("SELECT COUNT(*) FROM osint_scans WHERE deep_scan_data IS NOT NULL");
        $typeCounts    = allLocal("SELECT obj_type, COUNT(*) as c FROM ontology_objects GROUP BY obj_type ORDER BY c DESC");
        echo json_encode(['success' => true, 'data' => [
            'total_objects' => $totalObjects,
            'verified'      => $verified,
            'total_links'   => $totalLinks,
            'total_events'  => $totalEvents,
            'total_scans'   => $totalScans,
            'type_counts'   => $typeCounts,
        ]]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$query = trim($body['query'] ?? '');
$typeFilter = $body['type_filter'] ?? null;
$includeLive = !empty($body['include_live']);

// Empty query → return recent objects + recent scans (browse mode)
if ($query === '') {
    try {
        $recent = allLocal(
            "SELECT obj_id, obj_type, display_name, properties, confidence, verified, source_count, last_updated
             FROM ontology_objects
             " . ($typeFilter && $typeFilter !== 'all' ? "WHERE obj_type = ? " : "") . "
             ORDER BY last_updated DESC LIMIT 30",
            $typeFilter && $typeFilter !== 'all' ? [$typeFilter] : []
        );
        foreach ($recent as &$r) $r['properties'] = json_decode($r['properties'] ?? 'null', true);

        $recentScans = allLocal(
            "SELECT scan_id, scan_name, scan_email, scan_phone, scan_address, created_at
             FROM osint_scans
             WHERE scan_name IS NOT NULL OR scan_email IS NOT NULL
             ORDER BY created_at DESC LIMIT 20"
        );

        echo json_encode(['success' => true, 'data' => [
            'query'    => '',
            'browse'   => true,
            'ontology' => $recent,
            'scans'    => $recentScans,
            'live'     => [],
            'counts'   => [
                'ontology' => count($recent),
                'scans'    => count($recentScans),
                'live'     => 0,
                'total'    => count($recent) + count($recentScans),
            ],
        ]]);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if (mb_strlen($query) < 2) {
    echo json_encode(['error' => 'query too short (min 2 chars)']);
    exit;
}

$results = [
    'query'    => $query,
    'ontology' => [],
    'scans'    => [],
    'live'     => [],
    'counts'   => [],
];

// ============================================================
// 1. Ontology search (indexed, fast)
// ============================================================
try {
    $results['ontology'] = ontology_search($query, $typeFilter, 30);
} catch (Exception $e) {
    $results['ontology_error'] = $e->getMessage();
}

// ============================================================
// 2. Past scans (fuzzy match on historical data)
// ============================================================
try {
    $like = '%' . $query . '%';
    $scans = allLocal(
        "SELECT scan_id, scan_name, scan_email, scan_phone, scan_address, created_at,
                CHAR_LENGTH(COALESCE(deep_scan_data,'')) as data_size
         FROM osint_scans
         WHERE scan_name LIKE ? OR scan_email LIKE ? OR scan_phone LIKE ? OR scan_address LIKE ?
         ORDER BY created_at DESC LIMIT 20",
        [$like, $like, $like, $like]
    );
    $results['scans'] = $scans;
} catch (Exception $e) {
    $results['scans_error'] = $e->getMessage();
}

// ============================================================
// 3. Live SearXNG (opt-in, adds 5-15s latency)
// ============================================================
if ($includeLive) {
    $ch = curl_init('http://89.116.22.185:8900/searxng');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'query'      => $query,
            'categories' => 'general',
            'limit'      => 10,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . API_KEY,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $decoded = $resp ? json_decode($resp, true) : null;
    if ($decoded && !empty($decoded['results'])) {
        $results['live'] = array_slice(array_map(fn($r) => [
            'title'   => $r['title'] ?? '',
            'url'     => $r['url'] ?? '',
            'snippet' => mb_substr($r['snippet'] ?? '', 0, 250),
        ], $decoded['results']), 0, 10);
    }
}

$results['counts'] = [
    'ontology' => count($results['ontology']),
    'scans'    => count($results['scans']),
    'live'     => count($results['live']),
    'total'    => count($results['ontology']) + count($results['scans']) + count($results['live']),
];

echo json_encode(['success' => true, 'data' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
