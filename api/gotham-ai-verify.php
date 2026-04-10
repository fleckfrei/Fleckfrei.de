<?php
/**
 * Gotham AI Verify — fast multi-LLM lookup UI endpoint
 *
 * Runs Groq + Perplexity + SearXNG (and optionally Grok) in parallel
 * on a free-text query OR an ontology object's display_name, and
 * returns all three results for side-by-side display.
 *
 * Different from vulture-core.php which runs a full 30-60s cascade;
 * this is ~5-10s AI-only lookup suitable for an interactive button.
 *
 * POST body:
 *   { query: "Rosa Gortner Berlin", obj_id?: 31 }
 *   - If query empty and obj_id given, uses object's display_name
 *
 * Response:
 *   { success: true, data: {
 *       query, anchor,
 *       groq:      { content, elapsed, cached, error? },
 *       perplexity:{ content, citations, elapsed, cached, error? },
 *       grok:      { content, elapsed, cached, error? },
 *       searxng:   { hits, results: [{title,url,snippet}], elapsed, cached }
 *   }}
 */

ini_set('max_execution_time', 60);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/ontology.php';
require_once __DIR__ . '/../includes/llm-helpers.php';

db_ping_reconnect();

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
$objId = (int)($body['obj_id'] ?? 0);
$cacheOnly = !empty($body['cache_only']);  // return only cached, skip live calls

// Resolve query from obj_id if needed
if ($query === '' && $objId > 0) {
    $obj = oneLocal("SELECT display_name, obj_type FROM ontology_objects WHERE obj_id = ? LIMIT 1", [$objId]);
    if ($obj) $query = $obj['display_name'];
}

if ($query === '' || mb_strlen($query) < 2) {
    echo json_encode(['error' => 'query or obj_id with display_name >= 2 chars required']);
    exit;
}

$out = ['query' => $query, 'anchor' => $query, 'cache_only' => $cacheOnly];
$globalStart = microtime(true);

// cache_only mode: peek the filecache without making any API calls.
// Used by auto-load-on-select in the UI so selecting an object is
// instant if we already have AI answers for it from a previous run.
function peek_cache(string $key, int $ttl = 86400): ?array {
    $cacheFile = sys_get_temp_dir() . '/vulture_vps_cache/' . $key . '.json';
    if (!file_exists($cacheFile)) return null;
    if ((time() - filemtime($cacheFile)) >= $ttl) return null;
    $d = json_decode(@file_get_contents($cacheFile), true);
    return is_array($d) ? $d : null;
}

// ============================================================
// GROQ (fast, ~0.5s, free)
// ============================================================
$groqPrompt = "Who is $query? Short factual profile with verifiable public sources if known. If unknown, say so honestly. 4 sentences max.";
$t = microtime(true);
if ($cacheOnly) {
    $key = 'groq_' . md5($groqPrompt . '|400');
    $groqRes = peek_cache($key);
    if ($groqRes) $groqRes['_cache_hit'] = true;
    else $groqRes = ['content' => '', '_cache_miss' => true];
} else {
    $groqRes = groq_chat($groqPrompt, 400);
}
$out['groq'] = [
    'content'  => $groqRes['content'] ?? '',
    'cached'   => !empty($groqRes['_cache_hit']),
    'elapsed'  => round(microtime(true) - $t, 2),
    'error'    => $groqRes['error'] ?? null,
];

// ============================================================
// PERPLEXITY (with citations, ~3-5s)
// ============================================================
$pplxQuery = "Who is $query? Give a short factual profile with verifiable public sources. If business: registry, directors, address. If person: role, location, companies. 4 sentences max.";
$t = microtime(true);
if ($cacheOnly) {
    $key = md5('perplexity|' . json_encode(['query' => $pplxQuery]));
    $pplxRes = peek_cache($key);
    if ($pplxRes) $pplxRes['_cache_hit'] = true;
    else $pplxRes = ['_cache_miss' => true];
} else {
    $pplxRes = vps_call('perplexity', ['query' => $pplxQuery]);
}
$pplxContent = '';
$pplxCitations = [];
if ($pplxRes && !isset($pplxRes['error'])) {
    // VPS API wraps the answer field
    $pplxContent = $pplxRes['answer'] ?? $pplxRes['content'] ?? ($pplxRes['choices'][0]['message']['content'] ?? '');
    $pplxCitations = $pplxRes['citations'] ?? [];
}
$out['perplexity'] = [
    'content'   => $pplxContent,
    'citations' => is_array($pplxCitations) ? array_slice($pplxCitations, 0, 8) : [],
    'cached'    => !empty($pplxRes['_cache_hit']),
    'elapsed'   => round(microtime(true) - $t, 2),
    'error'     => $pplxRes['error'] ?? null,
];

// ============================================================
// GROK (opt-in, skipped if key missing)
// ============================================================
$t = microtime(true);
$grokRes = grok_chat($groqPrompt, 400);
$out['grok'] = [
    'content'  => $grokRes['content'] ?? '',
    'cached'   => !empty($grokRes['_cache_hit']),
    'elapsed'  => round(microtime(true) - $t, 2),
    'error'    => ($grokRes['error'] ?? null) === 'GROK_API_KEY not configured' ? null : ($grokRes['error'] ?? null),
    'configured' => defined('GROK_API_KEY') && !empty(GROK_API_KEY),
];

// ============================================================
// SEARXNG (250 engines, ~2s cached)
// ============================================================
$t = microtime(true);
if ($cacheOnly) {
    $key = md5('searxng|' . json_encode(['query' => $query, 'categories' => 'general', 'limit' => 10]));
    $sxRes = peek_cache($key);
    if ($sxRes) $sxRes['_cache_hit'] = true;
    else $sxRes = ['results' => [], '_cache_miss' => true];
} else {
    $sxRes = vps_call('searxng', ['query' => $query, 'categories' => 'general', 'limit' => 10]);
}
$sxHits = [];
if ($sxRes && !empty($sxRes['results']) && is_array($sxRes['results'])) {
    foreach (array_slice($sxRes['results'], 0, 10) as $r) {
        $sxHits[] = [
            'title'   => $r['title'] ?? '',
            'url'     => $r['url'] ?? '',
            'snippet' => mb_substr($r['snippet'] ?? '', 0, 250),
        ];
    }
}
$out['searxng'] = [
    'hits'    => count($sxHits),
    'results' => $sxHits,
    'cached'  => !empty($sxRes['_cache_hit']),
    'elapsed' => round(microtime(true) - $t, 2),
    'error'   => $sxRes['error'] ?? null,
];

$out['total_elapsed'] = round(microtime(true) - $globalStart, 2);

echo json_encode(['success' => true, 'data' => $out], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
