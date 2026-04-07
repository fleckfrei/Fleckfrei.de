<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Chat'; $page = 'messages';

// Delete message (admin only, silent)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'delete_msg') {
    if (!verifyCsrf()) { header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
    qLocal("DELETE FROM messages WHERE msg_id=?", [(int)$_POST['msg_id']]);
    header("Location: " . $_SERVER['REQUEST_URI']); exit;
}

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'send') {
    if (!verifyCsrf()) { header('Location: /admin/messages.php'); exit; }
    $recipType = $_POST['recipient_type'];
    $recipId = (int)$_POST['recipient_id'];
    $msg = trim($_POST['message'] ?? '');
    if ($msg && $recipId) {
        qLocal("INSERT INTO messages (sender_type,sender_id,sender_name,recipient_type,recipient_id,message,job_id,channel) VALUES ('admin',?,?,?,?,?,?,?)",
          [me()['id'], SITE . ' Team', $recipType, $recipId, $msg, $_POST['job_id']??null, 'portal']);

        // n8n webhook for KI processing
        $table = $recipType === 'customer' ? 'customer' : 'employee';
        $idCol = $recipType === 'customer' ? 'customer_id' : 'emp_id';
        $recipient = one("SELECT name, email, phone FROM $table WHERE $idCol=?", [$recipId]);
        @file_get_contents('https://n8n.la-renting.com/webhook/fleckfrei-v2-message', false, stream_context_create([
            'http' => ['method'=>'POST', 'header'=>"Content-Type: application/json\r\n", 'timeout'=>3,
                'content'=>json_encode(['event'=>'new_message','from'=>'admin','from_name'=>SITE.' Team','to_type'=>$recipType,'to_name'=>$recipient['name']??'','to_email'=>$recipient['email']??'','to_phone'=>$recipient['phone']??'','message'=>$msg,'job_id'=>$_POST['job_id']??null])]
        ]));
    }
    header("Location: /admin/messages.php?chat={$recipType}_{$recipId}"); exit;
}

