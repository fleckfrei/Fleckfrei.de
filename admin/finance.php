<?php
/**
 * Admin: Finance Dashboard — Revenue, Expenses, Net Profit
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Finance'; $page = 'finance';

// Handle POST: add/edit/delete expense
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/finance.php'); exit; }
    $act = $_POST['action'] ?? '';
    if ($act === 'save_expense') {
        $exId = (int)($_POST['ex_id'] ?? 0);
        $data = [
            $_POST['category'] ?? 'other',
            trim($_POST['subcategory'] ?? ''),
            trim($_POST['vendor'] ?? ''),
            trim($_POST['description'] ?? ''),
            (float)$_POST['amount'],
            $_POST['date_incurred'] ?? date('Y-m-d'),
            !empty($_POST['is_recurring']) ? 1 : 0,
            $_POST['frequency'] ?? 'one-off',
            trim($_POST['invoice_ref'] ?? ''),
            !empty($_POST['paid_at']) ? $_POST['paid_at'] : null,
            trim($_POST['notes'] ?? ''),
        ];
        if ($exId > 0) {
            $data[] = $exId;
            q("UPDATE expenses SET category=?, subcategory=?, vendor=?, description=?, amount=?, date_incurred=?, is_recurring=?, frequency=?, invoice_ref=?, paid_at=?, notes=? WHERE ex_id=?", $data);
        } else {
            $data[] = me()['id'] ?? null;
            q("INSERT INTO expenses (category, subcategory, vendor, description, amount, date_incurred, is_recurring, frequency, invoice_ref, paid_at, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", $data);
        }
        audit('update', 'expenses', $exId, 'Ausgabe: ' . $data[2]);
        header('Location: /admin/finance.php?saved=1'); exit;
    }
    if ($act === 'delete_expense') {
        q("DELETE FROM expenses WHERE ex_id=?", [(int)$_POST['ex_id']]);
        header('Location: /admin/finance.php?saved=1'); exit;
    }
}

// Period: last 12 months
$periods = all("SELECT YEAR(issue_date) AS y, MONTH(issue_date) AS m,
                   COUNT(*) AS invoice_cnt,
                   ROUND(SUM(total_price),2) AS revenue_gross,
                   ROUND(SUM(CASE WHEN invoice_paid='yes' THEN total_price ELSE 0 END),2) AS revenue_paid,
                   ROUND(SUM(CASE WHEN invoice_paid='no' THEN total_price ELSE 0 END),2) AS revenue_open
               FROM invoices
               WHERE issue_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
               GROUP BY y, m
               ORDER BY y DESC, m DESC") ?: [];

// Expenses by month (both recurring and one-off)
$expenseByMonth = all("SELECT YEAR(date_incurred) AS y, MONTH(date_incurred) AS m,
                           ROUND(SUM(CASE WHEN is_recurring=0 THEN amount ELSE 0 END),2) AS one_off,
                           ROUND(SUM(CASE WHEN is_recurring=1 AND frequency='monthly' THEN amount ELSE 0 END),2) AS recurring_monthly,
                           ROUND(SUM(CASE WHEN is_recurring=1 AND frequency='yearly' THEN amount/12 ELSE 0 END),2) AS recurring_yearly_pro_rata
                       FROM expenses
                       WHERE date_incurred >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                       GROUP BY y, m
                       ORDER BY y DESC, m DESC") ?: [];
$expenseMap = [];
foreach ($expenseByMonth as $e) {
    $expenseMap[$e['y'] . '-' . $e['m']] = (float)$e['one_off'] + (float)$e['recurring_monthly'] + (float)$e['recurring_yearly_pro_rata'];
}

// Current month KPIs
$thisY = (int)date('Y'); $thisM = (int)date('n');
$thisRevenue = (float)val("SELECT COALESCE(SUM(total_price),0) FROM invoices WHERE YEAR(issue_date)=? AND MONTH(issue_date)=?", [$thisY, $thisM]);
$thisRevenuePaid = (float)val("SELECT COALESCE(SUM(total_price),0) FROM invoices WHERE YEAR(issue_date)=? AND MONTH(issue_date)=? AND invoice_paid='yes'", [$thisY, $thisM]);
$thisExpenses = $expenseMap[$thisY . '-' . $thisM] ?? 0;
$thisNet = $thisRevenuePaid - $thisExpenses;

// YTD
$ytdRevenue = (float)val("SELECT COALESCE(SUM(total_price),0) FROM invoices WHERE YEAR(issue_date)=? AND invoice_paid='yes'", [$thisY]);
$ytdExpenseRec = (float)val("SELECT COALESCE(SUM(CASE WHEN frequency='monthly' THEN amount ELSE IF(frequency='yearly', amount/12, 0) END * LEAST(MONTH(CURDATE()), 12)),0) FROM expenses WHERE is_recurring=1");
$ytdExpenseOneOff = (float)val("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE is_recurring=0 AND YEAR(date_incurred)=?", [$thisY]);
$ytdExpenses = $ytdExpenseRec + $ytdExpenseOneOff;
$ytdNet = $ytdRevenue - $ytdExpenses;

// Expenses by category
$byCat = all("SELECT category, ROUND(SUM(amount),2) AS total, COUNT(*) AS cnt
              FROM expenses WHERE YEAR(date_incurred)=? GROUP BY category ORDER BY total DESC", [$thisY]) ?: [];

// Recent expenses
$recentExp = all("SELECT * FROM expenses ORDER BY date_incurred DESC LIMIT 25") ?: [];

include __DIR__ . '/../includes/layout.php';

$mnames = ['-', 'Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
?>
<div class="max-w-6xl mx-auto space-y-6">
  <div>
    <h1 class="text-2xl font-bold">💰 Finance — Gewinn & Ausgaben</h1>
    <p class="text-sm text-gray-600">Live-Rechnungen + Ausgaben-Tracking. Monatlich + YTD.</p>
  </div>

  <?php if (!empty($_GET['saved'])): ?>
  <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl">✓ Gespeichert</div>
  <?php endif; ?>

  <!-- KPI Cards -->
  <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <div class="bg-white rounded-xl border p-5">
      <div class="text-xs text-gray-500">Dieser Monat · Umsatz</div>
      <div class="text-3xl font-bold text-brand mt-1"><?= number_format($thisRevenue,2,',','.') ?>€</div>
      <div class="text-xs text-gray-500 mt-1">davon bezahlt: <?= number_format($thisRevenuePaid,2,',','.') ?>€</div>
    </div>
    <div class="bg-white rounded-xl border p-5">
      <div class="text-xs text-gray-500">Dieser Monat · Ausgaben</div>
      <div class="text-3xl font-bold text-red-600 mt-1"><?= number_format($thisExpenses,2,',','.') ?>€</div>
      <div class="text-xs text-gray-500 mt-1">Recurring + One-Off</div>
    </div>
    <div class="bg-white rounded-xl border p-5 <?= $thisNet > 0 ? 'ring-2 ring-emerald-400' : 'ring-2 ring-red-400' ?>">
      <div class="text-xs text-gray-500">Dieser Monat · Netto-Gewinn</div>
      <div class="text-3xl font-bold <?= $thisNet > 0 ? 'text-emerald-600' : 'text-red-600' ?> mt-1"><?= ($thisNet>=0?'+':'') . number_format($thisNet,2,',','.') ?>€</div>
      <div class="text-xs text-gray-500 mt-1">Paid Revenue − Expenses</div>
    </div>
    <div class="bg-white rounded-xl border p-5">
      <div class="text-xs text-gray-500">YTD <?= $thisY ?></div>
      <div class="text-3xl font-bold <?= $ytdNet > 0 ? 'text-emerald-600' : 'text-red-600' ?> mt-1"><?= ($ytdNet>=0?'+':'') . number_format($ytdNet,0,',','.') ?>€</div>
      <div class="text-xs text-gray-500 mt-1">Rev <?= number_format($ytdRevenue,0,',','.') ?>€ − Exp <?= number_format($ytdExpenses,0,',','.') ?>€</div>
    </div>
  </div>

  <!-- Monthly breakdown table -->
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-3">📊 Monats-Übersicht (letzte 12 Monate)</h3>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
          <tr>
            <th class="px-3 py-2 text-left">Monat</th>
            <th class="px-3 py-2 text-right">Rechnungen</th>
            <th class="px-3 py-2 text-right">Umsatz brutto</th>
            <th class="px-3 py-2 text-right">davon bezahlt</th>
            <th class="px-3 py-2 text-right">offen</th>
            <th class="px-3 py-2 text-right">Ausgaben</th>
            <th class="px-3 py-2 text-right">Netto</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($periods as $p):
            $key = $p['y'] . '-' . $p['m'];
            $exp = $expenseMap[$key] ?? 0;
            $net = (float)$p['revenue_paid'] - $exp;
          ?>
          <tr class="border-t hover:bg-gray-50">
            <td class="px-3 py-2 font-semibold"><?= $mnames[$p['m']] ?> <?= $p['y'] ?></td>
            <td class="px-3 py-2 text-right"><?= (int)$p['invoice_cnt'] ?></td>
            <td class="px-3 py-2 text-right"><?= number_format((float)$p['revenue_gross'],2,',','.') ?>€</td>
            <td class="px-3 py-2 text-right text-emerald-700"><?= number_format((float)$p['revenue_paid'],2,',','.') ?>€</td>
            <td class="px-3 py-2 text-right text-amber-700"><?= number_format((float)$p['revenue_open'],2,',','.') ?>€</td>
            <td class="px-3 py-2 text-right text-red-600"><?= number_format($exp,2,',','.') ?>€</td>
            <td class="px-3 py-2 text-right font-bold <?= $net>=0?'text-emerald-700':'text-red-600' ?>"><?= ($net>=0?'+':'') . number_format($net,2,',','.') ?>€</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Expense breakdown + input -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="bg-white rounded-xl border p-5 md:col-span-1">
      <h3 class="font-semibold mb-3">💸 Ausgaben nach Kategorie (<?= $thisY ?>)</h3>
      <?php $totalY = array_sum(array_column($byCat, 'total')); foreach ($byCat as $c): $pct = $totalY ? ($c['total']/$totalY*100) : 0; ?>
      <div class="mb-2">
        <div class="flex justify-between text-sm"><span class="font-medium"><?= e($c['category']) ?></span><span><?= number_format((float)$c['total'],2,',','.') ?>€</span></div>
        <div class="h-2 bg-gray-100 rounded"><div class="h-2 bg-brand rounded" style="width:<?= round($pct) ?>%"></div></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="bg-white rounded-xl border p-5 md:col-span-2">
      <div class="flex items-center justify-between mb-3">
        <h3 class="font-semibold">📝 Neue Ausgabe erfassen</h3>
      </div>
      <form method="POST" class="grid grid-cols-2 md:grid-cols-3 gap-2 text-sm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_expense"/>
        <select name="category" class="px-2 py-1.5 border rounded col-span-1">
          <option value="server">Server</option>
          <option value="saas">SaaS</option>
          <option value="marketing">Marketing</option>
          <option value="salary">Gehalt</option>
          <option value="partner_payout">Partner-Auszahlung</option>
          <option value="materials">Material</option>
          <option value="office">Office</option>
          <option value="tax">Steuer</option>
          <option value="other">Sonstiges</option>
        </select>
        <input name="vendor" placeholder="Vendor (z.B. Apify)" class="px-2 py-1.5 border rounded col-span-1"/>
        <input name="subcategory" placeholder="Sub-Kategorie" class="px-2 py-1.5 border rounded col-span-1"/>
        <input name="description" placeholder="Beschreibung" class="px-2 py-1.5 border rounded col-span-2"/>
        <input name="amount" type="number" step="0.01" required placeholder="Betrag €" class="px-2 py-1.5 border rounded col-span-1"/>
        <input name="date_incurred" type="date" required value="<?= date('Y-m-d') ?>" class="px-2 py-1.5 border rounded col-span-1"/>
        <select name="frequency" class="px-2 py-1.5 border rounded col-span-1">
          <option value="one-off">Einmalig</option>
          <option value="monthly">Monatlich</option>
          <option value="quarterly">Quartalsweise</option>
          <option value="yearly">Jährlich</option>
        </select>
        <label class="flex items-center gap-1 text-xs col-span-1"><input type="checkbox" name="is_recurring" value="1"/> Wiederkehrend</label>
        <input name="invoice_ref" placeholder="Beleg-Nr." class="px-2 py-1.5 border rounded col-span-1"/>
        <input name="paid_at" type="date" placeholder="Bezahlt am" class="px-2 py-1.5 border rounded col-span-1"/>
        <button type="submit" class="col-span-full md:col-span-1 px-4 py-1.5 bg-brand text-white rounded font-semibold">+ Ausgabe erfassen</button>
      </form>
    </div>
  </div>

  <!-- Recent expenses -->
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-3">📋 Letzte Ausgaben</h3>
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
        <tr><th class="px-3 py-2 text-left">Datum</th><th class="px-3 py-2 text-left">Kategorie</th><th class="px-3 py-2 text-left">Vendor</th><th class="px-3 py-2 text-left">Beschreibung</th><th class="px-3 py-2 text-right">Betrag</th><th class="px-3 py-2 text-center">Recurring</th><th class="px-3 py-2 text-right"></th></tr>
      </thead>
      <tbody>
      <?php foreach ($recentExp as $e): ?>
        <tr class="border-t hover:bg-gray-50">
          <td class="px-3 py-2 text-xs text-gray-500"><?= e(substr($e['date_incurred'],0,10)) ?></td>
          <td class="px-3 py-2"><span class="text-xs px-2 py-0.5 bg-gray-100 rounded"><?= e($e['category']) ?></span></td>
          <td class="px-3 py-2 font-medium"><?= e($e['vendor']) ?></td>
          <td class="px-3 py-2 text-xs text-gray-600"><?= e($e['description']) ?></td>
          <td class="px-3 py-2 text-right font-semibold"><?= number_format((float)$e['amount'],2,',','.') ?>€</td>
          <td class="px-3 py-2 text-center text-xs"><?= $e['is_recurring'] ? '↻ '.$e['frequency'] : '·' ?></td>
          <td class="px-3 py-2 text-right">
            <form method="POST" class="inline" onsubmit="return confirm('Löschen?')">
              <?= csrfField() ?><input type="hidden" name="action" value="delete_expense"/><input type="hidden" name="ex_id" value="<?= $e['ex_id'] ?>"/>
              <button class="text-red-500 hover:underline text-xs">del</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
