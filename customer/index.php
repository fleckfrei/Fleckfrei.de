<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
$title = 'Mein Konto'; $page = 'dashboard';
$user = me();
$cid = $user['id'];

$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);
$today = date('Y-m-d');

// Next upcoming job
$nextJob = one("
    SELECT j.*, s.title as stitle, e.name as ename, e.surname as esurname
    FROM jobs j
    LEFT JOIN services s ON j.s_id_fk = s.s_id
    LEFT JOIN employee e ON j.emp_id_fk = e.emp_id
    WHERE j.customer_id_fk = ?
      AND j.j_date >= ?
      AND j.status = 1
      AND j.job_status NOT IN ('CANCELLED', 'COMPLETED')
    ORDER BY j.j_date ASC, j.j_time ASC
    LIMIT 1
", [$cid, $today]);

// Open invoices
$unpaid = customerCan('invoices') ? all("
    SELECT * FROM invoices
    WHERE customer_id_fk = ? AND invoice_paid = 'no' AND remaining_price > 0
    ORDER BY issue_date DESC
", [$cid]) : [];
$totalUnpaid = array_sum(array_column($unpaid, 'remaining_price'));

// Counts
$upcomingCount = (int) val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk = ? AND j_date >= ? AND status = 1 AND job_status NOT IN ('CANCELLED','COMPLETED')", [$cid, $today]);
$completedCount = (int) val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk = ? AND job_status = 'COMPLETED' AND status = 1", [$cid]);

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Welcome header -->
<div class="mb-8">
  <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">
    Willkommen zurück, <?= e(explode(' ', $customer['name'] ?? 'Gast')[0]) ?> 👋
  </h1>
  <p class="text-gray-500 mt-1 text-sm">Hier ist Ihr persönliches Dashboard.</p>
</div>

<!-- Open invoice warning -->
<?php if ($totalUnpaid > 0): ?>
<div class="card-elev border-red-200 bg-red-50 p-5 mb-6 flex items-start gap-4">
  <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
  </div>
  <div class="flex-1 min-w-0">
    <div class="font-semibold text-red-900"><?= count($unpaid) ?> offene Rechnung<?= count($unpaid) === 1 ? '' : 'en' ?></div>
    <div class="text-sm text-red-700 mt-0.5">Gesamtbetrag: <strong><?= money($totalUnpaid) ?></strong></div>
  </div>
  <a href="/customer/invoices.php" class="flex-shrink-0 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-semibold whitespace-nowrap">Jetzt bezahlen</a>
</div>
<?php endif; ?>

<!-- Stats row -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="card-elev p-5">
    <div class="text-3xl font-extrabold text-brand"><?= $upcomingCount ?></div>
    <div class="text-xs text-gray-500 mt-1 uppercase font-semibold tracking-wider">Anstehende Termine</div>
  </div>
  <div class="card-elev p-5">
    <div class="text-3xl font-extrabold text-gray-900"><?= $completedCount ?></div>
    <div class="text-xs text-gray-500 mt-1 uppercase font-semibold tracking-wider">Abgeschlossen</div>
  </div>
  <div class="card-elev p-5">
    <div class="text-3xl font-extrabold <?= $totalUnpaid > 0 ? 'text-red-600' : 'text-green-600' ?>"><?= money($totalUnpaid) ?></div>
    <div class="text-xs text-gray-500 mt-1 uppercase font-semibold tracking-wider">Offen</div>
  </div>
  <a href="/customer/booking.php" class="card-elev p-5 flex items-center justify-between hover:bg-brand-light group">
    <div>
      <div class="text-base font-bold text-brand">Neuer Termin</div>
      <div class="text-xs text-gray-500 mt-1">Jetzt buchen</div>
    </div>
    <div class="w-10 h-10 rounded-full bg-brand text-white flex items-center justify-center group-hover:bg-brand-dark transition">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
    </div>
  </a>
</div>

<!-- Next appointment highlight -->
<?php if ($nextJob):
    $cd = '';
    $target = strtotime($nextJob['j_date'] . ' ' . $nextJob['j_time']);
    $diff = $target - time();
    if ($diff > 0) {
        $days = floor($diff / 86400);
        $hours = floor(($diff % 86400) / 3600);
        if ($days > 0) $cd = "in $days Tag" . ($days > 1 ? 'en' : '');
        elseif ($hours > 0) $cd = "in $hours Std";
        else $cd = "gleich";
    }
?>
<div class="card-elev p-6 mb-6 bg-gradient-to-br from-white to-brand-light relative overflow-hidden">
  <div class="absolute top-0 right-0 w-32 h-32 opacity-10">
    <svg fill="currentColor" class="text-brand" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
  </div>
  <div class="relative z-10">
    <div class="text-[11px] uppercase font-bold tracking-wider text-brand mb-2">Nächster Termin</div>
    <div class="text-xl sm:text-2xl font-bold text-gray-900 mb-1">
      <?= date('d.m.Y', strtotime($nextJob['j_date'])) ?> · <?= substr($nextJob['j_time'], 0, 5) ?> Uhr
      <?php if ($cd): ?><span class="text-brand text-sm font-semibold ml-2">(<?= $cd ?>)</span><?php endif; ?>
    </div>
    <div class="text-sm text-gray-600 mb-4">
      <?= e($nextJob['stitle'] ?? 'Reinigungsservice') ?>
      <?php if ($nextJob['ename']): ?> · mit <?= e($nextJob['ename']) ?><?php endif; ?>
    </div>
    <a href="/customer/jobs.php" class="inline-flex items-center gap-2 text-sm font-semibold text-brand hover:text-brand-dark">
      Alle Termine anzeigen
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
    </a>
  </div>
</div>
<?php endif; ?>

<!-- Quick actions -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
  <a href="/customer/jobs.php" class="card-elev p-5 hover:border-brand transition">
    <div class="text-2xl mb-2">📅</div>
    <div class="font-semibold text-gray-900">Meine Termine</div>
    <div class="text-xs text-gray-500 mt-1">Alle Buchungen verwalten</div>
  </a>
  <a href="/customer/invoices.php" class="card-elev p-5 hover:border-brand transition">
    <div class="text-2xl mb-2">🧾</div>
    <div class="font-semibold text-gray-900">Rechnungen</div>
    <div class="text-xs text-gray-500 mt-1">Zahlungen und Belege</div>
  </a>
  <a href="/customer/messages.php" class="card-elev p-5 hover:border-brand transition">
    <div class="text-2xl mb-2">💬</div>
    <div class="font-semibold text-gray-900">Chat</div>
    <div class="text-xs text-gray-500 mt-1">Nachrichten an Ihren Partner</div>
  </a>
</div>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
