<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Schlüssel-Inventar'; $page = 'keys';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/keys.php'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'create_key') {
        q("INSERT INTO key_inventory (customer_id_fk, label, description, property_address, status, current_holder_type) VALUES (?,?,?,?,'with_office','office')",
          [(int)$_POST['customer_id_fk'], $_POST['label'], $_POST['description'] ?? '', $_POST['property_address'] ?? '']);
        $keyId = (int)lastInsertId();
        $cName = val("SELECT name FROM customer WHERE customer_id=?", [(int)$_POST['customer_id_fk']]);
        q("INSERT INTO key_handovers (key_id_fk, action, from_type, from_name, to_type, to_name, notes, recorded_by_admin) VALUES (?, 'received', 'customer', ?, 'office', 'Fleckfrei Büro', ?, ?)",
          [$keyId, $cName, $_POST['notes'] ?? 'Erstabgabe', $_SESSION['uid']]);
        audit('create', 'key_inventory', $keyId, "Schlüssel erstellt: " . $_POST['label']);
        header('Location: /admin/keys.php?saved=1'); exit;
    }

    if ($act === 'transfer') {
        $kid = (int)$_POST['key_id'];
        $key = one("SELECT * FROM key_inventory WHERE key_id=?", [$kid]);
        if (!$key) { header('Location: /admin/keys.php?error=notfound'); exit; }

        $toType = $_POST['to_type'] ?? 'office';
        $toId = !empty($_POST['to_id']) ? (int)$_POST['to_id'] : null;
        $action = $_POST['handover_action'] ?? 'given';

        // Resolve to_name
        $toName = 'Fleckfrei Büro';
        if ($toType === 'partner' && $toId) {
            $toName = val("SELECT CONCAT(name, ' ', surname) FROM employee WHERE emp_id=?", [$toId]) ?: 'Partner';
        } elseif ($toType === 'customer' && $toId) {
            $toName = val("SELECT name FROM customer WHERE customer_id=?", [$toId]) ?: 'Kunde';
        }

        // From = current holder
        $fromType = $key['current_holder_type'];
        $fromId = $key['current_holder_id'];
        $fromName = 'Fleckfrei Büro';
        if ($fromType === 'partner' && $fromId) {
            $fromName = val("SELECT CONCAT(name, ' ', surname) FROM employee WHERE emp_id=?", [$fromId]) ?: 'Partner';
        } elseif ($fromType === 'customer' && $fromId) {
            $fromName = val("SELECT name FROM customer WHERE customer_id=?", [$fromId]) ?: 'Kunde';
        }

        // Status mapping
        $newStatus = match($toType) {
            'office'   => 'with_office',
            'partner'  => 'with_partner',
            'customer' => 'with_customer',
            default    => $key['status'],
        };
        if ($action === 'lost') $newStatus = 'lost';
        if ($action === 'returned' && $toType === 'customer') $newStatus = 'returned';

        q("UPDATE key_inventory SET status=?, current_holder_type=?, current_holder_id=? WHERE key_id=?",
          [$newStatus, $toType, $toId, $kid]);
        q("INSERT INTO key_handovers (key_id_fk, action, from_type, from_id, from_name, to_type, to_id, to_name, notes, recorded_by_admin) VALUES (?,?,?,?,?,?,?,?,?,?)",
          [$kid, $action, $fromType, $fromId, $fromName, $toType, $toId, $toName, $_POST['notes'] ?? '', $_SESSION['uid']]);
        audit('update', 'key_inventory', $kid, "Schlüssel-Übergabe: $fromName → $toName ($action)");
        header('Location: /admin/keys.php?saved=1'); exit;
    }
}

$filter = $_GET['filter'] ?? 'all';
$where = $filter === 'all' ? '1=1' : "status='" . addslashes($filter) . "'";
$keys = all("SELECT k.*, c.name AS cname FROM key_inventory k LEFT JOIN customer c ON k.customer_id_fk=c.customer_id WHERE $where ORDER BY k.updated_at DESC");

$customers = all("SELECT customer_id, name, customer_type FROM customer WHERE status=1 ORDER BY name LIMIT 200");
$partners = all("SELECT emp_id, name, surname FROM employee WHERE status=1 ORDER BY name");

$counts = [
    'all'           => (int) val("SELECT COUNT(*) FROM key_inventory"),
    'with_office'   => (int) val("SELECT COUNT(*) FROM key_inventory WHERE status='with_office'"),
    'with_partner'  => (int) val("SELECT COUNT(*) FROM key_inventory WHERE status='with_partner'"),
    'with_customer' => (int) val("SELECT COUNT(*) FROM key_inventory WHERE status='with_customer'"),
    'lost'          => (int) val("SELECT COUNT(*) FROM key_inventory WHERE status='lost'"),
];

