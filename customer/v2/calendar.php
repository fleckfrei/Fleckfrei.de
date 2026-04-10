<?php
require_once __DIR__ . '/../../includes/auth.php';
requireCustomer();
$title = 'Kalender / iCal'; $page = 'calendar';
$cid = me()['id'];
$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);

// Host-only page
if (!in_array($customer['customer_type'] ?? '', ['Airbnb', 'Host'], true)) {
    header('Location: /customer/v2/'); exit;
}

$feeds = [];
try {
    $feeds = all("SELECT * FROM ical_feeds WHERE customer_id_fk = ? ORDER BY created_at DESC", [$cid]);
} catch (Exception $e) { $feeds = []; }

include __DIR__ . '/../../includes/layout-v2.php';
?>

<div class="mb-6">
  <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Kalender & iCal</h1>
  <p class="text-gray-500 mt-1 text-sm">Verbinden Sie Ihre Airbnb- oder Booking.com-Kalender um automatisch Reinigungen nach jedem Checkout zu planen.</p>
</div>

<div class="card-elev p-5 mb-6 bg-amber-50 border-amber-200 flex items-start gap-3">
  <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
  <div class="text-sm text-amber-900">
    <strong>iCal-Feeds-Verwaltung (v2) in Entwicklung.</strong> Für jetzt bleiben Feeds über die
    <a href="/admin/availability.php" class="underline font-semibold">Admin-Verfügbarkeits-Seite</a> erreichbar.
  </div>
</div>

<?php if (empty($feeds)): ?>
<div class="card-elev text-center py-16 px-4">
  <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-brand-light mb-5">
    <svg class="w-10 h-10 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
  </div>
  <h3 class="text-lg font-semibold text-gray-900 mb-2">Keine Kalender verbunden</h3>
  <p class="text-sm text-gray-500 max-w-md mx-auto mb-6">Verbinden Sie Ihre Airbnb-, Booking.com- oder VRBO-iCal URLs um Check-outs automatisch zu tracken.</p>
</div>
<?php else: ?>
<div class="grid gap-3">
<?php foreach ($feeds as $f): ?>
<div class="card-elev p-5 flex items-center justify-between gap-4">
  <div class="flex items-center gap-3 min-w-0">
    <div class="w-10 h-10 rounded-lg bg-brand-light flex items-center justify-center flex-shrink-0">
      <svg class="w-5 h-5 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
    </div>
    <div class="min-w-0">
      <div class="font-semibold text-gray-900 truncate"><?= e($f['name'] ?? $f['source'] ?? 'Feed') ?></div>
      <div class="text-xs text-gray-500 font-mono truncate max-w-md"><?= e(substr($f['feed_url'] ?? '', 0, 60)) ?>…</div>
    </div>
  </div>
  <span class="inline-block px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-[11px] font-semibold">Aktiv</span>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer-v2.php'; ?>
