<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
$title = 'Mein Dashboard'; $page = 'dashboard';
$user = me();
$cid = $user['id'];

$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);
$today = date('Y-m-d');

// Stats (only load what's needed)
$upcomingJobs = customerCan('jobs') ? all("SELECT j.*, s.title as stitle, s.street, s.city,
    e.name as ename, e.surname as esurname
    FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
    WHERE j.customer_id_fk=? AND j.j_date>=? AND j.status=1 AND j.job_status!='CANCELLED'
    ORDER BY j.j_date, j.j_time LIMIT 20", [$cid, $today]) : [];

$recentDone = customerCan('jobs') ? all("SELECT j.*, s.title as stitle, e.name as ename
    FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
    WHERE j.customer_id_fk=? AND j.job_status='COMPLETED' AND j.status=1
    ORDER BY j.j_date DESC LIMIT 5", [$cid]) : [];

$unpaid = customerCan('invoices') ? all("SELECT * FROM invoices WHERE customer_id_fk=? AND invoice_paid='no' ORDER BY issue_date DESC", [$cid]) : [];
$totalUnpaid = array_sum(array_column($unpaid, 'remaining_price'));

$totalJobs = val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1", [$cid]);
$completedJobs = val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND job_status='COMPLETED'", [$cid]);

include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-6">
  <h2 class="text-xl font-semibold">Willkommen, <?= e($customer['name']) ?>!</h2>
  <p class="text-sm text-gray-500"><?= e($customer['customer_type']) ?> — <?= e($customer['email']) ?></p>
</div>

<?php if (customerCan('dashboard')): ?>
<!-- Stats -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <?php if (customerCan('jobs')): ?>
  <div class="bg-white rounded-xl border p-4 card-hover"><div class="text-2xl font-bold text-brand"><?= count($upcomingJobs) ?></div><div class="text-sm text-gray-500">Kommende Jobs</div></div>
  <div class="bg-white rounded-xl border p-4 card-hover"><div class="text-2xl font-bold"><?= $completedJobs ?></div><div class="text-sm text-gray-500">Erledigt</div></div>
  <?php endif; ?>
  <?php if (customerCan('invoices')): ?>
  <div class="bg-white rounded-xl border p-4 card-hover"><div class="text-2xl font-bold <?= $totalUnpaid > 0 ? 'text-red-600' : 'text-green-600' ?>"><?= money($totalUnpaid) ?></div><div class="text-sm text-gray-500">Offene Rechnungen</div></div>
  <?php endif; ?>
  <?php if (customerCan('workhours')): ?>
  <?php $totalH = val("SELECT COALESCE(SUM(GREATEST(COALESCE(total_hours,j_hours),2)),0) FROM jobs WHERE customer_id_fk=? AND job_status='COMPLETED' AND status=1", [$cid]); ?>
  <div class="bg-white rounded-xl border p-4 card-hover"><div class="text-2xl font-bold text-brand"><?= round($totalH,1) ?>h</div><div class="text-sm text-gray-500">Stunden gesamt</div></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (customerCan('invoices') && $totalUnpaid > 0): ?>
<div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6">
  <span class="text-red-800 font-medium"><?= count($unpaid) ?> offene Rechnung(en) — <?= money($totalUnpaid) ?></span>
  <a href="/customer/invoices.php" class="ml-2 text-brand font-medium hover:underline">Ansehen</a>
</div>
<?php endif; ?>

<?php if (customerCan('jobs') && !empty($upcomingJobs)): ?>
<!-- Upcoming Jobs -->
<div class="bg-white rounded-xl border mb-6">
  <div class="p-5 border-b"><h3 class="font-semibold">Kommende Jobs</h3></div>
  <div class="divide-y">
    <?php foreach ($upcomingJobs as $j): ?>
    <div class="px-5 py-4 flex items-center justify-between">
      <div>
        <div class="font-medium"><?= e($j['stitle'] ?: 'Service') ?></div>
        <div class="text-sm text-gray-500"><?= date('d.m.Y', strtotime($j['j_date'])) ?> um <?= substr($j['j_time'],0,5) ?> — <?= $j['j_hours'] ?>h</div>
        <div class="text-xs text-gray-400"><?= e($j['address'] ?: $j['street'].' '.$j['city']) ?></div>
      </div>
      <div class="text-right">
        <?= badge($j['job_status']) ?>
        <?php if ($j['ename']): ?><div class="text-xs text-gray-500 mt-1"><?= e($j['ename']) ?></div><?php endif; ?>
        <?php if (customerCan('cancel') && ($j['job_status']==='PENDING'||$j['job_status']==='CONFIRMED')): ?>
        <button onclick="if(confirm('Job stornieren?'))fetch('/api/index.php?action=jobs/status',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({j_id:<?= $j['j_id'] ?>,status:'CANCELLED'})}).then(()=>location.reload())" class="mt-1 px-2 py-1 text-xs bg-red-50 text-red-600 rounded-lg">Stornieren</button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (customerCan('jobs') && !empty($recentDone)): ?>
<!-- Recent Completed -->
<div class="bg-white rounded-xl border mb-6">
  <div class="p-5 border-b"><h3 class="font-semibold">Letzte erledigte Jobs</h3></div>
  <div class="divide-y">
    <?php foreach ($recentDone as $j): ?>
    <div class="px-5 py-3 flex justify-between text-sm">
      <span><?= date('d.m.Y', strtotime($j['j_date'])) ?> — <?= e($j['stitle']) ?></span>
      <span class="text-green-600"><?= round($j['total_hours'] ?: $j['j_hours'], 1) ?>h</span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (customerCan('booking')): ?>
<div class="bg-brand/5 border border-brand/20 rounded-xl p-5 mb-6">
  <h3 class="font-semibold text-brand mb-2">Neue Buchung</h3>
  <p class="text-sm text-gray-600 mb-3">Buchen Sie jetzt einen neuen Termin.</p>
  <a href="/customer/booking.php" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-medium">Jetzt buchen</a>
</div>
<?php endif; ?>

<!-- Contact -->
<div class="bg-white rounded-xl border p-5">
  <h3 class="font-semibold mb-3">Kontakt</h3>
  <div class="flex gap-3">
    <a href="https://wa.me/<?= CONTACT_WA ?>" target="_blank" class="flex items-center gap-2 px-4 py-2 bg-green-500 text-white rounded-xl text-sm font-medium hover:bg-green-600 transition">WhatsApp</a>
    <a href="mailto:<?= CONTACT_EMAIL ?>" class="flex items-center gap-2 px-4 py-2 border rounded-xl text-sm font-medium hover:bg-gray-50 transition">E-Mail</a>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
