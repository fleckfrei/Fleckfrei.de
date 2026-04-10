<?php
require_once __DIR__ . '/../../includes/auth.php';
requireCustomer();
if (!customerCan('invoices')) { header('Location: /customer/v2/'); exit; }
$title = 'Rechnungen'; $page = 'invoices';
$cid = me()['id'];

// Tab: 'service' (Reinigungen) or 'other'
$tab = $_GET['tab'] ?? 'service';

// All invoices
$allInvoices = all("SELECT * FROM invoices WHERE customer_id_fk=? ORDER BY issue_date DESC", [$cid]);
$invoices = $allInvoices; // no separation yet — show all under 'service'; 'other' shows empty for now

$totalUnpaid = (float) val("SELECT COALESCE(SUM(remaining_price),0) FROM invoices WHERE customer_id_fk=? AND invoice_paid='no'", [$cid]);
$unpaidCount = (int) val("SELECT COUNT(*) FROM invoices WHERE customer_id_fk=? AND invoice_paid='no'", [$cid]);

include __DIR__ . '/../../includes/layout-v2.php';
?>

<div class="mb-6">
  <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Rechnungen</h1>
  <p class="text-gray-500 mt-1 text-sm">Alle Belege und Zahlungen im Überblick.</p>
</div>

<?php if ($totalUnpaid > 0): ?>
<div class="card-elev border-red-200 bg-red-50 p-5 mb-6 flex items-center justify-between gap-4">
  <div>
    <div class="font-semibold text-red-900"><?= $unpaidCount ?> offene Rechnung<?= $unpaidCount === 1 ? '' : 'en' ?></div>
    <div class="text-sm text-red-700"><?= money($totalUnpaid) ?> Gesamtbetrag</div>
  </div>
  <a href="/customer/invoices.php" class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-semibold whitespace-nowrap">Jetzt bezahlen →</a>
</div>
<?php endif; ?>

<!-- Tabs (Helpling-style) -->
<div class="border-b mb-6">
  <div class="flex gap-8">
    <a href="?tab=service" class="tab-underline <?= $tab === 'service' ? 'active' : '' ?>">
      Dienstleistung
    </a>
    <a href="?tab=other" class="tab-underline <?= $tab === 'other' ? 'active' : '' ?>">
      Andere
    </a>
  </div>
</div>

<?php if ($tab === 'service'):
    if (empty($invoices)): ?>
<div class="card-elev text-center py-16 px-4">
  <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-brand-light mb-5">
    <svg class="w-10 h-10 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
  </div>
  <h3 class="text-lg font-semibold text-gray-900 mb-2">Keine Rechnungen</h3>
  <p class="text-sm text-gray-500 max-w-md mx-auto">Nach Ihrem ersten Termin werden Ihnen hier Ihre Rechnungen angezeigt.</p>
</div>
<?php else: ?>
<div class="grid gap-3">
<?php foreach ($invoices as $inv):
    $paid = $inv['invoice_paid'] === 'yes';
    $partial = !$paid && (float) $inv['remaining_price'] < (float) $inv['total_price'];
?>
<div class="card-elev p-5 flex items-center justify-between gap-4 flex-wrap">
  <div class="flex items-center gap-4 min-w-0">
    <div class="w-12 h-12 rounded-xl <?= $paid ? 'bg-green-50' : 'bg-amber-50' ?> flex items-center justify-center flex-shrink-0">
      <svg class="w-6 h-6 <?= $paid ? 'text-green-600' : 'text-amber-600' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
    </div>
    <div class="min-w-0">
      <div class="font-mono font-semibold text-gray-900 text-sm truncate"><?= e($inv['invoice_number']) ?></div>
      <div class="text-xs text-gray-500 mt-0.5">
        Ausgestellt <?= date('d.m.Y', strtotime($inv['issue_date'])) ?>
        <?php if (!empty($inv['start_date']) && !empty($inv['end_date'])): ?>
          · <?= date('d.m.', strtotime($inv['start_date'])) ?> – <?= date('d.m.Y', strtotime($inv['end_date'])) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="flex items-center gap-4 ml-auto">
    <div class="text-right">
      <div class="font-bold text-gray-900"><?= money($inv['total_price']) ?></div>
      <?php if ($paid): ?>
        <span class="inline-block px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-[11px] font-semibold">Bezahlt</span>
      <?php elseif ($partial): ?>
        <span class="inline-block px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[11px] font-semibold">Teilzahlung</span>
      <?php else: ?>
        <span class="inline-block px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-[11px] font-semibold">Offen <?= money($inv['remaining_price']) ?></span>
      <?php endif; ?>
    </div>
    <div class="flex gap-2">
      <?php if (customerCan('inv_pdf')): ?>
      <a href="/admin/invoice-pdf.php?id=<?= (int) $inv['inv_id'] ?>" target="_blank" class="px-3 py-2 border border-gray-200 rounded-lg text-xs font-medium hover:bg-gray-50 flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
        PDF
      </a>
      <?php endif; ?>
      <?php if (!$paid): ?>
      <a href="/customer/invoices.php" class="px-4 py-2 bg-brand hover:bg-brand-dark text-white rounded-lg text-xs font-semibold">Bezahlen</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif;
else: // tab === other
?>
<div class="card-elev text-center py-16 px-4">
  <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-gray-100 mb-5">
    <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
  </div>
  <h3 class="text-lg font-semibold text-gray-900 mb-2">Keine sonstigen Belege</h3>
  <p class="text-sm text-gray-500 max-w-md mx-auto">Hier erscheinen sonstige Belege wie Gutscheine oder Gutschriften.</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer-v2.php'; ?>
