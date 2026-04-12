<?php
/**
 * Nuki OAuth Callback Handler
 * Receives authorization code, exchanges it for access + refresh tokens,
 * fetches smartlocks list, creates smart_locks DB entries.
 *
 * Flow: /customer/locks.php → Nuki OAuth → this file → redirect back to /customer/locks.php
 */
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nuki-helpers.php';

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

// Verify session + state (CSRF protection)
if ($error) {
    header('Location: /customer/locks.php?error=oauth_' . urlencode($error));
    exit;
}

if (empty($code) || empty($state)) {
    header('Location: /customer/locks.php?error=missing_code');
    exit;
}

if (($_SESSION['nuki_oauth_state'] ?? '') !== $state) {
    header('Location: /customer/locks.php?error=state_mismatch');
    exit;
}

$cid = (int) ($_SESSION['nuki_oauth_cid'] ?? 0);
if (!$cid || ($_SESSION['utype'] ?? '') !== 'customer' || (int)($_SESSION['uid'] ?? 0) !== $cid) {
    header('Location: /login.php');
    exit;
}

// Exchange code for token
$tokenResp = nukiExchangeCode($code);
if (!$tokenResp || isset($tokenResp['error'])) {
    $msg = $tokenResp['error'] ?? 'token_exchange_failed';
    header('Location: /customer/locks.php?error=' . urlencode('oauth_' . $msg));
    exit;
}

$accessToken = $tokenResp['access_token'] ?? '';
$refreshToken = $tokenResp['refresh_token'] ?? '';
$expiresIn = (int) ($tokenResp['expires_in'] ?? 3600);
$expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

if (!$accessToken) {
    header('Location: /customer/locks.php?error=no_token');
    exit;
}

// Fetch smartlocks from Nuki and create/update rows
$smartlocks = nukiListSmartlocks($accessToken);
$importedCount = 0;

if (is_array($smartlocks) && !isset($smartlocks['error'])) {
    foreach ($smartlocks as $sl) {
        $deviceId = (string) ($sl['smartlockId'] ?? '');
        if (!$deviceId) continue;
        $name = $sl['name'] ?? 'Nuki Smart Lock';
        $battery = isset($sl['state']['batteryCharge']) ? (int) $sl['state']['batteryCharge'] : null;
        $state = $sl['state']['stateName'] ?? null;

        try {
            q("INSERT INTO smart_locks (customer_id_fk, provider, device_id, device_name, auth_token, refresh_token, token_expires_at, battery_level, last_state, last_checked_at, is_active)
               VALUES (?, 'nuki', ?, ?, ?, ?, ?, ?, ?, NOW(), 1)
               ON DUPLICATE KEY UPDATE device_name=VALUES(device_name), auth_token=VALUES(auth_token), refresh_token=VALUES(refresh_token), token_expires_at=VALUES(token_expires_at), battery_level=VALUES(battery_level), last_state=VALUES(last_state), last_checked_at=NOW(), is_active=1",
              [$cid, $deviceId, $name, $accessToken, $refreshToken, $expiresAt, $battery, $state]);
            $importedCount++;
        } catch (Exception $e) { /* skip */ }
    }
}

if (function_exists('audit')) {
    audit('create', 'smart_locks', 0, "Nuki OAuth verbunden — $importedCount Schlösser importiert");
}
if (function_exists('telegramNotify')) {
    telegramNotify("🔐 Kunde #$cid hat Nuki verbunden: $importedCount Schlösser importiert");
}

// Clean up session
unset($_SESSION['nuki_oauth_state'], $_SESSION['nuki_oauth_cid']);

header('Location: /customer/locks.php?saved=connected');
exit;
