<?php
/**
 * Cron endpoint — auto-revoke expired / completed-job access codes.
 *
 * Runs every ~15 min. For each 'active' code in lock_access_codes:
 *   a) allowed_until < NOW()      → revoke on device, mark 'expired'
 *   b) linked job is COMPLETED/CANCELLED + 30min grace passed → revoke, mark 'revoked'
 *
 * Call: GET /api/lock-codes-cleanup.php?key=<API_KEY>
 * Cron: * / 15 * * * curl -s "https://app.fleckfrei.de/api/lock-codes-cleanup.php?key=..."
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/nuki-helpers.php';

header('Content-Type: application/json; charset=utf-8');

$key = $_GET['key'] ?? '';
if (!hash_equals(API_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid key']);
    exit;
}

$stats = ['checked' => 0, 'revoked_expired' => 0, 'revoked_completed' => 0, 'errors' => 0, 'skipped' => 0];

// Fetch candidate codes — join with smart_locks + jobs
$candidates = all("
    SELECT ac.code_id, ac.lock_id_fk, ac.job_id_fk, ac.auth_id_remote, ac.allowed_until,
           sl.auth_token, sl.device_id, sl.provider, sl.customer_id_fk,
           j.job_status, j.j_date, j.j_time, j.total_hours, j.j_hours
    FROM lock_access_codes ac
    LEFT JOIN smart_locks sl ON ac.lock_id_fk = sl.lock_id
    LEFT JOIN jobs j ON ac.job_id_fk = j.j_id
    WHERE ac.status = 'active'
    LIMIT 500
");

$stats['checked'] = count($candidates);
$now = time();

foreach ($candidates as $code) {
    $shouldRevoke = false;
    $reason = '';

    // (a) Hard expiration
    if ($code['allowed_until'] && strtotime($code['allowed_until']) < $now) {
        $shouldRevoke = true;
        $reason = 'expired';
    }

    // (b) Linked job finished + 30min grace passed
    if (!$shouldRevoke && $code['job_id_fk'] && in_array($code['job_status'] ?? '', ['COMPLETED', 'CANCELLED'], true)) {
        // Compute effective job-end timestamp from j_date + j_time + hours, add 30min grace
        $jobStart = strtotime(($code['j_date'] ?? '') . ' ' . ($code['j_time'] ?? '00:00:00'));
        $hours = (float)($code['total_hours'] ?: $code['j_hours'] ?: 3);
        $graceEnd = $jobStart + ($hours * 3600) + (30 * 60);
        if ($jobStart && $now >= $graceEnd) {
            $shouldRevoke = true;
            $reason = 'job_completed';
        }
    }

    if (!$shouldRevoke) {
        $stats['skipped']++;
        continue;
    }

    // Revoke on provider (Nuki only for now)
    $apiOk = true;
    if ($code['provider'] === 'nuki' && $code['auth_id_remote'] && $code['auth_token']) {
        $resp = nukiRevokeAuth($code['auth_token'], $code['device_id'], $code['auth_id_remote']);
        if (isset($resp['error'])) {
            $apiOk = false;
            // 404 means the auth was already deleted on Nuki's side — still mark locally
            if (($resp['http_code'] ?? 0) === 404) {
                $apiOk = true;
            }
        }
    }

    $newStatus = $reason === 'expired' ? 'expired' : 'revoked';
    if ($apiOk) {
        q("UPDATE lock_access_codes SET status=? WHERE code_id=?", [$newStatus, $code['code_id']]);
        logLockEvent(
            (int)$code['lock_id_fk'],
            (int)$code['customer_id_fk'],
            'auto',
            null,
            'cron-cleanup',
            'code_revoked',
            'success',
            "Auto-revoke ($reason) code #{$code['code_id']}",
            $code['job_id_fk'] ? (int)$code['job_id_fk'] : null
        );
        if ($reason === 'expired') $stats['revoked_expired']++;
        else                       $stats['revoked_completed']++;
    } else {
        logLockEvent(
            (int)$code['lock_id_fk'],
            (int)$code['customer_id_fk'],
            'auto',
            null,
            'cron-cleanup',
            'failed',
            'failed',
            "Auto-revoke failed for code #{$code['code_id']}: provider returned error",
            $code['job_id_fk'] ? (int)$code['job_id_fk'] : null
        );
        $stats['errors']++;
    }
}

echo json_encode([
    'success'    => true,
    'timestamp'  => date('Y-m-d H:i:s'),
    'stats'      => $stats,
]);
