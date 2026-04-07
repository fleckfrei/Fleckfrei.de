<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Jobs Kalender'; $page = 'jobs';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'edit_job') {
        $fields = ['j_date','j_time','j_hours','emp_id_fk','customer_id_fk','s_id_fk','address','code_door','no_people','job_for','platform','emp_message','job_status','optional_products','job_note'];
        $sets = []; $params = [];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) { $sets[] = "$f=?"; $params[] = $_POST[$f] ?: null; }
        }
        $params[] = $_POST['j_id'];
        q("UPDATE jobs SET " . implode(',', $sets) . " WHERE j_id=?", $params);
        header("Location: /admin/jobs.php?view=" . $_POST['j_id'] . "&saved=1"); exit;
    }
    if ($act === 'delete_job') {
        q("UPDATE jobs SET status=0 WHERE j_id=?", [$_POST['j_id']]);
        header("Location: /admin/jobs.php?deleted=1"); exit;
    }
}

$employees = all("SELECT emp_id, name, surname FROM employee WHERE status=1 ORDER BY name");
$customers = all("SELECT customer_id, name, customer_type FROM customer WHERE status=1 ORDER BY name");
$services = all("SELECT s_id, title, street, city, total_price FROM services WHERE status=1 ORDER BY title");

// Group customers by type for optgroup dropdowns
$grouped = [];
foreach ($customers as $c) $grouped[$c['customer_type']][] = $c;
$typeLabels = ['Airbnb'=>'🏠 Airbnb / Host','Company'=>'🏢 Firma / Partner','Private Person'=>'👤 Privat','Private'=>'👤 Privat','Host'=>'🏠 Host'];

