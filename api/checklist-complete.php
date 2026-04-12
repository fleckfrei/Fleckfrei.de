<?php
/**
 * Checklist completion — partner marks an item as done (optionally with note + photo)
 *
 * POST /api/checklist-complete.php  (multipart/form-data, session auth)
 * Fields: job_id, checklist_id, completed (0|1), note?, photo? (file)
 */
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$utype = $_SESSION['utype'] ?? '';
$uid = (int)($_SESSION['uid'] ?? 0);
if (!in_array($utype, ['employee', 'admin'], true) || !$uid) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$jobId = (int)($_POST['job_id'] ?? 0);
$checklistId = (int)($_POST['checklist_id'] ?? 0);
$completed = (int)($_POST['completed'] ?? 0);
$note = trim($_POST['note'] ?? '');

if (!$jobId || !$checklistId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing job_id/checklist_id']);
    exit;
}

// Verify this partner is assigned to that job (admin bypass)
if ($utype === 'employee') {
    $job = one("SELECT j_id, emp_id_fk, s_id_fk FROM jobs WHERE j_id=? AND emp_id_fk=?", [$jobId, $uid]);
    if (!$job) {
        http_response_code(403);
        echo json_encode(['error' => 'Job not assigned to you']);
        exit;
    }
    // Checklist must belong to the same service
    $item = one("SELECT checklist_id FROM service_checklists WHERE checklist_id=? AND s_id_fk=?", [$checklistId, $job['s_id_fk']]);
    if (!$item) {
        http_response_code(403);
        echo json_encode(['error' => 'Checklist item not part of this job']);
        exit;
    }
}

// Handle photo upload
$photoPath = null;
if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $tmp = $_FILES['photo']['tmp_name'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic'];
    if (isset($allowedMimes[$mime]) && $_FILES['photo']['size'] < 10 * 1024 * 1024) {
        $dir = __DIR__ . '/../uploads/checklists/completed/' . $jobId . '/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $fname = bin2hex(random_bytes(8)) . '.' . $allowedMimes[$mime];
        if (move_uploaded_file($tmp, $dir . $fname)) {
            $photoPath = '/uploads/checklists/completed/' . $jobId . '/' . $fname;
        }
    }
}

// Upsert
$existing = one("SELECT completion_id, photo FROM checklist_completions WHERE job_id_fk=? AND checklist_id_fk=?", [$jobId, $checklistId]);
if ($existing) {
    // Keep existing photo if no new one uploaded
    $finalPhoto = $photoPath ?: $existing['photo'];
    q("UPDATE checklist_completions SET completed=?, note=?, photo=?, completed_at=? WHERE completion_id=?",
      [$completed, $note, $finalPhoto, $completed ? date('Y-m-d H:i:s') : null, $existing['completion_id']]);
    $completionId = $existing['completion_id'];
} else {
    q("INSERT INTO checklist_completions (job_id_fk, checklist_id_fk, completed, note, photo, completed_at) VALUES (?,?,?,?,?,?)",
      [$jobId, $checklistId, $completed, $note, $photoPath, $completed ? date('Y-m-d H:i:s') : null]);
    $completionId = (int) lastInsertId();
}

echo json_encode([
    'success' => true,
    'completion_id' => $completionId,
    'completed' => (bool)$completed,
    'photo' => $photoPath,
]);
