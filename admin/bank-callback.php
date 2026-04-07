<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/openbanking.php';
requireAdmin();

$code = $_GET['code'] ?? '';
if ($code) {
    $ob = new OpenBanking();
    $session = $ob->createSession($code);

    if ($session && !empty($session['accounts'])) {
        // Extract UIDs from account objects
        $uids = array_map(fn($a) => is_array($a) ? ($a['uid'] ?? '') : (string)$a, $session['accounts']);
        $uids = array_filter($uids);
        file_put_contents(__DIR__ . '/../includes/openbanking_account.txt', implode("\n", $uids));
        file_put_contents(__DIR__ . '/../includes/openbanking_session.json', json_encode($session, JSON_PRETTY_PRINT));
        audit('bank_connected', 'system', 0, count($uids) . ' N26 accounts linked');
        telegramNotify("🏦 <b>N26 verbunden!</b>\n\n" . count($uids) . " Konten verlinkt");
        header("Location: /admin/bank-import.php?connected=" . count($uids));
        exit;
    }
    header("Location: /admin/bank-import.php?error=" . urlencode($session['message'] ?? 'no accounts'));
    exit;
}
header("Location: /admin/bank-import.php?error=no_code");
