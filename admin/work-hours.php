<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Arbeitszeit'; $page = 'work-hours';

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$empFilter = $_GET['emp'] ?? '';
$custFilter = $_GET['cust'] ?? '';

$sql = "SELECT j.j_id, j.j_date, j.j_time, j.start_time, j.end_time, j.j_hours, j.total_hours, j.address, j.job_note,
        c.name as cname, c.customer_type as ctype,
        e.name as ename, e.surname as esurname, e.tariff,
        s.title as stitle, s.total_price as sprice
        FROM jobs j
        LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
        LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
        LEFT JOIN services s ON j.s_id_fk=s.s_id
        WHERE j.job_status='COMPLETED' AND j.j_date BETWEEN ? AND ?";
$p = [$startDate, $endDate];
if ($empFilter) { $sql .= " AND j.emp_id_fk=?"; $p[] = $empFilter; }
if ($custFilter) { $sql .= " AND j.customer_id_fk=?"; $p[] = $custFilter; }
$sql .= " ORDER BY j.j_date DESC, j.j_time DESC";
$jobs = all($sql, $p);

$employees = all("SELECT emp_id, name, surname FROM employee WHERE status=1 ORDER BY name");
$customers_list = all("SELECT customer_id, name FROM customer WHERE status=1 ORDER BY name");

// Totals
$totalHours = array_sum(array_map(fn($j) => $j['total_hours'] ?: $j['j_hours'], $jobs));
$totalSalary = array_sum(array_map(fn($j) => ($j['tariff'] ?? 0) * ($j['total_hours'] ?: $j['j_hours']), $jobs));
$totalRevenue = array_sum(array_map(fn($j) => ($j['sprice'] ?? 0) * ($j['total_hours'] ?: $j['j_hours']), $jobs));

include __DIR__ . '/../includes/layout.php';
?>

<!-- Filters -->
<form class="bg-white rounded-xl border p-5 mb-6">
  <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Von</label><input type="date" name="start_date" value="<?= e($startDate) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/></div>
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Bis</label><input type="date" name="end_date" value="<?= e($endDate) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/></div>
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Mitarbeiter</label>
      <select name="emp" class="w-full px-3 py-2 border rounded-lg text-sm"><option value="">Alle</option>
        <?php foreach ($employees as $emp): ?><option value="<?= $emp['emp_id'] ?>" <?= $empFilter==$emp['emp_id']?'selected':'' ?>><?= e($emp['name'].' '.$emp['surname']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Kunde</label>
      <select name="cust" class="w-full px-3 py-2 border rounded-lg text-sm"><option value="">Alle</option>
        <?php foreach ($customers_list as $c): ?><option value="<?= $c['customer_id'] ?>" <?= $custFilter==$c['customer_id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="flex items-end"><button class="w-full px-4 py-2 bg-brand text-white rounded-lg text-sm font-medium">Filtern</button></div>
  </div>
</form>

<!-- Summary -->
<div class="grid grid-cols-3 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-4"><div class="text-2xl font-bold text-brand"><?= number_format($totalHours, 1) ?>h</div><div class="text-sm text-gray-500">Gesamtstunden</div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-2xl font-bold text-red-600"><?= money($totalSalary) ?></div><div class="text-sm text-gray-500">Gehalt (Kosten)</div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-2xl font-bold text-green-600"><?= money($totalRevenue) ?></div><div class="text-sm text-gray-500">Umsatz (Kunden)</div></div>
</div>

<!-- Table -->
<div class="bg-white rounded-xl border">
  <div class="p-5 border-b flex items-center justify-between">
    <h3 class="font-semibold">Erledigte Jobs (<?= count($jobs) ?>)</h3>
    <div class="flex gap-2">
      <a href="/api/index.php?action=export/workhours&month=<?= date('Y-m', strtotime($startDate)) ?><?= $custFilter ? '&customer_id='.$custFilter : '' ?>&key=<?= API_KEY ?>" class="px-3 py-1.5 border rounded-lg text-sm text-gray-600 hover:bg-gray-50">CSV Export</a>
      <?php if ($custFilter): ?>
      <button onclick="generateInvoice(<?= $custFilter ?>,'<?= date('Y-m', strtotime($startDate)) ?>')" class="px-3 py-1.5 bg-brand text-white rounded-lg text-sm font-medium">Rechnung erstellen</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Mitarbeiter</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Kunde</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Service</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Start</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Ende</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Std</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Gehalt</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Umsatz</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($jobs as $j):
        $hrs = $j['total_hours'] ?: $j['j_hours'];
        $salary = ($j['tariff'] ?? 0) * $hrs;
        $revenue = ($j['sprice'] ?? 0) * $hrs;
      ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 font-mono"><?= date('d.m.Y', strtotime($j['j_date'])) ?></td>
        <td class="px-4 py-3"><?= e($j['ename'].' '.($j['esurname']??'')) ?></td>
        <td class="px-4 py-3"><?= e($j['cname']) ?> <span class="text-xs text-gray-400">(<?= e($j['ctype']) ?>)</span></td>
        <td class="px-4 py-3"><?= e($j['stitle']) ?></td>
        <td class="px-4 py-3 font-mono"><?= $j['start_time'] ? substr($j['start_time'],0,5) : '-' ?></td>
        <td class="px-4 py-3 font-mono"><?= $j['end_time'] ? substr($j['end_time'],0,5) : '-' ?></td>
        <td class="px-4 py-3 font-medium"><?= number_format($hrs, 1) ?>h</td>
        <td class="px-4 py-3 text-red-600"><?= money($salary) ?></td>
        <td class="px-4 py-3 text-green-600"><?= money($revenue) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($jobs)): ?>
      <tr><td colspan="9" class="px-4 py-8 text-center text-gray-400">Keine erledigten Jobs im Zeitraum.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$script = <<<JS
function generateInvoice(custId, month) {
    if (!confirm('Rechnung für Monat ' + month + ' erstellen?')) return;
    fetch('/api/index.php?action=invoice/generate', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-API-Key':'${\constant('API_KEY')}'},
        body:JSON.stringify({customer_id:custId, month:month})
    }).then(r=>r.json()).then(d=>{
        if(d.success) alert('Rechnung ' + d.data.invoice_number + ' erstellt!\\n' + d.data.jobs_count + ' Jobs, ' + d.data.hours + 'h, ' + d.data.total.toFixed(2) + ' €');
        else alert(d.error || 'Fehler');
    });
}
JS;
include __DIR__ . '/../includes/footer.php'; ?>
