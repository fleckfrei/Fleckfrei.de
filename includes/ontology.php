<?php
/**
 * Gotham Ontology — Palantir-Lite Normalizer
 *
 * Converts osint-deep.php and vulture-core.php scan output into
 * typed ontology objects with relations and events.
 *
 * Core model:
 *   - Object  : a typed entity (person, company, address, email, ...)
 *   - Link    : a typed relation between two objects
 *   - Event   : a dated thing that happened to an object
 *
 * All storage uses the *Local DB (qLocal/allLocal/valLocal) — ontology
 * is an analytics layer, kept separate from the transactional remote DB.
 */

if (!function_exists('qLocal')) {
    require_once __DIR__ . '/config.php';
}

// ============================================================
// CANONICALIZATION — produce stable keys so duplicates merge
// ============================================================
function ontology_canonicalize(string $type, string $value): string {
    $v = trim($value);
    switch ($type) {
        case 'email':
        case 'domain':
        case 'handle':
            return strtolower($v);
        case 'phone':
            return preg_replace('/[^0-9+]/', '', $v);
        case 'address':
            return mb_strtolower(preg_replace('/\s+/', ' ', $v));
        case 'person':
        case 'company':
            return mb_strtolower(preg_replace('/\s+/', ' ', $v));
        default:
            return mb_strtolower($v);
    }
}

// ============================================================
// UPSERT OBJECT — merge-on-conflict, track source count
// ============================================================
function ontology_upsert_object(
    string $type,
    string $displayName,
    array $properties = [],
    ?int $scanId = null,
    float $confidence = 0.5
): int {
    $key = ontology_canonicalize($type, $displayName);
    if ($key === '') return 0;

    $existing = oneLocal(
        "SELECT obj_id, properties, source_scans, source_count, confidence, verified
         FROM ontology_objects WHERE obj_type=? AND obj_key=? LIMIT 1",
        [$type, $key]
    );

    if ($existing) {
        $objId = (int)$existing['obj_id'];
        $props = json_decode($existing['properties'] ?? 'null', true) ?: [];
        $props = array_merge($props, $properties);
        $scans = json_decode($existing['source_scans'] ?? '[]', true) ?: [];
        if ($scanId !== null && !in_array($scanId, $scans, true)) {
            $scans[] = $scanId;
        }
        $newCount = count($scans) ?: $existing['source_count'] + 1;
        // Confidence: 1 - (1-0.33)^n  (matches vulture-core)
        $newConf = min(0.99, 1 - pow(0.67, max(1, $newCount)));
        $verified = $newCount >= 3 ? 1 : 0;
        qLocal(
            "UPDATE ontology_objects SET properties=?, source_scans=?, source_count=?,
             confidence=?, verified=?, last_updated=NOW() WHERE obj_id=?",
            [json_encode($props, JSON_UNESCAPED_UNICODE),
             json_encode($scans), $newCount, $newConf, $verified, $objId]
        );
        return $objId;
    }

    // Insert
    $scans = $scanId !== null ? [$scanId] : [];
    qLocal(
        "INSERT INTO ontology_objects
         (obj_type, obj_key, display_name, properties, confidence, verified,
          source_count, source_scans, created_by)
         VALUES (?,?,?,?,?,?,?,?,?)",
        [$type, $key, $displayName,
         json_encode($properties, JSON_UNESCAPED_UNICODE),
         $confidence, 0, 1, json_encode($scans),
         $_SESSION['uid'] ?? null]
    );
    global $dbLocal;
    return (int)$dbLocal->lastInsertId();
}

// ============================================================
// UPSERT LINK — typed relation between two objects
// ============================================================
function ontology_upsert_link(
    int $fromObj, int $toObj, string $relation,
    ?string $source = null, float $confidence = 0.5
): void {
    if ($fromObj <= 0 || $toObj <= 0 || $fromObj === $toObj) return;
    qLocal(
        "INSERT INTO ontology_links (from_obj, to_obj, relation, source, confidence)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE confidence=GREATEST(confidence, VALUES(confidence)),
                                  source=COALESCE(source, VALUES(source))",
        [$fromObj, $toObj, $relation, $source, $confidence]
    );
}

