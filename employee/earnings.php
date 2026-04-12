<?php
require_once __DIR__ . '/../includes/auth.php';
requireEmployee();
if (!employeeCan('portal_earnings')) { header('Location: /employee/'); exit; }
$title = t('nav.earnings'); $page = 'earnings';
$user = me();
$eid = $user['id'];

$emp = one("SELECT * FROM employee WHERE emp_id=?", [$eid]);
$month = $_GET['month'] ?? date('Y-m');

$jobs = all("SELECT j.*, s.title as stitle, c.name as cname
    FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
    WHERE j.emp_id_fk=? AND j.job_status='COMPLETED' AND j.j_date LIKE ? AND j.status=1
    ORDER BY j.j_date DESC", [$eid, "$month%"]);

// Checklist bonus rate — 5% bonus when ALL checklist items are completed (with proof photos)
define('CHECKLIST_BONUS_RATE', 0.05);

$tariff = (float)($emp['tariff'] ?: 0);
$totalHours = 0;
$totalBase = 0;
$totalBonus = 0;
$bonusEligibleCount = 0;

// Compute per-job bonus
foreach ($jobs as $k => $j) {
    $hrs = $j['total_hours'] ?: $j['j_hours'];
    $base = $hrs * $tariff;
    $totalHours += $hrs;
    $totalBase += $base;

    // Check if service has a checklist + partner completed ALL items with photos
    $jobs[$k]['base_pay'] = $base;
    $jobs[$k]['bonus'] = 0;
    $jobs[$k]['bonus_reason'] = '';

    if (!empty($j['s_id_fk'])) {
        $totalItems = (int) val("SELECT COUNT(*) FROM service_checklists WHERE s_id_fk=? AND is_active=1", [$j['s_id_fk']]);
        if ($totalItems > 0) {
            // All items done + has photo
            $doneWithPhoto = (int) val("SELECT COUNT(*) FROM checklist_completions WHERE job_id_fk=? AND completed=1 AND photo IS NOT NULL", [$j['j_id']]);
            $doneAny = (int) val("SELECT COUNT(*) FROM checklist_completions WHERE job_id_fk=? AND completed=1", [$j['j_id']]);
            $jobs[$k]['checklist_total'] = $totalItems;
            $jobs[$k]['checklist_done'] = $doneAny;
            if ($doneWithPhoto >= $totalItems) {
                // Full bonus
                $bonus = round($base * CHECKLIST_BONUS_RATE, 2);
                $jobs[$k]['bonus'] = $bonus;
                $jobs[$k]['bonus_reason'] = 'Alle ' . $totalItems . ' Items mit Foto erledigt';
                $totalBonus += $bonus;
                $bonusEligibleCount++;
            }
        }
    }
}
$totalEarnings = $totalBase + $totalBonus;

// Last 6 months for chart
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $mHrs = val("SELECT COALESCE(SUM(COALESCE(total_hours, j_hours)),0) FROM jobs WHERE emp_id_fk=? AND job_status='COMPLETED' AND j_date LIKE ? AND status=1", [$eid, "$m%"]);
    $monthlyData[] = ['month' => date('M', strtotime("$m-01")), 'hours' => round($mHrs, 1), 'earnings' => round($mHrs * $tariff, 2)];
}

include __DIR__ . '/../includes/layout.php';
?>

<!-- Month selector -->
<div class="flex items-center gap-4 mb-6">
  <a href="?month=<?= date('Y-m', strtotime("$month-01 -1 month")) ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">&larr;</a>
  <input type="month" value="<?= $month ?>" onchange="location='?month='+this.value" class="px-3 py-2 border rounded-lg text-sm font-medium"/>
  <a href="?month=<?= date('Y-m', strtotime("$month-01 +1 month")) ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">&rarr;</a>
</div>

<!-- Summary -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-bold text-brand"><?= number_format($totalHours, 1) ?>h</div>
    <div class="text-sm text-gray-500">Stunden</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-bold text-green-600"><?= money($totalEarnings) ?></div>
    <div class="text-sm text-gray-500">Verdienst gesamt</div>
    <?php if ($totalBonus > 0): ?>
    <div class="text-[10px] text-amber-600 font-semibold mt-0.5">+ <?= money($totalBonus) ?> Bonus</div>
    <?php endif; ?>
  </div>
  <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-xl border border-amber-200 p-4">
    <div class="text-2xl font-bold text-amber-600"><?= money($totalBonus) ?></div>
    <div class="text-sm text-gray-500 flex items-center gap-1">
      <span>🏆</span> Checklist-Bonus
    </div>
    <div class="text-[10px] text-gray-400 mt-0.5"><?= $bonusEligibleCount ?> von <?= count($jobs) ?> Jobs</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-bold"><?= count($jobs) ?></div>
    <div class="text-sm text-gray-500">Jobs</div>
  </div>
