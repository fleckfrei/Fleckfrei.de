<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
if (!customerCan('jobs')) { header('Location: /customer/'); exit; }
$title = 'Meine Termine'; $page = 'jobs';
$cid = me()['id'];

// Filter state
$tab = $_GET['tab'] ?? 'zukunft'; // 'vergangenheit' | 'zukunft'
$showCancelled = !empty($_GET['cancelled']);
$today = date('Y-m-d');

// Query jobs based on tab + cancelled filter
$cancelledClause = $showCancelled ? "" : " AND j.job_status != 'CANCELLED' ";
if ($tab === 'zukunft') {
    $jobs = all("
        SELECT j.*, s.title as stitle, s.street, s.city, s.total_price,
               e.name as ename, e.surname as esurname, e.phone as ephone
        FROM jobs j
        LEFT JOIN services s ON j.s_id_fk = s.s_id
        LEFT JOIN employee e ON j.emp_id_fk = e.emp_id
        WHERE j.customer_id_fk = ?
          AND j.j_date >= ?
          AND j.status = 1
          $cancelledClause
        ORDER BY j.j_date ASC, j.j_time ASC
    ", [$cid, $today]);
} else {
    $jobs = all("
        SELECT j.*, s.title as stitle, s.street, s.city, s.total_price,
               e.name as ename, e.surname as esurname, e.phone as ephone
        FROM jobs j
        LEFT JOIN services s ON j.s_id_fk = s.s_id
        LEFT JOIN employee e ON j.emp_id_fk = e.emp_id
        WHERE j.customer_id_fk = ?
          AND j.j_date < ?
          AND j.status = 1
          $cancelledClause
        ORDER BY j.j_date DESC, j.j_time DESC
        LIMIT 100
    ", [$cid, $today]);
}

// Status color mapping
function statusColor(string $s): array {
    return match ($s) {
        'PENDING'   => ['bg-amber-50', 'text-amber-700', 'border-amber-200', 'Ausstehend'],
        'CONFIRMED' => ['bg-blue-50',  'text-blue-700',  'border-blue-200',  'Bestätigt'],
        'STARTED', 'RUNNING' => ['bg-purple-50', 'text-purple-700', 'border-purple-200', 'Läuft'],
        'COMPLETED' => ['bg-green-50', 'text-green-700', 'border-green-200', 'Erledigt'],
        'CANCELLED' => ['bg-red-50',   'text-red-700',   'border-red-200',   'Storniert'],
        default => ['bg-gray-50', 'text-gray-700', 'border-gray-200', $s],
    };
}

// German weekday
function weekdayDe(string $date): string {
    static $days = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
    return $days[(int) date('w', strtotime($date))];
}
function monthDe(string $date): string {
    static $months = [1=>'Jan', 2=>'Feb', 3=>'Mär', 4=>'Apr', 5=>'Mai', 6=>'Jun', 7=>'Jul', 8=>'Aug', 9=>'Sep', 10=>'Okt', 11=>'Nov', 12=>'Dez'];
    return $months[(int) date('n', strtotime($date))];
}

// Countdown helper
function countdown(string $date, string $time): string {
    $target = strtotime("$date $time");
    $diff = $target - time();
    if ($diff < 0) return '';
    $days = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);
    if ($days > 0) return "in $days Tag" . ($days > 1 ? 'en' : '');
    if ($hours > 0) return "in $hours Std";
    $mins = floor(($diff % 3600) / 60);
    return "in $mins Min";
}

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Page header -->
<div class="flex items-start justify-between mb-6 flex-wrap gap-4">
  <div>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Meine Termine</h1>
    <p class="text-gray-500 mt-1 text-sm">Alle Reinigungstermine und deren Status im Überblick.</p>
  </div>
  <a href="/customer/booking.php" class="inline-flex items-center gap-2 px-5 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-semibold text-sm shadow-sm transition">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
    Neuer Termin
  </a>
</div>

<!-- Toggle: Abgesagte anzeigen -->
<div class="mb-4">
  <a href="?tab=<?= $tab ?>&cancelled=<?= $showCancelled ? '0' : '1' ?>"
     class="inline-flex items-center gap-2 px-4 py-2 <?= $showCancelled ? 'bg-brand-light text-brand-dark border-brand' : 'bg-white text-gray-600 border-gray-200' ?> border rounded-lg text-xs font-medium hover:bg-brand-light hover:text-brand-dark hover:border-brand transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <?php if ($showCancelled): ?>
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
      <?php else: ?>
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
      <?php endif; ?>
    </svg>
    Abgesagte Termine <?= $showCancelled ? 'ausblenden' : 'anzeigen' ?>
  </a>
</div>

<!-- Tabs -->
<div class="border-b mb-6">
  <div class="flex gap-8">
    <a href="?tab=vergangenheit<?= $showCancelled ? '&cancelled=1' : '' ?>" class="tab-underline <?= $tab === 'vergangenheit' ? 'active' : '' ?>">
      Vergangenheit
    </a>
    <a href="?tab=zukunft<?= $showCancelled ? '&cancelled=1' : '' ?>" class="tab-underline <?= $tab === 'zukunft' ? 'active' : '' ?>">
      Zukunft
    </a>
  </div>
</div>

<?php if (empty($jobs)): ?>

<!-- EMPTY STATE -->
<div class="card-elev text-center py-16 px-4">
  <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-brand-light mb-5">
    <svg class="w-10 h-10 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
  </div>
  <h3 class="text-lg font-semibold text-gray-900 mb-2">
    <?= $tab === 'zukunft' ? 'Keine anstehenden Termine' : 'Keine vergangenen Termine' ?>
  </h3>
  <p class="text-sm text-gray-500 max-w-md mx-auto mb-6">
    <?php if ($tab === 'zukunft'): ?>
      Buchen Sie jetzt Ihren nächsten Reinigungstermin. Unsere Partner stehen bereit.
    <?php else: ?>
      Hier erscheinen Ihre abgeschlossenen Termine sobald Sie einen ersten Auftrag hatten.
    <?php endif; ?>
  </p>
  <?php if ($tab === 'zukunft'): ?>
  <a href="/customer/booking.php" class="inline-flex items-center gap-2 px-6 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-semibold text-sm shadow-sm transition">
    Ersten Termin buchen
  </a>
  <?php endif; ?>
</div>

<?php else: ?>

<!-- JOB CARDS -->
<div class="grid gap-4">
<?php foreach ($jobs as $j):
    [$badgeBg, $badgeText, $badgeBorder, $badgeLabel] = statusColor($j['job_status']);
    $cd = $tab === 'zukunft' ? countdown($j['j_date'], $j['j_time']) : '';
    $canCancel = customerCan('cancel') && in_array($j['job_status'], ['PENDING', 'CONFIRMED']);
?>
<div class="card-elev p-5 flex flex-col sm:flex-row gap-5 transition">

  <!-- Date block left (Helpling-style prominent date) -->
  <div class="flex-shrink-0 flex sm:flex-col items-center sm:items-start gap-3 sm:gap-0 sm:w-24">
    <div class="text-center sm:text-left sm:px-3 sm:py-3 sm:bg-brand-light sm:rounded-xl sm:w-full">
      <div class="text-[11px] uppercase font-bold tracking-wider text-brand"><?= substr(weekdayDe($j['j_date']), 0, 2) ?></div>
      <div class="text-3xl font-extrabold text-gray-900 leading-none my-0.5"><?= (int) date('d', strtotime($j['j_date'])) ?></div>
      <div class="text-[11px] uppercase text-gray-500 font-semibold"><?= monthDe($j['j_date']) ?> <?= date('Y', strtotime($j['j_date'])) ?></div>
    </div>
    <?php if ($cd): ?>
      <div class="sm:mt-2 text-[11px] text-brand font-semibold text-center sm:w-full">⏱ <?= $cd ?></div>
    <?php endif; ?>
  </div>

  <!-- Main info -->
  <div class="flex-1 min-w-0">
    <div class="flex items-start justify-between gap-3 mb-2">
      <div class="min-w-0">
        <h3 class="font-bold text-gray-900 text-base sm:text-lg truncate"><?= e($j['stitle'] ?: 'Reinigungsservice') ?></h3>
        <div class="flex items-center gap-2 mt-1 text-xs text-gray-500">
          <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span><?= substr($j['j_time'], 0, 5) ?> · <?= (float) $j['j_hours'] ?> Std</span>
        </div>
      </div>
      <span class="inline-flex items-center px-2.5 py-1 rounded-full border <?= $badgeBg ?> <?= $badgeText ?> <?= $badgeBorder ?> text-[11px] font-semibold whitespace-nowrap"><?= $badgeLabel ?></span>
    </div>

    <?php if (!empty($j['address']) || !empty($j['street'])): ?>
    <div class="flex items-start gap-2 mt-2 text-xs text-gray-600">
      <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      <span class="truncate"><?= e($j['address'] ?: trim(($j['street'] ?? '') . ' ' . ($j['city'] ?? ''))) ?></span>
    </div>
    <?php endif; ?>

    <?php if (!empty($j['ename'])): ?>
    <div class="flex items-center gap-2 mt-2">
      <div class="w-7 h-7 rounded-full bg-gradient-to-br from-brand to-brand-dark text-white flex items-center justify-center text-xs font-bold">
        <?= strtoupper(substr($j['ename'], 0, 1) . substr($j['esurname'] ?? '', 0, 1)) ?>
      </div>
      <div class="text-xs">
        <span class="text-gray-500">Ihr Partner:</span>
        <span class="font-semibold text-gray-800"><?= e($j['ename'] . ' ' . ($j['esurname'] ?? '')) ?></span>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($j['job_note'])): ?>
    <div class="mt-3 text-xs text-gray-600 bg-gray-50 border-l-2 border-gray-300 pl-3 py-1.5 italic">
      <?= e($j['job_note']) ?>
    </div>
    <?php endif; ?>

    <?php
      $photos = !empty($j['job_photos']) ? json_decode($j['job_photos'], true) : [];
      if (!empty($photos)):
    ?>
    <div class="flex gap-2 mt-3">
      <?php foreach (array_slice($photos, 0, 6) as $p): ?>
      <a href="/uploads/jobs/<?= (int) $j['j_id'] ?>/<?= e($p) ?>" target="_blank">
        <img src="/uploads/jobs/<?= (int) $j['j_id'] ?>/<?= e($p) ?>" class="w-14 h-14 object-cover rounded-lg border border-gray-200 hover:border-brand transition"/>
      </a>
      <?php endforeach; ?>
      <?php if (count($photos) > 6): ?>
      <div class="w-14 h-14 rounded-lg border border-gray-200 flex items-center justify-center text-xs text-gray-500 bg-gray-50">+<?= count($photos) - 6 ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Right: price + actions -->
  <div class="flex sm:flex-col items-end justify-between sm:justify-start sm:w-32 flex-shrink-0 pt-2 sm:pt-0">
    <?php if (!empty($j['total_price'])): ?>
    <div class="text-right">
      <div class="text-[11px] text-gray-400 uppercase font-semibold">Preis</div>
      <div class="text-lg font-bold text-gray-900"><?= money($j['total_price']) ?></div>
    </div>
    <?php endif; ?>

    <div class="flex flex-col gap-2 mt-0 sm:mt-4 items-end">
      <?php if ($j['job_status'] === 'COMPLETED' && customerCan('rate')): ?>
      <a href="/customer/rate.php?j_id=<?= (int) $j['j_id'] ?>" class="text-xs font-semibold text-brand hover:text-brand-dark flex items-center gap-1">
        ⭐ Bewerten
      </a>
      <?php endif; ?>

      <?php if ($canCancel): ?>
      <button
        onclick="if(confirm('Termin wirklich stornieren?\nBei Stornierung weniger als 24 Stunden vor dem Termin fällt eine Gebühr an.')) { fetch('/api/index.php?action=jobs/status', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ j_id: <?= (int) $j['j_id'] ?>, status: 'CANCELLED' }) }).then(() => location.reload()); }"
        class="text-xs font-semibold text-red-600 hover:text-red-700 flex items-center gap-1">
        ✕ Stornieren
      </button>
      <?php endif; ?>

      <?php if ($j['job_status'] === 'COMPLETED' && customerCan('booking')): ?>
      <a href="/customer/booking.php?repeat=<?= (int) $j['j_id'] ?>" class="text-xs font-semibold text-gray-500 hover:text-brand flex items-center gap-1">
        ↻ Nochmal buchen
      </a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- Count footer -->
<div class="mt-6 text-center text-xs text-gray-500">
  <?= count($jobs) ?> Termin<?= count($jobs) === 1 ? '' : 'e' ?> <?= $showCancelled ? '(inkl. abgesagte)' : '' ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