// View single job?
$viewJob = null;
if (!empty($_GET['view'])) {
    $viewJob = one("SELECT j.*, c.name as cname, c.email as cemail, c.phone as cphone, c.customer_type as ctype,
        e.name as ename, e.surname as esurname, e.phone as ephone, e.tariff,
        s.title as stitle, s.street, s.city, s.total_price as sprice, s.box_code, s.client_code, s.deposit_code, s.wifi_name, s.wifi_password
        FROM jobs j LEFT JOIN customer c ON j.customer_id_fk=c.customer_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id LEFT JOIN services s ON j.s_id_fk=s.s_id
        WHERE j.j_id=?", [$_GET['view']]);
}

include __DIR__ . '/../includes/layout.php';
?>

<?php if ($viewJob): $j=$viewJob; ?>
<!-- Job Detail View -->
<div class="bg-white rounded-xl border mb-6">
  <div class="p-5 border-b flex items-center justify-between">
    <h3 class="font-semibold">Job #<?= $j['j_id'] ?> — <?= e($j['stitle']) ?></h3>
    <div class="flex gap-2 items-center">
      <?= badge($j['job_status']) ?>
      <button onclick="document.getElementById('editJobModal').classList.remove('hidden')" class="px-3 py-1.5 bg-brand text-white text-sm rounded-lg">Bearbeiten</button>
      <form method="POST" class="inline" onsubmit="return confirm('Job wirklich löschen?')"><input type="hidden" name="action" value="delete_job"/><input type="hidden" name="j_id" value="<?= $j['j_id'] ?>"/><button class="px-3 py-1.5 bg-red-100 text-red-700 text-sm rounded-lg">Löschen</button></form>
      <a href="/admin/jobs.php" class="text-sm text-gray-500 hover:text-gray-700">&larr; Zurück</a>
    </div>
  </div>
  <div class="p-5 grid grid-cols-1 md:grid-cols-3 gap-6">
    <div>
      <h4 class="text-xs font-semibold text-gray-400 uppercase mb-2">Job Details</h4>
      <dl class="space-y-2 text-sm">
        <div class="flex justify-between"><dt class="text-gray-500">Datum</dt><dd class="font-medium"><?= date('d.m.Y', strtotime($j['j_date'])) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Zeit</dt><dd class="font-medium"><?= substr($j['j_time'],0,5) ?> - <?= $j['stop_times'] ? substr($j['stop_times'],0,5) : '?' ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Stunden (geplant)</dt><dd class="font-medium"><?= $j['j_hours'] ?>h</dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Tatsächlich</dt><dd class="font-medium"><?= $j['total_hours'] ? round($j['total_hours'],1).'h' : '-' ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Adresse</dt><dd class="font-medium text-right"><?= e($j['address'] ?: $j['street'].' '.$j['city']) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Türcode</dt><dd class="font-mono"><?= e($j['code_door']) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Plattform</dt><dd><?= e($j['platform']) ?></dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">Wiederholung</dt><dd><?= e($j['job_for']) ?></dd></div>
        <?php if ($j['start_time']): ?><div class="flex justify-between"><dt class="text-gray-500">Gestartet</dt><dd class="text-green-600 font-mono"><?= substr($j['start_time'],0,5) ?></dd></div><?php endif; ?>
        <?php if ($j['end_time']): ?><div class="flex justify-between"><dt class="text-gray-500">Beendet</dt><dd class="text-blue-600 font-mono"><?= substr($j['end_time'],0,5) ?></dd></div><?php endif; ?>
      </dl>
    </div>
    <div>
      <h4 class="text-xs font-semibold text-gray-400 uppercase mb-2">Kunde</h4>
      <p class="font-medium"><?= e($j['cname']) ?> <span class="text-xs text-gray-400">(<?= e($j['ctype']) ?>)</span></p>
      <p class="text-sm text-gray-500"><?= e($j['cemail']) ?></p>
      <p class="text-sm text-gray-500"><?= e($j['cphone']) ?></p>
      <h4 class="text-xs font-semibold text-gray-400 uppercase mt-4 mb-2">Mitarbeiter</h4>
      <?php if ($j['emp_id_fk']): ?>
        <p class="font-medium"><?= e($j['ename'].' '.$j['esurname']) ?></p>
        <p class="text-sm text-gray-500"><?= e($j['ephone']) ?> — <?= money($j['tariff']) ?>/h</p>
      <?php else: ?>
        <p class="text-red-500 text-sm">Nicht zugewiesen</p>
      <?php endif; ?>
      <?php if ($j['wifi_name']): ?>
      <h4 class="text-xs font-semibold text-gray-400 uppercase mt-4 mb-2">WiFi</h4>
      <p class="text-sm"><?= e($j['wifi_name']) ?> / <?= e($j['wifi_password']) ?></p>
      <?php endif; ?>
    </div>
    <div>
      <h4 class="text-xs font-semibold text-gray-400 uppercase mb-2">Notizen & Extras</h4>
      <p class="text-sm bg-gray-50 p-3 rounded-lg"><?= e($j['job_note']) ?: '<span class="text-gray-400">Keine Notizen</span>' ?></p>
      <?php if ($j['optional_products']): ?>
      <h4 class="text-xs font-semibold text-gray-400 uppercase mt-4 mb-2">Extras</h4>
      <p class="text-sm"><?= e($j['optional_products']) ?></p>
      <?php endif; ?>
      <?php if ($j['emp_message']): ?>
      <h4 class="text-xs font-semibold text-gray-400 uppercase mt-4 mb-2">Nachricht an MA</h4>
      <p class="text-sm bg-yellow-50 p-3 rounded-lg"><?= e($j['emp_message']) ?></p>
      <?php endif; ?>
      <?php
        $allPhotos = [];
        if (!empty($j['job_file'])) foreach (json_decode($j['job_file'],true)?:[] as $f) $allPhotos[] = $f;
        if (!empty($j['job_photos'])) foreach (json_decode($j['job_photos'],true)?:[] as $f) $allPhotos[] = '/uploads/jobs/'.$j['j_id'].'/'.$f;
        if (!empty($allPhotos)):
      ?>
      <h4 class="text-xs font-semibold text-gray-400 uppercase mt-4 mb-2">Fotos (<?= count($allPhotos) ?>)</h4>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($allPhotos as $f): ?>
        <a href="<?= e($f) ?>" target="_blank" class="block w-16 h-16 bg-gray-100 rounded-lg overflow-hidden"><img src="<?= e($f) ?>" class="w-full h-full object-cover"/></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Edit Job Modal -->
<div id="editJobModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden overflow-y-auto py-8">
  <div class="bg-white rounded-2xl p-6 w-full max-w-2xl shadow-2xl m-4">
    <h3 class="text-lg font-semibold mb-4">Job #<?= $j['j_id'] ?> bearbeiten</h3>
    <form method="POST" class="grid grid-cols-2 gap-4">
      <input type="hidden" name="action" value="edit_job"/>
      <input type="hidden" name="j_id" value="<?= $j['j_id'] ?>"/>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Kunde</label>
        <select name="customer_id_fk" class="w-full px-3 py-2.5 border rounded-xl"><?php foreach ($grouped as $type => $custs): ?><optgroup label="<?= e($typeLabels[$type] ?? $type) ?>"><?php foreach ($custs as $c): ?><option value="<?= $c['customer_id'] ?>" <?= $c['customer_id']==$j['customer_id_fk']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Service</label>
        <select name="s_id_fk" class="w-full px-3 py-2.5 border rounded-xl"><?php foreach ($services as $sv): ?><option value="<?= $sv['s_id'] ?>" <?= $sv['s_id']==$j['s_id_fk']?'selected':'' ?>><?= e($sv['title']) ?></option><?php endforeach; ?></select></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Datum</label><input type="date" name="j_date" value="<?= e($j['j_date']) ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Uhrzeit</label><input type="time" name="j_time" value="<?= e(substr($j['j_time'],0,5)) ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Stunden</label><input type="number" name="j_hours" value="<?= $j['j_hours'] ?>" step="0.5" class="w-full px-3 py-2.5 border rounded-xl"/></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Mitarbeiter</label>
        <select name="emp_id_fk" class="w-full px-3 py-2.5 border rounded-xl"><option value="">Nicht zugewiesen</option><?php foreach ($employees as $emp): ?><option value="<?= $emp['emp_id'] ?>" <?= $emp['emp_id']==$j['emp_id_fk']?'selected':'' ?>><?= e($emp['name'].' '.$emp['surname']) ?></option><?php endforeach; ?></select></div>
      <div class="col-span-2"><label class="block text-sm font-medium text-gray-600 mb-1">Adresse</label><input type="text" name="address" value="<?= e($j['address']) ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Türcode</label><input type="text" name="code_door" value="<?= e($j['code_door']) ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Personen</label><input type="number" name="no_people" value="<?= $j['no_people'] ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Status</label>
        <select name="job_status" class="w-full px-3 py-2.5 border rounded-xl"><?php foreach (['PENDING','CONFIRMED','RUNNING','COMPLETED','CANCELLED'] as $st): ?><option value="<?= $st ?>" <?= $st===$j['job_status']?'selected':'' ?>><?= $st ?></option><?php endforeach; ?></select></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Plattform</label>
        <select name="platform" class="w-full px-3 py-2.5 border rounded-xl"><?php foreach (['admin','website','whatsapp','airbnb','botsailor'] as $p): ?><option value="<?= $p ?>" <?= $p===$j['platform']?'selected':'' ?>><?= ucfirst($p) ?></option><?php endforeach; ?></select></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Wiederholung</label>
        <select name="job_for" class="w-full px-3 py-2.5 border rounded-xl"><option value="">Einmalig</option><?php foreach (['daily'=>'Täglich','weekly'=>'Wöchentlich','weekly2'=>'Alle 2 Wo','weekly3'=>'Alle 3 Wo','weekly4'=>'Monatlich'] as $k=>$v): ?><option value="<?= $k ?>" <?= $k===$j['job_for']?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <div class="col-span-2"><label class="block text-sm font-medium text-gray-600 mb-1">Nachricht an Mitarbeiter</label><textarea name="emp_message" rows="2" class="w-full px-3 py-2.5 border rounded-xl"><?= e($j['emp_message']) ?></textarea></div>
      <div class="col-span-2"><label class="block text-sm font-medium text-gray-600 mb-1">Notizen</label><textarea name="job_note" rows="2" class="w-full px-3 py-2.5 border rounded-xl"><?= e($j['job_note']) ?></textarea></div>
      <div class="col-span-2 flex gap-3 mt-2">
        <button type="button" onclick="document.getElementById('editJobModal').classList.add('hidden')" class="flex-1 px-4 py-2.5 border rounded-xl text-gray-600">Abbrechen</button>
        <button type="submit" class="flex-1 px-4 py-2.5 bg-brand text-white rounded-xl font-medium">Speichern</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Calendar + Day Panel -->
<div class="grid grid-cols-1 xl:grid-cols-4 gap-6 mb-6">
  <!-- Calendar -->
  <div class="xl:col-span-3 bg-white rounded-xl border p-5">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div class="flex flex-wrap gap-2">
        <select id="empFilter" class="px-3 py-2 border rounded-lg text-sm">
          <option value="">Alle Mitarbeiter</option>
          <?php foreach ($employees as $emp): ?><option value="<?= $emp['emp_id'] ?>"><?= e($emp['name'].' '.$emp['surname']) ?></option><?php endforeach; ?>
        </select>
        <select id="statusFilter" class="px-3 py-2 border rounded-lg text-sm">
          <option value="">Alle Status</option>
          <option value="PENDING">Offen</option><option value="CONFIRMED">Bestätigt</option>
          <option value="RUNNING">Laufend</option><option value="COMPLETED">Erledigt</option><option value="CANCELLED">Storniert</option>
        </select>
        <select id="typeFilter" class="px-3 py-2 border rounded-lg text-sm">
          <option value="">Alle Kunden-Typen</option>
          <option value="Private Person">Privat</option><option value="Airbnb">Airbnb</option>
          <option value="Company">Firma</option><option value="Host">Host</option>
        </select>
      </div>
      <button onclick="document.getElementById('addJobModal').classList.remove('hidden')" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-medium hover:bg-brand-dark transition">+ Neuer Job</button>
    </div>
    <div id="calendar"></div>
  </div>

  <!-- Day Panel -->
  <div class="xl:col-span-1">
    <div class="bg-white rounded-xl border xl:sticky xl:top-20">
      <div class="p-4 border-b flex items-center justify-between">
        <div>
          <h3 class="font-semibold text-gray-900 text-sm" id="dayPanelTitle">Heute</h3>
          <p class="text-xs text-gray-400" id="dayPanelDate"><?= date('d.m.Y') ?></p>
        </div>
        <div class="flex gap-2 text-xs text-gray-400">
          <span id="dayPanelCount">0</span>
          <span>·</span>
          <span id="dayPanelHours">0h</span>
        </div>
      </div>
      <div id="dayPanelJobs" class="p-2 space-y-1.5 max-h-[420px] overflow-y-auto">
        <div class="text-center text-gray-400 text-xs py-4">Tag anklicken</div>
      </div>
    </div>
  </div>
</div>

<!-- Quick Edit Popup (click on event) -->
<div id="quickEditModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden overflow-y-auto py-4">
  <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl m-4">
    <div class="p-5 border-b flex items-center justify-between">
      <h3 class="font-semibold" id="qeTitle">Job</h3>
      <button onclick="closeQuickEdit()" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
    </div>
    <div class="p-5 space-y-3" id="qeBody">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Datum</label>
          <input type="date" id="qe_date" class="w-full px-3 py-2 border rounded-lg text-sm"/>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Zeit</label>
          <input type="time" id="qe_time" class="w-full px-3 py-2 border rounded-lg text-sm"/>
        </div>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Kunde (nur aktive)</label>
        <select id="qe_customer" class="w-full px-3 py-2 border rounded-lg text-sm">
          <?php foreach ($grouped as $type => $custs): ?>
          <optgroup label="<?= e($typeLabels[$type] ?? $type) ?>">
            <?php foreach ($custs as $c): ?>
            <option value="<?= $c['customer_id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Service</label>
        <select id="qe_service" class="w-full px-3 py-2 border rounded-lg text-sm">
          <?php foreach ($services as $sv): ?><option value="<?= $sv['s_id'] ?>"><?= e($sv['title']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Mitarbeiter</label>
          <select id="qe_employee" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="">— kein MA —</option>
            <?php foreach ($employees as $emp): ?><option value="<?= $emp['emp_id'] ?>"><?= e($emp['name'].' '.$emp['surname']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
          <select id="qe_status" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="PENDING">Offen</option><option value="CONFIRMED">Bestätigt</option>
            <option value="RUNNING">Laufend</option><option value="COMPLETED">Erledigt</option><option value="CANCELLED">Storniert</option>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Stunden</label>
        <input type="number" id="qe_hours" step="0.5" min="0.5" class="w-full px-3 py-2 border rounded-lg text-sm"/>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Adresse</label>
        <input type="text" id="qe_address" class="w-full px-3 py-2 border rounded-lg text-sm"/>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Türcode</label>
          <input type="text" id="qe_code" class="w-full px-3 py-2 border rounded-lg text-sm font-mono"/>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-500 mb-1">Plattform</label>
          <select id="qe_platform" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="admin">Admin</option><option value="website">Website</option>
            <option value="whatsapp">WhatsApp</option><option value="airbnb">Airbnb</option>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Notizen</label>
        <textarea id="qe_note" rows="2" class="w-full px-3 py-2 border rounded-lg text-sm"></textarea>
      </div>
      <!-- Start/Stop Info + Admin Controls -->
      <div id="qe_timing" class="bg-gray-50 rounded-lg p-3 hidden">
        <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Arbeitszeit</div>
        <div class="grid grid-cols-3 gap-2 text-sm">
          <div><span class="text-gray-400">Start:</span> <strong id="qe_start_time" class="font-mono">—</strong></div>
          <div><span class="text-gray-400">Ende:</span> <strong id="qe_end_time" class="font-mono">—</strong></div>
          <div><span class="text-gray-400">Dauer:</span> <strong id="qe_total_hours" class="text-brand">—</strong></div>
        </div>
        <div id="qe_location_info" class="text-xs text-gray-400 mt-1 hidden">GPS: <span id="qe_start_loc"></span></div>
      </div>
      <!-- Admin Start/Stop Buttons -->
      <div class="flex gap-2" id="qe_admin_controls">
        <button onclick="adminStartJob()" id="qeStartBtn" class="flex-1 px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hidden">▶ Manuell Starten</button>
        <button onclick="adminStopJob()" id="qeStopBtn" class="flex-1 px-3 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hidden">⏹ Manuell Stoppen</button>
        <button onclick="adminPauseJob()" id="qePauseBtn" class="flex-1 px-3 py-2 bg-amber-500 text-white rounded-lg text-sm font-medium hidden">⏸ Pausieren</button>
      </div>
    </div>
    <!-- Quick action buttons -->
    <div class="px-5 py-3 border-t bg-gray-50 flex gap-2 flex-wrap" id="qeActions">
      <button onclick="quickAction('CONFIRMED')" id="qeConfirmBtn" class="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-medium">Bestätigen</button>
      <button onclick="quickAction('CANCELLED')" class="px-3 py-1.5 bg-red-600 text-white rounded-lg text-xs font-medium">Stornieren</button>
      <button onclick="quickAction('PENDING')" class="px-3 py-1.5 bg-amber-500 text-white rounded-lg text-xs font-medium">Zurücksetzen</button>
      <button onclick="quickAction('COMPLETED')" class="px-3 py-1.5 bg-green-700 text-white rounded-lg text-xs font-medium">Erledigt</button>
    </div>
    <!-- Save / close -->
    <div class="p-5 border-t flex gap-2">
      <button onclick="saveQuickEdit()" class="flex-1 px-4 py-2.5 bg-brand text-white rounded-xl font-medium text-sm">Speichern</button>
      <a id="qeFullEdit" href="#" class="px-4 py-2.5 border rounded-xl text-gray-600 text-sm text-center">Voll-Edit</a>
      <button onclick="closeQuickEdit()" class="px-4 py-2.5 border rounded-xl text-gray-600 text-sm">Schliessen</button>
    </div>
  </div>
</div>

<!-- Add Job Modal with Booking Templates -->
<?php
// Customer data for JS templates
$custJson = json_encode(array_map(fn($c) => ['id'=>$c['customer_id'],'name'=>$c['name'],'type'=>$c['customer_type']], $customers));
?>
<div id="addJobModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden overflow-y-auto py-4">
  <div class="bg-white rounded-2xl p-5 w-full max-w-2xl shadow-2xl m-4">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold">Neuen Job erstellen</h3>
      <div id="templateBadge" class="px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-500 hidden"></div>
    </div>
    <form id="addJobForm" onsubmit="submitNewJob(event)" class="grid grid-cols-2 gap-3">
      <div class="col-span-2">
        <label class="block text-xs font-medium text-gray-500 mb-1">Kunde (nur aktive)</label>
        <select name="customer_id_fk" id="newJobCustomer" required class="w-full px-3 py-2 border rounded-xl text-sm" onchange="applyBookingTemplate(this.value)">
          <option value="">Wählen...</option>
          <?php foreach ($grouped as $type => $custs): ?>
          <optgroup label="<?= e($typeLabels[$type] ?? $type) ?>">
            <?php foreach ($custs as $c): ?>
            <option value="<?= $c['customer_id'] ?>" data-type="<?= e($c['customer_type']) ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-span-2">
        <label class="block text-xs font-medium text-gray-500 mb-1">Service / Objekt</label>
        <select name="s_id_fk" required class="w-full px-3 py-2 border rounded-xl text-sm">
          <option value="">Wählen...</option>
          <?php foreach ($services as $sv): ?><option value="<?= $sv['s_id'] ?>"><?= e($sv['title']) ?> — <?= e($sv['street'].' '.$sv['city']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Datum</label><input type="date" name="j_date" required class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Uhrzeit</label><input type="time" name="j_time" id="newJobTime" required value="09:00" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Stunden</label><input type="number" name="j_hours" id="newJobHours" required value="3" step="0.5" min="1" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Mitarbeiter</label>
        <select name="emp_id_fk" class="w-full px-3 py-2 border rounded-xl text-sm">
          <option value="">Später zuweisen</option>
          <?php foreach ($employees as $emp): ?><option value="<?= $emp['emp_id'] ?>"><?= e($emp['name'].' '.$emp['surname']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-span-2"><label class="block text-xs font-medium text-gray-500 mb-1">Adresse</label><input type="text" name="address" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Türcode</label><input type="text" name="code_door" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Personen</label><input type="number" name="no_people" value="1" min="1" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Wiederholung</label>
        <select name="job_for" id="newJobFor" class="w-full px-3 py-2 border rounded-xl text-sm" onchange="toggleRecurEnd(this.value)">
          <option value="">Einmalig</option><option value="daily">Täglich</option>
          <option value="weekly">Wöchentlich</option><option value="weekly2">Alle 2 Wochen</option>
          <option value="weekly3">Alle 3 Wochen</option><option value="weekly4">Monatlich</option>
        </select>
      </div>
      <div id="recurEndWrap" class="hidden">
        <label class="block text-xs font-medium text-gray-500 mb-1">Wiederholen bis</label>
        <input type="date" name="recur_end" id="newJobRecurEnd" value="<?= date('Y-12-31') ?>" class="w-full px-3 py-2 border rounded-xl text-sm"/>
        <div class="text-[10px] text-gray-400 mt-1" id="recurPreview"></div>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Plattform</label>
        <select name="platform" id="newJobPlatform" class="w-full px-3 py-2 border rounded-xl text-sm">
          <option value="admin">Admin</option><option value="website">Website</option>
          <option value="whatsapp">WhatsApp</option><option value="airbnb">Airbnb</option>
        </select>
      </div>
      <div class="col-span-2"><label class="block text-xs font-medium text-gray-500 mb-1">Nachricht an Mitarbeiter</label><textarea name="emp_message" rows="2" class="w-full px-3 py-2 border rounded-xl text-sm"></textarea></div>
      <div class="col-span-2 flex gap-3 mt-1">
        <button type="button" onclick="document.getElementById('addJobModal').classList.add('hidden')" class="flex-1 px-4 py-2.5 border rounded-xl text-gray-600 text-sm">Abbrechen</button>
        <button type="submit" id="addJobBtn" class="flex-1 px-4 py-2.5 bg-brand text-white rounded-xl font-medium text-sm">Job erstellen</button>
      </div>
    </form>
  </div>
</div>

<?php
$apiKey = API_KEY;
$script = <<<JS
const API = '/api/index.php';
const KEY = '{$apiKey}';
// Softer, professional colors
const colors = {PENDING:'#d97706',CONFIRMED:'#2563EB',RUNNING:'#7c3aed',STARTED:'#7c3aed',COMPLETED:'#2E7D6B',CANCELLED:'#dc2626'};
const statusLabels = {PENDING:'Offen',CONFIRMED:'Bestätigt',RUNNING:'Laufend',STARTED:'Laufend',COMPLETED:'Erledigt',CANCELLED:'Storniert'};
let allEvents = [];
let currentEditId = null;
let currentJobFor = '';

// Calendar
const cal = new FullCalendar.Calendar(document.getElementById('calendar'), {
    initialView: window.innerWidth < 640 ? 'listWeek' : 'dayGridMonth',
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: window.innerWidth < 640 ? 'listWeek,dayGridMonth' : 'dayGridMonth,timeGridWeek,listWeek'
    },
    contentHeight: window.innerWidth < 640 ? 'auto' : 700,
    firstDay: 1, locale: 'de',
    eventDisplay: 'block',
    dayMaxEvents: 5,
    moreLinkText: 'weitere',
    editable: true,
    windowResize: function(arg) {
        if (window.innerWidth < 768) {
            cal.changeView('listWeek');
            cal.setOption('contentHeight', 'auto');
        } else {
            cal.setOption('contentHeight', 520);
        }
    },
    eventDrop: function(info) {
        // Drag & drop reschedule
        const newDate = info.event.start.toISOString().split('T')[0];
        fetch(API + '?action=jobs/update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-API-Key': KEY },
            body: JSON.stringify({ j_id: parseInt(info.event.id), field: 'j_date', value: newDate })
        }).then(r => r.json()).then(d => {
            if (!d.success) { info.revert(); alert(d.error || 'Fehler'); }
        }).catch(() => { info.revert(); });
    },
    events: function(info, ok, fail) {
        const p = new URLSearchParams({ action:'jobs', start:info.startStr.split('T')[0], end:info.endStr.split('T')[0] });
        const ef = document.getElementById('empFilter').value;
        const sf = document.getElementById('statusFilter').value;
        const tf = document.getElementById('typeFilter').value;
        if (ef) p.set('emp_id', ef);
        if (sf) p.set('status', sf);
        fetch(API + '?' + p, { headers:{'X-API-Key':KEY} })
            .then(r=>r.json()).then(d => {
                if (!d.success) return fail();
                let data = d.data;
                if (tf) data = data.filter(j => j.customer_type === tf);
                allEvents = data;
                ok(data.map(j => ({
                    id: j.j_id,
                    title: j.customer_name || 'Unbekannt',
                    start: j.j_date+'T'+j.j_time,
                    end: j.j_date+'T'+ new Date(new Date('2000-01-01T'+j.j_time).getTime()+(j.j_hours||2)*3600000).toTimeString().slice(0,8),
                    color: colors[j.job_status] || '#999',
                    extendedProps: j
                })));
            }).catch(fail);
    },
    eventContent: function(arg) {
        const j = arg.event.extendedProps;
        const time = arg.event.start ? arg.event.start.toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'}) : '';
        const cname = j.customer_name || '?';
        const svc = j.service_title || '';
        const emp = j.emp_name ? j.emp_name.charAt(0)+'.' : '';
        // Customer type icon
        const typeIcon = {'Airbnb':'🏠','Host':'🏠','Company':'🏢','Private Person':'👤'}[j.customer_type] || '';
        const el = document.createElement('div');
        el.style.cssText = 'padding:2px 5px;line-height:1.3;overflow:hidden;cursor:pointer;font-size:11.5px;';
        el.innerHTML = '<span style="opacity:0.65;">' + time + '</span> ' +
            '<b>' + cname.substring(0,20) + '</b>' +
            (emp ? ' <span style="opacity:0.5;">' + emp + '</span>' : '');
        return { domNodes: [el] };
    },
    eventClick: function(info) {
        info.jsEvent.preventDefault();
        openQuickEdit(info.event.extendedProps);
    },
    dateClick: function(info) {
        loadDayPanel(info.dateStr);
        document.querySelector('#addJobModal [name="j_date"]').value = info.dateStr;
    }
});
cal.render();

// Load today's jobs in day panel on init
loadDayPanel(new Date().toISOString().split('T')[0]);

document.getElementById('empFilter').onchange = () => cal.refetchEvents();
document.getElementById('statusFilter').onchange = () => cal.refetchEvents();
document.getElementById('typeFilter').onchange = () => cal.refetchEvents();

// Auto-refresh every 30s for live status updates (partner start/stop)
setInterval(() => { cal.refetchEvents(); }, 30000);

// Day Panel — shows all jobs for clicked date
function loadDayPanel(dateStr) {
    const panel = document.getElementById('dayPanelJobs');
    const dateObj = new Date(dateStr + 'T12:00:00');
    const dayNames = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
    document.getElementById('dayPanelTitle').textContent = dayNames[dateObj.getDay()];
    document.getElementById('dayPanelDate').textContent = dateObj.toLocaleDateString('de-DE');

    // Fetch jobs for this specific day
    fetch(API + '?action=jobs&start=' + dateStr + '&end=' + dateStr, { headers:{'X-API-Key':KEY} })
        .then(r=>r.json()).then(d => {
            if (!d.success || !d.data.length) {
                panel.innerHTML = '<div class="text-center text-gray-400 text-sm py-6">Keine Jobs</div>';
                document.getElementById('dayPanelCount').textContent = '0 Jobs';
                document.getElementById('dayPanelHours').textContent = '0h';
                return;
            }
            const jobs = d.data.sort((a,b) => a.j_time.localeCompare(b.j_time));
            let totalH = 0;
            panel.innerHTML = jobs.map(j => {
                totalH += parseFloat(j.j_hours) || 0;
                const col = colors[j.job_status] || '#999';
                const emp = j.emp_name ? j.emp_name.charAt(0) + '.' : '⚠';
                const jStr = JSON.stringify(j).replace(/'/g,'\\x27').replace(/"/g,'&quot;');
                return '<div class="px-2.5 py-2 rounded-lg border border-gray-100 hover:border-brand/30 cursor-pointer transition" onclick="openQuickEdit(' + jStr + ')">' +
                    '<div class="flex items-center gap-2">' +
                        '<div class="w-1 h-8 rounded-full flex-shrink-0" style="background:' + col + '"></div>' +
                        '<div class="flex-1 min-w-0">' +
                            '<div class="flex items-center justify-between">' +
                                '<span class="text-[11px] font-semibold text-gray-900 truncate">' + (j.customer_name||'?') + '</span>' +
                                '<span class="text-[10px] font-mono text-gray-400 flex-shrink-0 ml-1">' + j.j_time.slice(0,5) + '</span>' +
                            '</div>' +
                            '<div class="text-[10px] text-gray-400 truncate">' + (j.service_title||'') + ' · ' + emp + ' · ' + j.j_hours + 'h</div>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            }).join('');
            document.getElementById('dayPanelCount').textContent = jobs.length + ' Jobs';
            document.getElementById('dayPanelHours').textContent = totalH + 'h';
        });
}

// Quick Edit Popup
function openQuickEdit(j) {
    currentEditId = j.j_id;
    document.getElementById('qeTitle').textContent = 'Job #' + j.j_id + ' — ' + (j.customer_name||'');
    document.getElementById('qe_date').value = j.j_date || '';
    document.getElementById('qe_time').value = j.j_time ? j.j_time.slice(0,5) : '';
    // Set dropdowns — use String() to match option values
    setSelect('qe_customer', String(j.customer_id_fk || ''));
    setSelect('qe_service', String(j.s_id_fk || ''));
    setSelect('qe_employee', String(j.emp_id_fk || ''));
    setSelect('qe_status', j.job_status || 'PENDING');
    document.getElementById('qe_hours').value = j.j_hours || '';
    document.getElementById('qe_address').value = j.address || '';
    document.getElementById('qe_code').value = j.code_door || '';
    setSelect('qe_platform', j.platform || 'admin');
    document.getElementById('qe_note').value = j.job_note || '';
    document.getElementById('qeFullEdit').href = '/admin/jobs.php?view=' + j.j_id;
    currentJobFor = j.job_for || '';

    // Show timing info
    const timingDiv = document.getElementById('qe_timing');
    const startTime = j.start_time ? j.start_time.slice(0,5) : null;
    const endTime = j.end_time ? j.end_time.slice(0,5) : null;
    const totalH = j.total_hours ? parseFloat(j.total_hours).toFixed(1) + 'h' : null;

    if (startTime || endTime || totalH) {
        timingDiv.classList.remove('hidden');
        document.getElementById('qe_start_time').textContent = startTime || '—';
        document.getElementById('qe_end_time').textContent = endTime || '—';
        document.getElementById('qe_total_hours').textContent = totalH || '—';
        if (j.start_location) {
            document.getElementById('qe_location_info').classList.remove('hidden');
            document.getElementById('qe_start_loc').textContent = j.start_location;
        } else {
            document.getElementById('qe_location_info').classList.add('hidden');
        }
    } else {
        timingDiv.classList.add('hidden');
    }

    // Show/hide admin start/stop buttons based on status
    const startBtn = document.getElementById('qeStartBtn');
    const stopBtn = document.getElementById('qeStopBtn');
    const pauseBtn = document.getElementById('qePauseBtn');
    startBtn.classList.add('hidden');
    stopBtn.classList.add('hidden');
    pauseBtn.classList.add('hidden');

    if (j.job_status === 'PENDING' || j.job_status === 'CONFIRMED') {
        startBtn.classList.remove('hidden');
    } else if (j.job_status === 'RUNNING' || j.job_status === 'STARTED') {
        stopBtn.classList.remove('hidden');
        pauseBtn.classList.remove('hidden');
    }

    document.getElementById('quickEditModal').classList.remove('hidden');
}
// Helper to set select value reliably (handles optgroups + type mismatch)
function setSelect(id, val) {
    const sel = document.getElementById(id);
    if (!sel) return;
    // Try direct
    sel.value = val;
    if (sel.value === val) return;
    // Search through all options
    for (let i = 0; i < sel.options.length; i++) {
        if (String(sel.options[i].value) === val) { sel.selectedIndex = i; return; }
    }
    sel.value = ''; // fallback
}
function closeQuickEdit() { document.getElementById('quickEditModal').classList.add('hidden'); currentEditId = null; }

// Quick status change (Bestätigen, Stornieren, Zurücksetzen, Erledigt)
function quickAction(newStatus) {
    if (!currentEditId) return;
    if (newStatus === 'CANCELLED') {
        // Check if recurring — offer options
        const jobFor = document.getElementById('qe_status').closest('.space-y-3')?.dataset?.jobFor || '';
        if (currentJobFor) {
            const choice = prompt('Stornieren: 1=Nur diesen, 2=Alle zukünftigen', '1');
            if (!choice) return;
            const mode = choice === '2' ? 'future' : 'single';
            fetch(API + '?action=jobs/cancel-recurring', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-API-Key': KEY },
                body: JSON.stringify({ j_id: currentEditId, mode: mode })
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    alert(d.data.cancelled + ' Job(s) storniert');
                    closeQuickEdit(); cal.refetchEvents();
                } else alert(d.error || 'Fehler');
            });
            return;
        }
        if (!confirm('Job wirklich stornieren?')) return;
    }
    fetch(API + '?action=jobs/status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-API-Key': KEY },
        body: JSON.stringify({ j_id: currentEditId, status: newStatus })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            setSelect('qe_status', newStatus);
            closeQuickEdit();
            cal.refetchEvents();
        } else alert(d.error || 'Fehler');
    });
}

