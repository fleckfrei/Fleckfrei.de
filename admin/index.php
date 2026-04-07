<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Dashboard'; $page = 'dashboard';

$today = date('Y-m-d');
$filterDate = $_GET['date'] ?? $today;
$month = date('Y-m', strtotime($filterDate));
$monthNames = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
$monthLabel = $monthNames[(int)date('n', strtotime($filterDate))-1] . ' ' . date('Y', strtotime($filterDate));

// Stats — only ACTIVE customers, clear labels
$s = [
    'today' => val("SELECT COUNT(*) FROM jobs WHERE j_date=? AND status=1", [$filterDate]),
    'pending' => val("SELECT COUNT(*) FROM jobs WHERE job_status='PENDING' AND status=1 AND j_date>=?", [$today]),
    'running' => val("SELECT COUNT(*) FROM jobs WHERE (job_status='RUNNING' OR job_status='STARTED') AND status=1"),
    'completed_month' => val("SELECT COUNT(*) FROM jobs WHERE job_status='COMPLETED' AND j_date LIKE ? AND status=1", ["$month%"]),
    'customers_active' => val("SELECT COUNT(*) FROM customer WHERE status=1"),
    'employees' => val("SELECT COUNT(*) FROM employee WHERE status=1"),
    'unpaid_count' => val("SELECT COUNT(*) FROM invoices WHERE invoice_paid='no' AND remaining_price > 0"),
    'unpaid_netto' => val("SELECT COALESCE(SUM(remaining_price),0) FROM invoices WHERE invoice_paid='no' AND remaining_price > 0"),
    'revenue_month' => val("SELECT COALESCE(SUM(total_price),0) FROM invoices WHERE invoice_paid='yes' AND issue_date LIKE ?", ["$month%"]),
];
$unpaidBrutto = round($s['unpaid_netto'] * 1.19, 2);

// Data for dropdowns
$allEmployees = all("SELECT emp_id, name, surname FROM employee WHERE status=1 ORDER BY name");
$allCustomers = all("SELECT customer_id, name, customer_type FROM customer WHERE status=1 ORDER BY name");
$allServices = all("SELECT s_id, title FROM services WHERE status=1 ORDER BY title");

