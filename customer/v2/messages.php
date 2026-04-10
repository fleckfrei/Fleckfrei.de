<?php
require_once __DIR__ . '/../../includes/auth.php';
requireCustomer();
if (!customerCan('messages')) { header('Location: /customer/v2/'); exit; }
$title = 'Chat'; $page = 'messages';
$user = me();
$cid = $user['id'];

// Send message (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!verifyCsrf()) { header('Location: /customer/v2/messages.php?error=csrf'); exit; }
    $msg = trim($_POST['message'] ?? '');
    if ($msg !== '') {
        qLocal(
            "INSERT INTO messages (sender_type, sender_id, sender_name, recipient_type, recipient_id, message, job_id, channel) VALUES ('customer', ?, ?, 'admin', 0, ?, ?, ?)",
            [$cid, $user['name'], $msg, $_POST['job_id'] ?: null, 'portal']
        );

        // Fire-and-forget n8n webhook for AI processing / routing
        $webhook = 'https://n8n.la-renting.com/webhook/fleckfrei-v2-message';
        @file_get_contents($webhook, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'timeout' => 3,
                'content' => json_encode([
                    'event' => 'new_message',
                    'from' => 'customer',
                    'from_name' => $user['name'],
                    'from_id' => $cid,
                    'to_type' => 'admin',
                    'message' => $msg,
                    'job_id' => $_POST['job_id'] ?? null,
                ]),
            ],
        ]));
    }
    header('Location: /customer/v2/messages.php?sent=1'); exit;
}

// Fetch messages (local DB — messages table lives in the app.fleckfrei.de DB, not the shared la-renting one)
$messages = [];
try {
    $messages = allLocal(
        "SELECT * FROM messages WHERE (sender_type='customer' AND sender_id=?) OR (recipient_type='customer' AND recipient_id=?) ORDER BY created_at ASC LIMIT 200",
        [$cid, $cid]
    );
    // Mark unread as read
    qLocal("UPDATE messages SET read_at=NOW() WHERE recipient_type='customer' AND recipient_id=? AND read_at IS NULL", [$cid]);
} catch (Exception $e) {
    $messages = [];
}

// Recent jobs for "reference job" dropdown
$recentJobs = all(
    "SELECT j.j_id, j.j_date, s.title AS stitle
     FROM jobs j LEFT JOIN services s ON j.s_id_fk = s.s_id
     WHERE j.customer_id_fk = ? AND j.status = 1
     ORDER BY j.j_date DESC
     LIMIT 10",
    [$cid]
);

include __DIR__ . '/../../includes/layout-v2.php';
?>

<div class="mb-6">
  <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Chat</h1>
  <p class="text-gray-500 mt-1 text-sm">Ihre Nachrichten an das <?= SITE ?>-Team.</p>
</div>

