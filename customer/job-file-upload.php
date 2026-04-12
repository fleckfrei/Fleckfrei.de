<?php
/**
 * Upload für Host-Anweisungen (Bilder/Videos/Dokumente) pro Job.
 * Auto-Kompression clientside + Server-Validation.
 */
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
header('Content-Type: application/json');
$cid = me()['id'];

try {
    $jobId = (int)($_POST['j_id'] ?? 0);
    if (!$jobId) throw new Exception('Need j_id');
    $job = one("SELECT j_id, customer_id_fk FROM jobs WHERE j_id=? AND customer_id_fk=? AND status=1", [$jobId, $cid]);
    if (!$job) throw new Exception('Job nicht gefunden oder nicht autorisiert');

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Kein File hochgeladen');
    }
    $file = $_FILES['file'];
    $allowedExt = ['jpg','jpeg','png','webp','gif','mp4','webm','mov','pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt)) throw new Exception('Dateiformat nicht erlaubt');

    // Max 10 MB (client should compress)
    if ($file['size'] > 10 * 1024 * 1024) throw new Exception('Datei zu groß (>10MB). Bitte komprimieren.');

    // Target dir
    $uploadDir = __DIR__ . '/../uploads/jobs/' . $jobId;
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    // Save with unique name
    $fileName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $uploadDir . '/' . $fileName;
    if (!move_uploaded_file($file['tmp_name'], $target)) throw new Exception('Speichern fehlgeschlagen');

    // Relative URL
    $url = '/uploads/jobs/' . $jobId . '/' . $fileName;

    // Metadata in job.job_file (JSON array)
    global $dbLocal;
    $current = one("SELECT job_file FROM jobs WHERE j_id=?", [$jobId]);
    $existing = json_decode($current['job_file'] ?? '[]', true) ?: [];
    $existing[] = [
        'url' => $url,
        'type' => in_array($ext, ['mp4','webm','mov']) ? 'video' : (in_array($ext, ['jpg','jpeg','png','webp','gif']) ? 'image' : 'document'),
        'original_name' => substr($file['name'], 0, 100),
        'size' => $file['size'],
        'uploaded_by' => 'customer',
        'uploaded_at' => date('Y-m-d H:i:s')
    ];
    q("UPDATE jobs SET job_file=? WHERE j_id=?", [json_encode($existing), $jobId]);

    audit('upload', 'job_file', $jobId, "Upload: {$file['name']} ({$file['size']} bytes)");

    echo json_encode(['success' => true, 'data' => [
        'url' => $url,
        'type' => $existing[count($existing)-1]['type'],
        'count' => count($existing)
    ]]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