// Admin manual Start/Stop/Pause
function adminStartJob() {
    if (!currentEditId) return;
    fetch(API + '?action=jobs/status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-API-Key': KEY },
        body: JSON.stringify({ j_id: currentEditId, status: 'RUNNING' })
    }).then(r => r.json()).then(d => {
        if (d.success) { closeQuickEdit(); cal.refetchEvents(); }
        else alert(d.error || 'Fehler');
    });
}
function adminStopJob() {
    if (!currentEditId) return;
    const note = prompt('Notiz zum Job (optional):');
    fetch(API + '?action=jobs/status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-API-Key': KEY },
        body: JSON.stringify({ j_id: currentEditId, status: 'COMPLETED' })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            if (note) {
                fetch(API + '?action=jobs/update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-API-Key': KEY },
                    body: JSON.stringify({ j_id: currentEditId, field: 'job_note', value: note })
                });
            }
            closeQuickEdit(); cal.refetchEvents();
        } else alert(d.error || 'Fehler');
    });
}
function adminPauseJob() {
    if (!currentEditId) return;
    // Pause = set back to CONFIRMED (keeps start_time for reference)
    fetch(API + '?action=jobs/status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-API-Key': KEY },
        body: JSON.stringify({ j_id: currentEditId, status: 'CONFIRMED', old_status: 'RUNNING' })
    }).then(r => r.json()).then(d => {
        if (d.success) { closeQuickEdit(); cal.refetchEvents(); }
        else alert(d.error || 'Fehler');
    });
}

