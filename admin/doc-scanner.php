<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

// === Save scanned document handler ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_scan') {
    if (!verifyCsrf()) { http_response_code(403); echo 'CSRF'; exit; }
    $entityType = in_array($_POST['entity_type'] ?? '', ['employee','customer','general'], true) ? $_POST['entity_type'] : 'general';
    $entityId = (int)($_POST['entity_id'] ?? 0);

    // Save uploaded file to disk
    $savedPath = '';
    if (!empty($_FILES['file']['tmp_name'])) {
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION) ?: 'pdf';
        $dir = __DIR__ . '/../uploads/scanned-docs/' . date('Y-m') . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $fname = 'scan_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $rel = '/uploads/scanned-docs/' . date('Y-m') . '/' . $fname;
        if (move_uploaded_file($_FILES['file']['tmp_name'], $dir . $fname)) $savedPath = $rel;
    }

    q("INSERT INTO documents (entity_type, entity_id, doc_type, label, file_path, file_name, file_size, mime_type, issued_at, expires_at, issuer, notes, extracted_text, status, uploaded_by)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'valid','admin-scan')",
       [$entityType === 'general' ? 'employee' : $entityType, $entityId,
        $_POST['doc_type'] ?? 'sonstiges',
        $_POST['label'] ?? 'Scan ' . date('d.m.Y H:i'),
        $savedPath,
        $_POST['file_name'] ?? 'scan',
        (int)($_POST['file_size'] ?? 0),
        $_POST['mime_type'] ?? 'application/pdf',
        $_POST['issued_at'] ?: null,
        $_POST['expires_at'] ?: null,
        $_POST['issuer'] ?: null,
        $_POST['notes'] ?: null,
        $_POST['extracted_text'] ?? null]);

    $docId = (int)lastInsertId();
    audit('scan-save', 'document', $docId, "Scan gespeichert: " . ($_POST['label'] ?? ''));
    header("Location: /admin/doc-scanner.php?saved=$docId"); exit;
}

// Pre-load customers + employees for selector
$allCustomers = all("SELECT customer_id, name FROM customer WHERE status=1 ORDER BY name");
$allEmployees = all("SELECT emp_id, name, surname FROM employee WHERE status=1 ORDER BY name");
$title = 'AI Document Scanner'; $page = 'doc-scanner';
include __DIR__ . '/../includes/layout.php';
?>

