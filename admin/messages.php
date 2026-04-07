<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Nachrichten'; $page = 'messages';

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'send') {
        q("INSERT INTO messages (sender_type,sender_id,sender_name,recipient_type,recipient_id,message,job_id,channel) VALUES ('admin',?,?,?,?,?,?,?)",
          [me()['id'], me()['name'], $_POST['recipient_type'], $_POST['recipient_id'], $_POST['message'], $_POST['job_id']??null, 'portal']);
        audit('send', 'message', 0, 'To: '.$_POST['recipient_type'].'#'.$_POST['recipient_id']);

        // Trigger n8n webhook for AI translation if sending to customer/employee
        $recipientType = $_POST['recipient_type'];
        if ($recipientType === 'customer' || $recipientType === 'employee') {
            $table = $recipientType === 'customer' ? 'customer' : 'employee';
            $idCol = $recipientType === 'customer' ? 'customer_id' : 'emp_id';
            $recipient = one("SELECT name, email, phone FROM $table WHERE $idCol=?", [$_POST['recipient_id']]);
            $webhook = 'https://n8n.la-renting.com/webhook/fleckfrei-v2-message';
            @file_get_contents($webhook, false, stream_context_create([
                'http' => ['method'=>'POST', 'header'=>"Content-Type: application/json\r\n", 'timeout'=>3,
                    'content'=>json_encode([
                        'event' => 'new_message',
                        'from' => 'admin',
                        'from_name' => me()['name'],
                        'to_type' => $recipientType,
                        'to_name' => $recipient['name'] ?? '',
                        'to_email' => $recipient['email'] ?? '',
                        'to_phone' => $recipient['phone'] ?? '',
                        'message' => $_POST['message'],
                        'job_id' => $_POST['job_id'] ?? null,
                    ])]
            ]));
        }

        header("Location: /admin/messages.php?sent=1"); exit;
    }
}

// Filter
$filterType = $_GET['type'] ?? '';
$filterId = $_GET['id'] ?? '';

$sql = "SELECT m.*,
    CASE WHEN m.sender_type='customer' THEN (SELECT name FROM customer WHERE customer_id=m.sender_id)
         WHEN m.sender_type='employee' THEN (SELECT name FROM employee WHERE emp_id=m.sender_id)
         ELSE m.sender_name END as resolved_sender,
    CASE WHEN m.recipient_type='customer' THEN (SELECT name FROM customer WHERE customer_id=m.recipient_id)
         WHEN m.recipient_type='employee' THEN (SELECT name FROM employee WHERE emp_id=m.recipient_id)
         ELSE 'Admin' END as resolved_recipient
    FROM messages m";
$p = [];
if ($filterType && $filterId) {
    $sql .= " WHERE (m.sender_type=? AND m.sender_id=?) OR (m.recipient_type=? AND m.recipient_id=?)";
    $p = [$filterType, $filterId, $filterType, $filterId];
}
$sql .= " ORDER BY m.created_at DESC LIMIT 200";
$messages = all($sql, $p);

// Unread count
$unreadCount = val("SELECT COUNT(*) FROM messages WHERE recipient_type='admin' AND read_at IS NULL");

// Mark messages as read
if ($unreadCount > 0) {
    q("UPDATE messages SET read_at=NOW() WHERE recipient_type='admin' AND read_at IS NULL");
}

$customers = all("SELECT customer_id, name FROM customer WHERE status=1 ORDER BY name");
$employees = all("SELECT emp_id, name, surname FROM employee WHERE status=1 ORDER BY name");

include __DIR__ . '/../includes/layout.php';
?>