</div>

<!-- Bonus explanation -->
<div class="mb-6 p-4 rounded-xl bg-gradient-to-r from-amber-50 to-yellow-50 border border-amber-200 flex items-start gap-3">
  <span class="text-2xl">🏆</span>
  <div class="flex-1">
    <div class="font-bold text-sm text-amber-900">Checklist-Bonus: +5 % auf den Job</div>
    <div class="text-xs text-amber-800 mt-0.5">
      Wenn Sie bei einem Job <strong>alle Checklist-Items</strong> abhaken <strong>und jedes mit einem Beweisfoto</strong> belegen, bekommen Sie automatisch <strong>+5 %</strong> auf den Job-Verdienst. Kein Zusatzaufwand — einfach die Fotos direkt beim Job machen.
    </div>
  </div>
</div>

<!-- 6 Month Chart -->
<div class="bg-white rounded-xl border p-5 mb-6">
  <h3 class="font-semibold mb-3">Letzte 6 Monate</h3>
  <div style="height:200px"><canvas id="earningsChart"></canvas></div>
</div>

<!-- Jobs table -->
<div class="bg-white rounded-xl border">
  <div class="p-5 border-b"><h3 class="font-semibold">Jobs — <?= count($jobs) ?></h3></div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Kunde</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Service</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Checkliste</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Stunden</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Basis</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Bonus</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Gesamt</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($jobs as $j):
        $hrs = $j['total_hours'] ?: $j['j_hours'];
        $base = $j['base_pay'] ?? ($hrs * $tariff);
        $bonus = $j['bonus'] ?? 0;
        $total = $base + $bonus;
      ?>
      <tr class="hover:bg-gray-50 <?= $bonus > 0 ? 'bg-amber-50/30' : '' ?>">
        <td class="px-4 py-3 font-mono"><?= date('d.m.Y', strtotime($j['j_date'])) ?></td>
        <td class="px-4 py-3"><?= employeeCan('can_see_customer_info') ? e($j['cname']) : '***' ?></td>
        <td class="px-4 py-3"><?= e($j['stitle']) ?></td>
        <td class="px-4 py-3">
          <?php if (!empty($j['checklist_total'])): ?>
            <span class="text-xs font-semibold <?= $bonus > 0 ? 'text-green-600' : 'text-gray-500' ?>"><?= $j['checklist_done'] ?? 0 ?>/<?= $j['checklist_total'] ?></span>
            <?php if ($bonus > 0): ?><span class="ml-1 text-[10px]">✓</span><?php endif; ?>
          <?php else: ?>
            <span class="text-[10px] text-gray-300">—</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3 font-medium"><?= number_format($hrs, 1) ?>h</td>
        <td class="px-4 py-3 text-gray-700"><?= money($base) ?></td>
        <td class="px-4 py-3">
          <?php if ($bonus > 0): ?>
          <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[11px] font-bold">+<?= money($bonus) ?></span>
          <?php else: ?>
          <span class="text-[10px] text-gray-300">—</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3 font-bold <?= $bonus > 0 ? 'text-amber-700' : 'text-green-600' ?>"><?= money($total) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($jobs)): ?>
      <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">Keine Jobs in diesem Monat.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$chartLabels = json_encode(array_column($monthlyData, 'month'));
$chartEarnings = json_encode(array_column($monthlyData, 'earnings'));
$chartHours = json_encode(array_column($monthlyData, 'hours'));
$script = <<<JS
new Chart(document.getElementById('earningsChart'), {
    type: 'bar',
    data: {
        labels: $chartLabels,
        datasets: [{
            label: 'Verdienst (€)',
            data: $chartEarnings,
            backgroundColor: '#2E7D6B',
            borderRadius: 8,
            borderSkipped: false,
            yAxisID: 'y'
        },{
            label: 'Stunden',
            data: $chartHours,
            type: 'line',
            borderColor: '#6366f1',
            backgroundColor: 'rgba(99,102,241,0.1)',
            fill: true,
            tension: 0.3,
            pointRadius: 4,
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, font: { size: 11 } } } },
        scales: {
            y: { beginAtZero: true, position: 'left', ticks: { font: { size: 10 }, callback: v => v + ' €' }, grid: { color: 'rgba(0,0,0,0.04)' } },
            y1: { beginAtZero: true, position: 'right', ticks: { font: { size: 10 }, callback: v => v + 'h' }, grid: { display: false } },
            x: { ticks: { font: { size: 11 } }, grid: { display: false } }
        }
    }
});
JS;
include __DIR__ . '/../includes/footer.php'; ?>
