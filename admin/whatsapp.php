<?php
/**
 * Admin — WhatsApp Inbox
 * Shows incoming WhatsApp messages (channel='whatsapp') from /api/whatsapp-inbound.php
 * Grouped by sender, newest first.
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'WhatsApp Inbox'; $page = 'whatsapp';

// Mark all visible messages as read — quick "Alle als gelesen markieren" button
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/whatsapp.php'); exit; }
    $act = $_POST['action'] ?? '';
    if ($act === 'mark_read') {
        q("UPDATE messages SET is_read=1, read_at=NOW() WHERE channel='whatsapp' AND recipient_type='admin' AND (is_read IS NULL OR is_read=0)");
        header('Location: /admin/whatsapp.php?marked=1'); exit;
    }
    if ($act === 'mark_one_read') {
        $msgId = (int)($_POST['msg_id'] ?? 0);
        q("UPDATE messages SET is_read=1, read_at=NOW() WHERE msg_id=?", [$msgId]);
        header('Location: /admin/whatsapp.php'); exit;
    }
}

$filter = $_GET['filter'] ?? 'all'; // all | unread | matched | unknown
$whereExtra = '';
if ($filter === 'unread')  $whereExtra = "AND (m.is_read IS NULL OR m.is_read=0)";
if ($filter === 'matched') $whereExtra = "AND m.sender_type='customer'";
if ($filter === 'unknown') $whereExtra = "AND m.sender_type='system'";

$messages = all("
    SELECT m.*, c.name AS customer_name, c.customer_type, c.phone AS customer_phone
    FROM messages m
    LEFT JOIN customer c ON m.sender_id = c.customer_id AND m.sender_type='customer'
    WHERE m.channel='whatsapp' AND m.recipient_type='admin'
      $whereExtra
    ORDER BY m.created_at DESC
    LIMIT 200
");

$stats = one("
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN is_read IS NULL OR is_read=0 THEN 1 ELSE 0 END) AS unread,
      SUM(CASE WHEN sender_type='customer' THEN 1 ELSE 0 END) AS matched,
      SUM(CASE WHEN sender_type='system' THEN 1 ELSE 0 END) AS unknown,
      SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) AS today
    FROM messages WHERE channel='whatsapp' AND recipient_type='admin'
");

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['marked'])): ?>
<div class="mb-4 p-3 rounded-xl bg-green-50 border border-green-200 text-sm text-green-800">
  ✓ Alle Nachrichten als gelesen markiert.
</div>
<?php endif; ?>

<div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
  <div>
    <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
      <span class="text-3xl">💬</span> WhatsApp Inbox
    </h1>
    <p class="text-sm text-gray-500 mt-1">Eingehende WhatsApp-Nachrichten via n8n-Webhook. Phone-Matching automatisch.</p>
  </div>
  <?php if (($stats['unread'] ?? 0) > 0): ?>
  <form method="POST">
    <input type="hidden" name="_csrf" value="<?= csrfToken() ?>"/>
    <input type="hidden" name="action" value="mark_read"/>
    <button class="px-4 py-2 bg-brand hover:bg-brand-dark text-white rounded-xl text-sm font-semibold">Alle als gelesen markieren</button>
  </form>
  <?php endif; ?>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
  <a href="?filter=all" class="bg-white rounded-xl border p-4 hover:border-brand transition <?= $filter === 'all' ? 'border-brand ring-2 ring-brand/20' : '' ?>">
    <div class="text-2xl font-extrabold text-gray-900"><?= (int)($stats['total'] ?? 0) ?></div>
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mt-1">Gesamt</div>
  </a>
  <a href="?filter=unread" class="bg-white rounded-xl border p-4 hover:border-red-500 transition <?= $filter === 'unread' ? 'border-red-500 ring-2 ring-red-500/20' : '' ?>">
    <div class="text-2xl font-extrabold <?= ($stats['unread'] ?? 0) > 0 ? 'text-red-600' : 'text-gray-900' ?>"><?= (int)($stats['unread'] ?? 0) ?></div>
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mt-1">Ungelesen</div>
  </a>
  <a href="?filter=matched" class="bg-white rounded-xl border p-4 hover:border-green-500 transition <?= $filter === 'matched' ? 'border-green-500 ring-2 ring-green-500/20' : '' ?>">
    <div class="text-2xl font-extrabold text-green-600"><?= (int)($stats['matched'] ?? 0) ?></div>
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mt-1">Zugeordnet</div>
  </a>
  <a href="?filter=unknown" class="bg-white rounded-xl border p-4 hover:border-amber-500 transition <?= $filter === 'unknown' ? 'border-amber-500 ring-2 ring-amber-500/20' : '' ?>">
    <div class="text-2xl font-extrabold text-amber-600"><?= (int)($stats['unknown'] ?? 0) ?></div>
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mt-1">Unbekannt</div>
  </a>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-extrabold text-brand"><?= (int)($stats['today'] ?? 0) ?></div>
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mt-1">Heute</div>
  </div>
</div>

<!-- Messages list -->
<div class="bg-white rounded-xl border overflow-hidden">
  <?php if (empty($messages)): ?>
  <div class="p-12 text-center">
    <div class="text-5xl mb-3">📭</div>
    <h3 class="font-bold text-gray-900">Keine Nachrichten</h3>
    <p class="text-sm text-gray-500 mt-1">Noch keine WhatsApp-Nachrichten empfangen.</p>
    <p class="text-[11px] text-gray-400 mt-2">n8n Workflow muss POST an <code class="bg-gray-100 px-1 rounded">api/whatsapp-inbound.php</code> senden.</p>
  </div>
  <?php else: ?>
  <div class="divide-y">
    <?php foreach ($messages as $m):
      $isUnread = empty($m['is_read']);
      $isMatched = $m['sender_type'] === 'customer';
    ?>
    <div class="p-4 <?= $isUnread ? 'bg-amber-50/50' : '' ?> hover:bg-gray-50 transition">
      <div class="flex items-start gap-3">
        <!-- Avatar -->
        <div class="w-10 h-10 rounded-full flex-shrink-0 flex items-center justify-center font-bold text-white <?= $isMatched ? 'bg-green-500' : 'bg-amber-500' ?>">
          <?= strtoupper(substr($m['sender_name'] ?? '?', 0, 1)) ?>
        </div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap mb-1">
            <?php if ($isMatched): ?>
              <a href="/admin/view-customer.php?id=<?= (int)$m['sender_id'] ?>" class="font-bold text-green-700 hover:underline"><?= e($m['customer_name'] ?: $m['sender_name']) ?></a>
              <?php if ($m['customer_type']): ?><span class="text-[10px] px-1.5 py-0.5 rounded bg-green-100 text-green-700 font-semibold"><?= e($m['customer_type']) ?></span><?php endif; ?>
            <?php else: ?>
              <span class="font-bold text-amber-700"><?= e($m['sender_name'] ?: 'Unbekannt') ?></span>
              <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 font-semibold">NICHT ZUGEORDNET</span>
            <?php endif; ?>
            <span class="text-xs text-gray-400"><?= e(date('d.m. H:i', strtotime($m['created_at']))) ?></span>
            <?php if ($isUnread): ?><span class="w-2 h-2 bg-red-500 rounded-full"></span><?php endif; ?>
          </div>

          <div class="text-sm text-gray-800 whitespace-pre-wrap break-words"><?= e($m['message']) ?></div>

          <?php if (!empty($m['attachment'])): ?>
          <a href="<?= e($m['attachment']) ?>" target="_blank" class="inline-flex items-center gap-1 mt-2 text-xs text-blue-600 hover:underline">
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
            Anhang öffnen
          </a>
          <?php endif; ?>

          <div class="flex items-center gap-3 mt-2">
            <?php if ($isUnread): ?>
            <form method="POST" class="inline">
              <input type="hidden" name="_csrf" value="<?= csrfToken() ?>"/>
              <input type="hidden" name="action" value="mark_one_read"/>
              <input type="hidden" name="msg_id" value="<?= (int)$m['msg_id'] ?>"/>
              <button class="text-[11px] text-gray-500 hover:text-brand">Als gelesen markieren</button>
            </form>
            <?php endif; ?>
            <?php if ($m['customer_phone']): ?>
            <a href="https://wa.me/<?= e(preg_replace('/\D+/', '', $m['customer_phone'])) ?>" target="_blank" rel="noopener" class="text-[11px] text-green-600 hover:underline flex items-center gap-1">
              <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606"/></svg>
              Antworten
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Integration hint -->
<div class="mt-6 p-4 rounded-xl bg-blue-50 border border-blue-200 text-xs text-blue-900">
  <strong class="text-blue-950">🔗 n8n Integration:</strong>
  Trigger einen HTTP-Request-Node in n8n mit<br/>
  <code class="bg-white/80 px-2 py-1 rounded mt-1 inline-block text-[10px]">POST https://app.fleckfrei.de/api/whatsapp-inbound.php?key=flk_api_2026_...</code><br/>
  Body JSON: <code class="bg-white/80 px-2 py-0.5 rounded text-[10px]">{"from":"+49...", "name":"...", "text":"...", "media_url":"...", "wa_message_id":"wamid..."}</code>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
