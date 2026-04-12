<?php
/**
 * Nuki Web API wrapper — OAuth2 + Smart Lock actions.
 * Docs: https://developer.nuki.io/page/nuki-web-api-111/4
 *
 * Usage:
 *   nukiAuthorizeUrl($state)           → build OAuth authorize URL
 *   nukiExchangeCode($code)            → exchange auth code for access token
 *   nukiRefreshToken($refreshToken)    → refresh expired token
 *   nukiListSmartlocks($token)         → get all locks for account
 *   nukiUnlock($token, $smartlockId)   → trigger unlock action
 *   nukiLock($token, $smartlockId)     → trigger lock action
 *   nukiCreateAuth($token, $smartlockId, $name, $allowedFrom, $allowedUntil)  → create temporary access
 *   nukiRevokeAuth($token, $smartlockId, $authId)  → revoke access
 */

/** Build OAuth authorize URL — user gets redirected here. */
function nukiAuthorizeUrl(string $state): ?string {
    if (!defined('NUKI_CLIENT_ID') || !NUKI_CLIENT_ID) return null;
    $params = [
        'client_id'     => NUKI_CLIENT_ID,
        'response_type' => 'code',
        'scope'         => NUKI_SCOPE,
        'redirect_uri'  => NUKI_REDIRECT_URI,
        'state'         => $state,
    ];
    return NUKI_API_BASE . '/oauth/authorize?' . http_build_query($params);
}

/** Exchange authorization code for access + refresh tokens. */
function nukiExchangeCode(string $code): ?array {
    if (!defined('NUKI_CLIENT_ID') || !NUKI_CLIENT_ID) return null;
    return nukiHttp('POST', '/oauth/token', [
        'client_id'     => NUKI_CLIENT_ID,
        'client_secret' => NUKI_CLIENT_SECRET,
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => NUKI_REDIRECT_URI,
    ], null, true);
}

/** Refresh an expired access token. */
function nukiRefreshToken(string $refreshToken): ?array {
    if (!defined('NUKI_CLIENT_ID') || !NUKI_CLIENT_ID) return null;
    return nukiHttp('POST', '/oauth/token', [
        'client_id'     => NUKI_CLIENT_ID,
        'client_secret' => NUKI_CLIENT_SECRET,
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refreshToken,
    ], null, true);
}

/** List all smart locks the user has access to. */
function nukiListSmartlocks(string $token): ?array {
    return nukiHttp('GET', '/smartlock', null, $token);
}

/** Get a single smartlock state (battery, locked/unlocked). */
function nukiGetSmartlock(string $token, string $smartlockId): ?array {
    return nukiHttp('GET', '/smartlock/' . $smartlockId, null, $token);
}

/** Unlock (action 1 = unlock, 2 = lock, 3 = unlatch, 4 = lock n go). */
function nukiUnlock(string $token, string $smartlockId): ?array {
    return nukiHttp('POST', '/smartlock/' . $smartlockId . '/action', ['action' => 1], $token);
}

function nukiLock(string $token, string $smartlockId): ?array {
    return nukiHttp('POST', '/smartlock/' . $smartlockId . '/action', ['action' => 2], $token);
}

/** Create time-limited keypad code for a partner. */
function nukiCreateAuth(string $token, string $smartlockId, string $name, string $code, string $allowedFrom, string $allowedUntil): ?array {
    return nukiHttp('PUT', '/smartlock/' . $smartlockId . '/auth', [
        'name' => $name,
        'allowedFromDate' => $allowedFrom,
        'allowedUntilDate' => $allowedUntil,
        'type' => 13, // keypad code
        'code' => (int) $code,
    ], $token);
}

function nukiRevokeAuth(string $token, string $smartlockId, string $authId): ?array {
    return nukiHttp('DELETE', '/smartlock/' . $smartlockId . '/auth/' . $authId, null, $token);
}

/**
 * Generic HTTP wrapper.
 * $formEncoded=true → send as application/x-www-form-urlencoded (used for /oauth/token)
 */
function nukiHttp(string $method, string $path, ?array $body = null, ?string $token = null, bool $formEncoded = false): ?array {
    $ch = curl_init(NUKI_API_BASE . $path);
    $headers = ['Accept: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);

    if ($body !== null) {
        if ($formEncoded) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        } else {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) return ['error' => 'Network error'];
    $json = json_decode($resp, true);
    if ($httpCode >= 400) {
        return ['error' => $json['detail'] ?? $json['message'] ?? "HTTP $httpCode", 'http_code' => $httpCode];
    }
    return is_array($json) ? $json : ['raw' => $resp];
}

/**
 * Log a lock event to audit table.
 */
function logLockEvent(int $lockId, int $cid, string $triggeredByType, ?int $triggeredById, string $triggeredByName, string $action, string $result = 'success', ?string $notes = null, ?int $jobId = null): void {
    if (!function_exists('q')) return;
    try {
        q("INSERT INTO lock_events (lock_id_fk, customer_id_fk, job_id_fk, triggered_by_type, triggered_by_id, triggered_by_name, action, result, notes, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
          [$lockId, $cid, $jobId, $triggeredByType, $triggeredById, $triggeredByName, $action, $result, $notes, $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Exception $e) {}
}