// Build conversations list
$conversations = allLocal("SELECT
    CASE WHEN sender_type='admin' THEN CONCAT(recipient_type,'_',recipient_id) ELSE CONCAT(sender_type,'_',sender_id) END as conv_key,
    MAX(created_at) as last_msg_time,
    COUNT(*) as msg_count,
    SUM(CASE WHEN recipient_type='admin' AND read_at IS NULL THEN 1 ELSE 0 END) as unread
    FROM messages
    GROUP BY conv_key
    ORDER BY last_msg_time DESC");

// Resolve conversation names
foreach ($conversations as &$conv) {
    [$type, $id] = explode('_', $conv['conv_key'], 2);
    $conv['type'] = $type;
    $conv['id'] = (int)$id;
    if ($type === 'customer') {
        $conv['name'] = val("SELECT name FROM customer WHERE customer_id=?", [$id]) ?: 'Kunde #'.$id;
        $conv['icon'] = 'blue';
    } elseif ($type === 'employee') {
        $r = one("SELECT name, surname FROM employee WHERE emp_id=?", [$id]);
        $conv['name'] = $r ? $r['name'].' '.($r['surname']??'') : 'Partner #'.$id;
        $conv['icon'] = 'purple';
    } else {
        $conv['name'] = $type.' #'.$id;
        $conv['icon'] = 'gray';
    }
    // Last message preview
    $lastMsg = oneLocal("SELECT message, sender_type FROM messages WHERE
        (sender_type=? AND sender_id=?) OR (recipient_type=? AND recipient_id=?)
        ORDER BY created_at DESC LIMIT 1", [$type, $id, $type, $id]);
    $conv['preview'] = $lastMsg ? mb_substr($lastMsg['message'], 0, 60) . (mb_strlen($lastMsg['message']) > 60 ? '...' : '') : '';
    $conv['last_sender'] = $lastMsg ? ($lastMsg['sender_type'] === 'admin' ? 'Du: ' : '') : '';
}
unset($conv);

// Active chat
$activeChat = $_GET['chat'] ?? '';
$chatMessages = [];
$chatName = '';
$chatType = '';
$chatId = 0;
if ($activeChat && str_contains($activeChat, '_')) {
    [$chatType, $chatId] = explode('_', $activeChat, 2);
    $chatId = (int)$chatId;
    $chatMessages = allLocal("SELECT * FROM messages WHERE
        (sender_type=? AND sender_id=?) OR (recipient_type=? AND recipient_id=?)
        ORDER BY created_at ASC", [$chatType, $chatId, $chatType, $chatId]);
    // Mark as read
    qLocal("UPDATE messages SET read_at=NOW() WHERE recipient_type='admin' AND read_at IS NULL AND sender_type=? AND sender_id=?", [$chatType, $chatId]);
    // Get name
    if ($chatType === 'customer') $chatName = val("SELECT name FROM customer WHERE customer_id=?", [$chatId]) ?: 'Kunde';
    elseif ($chatType === 'employee') { $r = one("SELECT name, surname FROM employee WHERE emp_id=?", [$chatId]); $chatName = $r ? $r['name'].' '.($r['surname']??'') : 'Partner'; }
}

$customers = all("SELECT customer_id, name FROM customer WHERE status=1 ORDER BY name");
$employees = all("SELECT emp_id, name, surname FROM employee WHERE status=1 ORDER BY name");

include __DIR__ . '/../includes/layout.php';
?>

<style>
.chat-container { display: flex; height: calc(100vh - 180px); min-height: 500px; }
.chat-sidebar { width: 320px; border-right: 1px solid #e5e7eb; overflow-y: auto; flex-shrink: 0; }
.chat-main { flex: 1; display: flex; flex-direction: column; }
.chat-messages { flex: 1; overflow-y: auto; padding: 20px; background: #f0f2f5; }
.chat-input { border-top: 1px solid #e5e7eb; padding: 12px 16px; background: white; }
.bubble { max-width: 75%; padding: 8px 14px; border-radius: 12px; font-size: 14px; line-height: 1.5; position: relative; word-wrap: break-word; }
.bubble-out { background: #d9fdd3; margin-left: auto; border-bottom-right-radius: 4px; }
.bubble-in { background: white; margin-right: auto; border-bottom-left-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,0.06); }
.bubble-system { background: #fef3c7; margin: 0 auto; text-align: center; font-size: 12px; border-radius: 8px; }
.conv-item { padding: 12px 16px; cursor: pointer; transition: background 0.1s; border-bottom: 1px solid #f3f4f6; }
.conv-item:hover { background: #f9fafb; }
.conv-item.active { background: <?= BRAND_LIGHT ?>; border-left: 3px solid <?= BRAND ?>; }
@media (max-width: 768px) { .chat-sidebar { width: 100%; } .chat-main { display: none; } }
</style>

<div class="bg-white rounded-xl border overflow-hidden" x-data="{ newChat: false }">
  <div class="chat-container">
    <!-- Sidebar: Conversations -->
    <div class="chat-sidebar">
      <div class="p-3 border-b flex items-center justify-between bg-gray-50">
        <h3 class="font-semibold text-sm">Chats (<?= count($conversations) ?>)</h3>
        <button @click="newChat=!newChat" class="px-2 py-1 bg-brand text-white rounded-lg text-xs">+ Neu</button>
      </div>

      <!-- New chat selector -->
      <div x-show="newChat" x-cloak class="p-3 border-b bg-blue-50">
        <select id="newChatSelect" onchange="if(this.value)location='/admin/messages.php?chat='+this.value" class="w-full px-3 py-2 border rounded-lg text-sm">
          <option value="">Kontakt wählen...</option>
          <optgroup label="Kunden">
            <?php foreach ($customers as $c): ?><option value="customer_<?= $c['customer_id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
          </optgroup>
          <optgroup label="Partner">
            <?php foreach ($employees as $emp): ?><option value="employee_<?= $emp['emp_id'] ?>"><?= e($emp['name'].' '.($emp['surname']??'')) ?></option><?php endforeach; ?>
          </optgroup>
        </select>
      </div>

      <!-- Conversation list -->
      <?php foreach ($conversations as $conv): ?>
      <a href="?chat=<?= $conv['conv_key'] ?>" class="conv-item block <?= $activeChat === $conv['conv_key'] ? 'active' : '' ?>">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-full bg-<?= $conv['icon'] ?>-100 text-<?= $conv['icon'] ?>-700 flex items-center justify-center text-sm font-bold flex-shrink-0">
            <?= strtoupper(mb_substr($conv['name'],0,1)) ?>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between">
              <span class="text-sm font-medium truncate"><?= e($conv['name']) ?></span>
              <span class="text-[10px] text-gray-400"><?= date('d.m H:i', strtotime($conv['last_msg_time'])) ?></span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-xs text-gray-500 truncate"><?= e($conv['last_sender'] . $conv['preview']) ?></span>
              <?php if ($conv['unread'] > 0): ?>
              <span class="px-1.5 py-0.5 text-[10px] font-bold rounded-full bg-brand text-white"><?= $conv['unread'] ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
      <?php if (empty($conversations)): ?>
      <div class="p-8 text-center text-gray-400 text-sm">Keine Chats</div>
      <?php endif; ?>
    </div>

    <!-- Main chat area -->
    <div class="chat-main">
      <?php if ($activeChat && $chatName): ?>
      <!-- Chat header -->
      <div class="px-4 py-3 border-b bg-gray-50 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-full bg-<?= $chatType==='customer'?'blue':'purple' ?>-100 text-<?= $chatType==='customer'?'blue':'purple' ?>-700 flex items-center justify-center text-sm font-bold">
            <?= strtoupper(mb_substr($chatName,0,1)) ?>
          </div>
          <div>
            <div class="font-semibold text-sm"><?= e($chatName) ?></div>
            <div class="text-[10px] text-gray-400"><?= $chatType === 'customer' ? 'Kunde' : 'Partner' ?> #<?= $chatId ?> &middot; <?= count($chatMessages) ?> Nachrichten</div>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <span class="text-xs text-gray-400">Admin sieht alles</span>
        </div>
      </div>

      <!-- Messages -->
      <div class="chat-messages" id="chatScroll">
        <?php $lastDate = ''; foreach ($chatMessages as $m):
          $msgDate = date('d.m.Y', strtotime($m['created_at']));
          $isOut = $m['sender_type'] === 'admin';
          $isSystem = $m['sender_type'] === 'system' || $m['sender_type'] === 'ai';
        ?>
          <?php if ($msgDate !== $lastDate): $lastDate = $msgDate; ?>
          <div class="text-center my-4"><span class="px-3 py-1 bg-white rounded-full text-xs text-gray-500 shadow-sm"><?= $msgDate ?></span></div>
          <?php endif; ?>

          <div class="flex mb-2 <?= $isOut ? 'justify-end' : ($isSystem ? 'justify-center' : 'justify-start') ?>">
            <div class="bubble <?= $isSystem ? 'bubble-system' : ($isOut ? 'bubble-out' : 'bubble-in') ?>">
              <?php if (!$isOut && !$isSystem): ?>
              <div class="text-xs font-medium <?= $m['sender_type']==='customer' ? 'text-blue-600' : 'text-purple-600' ?> mb-1">
                <?= e($m['sender_name'] ?: ($m['sender_type'] === 'customer' ? $chatName : $chatName)) ?>
                <span class="text-gray-400 font-normal ml-1"><?= ucfirst($m['sender_type']) ?></span>
              </div>
              <?php endif; ?>
              <div><?= nl2br(e($m['message'])) ?></div>
              <?php if ($m['translated_message']): ?>
              <div class="text-xs text-brand italic mt-1 pt-1 border-t border-gray-200/50">KI: <?= nl2br(e($m['translated_message'])) ?></div>
              <?php endif; ?>
              <div class="flex items-center justify-end gap-2 mt-1">
                <span class="text-[10px] text-gray-400"><?= date('H:i', strtotime($m['created_at'])) ?></span>
                <?php if ($isOut && $m['read_at']): ?><span class="text-[10px] text-blue-500">&#10003;&#10003;</span><?php elseif ($isOut): ?><span class="text-[10px] text-gray-400">&#10003;</span><?php endif; ?>
                <!-- Admin delete (silent) -->
                <form method="POST" class="inline" onsubmit="return confirm('Nachricht löschen?')">
                  <input type="hidden" name="action" value="delete_msg"/>
                  <input type="hidden" name="msg_id" value="<?= $m['msg_id'] ?>"/>
                  <button class="text-[10px] text-gray-300 hover:text-red-500 opacity-0 group-hover:opacity-100" title="Löschen">&times;</button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($chatMessages)): ?>
        <div class="text-center text-gray-400 text-sm mt-8">Noch keine Nachrichten. Schreibe die erste!</div>
        <?php endif; ?>
      </div>

      <!-- Input -->
      <div class="chat-input">
        <form method="POST" class="flex gap-2">
          <input type="hidden" name="action" value="send"/>
          <input type="hidden" name="recipient_type" value="<?= e($chatType) ?>"/>
          <input type="hidden" name="recipient_id" value="<?= $chatId ?>"/>
          <input type="text" name="message" required autofocus placeholder="Nachricht eingeben..." class="flex-1 px-4 py-2.5 border rounded-xl text-sm focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"/>
          <button type="submit" class="px-5 py-2.5 bg-brand text-white rounded-xl font-medium text-sm hover:bg-brand/90 transition">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
          </button>
        </form>
      </div>

      <?php else: ?>
      <!-- No chat selected -->
      <div class="flex-1 flex items-center justify-center bg-gray-50">
        <div class="text-center">
          <div class="w-20 h-20 rounded-full bg-brand/10 flex items-center justify-center mx-auto mb-4">
            <svg class="w-10 h-10 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
          </div>
          <h3 class="text-lg font-semibold text-gray-700"><?= SITE ?> Chat</h3>
          <p class="text-sm text-gray-400 mt-1">Wähle einen Chat oder starte eine neue Konversation</p>
          <p class="text-xs text-gray-300 mt-3">Kunden sehen "<?= SITE ?> Team" als Absender</p>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$script = <<<JS
// Scroll to bottom on load
const chatScroll = document.getElementById('chatScroll');
if (chatScroll) chatScroll.scrollTop = chatScroll.scrollHeight;

// Show delete button on hover
document.querySelectorAll('.bubble').forEach(b => {
    b.classList.add('group');
    const del = b.querySelector('form button');
    if (del) { del.style.opacity = '0'; b.addEventListener('mouseenter', () => del.style.opacity = '1'); b.addEventListener('mouseleave', () => del.style.opacity = '0'); }
});
JS;
include __DIR__ . '/../includes/footer.php'; ?>
