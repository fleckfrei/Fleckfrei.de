<?php
/**
 * Google OAuth2 Callback — handles the redirect after Google login
 * Stores access_token + refresh_token for Gmail, Drive, Sheets, Contacts access
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

// Credentials stored in app_config table (not in code — GitHub secret scanning)
define('GOOGLE_CLIENT_ID', val("SELECT config_value FROM app_config WHERE config_key='google_client_id'") ?: '');
define('GOOGLE_CLIENT_SECRET', val("SELECT config_value FROM app_config WHERE config_key='google_client_secret'") ?: '');
define('GOOGLE_REDIRECT_URI', 'https://app.fleckfrei.de/api/google-callback.php');

$code = $_GET['code'] ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    header('Location: /admin/osi.php?google_error=' . urlencode($error));
    exit;
}

if (!$code) {
    // Step 1: Redirect to Google OAuth
    $scopes = implode(' ', [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/drive.readonly',
        'https://www.googleapis.com/auth/spreadsheets.readonly',
        'https://www.googleapis.com/auth/contacts.readonly',
    ]);
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => $scopes,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => csrfToken(),
    ]);
    header('Location: ' . $url);
    exit;
}

// Step 2: Exchange code for tokens
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code',
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT => 15,
]);
$resp = curl_exec($ch);
curl_close($ch);

$tokens = json_decode($resp, true);
if (empty($tokens['access_token'])) {
    header('Location: /admin/osi.php?google_error=' . urlencode('Token exchange failed: ' . ($tokens['error_description'] ?? $resp)));
    exit;
}

// Save tokens to settings table
$tokenData = json_encode([
    'access_token' => $tokens['access_token'],
    'refresh_token' => $tokens['refresh_token'] ?? null,
    'expires_at' => time() + ($tokens['expires_in'] ?? 3600),
    'scope' => $tokens['scope'] ?? '',
    'connected_at' => date('Y-m-d H:i:s'),
    'connected_by' => $_SESSION['uname'] ?? 'admin',
]);

// Store in settings table (upsert)
$existing = val("SELECT COUNT(*) FROM app_config WHERE config_key='google_oauth_tokens'");
if ($existing) {
    q("UPDATE app_config SET config_value=? WHERE config_key='google_oauth_tokens'", [$tokenData]);
} else {
    q("INSERT INTO app_config (config_key, config_value) VALUES ('google_oauth_tokens', ?)", [$tokenData]);
}

audit('google_connect', 'system', 0, 'Google OAuth connected: ' . ($tokens['scope'] ?? ''));
if (function_exists('telegramNotify')) {
    telegramNotify("🔗 <b>Google OAuth verbunden!</b>\nScopes: " . ($tokens['scope'] ?? ''));
}

header('Location: /admin/osi.php?google_connected=1');
exit;
