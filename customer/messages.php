<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/llm-helpers.php';
requireCustomer();
if (!customerCan('messages')) { header('Location: /customer/'); exit; }
$title = 'Chat'; $page = 'messages';
$user = me();
$cid = $user['id'];

// ============================================================
// Helper: translate via Groq (llama-3.3-70b-versatile)
// ============================================================
function translateViaGroq(string $text, string $targetLang): ?string {
    if ($text === '' || !defined('GROQ_API_KEY') || !GROQ_API_KEY) return null;
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . GROQ_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'llama-3.3-70b-versatile',
            'messages' => [
                ['role' => 'system', 'content' => "Translate to $targetLang. Output ONLY the translation, no explanations, no quotes, preserve emojis."],
                ['role' => 'user', 'content' => $text],
            ],
            'temperature' => 0.1,
            'max_tokens' => 400,
        ]),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return null;
    $json = json_decode($resp, true);
    return trim($json['choices'][0]['message']['content'] ?? '') ?: null;
}

// ============================================================
// Send message (POST) — with optional multi-file upload + auto-translate
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!verifyCsrf()) { header('Location: /customer/messages.php?error=csrf'); exit; }
    $msg = trim($_POST['message'] ?? '');

    // Multi-file upload — iterate all uploaded files
    $uploadedPaths = [];
    if (!empty($_FILES['attachments']['tmp_name']) && is_array($_FILES['attachments']['tmp_name'])) {
        $allowed = [
            'image/jpeg','image/png','image/gif','image/webp',
            'video/mp4','video/quicktime','video/webm',
            'audio/mpeg','audio/mp4','audio/webm','audio/ogg','audio/x-m4a','audio/mp3',
            'application/pdf','application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        $maxSize = 25 * 1024 * 1024;
        $uploadDir = __DIR__ . '/../uploads/chat/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        foreach ($_FILES['attachments']['tmp_name'] as $i => $tmp) {
            if (!$tmp) continue;
            $type = $_FILES['attachments']['type'][$i] ?? '';
            $size = $_FILES['attachments']['size'][$i] ?? 0;
            if (!in_array($type, $allowed, true) || $size >= $maxSize) continue;
            $ext = pathinfo($_FILES['attachments']['name'][$i], PATHINFO_EXTENSION);
            $fname = 'c' . $cid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
            if (move_uploaded_file($tmp, $uploadDir . $fname)) {
                $uploadedPaths[] = '/uploads/chat/' . $fname;
            }
            if (count($uploadedPaths) >= 10) break;
        }
    }

    // Single attachment support (legacy) + multi
    if (empty($uploadedPaths) && !empty($_FILES['attachment']['tmp_name'])) {
        $allowed = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/webm','application/pdf'];
        $type = $_FILES['attachment']['type'] ?? '';
        if (in_array($type, $allowed, true) && $_FILES['attachment']['size'] < 25*1024*1024) {
            $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
            $uploadDir = __DIR__ . '/../uploads/chat/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $fname = 'c' . $cid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $fname)) {
                $uploadedPaths[] = '/uploads/chat/' . $fname;
            }
        }
    }

    // Determine partner target language (for translation direction)
    // Customer writes in DE (assumed) → translate to partner's language.
    // Get partner language from employee table if assigned, default 'ro' (most partners are romanian)
    $partnerLang = val("SELECT e.language FROM jobs j JOIN employee e ON j.emp_id_fk=e.emp_id WHERE j.customer_id_fk=? AND e.language IS NOT NULL ORDER BY j.j_date DESC LIMIT 1", [$cid]) ?: 'ro';

    $translated = null;
    if ($msg !== '' && $partnerLang !== 'de') {
        $translated = translateViaGroq($msg, $partnerLang);
    }

    if ($msg !== '' || !empty($uploadedPaths)) {
        // Insert one message per file (multi) OR single message if only text
        $attachmentsToInsert = empty($uploadedPaths) ? [null] : $uploadedPaths;
        $isFirst = true;
        foreach ($attachmentsToInsert as $att) {
            // Only attach text to first message if sending multi-files
            $thisMsg = $isFirst ? ($msg !== '' ? $msg : '[Datei]') : '[Datei]';
            $thisTrans = $isFirst ? $translated : null;
            qLocal(
                "INSERT INTO messages (sender_type, sender_id, sender_name, recipient_type, recipient_id, message, translated_message, attachment, job_id, channel) VALUES ('customer', ?, ?, 'admin', 0, ?, ?, ?, ?, ?)",
                [$cid, $user['name'], $thisMsg, $thisTrans, $att, $_POST['job_id'] ?: null, 'portal']
            );
            $isFirst = false;
        }

        // Fire-and-forget n8n webhook
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
                    'message' => $msg,
                    'translated' => $translated,
                    'attachments' => $uploadedPaths,
                ]),
            ],
        ]));
    }

    // AJAX: return JSON for seamless UX
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'attachments' => $uploadedPaths]);
        exit;
    }
    header('Location: /customer/messages.php'); exit;
}

