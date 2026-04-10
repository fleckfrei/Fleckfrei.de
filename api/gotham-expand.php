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

db_ping_reconnect();
session_start();
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($_SESSION['uid']) && $apiKey !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$objId  = (int)($_GET['obj_id'] ?? $_POST['obj_id'] ?? 0);

// Some actions don't use obj_id — skip guard for them
$noObjIdActions = ['ingest_scan','merge_candidates','merge'];
if (!in_array($action, $noObjIdActions, true) && $objId <= 0) {
    echo json_encode(['error' => 'obj_id required']);
    exit;
}

// ============================================================
// MERGE_CANDIDATES — surface cluster duplicates for review
// ============================================================
if ($action === 'merge_candidates') {
    try {
        $cands = ontology_find_merge_candidates(50);
        echo json_encode(['success' => true, 'data' => $cands]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// MERGE — execute a reviewed merge
// ============================================================
if ($action === 'merge') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $winnerId = (int)($body['winner_id'] ?? 0);
    $loserId  = (int)($body['loser_id']  ?? 0);
    try {
        $result = ontology_merge_objects($winnerId, $loserId);
        echo json_encode($result);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
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

// ============================================================
// INGEST_SCAN — create ontology objects from a past osint_scans
// row on-demand. Used when user clicks a "past scan" result
// that has no obj_id yet. Returns the root obj_id for selection.
// ============================================================
if ($action === 'ingest_scan') {
    $scanId = (int)($_GET['scan_id'] ?? $_POST['scan_id'] ?? 0);
    if ($scanId <= 0) { echo json_encode(['error' => 'scan_id required']); exit; }
    try {
        $scan = one("SELECT scan_id, scan_name, scan_email, scan_phone, scan_address, deep_scan_data
                     FROM osint_scans WHERE scan_id = ? LIMIT 1", [$scanId]);
        if (!$scan) {
            http_response_code(404);
            echo json_encode(['error' => 'scan not found']);
            exit;
        }
        $stats = ['objects_created' => 0, 'links_created' => 0, 'events_created' => 0];
        $rootId = 0;

        // Primary person object from scan identifiers
        $primaryName = $scan['scan_name'] ?: $scan['scan_email'] ?: $scan['scan_phone'] ?: $scan['scan_address'];
        if ($primaryName) {
            $rootId = ontology_upsert_object('person', $primaryName, [
                'from_scan_id' => $scanId,
            ], $scanId, 0.7);
            $stats['objects_created']++;
        }

        // Link identifiers
        if ($rootId && !empty($scan['scan_email'])) {
            $eId = ontology_upsert_object('email', $scan['scan_email'], [], $scanId, 0.8);
            ontology_upsert_link($rootId, $eId, 'has_email', 'osint_scans', 0.8);
            $stats['objects_created']++; $stats['links_created']++;
        }
        if ($rootId && !empty($scan['scan_phone'])) {
            $pId = ontology_upsert_object('phone', $scan['scan_phone'], [], $scanId, 0.8);
            ontology_upsert_link($rootId, $pId, 'has_phone', 'osint_scans', 0.8);
            $stats['objects_created']++; $stats['links_created']++;
        }
        if ($rootId && !empty($scan['scan_address'])) {
            $aId = ontology_upsert_object('address', $scan['scan_address'], [], $scanId, 0.7);
            ontology_upsert_link($rootId, $aId, 'lives_at', 'osint_scans', 0.7);
            $stats['objects_created']++; $stats['links_created']++;
        }

        // Parse deep_scan_data for additional nodes
        if ($rootId && !empty($scan['deep_scan_data'])) {
            $deepData = json_decode($scan['deep_scan_data'], true);
            if (is_array($deepData)) {
                // Check if this is a vulture_core scan with a graph
                if (isset($deepData['report']) && isset($deepData['graph_summary'])) {
                    // Already a vulture scan — nothing else to extract
                } else {
                    // Raw osint-deep scan — pull known fields
                    $blob = json_encode($deepData);
                    // Extract emails (filter noise)
                    if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $blob, $m)) {
                        $emails = array_unique(array_slice($m[0], 0, 10));
                        foreach ($emails as $em) {
                            $dom = strtolower(substr($em, strpos($em, '@') + 1));
                            if (in_array($dom, ['example.com','test.com','sentry.io','google.com','github.com'], true)) continue;
                            $eId = ontology_upsert_object('email', $em, [], $scanId, 0.5);
                            if ($eId) {
                                ontology_upsert_link($rootId, $eId, 'mentioned_email', 'osint_scans', 0.5);
                                $stats['objects_created']++; $stats['links_created']++;
                            }
                        }
                    }
                    // Companies from handelsregister/opencorporates/gleif_lei
                    foreach (['handelsregister','opencorporates','gleif_lei'] as $mod) {
                        if (!empty($deepData[$mod]) && is_array($deepData[$mod])) {
                            foreach ($deepData[$mod] as $entry) {
                                if (is_array($entry) && !empty($entry['company'])) {
                                    $cId = ontology_upsert_object('company', $entry['company'], [], $scanId, 0.8);
                                    ontology_upsert_link($rootId, $cId, 'associated_with', $mod, 0.8);
                                    $stats['objects_created']++; $stats['links_created']++;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Timeline event
        if ($rootId) {
            ontology_add_event(
                $rootId, 'osint_scan_imported', substr($scan['scan_name'] ? date('Y-m-d') : '', 0, 10),
                'Imported from past scan #' . $scanId,
                ['scan_id' => $scanId], 'gotham_ingest'
            );
            $stats['events_created']++;
        }

        echo json_encode(['success' => true, 'obj_id' => $rootId, 'stats' => $stats]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

echo json_encode(['error' => 'unknown action', 'valid' => ['detail','graph','cascade','ingest_scan']]);