<?php if (!empty($_GET['sent'])): ?>
<div class="card-elev bg-green-50 border-green-200 p-4 mb-6 flex items-center gap-2 text-sm text-green-800">
  <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
  Nachricht gesendet.
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

  <!-- Chat thread (2 cols) -->
  <div class="lg:col-span-2">
    <div class="card-elev overflow-hidden">
      <div class="px-5 py-4 border-b bg-gray-50 flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-brand text-white flex items-center justify-center font-bold text-sm">F</div>
        <div>
          <div class="font-semibold text-gray-900"><?= SITE ?> Team</div>
          <div class="text-[11px] text-gray-500">Antwortet meist innerhalb weniger Stunden</div>
        </div>
      </div>

      <?php if (empty($messages)): ?>
      <div class="py-16 px-4 text-center">
        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-brand-light mb-5">
          <svg class="w-10 h-10 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
        </div>
        <h3 class="text-lg font-semibold text-gray-900 mb-2">Noch keine Nachrichten</h3>
        <p class="text-sm text-gray-500 max-w-sm mx-auto">Senden Sie uns Ihre erste Nachricht über das Formular unten.</p>
      </div>
      <?php else: ?>
      <div class="p-5 space-y-4 max-h-[500px] overflow-y-auto" id="chat-thread">
        <?php foreach ($messages as $m):
            $isMine = $m['sender_type'] === 'customer' && (int) $m['sender_id'] === $cid;
        ?>
        <div class="flex <?= $isMine ? 'justify-end' : 'justify-start' ?>">
          <div class="max-w-[80%] <?= $isMine ? 'items-end' : 'items-start' ?> flex flex-col">
            <div class="<?= $isMine ? 'bg-brand text-white rounded-2xl rounded-br-md' : 'bg-gray-100 text-gray-900 rounded-2xl rounded-bl-md' ?> px-4 py-2.5 text-sm">
              <?= nl2br(e($m['message'])) ?>
              <?php if (!empty($m['translated_message'])): ?>
              <div class="text-[11px] opacity-75 italic mt-1 pt-1 border-t <?= $isMine ? 'border-white/20' : 'border-gray-300' ?>"><?= nl2br(e($m['translated_message'])) ?></div>
              <?php endif; ?>
            </div>
            <div class="text-[10px] text-gray-400 mt-1 px-1">
              <?= $isMine ? 'Sie' : e(SITE . ' Team') ?> ·
              <?= date('d.m. H:i', strtotime($m['created_at'])) ?>
              <?php if (!empty($m['job_id'])): ?> · Job #<?= (int) $m['job_id'] ?><?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <script>(function(){ const el = document.getElementById('chat-thread'); if (el) el.scrollTop = el.scrollHeight; })();</script>
      <?php endif; ?>

      <!-- Compose form (docked below thread) -->
      <form method="POST" class="border-t p-4 bg-gray-50">
        <input type="hidden" name="action" value="send"/>
        <?= csrfField() ?>
        <?php if (!empty($recentJobs)): ?>
        <select name="job_id" class="w-full mb-3 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:ring-2 focus:ring-brand focus:border-brand outline-none">
          <option value="">Allgemeine Frage</option>
          <?php foreach ($recentJobs as $j): ?>
          <option value="<?= (int) $j['j_id'] ?>">Job #<?= (int) $j['j_id'] ?> — <?= date('d.m.', strtotime($j['j_date'])) ?> <?= e($j['stitle']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <div class="flex gap-2">
          <textarea name="message" required rows="2" placeholder="Ihre Nachricht…" class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white focus:ring-2 focus:ring-brand focus:border-brand outline-none resize-none"></textarea>
          <button type="submit" class="px-5 py-2 bg-brand hover:bg-brand-dark text-white rounded-lg font-semibold text-sm self-end flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
            Senden
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Sidebar info -->
  <div class="lg:col-span-1 space-y-4">
    <div class="card-elev p-5">
      <h3 class="font-bold text-gray-900 mb-3">Schnell-Kontakt</h3>
      <div class="space-y-2">
        <a href="https://wa.me/<?= CONTACT_WA ?>" target="_blank" class="flex items-center gap-2 px-3 py-2 bg-green-50 hover:bg-green-100 rounded-lg text-sm text-green-800 font-medium transition">
          <span class="text-lg">💬</span> WhatsApp
        </a>
        <a href="mailto:<?= CONTACT_EMAIL ?>" class="flex items-center gap-2 px-3 py-2 border border-gray-200 hover:bg-gray-50 rounded-lg text-sm text-gray-700 font-medium transition">
          <span class="text-lg">✉️</span> E-Mail
        </a>
      </div>
    </div>
    <div class="card-elev p-5 bg-brand-light border-brand">
      <h3 class="font-bold text-brand-dark text-sm mb-2">Hinweis</h3>
      <p class="text-xs text-gray-700 leading-relaxed">
        Nachrichten werden automatisch an das <?= SITE ?>-Team weitergeleitet. Sie erhalten eine Antwort direkt hier im Chat oder per E-Mail.
      </p>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer-v2.php'; ?>
