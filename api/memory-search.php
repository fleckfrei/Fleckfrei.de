<?php
/**
 * Hybrid Memory Search — MySQL FULLTEXT (lexical) + Groq rerank (semantic)
 * POST /api/memory-search.php {query, limit?, rerank?}
 * Returns: ordered list of matching ontology objects with relevance scores.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/llm-helpers.php';
requireAdmin();
header('Content-Type: application/json; charset=utf-8');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$query = trim($in['query'] ?? '');
$limit = min(50, max(1, (int)($in['limit'] ?? 10)));
$rerank = !empty($in['rerank']);

if (!$query) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'query required']);
    exit;
}

$t0 = microtime(true);

// Stage 1: Lexical search (MySQL FULLTEXT natural language)
$rows = all("SELECT obj_id, obj_type, obj_key, display_name, LEFT(properties, 500) AS preview, confidence, verified, source_count, last_updated,
    MATCH(display_name, searchable_text) AGAINST (? IN NATURAL LANGUAGE MODE) AS score
    FROM ontology_objects
    WHERE MATCH(display_name, searchable_text) AGAINST (? IN NATURAL LANGUAGE MODE)
    ORDER BY score DESC
    LIMIT ?", [$query, $query, $limit * 4]) ?: [];

$lexMs = round((microtime(true) - $t0) * 1000);

// Stage 2 (optional): Groq semantic rerank on top-N
$rerankMs = 0;
if ($rerank && count($rows) > 1) {
    $t1 = microtime(true);
    $candidates = array_slice($rows, 0, min(20, count($rows)));
    $listText = '';
    foreach ($candidates as $i => $r) {
        $listText .= sprintf("[%d] %s (%s) — %s\n",
            $i,
            mb_substr($r['display_name'], 0, 100),
            $r['obj_type'],
            mb_substr(preg_replace('~\s+~', ' ', $r['preview'] ?? ''), 0, 180));
    }
    $prompt = "Ranke diese Einträge nach Relevanz zur Suchanfrage. Antworte NUR als JSON-Array mit den Indizes in absteigender Relevanz, z.B. [3,1,7,0,2].\n\n"
        . "SUCHANFRAGE: \"$query\"\n\n"
        . "KANDIDATEN:\n$listText";
    $ai = groq_chat($prompt, 300);
    if ($ai && !empty($ai['content'])) {
        $raw = trim(preg_replace('~^```(?:json)?\s*|\s*```$~m', '', $ai['content']));
        $order = json_decode($raw, true);
        if (is_array($order)) {
            $reranked = [];
            foreach ($order as $idx) {
                if (isset($candidates[$idx])) $reranked[] = $candidates[$idx] + ['_rerank_idx' => $idx];
            }
            // Append non-reranked tail
            foreach ($candidates as $i => $c) {
                if (!in_array($i, $order, true)) $reranked[] = $c;
            }
            $rows = array_merge($reranked, array_slice($rows, 20));
        }
    }
    $rerankMs = round((microtime(true) - $t1) * 1000);
}

$rows = array_slice($rows, 0, $limit);
foreach ($rows as &$r) {
    $r['properties_preview'] = mb_substr(preg_replace('~\s+~', ' ', $r['preview'] ?? ''), 0, 200);
    unset($r['preview']);
}

echo json_encode([
    'success' => true,
    'query' => $query,
    'results' => $rows,
    'counts' => ['returned' => count($rows)],
    'timing_ms' => ['lexical' => $lexMs, 'rerank' => $rerankMs, 'total' => round((microtime(true)-$t0)*1000)],
    'mode' => $rerank ? 'hybrid (lexical + Groq rerank)' : 'lexical-only',
]);