// Today's jobs with details
$jobs = all("
    SELECT j.*, c.name as cname, c.customer_type as ctype,
           e.name as ename, e.surname as esurname,
           s.title as stitle, s.street, s.city, s.total_price as sprice
    FROM jobs j
    LEFT JOIN customer c ON j.customer_id_fk = c.customer_id
    LEFT JOIN employee e ON j.emp_id_fk = e.emp_id
    LEFT JOIN services s ON j.s_id_fk = s.s_id
    WHERE j.j_date = ? AND j.status = 1
    ORDER BY j.j_time
", [$filterDate]);

// Unassigned jobs (no employee)
$unassigned = val("SELECT COUNT(*) FROM jobs WHERE emp_id_fk IS NULL AND job_status='PENDING' AND status=1 AND j_date >= ?", [$today]);

// Jobs per day this month (for chart)
$dailyJobs = all("SELECT j_date, COUNT(*) as cnt, SUM(CASE WHEN job_status='COMPLETED' THEN 1 ELSE 0 END) as done, SUM(CASE WHEN job_status='CANCELLED' THEN 1 ELSE 0 END) as cancelled FROM jobs WHERE j_date LIKE ? AND status=1 GROUP BY j_date ORDER BY j_date", ["$month%"]);

// === Extended KPIs ===
// Previous month comparison
$prevMonth = date('Y-m', strtotime("$month-01 -1 month"));
$prevCompleted = val("SELECT COUNT(*) FROM jobs WHERE job_status='COMPLETED' AND j_date LIKE ? AND status=1", ["$prevMonth%"]);
$prevRevenue = val("SELECT COALESCE(SUM(total_price),0) FROM invoices WHERE invoice_paid='yes' AND issue_date LIKE ?", ["$prevMonth%"]);
$jobsDelta = $s['completed_month'] - $prevCompleted;
$revenueDelta = $s['revenue_month'] - $prevRevenue;

// Partner performance this month
$partnerPerf = all("SELECT e.emp_id, e.name, e.surname,
    COUNT(j.j_id) as jobs_count,
    COALESCE(SUM(COALESCE(j.total_hours, j.j_hours)),0) as total_hours,
    SUM(CASE WHEN j.job_status='CANCELLED' THEN 1 ELSE 0 END) as cancelled
    FROM employee e
    LEFT JOIN jobs j ON j.emp_id_fk=e.emp_id AND j.j_date LIKE ? AND j.status=1
    WHERE e.status=1
    GROUP BY e.emp_id ORDER BY jobs_count DESC", ["$month%"]);

// Top 5 customers by revenue this month
$topCustomers = all("SELECT c.name, c.customer_type,
    COUNT(j.j_id) as jobs_count,
    COALESCE(SUM(GREATEST(COALESCE(j.total_hours, j.j_hours), 2) * COALESCE(s.total_price,0)),0) as revenue
    FROM customer c
    JOIN jobs j ON j.customer_id_fk=c.customer_id AND j.job_status='COMPLETED' AND j.j_date LIKE ? AND j.status=1
    LEFT JOIN services s ON j.s_id_fk=s.s_id
    WHERE c.status=1
    GROUP BY c.customer_id ORDER BY revenue DESC LIMIT 5", ["$month%"]);

// Messages unread
$unreadMsgs = 0;
try { $unreadMsgs = val("SELECT COUNT(*) FROM messages WHERE recipient_type='admin' AND read_at IS NULL") ?: 0; } catch (Exception $e) {}

include __DIR__ . '/../includes/layout.php';
?>

<!-- Stats Grid -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <!-- Jobs heute -->
  <div class="bg-white rounded-xl border border-gray-200 p-5 relative overflow-hidden">
    <div class="absolute top-0 left-0 w-1 h-full bg-brand"></div>
    <div class="flex items-start justify-between">
      <div>
        <div class="text-3xl font-bold text-gray-900"><?= $s['today'] ?></div>
        <div class="text-sm font-medium text-gray-500 mt-1">Jobs heute</div>
        <div class="text-xs text-gray-400 mt-0.5"><?= date('d.m.Y', strtotime($filterDate)) ?></div>
      </div>
      <div class="w-10 h-10 rounded-lg bg-brand/10 flex items-center justify-center">
        <svg class="w-5 h-5 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
      </div>
    </div>
  </div>
  <!-- Offene Jobs -->
  <div class="bg-white rounded-xl border border-gray-200 p-5 relative overflow-hidden">
    <div class="absolute top-0 left-0 w-1 h-full bg-amber-500"></div>
    <div class="flex items-start justify-between">
      <div>
        <div class="text-3xl font-bold text-gray-900"><?= $s['pending'] ?></div>
        <div class="text-sm font-medium text-gray-500 mt-1">Offene Jobs</div>
        <div class="text-xs text-gray-400 mt-0.5">Wartend auf Erledigung</div>
      </div>
      <div class="w-10 h-10 rounded-lg bg-amber-50 flex items-center justify-center">
        <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
    </div>
  </div>
  <!-- Jobs aktiv -->
  <div class="bg-white rounded-xl border border-gray-200 p-5 relative overflow-hidden">
    <div class="absolute top-0 left-0 w-1 h-full bg-blue-500"></div>
    <div class="flex items-start justify-between">
      <div>
        <div class="text-3xl font-bold text-gray-900"><?= $s['running'] ?></div>
        <div class="text-sm font-medium text-gray-500 mt-1">Jobs aktiv</div>
        <div class="text-xs text-gray-400 mt-0.5">Gerade gestartet / laufend</div>
      </div>
      <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center">
        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
      </div>
    </div>
  </div>
  <!-- Erledigt im Monat -->
  <div class="bg-white rounded-xl border border-gray-200 p-5 relative overflow-hidden">
    <div class="absolute top-0 left-0 w-1 h-full bg-green-500"></div>
    <div class="flex items-start justify-between">
      <div>
        <div class="text-3xl font-bold text-gray-900"><?= $s['completed_month'] ?></div>
        <div class="text-sm font-medium text-gray-500 mt-1">Erledigt</div>
        <div class="text-xs text-gray-400 mt-0.5">Jobs im <?= $monthLabel ?></div>
      </div>
      <div class="w-10 h-10 rounded-lg bg-green-50 flex items-center justify-center">
        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
    </div>
  </div>
  <!-- Aktive Kunden -->
  <div class="bg-white rounded-xl border border-gray-200 p-5 relative overflow-hidden">
    <div class="absolute top-0 left-0 w-1 h-full bg-indigo-400"></div>
    <div class="flex items-start justify-between">
      <div>
        <div class="text-3xl font-bold text-gray-900"><?= $s['customers_active'] ?></div>
        <div class="text-sm font-medium text-gray-500 mt-1">Aktive Kunden</div>
      </div>
      <div class="w-10 h-10 rounded-lg bg-indigo-50 flex items-center justify-center">
        <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      </div>
    </div>
  </div>
  <!-- Mitarbeiter -->
  <div class="bg-white rounded-xl border border-gray-200 p-5 relative overflow-hidden">
    <div class="absolute top-0 left-0 w-1 h-full bg-violet-400"></div>
    <div class="flex items-start justify-between">
      <div>
        <div class="text-3xl font-bold text-gray-900"><?= $s['employees'] ?></div>
        <div class="text-sm font-medium text-gray-500 mt-1">Mitarbeiter</div>
      </div>
      <div class="w-10 h-10 rounded-lg bg-violet-50 flex items-center justify-center">
        <svg class="w-5 h-5 text-violet-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
      </div>
    </div>
  </div>
  <!-- Offene Rechnungen mit Betrag -->
  <div class="bg-white rounded-xl border border-gray-200 p-5 relative overflow-hidden">
    <div class="absolute top-0 left-0 w-1 h-full bg-red-500"></div>
    <div class="flex items-start justify-between">
      <div>
        <div class="text-3xl font-bold text-gray-900"><?= $s['unpaid_count'] ?></div>
        <div class="text-sm font-medium text-gray-500 mt-1">Offene Rechnungen</div>
        <div class="text-xs mt-1">
          <span class="text-red-600 font-semibold"><?= money($unpaidBrutto) ?></span>
          <span class="text-gray-400">brutto</span>
        </div>
        <div class="text-xs text-gray-400"><?= money($s['unpaid_netto']) ?> netto</div>
      </div>
      <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center">
        <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>
      </div>
    </div>
  </div>
  <!-- Umsatz Monat -->
  <div class="bg-white rounded-xl border border-gray-200 p-5 relative overflow-hidden">
    <div class="absolute top-0 left-0 w-1 h-full bg-emerald-500"></div>
    <div class="flex items-start justify-between">
      <div>
        <div class="text-3xl font-bold text-emerald-700"><?= money($s['revenue_month']) ?></div>
        <div class="text-sm font-medium text-gray-500 mt-1">Umsatz</div>
        <div class="text-xs text-gray-400 mt-0.5"><?= $monthLabel ?></div>
      </div>
      <div class="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center">
        <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
    </div>
  </div>
</div>

<?php if ($unassigned > 0): ?>
<div class="bg-yellow-50 border border-yellow-300 rounded-xl p-4 mb-6 flex items-center gap-3">
  <svg class="w-6 h-6 text-yellow-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
  <span class="text-yellow-800 font-medium"><?= $unassigned ?> Jobs ohne Mitarbeiter zugewiesen!</span>
  <a href="/admin/jobs.php?filter=unassigned" class="ml-auto text-sm font-medium text-brand hover:underline">Anzeigen &rarr;</a>
</div>
<?php endif; ?>

<!-- Chart + Jobs Table -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
  <div class="lg:col-span-1 bg-white rounded-xl border p-5">
    <h3 class="font-semibold text-gray-900 mb-1">Jobs im <?= $monthLabel ?></h3>
    <p class="text-xs text-gray-400 mb-4">Gesamt, Erledigt & Storniert pro Tag</p>
    <div style="position:relative;height:280px;"><canvas id="monthChart"></canvas></div>
  </div>
  <div class="lg:col-span-2 bg-white rounded-xl border">
    <div class="p-5 border-b flex items-center justify-between">
      <h3 class="font-semibold text-gray-900">Jobs am <?= date('d.m.Y', strtotime($filterDate)) ?> (<?= count($jobs) ?>)</h3>
      <form class="flex gap-2">
        <input type="date" name="date" value="<?= e($filterDate) ?>" class="px-3 py-1.5 border rounded-lg text-sm" onchange="this.form.submit()"/>
      </form>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50"><tr>
          <th class="px-3 py-3 text-left font-medium text-gray-600">Zeit</th>
          <th class="px-3 py-3 text-left font-medium text-gray-600">Service</th>
          <th class="px-3 py-3 text-left font-medium text-gray-600">Kunde</th>
          <th class="px-3 py-3 text-left font-medium text-gray-600">Mitarbeiter</th>
          <th class="px-3 py-3 text-left font-medium text-gray-600">Std</th>
          <th class="px-3 py-3 text-left font-medium text-gray-600">Status</th>
          <th class="px-3 py-3 text-left font-medium text-gray-600">Aktionen</th>
        </tr></thead>
        <tbody class="divide-y">
        <?php foreach ($jobs as $j):
          $realHours = $j['total_hours'] ?: $j['j_hours'];
          $customerHours = max(2, $realHours);
          $displayHours = $realHours < 2 && $realHours > 0 ? round($realHours,1)."h <span class='text-xs text-gray-400'>(→2h)</span>" : round($realHours,1)."h";
          $statusColors = ['PENDING'=>'text-yellow-700 bg-yellow-50 border-yellow-200','CONFIRMED'=>'text-blue-700 bg-blue-50 border-blue-200','RUNNING'=>'text-indigo-700 bg-indigo-50 border-indigo-200','STARTED'=>'text-indigo-700 bg-indigo-50 border-indigo-200','COMPLETED'=>'text-green-700 bg-green-50 border-green-200','CANCELLED'=>'text-red-700 bg-red-50 border-red-200'];
          $sc = $statusColors[$j['job_status']] ?? 'text-gray-700 bg-gray-50 border-gray-200';
        ?>
          <tr class="hover:bg-gray-50" id="job-row-<?= $j['j_id'] ?>">
            <!-- Zeit -->
            <td class="px-2 py-2">
              <input type="time" value="<?= substr($j['j_time'],0,5) ?>" onchange="updateJobField(<?= $j['j_id'] ?>,'j_time',this.value)" class="w-[72px] px-1.5 py-1.5 text-xs font-mono border border-gray-200 rounded-lg bg-white cursor-pointer hover:border-brand focus:border-brand focus:ring-1 focus:ring-brand/30 transition"/>
            </td>
            <!-- Service Dropdown -->
            <td class="px-2 py-2">
              <select onchange="updateJobField(<?= $j['j_id'] ?>,'s_id_fk',this.value)" class="w-full px-1.5 py-1.5 text-xs border border-gray-200 rounded-lg bg-white cursor-pointer hover:border-brand focus:border-brand focus:ring-1 focus:ring-brand/30 transition">
                <option value="">—</option>
                <?php foreach ($allServices as $sv): ?>
                <option value="<?= $sv['s_id'] ?>" <?= $sv['s_id']==$j['s_id_fk']?'selected':'' ?>><?= e($sv['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <!-- Kunde Dropdown -->
            <td class="px-2 py-2">
              <select onchange="updateJobField(<?= $j['j_id'] ?>,'customer_id_fk',this.value)" class="w-full px-1.5 py-1.5 text-xs border border-gray-200 rounded-lg bg-white cursor-pointer hover:border-brand focus:border-brand focus:ring-1 focus:ring-brand/30 transition">
                <option value="">—</option>
                <?php foreach ($allCustomers as $c): ?>
                <option value="<?= $c['customer_id'] ?>" <?= $c['customer_id']==$j['customer_id_fk']?'selected':'' ?>><?= e($c['name']) ?> (<?= e($c['customer_type']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </td>
            <!-- Mitarbeiter Dropdown -->
            <td class="px-2 py-2">
              <select onchange="updateJobEmployee(<?= $j['j_id'] ?>, this.value)" class="w-full px-1.5 py-1.5 text-xs border border-gray-200 rounded-lg bg-white cursor-pointer hover:border-brand focus:border-brand focus:ring-1 focus:ring-brand/30 transition">
                <option value="">— kein MA —</option>
                <?php foreach ($allEmployees as $emp): ?>
                <option value="<?= $emp['emp_id'] ?>" <?= $emp['emp_id']==$j['emp_id_fk']?'selected':'' ?>><?= e($emp['name'].' '.$emp['surname']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="px-2 py-3 text-xs"><?= $displayHours ?></td>
            <!-- Status Dropdown -->
            <td class="px-3 py-2">
              <select onchange="updateJobStatus(<?= $j['j_id'] ?>, this.value, this)" class="w-full px-2 py-1.5 text-xs font-medium border rounded-lg cursor-pointer transition <?= $sc ?>">
                <?php foreach (['PENDING'=>'Offen','CONFIRMED'=>'Bestätigt','RUNNING'=>'Laufend','COMPLETED'=>'Erledigt','CANCELLED'=>'Storniert'] as $sk=>$sv): ?>
                <option value="<?= $sk ?>" <?= $sk===$j['job_status']?'selected':'' ?>><?= $sv ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="px-3 py-3">
              <button onclick="showJobDetail(<?= htmlspecialchars(json_encode([
                'id'=>$j['j_id'],'date'=>$j['j_date'],'time'=>substr($j['j_time'],0,5),'hours'=>$j['j_hours'],
                'real_hours'=>$realHours,'customer_hours'=>$customerHours,
                'service'=>$j['stitle'],'customer'=>$j['cname'],'type'=>$j['ctype'],
                'employee'=>$j['emp_id_fk'] ? $j['ename'].' '.($j['esurname']??'') : 'Nicht zugewiesen',
                'status'=>$j['job_status'],'address'=>$j['address']??'','platform'=>$j['platform']??'',
                'start_time'=>$j['start_time']?substr($j['start_time'],0,5):'','end_time'=>$j['end_time']?substr($j['end_time'],0,5):'',
                'cancel_date'=>$j['cancel_date']??'','cancelled_role'=>$j['cancelled_role']??'','cancelled_by'=>$j['cancelled_by']??'',
                'note'=>$j['job_note']??'','code'=>$j['code_door']??'',
                'cancel_24h'=> $j['cancel_date'] ? (strtotime($j['j_date'].' '.$j['j_time']) - strtotime($j['cancel_date']) > 86400 ? 'Vor 24h (kostenlos)' : 'Nach 24h (Storno-Gebühr!)') : '',
              ],JSON_HEX_APOS|JSON_HEX_QUOT)) ?>)" class="px-2.5 py-1.5 text-xs bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 font-medium">Details</button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($jobs)): ?>
          <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Keine Jobs an diesem Tag.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Month Comparison -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
  <!-- Monatsvergleich -->
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-4">Monatsvergleich</h3>
    <div class="grid grid-cols-2 gap-4">
      <div class="bg-gray-50 rounded-lg p-4">
        <div class="text-sm text-gray-500 mb-1">Jobs erledigt</div>
        <div class="text-2xl font-bold"><?= $s['completed_month'] ?></div>
        <div class="text-xs mt-1 <?= $jobsDelta >= 0 ? 'text-green-600' : 'text-red-600' ?>">
          <?= $jobsDelta >= 0 ? '+' : '' ?><?= $jobsDelta ?> vs. Vormonat (<?= $prevCompleted ?>)
        </div>
      </div>
      <div class="bg-gray-50 rounded-lg p-4">
        <div class="text-sm text-gray-500 mb-1">Umsatz bezahlt</div>
        <div class="text-2xl font-bold text-brand"><?= money($s['revenue_month']) ?></div>
        <div class="text-xs mt-1 <?= $revenueDelta >= 0 ? 'text-green-600' : 'text-red-600' ?>">
          <?= $revenueDelta >= 0 ? '+' : '' ?><?= money($revenueDelta) ?> vs. Vormonat
        </div>
      </div>
    </div>
    <?php if ($unreadMsgs > 0): ?>
    <div class="mt-4 bg-blue-50 border border-blue-200 rounded-lg p-3 flex items-center justify-between">
      <span class="text-sm text-blue-800 font-medium"><?= $unreadMsgs ?> ungelesene Nachricht(en)</span>
      <a href="/admin/messages.php" class="text-xs text-brand font-medium hover:underline">Ansehen &rarr;</a>
    </div>
    <?php endif; ?>
  </div>

  <!-- Partner Performance -->
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-4">Partner-Performance (<?= $monthLabel ?>)</h3>
    <div class="space-y-3">
      <?php foreach ($partnerPerf as $pp):
        $pctBar = $pp['jobs_count'] > 0 ? min(100, ($pp['jobs_count'] / max(1, $s['completed_month'] + $s['pending'])) * 100) : 0;
      ?>
      <div>
        <div class="flex items-center justify-between mb-1">
          <span class="text-sm font-medium"><?= e($pp['name'].' '.($pp['surname']??'')) ?></span>
          <span class="text-xs text-gray-500"><?= $pp['jobs_count'] ?> Jobs &middot; <?= round($pp['total_hours'],1) ?>h<?= $pp['cancelled'] > 0 ? ' &middot; <span class="text-red-500">'.$pp['cancelled'].' storn.</span>' : '' ?></span>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-2">
          <div class="bg-brand rounded-full h-2" style="width:<?= $pctBar ?>%"></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($partnerPerf)): ?>
      <p class="text-gray-400 text-sm">Keine Partner-Daten.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!empty($topCustomers)): ?>
<!-- Top Kunden -->
<div class="bg-white rounded-xl border p-5 mb-6">
  <h3 class="font-semibold mb-4">Top Kunden nach Umsatz (<?= $monthLabel ?>)</h3>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-2 text-left font-medium text-gray-600">#</th>
        <th class="px-4 py-2 text-left font-medium text-gray-600">Kunde</th>
        <th class="px-4 py-2 text-left font-medium text-gray-600">Typ</th>
        <th class="px-4 py-2 text-left font-medium text-gray-600">Jobs</th>
        <th class="px-4 py-2 text-left font-medium text-gray-600">Umsatz</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($topCustomers as $i => $tc): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-2 text-gray-400"><?= $i+1 ?></td>
        <td class="px-4 py-2 font-medium"><?= e($tc['name']) ?></td>
        <td class="px-4 py-2 text-xs text-gray-500"><?= e($tc['customer_type']) ?></td>
        <td class="px-4 py-2"><?= $tc['jobs_count'] ?></td>
        <td class="px-4 py-2 font-bold text-brand"><?= money($tc['revenue']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Job Detail Popup -->
<div id="jobDetailModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden overflow-y-auto py-4">
  <div class="bg-white rounded-2xl p-6 w-full max-w-lg shadow-2xl m-4">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-lg font-semibold" id="jdTitle">Job Details</h3>
      <button onclick="document.getElementById('jobDetailModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
    </div>
    <div id="jdBody" class="space-y-2 text-sm"></div>
    <div class="flex gap-2 mt-4">
      <a id="jdEditLink" href="#" class="flex-1 px-4 py-2 bg-brand text-white rounded-xl text-center font-medium">Bearbeiten</a>
      <button onclick="document.getElementById('jobDetailModal').classList.add('hidden')" class="flex-1 px-4 py-2 border rounded-xl text-gray-600">Schliessen</button>
    </div>
  </div>
</div>

<!-- Assign Modal -->
<div id="assignModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center hidden">
  <div class="bg-white rounded-2xl p-6 w-full max-w-md shadow-2xl">
    <h3 class="text-lg font-semibold mb-4">Mitarbeiter zuweisen</h3>
    <form id="assignForm" onsubmit="submitAssign(event)">
      <input type="hidden" name="j_id" id="assign_jid"/>
      <select name="emp_id" id="assign_emp" class="w-full px-4 py-3 border rounded-xl mb-4" required>
        <option value="">Mitarbeiter wählen...</option>
        <?php foreach (all("SELECT emp_id, name, surname FROM employee WHERE status=1 ORDER BY name") as $emp): ?>
        <option value="<?= $emp['emp_id'] ?>"><?= e($emp['name'].' '.$emp['surname']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="flex gap-3">
        <button type="button" onclick="closeAssign()" class="flex-1 px-4 py-2.5 border rounded-xl text-gray-600">Abbrechen</button>
        <button type="submit" class="flex-1 px-4 py-2.5 bg-brand text-white rounded-xl font-medium">Zuweisen</button>
      </div>
    </form>
  </div>
</div>

<?php
$chartLabels = json_encode(array_map(fn($d) => date('d.', strtotime($d['j_date'])), $dailyJobs));
$chartData = json_encode(array_map('intval', array_column($dailyJobs, 'cnt')));
$chartDone = json_encode(array_map('intval', array_column($dailyJobs, 'done')));
$chartCancelled = json_encode(array_map('intval', array_column($dailyJobs, 'cancelled')));
$apiKeyJs = API_KEY;

$script = <<<JS
// Month chart — professional
new Chart(document.getElementById('monthChart'), {
    type: 'bar',
    data: {
        labels: $chartLabels,
        datasets: [
            {
                label: 'Gesamt',
                data: $chartData,
                backgroundColor: 'rgba(46,125,107,0.15)',
                borderColor: 'rgba(46,125,107,0.4)',
                borderWidth: 1,
                borderRadius: 6,
                borderSkipped: false
            },
            {
                label: 'Erledigt',
                data: $chartDone,
                backgroundColor: '#2E7D6B',
                borderRadius: 6,
                borderSkipped: false
            },
            {
                label: 'Storniert',
                data: $chartCancelled,
                backgroundColor: '#EF4444',
                borderRadius: 6,
                borderSkipped: false
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                position: 'bottom',
                labels: { usePointStyle: true, pointStyle: 'circle', padding: 16, font: { size: 11, family: 'Inter' } }
            },
            tooltip: {
                backgroundColor: '#1f2937',
                titleFont: { family: 'Inter', size: 12 },
                bodyFont: { family: 'Inter', size: 11 },
                padding: 10,
                cornerRadius: 8,
                displayColors: true,
                callbacks: {
                    title: function(items) { return 'Tag ' + items[0].label; }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1, font: { size: 10, family: 'Inter' }, color: '#9ca3af' },
                grid: { color: 'rgba(0,0,0,0.04)', drawBorder: false }
            },
            x: {
                ticks: { font: { size: 10, family: 'Inter' }, color: '#9ca3af' },
                grid: { display: false }
            }
        }
    }
});

// Job detail popup
function showJobDetail(j) {
    document.getElementById('jdTitle').textContent = 'Job #' + j.id + ' — ' + j.service;
    document.getElementById('jdEditLink').href = '/admin/jobs.php?view=' + j.id;
    let html = '<table class="w-full">';
    const r = (l,v,cls) => '<tr><td class="py-1 text-gray-500 pr-3">' + l + '</td><td class="py-1 font-medium ' + (cls||'') + '">' + (v||'-') + '</td></tr>';
    html += r('Datum', j.date);
    html += r('Zeit', j.time + (j.end_time ? ' — ' + j.end_time : ''));
    html += r('Geplant', j.hours + 'h');
    html += r('Tatsächlich', j.real_hours ? (j.real_hours < 2 ? j.real_hours + 'h → <strong>2h (Min.)</strong>' : j.real_hours + 'h') : '-');
    html += r('Kunden-Stunden', j.customer_hours + 'h', 'text-brand');
    html += r('Kunde', j.customer + ' (' + j.type + ')');
    html += r('Mitarbeiter', j.employee);
    html += r('Status', j.status, j.status==='CANCELLED'?'text-red-600':j.status==='COMPLETED'?'text-green-600':'');
    html += r('Adresse', j.address);
    html += r('Türcode', j.code);
    html += r('Plattform', j.platform);
    if (j.start_time) html += r('Gestartet', j.start_time, 'text-green-600');
    if (j.end_time) html += r('Beendet', j.end_time, 'text-blue-600');
    if (j.note) html += r('Notiz', j.note);
    if (j.status === 'CANCELLED') {
        html += '<tr><td colspan="2" class="pt-3"><div class="bg-red-50 rounded-lg p-3">';
        html += '<div class="text-red-800 font-semibold">Stornierung</div>';
        html += '<div class="text-sm">Datum: ' + (j.cancel_date || '?') + '</div>';
        html += '<div class="text-sm">Von: <strong>' + (j.cancelled_role || '?') + '</strong></div>';
        html += '<div class="text-sm font-bold">' + (j.cancel_24h || '') + '</div>';
        html += '</div></td></tr>';
    }
    html += '</table>';
    document.getElementById('jdBody').innerHTML = html;
    document.getElementById('jobDetailModal').classList.remove('hidden');
}

const API_KEY = '{$apiKeyJs}';

// Update job status via dropdown — saves to DB instantly
function updateJobStatus(jid, newStatus, selectEl) {
    if (newStatus === 'CANCELLED' && !confirm('Job wirklich stornieren?')) {
        location.reload(); return;
    }
    selectEl.disabled = true;
    selectEl.style.opacity = '0.5';
    fetch('/api/index.php?action=jobs/status', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-API-Key': API_KEY },
        body: JSON.stringify({ j_id: jid, status: newStatus })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            // Flash green to confirm save
            const row = document.getElementById('job-row-' + jid);
            row.style.transition = 'background 0.3s';
            row.style.background = '#dcfce7';
            setTimeout(() => { row.style.background = ''; }, 1000);
            // Update dropdown styling
            const colors = {PENDING:'text-yellow-700 bg-yellow-50 border-yellow-200',CONFIRMED:'text-blue-700 bg-blue-50 border-blue-200',RUNNING:'text-indigo-700 bg-indigo-50 border-indigo-200',STARTED:'text-indigo-700 bg-indigo-50 border-indigo-200',COMPLETED:'text-green-700 bg-green-50 border-green-200',CANCELLED:'text-red-700 bg-red-50 border-red-200'};
            selectEl.className = 'w-full px-2 py-1.5 text-xs font-medium border rounded-lg cursor-pointer transition ' + (colors[newStatus]||'');
        } else {
            alert(d.error || 'Fehler beim Speichern');
            location.reload();
        }
        selectEl.disabled = false;
        selectEl.style.opacity = '1';
    }).catch(() => { selectEl.disabled = false; selectEl.style.opacity = '1'; alert('Netzwerkfehler'); });
}

// Update any job field — saves to DB instantly
function updateJobField(jid, field, value) {
    fetch('/api/index.php?action=jobs/update', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-API-Key': API_KEY },
        body: JSON.stringify({ j_id: jid, field: field, value: value })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            const row = document.getElementById('job-row-' + jid);
            row.style.transition = 'background 0.3s';
            row.style.background = '#dcfce7';
            setTimeout(() => { row.style.background = ''; }, 800);
        } else {
            alert(d.error || 'Fehler'); location.reload();
        }
    }).catch(() => alert('Netzwerkfehler'));
}

