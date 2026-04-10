<?php
require_once __DIR__ . '/../../includes/auth.php';
requireCustomer();
$title = 'Services'; $page = 'services';
$cid = me()['id'];
$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);

// Host-only page. Non-hosts: redirect to dashboard.
if (!in_array($customer['customer_type'] ?? '', ['Airbnb', 'Host'], true)) {
    header('Location: /customer/v2/'); exit;
}

$services = [];
try {
    $services = all("SELECT * FROM services WHERE customer_id_fk = ? AND status = 1 ORDER BY title", [$cid]);
} catch (Exception $e) { $services = []; }

include __DIR__ . '/../../includes/layout-v2.php';
?>

<div class="flex items-start justify-between mb-6 flex-wrap gap-4">
  <div>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Meine Services</h1>
    <p class="text-gray-500 mt-1 text-sm">Verwalten Sie Ihre Apartments / Adressen mit eigenen Service-Paketen.</p>
  </div>
  <a href="/customer/booking.php" class="inline-flex items-center gap-2 px-5 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-semibold text-sm shadow-sm transition">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
    Neuer Service
  </a>
</div>

<div class="card-elev p-5 mb-6 bg-amber-50 border-amber-200 flex items-start gap-3">
  <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
  <div class="text-sm text-amber-900">
    <strong>Services-Verwaltung (v2) in Entwicklung.</strong> Für jetzt nutzen Sie bitte die <a href="/customer/booking.php" class="underline font-semibold">bestehende Service-Verwaltung</a>.
  </div>
</div>

<?php if (empty($services)): ?>
<div class="card-elev text-center py-16 px-4">
  <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-brand-light mb-5">
    <svg class="w-10 h-10 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
  </div>
  <h3 class="text-lg font-semibold text-gray-900 mb-2">Keine Services angelegt</h3>
  <p class="text-sm text-gray-500 max-w-md mx-auto">Legen Sie Ihre Apartments an, um regelmäßige Reinigungen nach jedem Gäste-Checkout zu buchen.</p>
</div>
<?php else: ?>
<div class="grid gap-3">
<?php foreach ($services as $s): ?>
<div class="card-elev p-5 flex items-center justify-between">
  <div>
    <div class="font-bold text-gray-900"><?= e($s['title']) ?></div>
    <div class="text-xs text-gray-500 mt-1"><?= e(($s['street'] ?? '') . ' ' . ($s['city'] ?? '')) ?></div>
  </div>
  <div class="text-right">
    <div class="font-bold text-gray-900"><?= money($s['total_price'] ?? 0) ?></div>
    <div class="text-[11px] text-gray-400">pro Reinigung</div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer-v2.php'; ?>
