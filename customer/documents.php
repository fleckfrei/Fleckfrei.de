<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
if (!customerCan('documents')) { header('Location: /customer/'); exit; }
$title = t('nav.documents'); $page = 'documents';
$cid = me()['id'];

$invoices = all("SELECT * FROM invoices WHERE customer_id_fk=? ORDER BY issue_date DESC", [$cid]);

include __DIR__ . '/../includes/layout.php';
?>

<div class="bg-white rounded-xl border">
  <div class="p-5 border-b"><h3 class="font-semibold">Ihre Dokumente</h3></div>
  <div class="divide-y">
    <?php foreach ($invoices as $inv): ?>
    <div class="px-5 py-4 flex items-center justify-between">
      <div class="flex items-center gap-4">
        <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center">
          <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
        </div>
        <div>
          <div class="font-medium">Rechnung <?= e($inv['invoice_number']) ?></div>
          <div class="text-sm text-gray-500"><?= date('d.m.Y', strtotime($inv['issue_date'])) ?> — <?= money($inv['total_price']) ?></div>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <?php if ($inv['invoice_paid']==='yes'): ?>
          <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Bezahlt</span>
        <?php else: ?>
          <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">Offen</span>
        <?php endif; ?>
        <a href="/admin/invoice-pdf.php?id=<?= $inv['inv_id'] ?>" target="_blank" class="px-3 py-1.5 bg-brand text-white rounded-lg text-sm font-medium hover:bg-brand/90 transition">PDF</a>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($invoices)): ?>
    <div class="px-5 py-12 text-center text-gray-400">Keine Dokumente vorhanden.</div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
