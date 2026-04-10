<?php
// Booking flow v2 is not built yet — redirect to the working v1 booking.
// Planned for Session 12+: 3-step Helpling-style flow with live pricing sidebar.
require_once __DIR__ . '/../../includes/auth.php';
requireCustomer();

$qs = '';
if (!empty($_GET)) {
    $qs = '?' . http_build_query($_GET);
}

header('Location: /customer/booking.php' . $qs);
exit;