function saveQuickEdit() {
    if (!currentEditId) return;
    const fields = {
        j_date: document.getElementById('qe_date').value,
        j_time: document.getElementById('qe_time').value,
        customer_id_fk: document.getElementById('qe_customer').value,
        s_id_fk: document.getElementById('qe_service').value,
        j_hours: document.getElementById('qe_hours').value,
        job_status: document.getElementById('qe_status').value,
        address: document.getElementById('qe_address').value,
        code_door: document.getElementById('qe_code').value,
        platform: document.getElementById('qe_platform').value,
        job_note: document.getElementById('qe_note').value
    };
    const empId = document.getElementById('qe_employee').value;

    // Save all fields + employee
    const promises = Object.entries(fields).map(([field, value]) =>
        fetch(API + '?action=jobs/update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-API-Key': KEY },
            body: JSON.stringify({ j_id: currentEditId, field, value })
        }).then(r => r.json())
    );
    // Also update employee
    promises.push(
        fetch(API + '?action=jobs/assign', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-API-Key': KEY },
            body: JSON.stringify({ j_id: currentEditId, emp_id_fk: empId ? parseInt(empId) : null })
        }).then(r => r.json())
    );

    Promise.all(promises).then(results => {
        const failed = results.find(r => !r.success);
        if (failed) { alert(failed.error || 'Fehler beim Speichern'); }
        closeQuickEdit();
        cal.refetchEvents();
        // Reload day panel for current date
        const d = document.getElementById('qe_date').value;
        if (d) loadDayPanel(d);
    });
}

