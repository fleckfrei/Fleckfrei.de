<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Arbeitszeit'; $page = 'work-hours';

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$empFilter = $_GET['emp'] ?? '';
$custFilter = $_GET['cust'] ?? '';
$svcFilter = $_GET['svc'] ?? '';

$sql = "SELECT j.j_id, j.j_date, j.j_time, j.start_time, j.end_time, j.j_hours, j.total_hours, j.address, j.job_note, j.job_photos,
        j.customer_id_fk, j.emp_id_fk, j.s_id_fk,
        c.name as cname, c.customer_type as ctype,
        e.name as ename, e.surname as esurname, e.tariff,
        s.title as stitle, s.price as sprice, s.total_price as sprice_brutto
        FROM jobs j
        LEFT JOIN customer c ON j.customer_id_fk=c.customer_id
        LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
        LEFT JOIN services s ON j.s_id_fk=s.s_id
        WHERE j.job_status='COMPLETED' AND j.j_date BETWEEN ? AND ? AND j.status=1";
$p = [$startDate, $endDate];
if ($empFilter) { $sql .= " AND j.emp_id_fk=?"; $p[] = $empFilter; }
if ($custFilter) { $sql .= " AND j.customer_id_fk=?"; $p[] = $custFilter; }
if ($svcFilter) { $sql .= " AND j.s_id_fk=?"; $p[] = $svcFilter; }
$sql .= " ORDER BY j.j_date DESC, j.j_time DESC";
$jobs = all($sql, $p);

$employees = all("SELECT emp_id, name, surname FROM employee WHERE status=1 ORDER BY name");
$customers_list = all("SELECT customer_id, name FROM customer WHERE status=1 ORDER BY name");
$services_list = all("SELECT s_id, title FROM services WHERE status=1 ORDER BY title");

// Totals
$totalEmpHours = 0; $totalCustHours = 0; $totalSalary = 0; $totalRevenue = 0; $totalProfit = 0;
foreach ($jobs as $j) {
    $rh = null;
    if ($j['start_time'] && $j['end_time']) {
        $s = strtotime($j['j_date'].' '.$j['start_time']); $e = strtotime($j['j_date'].' '.$j['end_time']);
        if ($e > $s) $rh = round(($e - $s) / 3600, 1);
    }
    $empHrs = $j['total_hours'] ?: ($rh ?: $j['j_hours']);
    $custHrs = max(MIN_HOURS, $j['j_hours']);
    $salary = ($j['tariff'] ?? 0) * $empHrs;
    $revenue = ($j['sprice'] ?? 0) * $custHrs;
    $totalEmpHours += $empHrs;
    $totalCustHours += $custHrs;
    $totalSalary += $salary;
    $totalRevenue += $revenue;
    $totalProfit += ($revenue - $salary);
}

include __DIR__ . '/../includes/layout.php';
?>

