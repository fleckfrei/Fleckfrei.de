<?php
session_start();
if (!empty($_SESSION['admin_uid'])) {
    $_SESSION['uid'] = $_SESSION['admin_uid'];
    $_SESSION['uname'] = $_SESSION['admin_uname'];
    $_SESSION['uemail'] = $_SESSION['admin_uemail'];
    $_SESSION['utype'] = 'admin';
    unset($_SESSION['admin_uid'], $_SESSION['admin_uname'], $_SESSION['admin_uemail']);
}
header('Location: /admin/customers.php');
exit;
