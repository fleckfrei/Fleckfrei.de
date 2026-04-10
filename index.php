<?php
/**
 * Root redirect for app.fleckfrei.de
 * — authenticated admin → /admin/
 * — authenticated customer → /customer/
 * — not authenticated → /login.php
 */
require_once __DIR__ . '/includes/auth.php';

$user = function_exists('me') ? me() : null;

if ($user) {
    $role = $user['role'] ?? 'customer';
    header('Location: ' . ($role === 'admin' ? '/admin/' : '/customer/'));
    exit;
}

header('Location: /login.php');
exit;
