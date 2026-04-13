<?php
/**
 * Admin — Checklists Overview
 * Shows all customer-defined checklists per service, with completion stats.
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Checklisten'; $page = 'checklists';

// ============================================================
// POST handlers — Admin can add/edit/delete checklist items
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $act = $_POST['action'] ?? '';
    $sid = (int)($_POST['s_id_fk'] ?? 0);

    if ($act === 'add_item' && $sid) {
        $cid = (int) val("SELECT customer_id_fk FROM services WHERE s_id=?", [$sid]);
        $pos = (int) val("SELECT COALESCE(MAX(position),0)+1 FROM service_checklists WHERE s_id_fk=?", [$sid]);
        q("INSERT INTO service_checklists (s_id_fk, customer_id_fk, title, description, room, priority, position, is_active)
           VALUES (?,?,?,?,?,?,?,1)",
          [$sid, $cid, trim($_POST['title'] ?? ''), trim($_POST['description'] ?? ''),
           trim($_POST['room'] ?? ''), $_POST['priority'] ?? 'normal', $pos]);
        audit('create', 'service_checklists', (int)lastInsertId(), 'Admin: Aufgabe hinzugefügt');
        header("Location: /admin/checklists.php?s=$sid&saved=1#detail"); exit;
    }

    if ($act === 'edit_item') {
        $itemId = (int)($_POST['checklist_id'] ?? 0);
        $row = one("SELECT s_id_fk FROM service_checklists WHERE checklist_id=?", [$itemId]);
        if ($row) {
            q("UPDATE service_checklists SET title=?, description=?, room=?, priority=? WHERE checklist_id=?",
              [trim($_POST['title'] ?? ''), trim($_POST['description'] ?? ''),
               trim($_POST['room'] ?? ''), $_POST['priority'] ?? 'normal', $itemId]);
            audit('update', 'service_checklists', $itemId, 'Admin: Aufgabe bearbeitet');
            header("Location: /admin/checklists.php?s={$row['s_id_fk']}&saved=1#detail"); exit;
        }
    }

    if ($act === 'delete_item') {
        $itemId = (int)($_POST['checklist_id'] ?? 0);
        $row = one("SELECT s_id_fk FROM service_checklists WHERE checklist_id=?", [$itemId]);
        if ($row) {
            q("UPDATE service_checklists SET is_active=0 WHERE checklist_id=?", [$itemId]);
            audit('delete', 'service_checklists', $itemId, 'Admin: Aufgabe entfernt');
            header("Location: /admin/checklists.php?s={$row['s_id_fk']}&saved=1#detail"); exit;
        }
    }
}

$search = trim($_GET['q'] ?? '');
$whereExtra = '';
$params = [];
if ($search !== '') {
    $whereExtra = "AND (c.name LIKE ? OR s.title LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$rows = all("
    SELECT s.s_id, s.title AS service_title, s.street, s.city,
           c.customer_id, c.name AS customer_name, c.customer_type,
           COUNT(sc.checklist_id) AS total_items,
           SUM(CASE WHEN sc.priority = 'critical' THEN 1 ELSE 0 END) AS critical_items,
           MAX(sc.updated_at) AS last_updated
    FROM service_checklists sc
    LEFT JOIN services s ON sc.s_id_fk = s.s_id
    LEFT JOIN customer c ON sc.customer_id_fk = c.customer_id
    WHERE sc.is_active = 1 $whereExtra
    GROUP BY s.s_id, c.customer_id
    ORDER BY last_updated DESC
", $params);

// Total stats
$totalItems = (int) val("SELECT COUNT(*) FROM service_checklists WHERE is_active=1");
$totalServices = (int) val("SELECT COUNT(DISTINCT s_id_fk) FROM service_checklists WHERE is_active=1");
$totalCustomers = (int) val("SELECT COUNT(DISTINCT customer_id_fk) FROM service_checklists WHERE is_active=1");

// Completion rate across all jobs
$completionStats = one("
    SELECT
      SUM(CASE WHEN cc.completed=1 THEN 1 ELSE 0 END) AS done,
      SUM(CASE WHEN cc.completed=1 AND cc.photo IS NOT NULL THEN 1 ELSE 0 END) AS done_with_photo,
      COUNT(*) AS total
    FROM checklist_completions cc
");

// Detail view
$detailServiceId = (int)($_GET['s'] ?? 0);
$detailItems = $detailServiceId
    ? all("SELECT * FROM service_checklists WHERE s_id_fk=? AND is_active=1 ORDER BY position", [$detailServiceId])
    : [];
$detailService = $detailServiceId
    ? one("SELECT s.*, c.name AS customer_name FROM services s LEFT JOIN customer c ON s.customer_id_fk=c.customer_id WHERE s.s_id=?", [$detailServiceId])
    : null;
$detailJobs = $detailServiceId
    ? all("SELECT j_id, j_date, j_time, job_status, emp_id_fk,
                  (SELECT CONCAT(e.name,' ',COALESCE(e.surname,'')) FROM employee e WHERE e.emp_id=j.emp_id_fk) AS partner_name
           FROM jobs j
           WHERE j.s_id_fk=? AND j.status=1
           ORDER BY j.j_date DESC LIMIT 30", [$detailServiceId])
    : [];

include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
    <span>📋</span> Checklisten
  </h1>
  <p class="text-sm text-gray-500 mt-1">Übersicht aller Kunden-Checklisten pro Service — wer hat welche Aufgaben definiert.</p>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-extrabold text-gray-900"><?= $totalItems ?></div>
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mt-1">Aufgaben gesamt</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-extrabold text-brand"><?= $totalServices ?></div>
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mt-1">Services mit Checkliste</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-extrabold text-indigo-600"><?= $totalCustomers ?></div>
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mt-1">Kunden aktiv</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <?php
      $rate = ($completionStats['total'] ?? 0) > 0 ? round(($completionStats['done'] ?? 0) / $completionStats['total'] * 100) : 0;
      $rateWithPhoto = ($completionStats['total'] ?? 0) > 0 ? round(($completionStats['done_with_photo'] ?? 0) / $completionStats['total'] * 100) : 0;
    ?>
    <div class="text-2xl font-extrabold text-green-600"><?= $rate ?> %</div>
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mt-1">Completion Rate</div>
    <div class="text-[10px] text-gray-400 mt-0.5"><?= $rateWithPhoto ?>% mit Foto (Bonus-fähig)</div>
  </div>
</div>

<!-- Search -->
<div class="mb-4">
  <form method="GET">
    <input type="text" name="q" value="<?= e($search) ?>" placeholder="Service oder Kunde suchen..." class="w-full max-w-md px-4 py-2.5 border-2 border-gray-100 rounded-xl focus:border-brand outline-none"/>
  </form>
</div>

<!-- Main table -->
<div class="bg-white rounded-xl border overflow-hidden mb-6">
  <?php if (empty($rows)): ?>
  <div class="p-12 text-center">
    <div class="text-5xl mb-3">📋</div>
    <h3 class="font-bold text-gray-900">Noch keine Checklisten</h3>
    <p class="text-sm text-gray-500 mt-1">Kunden können Checklisten pro Service auf <code>/customer/checklist.php</code> erstellen.</p>
  </div>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
        <tr>
          <th class="px-4 py-2 text-left">Kunde</th>
          <th class="px-4 py-2 text-left">Service</th>
          <th class="px-4 py-2 text-left">Adresse</th>
          <th class="px-4 py-2 text-left">Aufgaben</th>
          <th class="px-4 py-2 text-left">Critical</th>
          <th class="px-4 py-2 text-left">Aktualisiert</th>
          <th class="px-4 py-2 text-left"></th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($rows as $r): ?>
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3">
            <a href="/admin/view-customer.php?id=<?= (int)$r['customer_id'] ?>" class="font-semibold text-brand hover:underline"><?= e($r['customer_name']) ?></a>
            <div class="text-[10px] text-gray-400"><?= e($r['customer_type'] ?? '') ?></div>
          </td>
          <td class="px-4 py-3 font-semibold text-gray-900"><?= e($r['service_title']) ?></td>
          <td class="px-4 py-3 text-xs text-gray-600 truncate max-w-[200px]"><?= e(trim(($r['street'] ?? '') . ', ' . ($r['city'] ?? ''), ', ')) ?></td>
          <td class="px-4 py-3 font-bold text-gray-900"><?= (int)$r['total_items'] ?></td>
          <td class="px-4 py-3">
            <?php if ((int)$r['critical_items'] > 0): ?>
            <span class="px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-[11px] font-bold"><?= (int)$r['critical_items'] ?> 🔴</span>
            <?php else: ?>
            <span class="text-gray-300">—</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-xs text-gray-600 whitespace-nowrap"><?= $r['last_updated'] ? e(date('d.m.Y H:i', strtotime($r['last_updated']))) : '—' ?></td>
          <td class="px-4 py-3">
            <a href="?s=<?= (int)$r['s_id'] ?>" class="px-2 py-1 bg-brand/10 hover:bg-brand text-brand hover:text-white rounded text-[11px] font-semibold transition">Details</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Detail view -->
<?php if ($detailService): ?>
<div id="detail" class="bg-white rounded-xl border overflow-hidden mb-6" x-data="{ editId: null, showAdd: false }">
  <div class="px-5 py-3 border-b bg-brand/5 flex items-center justify-between">
    <div>
      <h2 class="font-bold text-gray-900">Detail: <?= e($detailService['title']) ?></h2>
      <div class="text-xs text-gray-500"><?= e($detailService['customer_name']) ?> · <?= count($detailItems) ?> Aufgaben</div>
    </div>
    <div class="flex gap-2 items-center">
      <button @click="showAdd = !showAdd" class="px-3 py-1.5 bg-brand hover:bg-brand-dark text-white rounded-lg text-xs font-semibold">+ Aufgabe</button>
      <a href="?" class="text-[11px] text-gray-500 hover:text-brand">Schließen ×</a>
    </div>
  </div>

  <!-- Jobs für diesen Service -->
  <?php if (!empty($detailJobs)): ?>
  <div class="px-5 py-3 border-b bg-gray-50">
    <h3 class="text-[11px] uppercase font-bold text-gray-500 tracking-wide mb-2">Jobs für diesen Service <span class="text-gray-400 normal-case">· Klick auf Job-Nr = direkt bearbeiten</span></h3>
    <div class="flex flex-wrap gap-2">
      <?php foreach ($detailJobs as $jb):
        $bg = match($jb['job_status']) {
          'COMPLETED' => 'bg-green-50 border-green-300 text-green-800',
          'CANCELLED' => 'bg-red-50 border-red-300 text-red-800',
          'RUNNING'   => 'bg-orange-50 border-orange-300 text-orange-800',
          'CONFIRMED' => 'bg-amber-50 border-amber-300 text-amber-800',
          'PENDING'   => !$jb['emp_id_fk'] ? 'bg-blue-50 border-blue-300 text-blue-800' : 'bg-amber-50 border-amber-300 text-amber-800',
          default     => 'bg-gray-50 border-gray-300 text-gray-700',
        };
      ?>
      <a href="/admin/jobs.php?view=<?= (int)$jb['j_id'] ?>&edit=1"
         class="flex flex-col items-start px-3 py-2 border rounded-lg hover:shadow-md hover:-translate-y-0.5 transition <?= $bg ?>"
         title="Job bearbeiten">
        <div class="text-[10px] font-bold uppercase tracking-wide opacity-70">JOB</div>
        <div class="font-mono font-bold text-sm">#<?= (int)$jb['j_id'] ?></div>
        <div class="font-mono text-[11px] opacity-80"><?= date('d.m.', strtotime($jb['j_date'])) ?></div>
        <div class="flex items-center gap-1 mt-1">
          <a href="/admin/job-report.php?j_id=<?= (int)$jb['j_id'] ?>" target="_blank" class="text-[9px] underline opacity-70 hover:opacity-100" onclick="event.stopPropagation()" title="Versicherungs-Bericht">📄 Bericht</a>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Add form -->
  <div x-show="showAdd" x-cloak x-transition class="px-5 py-4 border-b bg-green-50/40">
    <form method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-2">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_item"/>
      <input type="hidden" name="s_id_fk" value="<?= (int)$detailServiceId ?>"/>
      <input name="title" required placeholder="Aufgabe (z.B. Küche reinigen)" class="md:col-span-2 px-3 py-2 border rounded-lg text-sm"/>
      <input name="room" placeholder="Raum (z.B. Küche)" class="px-3 py-2 border rounded-lg text-sm"/>
      <select name="priority" class="px-3 py-2 border rounded-lg text-sm bg-white">
        <option value="normal">Normal</option>
        <option value="high">🟠 Wichtig</option>
        <option value="critical">🔴 Kritisch</option>
      </select>
      <input name="description" placeholder="Notizen (optional)" class="px-3 py-2 border rounded-lg text-sm"/>
      <button type="submit" class="px-3 py-2 bg-brand hover:bg-brand-dark text-white rounded-lg text-sm font-semibold">Speichern</button>
    </form>
  </div>

  <!-- Checklist items -->
  <div class="p-4 space-y-2">
    <?php if (empty($detailItems)): ?>
    <div class="text-center py-6 text-sm text-gray-400">Noch keine Aufgaben. Klick "+ Aufgabe" oben.</div>
    <?php else:
    $itemsByRoom = [];
    foreach ($detailItems as $it) {
      $r = $it['room'] ?: 'Allgemein';
      $itemsByRoom[$r][] = $it;
    }
    foreach ($itemsByRoom as $room => $items):
    ?>
    <div class="mb-3">
      <h4 class="text-[11px] uppercase font-bold text-gray-500 tracking-wide mb-2"><?= e($room) ?></h4>
      <div class="space-y-1.5">
        <?php foreach ($items as $it):
          $prCls = match($it['priority']) {
            'critical' => 'border-l-red-500 bg-red-50/30',
            'high'     => 'border-l-amber-500 bg-amber-50/30',
            default    => 'border-l-gray-300 bg-white',
          };
        ?>
        <div class="p-2.5 rounded border-l-4 border border-gray-100 <?= $prCls ?>">
          <div x-show="editId !== <?= (int)$it['checklist_id'] ?>" class="flex items-start gap-3">
            <?php if ($it['photo']): ?>
            <a href="<?= e($it['photo']) ?>" target="_blank" class="flex-shrink-0">
              <img src="<?= e($it['photo']) ?>" class="w-12 h-12 object-cover rounded"/>
            </a>
            <?php endif; ?>
            <div class="flex-1">
              <div class="font-semibold text-sm"><?= e($it['title']) ?>
                <?php if ($it['priority'] === 'critical'): ?><span class="text-[10px] text-red-600 font-normal">🔴 kritisch</span><?php elseif ($it['priority'] === 'high'): ?><span class="text-[10px] text-amber-600 font-normal">🟠 wichtig</span><?php endif; ?>
              </div>
              <?php if ($it['description']): ?><div class="text-xs text-gray-600 mt-0.5"><?= nl2br(e($it['description'])) ?></div><?php endif; ?>
            </div>
            <div class="flex-shrink-0 flex gap-1">
              <button @click="editId = <?= (int)$it['checklist_id'] ?>" class="px-2 py-1 text-[11px] text-gray-600 hover:bg-gray-100 rounded" title="Bearbeiten">✎</button>
              <form method="POST" class="inline" onsubmit="return confirm('Aufgabe entfernen?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_item"/>
                <input type="hidden" name="checklist_id" value="<?= (int)$it['checklist_id'] ?>"/>
                <button class="px-2 py-1 text-[11px] text-red-600 hover:bg-red-50 rounded" title="Löschen">🗑</button>
              </form>
            </div>
          </div>
          <div x-show="editId === <?= (int)$it['checklist_id'] ?>" x-cloak>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-2">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="edit_item"/>
              <input type="hidden" name="checklist_id" value="<?= (int)$it['checklist_id'] ?>"/>
              <input name="title" value="<?= e($it['title']) ?>" required class="md:col-span-2 px-3 py-1.5 border rounded text-sm"/>
              <input name="room" value="<?= e($it['room']) ?>" placeholder="Raum" class="px-3 py-1.5 border rounded text-sm"/>
              <select name="priority" class="px-3 py-1.5 border rounded text-sm bg-white">
                <option value="normal" <?= $it['priority']==='normal'?'selected':'' ?>>Normal</option>
                <option value="high" <?= $it['priority']==='high'?'selected':'' ?>>🟠 Wichtig</option>
                <option value="critical" <?= $it['priority']==='critical'?'selected':'' ?>>🔴 Kritisch</option>
              </select>
              <input name="description" value="<?= e($it['description']) ?>" placeholder="Notizen" class="px-3 py-1.5 border rounded text-sm"/>
              <div class="flex gap-1">
                <button type="submit" class="flex-1 px-2 py-1.5 bg-brand text-white rounded text-xs font-semibold">✓</button>
                <button type="button" @click="editId = null" class="px-2 py-1.5 border rounded text-xs">✕</button>
              </div>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