// Just load last msg_id for initial state — messages themselves come via poll API
$lastMsgId = (int) valLocal(
    "SELECT COALESCE(MAX(msg_id), 0) FROM messages WHERE (sender_type='customer' AND sender_id=?) OR (recipient_type='customer' AND recipient_id=?)",
    [$cid, $cid]
);

include __DIR__ . '/../includes/layout-customer.php';
?>

<style>
  .chat-wrap { height: calc(100vh - 160px); min-height: 500px; }
  @media (max-width: 640px) { .chat-wrap { height: calc(100vh - 120px); } }
  [x-cloak] { display: none !important; }
  .chat-bg { background:#efeae2; background-image:url('data:image/svg+xml,%3Csvg width=%2240%22 height=%2240%22 viewBox=%220 0 40 40%22 xmlns=%22http://www.w3.org/2000/svg%22%3E%3Cg fill=%22%23d9d5cc%22 fill-opacity=%220.3%22%3E%3Cpath d=%22M0 0h20v20H0V0zm20 20h20v20H20V20z%22/%3E%3C/g%3E%3C/svg%3E'); }
</style>

<div class="mb-3">
  <a href="/customer/" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

<!-- WhatsApp-style chat container -->
<div class="chat-wrap flex flex-col bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden" x-data="chatApp()" x-init="init()">

  <!-- Header -->
  <div class="px-4 sm:px-5 py-3 border-b border-gray-100 bg-gradient-to-r from-brand to-brand-dark flex items-center gap-3 text-white">
    <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center font-bold text-lg flex-shrink-0">F</div>
    <div class="flex-1 min-w-0">
      <div class="font-semibold truncate"><?= SITE ?> Support</div>
      <div class="text-[11px] text-white/80 flex items-center gap-1">
        <span class="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse"></span>
        <span>Online · Übersetzt automatisch</span>
      </div>
    </div>
  </div>

  <!-- Messages area -->
  <div id="chat-thread" class="flex-1 overflow-y-auto p-4 sm:p-5 space-y-2 chat-bg">
    <!-- Empty state -->
    <template x-if="messages.length === 0">
      <div class="flex flex-col items-center justify-center h-full text-center">
        <div class="bg-white/90 rounded-2xl p-6 max-w-sm shadow-md">
          <div class="w-14 h-14 mx-auto rounded-full bg-brand text-white flex items-center justify-center text-2xl mb-3">F</div>
          <h3 class="font-bold text-gray-900 mb-1">Willkommen im Chat</h3>
          <p class="text-xs text-gray-500">Senden Sie eine Nachricht, Fotos, Videos oder Sprachnachrichten. Alles wird automatisch an den Partner in seiner Sprache übersetzt.</p>
        </div>
      </div>
    </template>

    <!-- Messages -->
    <template x-for="(m, idx) in messages" :key="m.id">
      <div>
        <!-- Date divider -->
        <div x-show="idx === 0 || messages[idx-1].date !== m.date" class="flex justify-center my-3">
          <span class="px-3 py-1 rounded-full bg-white/70 text-[10px] font-semibold text-gray-600 shadow-sm" x-text="formatDate(m.date)"></span>
        </div>

        <!-- Bubble -->
        <div class="flex" :class="m.mine ? 'justify-end' : 'justify-start'">
          <div class="max-w-[85%] sm:max-w-[70%] rounded-2xl shadow-sm overflow-hidden"
               :class="m.mine ? 'bg-green-100 rounded-tr-sm' : 'bg-white rounded-tl-sm'">

            <!-- Attachment -->
            <template x-if="m.attachment">
              <div>
                <template x-if="m.att_type === 'image'">
                  <a :href="m.attachment" target="_blank">
                    <img :src="m.attachment" class="max-w-full max-h-72 object-cover cursor-pointer"/>
                  </a>
                </template>
                <template x-if="m.att_type === 'video'">
                  <video controls class="max-w-full max-h-72">
                    <source :src="m.attachment"/>
                  </video>
                </template>
                <template x-if="m.att_type === 'audio'">
                  <audio controls class="w-full max-w-xs px-2 py-1.5">
                    <source :src="m.attachment"/>
                  </audio>
                </template>
                <template x-if="m.att_type === 'file'">
                  <a :href="m.attachment" target="_blank" class="flex items-center gap-2 px-3 py-2 hover:bg-gray-50">
                    <svg class="w-5 h-5 text-brand flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                    <span class="text-xs font-medium text-brand truncate" x-text="m.attachment.split('/').pop()"></span>
                  </a>
                </template>
              </div>
            </template>

            <!-- Text content -->
            <template x-if="m.message && m.message !== '[Datei]'">
              <div class="px-3 py-2 text-sm text-gray-800 whitespace-pre-wrap break-words" x-text="m.message"></div>
            </template>

            <!-- Translation -->
            <template x-if="m.translated && !m.mine">
              <div class="px-3 pb-2 text-[11px] text-gray-500 italic border-t border-gray-100 pt-1.5">
                🌐 <span x-text="m.translated"></span>
              </div>
            </template>

            <!-- Meta -->
            <div class="px-3 pb-1.5 text-[10px] text-gray-500 flex items-center justify-end gap-1">
              <span x-text="m.time"></span>
              <template x-if="m.mine">
                <svg class="w-3 h-3" :class="m.read ? 'text-blue-500' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
              </template>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>

  <!-- Compose area -->
  <form method="POST" enctype="multipart/form-data" class="border-t border-gray-100 p-3 bg-gray-50" @submit.prevent="sendMessage">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="send"/>

    <!-- File preview strip -->
    <div x-show="selectedFiles.length > 0" x-cloak class="flex gap-2 overflow-x-auto pb-2 mb-2">
      <template x-for="(f, i) in selectedFiles" :key="i">
        <div class="relative flex-shrink-0">
          <img x-show="f.preview" :src="f.preview" class="w-16 h-16 rounded-lg object-cover border border-gray-200"/>
          <div x-show="!f.preview" class="w-16 h-16 rounded-lg border border-gray-200 bg-white flex items-center justify-center text-[10px] text-gray-500 p-1 text-center truncate" x-text="f.name"></div>
          <button type="button" @click="removeFile(i)" class="absolute -top-1 -right-1 w-5 h-5 rounded-full bg-red-500 text-white text-[10px] flex items-center justify-center shadow">✕</button>
        </div>
      </template>
    </div>

    <div class="flex items-end gap-2">
      <!-- File upload -->
      <label class="flex-shrink-0 w-11 h-11 rounded-full bg-white border border-gray-200 hover:border-brand hover:bg-brand/5 flex items-center justify-center cursor-pointer transition" title="Datei anhängen (bis 10)">
        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
        <input type="file" multiple accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx" class="hidden" @change="addFiles($event.target.files); $event.target.value=''"/>
      </label>

      <!-- Voice record button -->
      <button type="button" @click="toggleRecord()" :class="recording ? 'bg-red-500 text-white animate-pulse' : 'bg-white border border-gray-200 hover:border-brand hover:bg-brand/5'" class="flex-shrink-0 w-11 h-11 rounded-full flex items-center justify-center transition" :title="recording ? 'Aufnahme stoppen' : 'Sprachnachricht'">
        <svg class="w-5 h-5" :class="recording ? 'text-white' : 'text-gray-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>
      </button>

      <!-- Textarea -->
      <div class="flex-1 bg-white border border-gray-200 rounded-2xl focus-within:ring-2 focus-within:ring-brand/20 focus-within:border-brand">
        <textarea x-model="draftText" rows="1" placeholder="Nachricht schreiben…"
          @keydown.enter.prevent="if(!$event.shiftKey) sendMessage()"
          @input="autoResize($event.target)"
          class="w-full px-4 py-3 text-sm outline-none resize-none max-h-32"></textarea>
      </div>

      <!-- Send button -->
      <button type="submit" :disabled="sending || (draftText.trim() === '' && selectedFiles.length === 0)" :class="sending || (draftText.trim() === '' && selectedFiles.length === 0) ? 'opacity-50' : ''" class="flex-shrink-0 w-11 h-11 rounded-full bg-brand hover:bg-brand-dark text-white flex items-center justify-center shadow-lg shadow-brand/20 transition">
        <svg x-show="!sending" class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
        <svg x-show="sending" x-cloak class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
      </button>
    </div>
  </form>
</div>

<script>
function chatApp() {
  return {
    messages: [],
    draftText: '',
    selectedFiles: [],
    sending: false,
    recording: false,
    mediaRecorder: null,
    audioChunks: [],
    lastId: <?= $lastMsgId ?>,
    pollInterval: null,

    async init() {
      await this.loadNewMessages();
      this.$nextTick(() => this.scrollBottom());
      // Poll every 4s — lightweight JSON
      this.pollInterval = setInterval(() => this.loadNewMessages(), 4000);
    },

    async loadNewMessages() {
      try {
        // First load: since=0 to get all; after: since=lastId
        const since = this.messages.length === 0 ? 0 : this.lastId;
        const r = await fetch('/api/chat-poll.php?since=' + since, { credentials: 'same-origin' });
        const d = await r.json();
        if (d.success && d.messages) {
          if (this.messages.length === 0) {
            this.messages = d.messages;
          } else {
            this.messages.push(...d.messages);
          }
          if (d.last_id > this.lastId) this.lastId = d.last_id;
          if (d.count > 0) this.$nextTick(() => this.scrollBottom());
        }
      } catch (e) { /* silent */ }
    },

    scrollBottom() {
      const el = document.getElementById('chat-thread');
      if (el) el.scrollTop = el.scrollHeight;
    },

    autoResize(el) {
      el.style.height = 'auto';
      el.style.height = Math.min(el.scrollHeight, 120) + 'px';
    },

    addFiles(files) {
      for (const file of files) {
        if (this.selectedFiles.length >= 10) break;
        const preview = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
        this.selectedFiles.push({ file, name: file.name, preview });
      }
    },

    removeFile(idx) {
      this.selectedFiles.splice(idx, 1);
    },

    async sendMessage() {
      if (this.sending) return;
      if (this.draftText.trim() === '' && this.selectedFiles.length === 0) return;

      this.sending = true;
      const fd = new FormData();
      fd.append('action', 'send');
      fd.append('_token', '<?= $_SESSION['csrf'] ?? '' ?>');
      fd.append('message', this.draftText);
      for (const f of this.selectedFiles) fd.append('attachments[]', f.file);

      try {
        await fetch('/customer/messages.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        this.draftText = '';
        this.selectedFiles = [];
        // Reset textarea height
        const ta = document.querySelector('textarea[x-model="draftText"]');
        if (ta) ta.style.height = 'auto';
        // Immediately poll for the new message
        await this.loadNewMessages();
      } catch (e) {
        alert('Fehler beim Senden');
      } finally {
        this.sending = false;
      }
    },

    async toggleRecord() {
      if (this.recording) {
        this.mediaRecorder.stop();
        this.recording = false;
        return;
      }
      try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        this.mediaRecorder = new MediaRecorder(stream);
        this.audioChunks = [];
        this.mediaRecorder.ondataavailable = e => this.audioChunks.push(e.data);
        this.mediaRecorder.onstop = () => {
          const blob = new Blob(this.audioChunks, { type: 'audio/webm' });
          const file = new File([blob], 'voice_' + Date.now() + '.webm', { type: 'audio/webm' });
          this.selectedFiles.push({ file, name: file.name, preview: null });
          stream.getTracks().forEach(t => t.stop());
        };
        this.mediaRecorder.start();
        this.recording = true;
      } catch (e) {
        alert('Mikrofon-Zugriff nicht möglich: ' + e.message);
      }
    },

    formatDate(dateStr) {
      const today = new Date().toISOString().slice(0, 10);
      const d = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
      if (dateStr === today) return 'Heute';
      if (dateStr === d) return 'Gestern';
      const parts = dateStr.split('-');
      return parts[2] + '.' + parts[1] + '.' + parts[0];
    },
  };
}
</script>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
