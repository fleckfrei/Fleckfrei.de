<?php
/**
 * Public catalog for booking extras — rooms, tasks, optional products.
 * Consumed by book.php at runtime.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/config.php';

$rooms = all("SELECT br_id AS id, label, icon FROM booking_rooms WHERE is_active=1 ORDER BY sort_order, label");
$tasks = all("SELECT bt_id AS id, label, icon FROM booking_tasks WHERE is_active=1 ORDER BY sort_order, label");

$addons = [];
try {
    $addons = all("SELECT op_id AS id, name AS label, description, customer_price AS price, icon
                   FROM optional_products
                   WHERE is_active=1 AND visibility != 'hidden'
                   ORDER BY sort_order, name");
} catch (Exception $e) {}

echo json_encode([
    'rooms'  => $rooms,
    'tasks'  => $tasks,
    'addons' => $addons,
], JSON_UNESCAPED_UNICODE);
