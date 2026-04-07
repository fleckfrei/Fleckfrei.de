<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$invId = (int)($_GET['id'] ?? 0);
if (!$invId) { header('Location: /admin/invoices.php'); exit; }

// Customers can only see their own invoices
$user = me();
if ($user['type'] === 'customer') {
    $check = one("SELECT inv_id FROM invoices WHERE inv_id=? AND customer_id_fk=?", [$invId, $user['id']]);
    if (!$check) { header('Location: /customer/'); exit; }
}

$inv = one("SELECT i.*, c.name as cname, c.surname as csurname, c.email as cemail, c.phone as cphone, c.customer_type as ctype
    FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id WHERE i.inv_id=?", [$invId]);
if (!$inv) { header('Location: /admin/invoices.php'); exit; }

// Get customer address
$addr = one("SELECT * FROM customer_address WHERE customer_id_fk=? ORDER BY ca_id DESC LIMIT 1", [$inv['customer_id_fk']]);

// Get linked jobs (line items)
$jobs = all("SELECT j.*, s.title as stitle, s.total_price as sprice, e.name as ename
    FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
    WHERE j.invoice_id=? ORDER BY j.j_date", [$invId]);

// Get payments
$payments = all("SELECT * FROM invoice_payments WHERE invoice_id_fk=? ORDER BY payment_date", [$invId]);

// Calculate line items
$lines = [];
$subtotal = 0;
foreach ($jobs as $j) {
    $hrs = max(MIN_HOURS, $j['total_hours'] ?: $j['j_hours']);
    $price = $j['sprice'] ?: 0;
    $lineTotal = round($hrs * $price, 2);
    $subtotal += $lineTotal;
    $lines[] = [
        'date' => $j['j_date'],
        'service' => $j['stitle'] ?: 'Reinigung',
        'address' => $j['address'],
        'hours' => $hrs,
        'rate' => $price,
        'total' => $lineTotal,
    ];
}

// If no linked jobs, use invoice totals directly
if (empty($lines)) {
    $subtotal = (float)($inv['price'] ?: $inv['total_price'] / (1 + TAX_RATE));
}

$tax = round($subtotal * TAX_RATE, 2);
$total = round($subtotal + $tax, 2);

// Use actual invoice total if it differs (legacy invoices)
if (abs($total - (float)$inv['total_price']) > 0.1 && (float)$inv['total_price'] > 0) {
    $total = (float)$inv['total_price'];
    $tax = round($total - $total / (1 + TAX_RATE), 2);
    $subtotal = $total - $tax;
}

$paidAmount = $total - (float)$inv['remaining_price'];

// Settings for company details
$settings = one("SELECT * FROM settings LIMIT 1");
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Rechnung <?= e($inv['invoice_number']) ?> — <?= SITE ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Inter', system-ui, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5; background: #f3f4f6; }
    .invoice-page { max-width: 800px; margin: 20px auto; background: white; padding: 60px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .brand-color { color: <?= BRAND ?>; }
    .brand-bg { background: <?= BRAND ?>; }

    /* Header */
    .inv-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 50px; }
    .inv-logo { display: flex; align-items: center; gap: 12px; }
    .inv-logo-icon { width: 48px; height: 48px; border-radius: 12px; background: <?= BRAND ?>; color: white; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700; }
    .inv-logo-text { font-size: 24px; font-weight: 700; }
    .inv-number { text-align: right; }
    .inv-number h2 { font-size: 28px; font-weight: 300; color: #6b7280; text-transform: uppercase; letter-spacing: 2px; }
    .inv-number .num { font-size: 18px; font-weight: 600; margin-top: 4px; }

    /* Addresses */
    .inv-addresses { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 40px; }
    .inv-addr label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; margin-bottom: 8px; display: block; }
    .inv-addr .name { font-size: 16px; font-weight: 600; margin-bottom: 4px; }

    /* Meta */
    .inv-meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 40px; padding: 16px 0; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; }
    .inv-meta-item label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; display: block; }
    .inv-meta-item .val { font-size: 14px; font-weight: 600; margin-top: 2px; }

    /* Table */
    .inv-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
    .inv-table th { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; text-align: left; padding: 12px 16px; border-bottom: 2px solid #e5e7eb; }
    .inv-table th:last-child, .inv-table td:last-child { text-align: right; }
    .inv-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; }
    .inv-table tr:last-child td { border-bottom: none; }

    /* Totals */
    .inv-totals { display: flex; justify-content: flex-end; margin-bottom: 40px; }
    .inv-totals table { width: 280px; }
    .inv-totals td { padding: 6px 0; }
    .inv-totals .label { color: #6b7280; }
    .inv-totals .amount { text-align: right; font-weight: 500; }
    .inv-totals .total-row td { border-top: 2px solid <?= BRAND ?>; padding-top: 10px; font-size: 18px; font-weight: 700; }
    .inv-totals .total-row .amount { color: <?= BRAND ?>; }

    /* Payments */
    .inv-payments { background: #f9fafb; border-radius: 12px; padding: 20px; margin-bottom: 30px; }
    .inv-payments h4 { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; margin-bottom: 12px; }

    /* Status badge */
    .badge-paid { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
    .badge-green { background: #dcfce7; color: #166534; }
    .badge-red { background: #fef2f2; color: #991b1b; }

    /* Footer */
    .inv-footer { border-top: 1px solid #e5e7eb; padding-top: 20px; text-align: center; color: #9ca3af; font-size: 12px; }
    .inv-footer a { color: <?= BRAND ?>; text-decoration: none; }

    /* Print controls */
    .print-controls { max-width: 800px; margin: 20px auto 0; display: flex; gap: 12px; justify-content: flex-end; }
    .btn-print { padding: 10px 24px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; transition: all 0.15s; }
    .btn-primary { background: <?= BRAND ?>; color: white; }
    .btn-primary:hover { opacity: 0.9; }
    .btn-secondary { background: white; color: #374151; border: 1px solid #d1d5db; }
    .btn-secondary:hover { background: #f9fafb; }

    @media print {
      body { background: white; }
      .invoice-page { box-shadow: none; margin: 0; padding: 40px; }
      .print-controls { display: none; }
    }
  </style>
</head>
<body>

<div class="print-controls">
  <a href="/admin/invoices.php" class="btn-print btn-secondary">Zurück</a>
  <button onclick="window.print()" class="btn-print btn-primary">Drucken / PDF speichern</button>
</div>

<div class="invoice-page">
  <!-- Header -->
  <div class="inv-header">
    <div class="inv-logo">
      <div class="inv-logo-icon"><?= LOGO_LETTER ?></div>
      <div>
        <div class="inv-logo-text"><?= SITE ?></div>
        <div style="color:#6b7280;font-size:12px"><?= SITE_TAGLINE ?></div>
      </div>
    </div>
    <div class="inv-number">
      <h2>Rechnung</h2>
      <div class="num brand-color"><?= e($inv['invoice_number']) ?></div>
    </div>
  </div>

  <!-- Addresses -->
  <div class="inv-addresses">
    <div class="inv-addr">
      <label>Von</label>
      <div class="name"><?= SITE ?></div>
      <?php if (!empty($settings['company_address'])): ?><div><?= e($settings['company_address']) ?></div><?php endif; ?>
      <div><?= CONTACT_EMAIL ?></div>
      <div><?= CONTACT_PHONE ?></div>
      <div><?= SITE_DOMAIN ?></div>
    </div>
    <div class="inv-addr">
      <label>An</label>
      <div class="name"><?= e($inv['cname'] . ' ' . ($inv['csurname'] ?? '')) ?></div>
      <?php if ($addr): ?>
        <div><?= e($addr['street'] . ' ' . ($addr['number'] ?? '')) ?></div>
        <div><?= e(($addr['postal_code'] ?? '') . ' ' . ($addr['city'] ?? '')) ?></div>
      <?php endif; ?>
      <?php if ($inv['cemail']): ?><div><?= e($inv['cemail']) ?></div><?php endif; ?>
      <?php if ($inv['cphone']): ?><div><?= e($inv['cphone']) ?></div><?php endif; ?>
    </div>
  </div>

  <!-- Meta -->
  <div class="inv-meta">
    <div class="inv-meta-item">
      <label>Rechnungsdatum</label>
      <div class="val"><?= $inv['issue_date'] ? date('d.m.Y', strtotime($inv['issue_date'])) : '-' ?></div>
    </div>
    <div class="inv-meta-item">
      <label>Zeitraum</label>
      <div class="val"><?= $inv['start_date'] ? date('d.m', strtotime($inv['start_date'])) : '' ?> — <?= $inv['end_date'] ? date('d.m.Y', strtotime($inv['end_date'])) : '' ?></div>
    </div>
    <div class="inv-meta-item">
      <label>Kundentyp</label>
      <div class="val"><?= e($inv['ctype'] ?? '-') ?></div>
    </div>
    <div class="inv-meta-item">
      <label>Status</label>
      <div class="val"><span class="badge-paid <?= $inv['invoice_paid']==='yes' ? 'badge-green' : 'badge-red' ?>"><?= $inv['invoice_paid']==='yes' ? 'Bezahlt' : 'Offen' ?></span></div>
    </div>
  </div>

  <!-- Line Items -->
  <?php if (!empty($lines)): ?>
  <table class="inv-table">
    <thead><tr>
      <th>Datum</th>
      <th>Service</th>
      <th>Adresse</th>
      <th>Stunden</th>
      <th>€/h</th>
      <th>Betrag</th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $l): ?>
    <tr>
      <td><?= date('d.m.Y', strtotime($l['date'])) ?></td>
      <td><?= e($l['service']) ?></td>
      <td style="font-size:12px;color:#6b7280"><?= e($l['address']) ?></td>
      <td><?= number_format($l['hours'], 1) ?></td>
      <td><?= money($l['rate']) ?></td>
      <td style="font-weight:500"><?= money($l['total']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div style="padding:20px 0;color:#6b7280;text-align:center;margin-bottom:30px">Keine verknüpften Jobs (Legacy-Rechnung)</div>
  <?php endif; ?>

  <!-- Totals -->
  <div class="inv-totals">
    <table>
      <tr><td class="label">Netto</td><td class="amount"><?= money($subtotal) ?></td></tr>
      <tr><td class="label">MwSt. (<?= (int)(TAX_RATE * 100) ?>%)</td><td class="amount"><?= money($tax) ?></td></tr>
      <tr class="total-row"><td>Gesamt</td><td class="amount"><?= money($total) ?></td></tr>
      <?php if ($paidAmount > 0 && $inv['invoice_paid'] !== 'yes'): ?>
      <tr><td class="label">Bereits bezahlt</td><td class="amount" style="color:#16a34a">-<?= money($paidAmount) ?></td></tr>
      <tr><td class="label" style="font-weight:600">Offen</td><td class="amount" style="font-weight:700;color:#dc2626"><?= money($inv['remaining_price']) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <!-- Payments -->
  <?php if (!empty($payments)): ?>
  <div class="inv-payments">
    <h4>Zahlungseingänge</h4>
    <?php foreach ($payments as $pay): ?>
    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #e5e7eb">
      <span><?= date('d.m.Y', strtotime($pay['payment_date'])) ?> — <?= e($pay['payment_method']) ?><?= $pay['note'] ? ' ('.e($pay['note']).')' : '' ?></span>
      <span style="font-weight:600;color:#16a34a"><?= money($pay['amount']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Footer -->
  <div class="inv-footer">
    <p style="margin-bottom:8px"><?= SITE ?> &middot; <?= CONTACT_EMAIL ?> &middot; <a href="https://<?= SITE_DOMAIN ?>"><?= SITE_DOMAIN ?></a></p>
    <p>Vielen Dank für Ihr Vertrauen!</p>
    <?php if (!empty($settings['tax_id'])): ?><p style="margin-top:8px">Steuernr.: <?= e($settings['tax_id']) ?></p><?php endif; ?>
  </div>
</div>

</body>
</html>
