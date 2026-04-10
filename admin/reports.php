<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Reports'; $page = 'reports';

$month = $_GET['month'] ?? date('Y-m');
$year = $_GET['year'] ?? date('Y');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$monthNames = ['','Januar','Februar','Maerz','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
$monthLabel = $monthNames[(int)date('n', strtotime($monthStart))] . ' ' . date('Y', strtotime($monthStart));

// Monthly stats
$ms = one("SELECT
    (SELECT COUNT(*) FROM jobs WHERE j_date BETWEEN ? AND ? AND status=1) as total_jobs,
    (SELECT COUNT(*) FROM jobs WHERE j_date BETWEEN ? AND ? AND status=1 AND job_status='COMPLETED') as completed,
    (SELECT COUNT(*) FROM jobs WHERE j_date BETWEEN ? AND ? AND status=1 AND job_status='CANCELLED') as cancelled,
    (SELECT COALESCE(SUM(GREATEST(COALESCE(total_hours,j_hours),2)),0) FROM jobs WHERE j_date BETWEEN ? AND ? AND status=1 AND job_status='COMPLETED') as total_hours,
    (SELECT COUNT(DISTINCT customer_id_fk) FROM jobs WHERE j_date BETWEEN ? AND ? AND status=1) as unique_customers,
    (SELECT COUNT(DISTINCT emp_id_fk) FROM jobs WHERE j_date BETWEEN ? AND ? AND status=1 AND emp_id_fk IS NOT NULL) as active_partners,
    (SELECT COUNT(*) FROM invoices WHERE issue_date BETWEEN ? AND ?) as invoices_created,
    (SELECT COALESCE(SUM(total_price),0) FROM invoices WHERE issue_date BETWEEN ? AND ? AND invoice_paid='yes') as revenue_paid,
    (SELECT COALESCE(SUM(remaining_price),0) FROM invoices WHERE issue_date BETWEEN ? AND ? AND invoice_paid='no') as revenue_open,
    (SELECT COUNT(*) FROM customer WHERE created_at BETWEEN ? AND ? AND status=1) as new_customers",
    [$monthStart,$monthEnd, $monthStart,$monthEnd, $monthStart,$monthEnd, $monthStart,$monthEnd, $monthStart,$monthEnd, $monthStart,$monthEnd, $monthStart,$monthEnd, $monthStart,$monthEnd, $monthStart,$monthEnd, $monthStart,$monthEnd]);

$completionRate = $ms['total_jobs'] > 0 ? round(($ms['completed'] / $ms['total_jobs']) * 100) : 0;
$avgHoursPerJob = $ms['completed'] > 0 ? round($ms['total_hours'] / $ms['completed'], 1) : 0;
$revenueTotal = $ms['revenue_paid'] + $ms['revenue_open'];
$revenueBrutto = round($revenueTotal * 1.19, 2);

// Partner performance
$partners = all("SELECT e.emp_id, e.name, e.surname,
    COUNT(j.j_id) as jobs, SUM(CASE WHEN j.job_status='COMPLETED' THEN 1 ELSE 0 END) as done,
    SUM(CASE WHEN j.job_status='CANCELLED' THEN 1 ELSE 0 END) as cancelled,
    COALESCE(SUM(CASE WHEN j.job_status='COMPLETED' THEN GREATEST(COALESCE(j.total_hours,j.j_hours),2) ELSE 0 END),0) as hours
    FROM employee e LEFT JOIN jobs j ON j.emp_id_fk=e.emp_id AND j.j_date BETWEEN ? AND ? AND j.status=1
    WHERE e.status=1 GROUP BY e.emp_id ORDER BY done DESC", [$monthStart, $monthEnd]);

// Partner ratings
global $dbLocal;
$partnerRatings = [];
try {
    foreach ($partners as &$p) {
        $r = $dbLocal->prepare("SELECT AVG(stars) as avg, COUNT(*) as cnt FROM job_ratings WHERE emp_id=?");
        $r->execute([$p['emp_id']]);
        $rd = $r->fetch(PDO::FETCH_ASSOC);
        $p['avg_rating'] = round($rd['avg'] ?? 0, 1);
        $p['rating_count'] = (int)($rd['cnt'] ?? 0);
    }
    unset($p);
} catch (Exception $e) {}

// Top customers
$topCustomers = all("SELECT c.name, c.customer_type, COUNT(j.j_id) as jobs,
    COALESCE(SUM(GREATEST(COALESCE(j.total_hours,j.j_hours),2) * COALESCE(s.total_price,0)),0) as revenue
    FROM customer c JOIN jobs j ON j.customer_id_fk=c.customer_id AND j.job_status='COMPLETED' AND j.j_date BETWEEN ? AND ? AND j.status=1
    LEFT JOIN services s ON j.s_id_fk=s.s_id WHERE c.status=1 GROUP BY c.customer_id ORDER BY revenue DESC LIMIT 10", [$monthStart, $monthEnd]);

// Daily breakdown
$daily = all("SELECT j_date, COUNT(*) as total, SUM(CASE WHEN job_status='COMPLETED' THEN 1 ELSE 0 END) as done,
    COALESCE(SUM(CASE WHEN job_status='COMPLETED' THEN GREATEST(COALESCE(total_hours,j_hours),2) ELSE 0 END),0) as hours
    FROM jobs WHERE j_date BETWEEN ? AND ? AND status=1 GROUP BY j_date ORDER BY j_date", [$monthStart, $monthEnd]);

// Year trend
$yearTrend = [];
for ($m = 1; $m <= 12; $m++) {
    $ym = $year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
    $yt = one("SELECT COUNT(*) as jobs, SUM(CASE WHEN job_status='COMPLETED' THEN 1 ELSE 0 END) as done,
        COALESCE(SUM(CASE WHEN job_status='COMPLETED' THEN GREATEST(COALESCE(total_hours,j_hours),2) ELSE 0 END),0) as hours
        FROM jobs WHERE j_date LIKE ? AND status=1", ["$ym%"]);
    $yr = val("SELECT COALESCE(SUM(total_price),0) FROM invoices WHERE issue_date LIKE ? AND invoice_paid='yes'", ["$ym%"]);
    $yearTrend[] = ['month' => $monthNames[$m], 'jobs' => (int)($yt['done'] ?? 0), 'hours' => round($yt['hours'] ?? 0, 1), 'revenue' => round($yr ?? 0, 2)];
}

include __DIR__ . '/../includes/layout.php';
?>

<!-- Month Selector -->
<div class="flex items-center justify-between mb-6">
  <div class="flex items-center gap-3">
    <a href="?month=<?= date('Y-m', strtotime($monthStart . ' -1 month')) ?>" class="p-2 rounded-lg border hover:bg-gray-50">&larr;</a>
    <h2 class="text-lg font-bold"><?= $monthLabel ?></h2>
    <a href="?month=<?= date('Y-m', strtotime($monthStart . ' +1 month')) ?>" class="p-2 rounded-lg border hover:bg-gray-50">&rarr;</a>
    <a href="?month=<?= date('Y-m') ?>" class="px-3 py-1.5 text-sm border rounded-lg hover:bg-gray-50">Heute</a>
  </div>
  <button onclick="printReport()" class="px-4 py-2 bg-gray-800 text-white rounded-xl text-sm font-semibold">PDF drucken</button>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3 mb-6">
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Jobs gesamt</div><div class="text-2xl font-bold"><?= $ms['total_jobs'] ?></div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Erledigt</div><div class="text-2xl font-bold text-green-600"><?= $ms['completed'] ?></div><div class="text-xs text-gray-400"><?= $completionRate ?>%</div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Storniert</div><div class="text-2xl font-bold text-red-600"><?= $ms['cancelled'] ?></div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Stunden</div><div class="text-2xl font-bold"><?= round($ms['total_hours'], 1) ?></div><div class="text-xs text-gray-400">&empty; <?= $avgHoursPerJob ?>h/Job</div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Umsatz (netto)</div><div class="text-2xl font-bold text-brand"><?= number_format($revenueTotal, 0, ',', '.') ?> EUR</div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-xs text-gray-500">Neue Kunden</div><div class="text-2xl font-bold"><?= $ms['new_customers'] ?></div></div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
  <!-- Year Chart -->
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-3">Jahresuebersicht <?= $year ?></h3>
    <canvas id="yearChart" height="180"></canvas>
  </div>

  <!-- Partner Ranking -->
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-3">Partner Ranking</h3>
    <div class="space-y-2">
      <?php foreach ($partners as $i => $p): if (!$p['done']) continue; ?>
      <div class="flex items-center gap-3">
        <span class="w-6 h-6 rounded-full bg-brand/10 text-brand flex items-center justify-center text-xs font-bold"><?= $i+1 ?></span>
        <div class="flex-1">
          <div class="flex items-center justify-between">
            <span class="text-sm font-medium"><?= e($p['name'] . ' ' . ($p['surname'] ?? '')) ?></span>
            <span class="text-xs text-gray-400"><?= $p['done'] ?> Jobs / <?= round($p['hours'], 1) ?>h</span>
          </div>
          <div class="flex items-center gap-2 mt-0.5">
            <div class="flex-1 bg-gray-100 rounded-full h-1.5"><div class="bg-brand rounded-full h-1.5" style="width:<?= $partners[0]['done'] ? min(100, ($p['done']/$partners[0]['done'])*100) : 0 ?>%"></div></div>
            <?php if ($p['avg_rating'] > 0): ?>
            <span class="text-xs text-yellow-600 font-medium"><?= $p['avg_rating'] ?> &#9733; (<?= $p['rating_count'] ?>)</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Top Customers -->
<?php if (!empty($topCustomers)): ?>
<div class="bg-white rounded-xl border p-5 mb-6">
  <h3 class="font-semibold mb-3">Top 10 Kunden nach Umsatz</h3>
  <table class="w-full text-sm">
    <thead class="bg-gray-50"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Kunde</th><th class="px-3 py-2 text-left">Typ</th><th class="px-3 py-2 text-right">Jobs</th><th class="px-3 py-2 text-right">Umsatz</th></tr></thead>
    <tbody class="divide-y">
      <?php foreach ($topCustomers as $i => $tc): ?>
      <tr><td class="px-3 py-2"><?= $i+1 ?></td><td class="px-3 py-2 font-medium"><?= e($tc['name']) ?></td><td class="px-3 py-2 text-gray-500"><?= e($tc['customer_type']) ?></td><td class="px-3 py-2 text-right"><?= $tc['jobs'] ?></td><td class="px-3 py-2 text-right font-bold"><?= number_format($tc['revenue'], 2, ',', '.') ?> EUR</td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
$yearLabels = json_encode(array_column($yearTrend, 'month'));
$yearJobs = json_encode(array_column($yearTrend, 'jobs'));
$yearRevenue = json_encode(array_column($yearTrend, 'revenue'));
$apiKey = API_KEY;

$script = <<<JS
new Chart(document.getElementById('yearChart'), {
    type: 'bar',
    data: {
        labels: $yearLabels,
        datasets: [{
            label: 'Jobs',
            data: $yearJobs,
            backgroundColor: 'rgba(46,125,107,0.7)',
            borderRadius: 4,
            yAxisID: 'y'
        },{
            label: 'Umsatz (EUR)',
            data: $yearRevenue,
            type: 'line',
            borderColor: '#16a34a',
            borderWidth: 2,
            tension: 0.3,
            pointRadius: 3,
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        interaction: { intersect: false, mode: 'index' },
        scales: {
            y: { position: 'left', title: { display: true, text: 'Jobs' } },
            y1: { position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'EUR' } }
        }
    }
});

function printReport() {
    window.print();
}
JS;
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
