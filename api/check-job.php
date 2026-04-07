<?php
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
echo json_encode(one("SELECT * FROM jobs WHERE j_id=?", [$_GET['id'] ?? 0]), JSON_PRETTY_PRINT);
