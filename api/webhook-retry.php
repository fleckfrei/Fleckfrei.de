<?php
/**
 * Webhook Retry Queue — processes failed webhook deliveries
 * Cron: every 5 min — php .../api/webhook-retry.php
 *
 * Table: failed_webhooks (auto-created on first use)
 * - Retries up to 5 times with exponential backoff (1m, 5m, 15m, 1h, 4h)
 * - Sends Telegram alert after final failure
 *
 * To queue a webhook: webhook_queue($url, $payload, $context)
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json; charset=utf-8');

// Auth
session_start();
$isAdmin = (($_SESSION['utype'] ?? '') === 'admin');
$isCron = ($_GET['cron'] ?? '') === (defined('CRON_SECRET') ? CRON_SECRET : 'flk_scrape_2026');
if (!$isAdmin && !$isCron && php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Ensure table exists
try {
    q("CREATE TABLE IF NOT EXISTS failed_webhooks (
        fw_id INT AUTO_INCREMENT PRIMARY KEY,
        url VARCHAR(500) NOT NULL,
        payload LONGTEXT,
        context VARCHAR(255),
        http_code INT DEFAULT 0,
        error_msg TEXT,
        attempts TINYINT DEFAULT 0,
        max_attempts TINYINT DEFAULT 5,
        status ENUM('pending','success','failed','abandoned') DEFAULT 'pending',
        next_retry_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status, next_retry_at),
        INDEX idx_context (context)
    )");
} catch (Exception $e) { /* already exists */ }

// Backoff schedule: attempt => seconds delay
$backoff = [1 => 60, 2 => 300, 3 => 900, 4 => 3600, 5 => 14400];

// Fetch pending retries
$pending = all("SELECT * FROM failed_webhooks WHERE status='pending' AND (next_retry_at IS NULL OR next_retry_at <= NOW()) AND attempts < max_attempts ORDER BY created_at ASC LIMIT 20");

$processed = 0;
$succeeded = 0;
$failed = 0;

foreach ($pending as $wh) {
    $attempt = (int)$wh['attempts'] + 1;

    $ch = curl_init($wh['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $wh['payload'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        // Success
        q("UPDATE failed_webhooks SET status='success', http_code=?, attempts=?, updated_at=NOW() WHERE fw_id=?",
          [$code, $attempt, $wh['fw_id']]);
        $succeeded++;
    } else {
        // Failed — schedule next retry or abandon
        $nextDelay = $backoff[$attempt + 1] ?? null;
        if ($attempt >= (int)$wh['max_attempts'] || !$nextDelay) {
            q("UPDATE failed_webhooks SET status='abandoned', http_code=?, error_msg=?, attempts=?, updated_at=NOW() WHERE fw_id=?",
              [$code, $err ?: "HTTP $code", $attempt, $wh['fw_id']]);
            // Telegram alert for abandoned webhooks
            if (function_exists('telegramNotify')) {
                telegramNotify("❌ <b>Webhook abandoned</b>\n\nURL: " . e($wh['url']) . "\nContext: " . e($wh['context']) . "\nAttempts: {$attempt}\nLast error: HTTP {$code} " . e(mb_substr($err, 0, 100)));
            }
        } else {
            q("UPDATE failed_webhooks SET http_code=?, error_msg=?, attempts=?, next_retry_at=DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at=NOW() WHERE fw_id=?",
              [$code, $err ?: "HTTP $code", $attempt, $nextDelay, $wh['fw_id']]);
        }
        $failed++;
    }
    $processed++;
}

echo json_encode([
    'success' => true,
    'processed' => $processed,
    'succeeded' => $succeeded,
    'failed' => $failed,
    'remaining' => (int) val("SELECT COUNT(*) FROM failed_webhooks WHERE status='pending'"),
]);
