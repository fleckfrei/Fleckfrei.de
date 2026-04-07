<?php
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
$jid = (int)($_GET['id'] ?? 0);
if (!$jid) { echo json_encode(['error' => 'Invalid ID']); exit; }
echo json_encode(one("SELECT * FROM jobs WHERE j_id=?", [$jid]), JSON_PRETTY_PRINT);