// Booking Templates by customer type
// WA Partner (Company) = 09:00, 4h, weekly, whatsapp
// WA Host (Airbnb/Host) = 11:00, 3h, einmalig, airbnb
// WA Privat (Private Person) = 09:00, 3h, einmalig, whatsapp
function applyBookingTemplate(custId) {
    const sel = document.getElementById('newJobCustomer');
    const opt = sel.options[sel.selectedIndex];
    if (!opt) return;
    const type = opt.getAttribute('data-type') || '';
    const badge = document.getElementById('templateBadge');

    if (type === 'Company') {
        // WA Partner template
        document.getElementById('newJobTime').value = '09:00';
        document.getElementById('newJobHours').value = '4';
        document.getElementById('newJobFor').value = 'weekly';
        document.getElementById('newJobPlatform').value = 'whatsapp';
        badge.textContent = '📋 WA Partner';
        badge.className = 'px-3 py-1 rounded-full text-xs font-medium bg-blue-50 text-blue-700';
    } else if (type === 'Airbnb' || type === 'Host') {
        // WA Host template
        document.getElementById('newJobTime').value = '11:00';
        document.getElementById('newJobHours').value = '3';
        document.getElementById('newJobFor').value = '';
        document.getElementById('newJobPlatform').value = 'airbnb';
        badge.textContent = '🏠 WA Host';
        badge.className = 'px-3 py-1 rounded-full text-xs font-medium bg-amber-50 text-amber-700';
    } else {
        // WA Privat template
        document.getElementById('newJobTime').value = '09:00';
        document.getElementById('newJobHours').value = '3';
        document.getElementById('newJobFor').value = '';
        document.getElementById('newJobPlatform').value = 'whatsapp';
        badge.textContent = '👤 WA Privat';
        badge.className = 'px-3 py-1 rounded-full text-xs font-medium bg-green-50 text-green-700';
    }
    badge.classList.remove('hidden');
}

