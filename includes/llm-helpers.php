<?php
/**
 * LLM Helpers — shared between vulture-core.php and gotham-ai-verify.php
 *
 * - vps_call(tool, params, useCache)  → VPS OSINT API v4 (perplexity, searxng, …)
 * - groq_chat(prompt, maxTokens)      → groq.com llama-3.3-70b-versatile
 * - grok_chat(prompt, maxTokens)      → x.ai grok-2-latest
 *
 * All responses cached 24h in sys_get_temp_dir()/vulture_vps_cache/
 * for idempotent read-only endpoints. Cache hit sets _cache_hit => true.
 */

if (!defined('LLM_HELPERS_LOADED')) {
    define('LLM_HELPERS_LOADED', true);

    // ============================================================
    // VPS API V4 — direct calls for tools not in osint-deep
    // Endpoint: http://89.116.22.185:8900  (auth via X-API-Key)
    // ============================================================
    function vps_call(string $tool, array $params, bool $useCache = true): ?array {
        $cacheable = in_array($tool, ['searxng', 'websearch', 'perplexity', 'google-osint'], true);
        $cacheDir = sys_get_temp_dir() . '/vulture_vps_cache';
        if ($useCache && $cacheable) {
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
            $cacheKey = md5($tool . '|' . json_encode($params));
            $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
                $cached = json_decode(@file_get_contents($cacheFile), true);
                if (is_array($cached)) {
                    $cached['_cache_hit'] = true;
                    return $cached;
                }
            }
        }

        $url = 'http://89.116.22.185:8900/' . ltrim($tool, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . API_KEY,
            ],
            CURLOPT_TIMEOUT => 90,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $decoded = $resp ? json_decode($resp, true) : null;

        if ($useCache && $cacheable && is_array($decoded) && empty($decoded['error'])) {
            @file_put_contents($cacheFile, $resp);
        }
        return $decoded;
    }

    // ============================================================
    // GROQ LLM — direct call (free llama-3.3-70b-versatile)
    // ============================================================
    function groq_chat(string $prompt, int $maxTokens = 400): ?array {
        if (!defined('GROQ_API_KEY') || !GROQ_API_KEY) {
            return ['error' => 'GROQ_API_KEY not configured'];
        }
        $cacheDir = sys_get_temp_dir() . '/vulture_vps_cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
        $cacheFile = $cacheDir . '/groq_' . md5($prompt . '|' . $maxTokens) . '.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
            $cached = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                $cached['_cache_hit'] = true;
                return $cached;
            }
        }
        $payload = [
            'model' => 'llama-3.3-70b-versatile',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => $maxTokens,
            'temperature' => 0.2,
        ];
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . GROQ_API_KEY,
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) return ['error' => "Groq HTTP $code"];
        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) return ['error' => 'invalid JSON'];
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        $out = [
            'content' => $content,
            'model' => $decoded['model'] ?? '',
            'usage' => $decoded['usage'] ?? null,
        ];
        if ($content) @file_put_contents($cacheFile, json_encode($out));
        return $out;
    }

    // ============================================================
    // GROK (x.ai) — grok-2-latest, paid
    // Gracefully returns 'not configured' if key missing so
    // ai_crosscheck can silently skip it without noise.
    // ============================================================
    function grok_chat(string $prompt, int $maxTokens = 400): ?array {
        if (!defined('GROK_API_KEY') || !GROK_API_KEY) {
            return ['error' => 'GROK_API_KEY not configured'];
        }
        $cacheDir = sys_get_temp_dir() . '/vulture_vps_cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
        $cacheFile = $cacheDir . '/grok_' . md5($prompt . '|' . $maxTokens) . '.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
            $cached = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached)) { $cached['_cache_hit'] = true; return $cached; }
        }
        $payload = [
            'model' => 'grok-2-latest',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => $maxTokens,
            'temperature' => 0.2,
        ];
        $ch = curl_init('https://api.x.ai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . GROK_API_KEY,
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) return ['error' => "Grok HTTP $code"];
        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) return ['error' => 'invalid JSON'];
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        $out = ['content' => $content, 'model' => $decoded['model'] ?? '', 'usage' => $decoded['usage'] ?? null];
        if ($content) @file_put_contents($cacheFile, json_encode($out));
        return $out;
    }
}