// ============================================================
// ADD EVENT — dated thing on an object's timeline
// ============================================================
function ontology_add_event(
    int $objId, string $eventType, ?string $eventDate,
    string $title, array $data = [], ?string $source = null
): int {
    if ($objId <= 0) return 0;
    qLocal(
        "INSERT INTO ontology_events (obj_id, event_type, event_date, title, data, source)
         VALUES (?,?,?,?,?,?)",
        [$objId, $eventType, $eventDate, mb_substr($title, 0, 500),
         json_encode($data, JSON_UNESCAPED_UNICODE), $source]
    );
    global $dbLocal;
    return (int)$dbLocal->lastInsertId();
}

// ============================================================
// INGEST SCAN — main entry point. Parses a vulture-core or
// osint-deep scan result and populates ontology tables.
// ============================================================
function ontology_ingest_scan(array $scan, ?int $scanId = null): array {
    $stats = ['objects_created' => 0, 'links_created' => 0, 'events_created' => 0];
    $data = $scan['data'] ?? ($scan['report'] ? $scan : $scan);

    // For vulture-core responses: look in graph.nodes
    if (isset($scan['graph']['nodes']) && is_array($scan['graph']['nodes'])) {
        $idMap = [];
        foreach ($scan['graph']['nodes'] as $n) {
            if (empty($n['value']) || empty($n['type'])) continue;
            $type = $n['type'] === 'name_variant' ? 'person' : $n['type'];
            $objId = ontology_upsert_object(
                $type,
                (string)$n['value'],
                ['sources' => $n['sources'] ?? []],
                $scanId,
                (float)($n['confidence'] ?? 0.5)
            );
            if ($objId) {
                $idMap[$n['type'] . ':' . strtolower($n['value'])] = $objId;
                $stats['objects_created']++;
            }
        }
        // Identity: link the primary_name to all verified identifiers
        $idBlock = $scan['report']['identity'] ?? [];
        $primaryName = $idBlock['primary_name'] ?? null;
        if ($primaryName) {
            $rootId = ontology_upsert_object('person', $primaryName, [], $scanId, 0.9);
            foreach (($idBlock['emails_verified'] ?? []) as $e) {
                $eId = ontology_upsert_object('email', $e['value'] ?? $e, [], $scanId, 0.9);
                ontology_upsert_link($rootId, $eId, 'has_email', 'vulture_core', 0.9);
                $stats['links_created']++;
            }
            foreach (($idBlock['phones_verified'] ?? []) as $p) {
                $pId = ontology_upsert_object('phone', $p['value'] ?? $p, [], $scanId, 0.9);
                ontology_upsert_link($rootId, $pId, 'has_phone', 'vulture_core', 0.9);
                $stats['links_created']++;
            }
            foreach (($idBlock['handles'] ?? []) as $h) {
                $hId = ontology_upsert_object('handle', $h, [], $scanId, 0.7);
                ontology_upsert_link($rootId, $hId, 'uses_handle', 'vulture_core', 0.7);
                $stats['links_created']++;
            }
            foreach (($scan['report']['network']['companies'] ?? []) as $c) {
                $cId = ontology_upsert_object('company', $c, [], $scanId, 0.8);
                ontology_upsert_link($rootId, $cId, 'associated_with', 'vulture_core', 0.8);
                $stats['links_created']++;
            }
            // Scan event
            if ($scanId) {
                ontology_add_event(
                    $rootId, 'vulture_scan', date('Y-m-d'),
                    'VULTURE cascade: ' . round(($scan['report']['confidence_overall'] ?? 0) * 100) . '% confidence',
                    ['scan_id' => $scanId, 'narrative' => $scan['report']['narrative'] ?? ''],
                    'vulture_core'
                );
                $stats['events_created']++;
            }
        }
        return $stats;
    }

    // Fallback: raw osint-deep response shape
    if (!empty($data['dossier']['primary_name'])) {
        $rootId = ontology_upsert_object('person', $data['dossier']['primary_name'], [], $scanId, 0.9);
        $stats['objects_created']++;
        if (!empty($data['email_security']) && !empty($scan['scan_email'])) {
            $eId = ontology_upsert_object('email', $scan['scan_email'], [], $scanId, 0.9);
            ontology_upsert_link($rootId, $eId, 'has_email', 'osint_deep', 0.9);
            $stats['links_created']++;
        }
    }

    return $stats;
}

