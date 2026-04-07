<?php
require_once __DIR__ . '/../includes/auth.php';
requireEmployee();
if (!employeeCan('portal_earnings')) { header('Location: /employee/'); exit; }
$title = 'Verdienst'; $page = 'earnings';
$user = me();
$eid = $user['id'];

$emp = one("SELECT * FROM employee WHERE emp_id=?", [$eid]);
$month = $_GET['month'] ?? date('Y-m');

$jobs = all("SELECT j.*, s.title as stitle, c.name as cname
    FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
    WHERE j.emp_id_fk=? AND j.job_status='COMPLETED' AND j.j_date LIKE ? AND j.status=1
    ORDER BY j.j_date DESC", [$eid, "$month%"]);

$tariff = (float)($emp['tariff'] ?: 0);
$totalHours = 0;
$totalEarnings = 0;
foreach ($jobs as $j) {
    $hrs = $j['total_hours'] ?: $j['j_hours'];
    $totalHours += $hrs;
    $totalEarnings += $hrs * $tariff;
}

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
<div class="grid grid-cols-3 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-bold text-brand"><?= number_format($totalHours, 1) ?>h</div>
    <div class="text-sm text-gray-500">Stunden</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-bold text-green-600"><?= money($totalEarnings) ?></div>
    <div class="text-sm text-gray-500">Verdienst</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-bold"><?= count($jobs) ?></div>
    <div class="text-sm text-gray-500">Jobs</div>
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
        <th class="px-4 py-3 text-left font-medium text-gray-600">Stunden</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Verdienst</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($jobs as $j):
        $hrs = $j['total_hours'] ?: $j['j_hours'];
      ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 font-mono"><?= date('d.m.Y', strtotime($j['j_date'])) ?></td>
        <td class="px-4 py-3"><?= employeeCan('can_see_customer_info') ? e($j['cname']) : '***' ?></td>
        <td class="px-4 py-3"><?= e($j['stitle']) ?></td>
        <td class="px-4 py-3 font-medium"><?= number_format($hrs, 1) ?>h</td>
        <td class="px-4 py-3 text-green-600 font-medium"><?= money($hrs * $tariff) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($jobs)): ?>
      <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Keine Jobs in diesem Monat.</td></tr>
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
