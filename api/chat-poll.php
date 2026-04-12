<?php
/**
 * Chat Polling API — fast lightweight JSON endpoint for chat refresh.
 * GET /api/chat-poll.php?since=N
 * Returns only messages with msg_id > N.
 *
 * Used by customer/messages.php frontend to poll every 5s without reloading full page.
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$utype = $_SESSION['utype'] ?? '';
$uid = (int) ($_SESSION['uid'] ?? 0);
if (!$uid || !in_array($utype, ['customer','employee','admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$since = (int) ($_GET['since'] ?? 0);

try {
    if ($utype === 'customer') {
        $rows = allLocal(
            "SELECT msg_id, sender_type, sender_id, sender_name, message, translated_message, attachment, job_id, created_at, read_at
             FROM messages
             WHERE msg_id > ?
               AND ((sender_type='customer' AND sender_id=?) OR (recipient_type='customer' AND recipient_id=?))
             ORDER BY created_at ASC LIMIT 100",
            [$since, $uid, $uid]
        );
        // Mark unread as read
        qLocal("UPDATE messages SET read_at=NOW() WHERE recipient_type='customer' AND recipient_id=? AND read_at IS NULL", [$uid]);
    } elseif ($utype === 'employee') {
        $rows = allLocal(
            "SELECT msg_id, sender_type, sender_id, sender_name, message, translated_message, attachment, job_id, created_at, read_at
             FROM messages
             WHERE msg_id > ?
               AND ((sender_type='employee' AND sender_id=?) OR (recipient_type='employee' AND recipient_id=?))
             ORDER BY created_at ASC LIMIT 100",
            [$since, $uid, $uid]
        );
        qLocal("UPDATE messages SET read_at=NOW() WHERE recipient_type='employee' AND recipient_id=? AND read_at IS NULL", [$uid]);
    } else {
        // admin sees all
        $rows = allLocal(
            "SELECT msg_id, sender_type, sender_id, sender_name, recipient_type, recipient_id, message, translated_message, attachment, job_id, created_at, read_at
             FROM messages WHERE msg_id > ? ORDER BY created_at ASC LIMIT 100",
            [$since]
        );
    }
} catch (Exception $e) {
    $rows = [];
}

// Normalize output
$out = [];
foreach ($rows as $m) {
    $isMine = ($m['sender_type'] === $utype && (int)$m['sender_id'] === $uid);
    $att = $m['attachment'] ?? null;
    $attType = null;
    if ($att) {
        if (preg_match('/\.(jpe?g|png|gif|webp)$/i', $att)) $attType = 'image';
        elseif (preg_match('/\.(mp4|mov|webm)$/i', $att)) $attType = 'video';
        elseif (preg_match('/\.(mp3|m4a|ogg|oga)$/i', $att)) $attType = 'audio';
        else $attType = 'file';
    }
    $out[] = [
        'id' => (int) $m['msg_id'],
        'mine' => $isMine,
        'sender_name' => $m['sender_name'] ?? '',
        'message' => $m['message'] ?? '',
        'translated' => $m['translated_message'] ?? null,
        'attachment' => $att,
        'att_type' => $attType,
        'created_at' => $m['created_at'],
        'time' => date('H:i', strtotime($m['created_at'])),
        'date' => date('Y-m-d', strtotime($m['created_at'])),
        'read' => !empty($m['read_at']),
    ];
}

echo json_encode([
    'success' => true,
    'messages' => $out,
    'last_id' => !empty($out) ? end($out)['id'] : $since,
    'count' => count($out),
]);