// ============================================================
// SEARCH — unified query over ontology + past_scans
// ============================================================
function ontology_search(string $query, ?string $typeFilter = null, int $limit = 50): array {
    $q = trim($query);
    if ($q === '') return [];

    $sql = "SELECT obj_id, obj_type, display_name, properties, confidence, verified,
                   source_count, last_updated
            FROM ontology_objects
            WHERE (display_name LIKE ? OR obj_key LIKE ?)";
    $params = ['%' . $q . '%', '%' . mb_strtolower($q) . '%'];
    if ($typeFilter && $typeFilter !== 'all') {
        $sql .= " AND obj_type = ?";
        $params[] = $typeFilter;
    }
    $sql .= " ORDER BY verified DESC, confidence DESC, last_updated DESC LIMIT " . (int)$limit;

    $rows = allLocal($sql, $params);
    foreach ($rows as &$r) {
        $r['properties'] = json_decode($r['properties'] ?? 'null', true);
    }
    return $rows;
}

// ============================================================
// GRAPH — traverse N hops from a root, return nodes+edges
// ============================================================
function ontology_get_graph(int $rootId, int $depth = 2): array {
    if ($rootId <= 0) return ['nodes' => [], 'edges' => []];
    $depth = max(1, min(3, $depth));

    $visited = [];
    $frontier = [$rootId];
    $allNodes = [];
    $allEdges = [];

    for ($d = 0; $d < $depth && !empty($frontier); $d++) {
        $nextFrontier = [];
        // Fetch current frontier objects
        $placeholders = implode(',', array_fill(0, count($frontier), '?'));
        $nodes = allLocal(
            "SELECT obj_id, obj_type, display_name, confidence, verified, source_count
             FROM ontology_objects WHERE obj_id IN ($placeholders)",
            $frontier
        );
        foreach ($nodes as $n) {
            if (isset($visited[$n['obj_id']])) continue;
            $visited[$n['obj_id']] = true;
            $allNodes[] = $n;
        }
        // Fetch outgoing + incoming links for frontier
        $links = allLocal(
            "SELECT link_id, from_obj, to_obj, relation, source, confidence
             FROM ontology_links
             WHERE from_obj IN ($placeholders) OR to_obj IN ($placeholders)",
            array_merge($frontier, $frontier)
        );
        foreach ($links as $l) {
            $allEdges[] = $l;
            if (!isset($visited[$l['from_obj']])) $nextFrontier[] = (int)$l['from_obj'];
            if (!isset($visited[$l['to_obj']])) $nextFrontier[] = (int)$l['to_obj'];
        }
        $frontier = array_unique($nextFrontier);
    }

    // Dedupe edges
    $seenEdges = [];
    $uniqueEdges = [];
    foreach ($allEdges as $e) {
        $k = $e['from_obj'] . '-' . $e['to_obj'] . '-' . $e['relation'];
        if (!isset($seenEdges[$k])) {
            $seenEdges[$k] = true;
            $uniqueEdges[] = $e;
        }
    }

    return ['nodes' => $allNodes, 'edges' => $uniqueEdges];
}

// ============================================================
// GET OBJECT — full detail view with recent events
// ============================================================
function ontology_get_object(int $objId): ?array {
    $obj = oneLocal(
        "SELECT * FROM ontology_objects WHERE obj_id = ? LIMIT 1",
        [$objId]
    );
    if (!$obj) return null;
    $obj['properties'] = json_decode($obj['properties'] ?? 'null', true);
    $obj['source_scans'] = json_decode($obj['source_scans'] ?? '[]', true);
    $obj['events'] = allLocal(
        "SELECT event_id, event_type, event_date, title, data, source, created_at
         FROM ontology_events WHERE obj_id = ? ORDER BY COALESCE(event_date, created_at) DESC LIMIT 50",
        [$objId]
    );
    foreach ($obj['events'] as &$e) {
        $e['data'] = json_decode($e['data'] ?? 'null', true);
    }
    $obj['links_out'] = allLocal(
        "SELECT l.*, o.display_name as target_name, o.obj_type as target_type
         FROM ontology_links l
         JOIN ontology_objects o ON o.obj_id = l.to_obj
         WHERE l.from_obj = ? ORDER BY l.confidence DESC LIMIT 100",
        [$objId]
    );
    $obj['links_in'] = allLocal(
        "SELECT l.*, o.display_name as source_name, o.obj_type as source_type
         FROM ontology_links l
         JOIN ontology_objects o ON o.obj_id = l.from_obj
         WHERE l.to_obj = ? ORDER BY l.confidence DESC LIMIT 100",
        [$objId]
    );
    return $obj;
}
