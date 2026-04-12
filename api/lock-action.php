<?php
/**
 * Lock Action API — Partners trigger unlock, system enforces time window.
 *
 * POST /api/lock-action.php
 * { "action": "unlock" | "lock" | "status", "lock_id": 42, "job_id": 12345 }
 *
 * Auth: session (partner, admin, or customer self-test)
 * Time window: 15 min before job start → 30 min after job end
 * Unless admin → always allowed
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nuki-helpers.php';

header('Content-Type: application/json; charset=utf-8');

$utype = $_SESSION['utype'] ?? '';
// lock_events enum only knows 'customer','partner','admin','auto','system'
$logType = $utype === 'employee' ? 'partner' : $utype;
$uid = (int) ($_SESSION['uid'] ?? 0);
$uname = $_SESSION['uname'] ?? '';

if (!in_array($utype, ['customer','employee','admin'], true) || !$uid) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$action = $body['action'] ?? '';
$lockId = (int) ($body['lock_id'] ?? 0);
$jobId = (int) ($body['job_id'] ?? 0);

if (!$lockId || !in_array($action, ['unlock', 'lock', 'status'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

// Load lock
$lock = one("SELECT * FROM smart_locks WHERE lock_id=? AND is_active=1", [$lockId]);
if (!$lock) {
    http_response_code(404);
    echo json_encode(['error' => 'Lock not found']);
    exit;
}

// ============================================================
// Authorization check
// ============================================================
$allowed = false;
$reason = '';

if ($utype === 'admin') {
    $allowed = true;
    $reason = 'admin';
} elseif ($utype === 'customer' && (int)$lock['customer_id_fk'] === $uid) {
    $allowed = true;
    $reason = 'customer_self';
} elseif ($utype === 'employee' && $jobId) {
    // Partner must have an assigned job to this customer+service, within time window
    $job = one("SELECT * FROM jobs WHERE j_id=? AND emp_id_fk=? AND status=1", [$jobId, $uid]);
    if ($job && (int)$job['customer_id_fk'] === (int)$lock['customer_id_fk']) {
        // Check: if lock is linked to a service, job must be for that service
        if ($lock['linked_service_id'] && (int)$job['s_id_fk'] !== (int)$lock['linked_service_id']) {
            $reason = 'service_mismatch';
        } else {
            // Time window: 15 min before j_time → 30 min after j_time + j_hours
            $jobStart = strtotime($job['j_date'] . ' ' . $job['j_time']);
            $jobHours = (float) ($job['total_hours'] ?: $job['j_hours'] ?: 3);
            $windowStart = $jobStart - 15 * 60;
            $windowEnd = $jobStart + ($jobHours * 3600) + 30 * 60;
            $now = time();
            if ($now >= $windowStart && $now <= $windowEnd) {
                $allowed = true;
                $reason = 'partner_in_window';
            } else {
                $reason = $now < $windowStart ? 'too_early' : 'too_late';
            }
        }
    } else {
        $reason = 'no_matching_job';
    }
}

if (!$allowed) {
    logLockEvent($lockId, (int)$lock['customer_id_fk'], $logType, $uid, $uname, $action, 'failed', "Denied: $reason", $jobId ?: null);
    http_response_code(403);
    echo json_encode(['error' => 'Access denied', 'reason' => $reason]);
    exit;
}

// ============================================================
// Refresh token if expired
// ============================================================
if ($lock['token_expires_at'] && strtotime($lock['token_expires_at']) < time() + 60) {
    if ($lock['refresh_token']) {
        $newToken = nukiRefreshToken($lock['refresh_token']);
        if ($newToken && isset($newToken['access_token'])) {
            $newExpiresAt = date('Y-m-d H:i:s', time() + (int)($newToken['expires_in'] ?? 3600));
            q("UPDATE smart_locks SET auth_token=?, refresh_token=?, token_expires_at=? WHERE lock_id=?",
              [$newToken['access_token'], $newToken['refresh_token'] ?? $lock['refresh_token'], $newExpiresAt, $lockId]);
            $lock['auth_token'] = $newToken['access_token'];
        }
    }
}

// ============================================================
// Execute action
// ============================================================
$result = null;
try {
    if ($action === 'unlock') {
        $result = nukiUnlock($lock['auth_token'], $lock['device_id']);
    } elseif ($action === 'lock') {
        $result = nukiLock($lock['auth_token'], $lock['device_id']);
    } elseif ($action === 'status') {
        $result = nukiGetSmartlock($lock['auth_token'], $lock['device_id']);
    }
} catch (Exception $e) {
    $result = ['error' => $e->getMessage()];
}

$success = $result && !isset($result['error']);

// Update lock state if status or after action
if ($success && $action === 'status') {
    $battery = isset($result['state']['batteryCharge']) ? (int)$result['state']['batteryCharge'] : null;
    $stateName = $result['state']['stateName'] ?? null;
    q("UPDATE smart_locks SET battery_level=?, last_state=?, last_checked_at=NOW() WHERE lock_id=?",
      [$battery, $stateName, $lockId]);
}

// Log event
logLockEvent(
    $lockId,
    (int)$lock['customer_id_fk'],
    $logType,
    $uid,
    $uname,
    $action,
    $success ? 'success' : 'failed',
    $success ? "$reason" : ($result['error'] ?? 'unknown'),
    $jobId ?: null
);

// Notify via Telegram on real unlock
if ($success && $action === 'unlock' && function_exists('telegramNotify')) {
    telegramNotify("🔓 $uname ($utype) hat " . ($lock['device_name'] ?: 'Lock') . " geöffnet" . ($jobId ? " (Job #$jobId)" : ''));
}

echo json_encode([
    'success' => $success,
    'action' => $action,
    'lock_id' => $lockId,
    'reason' => $reason,
    'result' => $result,
]);
