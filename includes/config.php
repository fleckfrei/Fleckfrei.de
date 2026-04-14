<?php
// Block direct access to includes
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'config.php') { http_response_code(403); exit; }

date_default_timezone_set('Europe/Berlin');

// ============================================================
// WHITE-LABEL CONFIG — Nur diese Sektion ändern für neues Branding
// ============================================================
define('SITE', 'Fleckfrei');                    // Firmenname
define('SITE_DOMAIN', 'fleckfrei.de');           // Domain
define('SITE_TAGLINE', 'Smart. Sauber. Zuverlässig.');
define('BRAND', '#2E7D6B');                      // Hauptfarbe (HEX)
define('BRAND_DARK', '#235F53');                  // Dunklere Variante
define('BRAND_LIGHT', '#E8F5F1');                 // Helle Variante
define('BRAND_RGB', '46,125,107');                // RGB für opacity
define('LOGO_LETTER', 'F');                       // Buchstabe für Logo-Icon
// Fleckfrei-Kontaktdaten (Max hat diese URLs bestätigt)
define('CONTACT_PHONE', '');                                              // Direkter tel: Link — leer = Button versteckt
define('CONTACT_WHATSAPP_URL', 'https://wa.me/message/OVHQQCZT7WYAH1');   // Offizieller WA Business Click-to-Chat Link
define('CONTACT_WHATSAPP', 'message/OVHQQCZT7WYAH1');                    // Für wa.me/ Prefix Compatibility
define('CONTACT_TELEGRAM', '@fleckfrei_bot');                             // Telegram bot username
define('CONTACT_EMAIL', 'info@fleckfrei.de');

// Nuki Smart Lock API Credentials
// Registriert bei https://web.nuki.io/de/#/admin/web-api
// Redirect URI muss dort gesetzt sein: https://app.fleckfrei.de/api/nuki-callback.php
define('NUKI_CLIENT_ID', '');                                             // TODO Max: Client ID von web.nuki.io eintragen (UUID)
define('NUKI_CLIENT_SECRET', '***REDACTED***');
define('NUKI_REDIRECT_URI', 'https://app.fleckfrei.de/api/nuki-callback.php');
define('NUKI_SCOPE', 'account notification smartlock smartlock.readOnly smartlock.action smartlock.auth');
define('NUKI_API_BASE', 'https://api.nuki.io');
define('CONTACT_WA', '');                          // Ohne + (removed)
define('CURRENCY', '€');
define('CURRENCY_POS', 'after');                   // 'before' ($100) oder 'after' (100 €)
define('MIN_HOURS', 2);                            // Minimum Stunden für Kunden-Abrechnung
define('TAX_RATE', 0.19);                          // MwSt-Satz (19%)
define('LOCALE', 'de');                            // Sprache
define('TIMEZONE', 'Europe/Berlin');

// ============================================================
// DATABASE — Direkt La-Renting DB (Master) + lokale DB (Fleckfrei-spezifisch)
// ============================================================
// Master DB (La-Renting auf Hostinger) — Jobs, Kunden, Partner, Services, Rechnungen
define('DB_HOST', 'localhost');                   // Hostinger MySQL (same server)
define('DB_NAME', 'u860899303_la_renting');
define('DB_USER', 'u860899303_root');
define('DB_PASS', '***REDACTED***');
// Lokale DB (GoDaddy) — Messages, Audit, Settings, Fleckfrei-only Tabellen
define('DB_LOCAL_HOST', 'localhost');
define('DB_LOCAL_NAME', 'i10205616_zlzy1');
define('DB_LOCAL_USER', 'i10205616_zlzy1');
define('DB_LOCAL_PASS', '***REDACTED***');
define('API_KEY', '***REDACTED***');

// ============================================================
// N8N WEBHOOKS — Pro Installation eigene URLs
// ============================================================
define('N8N_WEBHOOK_BOOKING', 'https://n8n.la-renting.com/webhook/fleckfrei-v2-booking');
define('N8N_WEBHOOK_STATUS', 'https://n8n.la-renting.com/webhook/fleckfrei-v2-job-status');
define('N8N_WEBHOOK_NOTIFY', 'https://n8n.la-renting.com/webhook/fleckfrei-v2-notify');
define('N8N_WEBHOOK_MESSAGE', 'https://n8n.la-renting.com/webhook/fleckfrei-v2-message');

