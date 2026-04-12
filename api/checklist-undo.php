<?php
/**
 * Undo last N checklist items for a service (soft-delete the most recent imports).
 * GET ?service_id=X&count=N
 */
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
header('Content-Type: application/json');

$cid = me()['id'];
$serviceId = (int)($_GET['service_id'] ?? 0);
$count = max(1, min(50, (int)($_GET['count'] ?? 0)));

if (!$serviceId || !$count) {
    echo json_encode(['success' => false, 'error' => 'missing params']);
    exit;
}

// Verify service belongs to customer
$svc = one("SELECT s_id FROM services WHERE s_id=? AND customer_id_fk=?", [$serviceId, $cid]);
if (!$svc) {
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

// Soft-delete the last N active items (most recently added = highest checklist_id)
$lastItems = all(
    "SELECT checklist_id FROM service_checklists WHERE s_id_fk=? AND customer_id_fk=? AND is_active=1 ORDER BY checklist_id DESC LIMIT ?",
    [$serviceId, $cid, $count]
);

$ids = array_column($lastItems, 'checklist_id');
if (!empty($ids)) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    q("UPDATE service_checklists SET is_active=0 WHERE checklist_id IN ($placeholders) AND customer_id_fk=?",
      array_merge($ids, [$cid]));
}

echo json_encode(['success' => true, 'removed' => count($ids)]);
