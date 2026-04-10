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

session_start();
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($_SESSION['uid']) && $apiKey !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$query = trim($body['query'] ?? '');
$typeFilter = $body['type_filter'] ?? null;
$includeLive = !empty($body['include_live']);

if ($query === '' || mb_strlen($query) < 2) {
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
