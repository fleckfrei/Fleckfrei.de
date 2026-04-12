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
    // GROQ VISION — image analysis via llama-3.2-90b-vision-preview
    // Accepts a base64 image or URL. Returns structured JSON analysis.
    // ============================================================
    function groq_vision(string $imageBase64OrUrl, string $prompt, int $maxTokens = 800): ?array {
        if (!defined('GROQ_API_KEY') || !GROQ_API_KEY) {
            return ['error' => 'GROQ_API_KEY not configured'];
        }
        // Determine if URL or base64
        $isUrl = str_starts_with($imageBase64OrUrl, 'http');
        $imageContent = $isUrl
            ? ['type' => 'image_url', 'image_url' => ['url' => $imageBase64OrUrl]]
            : ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $imageBase64OrUrl]];

        $payload = [
            'model' => 'llama-3.2-90b-vision-preview',
            'messages' => [
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    $imageContent,
                ]],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => 0.1,
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
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) return ['error' => "Groq Vision HTTP $code", 'raw' => $resp];
        $decoded = json_decode($resp, true);
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        return [
            'content' => $content,
            'model' => $decoded['model'] ?? 'llama-3.2-90b-vision-preview',
            'usage' => $decoded['usage'] ?? null,
        ];
    }

    // ============================================================
    // SHODAN HOST — enrich IP nodes with services/ports/certs
    // Free tier: 100 host lookups/month with API plan, $0 for SSL
    // Returns null on failure (graceful skip).
    // 7-day file cache (IPs change slowly).
    // ============================================================
    function shodan_host(string $ip): ?array {
        if (!defined('SHODAN_API_KEY') || !SHODAN_API_KEY) return null;
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return null;
        $cacheDir = sys_get_temp_dir() . '/vulture_vps_cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
        $cacheFile = $cacheDir . '/shodan_' . md5($ip) . '.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 604800) {
            $cached = json_decode(@file_get_contents($cacheFile), true);
            if (is_array($cached)) { $cached['_cache_hit'] = true; return $cached; }
        }
        $url = 'https://api.shodan.io/shodan/host/' . urlencode($ip) . '?key=' . urlencode(SHODAN_API_KEY);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$resp) {
            // Negative cache 7d so we don't hammer Shodan on bad IPs
            @file_put_contents($cacheFile, json_encode(['_error' => "HTTP $code", '_no_data' => true]));
            return null;
        }
        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) return null;
        // Distill: keep only the fields we care about, drop heavy raw banners
        $out = [
            'ip'            => $decoded['ip_str'] ?? $ip,
            'org'           => $decoded['org'] ?? null,
            'isp'           => $decoded['isp'] ?? null,
            'asn'           => $decoded['asn'] ?? null,
            'country'       => $decoded['country_name'] ?? null,
            'country_code'  => $decoded['country_code'] ?? null,
            'city'          => $decoded['city'] ?? null,
            'os'            => $decoded['os'] ?? null,
            'ports'         => $decoded['ports'] ?? [],
            'hostnames'     => $decoded['hostnames'] ?? [],
            'domains'       => $decoded['domains'] ?? [],
            'last_update'   => $decoded['last_update'] ?? null,
            'vulns'         => array_values($decoded['vulns'] ?? []),
            'tags'          => $decoded['tags'] ?? [],
        ];
        // Per-port service summary
        $services = [];
        foreach (($decoded['data'] ?? []) as $svc) {
            $services[] = [
                'port'      => $svc['port'] ?? null,
                'transport' => $svc['transport'] ?? 'tcp',
                'product'   => $svc['product'] ?? null,
                'version'   => $svc['version'] ?? null,
                'cpe'       => $svc['cpe'] ?? null,
                'ssl_cn'    => $svc['ssl']['cert']['subject']['CN'] ?? null,
                'ssl_issuer'=> $svc['ssl']['cert']['issuer']['O'] ?? null,
            ];
        }
        $out['services'] = $services;
        @file_put_contents($cacheFile, json_encode($out));
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