// ============================================================
// OSINT API KEYS
// ============================================================
define('SHODAN_API_KEY', '***REDACTED***');
define('VT_API_KEY', '***REDACTED***');
define('HUNTER_API_KEY', '***REDACTED***');

// ============================================================
// FEATURES — An/Aus schalten pro Installation
// ============================================================
// ============================================================
// OPEN BANKING — Enable Banking (N26 Auto-Import)
// ============================================================
define('OPENBANKING_APP_ID', '681f85da-7d47-492e-9b77-1688b06ca401');
define('OPENBANKING_PEM_PATH', __DIR__ . '/openbanking.pem');
define('OPENBANKING_ACCOUNT_ID', '');   // N26 Account ID (set after bank linking)
define('FEATURE_AUTO_BANK', true);

// ============================================================
// STRIPE — Online Payment (Karte + SEPA)
// ============================================================
// Stripe keys loaded from secrets file (not in git)
$_stripeSecrets = __DIR__ . '/stripe-keys.php';
if (file_exists($_stripeSecrets)) { require_once $_stripeSecrets; }

// LLM API keys — gitignored, manually deployed
$_llmKeys = __DIR__ . '/llm-keys.php';
if (file_exists($_llmKeys)) { require_once $_llmKeys; }

$_telegramKeys = __DIR__ . "/telegram-keys.php";
if (file_exists($_telegramKeys)) { require_once $_telegramKeys; }

$_apifyKeys = __DIR__ . "/apify-keys.php";
if (file_exists($_apifyKeys)) { require_once $_apifyKeys; }
if (!defined('STRIPE_PK')) define('STRIPE_PK', '');
if (!defined('STRIPE_SK')) define('STRIPE_SK', '');
define('FEATURE_STRIPE', !empty(STRIPE_SK));

