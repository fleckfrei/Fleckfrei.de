<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
if (!customerCan('workhours')) { header('Location: /customer/'); exit; }
$title = t('wh.title'); $page = 'workhours';
$cid = me()['id'];

$month = $_GET['month'] ?? date('Y-m');

$jobs = all("SELECT j.*, j.job_photos, s.title as stitle, s.total_price as sprice, e.name as ename, e.surname as esurname
    FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
    WHERE j.customer_id_fk=? AND j.job_status='COMPLETED' AND j.j_date LIKE ? AND j.status=1
    ORDER BY j.j_date DESC", [$cid, "$month%"]);

$totalHours = 0;
$totalCost = 0;
foreach ($jobs as $j) {
    $hrs = max(MIN_HOURS, $j['total_hours'] ?: $j['j_hours']);
    $totalHours += $hrs;
    $totalCost += $hrs * ($j['sprice'] ?: 0);
}

include __DIR__ . '/../includes/layout.php';
?>

<!-- Month Selector -->
<div class="flex items-center gap-4 mb-6">
  <a href="?month=<?= date('Y-m', strtotime("$month-01 -1 month")) ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">&larr;</a>
  <input type="month" value="<?= $month ?>" onchange="location='?month='+this.value" class="px-3 py-2 border rounded-lg text-sm font-medium"/>
  <a href="?month=<?= date('Y-m', strtotime("$month-01 +1 month")) ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">&rarr;</a>
</div>

<!-- Summary -->
<div class="grid grid-cols-2 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-bold text-brand"><?= number_format($totalHours, 1) ?>h</div>
    <div class="text-sm text-gray-500">Stunden (min. <?= MIN_HOURS ?>h pro Einsatz)</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-bold"><?= money($totalCost) ?></div>
    <div class="text-sm text-gray-500">Netto-Kosten</div>
  </div>
</div>

<!-- Table -->
<div class="bg-white rounded-xl border">
  <div class="p-5 border-b"><h3 class="font-semibold">Erledigte Jobs — <?= count($jobs) ?></h3></div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Service</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Partner</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Start</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Ende</th>
        <?php if (customerCan('wh_umsatz')): ?><th class="px-4 py-3 text-left font-medium text-gray-600">Stunden</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Kosten</th><?php endif; ?>
        <?php if (customerCan('wh_fotos')): ?><th class="px-4 py-3 text-left font-medium text-gray-600">Fotos</th><?php endif; ?>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($jobs as $j):
        $hrs = max(MIN_HOURS, $j['total_hours'] ?: $j['j_hours']);
        $cost = $hrs * ($j['sprice'] ?: 0);
      ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 font-mono"><?= date('d.m.Y', strtotime($j['j_date'])) ?></td>
        <td class="px-4 py-3"><?= e($j['stitle']) ?></td>
        <td class="px-4 py-3"><?= e($j['ename'].' '.($j['esurname']??'')) ?></td>
        <td class="px-4 py-3 font-mono"><?= $j['start_time'] ? substr($j['start_time'],0,5) : '-' ?></td>
        <td class="px-4 py-3 font-mono"><?= $j['end_time'] ? substr($j['end_time'],0,5) : '-' ?></td>
        <?php if (customerCan('wh_umsatz')): ?>
        <td class="px-4 py-3 font-medium"><?= number_format($hrs, 1) ?>h</td>
        <td class="px-4 py-3"><?= money($cost) ?></td>
        <?php endif; ?>
        <?php if (customerCan('wh_fotos')):
          $photos = !empty($j['job_photos']) ? json_decode($j['job_photos'], true) : [];
        ?>
        <td class="px-4 py-3">
          <?php if (!empty($photos)): ?>
          <div class="flex gap-1">
            <?php foreach ($photos as $p): ?>
            <a href="/uploads/jobs/<?= $j['j_id'] ?>/<?= e($p) ?>" target="_blank"><img src="/uploads/jobs/<?= $j['j_id'] ?>/<?= e($p) ?>" class="w-8 h-8 object-cover rounded border"/></a>
            <?php endforeach; ?>
          </div>
          <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($jobs)): ?>
      <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Keine Jobs in diesem Monat.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
