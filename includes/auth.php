<?php
session_start();
require_once __DIR__ . '/config.php';

function requireLogin($type = null) {
    if (empty($_SESSION['uid']) || empty($_SESSION['utype'])) { header('Location: /login.php'); exit; }
    if ($type && $_SESSION['utype'] !== $type) { header('Location: /login.php'); exit; }
}
function requireAdmin() { requireLogin('admin'); }
function requireCustomer() { requireLogin('customer'); }
function requireEmployee() { requireLogin('employee'); }
function me() {
    return ['id'=>$_SESSION['uid']??0, 'name'=>$_SESSION['uname']??'', 'email'=>$_SESSION['uemail']??'', 'type'=>$_SESSION['utype']??''];
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
        if (!is_array($eperms)) $eperms = ['portal_dashboard'=>1,'portal_jobs'=>1,'portal_messages'=>1,'portal_profile'=>1,'can_start_stop'=>1,'can_cancel'=>1,'can_upload_photos'=>1,'can_see_customer_info'=>1,'can_see_address'=>1];
    }
    return !empty($eperms[$perm]);
}

// Check if customer has a specific permission
function customerCan($perm) {
    if (($_SESSION['utype'] ?? '') !== 'customer') return true; // admin/employee can do everything
    if (!empty($_SESSION['admin_uid'])) return true; // impersonating admin can see everything
    $cid = $_SESSION['uid'] ?? 0;
    if (!$cid) return false;
    static $perms = null;
    if ($perms === null) {
        $raw = val("SELECT email_permissions FROM customer WHERE customer_id=?", [$cid]);
        $perms = json_decode($raw ?: '{}', true);
        if (!is_array($perms)) $perms = ($raw === 'all' || $raw === '') ? ['dashboard'=>1,'jobs'=>1,'invoices'=>1,'workhours'=>1,'profile'=>1,'booking'=>1,'documents'=>1,'messages'=>1,'cancel'=>0,'recurring'=>0] : [];
    }
    return !empty($perms[$perm]);
}