<?php if(!empty($_GET['sent'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Nachricht gesendet.</div><?php endif; ?>

<div x-data="{ composeOpen:false, recipientType:'customer', recipientId:'' }">
  <!-- Stats -->
  <div class="grid grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4"><div class="text-2xl font-bold text-brand"><?= count($messages) ?></div><div class="text-sm text-gray-500">Nachrichten gesamt</div></div>
    <div class="bg-white rounded-xl border p-4"><div class="text-2xl font-bold text-orange-600"><?= $unreadCount ?></div><div class="text-sm text-gray-500">Ungelesen (gerade gelesen)</div></div>
    <div class="bg-white rounded-xl border p-4"><div class="text-2xl font-bold"><?= val("SELECT COUNT(DISTINCT CONCAT(sender_type,'-',sender_id)) FROM messages") ?></div><div class="text-sm text-gray-500">Aktive Kontakte</div></div>
  </div>

  <!-- Message List -->
  <div class="bg-white rounded-xl border">
    <div class="p-5 border-b flex items-center justify-between">
      <h3 class="font-semibold">Alle Nachrichten</h3>
      <div class="flex gap-3">
        <input type="text" placeholder="Suchen..." class="px-3 py-2 border rounded-lg text-sm w-64" oninput="filterRows(this.value)"/>
        <button @click="composeOpen=true" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-medium">Neue Nachricht</button>
      </div>
    </div>
    <div class="divide-y" id="msg-list">
      <?php foreach ($messages as $m):
        $isIncoming = $m['recipient_type'] === 'admin';
        $senderLabel = $m['sender_type'] === 'admin' ? 'Du' : e($m['resolved_sender'] ?: $m['sender_type']);
        $recipLabel = $m['recipient_type'] === 'admin' ? 'Du' : e($m['resolved_recipient'] ?: $m['recipient_type']);
        $typeColor = match($m['sender_type']) {
            'customer' => 'blue', 'employee' => 'purple', 'ai' => 'amber', 'system' => 'gray', default => 'brand'
        };
      ?>
      <div class="px-5 py-4 hover:bg-gray-50 <?= $isIncoming && !$m['read_at'] ? 'bg-blue-50/30' : '' ?>">
        <div class="flex items-start justify-between gap-4">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <span class="px-2 py-0.5 text-[10px] font-medium rounded-full bg-<?= $typeColor ?>-100 text-<?= $typeColor ?>-700"><?= ucfirst($m['sender_type']) ?></span>
              <span class="text-sm font-medium"><?= $senderLabel ?></span>
              <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
              <span class="text-sm text-gray-500"><?= $recipLabel ?></span>
              <?php if ($m['job_id']): ?><span class="text-xs text-gray-400">Job #<?= $m['job_id'] ?></span><?php endif; ?>
            </div>
            <p class="text-sm text-gray-700 line-clamp-2"><?= e($m['message']) ?></p>
            <?php if ($m['translated_message']): ?>
            <p class="text-xs text-brand mt-1 italic">KI: <?= e($m['translated_message']) ?></p>
            <?php endif; ?>
          </div>
          <div class="text-right flex-shrink-0">
            <div class="text-xs text-gray-400"><?= date('d.m. H:i', strtotime($m['created_at'])) ?></div>
            <div class="text-[10px] text-gray-300 mt-0.5"><?= e($m['channel']) ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($messages)): ?>
      <div class="px-5 py-12 text-center text-gray-400">Keine Nachrichten.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Compose Modal -->
  <template x-if="composeOpen">
    <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center"><div class="bg-white rounded-2xl p-6 w-full max-w-lg shadow-2xl m-4">
      <h3 class="text-lg font-semibold mb-4">Neue Nachricht</h3>
      <form method="POST" class="space-y-4">
        <input type="hidden" name="action" value="send"/>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">An (Typ)</label>
            <select name="recipient_type" x-model="recipientType" class="w-full px-3 py-2.5 border rounded-xl">
              <option value="customer">Kunde</option>
              <option value="employee">Partner</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Empfänger</label>
            <select name="recipient_id" x-model="recipientId" required class="w-full px-3 py-2.5 border rounded-xl">
              <template x-if="recipientType==='customer'">
                <template x-for="c in <?= htmlspecialchars(json_encode(array_map(fn($c)=>['id'=>$c['customer_id'],'name'=>$c['name']], $customers))) ?>">
                  <option :value="c.id" x-text="c.name"></option>
                </template>
              </template>
              <template x-if="recipientType==='employee'">
                <template x-for="e in <?= htmlspecialchars(json_encode(array_map(fn($e)=>['id'=>$e['emp_id'],'name'=>$e['name'].' '.($e['surname']??'')], $employees))) ?>">
                  <option :value="e.id" x-text="e.name"></option>
                </template>
              </template>
            </select>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-600 mb-1">Job (optional)</label>
          <input type="number" name="job_id" placeholder="Job-ID" class="w-full px-3 py-2.5 border rounded-xl"/>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-600 mb-1">Nachricht</label>
          <textarea name="message" required rows="4" placeholder="Nachricht eingeben..." class="w-full px-3 py-2.5 border rounded-xl"></textarea>
          <p class="text-xs text-gray-400 mt-1">Die KI übersetzt und formatiert die Nachricht automatisch für den Empfänger.</p>
        </div>
        <div class="flex gap-3">
          <button type="button" @click="composeOpen=false" class="flex-1 px-4 py-2.5 border rounded-xl">Abbrechen</button>
          <button type="submit" class="flex-1 px-4 py-2.5 bg-brand text-white rounded-xl font-medium">Senden</button>
        </div>
      </form>
    </div></div>
  </template>
</div>

<?php
$script = <<<JS
function filterRows(q){q=q.toLowerCase();document.querySelectorAll('#msg-list > div').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'})}
JS;
include __DIR__ . '/../includes/footer.php'; ?>