// Update job employee via dropdown — saves to DB instantly
function updateJobEmployee(jid, empId) {
    fetch('/api/index.php?action=jobs/assign', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-API-Key': API_KEY },
        body: JSON.stringify({ j_id: jid, emp_id_fk: empId ? parseInt(empId) : null })
    }).then(r => r.json()).then(d => {
        if (d.success) {
            const row = document.getElementById('job-row-' + jid);
            row.style.transition = 'background 0.3s';
            row.style.background = '#dcfce7';
            setTimeout(() => { row.style.background = ''; }, 1000);
        } else {
            alert(d.error || 'Fehler beim Speichern');
            location.reload();
        }
    }).catch(() => alert('Netzwerkfehler'));
}

// Assign job (legacy modal - still used)
function assignJob(jid) {
    document.getElementById('assign_jid').value = jid;
    document.getElementById('assignModal').classList.remove('hidden');
}
function closeAssign() { document.getElementById('assignModal').classList.add('hidden'); }
function submitAssign(e) {
    e.preventDefault();
    const jid = document.getElementById('assign_jid').value;
    const empId = document.getElementById('assign_emp').value;
    fetch('/api/index.php?action=jobs/assign', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-API-Key': API_KEY },
        body: JSON.stringify({ j_id: parseInt(jid), emp_id_fk: parseInt(empId) })
    }).then(r => r.json()).then(d => {
        if (d.success) location.reload();
        else alert(d.error || 'Fehler');
    });
}
JS;
include __DIR__ . '/../includes/footer.php';
?>
