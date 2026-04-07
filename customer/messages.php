<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
if (!customerCan('messages')) { header('Location: /customer/'); exit; }
$title = 'Nachrichten'; $page = 'messages';
$user = me();
$cid = $user['id'];

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'send') {
    q("INSERT INTO messages (sender_type,sender_id,sender_name,recipient_type,recipient_id,message,job_id,channel) VALUES ('customer',?,?,'admin',0,?,?,?)",
      [$cid, $user['name'], $_POST['message'], $_POST['job_id']??null, 'portal']);

    // n8n webhook for AI processing
    $webhook = 'https://n8n.la-renting.com/webhook/fleckfrei-v2-message';
    @file_get_contents($webhook, false, stream_context_create([
        'http' => ['method'=>'POST', 'header'=>"Content-Type: application/json\r\n", 'timeout'=>3,
            'content'=>json_encode([
                'event' => 'new_message', 'from' => 'customer', 'from_name' => $user['name'],
                'from_id' => $cid, 'to_type' => 'admin', 'message' => $_POST['message'],
                'job_id' => $_POST['job_id'] ?? null,
            ])]
    ]));

    header("Location: /customer/messages.php?sent=1"); exit;
}

// Get messages for this customer
$messages = all("SELECT * FROM messages WHERE (sender_type='customer' AND sender_id=?) OR (recipient_type='customer' AND recipient_id=?) ORDER BY created_at DESC LIMIT 100",
    [$cid, $cid]);

// Mark incoming as read
q("UPDATE messages SET read_at=NOW() WHERE recipient_type='customer' AND recipient_id=? AND read_at IS NULL", [$cid]);

// Get recent jobs for reference
$recentJobs = all("SELECT j_id, j_date, s.title as stitle FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id WHERE j.customer_id_fk=? AND j.status=1 ORDER BY j.j_date DESC LIMIT 10", [$cid]);

include __DIR__ . '/../includes/layout.php';
?>

<?php if(!empty($_GET['sent'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Nachricht gesendet.</div><?php endif; ?>

<!-- Compose -->
<div class="bg-white rounded-xl border p-5 mb-6">
  <h3 class="font-semibold mb-3">Neue Nachricht an <?= SITE ?></h3>
  <form method="POST" class="space-y-3">
    <input type="hidden" name="action" value="send"/>
    <div>
      <label class="block text-sm font-medium text-gray-600 mb-1">Betreff / Job (optional)</label>
      <select name="job_id" class="w-full px-3 py-2.5 border rounded-xl">
        <option value="">Allgemein</option>
        <?php foreach ($recentJobs as $j): ?>
        <option value="<?= $j['j_id'] ?>">Job #<?= $j['j_id'] ?> — <?= date('d.m', strtotime($j['j_date'])) ?> <?= e($j['stitle']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <textarea name="message" required rows="3" placeholder="Ihre Nachricht..." class="w-full px-3 py-2.5 border rounded-xl"></textarea>
    </div>
    <button type="submit" class="px-6 py-2.5 bg-brand text-white rounded-xl font-medium">Senden</button>
  </form>
</div>

<!-- Messages -->
<div class="bg-white rounded-xl border">
  <div class="p-5 border-b"><h3 class="font-semibold">Nachrichten-Verlauf</h3></div>
  <div class="divide-y">
    <?php foreach ($messages as $m):
      $isMine = $m['sender_type'] === 'customer' && (int)$m['sender_id'] === $cid;
    ?>
    <div class="px-5 py-4 <?= $isMine ? 'bg-brand/5' : '' ?>">
      <div class="flex items-start justify-between gap-4">
        <div class="flex-1">
          <div class="flex items-center gap-2 mb-1">
            <span class="text-sm font-medium <?= $isMine ? 'text-brand' : 'text-gray-800' ?>"><?= $isMine ? 'Sie' : ($m['sender_name'] ?: SITE) ?></span>
            <?php if ($m['job_id']): ?><span class="text-xs text-gray-400">Job #<?= $m['job_id'] ?></span><?php endif; ?>
          </div>
          <p class="text-sm text-gray-700"><?= nl2br(e($m['message'])) ?></p>
          <?php if ($m['translated_message']): ?>
          <p class="text-xs text-brand mt-1 italic"><?= nl2br(e($m['translated_message'])) ?></p>
          <?php endif; ?>
        </div>
        <div class="text-xs text-gray-400 flex-shrink-0"><?= date('d.m. H:i', strtotime($m['created_at'])) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($messages)): ?>
    <div class="px-5 py-12 text-center text-gray-400">Noch keine Nachrichten.</div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
