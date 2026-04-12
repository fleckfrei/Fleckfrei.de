<?php
/**
 * OSI VULTURE — Unified Person Dossier
 * One page, ALL data ever collected about a target.
 * Aggregates: osint_scans, customer, employee, jobs, invoices, addresses, notes, deep_scan.
 *
 * Usage: /admin/vulture.php?q=email@example.com
 *        /admin/vulture.php?cid=123
 *        /admin/vulture.php?name=Max+Mustermann
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'OSI Vulture'; $page = 'scanner';

$query = trim($_GET['q'] ?? '');
$cid = (int)($_GET['cid'] ?? 0);
$nameQ = trim($_GET['name'] ?? '');

// Resolve target from customer ID
if ($cid) {
    $cust = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);
    if ($cust) {
        $query = $cust['email'] ?: $cust['name'];
        $nameQ = $cust['name'];
    }
}

if (!$query && !$nameQ) {
    // Show search form
    include __DIR__ . '/../includes/layout.php';
    ?>
    <div class="max-w-xl mx-auto py-20">
      <div class="text-center mb-8">
        <div class="text-6xl mb-4">🦅</div>
        <h1 class="text-3xl font-extrabold text-gray-900">OSI Vulture</h1>
        <p class="text-gray-500 mt-2">Komplettes Dossier — alles was je über eine Person gesammelt wurde.</p>
      </div>
      <form method="GET" class="space-y-3">
        <input name="q" placeholder="Email, Name oder Telefon..." autofocus required class="w-full px-5 py-4 border-2 border-gray-200 rounded-2xl text-lg focus:border-brand focus:ring-4 focus:ring-brand/10 outline-none"/>
        <button type="submit" class="w-full py-4 bg-gray-900 hover:bg-gray-800 text-white rounded-2xl font-bold text-lg transition">Dossier erstellen</button>
      </form>
      <div class="mt-6 text-center">
        <p class="text-xs text-gray-400">Oder: <code>?cid=123</code> für Kunde, <code>?name=Max</code> für Name</p>
      </div>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// ============================================================
// AGGREGATION: Collect ALL data for this target
// ============================================================
$target = $query ?: $nameQ;
$isEmail = str_contains($target, '@');
$phoneClean = preg_replace('/[^0-9]/', '', $target);
$isPhone = strlen($phoneClean) >= 8 && !$isEmail;

// 1) All OSINT scans matching this target
$scans = [];
if ($isEmail) {
    $scans = all("SELECT * FROM osint_scans WHERE scan_email=? OR scan_name LIKE ? ORDER BY created_at DESC", [$target, '%'.str_replace('@','%',$target).'%']);
} elseif ($isPhone) {
    $scans = all("SELECT * FROM osint_scans WHERE scan_phone LIKE ? ORDER BY created_at DESC", ['%'.substr($phoneClean,-8).'%']);
} else {
    $scans = all("SELECT * FROM osint_scans WHERE scan_name LIKE ? OR scan_email LIKE ? ORDER BY created_at DESC", ['%'.$target.'%', '%'.$target.'%']);
}

// Merge all scan data into one combined profile
$mergedData = [];
$mergedDeep = [];
$allEmails = [];
$allPhones = [];
$allNames = [];
$allAddresses = [];
$scanTimeline = [];

foreach ($scans as $s) {
    if ($s['scan_email'] && !in_array($s['scan_email'], $allEmails)) $allEmails[] = $s['scan_email'];
    if ($s['scan_phone'] && !in_array($s['scan_phone'], $allPhones)) $allPhones[] = $s['scan_phone'];
    if ($s['scan_name'] && !in_array($s['scan_name'], $allNames)) $allNames[] = $s['scan_name'];
    if ($s['scan_address'] && !in_array($s['scan_address'], $allAddresses)) $allAddresses[] = $s['scan_address'];

    $scanTimeline[] = [
        'date' => $s['created_at'],
        'type' => 'scan',
        'label' => 'OSINT Scan #' . $s['scan_id'],
        'detail' => $s['scan_name'] . ($s['scan_email'] ? ' <' . $s['scan_email'] . '>' : ''),
    ];

    $d = json_decode($s['scan_data'] ?? '', true);
    if (is_array($d)) $mergedData = array_merge($mergedData, $d);

    $dd = json_decode($s['deep_scan_data'] ?? '', true);
    if (is_array($dd)) $mergedDeep = array_merge($mergedDeep, $dd);
}

// 2) DB: Customer records
$dbCustomers = [];
$where = []; $params = [];
foreach ($allEmails as $e) { $where[] = 'c.email=?'; $params[] = $e; }
foreach ($allNames as $n) { $where[] = 'c.name LIKE ?'; $params[] = '%'.$n.'%'; }
foreach ($allPhones as $p) { $ph = preg_replace('/[^0-9]/', '', $p); if (strlen($ph) >= 8) { $where[] = 'c.phone LIKE ?'; $params[] = '%'.substr($ph,-8).'%'; } }
if ($nameQ && !in_array($nameQ, $allNames)) { $where[] = 'c.name LIKE ?'; $params[] = '%'.$nameQ.'%'; }
if ($isEmail && !in_array($target, $allEmails)) { $where[] = 'c.email=?'; $params[] = $target; }

if (!empty($where)) {
    $dbCustomers = all("SELECT c.*, COUNT(j.j_id) as total_jobs, MAX(j.j_date) as last_job, MIN(j.j_date) as first_job
                        FROM customer c LEFT JOIN jobs j ON j.customer_id_fk=c.customer_id AND j.status=1
                        WHERE " . implode(' OR ', $where) . " GROUP BY c.customer_id ORDER BY c.customer_id DESC LIMIT 5", $params);
}

// 3) DB: Employee records
$dbEmployees = [];
$empWhere = []; $empParams = [];
foreach ($allEmails as $e) { $empWhere[] = 'email=?'; $empParams[] = $e; }
foreach ($allPhones as $p) { $ph = preg_replace('/[^0-9]/', '', $p); if (strlen($ph) >= 8) { $empWhere[] = 'phone LIKE ?'; $empParams[] = '%'.substr($ph,-8).'%'; } }
if (!empty($empWhere)) {
    $dbEmployees = all("SELECT * FROM employee WHERE " . implode(' OR ', $empWhere) . " LIMIT 5", $empParams);
}

// 4) DB: Jobs, Invoices, Services, Addresses for matched customers
$dbJobs = []; $dbInvoices = []; $dbServices = []; $dbAddresses = []; $dbStats = null;
if (!empty($dbCustomers)) {
    $mainCid = $dbCustomers[0]['customer_id'];
    $dbStats = one("SELECT
        (SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1) as total_jobs,
        (SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='COMPLETED') as done,
        (SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='CANCELLED') as cancelled,
        (SELECT COALESCE(SUM(total_price),0) FROM invoices WHERE customer_id_fk=?) as revenue,
        (SELECT COALESCE(SUM(remaining_price),0) FROM invoices WHERE customer_id_fk=? AND invoice_paid='no') as open_balance,
        (SELECT COUNT(*) FROM invoices WHERE customer_id_fk=?) as invoice_count",
        [$mainCid, $mainCid, $mainCid, $mainCid, $mainCid, $mainCid]);
    $dbJobs = all("SELECT j.j_id, j.j_date, j.j_time, j.job_status, j.j_hours, j.total_hours, j.address, j.platform,
                   s.title as stitle, e.display_name as ename
                   FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
                   WHERE j.customer_id_fk=? AND j.status=1 ORDER BY j.j_date DESC LIMIT 50", [$mainCid]);
    $dbInvoices = all("SELECT * FROM invoices WHERE customer_id_fk=? ORDER BY issue_date DESC LIMIT 20", [$mainCid]);
    $dbServices = all("SELECT * FROM services WHERE customer_id_fk=? AND status=1", [$mainCid]);
    $dbAddresses = all("SELECT * FROM customer_address WHERE customer_id_fk=?", [$mainCid]);

    // Add jobs to timeline
    foreach ($dbJobs as $j) {
        $scanTimeline[] = [
            'date' => $j['j_date'] . ' ' . ($j['j_time'] ?? '00:00'),
            'type' => 'job',
            'label' => 'Job #' . $j['j_id'] . ' ' . $j['job_status'],
            'detail' => ($j['stitle'] ?? 'Reinigung') . ($j['ename'] ? ' — ' . $j['ename'] : ''),
        ];
    }
    // Add invoices
    foreach ($dbInvoices as $inv) {
        $scanTimeline[] = [
            'date' => $inv['issue_date'] . ' 12:00',
            'type' => 'invoice',
            'label' => 'Rechnung ' . ($inv['invoice_number'] ?? '#'.$inv['inv_id']),
            'detail' => number_format($inv['total_price'], 2, ',', '.') . ' € — ' . ($inv['invoice_paid'] === 'yes' ? 'Bezahlt' : 'Offen'),
        ];
    }
}

// 5) Messages from local DB
$dbMessages = [];
if (!empty($dbCustomers)) {
    try {
        $dbMessages = allLocal("SELECT * FROM messages WHERE (sender_type='customer' AND sender_id=?) OR (recipient_type='customer' AND recipient_id=?) ORDER BY created_at DESC LIMIT 20",
            [$mainCid, $mainCid]);
    } catch (Exception $e) {}
}

// Sort timeline
usort($scanTimeline, fn($a, $b) => strcmp($b['date'], $a['date']));

// Email analysis
$emailInfo = null;
$primaryEmail = $allEmails[0] ?? ($isEmail ? $target : '');
if ($primaryEmail) {
    $domain = strtolower(substr($primaryEmail, strpos($primaryEmail, '@') + 1));
    $local = strtolower(substr($primaryEmail, 0, strpos($primaryEmail, '@')));
    $mx = @dns_get_record($domain, DNS_MX);
    $free = in_array($domain, ['gmail.com','yahoo.com','hotmail.com','outlook.com','gmx.de','web.de','t-online.de','aol.com','icloud.com','protonmail.com','mail.de','freenet.de','live.de','live.com','googlemail.com','posteo.de','mailbox.org']);
    $gravatar = 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($primaryEmail))) . '?d=404&s=120';
    $emailInfo = ['domain' => $domain, 'local' => $local, 'mx' => $mx ? count($mx) : 0, 'free' => $free, 'gravatar' => $gravatar, 'business' => !$free];
}

// Display name
$displayName = $allNames[0] ?? ($dbCustomers[0]['name'] ?? $target);
$primaryPhone = $allPhones[0] ?? ($dbCustomers[0]['phone'] ?? '');

include __DIR__ . '/../includes/layout.php';
?>

<style>
@media print { .no-print { display: none !important; } .print-break { page-break-before: always; } }
.vulture-section { @apply bg-white rounded-xl border mb-4 overflow-hidden; }
.vulture-header { @apply px-5 py-3 border-b bg-gray-50 flex items-center gap-3; }
.vulture-header h3 { @apply font-semibold text-gray-900 text-sm; }
.vulture-body { @apply p-5; }
.stat-box { @apply bg-gray-50 rounded-lg p-3 text-center; }
.stat-val { @apply text-xl font-bold text-gray-900; }
.stat-label { @apply text-[10px] font-semibold text-gray-500 uppercase tracking-wider mt-0.5; }
.tl-dot { @apply w-2.5 h-2.5 rounded-full flex-shrink-0 mt-1.5; }
.link-pill { @apply inline-block px-2 py-1 text-[11px] font-medium rounded-lg border border-gray-200 text-gray-700 hover:border-brand hover:text-brand hover:bg-brand/5 transition mr-1 mb-1; }
</style>

<!-- HEADER -->
<div class="bg-gradient-to-r from-gray-900 to-gray-800 rounded-2xl p-6 mb-6 text-white relative overflow-hidden">
  <div class="absolute top-0 right-0 text-[120px] opacity-5 leading-none">🦅</div>
  <div class="relative z-10 flex items-start gap-5">
    <?php if ($emailInfo): ?>
    <img src="<?= e($emailInfo['gravatar']) ?>" onerror="this.style.display='none'" class="w-20 h-20 rounded-2xl border-2 border-white/20 shadow-lg"/>
    <?php endif; ?>
    <div class="flex-1 min-w-0">
      <div class="text-[10px] uppercase tracking-widest text-gray-400 font-mono mb-1">OSI VULTURE DOSSIER</div>
      <h1 class="text-2xl sm:text-3xl font-extrabold truncate"><?= e($displayName) ?></h1>
      <div class="flex flex-wrap gap-3 mt-2 text-sm text-gray-300">
        <?php if ($primaryEmail): ?><span><?= e($primaryEmail) ?></span><?php endif; ?>
        <?php if ($primaryPhone): ?><span><?= e($primaryPhone) ?></span><?php endif; ?>
        <?php foreach ($allAddresses as $a): ?><span class="truncate max-w-[200px]"><?= e($a) ?></span><?php endforeach; ?>
      </div>
      <div class="flex flex-wrap gap-2 mt-3 text-xs">
        <span class="px-2 py-1 rounded bg-white/10"><?= count($scans) ?> Scans</span>
        <span class="px-2 py-1 rounded bg-white/10"><?= count($dbCustomers) ?> DB-Treffer</span>
        <span class="px-2 py-1 rounded bg-white/10"><?= count($dbJobs) ?> Jobs</span>
        <span class="px-2 py-1 rounded bg-white/10"><?= count($dbInvoices) ?> Rechnungen</span>
        <span class="px-2 py-1 rounded bg-white/10"><?= count($dbMessages) ?> Nachrichten</span>
        <?php if ($dbStats): ?><span class="px-2 py-1 rounded bg-green-500/20 text-green-300"><?= number_format($dbStats['revenue'], 2, ',', '.') ?> € Umsatz</span><?php endif; ?>
      </div>
    </div>
    <div class="no-print flex flex-col gap-2">
      <button onclick="window.print()" class="px-4 py-2 bg-white/10 hover:bg-white/20 rounded-lg text-xs font-semibold">PDF</button>
      <a href="/admin/scanner.php?scan_email=<?= urlencode($primaryEmail) ?>&scan_name=<?= urlencode($displayName) ?>" class="px-4 py-2 bg-brand hover:bg-brand/80 rounded-lg text-xs font-semibold text-center">Neuer Scan</a>
    </div>
  </div>
</div>

<!-- STATS ROW -->
<?php if ($dbStats): ?>
<div class="grid grid-cols-3 sm:grid-cols-6 gap-3 mb-6">
  <div class="stat-box"><div class="stat-val"><?= $dbStats['total_jobs'] ?></div><div class="stat-label">Jobs gesamt</div></div>
  <div class="stat-box"><div class="stat-val text-green-600"><?= $dbStats['done'] ?></div><div class="stat-label">Erledigt</div></div>
  <div class="stat-box"><div class="stat-val text-red-600"><?= $dbStats['cancelled'] ?></div><div class="stat-label">Storniert</div></div>
  <div class="stat-box"><div class="stat-val"><?= number_format($dbStats['revenue'], 0, ',', '.') ?> €</div><div class="stat-label">Umsatz</div></div>
  <div class="stat-box"><div class="stat-val <?= $dbStats['open_balance'] > 0 ? 'text-red-600' : 'text-green-600' ?>"><?= number_format($dbStats['open_balance'], 0, ',', '.') ?> €</div><div class="stat-label">Offen</div></div>
  <div class="stat-box"><div class="stat-val"><?= $dbStats['invoice_count'] ?></div><div class="stat-label">Rechnungen</div></div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

  <!-- LEFT: Identität + DB -->
  <div class="lg:col-span-2 space-y-4">

    <!-- CUSTOMER RECORDS -->
    <?php if (!empty($dbCustomers)): ?>
    <div class="vulture-section">
      <div class="vulture-header"><span class="text-lg">👤</span><h3>Kundendaten</h3></div>
      <div class="vulture-body">
        <?php foreach ($dbCustomers as $c): ?>
        <div class="mb-4 last:mb-0 p-4 bg-gray-50 rounded-xl">
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">Name</span><strong><?= e($c['name']) ?></strong></div>
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">Email</span><?= e($c['email'] ?? '—') ?></div>
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">Telefon</span><?= e($c['phone'] ?? '—') ?></div>
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">Typ</span><?= e($c['customer_type'] ?? '—') ?></div>
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">Seit</span><?= $c['created_at'] ? date('d.m.Y', strtotime($c['created_at'])) : '—' ?></div>
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">Letzter Job</span><?= $c['last_job'] ? date('d.m.Y', strtotime($c['last_job'])) : '—' ?></div>
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">Jobs</span><?= (int)$c['total_jobs'] ?></div>
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">Status</span><?= $c['status'] ? '✓ Aktiv' : '✕ Inaktiv' ?></div>
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">ID</span>#<?= $c['customer_id'] ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- EMPLOYEE RECORDS -->
    <?php if (!empty($dbEmployees)): ?>
    <div class="vulture-section">
      <div class="vulture-header"><span class="text-lg">🤝</span><h3>Partner-Datensatz</h3></div>
      <div class="vulture-body">
        <?php foreach ($dbEmployees as $emp): ?>
        <div class="p-4 bg-purple-50 rounded-xl">
          <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">Name</span><strong><?= e($emp['name'] . ' ' . ($emp['surname'] ?? '')) ?></strong></div>
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">Email</span><?= e($emp['email'] ?? '—') ?></div>
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">Telefon</span><?= e($emp['phone'] ?? '—') ?></div>
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">Typ</span><?= e($emp['employee_type'] ?? '—') ?></div>
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">Status</span><?= ($emp['status'] ?? 0) ? '✓ Aktiv' : '✕ Inaktiv' ?></div>
            <div><span class="text-[10px] text-gray-500 uppercase font-bold block">ID</span>#<?= $emp['emp_id'] ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- SERVICES -->
    <?php if (!empty($dbServices)): ?>
    <div class="vulture-section">
      <div class="vulture-header"><span class="text-lg">🏠</span><h3>Services / Unterkünfte (<?= count($dbServices) ?>)</h3></div>
      <div class="vulture-body divide-y">
        <?php foreach ($dbServices as $svc): ?>
        <div class="py-3 first:pt-0 last:pb-0 flex items-center justify-between">
          <div>
            <div class="font-semibold text-sm"><?= e($svc['title']) ?></div>
            <div class="text-xs text-gray-500"><?= e(trim(($svc['street'] ?? '') . ' ' . ($svc['number'] ?? '') . ', ' . ($svc['postal_code'] ?? '') . ' ' . ($svc['city'] ?? ''))) ?></div>
          </div>
          <div class="text-sm font-bold text-brand"><?= number_format($svc['total_price'] ?? 0, 2, ',', '.') ?> €/h</div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- JOBS -->
    <?php if (!empty($dbJobs)): ?>
    <div class="vulture-section">
      <div class="vulture-header"><span class="text-lg">📅</span><h3>Alle Jobs (<?= count($dbJobs) ?>)</h3></div>
      <div class="vulture-body overflow-x-auto">
        <table class="w-full text-xs">
          <thead><tr class="text-left text-gray-500 uppercase tracking-wider border-b">
            <th class="py-2 pr-3">Datum</th><th class="pr-3">Service</th><th class="pr-3">Partner</th><th class="pr-3">Status</th><th class="pr-3">Std</th><th>Plattform</th>
          </tr></thead>
          <tbody class="divide-y">
          <?php foreach ($dbJobs as $j):
            $sc = match($j['job_status']) { 'COMPLETED' => 'text-green-700', 'CANCELLED' => 'text-red-500 line-through', 'RUNNING' => 'text-amber-600', default => 'text-gray-700' };
          ?>
          <tr class="hover:bg-gray-50">
            <td class="py-2 pr-3 font-mono"><?= date('d.m.Y', strtotime($j['j_date'])) ?></td>
            <td class="pr-3"><?= e($j['stitle'] ?? '—') ?></td>
            <td class="pr-3"><?= e($j['ename'] ?? '—') ?></td>
            <td class="pr-3 font-semibold <?= $sc ?>"><?= e($j['job_status']) ?></td>
            <td class="pr-3"><?= max(2, (float)($j['total_hours'] ?: $j['j_hours'])) ?>h</td>
            <td class="text-gray-400"><?= e($j['platform'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- INVOICES -->
    <?php if (!empty($dbInvoices)): ?>
    <div class="vulture-section">
      <div class="vulture-header"><span class="text-lg">🧾</span><h3>Rechnungen (<?= count($dbInvoices) ?>)</h3></div>
      <div class="vulture-body overflow-x-auto">
        <table class="w-full text-xs">
          <thead><tr class="text-left text-gray-500 uppercase tracking-wider border-b">
            <th class="py-2 pr-3">Nr.</th><th class="pr-3">Datum</th><th class="pr-3">Betrag</th><th class="pr-3">Offen</th><th>Status</th>
          </tr></thead>
          <tbody class="divide-y">
          <?php foreach ($dbInvoices as $inv): ?>
          <tr class="hover:bg-gray-50">
            <td class="py-2 pr-3 font-mono"><?= e($inv['invoice_number'] ?? '#'.$inv['inv_id']) ?></td>
            <td class="pr-3"><?= date('d.m.Y', strtotime($inv['issue_date'])) ?></td>
            <td class="pr-3 font-semibold"><?= number_format($inv['total_price'], 2, ',', '.') ?> €</td>
            <td class="pr-3 <?= $inv['remaining_price'] > 0 ? 'text-red-600 font-bold' : 'text-green-600' ?>"><?= number_format($inv['remaining_price'], 2, ',', '.') ?> €</td>
            <td><?= $inv['invoice_paid'] === 'yes' ? '✓ Bezahlt' : '⏳ Offen' ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- MESSAGES -->
    <?php if (!empty($dbMessages)): ?>
    <div class="vulture-section">
      <div class="vulture-header"><span class="text-lg">💬</span><h3>Nachrichten (<?= count($dbMessages) ?>)</h3></div>
      <div class="vulture-body space-y-2 max-h-96 overflow-y-auto">
        <?php foreach ($dbMessages as $m): ?>
        <div class="p-3 rounded-lg <?= $m['sender_type'] === 'customer' ? 'bg-green-50 border-l-2 border-green-400' : 'bg-gray-50 border-l-2 border-gray-300' ?>">
          <div class="flex items-center justify-between text-[10px] text-gray-500 mb-1">
            <span class="font-semibold"><?= e($m['sender_name'] ?? $m['sender_type']) ?></span>
            <span><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></span>
          </div>
          <div class="text-sm text-gray-800"><?= nl2br(e(mb_substr($m['message'], 0, 300))) ?></div>
          <?php if ($m['translated_message']): ?>
          <div class="text-xs text-gray-500 italic mt-1">🌐 <?= e(mb_substr($m['translated_message'], 0, 200)) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- DEEP SCAN DATA -->
    <?php if (!empty($mergedDeep)): ?>
    <div class="vulture-section">
      <div class="vulture-header"><span class="text-lg">🔬</span><h3>Deep Scan Daten</h3></div>
      <div class="vulture-body">
        <pre class="text-xs bg-gray-900 text-green-400 p-4 rounded-xl overflow-x-auto max-h-96 font-mono"><?= e(json_encode($mergedDeep, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
      </div>
    </div>
    <?php endif; ?>

    <!-- RAW SCAN DATA -->
    <?php if (!empty($mergedData)): ?>
    <div class="vulture-section" x-data="{ open: false }">
      <div class="vulture-header cursor-pointer" @click="open = !open">
        <span class="text-lg">🗄️</span><h3>Raw Scan Daten (<?= count($scans) ?> Scans)</h3>
        <svg class="w-4 h-4 text-gray-400 ml-auto transition" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
      </div>
      <div x-show="open" x-cloak class="vulture-body">
        <pre class="text-xs bg-gray-900 text-green-400 p-4 rounded-xl overflow-x-auto max-h-96 font-mono"><?= e(json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- RIGHT: Timeline + OSINT Links + Email -->
  <div class="space-y-4">

    <!-- EMAIL ANALYSIS -->
    <?php if ($emailInfo): ?>
    <div class="vulture-section">
      <div class="vulture-header"><span class="text-lg">📧</span><h3>Email-Analyse</h3></div>
      <div class="vulture-body text-sm space-y-2">
        <div class="flex justify-between"><span class="text-gray-500">Domain</span><span class="font-mono font-semibold"><?= e($emailInfo['domain']) ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Typ</span><span class="font-semibold <?= $emailInfo['business'] ? 'text-purple-600' : 'text-gray-600' ?>"><?= $emailInfo['business'] ? 'Business' : ($emailInfo['free'] ? 'Freemail' : 'Unbekannt') ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">MX Records</span><span><?= $emailInfo['mx'] ?></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Gravatar</span>
          <img src="<?= e($emailInfo['gravatar']) ?>" onerror="this.parentElement.innerHTML='Keins'" class="w-8 h-8 rounded"/>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ADDRESSES -->
    <?php if (!empty($dbAddresses) || !empty($allAddresses)): ?>
    <div class="vulture-section">
      <div class="vulture-header"><span class="text-lg">📍</span><h3>Adressen</h3></div>
      <div class="vulture-body text-sm space-y-2">
        <?php foreach ($dbAddresses as $a): ?>
        <div class="p-2 bg-gray-50 rounded-lg">
          <div class="font-semibold"><?= e(($a['address_for'] ?? 'Adresse')) ?></div>
          <div class="text-gray-600"><?= e($a['street'] . ' ' . ($a['number'] ?? '') . ', ' . ($a['postal_code'] ?? '') . ' ' . ($a['city'] ?? '')) ?></div>
        </div>
        <?php endforeach; ?>
        <?php foreach ($allAddresses as $a): ?>
        <div class="p-2 bg-gray-50 rounded-lg text-gray-600"><?= e($a) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- KNOWN IDENTIFIERS -->
    <div class="vulture-section">
      <div class="vulture-header"><span class="text-lg">🔑</span><h3>Bekannte Identifikatoren</h3></div>
      <div class="vulture-body text-sm space-y-2">
        <?php if (!empty($allNames)): ?><div><span class="text-[10px] text-gray-500 uppercase font-bold block">Namen</span><?php foreach ($allNames as $n): ?><span class="inline-block px-2 py-0.5 bg-blue-50 text-blue-800 rounded text-xs mr-1 mb-1"><?= e($n) ?></span><?php endforeach; ?></div><?php endif; ?>
        <?php if (!empty($allEmails)): ?><div><span class="text-[10px] text-gray-500 uppercase font-bold block">Emails</span><?php foreach ($allEmails as $e2): ?><span class="inline-block px-2 py-0.5 bg-green-50 text-green-800 rounded text-xs mr-1 mb-1"><?= e($e2) ?></span><?php endforeach; ?></div><?php endif; ?>
        <?php if (!empty($allPhones)): ?><div><span class="text-[10px] text-gray-500 uppercase font-bold block">Telefone</span><?php foreach ($allPhones as $p2): ?><span class="inline-block px-2 py-0.5 bg-amber-50 text-amber-800 rounded text-xs mr-1 mb-1"><?= e($p2) ?></span><?php endforeach; ?></div><?php endif; ?>
      </div>
    </div>

    <!-- TIMELINE -->
    <?php if (!empty($scanTimeline)): ?>
    <div class="vulture-section">
      <div class="vulture-header"><span class="text-lg">⏱️</span><h3>Timeline (<?= count($scanTimeline) ?>)</h3></div>
      <div class="vulture-body max-h-[500px] overflow-y-auto">
        <div class="space-y-3">
          <?php foreach (array_slice($scanTimeline, 0, 40) as $ev):
            $dotColor = match($ev['type']) { 'scan' => 'bg-purple-500', 'job' => 'bg-brand', 'invoice' => 'bg-amber-500', default => 'bg-gray-400' };
          ?>
          <div class="flex gap-3">
            <div class="tl-dot <?= $dotColor ?>"></div>
            <div class="flex-1 min-w-0">
              <div class="text-xs font-semibold text-gray-900"><?= e($ev['label']) ?></div>
              <div class="text-[11px] text-gray-500 truncate"><?= e($ev['detail']) ?></div>
              <div class="text-[10px] text-gray-400 font-mono"><?= date('d.m.Y H:i', strtotime($ev['date'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- QUICK OSINT LINKS -->
    <div class="vulture-section no-print">
      <div class="vulture-header"><span class="text-lg">🔗</span><h3>OSINT Schnell-Links</h3></div>
      <div class="vulture-body">
        <?php
        $ne = urlencode($displayName);
        $ee = urlencode($primaryEmail);
        $pe = urlencode(preg_replace('/[^+0-9]/', '', $primaryPhone));
        $quickLinks = [
            'Social' => [
                ['Google', 'https://www.google.com/search?q='.$ne],
                ['Facebook', 'https://www.facebook.com/search/top/?q='.$ne],
                ['Instagram', 'https://www.instagram.com/'.$ne.'/'],
                ['LinkedIn', 'https://www.google.com/search?q=site:linkedin.com/in+'.$ne],
                ['XING', 'https://www.xing.com/search/members?keywords='.$ne],
            ],
            'Firmen' => [
                ['Northdata', 'https://www.northdata.de/'.$ne],
                ['Handelsregister', 'https://www.handelsregister.de/rp_web/search.xhtml?schlagwoerter='.$ne],
                ['Bundesanzeiger', 'https://www.bundesanzeiger.de/pub/de/to_nlp_start?destatis_nlp_q='.$ne],
            ],
            'Leaks' => [
                ['HIBP', 'https://haveibeenpwned.com/account/'.urlencode($primaryEmail)],
                ['DeHashed', 'https://www.dehashed.com/search?query='.$ne],
                ['IntelX', 'https://intelx.io/?s='.$ne],
            ],
            'Recht' => [
                ['Insolvenz', 'https://www.google.com/search?q='.$ne.'+Insolvenz'],
                ['Sanctions', 'https://www.sanctionsmap.eu/#/main?search=%7B%22value%22:'.$ne.'%7D'],
                ['Scam/Betrug', 'https://www.google.com/search?q='.$ne.'+Betrug+OR+Scam+OR+Warnung'],
            ],
        ];
        foreach ($quickLinks as $cat => $links): ?>
        <div class="mb-3">
          <div class="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1"><?= $cat ?></div>
          <?php foreach ($links as [$label, $url]): ?>
          <a href="<?= e($url) ?>" target="_blank" rel="noopener" class="link-pill"><?= e($label) ?></a>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <div class="mt-3 pt-3 border-t">
          <a href="/admin/scanner.php" class="text-xs text-brand font-semibold hover:underline">→ Vollständiger Scanner mit allen 100+ Links</a>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- EMPTY STATE -->
<?php if (empty($scans) && empty($dbCustomers) && empty($dbEmployees)): ?>
<div class="card-elev text-center py-16 px-4 mt-6">
  <div class="text-5xl mb-4">🦅</div>
  <h3 class="text-lg font-bold text-gray-900 mb-2">Keine Daten gefunden</h3>
  <p class="text-sm text-gray-500 max-w-md mx-auto mb-6">Für "<strong><?= e($target) ?></strong>" wurden keine OSINT-Scans oder DB-Einträge gefunden.</p>
  <a href="/admin/scanner.php?scan_email=<?= urlencode($isEmail ? $target : '') ?>&scan_name=<?= urlencode($isEmail ? '' : $target) ?>" class="inline-flex items-center gap-2 px-6 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-semibold text-sm">
    Jetzt scannen
  </a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
