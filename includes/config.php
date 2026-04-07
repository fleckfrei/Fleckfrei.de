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
define('CONTACT_PHONE', '+4915757010977');         // WhatsApp/Telefon
define('CONTACT_EMAIL', 'info@fleckfrei.de');
define('CONTACT_WA', '4915757010977');             // Ohne +
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
define('DB_HOST', '31.97.198.95');              // Hostinger Remote MySQL
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

// ============================================================
// FEATURES — An/Aus schalten pro Installation
// ============================================================
// ============================================================
// OPEN BANKING — Enable Banking (N26 Auto-Import)
// ============================================================
define('OPENBANKING_APP_ID', '556ddcde-851b-452e-be27-b1cea95ffc7e');
define('OPENBANKING_PEM_PATH', __DIR__ . '/openbanking.pem');
define('OPENBANKING_ACCOUNT_ID', '');   // N26 Account ID (set after bank linking)
define('FEATURE_AUTO_BANK', true);

define('FEATURE_OSINT', true);        // OSINT Scanner Seite
define('FEATURE_RECURRING', true);    // Wiederkehrende Jobs
define('FEATURE_AUDIT', true);        // Audit-Log
define('FEATURE_WHATSAPP', true);     // WhatsApp-Integration
define('FEATURE_TELEGRAM', false);    // Telegram-Integration
define('FEATURE_INVOICE_AUTO', true); // Auto-Rechnungserstellung

// ============================================================
// SYSTEM — Nicht ändern
// ============================================================
// FAST MODE: Local DB for reads (1ms), Sync from Hostinger every minute
// This gives us: instant page loads + near-real-time data
$db = new PDO("mysql:host=".DB_LOCAL_HOST.";dbname=".DB_LOCAL_NAME.";charset=utf8mb4", DB_LOCAL_USER, DB_LOCAL_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_PERSISTENT => true
]);
define('DB_MODE', 'fast');

// Remote DB for writes that need to go to Hostinger (bookings, status changes)
try {
    $dbRemote = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_TIMEOUT => 3
    ]);
} catch (Exception $e) {
    $dbRemote = $db; // Fallback: write to local
}

// In fast mode, local DB IS the main DB — all reads are local (1ms)
$dbLocal = $db;

function q($sql, $p=[]) { global $db; $s=$db->prepare($sql); $s->execute($p); return $s; }
function all($sql, $p=[]) { return q($sql,$p)->fetchAll(); }
function one($sql, $p=[]) { return q($sql,$p)->fetch(); }
function val($sql, $p=[]) { return q($sql,$p)->fetchColumn(); }
// Local DB queries (messages, audit, settings, gps)
function qLocal($sql, $p=[]) { global $dbLocal; $s=$dbLocal->prepare($sql); $s->execute($p); return $s; }
function allLocal($sql, $p=[]) { return qLocal($sql,$p)->fetchAll(); }
function oneLocal($sql, $p=[]) { return qLocal($sql,$p)->fetch(); }
function valLocal($sql, $p=[]) { return qLocal($sql,$p)->fetchColumn(); }
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
    try {
        $user = $_SESSION['uname'] ?? 'API';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        qLocal("INSERT INTO audit_log (user_name,action,entity,entity_id,details,ip) VALUES (?,?,?,?,?,?)",
          [$user, $action, $entity, $entityId, $details, $ip]);
    } catch (Exception $e) {}
}
function badge($status) {
    $c = ['PENDING'=>'yellow','CONFIRMED'=>'blue','RUNNING'=>'indigo','STARTED'=>'indigo','COMPLETED'=>'green','CANCELLED'=>'red'];
    $col = $c[$status] ?? 'gray';
    $labels = ['PENDING'=>'Offen','CONFIRMED'=>'Bestätigt','RUNNING'=>'Laufend','STARTED'=>'Gestartet','COMPLETED'=>'Erledigt','CANCELLED'=>'Storniert'];
    $label = $labels[$status] ?? $status;
    return "<span class=\"px-2 py-1 text-xs font-medium rounded-full bg-{$col}-100 text-{$col}-800\">$label</span>";
}
require_once __DIR__ . '/lang.php';

function telegramNotify($message) {
    if (!defined('N8N_WEBHOOK_NOTIFY') || !N8N_WEBHOOK_NOTIFY) return;
    @file_get_contents(N8N_WEBHOOK_NOTIFY, false, stream_context_create([
        'http' => ['method'=>'POST', 'header'=>"Content-Type: application/json\r\n",
            'content'=>json_encode(['message' => $message]), 'timeout'=>3]
    ]));
}

function webhookNotify($event, $data) {
    $url = match($event) {
        'booking' => N8N_WEBHOOK_BOOKING,
        'status' => N8N_WEBHOOK_STATUS,
        default => null
    };
    if (!$url) return;
    @file_get_contents($url, false, stream_context_create([
        'http' => ['method'=>'POST', 'header'=>"Content-Type: application/json\r\n", 'content'=>json_encode($data), 'timeout'=>3]
    ]));
}
