<?php
/**
 * Auto-Cron: triggers periodic tasks on page load
 * Called as background ping from footer (1x1 pixel)
 * Tasks run at most once per interval — lock file prevents overlap
 */
header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store');
// 1x1 transparent GIF
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

// Run tasks after response is sent
require_once __DIR__ . '/../includes/config.php';

$lockDir = sys_get_temp_dir() . '/fleckfrei_cron';
if (!is_dir($lockDir)) @mkdir($lockDir, 0755, true);

function shouldRun(string $task, int $intervalMinutes): bool {
    global $lockDir;
    $lockFile = "$lockDir/$task.lock";
    if (file_exists($lockFile)) {
        $lastRun = (int)file_get_contents($lockFile);
        if (time() - $lastRun < $intervalMinutes * 60) return false;
    }
    file_put_contents($lockFile, time());
    return true;
}

// === TASK 1: iCal Sync (every 15 min) ===
if (shouldRun('ical_sync', 15)) {
    $feeds = [];
    try {
        $feeds = all("SELECT * FROM ical_feeds WHERE active=1");
    } catch (Exception $e) {}

    if (!empty($feeds)) {
        $url = 'https://app.' . SITE_DOMAIN . '/api/ical-import.php?key=' . API_KEY . '&all=1';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);

        // Log result
        $logFile = "$lockDir/ical_sync.log";
        file_put_contents($logFile, date('Y-m-d H:i:s') . " $result\n", FILE_APPEND);
        // Keep log under 50KB
        if (file_exists($logFile) && filesize($logFile) > 51200) {
            $lines = array_slice(file($logFile), -50);
            file_put_contents($logFile, implode('', $lines));
        }
    }
}

// === TASK 2: Email Inbox Sync (every 10 min) ===
if (shouldRun('email_sync', 10)) {
    try {
        global $dbLocal;
        $accounts = $dbLocal->query("SELECT * FROM email_accounts WHERE active=1")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($accounts)) {
            $url = 'https://app.' . SITE_DOMAIN . '/admin/email-inbox.php';
            // Trigger sync via internal POST
            $ch = curl_init('https://app.' . SITE_DOMAIN . '/api/index.php?action=email/sync');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>20, CURLOPT_POST=>1,
                CURLOPT_HTTPHEADER=>['X-API-Key: '.API_KEY, 'Content-Type: application/json']]);
            $result = curl_exec($ch); curl_close($ch);
            // Notify if new emails
            if ($result) {
                $data = json_decode($result, true);
                $count = $data['data']['synced'] ?? 0;
                if ($count > 0) {
                    $tgMsg = "📩 <b>{$count} neue Email(s)</b>\n\n";
                    $tgMsg .= "<a href=\"https://app." . SITE_DOMAIN . "/admin/email-inbox.php\">Email Inbox öffnen</a>";
                    $ch = curl_init('https://api.telegram.org/bot' . (defined('TELEGRAM_BOT') ? TELEGRAM_BOT : '***REDACTED***') . '/sendMessage');
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_POST=>1,
                        CURLOPT_POSTFIELDS=>http_build_query(['chat_id'=>'6904792507', 'text'=>$tgMsg, 'parse_mode'=>'HTML'])]);
                    curl_exec($ch); curl_close($ch);
                }
            }
        }
    } catch (Exception $e) {}
}

// === TASK 3: Smoobu Sync (every 30 min, if configured) ===
if (defined('FEATURE_SMOOBU') && FEATURE_SMOOBU && shouldRun('smoobu_sync', 30)) {
    $url = 'https://app.' . SITE_DOMAIN . '/api/index.php?action=smoobu/sync';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . API_KEY],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
