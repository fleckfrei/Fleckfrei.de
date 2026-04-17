<?php
session_set_cookie_params([
    'lifetime' => 7200,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/translate-helper.php';

// Auto-start page translation for employees (German source → Romanian).
// Cached per-string in translation_cache, so first view ~5s, subsequent <50ms.
if (($_SESSION['utype'] ?? '') === 'employee' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if (function_exists('pageTranslateStart')) { pageTranslateStart('ro'); }
}
// Shutdown hook: flush translated output
register_shutdown_function(function() {
    if (function_exists('pageTranslateEnd') && !empty($GLOBALS['__pageTranslateLang'])) {
        pageTranslateEnd();
    }
});


function urlSlug($name, $id) {
    $name = strtolower(trim((string)$name));
    $name = strtr($name, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
    $name = preg_replace('/[^a-z0-9]+/', '-', $name);
    $name = trim($name, '-');
    $name = substr($name, 0, 30);
    return $name ? ($name . '-' . (int)$id) : (string)(int)$id;
}
function requireLogin($type = null) {
    if (empty($_SESSION['uid']) || empty($_SESSION['utype'])) { header('Location: /login.php'); exit; }
    if ($type && $_SESSION['utype'] !== $type) { header('Location: /login.php'); exit; }
    // URL-Personalisierung: hänge ?u={uid} an für customer/employee — hilft beim Debug/Support
    if (in_array($_SESSION['utype'], ['customer','employee'], true)
        && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && empty($_GET['u'])
        && empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && !headers_sent()) {
        $sep = strpos($_SERVER['REQUEST_URI'], '?') === false ? '?' : '&';
        header('Location: ' . $_SERVER['REQUEST_URI'] . $sep . 'u=' . urlSlug($_SESSION['uname'] ?? '', $_SESSION['uid']));
        exit;
    }
}
function requireAdmin() { requireLogin('admin'); }
function requireCustomer() { requireLogin('customer'); }
function requireEmployee() { requireLogin('employee'); }
function me() {
    return ['id'=>$_SESSION['uid']??0, 'name'=>$_SESSION['uname']??'', 'email'=>$_SESSION['uemail']??'', 'type'=>$_SESSION['utype']??''];
}
// CSRF token
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function csrfField() {
    return '<input type="hidden" name="_csrf" value="' . csrfToken() . '"/>';
}
function verifyCsrf() {
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}
// Check if employee has a specific permission
function employeeCan($perm) {
    if (($_SESSION['utype'] ?? '') !== 'employee') return true;
    if (!empty($_SESSION['admin_uid'])) return true;
    $eid = $_SESSION['uid'] ?? 0;
    if (!$eid) return false;
    static $eperms = null;
    if ($eperms === null) {
        $raw = val("SELECT email_permissions FROM employee WHERE emp_id=?", [$eid]);
        $eperms = json_decode($raw ?: '{}', true);
        // BUG-FIX: json_decode('{}') returns [] (empty array, not null) — also apply defaults when empty
        if (!is_array($eperms) || empty($eperms)) $eperms = ['portal_dashboard'=>1,'portal_jobs'=>1,'portal_messages'=>1,'portal_profile'=>1,'can_start_stop'=>1,'can_cancel'=>1,'can_upload_photos'=>1,'can_see_customer_info'=>1,'can_see_address'=>1];
    }
    return !empty($eperms[$perm]);
}

// Check if customer has a specific permission
function customerCan($perm) {
    if (($_SESSION['utype'] ?? '') !== 'customer') return true;
    if (!empty($_SESSION['admin_uid'])) return true;
    $cid = $_SESSION['uid'] ?? 0;
    if (!$cid) return false;
    static $perms = null;
    if ($perms === null) {
        $raw = val("SELECT email_permissions FROM customer WHERE customer_id=?", [$cid]);
        $defaults = ['dashboard'=>1,'jobs'=>1,'invoices'=>1,'workhours'=>1,'profile'=>1,'booking'=>1,'documents'=>1,'messages'=>1,'cancel'=>1,'recurring'=>0,'rate'=>1,'calendar'=>1,'wh_umsatz'=>1,'wh_fotos'=>1];
        $perms = json_decode($raw ?: '{}', true);
        // BUG-FIX 2026-04-17: json_decode('{}') returns [] (empty array, NOT null).
        // Old code only applied defaults when !is_array, so empty-array case leaked through
        // and customers with empty email_permissions saw NOTHING.
        if (!is_array($perms) || empty($perms)) {
            $r = trim((string)$raw);
            // Leer oder nur "all" → alle Standard-Rechte
            if ($r === '' || $r === 'all' || $r === '{}' || empty($perms)) {
                $perms = $defaults;
            } else {
                // Komma-getrennte Liste parsen — "all" als Eintrag = alle Standard-Rechte + extras
                $parts = array_filter(array_map('trim', explode(',', $r)));
                $perms = in_array('all', $parts, true) ? $defaults : [];
                foreach ($parts as $p) {
                    if ($p !== 'all') $perms[$p] = 1;
                }
            }
        }
    }
    return !empty($perms[$perm]);
}