<div class="max-w-4xl mx-auto">
  <div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold flex items-center gap-2">📜 AI Document Scanner</h1>
    <?php if (!empty($_GET['saved'])): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-2 rounded-lg text-sm">✓ Scan gespeichert (#<?= (int)$_GET['saved'] ?>) — <a href="/admin/view-employee.php?tab=docs" class="underline">Zum Partner-Tab</a></div>
    <?php endif; ?>
    <a href="/admin/view-employee.php" class="text-sm text-brand hover:underline">🔗 Direkt-Upload zu Partner</a>
  </div>

  <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm mb-6">
    💡 Lade ein beliebiges Dokument hoch (PDF/JPG/PNG, max 10MB) — AI extrahiert <strong>strukturierte Daten</strong> + <strong>kompletten Text</strong> (auch Handschrift). Optional speicherbar in Documents-DB (Partner/Kunde-verknüpft).
  </div>

  <!-- Drag-Drop Box -->
  <form id="scanForm" enctype="multipart/form-data" class="bg-white rounded-xl border p-6 mb-6">
    <label for="scanFile" class="block cursor-pointer border-2 border-dashed border-gray-300 hover:border-brand bg-gray-50 rounded-xl p-10 text-center transition">
      <div class="text-5xl mb-3">📄</div>
      <div class="text-lg font-semibold text-gray-700" id="scanFileLabel">Datei wählen oder hierher ziehen</div>
      <div class="text-sm text-gray-500 mt-1">PDF, JPG, PNG, WEBP · max 10MB · Handschrift wird erkannt</div>
      <input type="file" id="scanFile" accept=".pdf,.png,.jpg,.jpeg,.webp" class="hidden"/>
    </label>
    <div id="scanStatus" class="text-sm mt-4 hidden"></div>
  </form>

  <!-- Result Areas -->
  <div id="scanResult" class="hidden space-y-6">

    <!-- Strukturierte Daten -->
    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold mb-3 flex items-center gap-2">📋 Strukturierte Daten</h3>
      <dl class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
        <div><dt class="text-xs text-gray-500">Bezeichnung</dt><dd class="font-medium" id="rLabel">—</dd></div>
        <div><dt class="text-xs text-gray-500">Aussteller</dt><dd class="font-medium" id="rIssuer">—</dd></div>
        <div><dt class="text-xs text-gray-500">Ausgestellt am</dt><dd class="font-medium" id="rIssued">—</dd></div>
        <div><dt class="text-xs text-gray-500">Gültig bis</dt><dd class="font-medium" id="rExpires">—</dd></div>
        <div class="md:col-span-2"><dt class="text-xs text-gray-500">Notizen / Resume</dt><dd class="text-sm" id="rNotes">—</dd></div>
      </dl>
    </div>

    <!-- Vollständiger Text -->
    <div class="bg-white rounded-xl border p-5">
      <div class="flex items-center justify-between mb-3">
        <h3 class="font-semibold flex items-center gap-2">📜 Kompletter Text (inkl. Handschrift)</h3>
        <button type="button" id="copyBtn" class="px-3 py-1 bg-brand text-white rounded-lg text-xs">📋 Kopieren</button>
      </div>
      <pre id="rFullText" class="text-xs whitespace-pre-wrap font-mono bg-gray-50 p-4 rounded border max-h-[600px] overflow-y-auto">—</pre>
    </div>

    <!-- Save to Database Section -->
    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold mb-3 flex items-center gap-2">💾 Scan speichern</h3>
      <p class="text-xs text-gray-500 mb-3">Speichert Datei + extrahierten Text in Documents-DB. Wähle Partner oder Kunde zum Verknüpfen.</p>
      <form id="saveForm" method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_scan"/>
        <input type="hidden" name="label" id="saveLabel"/>
        <input type="hidden" name="issuer" id="saveIssuer"/>
        <input type="hidden" name="issued_at" id="saveIssued"/>
        <input type="hidden" name="expires_at" id="saveExpires"/>
        <input type="hidden" name="notes" id="saveNotes"/>
        <input type="hidden" name="extracted_text" id="saveFullText"/>
        <input type="hidden" name="file_name" id="saveFileName"/>
        <input type="hidden" name="file_size" id="saveFileSize"/>
        <input type="hidden" name="mime_type" id="saveMime"/>
        <input type="file" name="file" id="saveFileInput" class="hidden"/>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Verknüpfen mit</label>
          <select name="entity_type" id="saveEntityType" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="general">Allgemein (kein Partner/Kunde)</option>
            <option value="employee">Partner</option>
            <option value="customer">Kunde</option>
          </select>
        </div>
        <div id="entityIdWrap" style="display:none">
          <label class="block text-xs font-medium text-gray-500 mb-1">Wer?</label>
          <select name="entity_id" id="saveEntityId" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="">Wählen...</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Doc-Typ</label>
          <select name="doc_type" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="sonstiges">Sonstiges</option>
            <option value="vertrag">Vertrag</option>
            <option value="haftpflicht">Haftpflicht</option>
            <option value="gewerbeanmeldung">Gewerbeanmeldung</option>
            <option value="ust_bescheinigung">USt-Bescheinigung</option>
            <option value="freistellung_48b">§48b Freistellung</option>
            <option value="unbedenklichkeit_fa">Unbedenklichkeit FA</option>
            <option value="berufshaftpflicht">Berufshaftpflicht</option>
            <option value="ausweis">Ausweis</option>
          </select>
        </div>
        <div class="md:col-span-2">
          <button type="submit" class="w-full px-4 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg font-semibold">💾 Scan in DB speichern</button>
        </div>
      </form>
    </div>

    <!-- Telegram Send -->
    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold mb-3 flex items-center gap-2">📱 An Telegram senden</h3>
      <p class="text-xs text-gray-500 mb-3">Sendet Text an @fleckfrei_bot (oder Email-Fallback an info@fleckfrei.de). 1-malig: schick /start an @fleckfrei_bot um Telegram zu aktivieren.</p>
      <button type="button" id="tgBtn" class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm">📤 Text an Telegram senden</button>
      <span id="tgStatus" class="ml-3 text-xs"></span>
    </div>

  </div>
</div>

