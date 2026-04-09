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
      <div class="flex items-center gap-3">
        <?php if ($inv['invoice_paid']!=='yes' && FEATURE_STRIPE): ?>
          <button onclick="stripePayInv(<?= $inv['inv_id'] ?>)" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-semibold hover:opacity-90 transition">Karte/SEPA</button>
        <?php endif; ?>
        <?php if ($inv['invoice_paid']!=='yes' && FEATURE_PAYPAL): ?>
          <paypal-button hidden data-inv="<?= $inv['inv_id'] ?>" class="paypal-v6-btn"></paypal-button>
        <?php endif; ?>
        <?php if (customerCan('inv_pdf')): ?>
          <a href="/admin/invoice-pdf.php?id=<?= $inv['inv_id'] ?>" target="_blank" class="px-3 py-2 border rounded-lg text-xs hover:bg-gray-50">PDF</a>
        <?php endif; ?>
        <div class="text-right">
          <div class="font-medium"><?= money($inv['total_price']) ?></div>
          <?php if ($inv['invoice_paid']==='yes'): ?>
            <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Bezahlt</span>
          <?php else: ?>
            <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">Offen: <?= money($inv['remaining_price']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($invoices)): ?>
    <div class="px-5 py-8 text-center text-gray-400">Keine Rechnungen vorhanden.</div>
    <?php endif; ?>
  </div>
</div>
<?php
$apiKey = API_KEY;
$paypalId = PAYPAL_CLIENT_ID;
$script = <<<JS
function stripePayInv(invId) {
    const btn = event.target;
    btn.textContent = 'Wird geladen...';
    btn.disabled = true;
    fetch('/api/index.php?action=stripe/checkout', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-API-Key': '$apiKey'},
        body: JSON.stringify({inv_id: invId})
    })
    .then(r => r.json())
    .then(d => {
        if (d.success && d.data.checkout_url) {
            window.location.href = d.data.checkout_url;
        } else {
            alert(d.error || 'Fehler beim Erstellen der Zahlung');
            btn.textContent = 'Jetzt bezahlen';
            btn.disabled = false;
        }
    })
    .catch(() => {
        alert('Netzwerk-Fehler');
        btn.textContent = 'Jetzt bezahlen';
        btn.disabled = false;
    });
}
JS;
if (FEATURE_PAYPAL) {
$script .= <<<JS

// PayPal v6 SDK — Web Components
(function(){
  const s = document.createElement('script');
  s.src = 'https://www.paypal.com/web-sdk/v6/core';
  s.onload = async function() {
    try {
      const sdkInstance = await window.paypal.createInstance({
        clientId: '$paypalId',
        components: ['paypal-payments'],
        pageType: 'checkout',
        locale: 'de-DE',
      });

      const methods = await sdkInstance.findEligibleMethods({ currencyCode: 'EUR' });
      if (!methods.isEligible('paypal')) return;

      document.querySelectorAll('.paypal-v6-btn').forEach(btn => {
        const invId = parseInt(btn.dataset.inv);

        const session = sdkInstance.createPayPalOneTimePaymentSession({
          async onApprove(data) {
            try {
              const resp = await fetch('/api/paypal-capture.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: data.orderId, inv_id: invId })
              });
              const d = await resp.json();
              if (d.success) { location.reload(); }
              else { alert(d.error || 'Capture fehlgeschlagen'); }
            } catch (e) { alert('Netzwerk-Fehler'); }
          },
          onCancel() { console.log('PayPal cancelled'); },
          onError(err) { console.error('PayPal Error:', err); }
        });

        btn.removeAttribute('hidden');
        btn.addEventListener('click', async () => {
          try {
            const resp = await fetch('/api/paypal-create.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ inv_id: invId })
            });
            const d = await resp.json();
            if (!d.success) { alert(d.error); return; }
            await session.start(
              { presentationMode: 'auto' },
              Promise.resolve({ orderId: d.order_id })
            );
          } catch (e) { console.error('PayPal start error:', e); }
        });
      });
    } catch (e) { console.error('PayPal v6 init error:', e); }
  };
  document.head.appendChild(s);
})();
JS;
}
include __DIR__ . '/../includes/footer.php'; ?>
