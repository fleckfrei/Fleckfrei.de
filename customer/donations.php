<?php
/**
 * Customer — Meine Spenden an Rumänien-Hilfe
 * Historie aller 1%-Spenden pro bezahlter Rechnung.
 */
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
$title = 'Meine Spenden'; $page = 'donations';
$cid = me()['id'];
$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);

$paidInvoices = all("
    SELECT inv_id, invoice_number, issue_date, price, total_price
    FROM invoices
    WHERE customer_id_fk=? AND invoice_paid='yes'
    ORDER BY issue_date DESC, inv_id DESC
", [$cid]);

$donationRate = 0.01;
$totalNetto = 0;
$totalDonation = 0;
foreach ($paidInvoices as &$inv) {
    $netto = (float)$inv['price'];
    $don = round($netto * $donationRate, 2);
    $inv['donation'] = $don;
    $totalNetto += $netto;
    $totalDonation += $don;
}
unset($inv);

// Group by year
$byYear = [];
foreach ($paidInvoices as $inv) {
    $y = substr($inv['issue_date'], 0, 4);
    $byYear[$y][] = $inv;
}

include __DIR__ . '/../includes/layout-customer.php';
?>

<div class="mb-4">
  <a href="/customer/" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
    <span class="text-3xl">🤝</span> Meine Spenden
  </h1>
  <p class="text-sm text-gray-500 mt-1">1 % vom Netto-Betrag jeder bezahlten Rechnung geht automatisch an Rumänien-Hilfe — ohne Aufpreis für Sie.</p>
</div>

<!-- Hero stats -->
<div class="rounded-2xl bg-gradient-to-br from-amber-50 via-yellow-50 to-red-50 border border-amber-200 p-6 mb-6">
  <div class="flex items-start gap-4 flex-wrap">
    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-amber-400 to-red-500 text-white flex items-center justify-center flex-shrink-0 shadow-lg text-3xl">🤝</div>
    <div class="flex-1 min-w-0">
      <h2 class="font-bold text-gray-900 text-lg">Danke — Sie haben geholfen!</h2>
      <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mt-3">
        <div class="bg-white/80 rounded-xl px-3 py-2 border border-amber-100">
          <div class="text-[10px] uppercase font-semibold text-amber-700 tracking-wide">Gesamt gespendet</div>
          <div class="font-extrabold text-xl text-gray-900"><?= money($totalDonation) ?></div>
        </div>
        <div class="bg-white/80 rounded-xl px-3 py-2 border border-amber-100">
          <div class="text-[10px] uppercase font-semibold text-amber-700 tracking-wide">Bezahlte Rechnungen</div>
          <div class="font-bold text-lg text-gray-900"><?= count($paidInvoices) ?></div>
        </div>
        <div class="bg-white/80 rounded-xl px-3 py-2 border border-amber-100">
          <div class="text-[10px] uppercase font-semibold text-amber-700 tracking-wide">Netto-Basis</div>
          <div class="font-bold text-lg text-gray-900"><?= money($totalNetto) ?></div>
        </div>
      </div>
      <a href="https://www.rumaenienhilfe-spiez.ch/detailseite-unterst%C3%BCtzung-alter-menschen" target="_blank" rel="noopener" class="inline-flex items-center gap-1 mt-4 text-xs font-bold text-amber-700 hover:text-amber-900">
        Mehr über Rumänien-Hilfe erfahren
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
      </a>
    </div>
  </div>
</div>

<!-- Per-invoice list, grouped by year -->
<?php if (empty($paidInvoices)): ?>
<div class="card-elev text-center py-12">
  <div class="text-4xl mb-2">📝</div>
  <h3 class="font-bold text-gray-900">Noch keine bezahlten Rechnungen</h3>
  <p class="text-sm text-gray-500 mt-1">Sobald Sie Ihre erste Rechnung bezahlen, beginnt Ihre Spenden-Historie hier.</p>
</div>
<?php else: ?>

<?php foreach ($byYear as $year => $invs):
  $yearTotal = array_sum(array_column($invs, 'donation'));
  $yearNetto = array_sum(array_map(fn($i) => (float)$i['price'], $invs));
?>
<div class="card-elev overflow-hidden mb-6">
  <div class="px-5 py-3 border-b border-gray-100 bg-brand-light/50 flex items-center justify-between">
    <h3 class="font-bold text-gray-900 text-base"><?= e($year) ?></h3>
    <div class="text-xs text-gray-600">
      <?= count($invs) ?> Rechnung<?= count($invs) === 1 ? '' : 'en' ?> ·
      <span class="font-bold text-amber-700"><?= money($yearTotal) ?></span> gespendet
    </div>
  </div>
  <div class="divide-y divide-gray-100">
    <?php foreach ($invs as $inv): ?>
    <div class="px-5 py-3 flex items-center justify-between gap-3 hover:bg-gray-50">
      <div class="flex-1 min-w-0">
        <div class="font-semibold text-gray-900 text-sm"><?= e($inv['invoice_number']) ?></div>
        <div class="text-[11px] text-gray-500">
          <?= date('d.m.Y', strtotime($inv['issue_date'])) ?> · Netto: <?= money($inv['price']) ?>
        </div>
      </div>
      <div class="text-right flex-shrink-0">
        <div class="text-sm font-bold text-amber-700"><?= money($inv['donation']) ?></div>
        <div class="text-[10px] text-gray-400">1 % → Spende</div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
