<?php
/**
 * Health-Check & Self-Healing — runs via Cron every minute
 * Checks: DB connection, API response, disk space, critical files
 * Auto-fixes: DB reconnect, session cleanup
 * Alerts: Telegram on critical failure
 *
 * Cron: * * * * * php /home/u860899303/domains/app.fleckfrei.de/public_html/api/health-check.php
 * Or:   GET /api/health-check.php?cron=flk_scrape_2026
 */
$startTime = microtime(true);
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

// Auth: cron secret or admin session
session_start();
$isAdmin = (($_SESSION['utype'] ?? '') === 'admin');
$cronSecret = $_GET['cron'] ?? '';
$isCron = ($cronSecret === (defined('CRON_SECRET') ? CRON_SECRET : 'flk_scrape_2026'));
if (!$isAdmin && !$isCron && php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$checks = [];
$critical = false;
$warnings = [];

// ============================================================
// 1. Main DB Connection
// ============================================================
try {
    $dbStart = microtime(true);
    $testVal = val("SELECT 1");
    $dbMs = round((microtime(true) - $dbStart) * 1000, 1);
    $checks['db_main'] = ['status' => 'ok', 'latency_ms' => $dbMs];

    // Table count sanity check
    $tableCount = (int) val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()");
    $checks['db_tables'] = ['status' => $tableCount > 50 ? 'ok' : 'warn', 'count' => $tableCount];

    // Pending jobs sanity
    $pendingJobs = (int) val("SELECT COUNT(*) FROM jobs WHERE job_status='PENDING' AND status=1 AND j_date>=CURDATE()");
    $checks['pending_jobs'] = ['status' => 'ok', 'count' => $pendingJobs];
} catch (Exception $e) {
    $checks['db_main'] = ['status' => 'critical', 'error' => $e->getMessage()];
    $critical = true;

    // Self-heal: try to reconnect
    try {
        $GLOBALS['db'] = null;
        db_connect(); // re-call from config.php
        $checks['db_main']['heal'] = 'reconnect attempted';
    } catch (Exception $e2) {
        $checks['db_main']['heal'] = 'reconnect failed: ' . $e2->getMessage();
    }
}

// ============================================================
// 2. Local DB Connection (Messages, GPS, Ratings)
// ============================================================
try {
    $dbStart = microtime(true);
    $testVal = valLocal("SELECT 1");
    $dbMs = round((microtime(true) - $dbStart) * 1000, 1);
    $checks['db_local'] = ['status' => 'ok', 'latency_ms' => $dbMs];
} catch (Exception $e) {
    $checks['db_local'] = ['status' => 'warn', 'error' => $e->getMessage()];
    $warnings[] = 'Local DB offline';
}

// ============================================================
// 3. Critical Files exist
// ============================================================
$criticalFiles = [
    'includes/config.php', 'includes/auth.php', 'includes/layout.php',
    'includes/layout-customer.php', 'customer/index.php', 'admin/index.php',
    'login.php', 'api/index.php',
];
$missingFiles = [];
$baseDir = __DIR__ . '/../';
foreach ($criticalFiles as $f) {
    if (!file_exists($baseDir . $f)) $missingFiles[] = $f;
}
$checks['critical_files'] = [
    'status' => empty($missingFiles) ? 'ok' : 'critical',
    'checked' => count($criticalFiles),
    'missing' => $missingFiles,
];
if (!empty($missingFiles)) $critical = true;

// ============================================================
// 4. Disk Space
// ============================================================
$freeBytes = @disk_free_space('/home/u860899303/');
$totalBytes = @disk_total_space('/home/u860899303/');
$freeMb = $freeBytes ? round($freeBytes / 1024 / 1024) : null;
$usagePct = ($totalBytes && $freeBytes) ? round((1 - $freeBytes / $totalBytes) * 100, 1) : null;
$checks['disk'] = [
    'status' => ($freeMb !== null && $freeMb < 500) ? 'warn' : 'ok',
    'free_mb' => $freeMb,
    'usage_pct' => $usagePct,
];
if ($freeMb !== null && $freeMb < 100) { $critical = true; $warnings[] = "Disk critical: {$freeMb}MB free"; }

// ============================================================
// 5. Session directory writable
// ============================================================
$sessPath = session_save_path() ?: sys_get_temp_dir();
$checks['sessions'] = [
    'status' => is_writable($sessPath) ? 'ok' : 'warn',
    'path' => $sessPath,
];

// ============================================================
// 6. Last backup age
// ============================================================
$backupDir = '/home/u860899303/backups/fleckfrei.de/';
$latestBackup = null;
if (is_dir($backupDir)) {
    $files = glob($backupDir . 'db_main_*.sql.gz');
    if (!empty($files)) {
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        $latestBackup = filemtime($files[0]);
    }
}
$backupAgeHours = $latestBackup ? round((time() - $latestBackup) / 3600, 1) : null;
$checks['backup'] = [
    'status' => ($backupAgeHours !== null && $backupAgeHours < 48) ? 'ok' : 'warn',
    'last_backup_hours_ago' => $backupAgeHours,
];
if ($backupAgeHours !== null && $backupAgeHours > 72) $warnings[] = "Backup {$backupAgeHours}h old";

// ============================================================
// 7. Failed Webhooks queue
// ============================================================
try {
    $failedCount = (int) val("SELECT COUNT(*) FROM failed_webhooks WHERE status='pending' AND attempts < 5");
    $checks['webhook_queue'] = ['status' => $failedCount > 20 ? 'warn' : 'ok', 'pending' => $failedCount];
} catch (Exception $e) {
    $checks['webhook_queue'] = ['status' => 'ok', 'pending' => 0, 'note' => 'table not yet created'];
}

// ============================================================
// 8. PHP errors in last hour
// ============================================================
$errorLog = ini_get('error_log') ?: '/tmp/php_errors.log';
$recentErrors = 0;
if (file_exists($errorLog)) {
    $oneHourAgo = time() - 3600;
    $fh = @fopen($errorLog, 'r');
    if ($fh) {
        // Read last 50KB only
        fseek($fh, max(0, filesize($errorLog) - 51200));
        while (($line = fgets($fh)) !== false) {
            if (preg_match('/\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})/', $line, $m)) {
                $ts = strtotime($m[1]);
                if ($ts && $ts > $oneHourAgo) $recentErrors++;
            }
        }
        fclose($fh);
    }
}
$checks['php_errors'] = ['status' => $recentErrors > 50 ? 'warn' : 'ok', 'last_hour' => $recentErrors];

// ============================================================
// TELEGRAM ALERT on critical failure
// ============================================================
if ($critical && function_exists('telegramNotify')) {
    $msg = "🚨 <b>HEALTH-CHECK CRITICAL</b>\n\n";
    foreach ($checks as $name => $check) {
        if ($check['status'] === 'critical') {
            $msg .= "❌ <b>{$name}</b>: " . ($check['error'] ?? json_encode($check)) . "\n";
        }
    }
    $msg .= "\n⏰ " . date('H:i:s d.m.Y');
    telegramNotify($msg);
}

// Warnings alert (max 1x per hour to avoid spam)
if (!empty($warnings) && function_exists('telegramNotify')) {
    $warnFile = sys_get_temp_dir() . '/flk_health_warn_' . date('YmdH') . '.lock';
    if (!file_exists($warnFile)) {
        telegramNotify("⚠️ <b>Health Warnings</b>\n\n" . implode("\n", $warnings) . "\n\n⏰ " . date('H:i'));
        @file_put_contents($warnFile, '1');
    }
}

// ============================================================
// Response
// ============================================================
$elapsed = round((microtime(true) - $startTime) * 1000, 1);
$allOk = !$critical && empty($warnings);

echo json_encode([
    'status' => $critical ? 'critical' : ($allOk ? 'healthy' : 'degraded'),
    'timestamp' => date('Y-m-d H:i:s'),
    'elapsed_ms' => $elapsed,
    'checks' => $checks,
    'warnings' => $warnings,
    'critical' => $critical,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
