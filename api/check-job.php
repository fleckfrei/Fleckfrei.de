<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
// Require authenticated session
$utype = $_SESSION['utype'] ?? '';
$uid = (int)($_SESSION['uid'] ?? 0);
if (!$uid || !in_array($utype, ['admin', 'employee', 'customer'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
$jid = (int)($_GET['id'] ?? 0);
if (!$jid) { echo json_encode(['error' => 'Invalid ID']); exit; }
// Scope to own data (customers only see their jobs, employees their assigned jobs)
$job = match($utype) {
    'admin' => one("SELECT * FROM jobs WHERE j_id=?", [$jid]),
    'customer' => one("SELECT * FROM jobs WHERE j_id=? AND customer_id_fk=?", [$jid, $uid]),
    'employee' => one("SELECT * FROM jobs WHERE j_id=? AND emp_id_fk=?", [$jid, $uid]),
};
if (!$job) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
echo json_encode($job, JSON_PRETTY_PRINT);
