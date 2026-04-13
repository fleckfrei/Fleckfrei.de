<?php
/**
 * Google Sign-In for customers — OAuth2 login flow
 * Matches Google email against customer/employee/admin table
 * Auto-creates customer account if new user
 */
session_start();
require_once __DIR__ . '/../includes/config.php';

$clientId = val("SELECT config_value FROM app_config WHERE config_key='google_client_id'") ?: '';
$clientSecret = val("SELECT config_value FROM app_config WHERE config_key='google_client_secret'") ?: '';
// Use same redirect URI as OSI OAuth (already registered in Google Console)
$redirectUri = 'https://app.fleckfrei.de/api/google-login.php';

$code = $_GET['code'] ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    header('Location: /login.php?err=' . urlencode('Google Login fehlgeschlagen'));
    exit;
}

if (!$code) {
    // Step 1: Redirect to Google for login (minimal scopes — just profile + email)
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'prompt' => 'select_account',
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
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ]),
    CURLOPT_TIMEOUT => 15,
]);
$resp = curl_exec($ch);
curl_close($ch);
$tokens = json_decode($resp, true);

if (empty($tokens['access_token'])) {
    header('Location: /login.php?err=' . urlencode('Google Token-Austausch fehlgeschlagen'));
    exit;
}

// Step 3: Get user info from Google
$ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tokens['access_token']],
    CURLOPT_TIMEOUT => 10,
]);
$userInfo = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($userInfo['email'])) {
    header('Location: /login.php?err=' . urlencode('Google hat keine E-Mail zurückgegeben'));
    exit;
}

$email = strtolower(trim($userInfo['email']));
$name = $userInfo['name'] ?? '';
$picture = $userInfo['picture'] ?? '';

// Step 4: Find existing user in our system
$user = one("SELECT * FROM users WHERE email=? LIMIT 1", [$email]);

if ($user) {
    // Existing user — log them in
    $type = $user['type'];
    $idCol = match($type) { 'admin' => 'admin_id', 'customer' => 'customer_id', 'employee' => 'emp_id', default => null };
    if ($idCol) {
        $row = one("SELECT * FROM `$type` WHERE email=? AND status=1 LIMIT 1", [$email]);
        if ($row) {
            session_regenerate_id(true);
            $_SESSION['uid'] = $row[$idCol];
            $_SESSION['uemail'] = $email;
            $_SESSION['uname'] = $row['name'] ?? $name;
            $_SESSION['utype'] = $type;
            header("Location: /$type/");
            exit;
        }
    }
    // User exists but no active account in type table
    header('Location: /login.php?err=' . urlencode('Konto deaktiviert. Kontaktieren Sie uns.'));
    exit;
}

// Step 5: New user — auto-create customer account
$existingCustomer = one("SELECT customer_id FROM customer WHERE email=? LIMIT 1", [$email]);
if ($existingCustomer) {
    // Customer exists but no users entry — create it
    q("INSERT INTO users (email, type) VALUES (?, 'customer')", [$email]);
    session_regenerate_id(true);
    $_SESSION['uid'] = $existingCustomer['customer_id'];
    $_SESSION['uemail'] = $email;
    $_SESSION['uname'] = $name;
    $_SESSION['utype'] = 'customer';
    header('Location: /customer/');
    exit;
}

// Brand new user — create customer + users entry
$nameParts = explode(' ', $name, 2);
$firstName = $nameParts[0] ?? $name;
$lastName = $nameParts[1] ?? '';

q("INSERT INTO customer (name, surname, email, phone, customer_type, status, created_at) VALUES (?, ?, ?, '', 'Regular', 1, NOW())",
  [$firstName, $lastName, $email]);
$newCid = (int) lastInsertId();
q("INSERT INTO users (email, type) VALUES (?, 'customer')", [$email]);

// Set a random password (user can reset later or always use Google)
q("UPDATE customer SET password=? WHERE customer_id=?", [password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), $newCid]);

audit('google_signup', 'customer', $newCid, "Google Sign-In: $name <$email>");
if (function_exists('telegramNotify')) {
    telegramNotify("🆕 <b>Neuer Kunde via Google</b>\n\n👤 $name\n📧 $email");
}

session_regenerate_id(true);
$_SESSION['uid'] = $newCid;
$_SESSION['uemail'] = $email;
$_SESSION['uname'] = $firstName;
$_SESSION['utype'] = 'customer';
header('Location: /customer/');
exit;
