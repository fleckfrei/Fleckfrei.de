<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$month = $_GET['month'] ?? date('Y-m');
$start = $month . '-01';
$end = date('Y-m-t', strtotime($start));
$monthLabel = date('F Y', strtotime($start));

try {
    // Try invoice_payments first
    $payments = all("SELECT * FROM invoice_payments WHERE invoice_id_fk IS NOT NULL ORDER BY ip_id DESC LIMIT 0");
    // If table exists, query with date filter
    $payments = all("SELECT ip.*, i.invoice_number, c.name as cname
        FROM invoice_payments ip
        LEFT JOIN invoices i ON ip.invoice_id_fk=i.inv_id
        LEFT JOIN customer c ON i.customer_id_fk=c.customer_id
        ORDER BY ip.ip_id DESC");
    // Filter by month in PHP (avoids column name issues)
    $payments = array_filter($payments, function($p) use ($start, $end) {
        $d = $p['payment_date'] ?? $p['issue_date'] ?? $p['created_at'] ?? '';
        return $d >= $start && $d <= $end . ' 23:59:59';
    });
    $payments = array_values($payments);
} catch (Exception $e) {
    $payments = [];
}

$total = array_sum(array_column($payments, 'amount'));
$count = count($payments);

try { $settings = one("SELECT * FROM settings LIMIT 1"); } catch (Exception $e) { $settings = []; }
if (!$settings) $settings = [];
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Kontoauszug <?= $monthLabel ?> — <?= SITE ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Inter', system-ui, sans-serif; color: #1f2937; font-size: 14px; line-height: 1.5; background: #f3f4f6; }
    .page { max-width: 800px; margin: 20px auto; background: white; padding: 60px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .brand { color: <?= BRAND ?>; }
    .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; }
    .logo { display: flex; align-items: center; gap: 12px; }
    .logo-icon { width: 48px; height: 48px; border-radius: 12px; background: <?= BRAND ?>; color: white; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700; }
    .logo-text { font-size: 24px; font-weight: 700; }
    .title { text-align: right; }
    .title h2 { font-size: 28px; font-weight: 300; color: #6b7280; text-transform: uppercase; letter-spacing: 2px; }
    .meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; padding: 16px 0; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; }
    .meta label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; display: block; }
    .meta .val { font-size: 14px; font-weight: 600; margin-top: 2px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
    th { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; text-align: left; padding: 12px 16px; border-bottom: 2px solid #e5e7eb; }
    td { padding: 10px 16px; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
    tr:last-child td { border-bottom: none; }
    .amount { text-align: right; font-weight: 600; color: #16a34a; }
    th:last-child { text-align: right; }
    .total-row { border-top: 2px solid <?= BRAND ?>; }
    .total-row td { padding-top: 12px; font-size: 16px; font-weight: 700; }
    .total-row .amount { color: <?= BRAND ?>; font-size: 18px; }
    .footer { border-top: 1px solid #e5e7eb; padding-top: 20px; text-align: center; color: #9ca3af; font-size: 12px; }
    .controls { max-width: 800px; margin: 20px auto 0; display: flex; gap: 12px; justify-content: flex-end; }
    .btn { padding: 10px 24px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; border: none; }
    .btn-primary { background: <?= BRAND ?>; color: white; }
    .btn-secondary { background: white; color: #374151; border: 1px solid #d1d5db; }
    @media print { body { background: white; } .page { box-shadow: none; margin: 0; padding: 40px; } .controls { display: none; } }
  </style>
</head>
<body>

<div class="controls">
  <a href="/admin/bank-import.php" class="btn btn-secondary">Zurück</a>
  <a href="/api/index.php?action=bank/export&month=<?= e($month) ?>&key=<?= API_KEY ?>" class="btn btn-secondary">CSV Download</a>
  <button onclick="window.print()" class="btn btn-primary">Drucken / PDF</button>
</div>

<div class="page">
  <div class="header">
    <div class="logo">
      <div class="logo-icon"><?= LOGO_LETTER ?></div>
      <div>
        <div class="logo-text"><?= SITE ?></div>
        <div style="color:#6b7280;font-size:12px"><?= SITE_TAGLINE ?></div>
      </div>
    </div>
    <div class="title">
      <h2>Kontoauszug</h2>
      <div class="brand" style="font-size:18px;font-weight:600"><?= $monthLabel ?></div>
    </div>
  </div>

  <div class="meta">
    <div><label>Zeitraum</label><div class="val"><?= date('d.m.Y', strtotime($start)) ?> — <?= date('d.m.Y', strtotime($end)) ?></div></div>
    <div><label>Transaktionen</label><div class="val"><?= $count ?></div></div>
    <div><label>Gesamtbetrag</label><div class="val brand"><?= money($total) ?></div></div>
  </div>

  <?php if ($count > 0): ?>
  <table>
    <thead><tr>
      <th>Datum</th>
      <th>Methode</th>
      <th>Rechnung</th>
      <th>Kunde</th>
      <th>Notiz</th>
      <th>Betrag</th>
    </tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
    <tr>
      <td><?= date('d.m.Y', strtotime($p['payment_date'] ?? $p['issue_date'] ?? $p['created_at'] ?? 'now')) ?></td>
      <td><?= e($p['payment_method'] ?? '-') ?></td>
      <td style="font-family:monospace;font-size:11px"><?= e($p['invoice_number'] ?? '-') ?></td>
      <td><?= e($p['cname'] ?? '-') ?></td>
      <td style="color:#6b7280;font-size:12px"><?= e($p['note'] ?? '') ?></td>
      <td class="amount"><?= money($p['amount']) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row">
      <td colspan="5"><strong>Gesamt</strong></td>
      <td class="amount"><?= money($total) ?></td>
    </tr>
    </tbody>
  </table>
  <?php else: ?>
  <div style="padding:40px 0;text-align:center;color:#6b7280">Keine Transaktionen in diesem Zeitraum.</div>
  <?php endif; ?>

  <?php if (!empty($settings['iban'])): ?>
  <div style="background:#f9fafb;border-radius:12px;padding:20px;margin-bottom:30px">
    <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:#6b7280;margin-bottom:12px">Bankverbindung</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px">
      <?php if (!empty($settings['bank'])): ?><div><span style="color:#6b7280">Bank:</span> <?= e($settings['bank']) ?></div><?php endif; ?>
      <div><span style="color:#6b7280">IBAN:</span> <span style="font-family:monospace"><?= e($settings['iban']) ?></span></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="footer">
    <p><?= SITE ?><?php if (!empty($settings['company'])): ?> — <?= e($settings['company']) ?><?php endif; ?></p>
    <p><?= CONTACT_EMAIL ?> &middot; <?= SITE_DOMAIN ?></p>
    <p style="margin-top:8px;color:#d1d5db">Erstellt am <?= date('d.m.Y H:i') ?></p>
  </div>
</div>

</body>
</html>
