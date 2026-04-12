<?php
/**
 * Admin: Notification Permissions — pro Kunde und Partner granular konfigurieren
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Benachrichtigungen'; $page = 'notifications';

// Event-Definitionen
$EVENTS = [
    'job_created'      => ['Neue Buchung',          'Kunde oder Admin erstellt einen neuen Job'],
    'job_rescheduled'  => ['Umbuchung',             'Termin wird auf neues Datum/Uhrzeit verschoben'],
    'job_cancelled'    => ['Stornierung',           'Job wird storniert'],
    'job_edited'       => ['Job bearbeitet',        'Details des Jobs werden geändert (Zeit, Adresse, Notizen)'],
    'job_confirmed'    => ['Job bestätigt',         'Status PENDING → CONFIRMED'],
    'job_assigned'     => ['Partner zugewiesen',    'Job wird einem Partner zugeordnet'],
    'job_started'      => ['Job gestartet',         'Partner hat Job gestartet (Status RUNNING)'],
    'job_completed'    => ['Job erledigt',          'Status COMPLETED'],
    'note_added'       => ['Notiz hinzugefügt',     'Jemand fügt eine Notiz zum Job hinzu'],
    'photo_uploaded'   => ['Foto hochgeladen',      'Foto (Vorher/Nachher) wurde hochgeladen'],
    'payment_made'     => ['Zahlung eingegangen',   'Rechnungszahlung wurde verbucht'],
    'payment_reminder' => ['Zahlungserinnerung',    'Automatische Erinnerung an offene Rechnung'],
    'customer_edited'  => ['Kundendaten geändert',  'Kundenprofil wurde bearbeitet'],
    'partner_absent'   => ['Partner Urlaub/Krank',  'Partner hat Abwesenheit eingetragen'],
    'rating_submitted' => ['Bewertung abgegeben',   'Kunde hat Partner bewertet']
];

// POST: Änderungen speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_prefs') {
        $userType = $_POST['user_type'] ?? '';
        $userId = (int)($_POST['user_id'] ?? 0);
        if (in_array($userType, ['customer','employee','admin']) && $userId >= 0) {
            // Delete existing prefs for this user+type
            q("DELETE FROM user_notification_prefs WHERE user_type=? AND user_id=?", [$userType, $userId]);
            // Insert from POST
            foreach ($_POST['events'] ?? [] as $event => $cfg) {
                $enabled = !empty($cfg['enabled']) ? 1 : 0;
                $channel = in_array($cfg['channel'] ?? '', ['telegram','email','push','in_app']) ? $cfg['channel'] : 'telegram';
                q("INSERT INTO user_notification_prefs (user_type, user_id, event_type, channel, enabled) VALUES (?,?,?,?,?)",
                  [$userType, $userId, $event, $channel, $enabled]);
            }
            audit('update', 'notification_prefs', $userId, "Prefs für {$userType}#{$userId} gespeichert");
            header("Location: /admin/notifications.php?user_type={$userType}&user_id={$userId}&saved=1"); exit;
        }
    }
}

// Welchen User zeigen?
$selectedType = $_GET['user_type'] ?? 'admin';
$selectedId = (int)($_GET['user_id'] ?? 0);

// User-Liste pro Typ
$users = [];
if ($selectedType === 'customer') {
    $users = all("SELECT customer_id AS id, name, email FROM customer WHERE status=1 ORDER BY name");
} elseif ($selectedType === 'employee') {
    $users = all("SELECT emp_id AS id, CONCAT(name, ' ', COALESCE(surname,'')) AS name, email FROM employee WHERE status=1 ORDER BY name");
} else {
    $users = [['id'=>0, 'name'=>'Globaler Admin-Default', 'email'=>'']];
}

// Existierende Prefs für selected user
$currentPrefs = [];
try {
    $rows = all("SELECT event_type, channel, enabled FROM user_notification_prefs WHERE user_type=? AND user_id=?", [$selectedType, $selectedId]);
    foreach ($rows as $r) $currentPrefs[$r['event_type']] = $r;
} catch (Exception $e) {}

// Recent activity_log
$recentActivity = [];
try {
    $recentActivity = all("SELECT * FROM activity_log ORDER BY created_at DESC LIMIT 20");
} catch (Exception $e) {}

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">✓ Einstellungen gespeichert</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
  <!-- LEFT: User-Typ + User-Liste -->
  <div class="bg-white rounded-xl border p-4">
    <h3 class="font-bold text-gray-900 mb-3">Benutzer wählen</h3>
    <div class="flex gap-1 mb-3">
      <?php foreach (['admin'=>'Admin','customer'=>'Kunden','employee'=>'Partner'] as $t => $lbl): ?>
      <a href="?user_type=<?= $t ?>&user_id=<?= $t==='admin'?0:($users[0]['id']??0) ?>"
         class="flex-1 px-2 py-1.5 text-xs font-semibold rounded-lg text-center <?= $selectedType === $t ? 'bg-brand text-white' : 'bg-gray-100 text-gray-700' ?>">
        <?= $lbl ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php if ($selectedType !== 'admin'): ?>
    <input type="search" placeholder="Suchen..." oninput="filterUsers(this.value)" class="w-full px-3 py-2 border rounded-lg text-sm mb-2"/>
    <?php endif; ?>
    <div class="space-y-1 max-h-[500px] overflow-y-auto" id="userList">
      <?php foreach ($users as $u): ?>
      <a href="?user_type=<?= $selectedType ?>&user_id=<?= $u['id'] ?>" data-name="<?= strtolower($u['name']) ?>"
         class="block px-3 py-2 rounded-lg text-sm <?= $selectedId == $u['id'] ? 'bg-brand-light text-brand-dark font-semibold' : 'hover:bg-gray-50 text-gray-700' ?>">
        <?= e($u['name']) ?>
        <?php if ($u['email']): ?><div class="text-[10px] text-gray-500"><?= e($u['email']) ?></div><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- CENTER: Permissions für gewählten User -->
  <div class="lg:col-span-2 bg-white rounded-xl border p-5">
    <h3 class="font-bold text-gray-900 mb-1">Benachrichtigungs-Matrix</h3>
    <p class="text-xs text-gray-600 mb-4">
      <?= $selectedType === 'admin' ? 'Globale Admin-Benachrichtigungen (Telegram-Bot)'
          : 'Was soll ' . e($selectedType === 'customer' ? 'dieser Kunde' : 'dieser Partner') . ' benachrichtigt bekommen?' ?>
    </p>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_prefs"/>
      <input type="hidden" name="user_type" value="<?= e($selectedType) ?>"/>
      <input type="hidden" name="user_id" value="<?= $selectedId ?>"/>
      <div class="space-y-2">
        <?php foreach ($EVENTS as $key => [$label, $desc]):
          $pref = $currentPrefs[$key] ?? ['enabled'=>0, 'channel'=>'telegram'];
        ?>
        <div class="flex items-center gap-3 p-3 rounded-lg border border-gray-200 hover:border-brand transition">
          <label class="flex items-center cursor-pointer">
            <input type="checkbox" name="events[<?= $key ?>][enabled]" value="1" <?= $pref['enabled'] ? 'checked' : '' ?>
                   class="w-5 h-5 rounded text-brand focus:ring-brand"/>
          </label>
          <div class="flex-1 min-w-0">
            <div class="font-semibold text-sm text-gray-900"><?= e($label) ?></div>
            <div class="text-xs text-gray-600"><?= e($desc) ?></div>
          </div>
          <select name="events[<?= $key ?>][channel]" class="px-2 py-1.5 border rounded-lg text-xs">
            <?php foreach (['telegram'=>'📱 Telegram','email'=>'📧 E-Mail','push'=>'🔔 Push','in_app'=>'💬 In-App'] as $c => $lbl): ?>
            <option value="<?= $c ?>" <?= $pref['channel'] === $c ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="flex gap-3 mt-5">
        <button type="submit" class="flex-1 px-4 py-2.5 bg-brand text-white rounded-xl font-semibold">Speichern</button>
        <button type="button" onclick="toggleAll(true)" class="px-4 py-2.5 border rounded-xl text-gray-700 text-sm">Alle an</button>
        <button type="button" onclick="toggleAll(false)" class="px-4 py-2.5 border rounded-xl text-gray-700 text-sm">Alle aus</button>
      </div>
    </form>
  </div>

  <!-- RIGHT: Activity Feed (letzte Aktionen) -->
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-bold text-gray-900 mb-3">Letzte Aktivitäten</h3>
    <div class="space-y-3 max-h-[600px] overflow-y-auto">
      <?php if (empty($recentActivity)): ?>
      <p class="text-sm text-gray-500">Noch keine Aktivität.</p>
      <?php else: foreach ($recentActivity as $act):
        $details = json_decode($act['details'] ?? '', true) ?: [];
      ?>
      <div class="border-l-2 border-brand pl-3 py-1">
        <div class="text-xs font-semibold text-gray-900"><?= e($act['event_type']) ?></div>
        <div class="text-[11px] text-gray-600 mt-0.5">
          <?= e($act['actor_type']) ?> #<?= $act['actor_id'] ?> →
          <?= e($act['target_type']) ?><?= $act['target_id'] ? ' #' . $act['target_id'] : '' ?>
        </div>
        <div class="text-[10px] text-gray-400 mt-0.5"><?= date('d.m. H:i', strtotime($act['created_at'])) ?></div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<script>
function filterUsers(q) {
  const items = document.querySelectorAll('#userList a[data-name]');
  q = q.toLowerCase();
  items.forEach(i => i.style.display = i.dataset.name.includes(q) ? 'block' : 'none');
}
function toggleAll(on) {
  document.querySelectorAll('input[type=checkbox][name^="events"]').forEach(c => c.checked = on);
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