$recentHistory = all("SELECT h.*, k.label AS key_label, k.customer_id_fk, c.name AS cname FROM key_handovers h JOIN key_inventory k ON h.key_id_fk=k.key_id LEFT JOIN customer c ON k.customer_id_fk=c.customer_id ORDER BY h.happened_at DESC LIMIT 30");

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Gespeichert.</div>
<?php endif; ?>

<div class="flex items-start justify-between mb-6 flex-wrap gap-4" x-data="{ showCreate: false }">
  <div>
    <h1 class="text-2xl font-bold text-gray-900">🔑 Schlüssel-Inventar</h1>
    <p class="text-sm text-gray-500 mt-1">Vollständige Nachverfolgung aller Kunden-Schlüssel — DSGVO-konform.</p>
  </div>
  <button @click="showCreate = !showCreate" class="px-4 py-2 bg-brand hover:bg-brand/90 text-white rounded-xl text-sm font-semibold flex items-center gap-2">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
    Neuer Schlüssel
  </button>

  <!-- Create form -->
  <div x-show="showCreate" x-cloak class="w-full bg-white rounded-xl border p-6">
    <h2 class="font-semibold text-lg mb-4">Schlüssel registrieren</h2>
    <form method="POST" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_key"/>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Kunde</label>
        <select name="customer_id_fk" required class="w-full px-3 py-2.5 border rounded-xl bg-white">
          <option value="">— Kunde wählen —</option>
          <?php foreach ($customers as $c): ?>
          <option value="<?= $c['customer_id'] ?>"><?= e($c['name']) ?> (<?= e($c['customer_type']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Bezeichnung</label>
        <input name="label" required placeholder="z.B. Pasteurstr 17 - Hausschlüssel" class="w-full px-3 py-2.5 border rounded-xl"/>
      </div>
      <div class="lg:col-span-2">
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Adresse</label>
        <input name="property_address" placeholder="Straße, PLZ, Stadt" class="w-full px-3 py-2.5 border rounded-xl"/>
      </div>
      <div class="lg:col-span-2">
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Beschreibung</label>
        <textarea name="description" rows="2" placeholder="Anhänger-Farbe, Form, Besonderheiten..." class="w-full px-3 py-2.5 border rounded-xl"></textarea>
      </div>
      <div class="lg:col-span-2">
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Notiz zur Erstabgabe</label>
        <input name="notes" placeholder="z.B. Übergabe persönlich am 10.04.2026" class="w-full px-3 py-2.5 border rounded-xl"/>
      </div>
      <div class="lg:col-span-2 flex gap-3">
        <button type="submit" class="px-6 py-2.5 bg-brand hover:bg-brand/90 text-white rounded-xl font-semibold">Schlüssel speichern</button>
        <button type="button" @click="showCreate = false" class="px-6 py-2.5 border rounded-xl text-gray-700 hover:bg-gray-50">Abbrechen</button>
      </div>
    </form>
  </div>
</div>

<!-- Filter tabs -->
<div class="flex gap-2 mb-4 flex-wrap">
  <a href="?filter=all" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'all' ? 'bg-brand text-white' : 'bg-white border hover:border-brand' ?>">Alle (<?= $counts['all'] ?>)</a>
  <a href="?filter=with_office" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'with_office' ? 'bg-green-600 text-white' : 'bg-white border hover:border-green-600' ?>">📥 Im Büro (<?= $counts['with_office'] ?>)</a>
  <a href="?filter=with_partner" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'with_partner' ? 'bg-amber-600 text-white' : 'bg-white border hover:border-amber-600' ?>">👤 Beim Partner (<?= $counts['with_partner'] ?>)</a>
  <a href="?filter=with_customer" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'with_customer' ? 'bg-blue-600 text-white' : 'bg-white border hover:border-blue-600' ?>">🏠 Beim Kunde (<?= $counts['with_customer'] ?>)</a>
  <a href="?filter=lost" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filter === 'lost' ? 'bg-red-600 text-white' : 'bg-white border hover:border-red-600' ?>">⚠ Verloren (<?= $counts['lost'] ?>)</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <!-- Keys list -->
  <div class="lg:col-span-2 bg-white rounded-xl border overflow-hidden" x-data="{ transferKey: null }">
    <div class="px-5 py-3 border-b bg-gray-50">
      <h3 class="font-semibold text-gray-900"><?= count($keys) ?> Schlüssel</h3>
    </div>
    <?php if (empty($keys)): ?>
    <div class="p-12 text-center text-sm text-gray-400">Keine Schlüssel.</div>
    <?php else: ?>
    <div class="divide-y">
      <?php foreach ($keys as $k):
        $statusClass = match($k['status']) {
            'with_office' => 'bg-green-100 text-green-700',
            'with_partner' => 'bg-amber-100 text-amber-700',
            'with_customer' => 'bg-blue-100 text-blue-700',
            'lost' => 'bg-red-100 text-red-700',
            default => 'bg-gray-100 text-gray-600',
        };
        $holderName = '—';
        if ($k['current_holder_type'] === 'partner' && $k['current_holder_id']) {
            $holderName = val("SELECT CONCAT(name, ' ', surname) FROM employee WHERE emp_id=?", [$k['current_holder_id']]) ?: '—';
        } elseif ($k['current_holder_type'] === 'office') {
            $holderName = 'Fleckfrei Büro';
        }
      ?>
      <div class="p-4 hover:bg-gray-50 transition">
        <div class="flex items-start justify-between gap-4">
          <div class="flex items-start gap-3 min-w-0 flex-1">
            <div class="text-2xl flex-shrink-0">🔑</div>
            <div class="min-w-0 flex-1">
              <div class="font-semibold text-gray-900"><?= e($k['label']) ?></div>
              <div class="text-xs text-gray-500 mt-0.5"><?= e($k['cname']) ?> · <?= e($k['property_address']) ?></div>
              <?php if ($k['description']): ?>
              <div class="text-xs text-gray-400 mt-1"><?= e($k['description']) ?></div>
              <?php endif; ?>
              <div class="text-[11px] text-gray-500 mt-1.5 flex items-center gap-1">
                Aktuell bei: <span class="font-semibold text-gray-700"><?= e($holderName) ?></span>
              </div>
            </div>
          </div>
          <div class="flex flex-col items-end gap-2">
            <span class="px-2.5 py-1 rounded-full text-[10px] font-semibold whitespace-nowrap <?= $statusClass ?>">
              <?= match($k['status']) { 'with_office'=>'Im Büro', 'with_partner'=>'Beim Partner', 'with_customer'=>'Beim Kunde', 'lost'=>'Verloren', 'returned'=>'Zurück', default=>$k['status'] } ?>
            </span>
            <button @click="transferKey = <?= $k['key_id'] ?>" class="text-xs text-brand hover:underline">Übergeben →</button>
          </div>
        </div>

        <!-- Inline transfer form -->
        <div x-show="transferKey === <?= $k['key_id'] ?>" x-cloak x-transition class="mt-3 pt-3 border-t border-gray-100">
          <form method="POST" class="grid grid-cols-2 gap-2">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="transfer"/>
            <input type="hidden" name="key_id" value="<?= $k['key_id'] ?>"/>

            <select name="handover_action" class="px-3 py-2 border rounded-lg text-xs bg-white">
              <option value="given">📤 Weitergeben</option>
              <option value="returned">↩️ Zurückgeben (Kunde)</option>
              <option value="lost">⚠ Verloren melden</option>
              <option value="found">✓ Wiedergefunden</option>
            </select>
            <select name="to_type" class="px-3 py-2 border rounded-lg text-xs bg-white">
              <option value="office">🏢 Fleckfrei Büro</option>
              <option value="partner">👤 An Partner</option>
              <option value="customer">🏠 An Kunde zurück</option>
            </select>
            <select name="to_id" class="col-span-2 px-3 py-2 border rounded-lg text-xs bg-white">
              <option value="">— Empfänger (für Partner) —</option>
              <?php foreach ($partners as $p): ?>
              <option value="<?= $p['emp_id'] ?>"><?= e($p['name'] . ' ' . $p['surname']) ?></option>
              <?php endforeach; ?>
            </select>
            <input name="notes" placeholder="Notiz (optional)" class="col-span-2 px-3 py-2 border rounded-lg text-xs"/>
            <button type="submit" class="col-span-2 px-3 py-2 bg-brand hover:bg-brand/90 text-white rounded-lg text-xs font-semibold">Übergabe protokollieren</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Recent history -->
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="px-5 py-3 border-b bg-gray-50">
      <h3 class="font-semibold text-gray-900">Letzte Bewegungen</h3>
    </div>
    <?php if (empty($recentHistory)): ?>
    <div class="p-8 text-center text-xs text-gray-400">Keine Einträge</div>
    <?php else: ?>
    <div class="divide-y max-h-[600px] overflow-y-auto">
      <?php foreach ($recentHistory as $h):
        $icon = match($h['action']) {
            'received' => '📥',
            'given' => '📤',
            'returned' => '↩️',
            'lost' => '❌',
            'found' => '✅',
            default => '•',
        };
      ?>
      <div class="px-4 py-3 text-xs">
        <div class="flex items-start gap-2">
          <span class="text-lg flex-shrink-0"><?= $icon ?></span>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-gray-900 truncate"><?= e($h['key_label']) ?></div>
            <div class="text-gray-500 mt-0.5"><?= e($h['from_name']) ?> → <?= e($h['to_name']) ?></div>
            <?php if ($h['notes']): ?>
            <div class="text-gray-400 italic mt-0.5">"<?= e($h['notes']) ?>"</div>
            <?php endif; ?>
            <div class="text-gray-400 mt-1"><?= date('d.m.Y H:i', strtotime($h['happened_at'])) ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
