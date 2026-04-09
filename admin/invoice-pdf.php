<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$invId = (int)($_GET['id'] ?? 0);
if (!$invId) { header('Location: /admin/invoices.php'); exit; }

$user = me();
$isAdmin = $user['type'] === 'admin';
if ($user['type'] === 'customer') {
    $check = one("SELECT inv_id FROM invoices WHERE inv_id=? AND customer_id_fk=?", [$invId, $user['id']]);
    if (!$check) { header('Location: /customer/'); exit; }
}

$inv = one("SELECT i.*, c.name as cname, c.surname as csurname, c.email as cemail, c.phone as cphone, c.customer_type as ctype
    FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id WHERE i.inv_id=?", [$invId]);
if (!$inv) { header('Location: /admin/invoices.php'); exit; }

try { $addr = one("SELECT * FROM customer_address WHERE customer_id_fk=? ORDER BY ca_id DESC LIMIT 1", [$inv['customer_id_fk']]); } catch (Exception $e) { $addr = null; }

// Check for custom lines first
$customLines = !empty($inv['custom_lines']) ? json_decode($inv['custom_lines'], true) : null;

// Get linked jobs (line items)
$jobs = all("SELECT j.*, s.title as stitle, s.price as sprice, e.name as ename
    FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
    WHERE j.invoice_id=? ORDER BY j.j_date", [$invId]);
if (empty($jobs) && $inv['customer_id_fk'] && $inv['start_date'] && $inv['end_date']) {
    $jobs = all("SELECT j.*, s.title as stitle, s.price as sprice, e.name as ename
        FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
        WHERE j.customer_id_fk=? AND j.j_date BETWEEN ? AND ? AND j.job_status='COMPLETED' AND j.status=1
        ORDER BY j.j_date", [$inv['customer_id_fk'], $inv['start_date'], $inv['end_date']]);
}

try { $payments = all("SELECT * FROM invoice_payments WHERE invoice_id_fk=? ORDER BY payment_date", [$invId]); } catch (Exception $e) { $payments = []; }

// Build lines from custom or jobs
$lines = [];
$subtotal = 0;
if ($customLines) {
    foreach ($customLines as $l) {
        $lineTotal = round(($l['hours'] ?? 0) * ($l['price'] ?? 0), 2);
        $subtotal += $lineTotal;
        $lines[] = [
            'date' => $l['date'] ?? $inv['issue_date'],
            'service' => $l['service'] ?? 'Service',
            'unit' => $l['unit'] ?? 'Std',
            'hours' => $l['hours'] ?? 0,
            'rate' => $l['price'] ?? 0,
            'total' => $lineTotal,
        ];
    }
} else {
    foreach ($jobs as $j) {
        $hrs = max(MIN_HOURS, ($j['total_hours'] ?: $j['j_hours']) ?: 0);
        $price = ($j['sprice'] ?? 0) ?: 0;
        $lineTotal = round($hrs * $price, 2);
        $subtotal += $lineTotal;
        $lines[] = [
            'date' => $j['j_date'],
            'service' => $j['stitle'] ?: 'Service',
            'unit' => 'Std',
            'hours' => $hrs,
            'rate' => $price,
            'total' => $lineTotal,
        ];
    }
}

if (empty($lines)) {
    $subtotal = (float)(($inv['price'] ?? 0) ?: ($inv['total_price'] ?? 0) / (1 + TAX_RATE));
}

$tax = round($subtotal * TAX_RATE, 2);
$total = round($subtotal + $tax, 2);

if (abs($total - (float)($inv['total_price'] ?? 0)) > 0.1 && (float)($inv['total_price'] ?? 0) > 0) {
    $total = (float)$inv['total_price'];
    $tax = round($total - $total / (1 + TAX_RATE), 2);
    $subtotal = $total - $tax;
}

$paidAmount = $total - (float)($inv['remaining_price'] ?? 0);

try { $settings = one("SELECT * FROM settings LIMIT 1"); } catch (Exception $e) { $settings = []; }
if (!$settings) $settings = [];

$linesJson = json_encode(array_map(fn($l) => [
    'service' => $l['service'],
    'date' => $l['date'],
    'unit' => $l['unit'] ?? 'Std',
    'hours' => $l['hours'],
    'price' => $l['rate'],
], $lines));
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
    body { font-family: 'Inter', system-ui, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.4; background: #f3f4f6; }
    .invoice-page { max-width: 800px; margin: 20px auto; background: white; padding: 40px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .brand-color { color: <?= BRAND ?>; }

    .inv-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
    .inv-logo { display: flex; align-items: center; gap: 10px; }
    .inv-logo-icon { width: 36px; height: 36px; border-radius: 8px; background: <?= BRAND ?>; color: white; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; }
    .inv-logo-text { font-size: 20px; font-weight: 700; }
    .inv-number { text-align: right; }
    .inv-number h2 { font-size: 20px; font-weight: 300; color: #6b7280; text-transform: uppercase; letter-spacing: 2px; }
    .inv-number .num { font-size: 14px; font-weight: 600; margin-top: 2px; }

    .inv-addresses { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 16px; font-size: 11px; }
    .inv-addr label { font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; margin-bottom: 4px; display: block; }
    .inv-addr .name { font-size: 13px; font-weight: 600; margin-bottom: 2px; }

    .inv-meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; padding: 8px 0; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; }
    .inv-meta-item label { font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; display: block; }
    .inv-meta-item .val { font-size: 12px; font-weight: 600; margin-top: 1px; }

    .inv-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    .inv-table th { font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; text-align: left; padding: 6px 8px; border-bottom: 2px solid #e5e7eb; }
    .inv-table th:last-child, .inv-table td:last-child { text-align: right; }
    .inv-table td { padding: 5px 8px; border-bottom: 1px solid #f3f4f6; font-size: 11px; }

    .inv-totals { display: flex; justify-content: flex-end; margin-bottom: 16px; }
    .inv-totals table { width: 220px; }
    .inv-totals td { padding: 3px 0; font-size: 12px; }
    .inv-totals .label { color: #6b7280; }
    .inv-totals .amount { text-align: right; font-weight: 500; }
    .inv-totals .total-row td { border-top: 2px solid <?= BRAND ?>; padding-top: 6px; font-size: 15px; font-weight: 700; }
    .inv-totals .total-row .amount { color: <?= BRAND ?>; }

    .inv-payments { background: #f9fafb; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
    .inv-payments h4 { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; margin-bottom: 8px; }

    .badge-paid { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 10px; font-weight: 600; }
    .badge-green { background: #dcfce7; color: #166534; }
    .badge-red { background: #fef2f2; color: #991b1b; }

    .paid-stamp { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%) rotate(-25deg); border: 6px solid #16a34a; color: #16a34a; padding: 12px 40px; font-size: 48px; font-weight: 800; text-transform: uppercase; letter-spacing: 8px; opacity: 0.15; pointer-events: none; border-radius: 12px; white-space: nowrap; z-index: 10; }
    .paid-stamp .stamp-date { display: block; font-size: 14px; letter-spacing: 2px; font-weight: 600; text-align: center; margin-top: 4px; }

    .inv-footer { border-top: 1px solid #e5e7eb; padding-top: 10px; text-align: center; color: #9ca3af; font-size: 10px; }
    .inv-footer a { color: <?= BRAND ?>; text-decoration: none; }

    /* Controls */
    .controls-bar { max-width: 800px; margin: 20px auto 0; display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; align-items: center; }
    .btn { padding: 8px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; transition: all 0.15s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-brand { background: <?= BRAND ?>; color: white; }
    .btn-brand:hover { opacity: 0.9; }
    .btn-outline { background: white; color: #374151; border: 1px solid #d1d5db; }
    .btn-outline:hover { background: #f9fafb; }
    .btn-blue { background: #3b82f6; color: white; }
    .btn-amber { background: #f59e0b; color: white; }
    .btn-green { background: #22c55e; color: white; }
    .save-feedback { display: none; padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; }

    /* Editable cells */
    .editable { cursor: text; border-radius: 4px; padding: 2px 4px; transition: background 0.15s; min-width: 40px; display: inline-block; }
    .editable:hover { background: #fef3c7; }
    .editable:focus { background: #fef9c3; outline: 2px solid #f59e0b; outline-offset: 1px; }
    .edit-mode .inv-table td { position: relative; }

    @media print {
      body { background: white; font-size: 11px; }
      .invoice-page { box-shadow: none; margin: 0; padding: 20px 30px; }
      .controls-bar, .edit-hint { display: none !important; }
      .editable { cursor: default; }
      .editable:hover { background: none; }
    }
  </style>
</head>
<body>

<div class="controls-bar">
  <a href="/admin/invoices.php" class="btn btn-outline">Zurück</a>
  <?php if ($isAdmin): ?>
  <button onclick="toggleEditMode()" class="btn btn-amber" id="editBtn">Bearbeiten</button>
  <button onclick="saveInvoice()" class="btn btn-green" id="saveBtn" style="display:none">Speichern</button>
  <button onclick="addLine()" class="btn btn-outline" id="addLineBtn" style="display:none">+ Position</button>
  <a href="/api/index.php?action=invoice/xrechnung&inv_id=<?= $invId ?>&key=<?= API_KEY ?>" class="btn btn-blue">XRechnung XML</a>
  <?php endif; ?>
  <button onclick="window.print()" class="btn btn-brand">Drucken / PDF</button>
  <span class="save-feedback" id="saveFeedback"></span>
</div>

<?php if ($isAdmin): ?>
<div class="controls-bar edit-hint" id="editHint" style="display:none">
  <div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:10px;padding:8px 16px;font-size:12px;color:#92400e;flex:1">
    Bearbeitungsmodus aktiv — Klicke auf Texte, Mengen oder Preise um sie zu ändern. Produkt-Namen können umbenannt werden.
  </div>
</div>
<?php endif; ?>

<div class="invoice-page" style="position:relative" id="invoicePage">
  <?php if ($inv['invoice_paid'] === 'yes'): ?>
  <div class="paid-stamp">Bezahlt<span class="stamp-date"><?php
    $payDate = '';
    if (!empty($payments)) { $payDate = end($payments)['payment_date'] ?? ''; }
    echo $payDate ? date('d.m.Y', strtotime($payDate)) : date('d.m.Y');
  ?></span></div>
  <?php endif; ?>

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

  <div class="inv-addresses">
    <div class="inv-addr">
      <label>Von</label>
      <div class="name"><?= !empty($settings['company']) ? e($settings['company']) : SITE ?></div>
      <?php if (!empty($settings['street'])): ?><div><?= e($settings['street'].' '.($settings['number']??'')) ?></div>
      <div><?= e(($settings['postal_code']??'').' '.($settings['city']??'')) ?></div><?php endif; ?>
      <div><?= CONTACT_EMAIL ?></div>
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

  <div class="inv-meta">
    <div class="inv-meta-item"><label>Rechnungsdatum</label><div class="val"><?= $inv['issue_date'] ? date('d.m.Y', strtotime($inv['issue_date'])) : '-' ?></div></div>
    <div class="inv-meta-item"><label>Zeitraum</label><div class="val"><?= $inv['start_date'] ? date('d.m', strtotime($inv['start_date'])) : '' ?> — <?= $inv['end_date'] ? date('d.m.Y', strtotime($inv['end_date'])) : '' ?></div></div>
    <div class="inv-meta-item"><label>Kundentyp</label><div class="val"><?= e($inv['ctype'] ?? '-') ?></div></div>
    <div class="inv-meta-item"><label>Status</label><div class="val"><span class="badge-paid <?= $inv['invoice_paid']==='yes' ? 'badge-green' : 'badge-red' ?>"><?= $inv['invoice_paid']==='yes' ? 'Bezahlt' : 'Offen' ?></span></div></div>
  </div>

  <?php if (!empty($lines)): ?>
  <table class="inv-table" id="linesTable">
    <thead><tr>
      <th>Nr.</th>
      <th>Service</th>
      <th>Datum</th>
      <th>Einheit</th>
      <th>Menge</th>
      <th>Netto €/h</th>
      <th>Zwischensumme</th>
      <th>MwSt. <?= (int)(TAX_RATE*100) ?> %</th>
      <th class="del-col" style="display:none;width:30px"></th>
    </tr></thead>
    <tbody>
    <?php $rowNum = 1; foreach ($lines as $l): $lineTax = round($l['total'] * TAX_RATE, 2); ?>
    <tr>
      <td><?= $rowNum++ ?></td>
      <td><span class="editable" data-field="service" contenteditable="false"><?= e($l['service']) ?></span></td>
      <td><span class="editable" data-field="date" contenteditable="false"><?= date('d.m.Y', strtotime($l['date'])) ?></span></td>
      <td><span class="editable" data-field="unit" contenteditable="false"><?= e($l['unit'] ?? 'Std') ?></span></td>
      <td><span class="editable" data-field="hours" contenteditable="false"><?= number_format($l['hours'], 2, ',', '.') ?></span></td>
      <td><span class="editable" data-field="price" contenteditable="false"><?= number_format($l['rate'], 2, ',', '.') ?></span></td>
      <td class="line-total" style="font-weight:500"><?= money($l['total']) ?></td>
      <td class="line-tax"><?= money($lineTax) ?></td>
      <td class="del-col" style="display:none"><button class="del-row" onclick="removeLine(this)" style="color:#ef4444;cursor:pointer;border:none;background:none;font-size:16px">&times;</button></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div style="padding:20px 0;color:#6b7280;text-align:center;margin-bottom:30px">Keine verknüpften Jobs</div>
  <?php endif; ?>

  <div class="inv-totals" id="totalsBlock">
    <table>
      <tr><td class="label">Netto</td><td class="amount" id="totalNetto"><?= money($subtotal) ?></td></tr>
      <tr><td class="label">MwSt. (<?= (int)(TAX_RATE * 100) ?>%)</td><td class="amount" id="totalTax"><?= money($tax) ?></td></tr>
      <tr class="total-row"><td>Gesamt</td><td class="amount" id="totalBrutto"><?= money($total) ?></td></tr>
      <?php if ($paidAmount > 0 && $inv['invoice_paid'] !== 'yes'): ?>
      <tr><td class="label">Bereits bezahlt</td><td class="amount" style="color:#16a34a">-<?= money($paidAmount) ?></td></tr>
      <tr><td class="label" style="font-weight:600">Offen</td><td class="amount" style="font-weight:700;color:#dc2626"><?= money($inv['remaining_price']) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

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

  <?php if (!empty($settings['iban'])): ?>
  <div style="background:#f9fafb;border-radius:12px;padding:20px;margin-bottom:30px">
    <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:1px;color:#6b7280;margin-bottom:12px">Bankverbindung</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px">
      <?php if (!empty($settings['bank'])): ?><div><span style="color:#6b7280">Bank:</span> <?= e($settings['bank']) ?></div><?php endif; ?>
      <div><span style="color:#6b7280">IBAN:</span> <span style="font-family:monospace"><?= e($settings['iban']) ?></span></div>
      <?php if (!empty($settings['bic'])): ?><div><span style="color:#6b7280">BIC:</span> <span style="font-family:monospace"><?= e($settings['bic']) ?></span></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($settings['invoice_text'])): ?>
  <div style="padding:16px 0;font-size:13px;color:#6b7280;border-top:1px solid #e5e7eb;margin-bottom:20px"><?= nl2br(e($settings['invoice_text'])) ?></div>
  <?php endif; ?>

  <div class="inv-footer">
    <p style="margin-bottom:4px"><?= SITE ?><?php if (!empty($settings['company'])): ?> — <?= e($settings['company']) ?><?php endif; ?></p>
    <?php if (!empty($settings['street'])): ?><p><?= e($settings['street'].' '.($settings['number']??'').', '.($settings['postal_code']??'').' '.($settings['city']??'')) ?></p><?php endif; ?>
    <p><?= CONTACT_EMAIL ?> &middot; <a href="https://<?= SITE_DOMAIN ?>"><?= SITE_DOMAIN ?></a></p>
    <?php if (!empty($settings['USt_IdNr'])): ?><p style="margin-top:4px">USt-IdNr: <?= e($settings['USt_IdNr']) ?></p><?php endif; ?>
    <?php if (!empty($settings['fiscal_number'])): ?><p>Finanzamt-Nr.: <?= e($settings['fiscal_number']) ?></p><?php endif; ?>
    <p style="margin-top:12px;color:#6b7280">Vielen Dank für Ihr Vertrauen!</p>
  </div>
</div>

<?php if ($isAdmin): ?>
<script>
const API_KEY = '<?= API_KEY ?>';
const INV_ID = <?= $invId ?>;
let editMode = false;

function toggleEditMode() {
    editMode = !editMode;
    const page = document.getElementById('invoicePage');
    const editables = page.querySelectorAll('.editable');
    const delCols = page.querySelectorAll('.del-col');
    const editBtn = document.getElementById('editBtn');
    const saveBtn = document.getElementById('saveBtn');
    const addLineBtn = document.getElementById('addLineBtn');
    const hint = document.getElementById('editHint');

    editables.forEach(el => el.contentEditable = editMode ? 'true' : 'false');
    delCols.forEach(el => el.style.display = editMode ? '' : 'none');
    editBtn.textContent = editMode ? 'Abbrechen' : 'Bearbeiten';
    editBtn.className = editMode ? 'btn btn-outline' : 'btn btn-amber';
    saveBtn.style.display = editMode ? '' : 'none';
    addLineBtn.style.display = editMode ? '' : 'none';
    hint.style.display = editMode ? '' : 'none';

    if (editMode) {
        page.classList.add('edit-mode');
        // Listen for changes to recalculate
        editables.forEach(el => {
            el.addEventListener('input', recalcTotals);
        });
    } else {
        page.classList.remove('edit-mode');
        location.reload(); // discard unsaved changes
    }
}

function parseNum(str) {
    return parseFloat(String(str).replace(/\./g, '').replace(',', '.')) || 0;
}
function formatMoney(n) {
    return n.toFixed(2).replace('.', ',') + ' €';
}

function recalcTotals() {
    const rows = document.querySelectorAll('#linesTable tbody tr');
    let netto = 0;
    rows.forEach(row => {
        const hours = parseNum(row.querySelector('[data-field="hours"]')?.textContent || '0');
        const price = parseNum(row.querySelector('[data-field="price"]')?.textContent || '0');
        const lineTotal = Math.round(hours * price * 100) / 100;
        const lineTax = Math.round(lineTotal * 0.19 * 100) / 100;
        row.querySelector('.line-total').textContent = formatMoney(lineTotal);
        row.querySelector('.line-tax').textContent = formatMoney(lineTax);
        netto += lineTotal;
    });
    const tax = Math.round(netto * 0.19 * 100) / 100;
    const brutto = Math.round((netto + tax) * 100) / 100;
    document.getElementById('totalNetto').textContent = formatMoney(netto);
    document.getElementById('totalTax').textContent = formatMoney(tax);
    document.getElementById('totalBrutto').textContent = formatMoney(brutto);
}

function addLine() {
    const tbody = document.querySelector('#linesTable tbody');
    const rowCount = tbody.querySelectorAll('tr').length + 1;
    const today = new Date().toLocaleDateString('de-DE', {day:'2-digit',month:'2-digit',year:'numeric'});
    const tr = document.createElement('tr');
    tr.innerHTML = '<td>' + rowCount + '</td>' +
        '<td><span class="editable" data-field="service" contenteditable="true">Neue Position</span></td>' +
        '<td><span class="editable" data-field="date" contenteditable="true">' + today + '</span></td>' +
        '<td><span class="editable" data-field="unit" contenteditable="true">Std</span></td>' +
        '<td><span class="editable" data-field="hours" contenteditable="true">1,00</span></td>' +
        '<td><span class="editable" data-field="price" contenteditable="true">0,00</span></td>' +
        '<td class="line-total" style="font-weight:500">0,00 €</td>' +
        '<td class="line-tax">0,00 €</td>' +
        '<td class="del-col"><button class="del-row" onclick="removeLine(this)" style="color:#ef4444;cursor:pointer;border:none;background:none;font-size:16px">&times;</button></td>';
    tbody.appendChild(tr);
    tr.querySelectorAll('.editable').forEach(el => el.addEventListener('input', recalcTotals));
    recalcTotals();
}

function removeLine(btn) {
    const row = btn.closest('tr');
    row.remove();
    // Renumber
    document.querySelectorAll('#linesTable tbody tr').forEach((r, i) => { r.children[0].textContent = i + 1; });
    recalcTotals();
}

function saveInvoice() {
    const rows = document.querySelectorAll('#linesTable tbody tr');
    const lines = [];
    rows.forEach(row => {
        const service = row.querySelector('[data-field="service"]')?.textContent?.trim() || 'Service';
        const dateStr = row.querySelector('[data-field="date"]')?.textContent?.trim() || '';
        const unit = row.querySelector('[data-field="unit"]')?.textContent?.trim() || 'Std';
        const hours = parseNum(row.querySelector('[data-field="hours"]')?.textContent || '0');
        const price = parseNum(row.querySelector('[data-field="price"]')?.textContent || '0');
        // Convert dd.mm.yyyy to yyyy-mm-dd
        let isoDate = dateStr;
        const parts = dateStr.match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
        if (parts) isoDate = parts[3] + '-' + parts[2] + '-' + parts[1];
        lines.push({ service, date: isoDate, unit, hours, price });
    });

    const fb = document.getElementById('saveFeedback');
    fb.style.display = 'inline-block';
    fb.style.background = '#fef3c7';
    fb.style.color = '#92400e';
    fb.textContent = 'Speichern...';

    fetch('/api/index.php?action=invoice/save-lines', {
        method: 'POST',
        headers: {'Content-Type':'application/json', 'X-API-Key': API_KEY},
        body: JSON.stringify({ inv_id: INV_ID, lines: lines })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            fb.style.background = '#dcfce7';
            fb.style.color = '#166534';
            fb.textContent = 'Gespeichert! ' + d.data.total.toFixed(2) + ' €';
            setTimeout(() => location.reload(), 1200);
        } else {
            fb.style.background = '#fef2f2';
            fb.style.color = '#991b1b';
            fb.textContent = d.error || 'Fehler';
        }
    })
    .catch(() => {
        fb.style.background = '#fef2f2';
        fb.style.color = '#991b1b';
        fb.textContent = 'Netzwerk-Fehler';
    });
}
</script>
<?php endif; ?>

</body>
</html>