<!-- Filters -->
<form class="bg-white rounded-xl border p-5 mb-6">
  <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Von</label><input type="date" name="start_date" value="<?= e($startDate) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/></div>
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Bis</label><input type="date" name="end_date" value="<?= e($endDate) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/></div>
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Kunde</label>
      <select name="cust" id="whCust" class="w-full px-3 py-2 border rounded-lg text-sm" onchange="filterSvcByCustomer()"><option value="">Alle</option>
        <?php foreach ($customers_list as $c): ?><option value="<?= $c['customer_id'] ?>" <?= $custFilter==$c['customer_id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Service</label>
      <select name="svc" id="whSvc" class="w-full px-3 py-2 border rounded-lg text-sm"><option value="">Alle</option>
        <?php
        if ($custFilter) {
            $filteredSvcs = all("SELECT DISTINCT s.s_id, s.title FROM services s WHERE s.status=1 AND (s.customer_id_fk=? OR s.s_id IN (SELECT DISTINCT s_id_fk FROM jobs WHERE customer_id_fk=? AND status=1)) ORDER BY s.title", [$custFilter, $custFilter]);
        } else {
            $filteredSvcs = $services_list;
        }
        foreach ($filteredSvcs as $sv): ?><option value="<?= $sv['s_id'] ?>" <?= $svcFilter==$sv['s_id']?'selected':'' ?>><?= e($sv['title']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Partner</label>
      <select name="emp" class="w-full px-3 py-2 border rounded-lg text-sm"><option value="">Alle</option>
        <?php foreach ($employees as $emp): ?><option value="<?= $emp['emp_id'] ?>" <?= $empFilter==$emp['emp_id']?'selected':'' ?>><?= e($emp['name'].' '.$emp['surname']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="flex items-end"><button class="w-full px-4 py-2 bg-brand text-white rounded-lg text-sm font-medium">Filtern</button></div>
  </div>
</form>

<!-- Summary -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-4"><div class="text-2xl font-bold text-brand"><?= number_format($totalEmpHours, 1) ?>h</div><div class="text-sm text-gray-500">Partner-Std (real)</div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-2xl font-bold text-blue-600"><?= number_format($totalCustHours, 1) ?>h</div><div class="text-sm text-gray-500">Kunden-Std (min <?= MIN_HOURS ?>h)</div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-2xl font-bold text-red-600"><?= money($totalSalary) ?></div><div class="text-sm text-gray-500">Gehalt (Partner)</div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-2xl font-bold text-green-600"><?= money($totalRevenue) ?></div><div class="text-sm text-gray-500">Umsatz (Kunden)</div></div>
  <div class="bg-white rounded-xl border p-4"><div class="text-2xl font-bold <?= $totalProfit >= 0 ? 'text-brand' : 'text-red-600' ?>"><?= money($totalProfit) ?></div><div class="text-sm text-gray-500">Marge</div></div>
</div>

<!-- Table -->
<div class="bg-white rounded-xl border">
  <div class="p-5 border-b flex items-center justify-between">
    <h3 class="font-semibold">Erledigte Jobs (<?= count($jobs) ?>)</h3>
    <div class="flex gap-2 items-center">
      <input type="text" placeholder="Suchen..." oninput="filterWH(this.value)" class="px-3 py-1.5 border rounded-lg text-sm w-40"/>
      <a href="/api/index.php?action=export/workhours&start=<?= $startDate ?>&end=<?= $endDate ?><?= $custFilter ? '&customer_id='.$custFilter : '' ?><?= $empFilter ? '&emp_id='.$empFilter : '' ?>&key=<?= API_KEY ?>" class="px-3 py-1.5 border rounded-lg text-sm text-gray-600 hover:bg-gray-50">CSV Export</a>
      <?php if ($custFilter): ?>
      <button onclick="generateInvoice(<?= $custFilter ?>,'<?= date('Y-m', strtotime($startDate)) ?>')" class="px-3 py-1.5 bg-brand text-white rounded-lg text-sm font-medium">Rechnung erstellen</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm" id="whTable">
      <thead class="bg-gray-50"><tr>
        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500">Datum</th>
        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500">Kunde / Service</th>
        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500">Partner</th>
        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500">Zeit</th>
        <th class="px-2 py-2 text-center text-xs font-medium text-gray-500">Fotos</th>
        <th class="px-2 py-2 text-left text-xs font-medium text-gray-500">Notiz</th>
        <th class="px-2 py-2 text-right text-xs font-medium text-gray-500">Std (P)</th>
        <th class="px-2 py-2 text-right text-xs font-medium text-gray-500">Std (K)</th>
        <th class="px-2 py-2 text-right text-xs font-medium text-gray-500">Gehalt</th>
        <th class="px-2 py-2 text-right text-xs font-medium text-gray-500">Umsatz</th>
        <th class="px-2 py-2 text-right text-xs font-medium text-gray-500">Brutto</th>
        <th class="px-2 py-2 text-right text-xs font-medium text-gray-500">Marge</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($jobs as $j):
        // Calculate real hours from start/end time if available
        $realHrs = null;
        if ($j['start_time'] && $j['end_time']) {
            $start = strtotime($j['j_date'] . ' ' . $j['start_time']);
            $end = strtotime($j['j_date'] . ' ' . $j['end_time']);
            if ($end > $start) $realHrs = round(($end - $start) / 3600, 1);
        }
        $empHrs = $j['total_hours'] ?: ($realHrs ?: $j['j_hours']);
        $custHrs = max(MIN_HOURS, $j['j_hours']); // Customer always pays booked hours (min 2h)
        $empRate = $j['tariff'] ?? 0;
        $custRate = $j['sprice'] ?? 0;
        $empSvcRate = $j['emp_price'] ?? 0; // Employee service rate (what partner earns per h from service)
        $salary = $empRate * $empHrs;
        $revenue = $custRate * $custHrs;
        $margin = $revenue - $salary;
        $brutto = round($revenue * (1 + TAX_RATE), 2);
        $photos = !empty($j['job_photos']) ? json_decode($j['job_photos'], true) : [];
      ?>
      <tr class="hover:bg-gray-50">
        <td class="px-2 py-2 font-mono text-xs"><?= date('d.m', strtotime($j['j_date'])) ?></td>
        <td class="px-2 py-2">
          <a href="/admin/view-customer.php?id=<?= $j['customer_id_fk'] ?>" class="text-brand hover:underline text-sm"><?= e($j['cname']) ?></a>
          <div class="text-xs text-gray-400"><?= e($j['stitle']) ?><?= $j['job_note'] ? ' — '.e(mb_substr($j['job_note'],0,30)) : '' ?></div>
        </td>
        <td class="px-2 py-2 text-sm">
          <select onchange="assignPartner(<?= $j['j_id'] ?>, this.value)" class="bg-transparent border-0 text-sm p-0 cursor-pointer hover:bg-gray-100 rounded focus:ring-1 focus:ring-brand w-full">
            <option value="">—</option>
            <?php foreach ($employees as $emp): ?>
            <option value="<?= $emp['emp_id'] ?>" <?= $j['emp_id_fk']==$emp['emp_id']?'selected':'' ?>><?= e($emp['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td class="px-2 py-2 font-mono text-xs">
          <div class="flex flex-col gap-0.5 items-start">
            <div class="flex items-center gap-0.5">
              <input type="time" step="1" value="<?= $j['start_time'] ? substr($j['start_time'],0,8) : '' ?>" onchange="updateJobTime(<?= $j['j_id'] ?>, 'start_time', this.value)" class="bg-transparent border-0 text-xs p-0 w-20 cursor-pointer hover:bg-gray-100 rounded focus:ring-1 focus:ring-brand" title="Startzeit"/>
              <span>–</span>
              <input type="time" step="1" value="<?= $j['end_time'] ? substr($j['end_time'],0,8) : '' ?>" onchange="updateJobTime(<?= $j['j_id'] ?>, 'end_time', this.value)" class="bg-transparent border-0 text-xs p-0 w-20 cursor-pointer hover:bg-gray-100 rounded focus:ring-1 focus:ring-brand" title="Endzeit"/>
            </div>
            <?php if ($realHrs !== null): ?>
              <div class="text-[10px] text-gray-400">Δ <?= number_format($realHrs, 2) ?>h</div>
            <?php endif; ?>
          </div>
        </td>
        <td class="px-2 py-2 text-center">
          <?php if (!empty($photos)): ?>
            <div class="inline-flex items-center gap-1">
              <?php foreach (array_slice($photos, 0, 3) as $ph):
                  $src = is_string($ph) ? $ph : ($ph['url'] ?? $ph['path'] ?? '');
                  if (!$src) continue;
                  if ($src[0] !== '/' && !str_starts_with($src, 'http')) $src = '/uploads/' . ltrim($src, '/');
              ?>
                <a href="<?= e($src) ?>" target="_blank" class="inline-block">
                  <img src="<?= e($src) ?>" class="w-8 h-8 rounded object-cover border hover:scale-150 transition" loading="lazy" title="<?= e(basename($src)) ?>"/>
                </a>
              <?php endforeach; ?>
              <?php if (count($photos) > 3): ?>
                <span class="text-[10px] text-gray-500 font-semibold">+<?= count($photos) - 3 ?></span>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <span class="text-gray-300 text-xs">—</span>
          <?php endif; ?>
        </td>
        <td class="px-2 py-2 text-xs text-gray-600 max-w-[180px]">
          <?php if (!empty($j['job_note'])): ?>
            <div class="truncate" title="<?= e($j['job_note']) ?>"><?= e($j['job_note']) ?></div>
          <?php else: ?>
            <span class="text-gray-300">—</span>
          <?php endif; ?>
        </td>
        <td class="px-2 py-2 text-right text-sm"><?= number_format($empHrs, 2) ?></td>
        <td class="px-2 py-2 text-right text-sm"><?= number_format($custHrs, 1) ?></td>
        <td class="px-2 py-2 text-right text-red-600 text-sm"><?= money($salary) ?></td>
        <td class="px-2 py-2 text-right text-green-600 text-sm"><?= money($revenue) ?></td>
        <td class="px-2 py-2 text-right text-sm"><?= money($brutto) ?></td>
        <td class="px-2 py-2 text-right font-medium text-sm <?= $margin >= 0 ? 'text-brand' : 'text-red-600' ?>"><?= money($margin) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($jobs)): ?>
      <tr><td colspan="12" class="px-4 py-8 text-center text-gray-400">Keine erledigten Jobs im Zeitraum.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$apiKey = API_KEY;
$allSvcsJson = json_encode($services_list);
$script = <<<JS
function filterWH(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#whTable tbody tr').forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

function generateInvoice(custId, month) {
    if (!confirm('Rechnung für Monat ' + month + ' erstellen?')) return;
    fetch('/api/index.php?action=invoice/generate', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-API-Key':'{$apiKey}'},
        body:JSON.stringify({customer_id:custId, month:month})
    }).then(r=>r.json()).then(d=>{
        if(d.success) alert('Rechnung ' + d.data.invoice_number + ' erstellt!\\n' + d.data.jobs_count + ' Jobs, ' + d.data.hours + 'h, ' + d.data.total.toFixed(2) + ' €');
        else alert(d.error || 'Fehler');
    });
}

function assignPartner(jId, empId) {
    fetch('/api/index.php?action=jobs/assign', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-API-Key':'{$apiKey}'},
        body:JSON.stringify({j_id:jId, emp_id_fk:empId || null})
    }).then(r=>r.json()).then(d=>{
        if(!d.success) alert(d.error || 'Fehler');
    });
}

function updateJobTime(jId, field, value) {
    fetch('/api/index.php?action=jobs/update', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-API-Key':'{$apiKey}'},
        body:JSON.stringify({j_id:jId, field:field, value:value || null})
    }).then(r=>r.json()).then(d=>{
        if(d.success) {
            // Recalculate hours in the row
            const row = event.target.closest('tr');
            const startEl = row.querySelector('input[type=time]:first-of-type');
            const endEl = row.querySelector('input[type=time]:last-of-type');
            if (startEl && endEl && startEl.value && endEl.value) {
                const [sh,sm] = startEl.value.split(':').map(Number);
                const [eh,em] = endEl.value.split(':').map(Number);
                const hrs = ((eh*60+em) - (sh*60+sm)) / 60;
                if (hrs > 0) {
                    const cells = row.querySelectorAll('td');
                    cells[4].textContent = hrs.toFixed(1); // Std (P)
                }
            }
        } else {
            alert(d.error || 'Fehler');
        }
    });
}

function filterSvcByCustomer() {
    const custId = document.getElementById('whCust').value;
    const svcSel = document.getElementById('whSvc');
    if (!custId) {
        // Show all services
        svcSel.innerHTML = '<option value="">Alle</option>';
        const allSvcs = {$allSvcsJson};
        allSvcs.forEach(s => {
            const o = document.createElement('option');
            o.value = s.s_id;
            o.textContent = s.title;
            svcSel.appendChild(o);
        });
        return;
    }
    svcSel.innerHTML = '<option value="">Laden...</option>';
    fetch('/api/index.php?action=customer/services&customer_id=' + custId + '&key={$apiKey}')
        .then(r => r.json())
        .then(d => {
            svcSel.innerHTML = '<option value="">Alle</option>';
            if (d.success && d.data.length > 0) {
                d.data.forEach(s => {
                    const o = document.createElement('option');
                    o.value = s.s_id;
                    o.textContent = s.title + (s.street ? ' — ' + s.street : '');
                    svcSel.appendChild(o);
                });
            }
        })
        .catch(() => { svcSel.innerHTML = '<option value="">Fehler</option>'; });
}
JS;
include __DIR__ . '/../includes/footer.php'; ?>