// Toggle "Wiederholen bis" field + preview
function toggleRecurEnd(val) {
    const wrap = document.getElementById('recurEndWrap');
    if (val) { wrap.classList.remove('hidden'); updateRecurPreview(); }
    else wrap.classList.add('hidden');
}
function updateRecurPreview() {
    const jobFor = document.getElementById('newJobFor').value;
    const startDate = document.querySelector('#addJobForm [name="j_date"]').value;
    const endDate = document.getElementById('newJobRecurEnd').value;
    if (!jobFor || !startDate || !endDate) return;
    const days = {daily:1,weekly:7,weekly2:14,weekly3:21,weekly4:28}[jobFor] || 0;
    if (!days) return;
    let count = 1, cur = new Date(startDate);
    const end = new Date(endDate);
    while (true) { cur.setDate(cur.getDate() + days); if (cur > end) break; count++; }
    const labels = {daily:'Tag',weekly:'Woche',weekly2:'2 Wochen',weekly3:'3 Wochen',weekly4:'Monat'};
    document.getElementById('recurPreview').textContent = '→ ' + count + ' Jobs werden erstellt (jeden ' + (labels[jobFor]||'') + ' bis ' + endDate + ')';
}
// Update preview when dates change
document.addEventListener('DOMContentLoaded', () => {
    const dateInput = document.querySelector('#addJobForm [name="j_date"]');
    if (dateInput) dateInput.addEventListener('change', updateRecurPreview);
    const endInput = document.getElementById('newJobRecurEnd');
    if (endInput) endInput.addEventListener('change', updateRecurPreview);
});

