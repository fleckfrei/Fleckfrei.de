<?php
/**
 * Admin — Checklists Overview
 * Shows all customer-defined checklists per service, with completion stats.
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Checklisten'; $page = 'checklists';

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
<?php if ($detailService && !empty($detailItems)): ?>
<div class="bg-white rounded-xl border overflow-hidden mb-6">
  <div class="px-5 py-3 border-b bg-brand/5 flex items-center justify-between">
    <div>
      <h2 class="font-bold text-gray-900">Detail: <?= e($detailService['title']) ?></h2>
      <div class="text-xs text-gray-500"><?= e($detailService['customer_name']) ?> · <?= count($detailItems) ?> Aufgaben</div>
    </div>
    <a href="?" class="text-[11px] text-gray-500 hover:text-brand">Schließen ×</a>
  </div>
  <div class="p-4 space-y-2">
    <?php
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
        <div class="p-2.5 rounded border-l-4 border border-gray-100 <?= $prCls ?> flex items-start gap-3">
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
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
