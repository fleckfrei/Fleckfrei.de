<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/openbanking.php';
requireAdmin();

// Enable Banking redirects here after bank authorization
$sessionId = $_GET['session_id'] ?? '';

if ($sessionId) {
    $ob = new OpenBanking();
    $session = $ob->getSession($sessionId);

    if ($session && !empty($session['accounts'])) {
        // Store the first account ID
        $accountId = $session['accounts'][0];
        // Save to a local file (can't update config.php constants at runtime)
        file_put_contents(__DIR__ . '/../includes/openbanking_account.txt', $accountId);
        audit('bank_connected', 'system', 0, "N26 Account linked: $accountId");
        telegramNotify("🏦 <b>Bank verbunden!</b>\n\nN26 Account: $accountId\nAuto-Import ist jetzt aktiv.");
        header("Location: /admin/bank-import.php?connected=1&account=$accountId");
        exit;
    }
}

header("Location: /admin/bank-import.php?error=connection_failed");
