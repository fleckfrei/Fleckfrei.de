<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
if (!customerCan('invoices')) { header('Location: /customer/'); exit; }
$title = 'Meine Rechnungen'; $page = 'invoices';
$cid = me()['id'];

$invoices = all("SELECT * FROM invoices WHERE customer_id_fk=? ORDER BY issue_date DESC", [$cid]);
$totalUnpaid = val("SELECT COALESCE(SUM(remaining_price),0) FROM invoices WHERE customer_id_fk=? AND invoice_paid='no'", [$cid]);

include __DIR__ . '/../includes/layout.php';
?>

<?php if ($totalUnpaid > 0): ?>
<div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6 flex items-center justify-between">
  <span class="text-red-800 font-medium">Offener Betrag: <?= money($totalUnpaid) ?></span>
  <a href="https://wa.me/<?= CONTACT_WA ?>?text=Hallo%20<?= urlencode(SITE) ?>,%20ich%20möchte%20meine%20Rechnung%20bezahlen." target="_blank" class="px-4 py-2 bg-green-500 text-white rounded-xl text-sm font-medium">Per WhatsApp bezahlen</a>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl border">
  <div class="p-5 border-b"><h3 class="font-semibold">Rechnungen (<?= count($invoices) ?>)</h3></div>
  <div class="divide-y">
    <?php foreach ($invoices as $inv): ?>
    <div class="px-5 py-4 flex items-center justify-between">
      <div>
        <div class="font-mono font-medium"><?= e($inv['invoice_number']) ?></div>
        <div class="text-sm text-gray-500"><?= date('d.m.Y', strtotime($inv['issue_date'])) ?> — <?= e($inv['start_date']) ?> bis <?= e($inv['end_date']) ?></div>
      </div>
      <div class="text-right">
        <div class="font-medium"><?= money($inv['total_price']) ?></div>
        <?php if ($inv['invoice_paid']==='yes'): ?>
          <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Bezahlt</span>
        <?php else: ?>
          <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">Offen: <?= money($inv['remaining_price']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($invoices)): ?>
    <div class="px-5 py-8 text-center text-gray-400">Keine Rechnungen vorhanden.</div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