// ============================================================
// PAYPAL — Checkout (Button auf Kundenportal)
// ============================================================
$_paypalSecrets = __DIR__ . '/paypal-keys.php';
if (file_exists($_paypalSecrets)) { require_once $_paypalSecrets; }
if (!defined('PAYPAL_CLIENT_ID')) define('PAYPAL_CLIENT_ID', '');
if (!defined('PAYPAL_SECRET')) define('PAYPAL_SECRET', '');
define('PAYPAL_MODE', 'live');  // 'sandbox' or 'live'
define('PAYPAL_BASE', PAYPAL_MODE === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com');
define('FEATURE_PAYPAL', !empty(PAYPAL_CLIENT_ID) && !empty(PAYPAL_SECRET));

// ============================================================
// SMOOBU — Channel Manager (Booking.com, VRBO, Agoda, Airbnb)
// ============================================================
define('SMOOBU_API_KEY', '');  // Set in Smoobu Dashboard > Settings > API
define('SMOOBU_BASE', 'https://login.smoobu.com/api');
define('FEATURE_SMOOBU', !empty(SMOOBU_API_KEY));

define('FEATURE_OSINT', true);        // OSINT Scanner Seite
define('FEATURE_RECURRING', true);    // Wiederkehrende Jobs
define('FEATURE_AUDIT', true);        // Audit-Log
define('FEATURE_WHATSAPP', true);     // WhatsApp-Integration
define('FEATURE_TELEGRAM', false);    // Telegram-Integration
define('FEATURE_INVOICE_AUTO', true); // Auto-Rechnungserstellung

// ============================================================
// SYSTEM — Nicht ändern
// ============================================================
// Main DB (Hostinger localhost) — all reads and writes
// NOTE: Persistent connections caused repeated "server has gone away"
// errors because Hostinger's shared MySQL kills idle connections in
// the PHP-FPM pool. Switched to non-persistent: ~5ms extra per request
// for the MySQL handshake, but connection is always fresh and alive.
$db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$dbRemote = $db;

// Local DB — for local-only tables: messages, audit, settings
try {
    $dbLocal = new PDO("mysql:host=".DB_LOCAL_HOST.";dbname=".DB_LOCAL_NAME.";charset=utf8mb4", DB_LOCAL_USER, DB_LOCAL_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    $dbLocal = $db; // Fallback to main DB
}
define('DB_MODE', 'local');

// Force-refresh both DB connections with NON-persistent PDO instances.
// Even when a ping passes, Hostinger's persistent pool can hand us a
// connection that dies mid-query after a long cascade. Don't rely on
// the ping — always replace the connection. This adds ~5ms latency
// per gotham endpoint but eliminates "server has gone away" entirely.
function db_ping_reconnect(): void {
    global $db, $dbLocal;
    try {
        $db = new PDO(
            "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            // Deliberately NO PDO::ATTR_PERSISTENT so we get a fresh socket
        );
    } catch (Exception $e) { /* downstream catches will report */ }
    try {
        $dbLocal = new PDO(
            "mysql:host=".DB_LOCAL_HOST.";dbname=".DB_LOCAL_NAME.";charset=utf8mb4",
            DB_LOCAL_USER, DB_LOCAL_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (Exception $e) { $dbLocal = $db; }
}

// q() / qLocal() with transparent auto-reconnect on "gone away".
// Hostinger's MySQL has wait_timeout=20s. Any idle connection after
// a long operation (vulture-core cascade, sentinel scan, mass-ingest)
// gets killed mid-flight. Catching PDOException 2006 here and
// retrying once with a fresh PDO eliminates the entire bug class
// without changing any caller code.
function _is_gone_away(PDOException $e): bool {
    $msg = $e->getMessage();
    return strpos($msg, 'gone away') !== false
        || strpos($msg, '2006') !== false
        || strpos($msg, 'Lost connection') !== false
        || strpos($msg, 'Error reading result') !== false;
}
function _reconnect_db(): void {
    global $db;
    $db = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
function _reconnect_dbLocal(): void {
    global $dbLocal, $db;
    try {
        $dbLocal = new PDO("mysql:host=".DB_LOCAL_HOST.";dbname=".DB_LOCAL_NAME.";charset=utf8mb4", DB_LOCAL_USER, DB_LOCAL_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Exception $e) { $dbLocal = $db; }
}

function q($sql, $p=[]) {
    global $db;
    try {
        $s = $db->prepare($sql); $s->execute($p); return $s;
    } catch (PDOException $e) {
        if (!_is_gone_away($e)) throw $e;
        _reconnect_db();
        $s = $db->prepare($sql); $s->execute($p); return $s;
    }
}
function all($sql, $p=[]) { return q($sql,$p)->fetchAll(); }
function one($sql, $p=[]) { return q($sql,$p)->fetch(); }
function val($sql, $p=[]) { return q($sql,$p)->fetchColumn(); }

// Global helpers for last inserted ID — matches PDO::lastInsertId()
function lastInsertId() { global $db; return $db->lastInsertId(); }
function lastInsertIdLocal() { return lastInsertId(); }

// Local DB = Main DB (same u860899303_la_renting on Hostinger)
// Kept as aliases for backward compatibility (235 call sites)
function qLocal($sql, $p=[]) { return q($sql, $p); }
function allLocal($sql, $p=[]) { return all($sql, $p); }
function oneLocal($sql, $p=[]) { return one($sql, $p); }
function valLocal($sql, $p=[]) { return val($sql, $p); }
// Remote DB writes (goes to Hostinger master — synced back in 1 min)
function qRemote($sql, $p=[]) {
    global $dbRemote;
    $s = $dbRemote->prepare($sql); $s->execute($p);
    return $s;
}
// Dual-write: local (instant) + remote (Hostinger)
function qBoth($sql, $p=[]) {
    global $db, $dbRemote;
    $s = $db->prepare($sql); $s->execute($p);
    if ($dbRemote !== $db) { try { $s2 = $dbRemote->prepare($sql); $s2->execute($p); } catch (Exception $e) {} }
    return $s;
}
function e($s) { return htmlspecialchars($s??'', ENT_QUOTES, 'UTF-8'); }
function money($n) {
    $formatted = number_format((float)($n??0), 2, ',', '.');
    return CURRENCY_POS === 'before' ? CURRENCY . $formatted : $formatted . ' ' . CURRENCY;
}
function audit($action, $entity, $entityId, $details='') {
    if (!FEATURE_AUDIT) return;
    $user = $_SESSION['uname'] ?? 'API';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    try {
        qLocal("INSERT INTO audit_log (user_name,action,entity,entity_id,details,ip) VALUES (?,?,?,?,?,?)",
          [$user, $action, $entity, $entityId, $details, $ip]);
    } catch (Exception $e) {}
    // Shadow log critical operations as flat file backup
    if (in_array($entity, ['jobs','invoices','customer','employee','services','payments'], true)) {
        shadow_log($entity, $action, ['id' => $entityId, 'user' => $user, 'ip' => $ip, 'details' => $details]);
    }
}
function badge($status) {
    $c = ['PENDING'=>'yellow','CONFIRMED'=>'blue','RUNNING'=>'indigo','STARTED'=>'indigo','COMPLETED'=>'green','CANCELLED'=>'red'];
    $col = $c[$status] ?? 'gray';
    $labels = ['PENDING'=>'Offen','CONFIRMED'=>'Bestätigt','RUNNING'=>'Laufend','STARTED'=>'Gestartet','COMPLETED'=>'Erledigt','CANCELLED'=>'Storniert'];
    $label = $labels[$status] ?? $status;
    return "<span class=\"px-2 py-1 text-xs font-medium rounded-full bg-{$col}-100 text-{$col}-800\">$label</span>";
}

// ============================================================
// PRIVACY: Partner display for customer-facing views
// ============================================================
// Customers must NEVER see a partner's real name or private photo.
// Partner decides in /employee/profile.php what display_name + avatar
// the customer sees. Until they set one, show generic fallback.
// Always use these helpers in /customer/* pages.
function partnerDisplayName(?array $row): string {
    if (!$row || empty($row['ename']) && empty($row['display_name']) && empty($row['name'])) {
        return 'Ihr Partner';
    }
    $display = trim($row['edisplay'] ?? $row['display_name'] ?? '');
    if ($display !== '') return $display;
    return 'Ihr Partner';
}
function partnerAvatarUrl(?array $row): ?string {
    if (!$row) return null;
    $pic = $row['eavatar'] ?? $row['avatar'] ?? '';
    if ($pic && !str_starts_with($pic, '/') && !str_starts_with($pic, 'http')) {
        $pic = '/uploads/' . ltrim($pic, '/');
    }
    return $pic ?: null;
}
function partnerInitial(?array $row): string {
    $name = partnerDisplayName($row);
    if ($name === 'Ihr Partner') return 'P';
    return strtoupper(mb_substr($name, 0, 1));
}
require_once __DIR__ . '/lang.php';

/**
 * DSGVO-CONSENT-GUARDS — zentrale Prüfung ob Kunde Marketing-Channel erlaubt.
 * Automatisch von allen Marketing-Funktionen aufgerufen.
 * @return bool true = darf senden, false = gesperrt
 */
function canContact(int $customerId, string $channel): bool {
    if ($customerId <= 0) return false;
    $col = match($channel) {
        'email' => 'consent_email',
        'whatsapp', 'wa' => 'consent_whatsapp',
        'phone', 'sms' => 'consent_phone',
        default => null
    };
    if (!$col) return false;
    try {
        $row = one("SELECT $col as c FROM customer WHERE customer_id=? AND status=1", [$customerId]);
        return !empty($row['c']);
    } catch (Exception $e) {
        // Fail-safe: im Zweifel KEIN Senden (DSGVO sicher)
        return false;
    }
}

/**
 * Log-Wrapper für Marketing-Versand. Prüft Consent + loggt in consent_history.
 * Wenn consent fehlt: silent fail mit Audit-Eintrag.
 * @return bool true = wurde gesendet, false = geblockt
 */
function marketingSend(int $customerId, string $channel, string $subject, callable $sendFn): bool {
    if (!canContact($customerId, $channel)) {
        audit('blocked_marketing', $channel, $customerId, "Consent fehlt für '$subject' — nicht gesendet");
        return false;
    }
    try {
        $result = $sendFn();
        audit('marketing_sent', $channel, $customerId, $subject);
        return $result !== false;
    } catch (Exception $e) {
        audit('marketing_error', $channel, $customerId, $subject . ' — ' . $e->getMessage());
        return false;
    }
}

function telegramNotify($message) {
    if (!defined('N8N_WEBHOOK_NOTIFY') || !N8N_WEBHOOK_NOTIFY) return;
    @file_get_contents(N8N_WEBHOOK_NOTIFY, false, stream_context_create([
        'http' => ['method'=>'POST', 'header'=>"Content-Type: application/json\r\n",
            'content'=>json_encode(['message' => $message]), 'timeout'=>3]
    ]));
}

/**
 * Zentrale Benachrichtigungs-Engine.
 * - Loggt JEDE Aktion in activity_log (unabhängig von Permissions)
 * - Prüft user_notification_prefs ob/wohin benachrichtigen
 * - Default: Admin bekommt IMMER, User nur wenn er opted-in
 *
 * @param string $actorType customer|employee|admin|system
 * @param int $actorId
 * @param string $eventType z.B. job_reschedule, job_cancel, note_added, payment_made
 * @param string $targetType jobs|invoices|customer|employee|note
 * @param int|null $targetId
 * @param string $humanMsg Kurztext für Notification (z.B. "Max hat Job #42 umgebucht")
 * @param array $details extra data für log
 */
function notifyEvent(string $actorType, int $actorId, string $eventType, string $targetType, ?int $targetId, string $humanMsg, array $details = []): void {
    try {
        global $dbLocal;
        // 1. In activity_log schreiben
        $stmt = ($dbLocal ?? $GLOBALS['db'])->prepare("INSERT INTO activity_log (actor_type, actor_id, target_type, target_id, event_type, action, details, ip_address, user_agent) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $actorType, $actorId, $targetType, $targetId, $eventType,
            $details['action'] ?? $eventType,
            json_encode($details, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
        ]);
    } catch (Exception $e) { /* log failure ok */ }

    // 2. Admin IMMER notifizieren (außer Admin ist selbst der Actor)
    $adminShouldGet = $actorType !== 'admin';
    // Check if admin has disabled this event type
    try {
        $adminPref = one("SELECT enabled, channel FROM user_notification_prefs WHERE user_type='admin' AND user_id=0 AND event_type=?", [$eventType]);
        if ($adminPref && !$adminPref['enabled']) $adminShouldGet = false;
    } catch (Exception $e) {}

    if ($adminShouldGet) {
        telegramNotify("🔔 <b>{$eventType}</b>\n\n{$humanMsg}\n\n<i>von {$actorType} #{$actorId}</i>");
    }

    // 3. Dem betroffenen User (nicht Actor) benachrichtigen falls Pref erlaubt
    $targetUserType = null; $targetUserId = null;
    if ($targetType === 'jobs' && $targetId) {
        try {
            $job = one("SELECT customer_id_fk, emp_id_fk FROM jobs WHERE j_id=?", [$targetId]);
            // Actor ist Customer → Employee benachrichtigen (wenn vorhanden)
            if ($actorType === 'customer' && !empty($job['emp_id_fk'])) {
                $targetUserType = 'employee'; $targetUserId = $job['emp_id_fk'];
            }
            // Actor ist Admin/Employee → Customer benachrichtigen
            if ($actorType !== 'customer' && !empty($job['customer_id_fk'])) {
                $targetUserType = 'customer'; $targetUserId = $job['customer_id_fk'];
            }
        } catch (Exception $e) {}
    }

    if ($targetUserType && $targetUserId) {
        try {
            $pref = one("SELECT enabled, channel FROM user_notification_prefs WHERE user_type=? AND user_id=? AND event_type=?", [$targetUserType, $targetUserId, $eventType]);
            // Default: user bekommt NICHTS (außer explizit opted-in)
            if ($pref && $pref['enabled']) {
                if ($pref['channel'] === 'email') {
                    $table = $targetUserType === 'customer' ? 'customer' : 'employee';
                    $idCol = $targetUserType === 'customer' ? 'customer_id' : 'emp_id';
                    $user = one("SELECT email, name FROM $table WHERE $idCol=?", [$targetUserId]);
                    if (!empty($user['email']) && function_exists('sendEmail')) {
                        sendEmail($user['email'], SITE . ' — Update zu Ihrem Termin', "<p>{$humanMsg}</p>");
                    }
                }
                // TODO: push, in_app channels
            }
        } catch (Exception $e) {}
    }
}

/**
 * Default-Permissions für einen neuen Kunden/Partner anlegen.
 * Admin-pref mit user_id=0 ist der globale Default.
 */
function ensureDefaultNotifPrefs(string $userType, int $userId): void {
    $defaults = [
        // Events die Admin IMMER sehen soll (enabled=1)
        'admin' => [
            'job_created' => 1, 'job_rescheduled' => 1, 'job_cancelled' => 1,
            'job_edited' => 1, 'job_completed' => 1, 'note_added' => 1,
            'payment_made' => 1, 'photo_uploaded' => 1, 'customer_edited' => 1,
            'partner_absent' => 1, 'rating_submitted' => 1
        ],
        // Kunden-Default: nur wichtige Updates
        'customer' => [
            'job_created' => 1, 'job_rescheduled' => 1, 'job_cancelled' => 1,
            'job_completed' => 1, 'payment_reminder' => 1,
            'note_added' => 0, 'job_edited' => 0
        ],
        // Partner-Default: Job-relevante Events
        'employee' => [
            'job_created' => 1, 'job_assigned' => 1, 'job_rescheduled' => 1,
            'job_cancelled' => 1, 'note_added' => 1
        ]
    ];
    $prefs = $defaults[$userType] ?? [];
    foreach ($prefs as $event => $enabled) {
        try {
            q("INSERT IGNORE INTO user_notification_prefs (user_type, user_id, event_type, channel, enabled) VALUES (?,?,?,'telegram',?)",
              [$userType, $userId, $event, $enabled]);
        } catch (Exception $e) {}
    }
}

function webhookNotify($event, $data) {
    $url = match($event) {
        'booking' => N8N_WEBHOOK_BOOKING,
        'status' => N8N_WEBHOOK_STATUS,
        default => null
    };
    if (!$url) return;
    $payload = json_encode($data);
    $resp = @file_get_contents($url, false, stream_context_create([
        'http' => ['method'=>'POST', 'header'=>"Content-Type: application/json\r\n", 'content'=>$payload, 'timeout'=>5]
    ]));
    // If webhook failed, queue for retry
    if ($resp === false) {
        webhook_queue($url, $payload, "event:{$event}");
    }
}

/**
 * Queue a failed webhook for retry (exponential backoff, max 5 attempts)
 */
function webhook_queue(string $url, string $payload, string $context = '') {
    try {
        q("INSERT INTO failed_webhooks (url, payload, context, next_retry_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 60 SECOND))",
          [$url, $payload, $context]);
    } catch (Exception $e) {
        // Table doesn't exist yet — create it
        try {
            q("CREATE TABLE IF NOT EXISTS failed_webhooks (
                fw_id INT AUTO_INCREMENT PRIMARY KEY, url VARCHAR(500), payload LONGTEXT, context VARCHAR(255),
                http_code INT DEFAULT 0, error_msg TEXT, attempts TINYINT DEFAULT 0, max_attempts TINYINT DEFAULT 5,
                status ENUM('pending','success','failed','abandoned') DEFAULT 'pending',
                next_retry_at TIMESTAMP NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status, next_retry_at))");
            q("INSERT INTO failed_webhooks (url, payload, context, next_retry_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 60 SECOND))",
              [$url, $payload, $context]);
        } catch (Exception $e2) { /* silent */ }
    }
}

/**
 * Shadow Log — write critical operations to flat file as backup
 * If DB crashes, these logs can reconstruct lost data.
 * Format: TSV (tab-separated), one line per operation.
 */
function shadow_log(string $table, string $action, array $data) {
    static $dir = null;
    if ($dir === null) {
        $dir = '/home/u860899303/backups/shadow_log/';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
    }
    $file = $dir . date('Y-m-d') . '.tsv';
    $line = implode("\t", [
        date('Y-m-d H:i:s'),
        $table,
        $action,
        json_encode($data, JSON_UNESCAPED_UNICODE),
    ]) . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

require_once __DIR__ . '/schema.php';