// Add job
function submitNewJob(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = Object.fromEntries(fd);
    data.customer_id_fk = parseInt(data.customer_id_fk);
    data.s_id_fk = parseInt(data.s_id_fk);
    if (data.emp_id_fk) data.emp_id_fk = parseInt(data.emp_id_fk);
    else delete data.emp_id_fk;
    // Pass recur_end date for recurring jobs
    if (data.recur_end) data.recur_end = data.recur_end;
    else delete data.recur_end;
    document.getElementById('addJobBtn').disabled = true;
    document.getElementById('addJobBtn').textContent = 'Wird erstellt...';
    fetch(API + '?action=jobs', {
        method: 'POST',
        headers: { 'Content-Type':'application/json', 'X-API-Key':KEY },
        body: JSON.stringify(data)
    }).then(r=>r.json()).then(d => {
        if (d.success) {
            document.getElementById('addJobModal').classList.add('hidden');
            cal.refetchEvents();
            const r = d.data;
            if (r.recurring && r.total_created > 1) {
                alert(r.total_created + ' Jobs erstellt (wiederkehrend bis ' + r.dates_until + ')');
            }
            e.target.reset();
            if (data.j_date) loadDayPanel(data.j_date);
        }
        else alert(d.error || 'Fehler');
        document.getElementById('addJobBtn').disabled = false;
        document.getElementById('addJobBtn').textContent = 'Job erstellen';
    });
}
JS;
include __DIR__ . '/../includes/footer.php';
?>
