<?php
/**
 * Gotham Expand — Palantir-Lite graph/detail/cascade endpoint
 *
 * GET  ?action=detail&obj_id=X         → full object with events + 1-hop links
 * GET  ?action=graph&obj_id=X&depth=N  → N-hop subgraph for canvas
 * POST action=cascade, obj_id=X        → fires vulture-core cascade FROM this object
 *                                         and ingests results back into ontology
 */

ini_set('max_execution_time', 300);
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

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$objId  = (int)($_GET['obj_id'] ?? $_POST['obj_id'] ?? 0);

if ($objId <= 0) {
    echo json_encode(['error' => 'obj_id required']);
    exit;
}

// ============================================================
// DETAIL — object with events + links
// ============================================================
if ($action === 'detail') {
    $obj = ontology_get_object($objId);
    if (!$obj) {
        http_response_code(404);
        echo json_encode(['error' => 'object not found']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $obj], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// GRAPH — N-hop subgraph for Cytoscape canvas
// ============================================================
if ($action === 'graph') {
    $depth = max(1, min(3, (int)($_GET['depth'] ?? 2)));
    $graph = ontology_get_graph($objId, $depth);
    // Cytoscape format: {nodes:[{data:{id,label,type}}], edges:[{data:{source,target,label}}]}
    $cy = ['nodes' => [], 'edges' => []];
    foreach ($graph['nodes'] as $n) {
        $cy['nodes'][] = [
            'data' => [
                'id'          => 'n' . $n['obj_id'],
                'label'       => mb_substr($n['display_name'], 0, 40),
                'type'        => $n['obj_type'],
                'verified'    => (int)$n['verified'],
                'confidence'  => (float)$n['confidence'],
                'source_count'=> (int)$n['source_count'],
                'obj_id'      => (int)$n['obj_id'],
            ],
        ];
    }
    foreach ($graph['edges'] as $e) {
        $cy['edges'][] = [
            'data' => [
                'id'     => 'e' . $e['link_id'],
                'source' => 'n' . $e['from_obj'],
                'target' => 'n' . $e['to_obj'],
                'label'  => $e['relation'],
                'confidence' => (float)$e['confidence'],
            ],
        ];
    }
    echo json_encode(['success' => true, 'data' => $cy], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// CASCADE — fire vulture-core with this object as seed
// ============================================================
if ($action === 'cascade') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required for cascade']);
        exit;
    }
    $obj = ontology_get_object($objId);
    if (!$obj) {
        http_response_code(404);
        echo json_encode(['error' => 'object not found']);
        exit;
    }

    // Map object type to vulture-core seed field
    $seedField = match ($obj['obj_type']) {
        'email'   => 'email',
        'phone'   => 'phone',
        'domain'  => 'domain',
        'handle'  => 'handle',
        'company' => 'company',
        'address' => 'address',
        default   => 'name',
    };
    $seed = [
        $seedField => $obj['display_name'],
        'depth'    => (int)($_POST['depth'] ?? 2),
        'mode'     => $_POST['mode'] ?? 'fast',
        'context'  => 'Gotham click-expand from obj_id=' . $objId,
    ];

    // Internal POST to vulture-core.php
    $host   = $_SERVER['HTTP_HOST'] ?? 'app.fleckfrei.de';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
    $url    = "$scheme://$host/api/vulture-core.php";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($seed),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . API_KEY,
        ],
        CURLOPT_TIMEOUT => 280,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $vc = $resp ? json_decode($resp, true) : null;
    if (!$vc || empty($vc['success'])) {
        echo json_encode(['success' => false, 'error' => 'cascade failed', 'raw' => $vc]);
        exit;
    }

    // Log expansion event on the root object
    ontology_add_event(
        $objId, 'gotham_expand', date('Y-m-d'),
        'Click-expand cascade · ' . round(($vc['report']['confidence_overall'] ?? 0) * 100) . '% confidence · ' .
        ($vc['ontology']['objects_created'] ?? 0) . ' new objects',
        ['cascade_layers' => $vc['graph']['layers_executed'] ?? 0,
         'elapsed'        => $vc['elapsed_seconds'] ?? 0,
         'narrative'      => $vc['report']['narrative'] ?? ''],
        'gotham'
    );

    echo json_encode([
        'success' => true,
        'cascade_stats' => $vc['ontology'] ?? [],
        'elapsed'       => $vc['elapsed_seconds'] ?? 0,
        'narrative'     => $vc['report']['narrative'] ?? '',
        'confidence'    => $vc['report']['confidence_overall'] ?? 0,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'unknown action', 'valid' => ['detail','graph','cascade']]);