<script>
(function(){
  var fileInput = document.getElementById('scanFile');
  var fileLabel = document.getElementById('scanFileLabel');
  var status = document.getElementById('scanStatus');
  var result = document.getElementById('scanResult');
  var dropZone = fileInput.closest('label');
  var fullTextValue = '';

  // Drag & drop
  ['dragenter','dragover'].forEach(ev => dropZone.addEventListener(ev, e => {
    e.preventDefault(); dropZone.classList.add('border-brand','bg-brand/5');
  }));
  ['dragleave','drop'].forEach(ev => dropZone.addEventListener(ev, e => {
    e.preventDefault(); dropZone.classList.remove('border-brand','bg-brand/5');
  }));
  dropZone.addEventListener('drop', e => {
    if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; fileInput.dispatchEvent(new Event('change')); }
  });

  fileInput.addEventListener('change', async function() {
    var f = fileInput.files[0];
    if (!f) return;
    fileLabel.textContent = '📎 ' + f.name + ' (' + Math.round(f.size/1024) + ' KB)';
    status.classList.remove('hidden');
    status.className = 'text-sm mt-4 text-blue-600';
    status.innerHTML = '<span class="inline-block animate-spin">⟳</span> AI scannt Dokument... (kann 10-30s dauern)';
    result.classList.add('hidden');

    var fd = new FormData();
    fd.append('file', f);
    try {
      var r = await fetch('/api/doc-extract.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      var d = await r.json();
      if (d.success) {
        document.getElementById('rLabel').textContent = d.label || '—';
        document.getElementById('rIssuer').textContent = d.issuer || '—';
        document.getElementById('rIssued').textContent = d.issued_at || '—';
        document.getElementById('rExpires').textContent = d.expires_at || '—';
        document.getElementById('rNotes').textContent = d.notes || '—';
        document.getElementById('rFullText').textContent = d.full_text || '(kein Text extrahiert)';
        fullTextValue = d.full_text || '';
        status.className = 'text-sm mt-4 text-green-600';
        status.textContent = '✓ Extraktion erfolgreich';
        result.classList.remove('hidden');
      } else {
        status.className = 'text-sm mt-4 text-red-600';
        status.textContent = '✗ ' + (d.error || 'Fehler') + (d.raw ? ' — Raw: ' + d.raw : '');
      }
    } catch(e) {
      status.className = 'text-sm mt-4 text-red-600';
      status.textContent = '✗ Netzwerk-Fehler: ' + e.message;
    }
  });

  // Copy
  document.getElementById('copyBtn').addEventListener('click', function() {
    navigator.clipboard.writeText(document.getElementById('rFullText').textContent);
    this.textContent = '✓ Kopiert';
    setTimeout(() => this.textContent = '📋 Kopieren', 2000);
  });

  // Telegram
  document.getElementById('tgBtn').addEventListener('click', async function() {
    if (!fullTextValue) { document.getElementById('tgStatus').textContent = '— kein Text vorhanden'; return; }
    document.getElementById('tgStatus').textContent = '⟳ sende...';
    try {
      var r = await fetch('/api/tg-send.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({text: '📜 *Doc-Scanner Result:*\n\n' + fullTextValue.substring(0, 3500)})
      });
      var d = await r.json();
      document.getElementById('tgStatus').textContent = d.ok ? '✓ gesendet' : ('✗ ' + (d.error || 'Fehler'));
    } catch(e) {
      document.getElementById('tgStatus').textContent = '✗ ' + e.message;
    }
  });

  // Entity selector
  var entType = document.getElementById('saveEntityType');
  var entWrap = document.getElementById('entityIdWrap');
  var entSel = document.getElementById('saveEntityId');
  var customers = <?= json_encode($allCustomers, JSON_HEX_APOS) ?>;
  var employees = <?= json_encode($allEmployees, JSON_HEX_APOS) ?>;
  if (entType) entType.addEventListener('change', function() {
    if (entType.value === 'general') { entWrap.style.display='none'; return; }
    entWrap.style.display='block';
    entSel.innerHTML = '<option value="">Wählen...</option>';
    var list = entType.value === 'customer' ? customers : employees;
    var idField = entType.value === 'customer' ? 'customer_id' : 'emp_id';
    list.forEach(function(x) {
      var o = document.createElement('option');
      o.value = x[idField];
      o.textContent = x.name + (x.surname ? ' ' + x.surname : '');
      entSel.appendChild(o);
    });
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
