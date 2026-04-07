<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Bank Import'; $page = 'invoices';

$results = null;
$matched = 0;
$unmatched = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    // Process CSV upload
    if ($act === 'upload' && !empty($_FILES['csv']['tmp_name'])) {
        $file = $_FILES['csv']['tmp_name'];
        $rows = [];
        if (($handle = fopen($file, 'r')) !== false) {
            $header = fgetcsv($handle, 0, ',');
            // N26 uses different separators depending on locale
            if (count($header) <= 1) {
                rewind($handle);
                $header = fgetcsv($handle, 0, ';');
            }
            // Normalize header names
            $headerMap = [];
            foreach ($header as $i => $h) {
                $h = strtolower(trim($h, "\xEF\xBB\xBF\"")); // Remove BOM
                if (str_contains($h, 'datum') || str_contains($h, 'date') || str_contains($h, 'buchung')) $headerMap['date'] = $i;
                elseif (str_contains($h, 'betrag') || str_contains($h, 'amount')) $headerMap['amount'] = $i;
                elseif (str_contains($h, 'empfänger') || str_contains($h, 'empfanger') || str_contains($h, 'payee') || str_contains($h, 'auftraggeber')) $headerMap['payee'] = $i;
                elseif (str_contains($h, 'verwendungszweck') || str_contains($h, 'reference') || str_contains($h, 'zweck')) $headerMap['reference'] = $i;
                elseif (str_contains($h, 'kontonummer') || str_contains($h, 'iban') || str_contains($h, 'account')) $headerMap['iban'] = $i;
            }

            $sep = count(fgetcsv($handle, 0, ';') ?? []) > 1 ? ';' : ',';
            rewind($handle); fgetcsv($handle, 0, $sep); // skip header

            while (($row = fgetcsv($handle, 0, $sep)) !== false) {
                if (count($row) < 3) continue;
                $amount = isset($headerMap['amount']) ? (float)str_replace(['.', ','], ['', '.'], $row[$headerMap['amount']]) : 0;
                if ($amount <= 0) continue; // Only incoming payments

                $rows[] = [
                    'date' => $row[$headerMap['date'] ?? 0] ?? '',
                    'amount' => $amount,
                    'payee' => $row[$headerMap['payee'] ?? 1] ?? '',
                    'reference' => $row[$headerMap['reference'] ?? 4] ?? '',
                    'iban' => $row[$headerMap['iban'] ?? 2] ?? '',
                ];
            }
            fclose($handle);
        }

        // Match with open invoices
        $openInvoices = all("SELECT i.*, c.name as cname, c.email as cemail FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id WHERE i.invoice_paid='no' AND i.remaining_price > 0 ORDER BY i.total_price");
        $results = [];

        foreach ($rows as $tx) {
            $matchedInv = null;
            $matchType = '';

            // Strategy 1: Match by invoice number in reference
            foreach ($openInvoices as $inv) {
                if ($inv['invoice_number'] && stripos($tx['reference'], $inv['invoice_number']) !== false) {
                    $matchedInv = $inv;
                    $matchType = 'Rechnungsnr. im Verwendungszweck';
                    break;
                }
            }

            // Strategy 2: Match by exact amount + customer name in payee/reference
            if (!$matchedInv) {
                foreach ($openInvoices as $inv) {
                    $amountMatch = abs($tx['amount'] - $inv['remaining_price']) < 0.02 || abs($tx['amount'] - $inv['total_price']) < 0.02;
                    $nameMatch = $inv['cname'] && (stripos($tx['payee'], $inv['cname']) !== false || stripos($tx['reference'], $inv['cname']) !== false);
                    if ($amountMatch && $nameMatch) {
                        $matchedInv = $inv;
                        $matchType = 'Betrag + Kundenname';
                        break;
                    }
                }
            }

            // Strategy 3: Match by exact amount only (if unique)
            if (!$matchedInv) {
                $amountMatches = array_filter($openInvoices, fn($inv) => abs($tx['amount'] - $inv['remaining_price']) < 0.02 || abs($tx['amount'] - $inv['total_price']) < 0.02);
                if (count($amountMatches) === 1) {
                    $matchedInv = reset($amountMatches);
                    $matchType = 'Betrag (eindeutig)';
                }
            }

            $results[] = [
                'tx' => $tx,
                'invoice' => $matchedInv,
                'match_type' => $matchType,
            ];
            if ($matchedInv) $matched++;
            else $unmatched++;
        }
    }

    // Apply matches
    if ($act === 'apply') {
        $applied = 0;
        foreach ($_POST['match'] ?? [] as $invId => $amount) {
            $amount = (float)$amount;
            if ($amount <= 0 || !$invId) continue;
            $inv = one("SELECT * FROM invoices WHERE inv_id=?", [(int)$invId]);
            if (!$inv) continue;
            $newRemaining = max(0, $inv['remaining_price'] - $amount);
            $paid = $newRemaining <= 0 ? 'yes' : 'no';
            q("UPDATE invoices SET remaining_price=?, invoice_paid=? WHERE inv_id=?", [$newRemaining, $paid, (int)$invId]);
            audit('payment', 'invoice', (int)$invId, 'Bank-Import: '.money($amount));
            $applied++;
        }
        header("Location: /admin/bank-import.php?applied=$applied"); exit;
    }
}

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['applied'])): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4"><?= (int)$_GET['applied'] ?> Zahlungen verbucht!</div>
<?php endif; ?>

<!-- Bank Import Options -->
<?php if (!$results): ?>
<div class="max-w-2xl mx-auto space-y-6">

  <!-- Auto Bank (Open Banking) -->
  <div class="bg-white rounded-xl border p-6">
    <div class="flex items-start gap-4">
      <div class="w-12 h-12 rounded-xl bg-blue-50 flex items-center justify-center flex-shrink-0">
        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
      </div>
      <div class="flex-1">
        <div class="flex items-center gap-2 mb-1">
          <h2 class="text-lg font-semibold">Automatischer Bank-Import</h2>
          <?php if (FEATURE_AUTO_BANK && OPENBANKING_ACCOUNT_ID): ?>
          <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-700">Verbunden</span>
          <?php else: ?>
          <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-500">Nicht konfiguriert</span>
          <?php endif; ?>
        </div>
        <p class="text-sm text-gray-500 mb-4">Verbinde dein N26 Konto direkt. Zahlungen werden automatisch gematcht — täglich um 9 Uhr, ohne manuellen Aufwand.</p>

        <?php if (FEATURE_AUTO_BANK && OPENBANKING_ACCOUNT_ID): ?>
        <div class="flex gap-3">
          <button onclick="runAutoSync()" class="px-4 py-2.5 bg-blue-600 text-white rounded-xl font-medium text-sm">Jetzt synchronisieren</button>
          <span id="syncStatus" class="text-sm text-gray-400 self-center"></span>
        </div>
        <?php else: ?>
        <div class="bg-blue-50 rounded-lg p-4 text-sm">
          <p class="text-blue-800 font-medium mb-2">So aktivierst du den Auto-Import:</p>
          <ol class="text-blue-700 space-y-1">
            <li>1. Erstelle einen kostenlosen Account bei <a href="https://enablebanking.com" target="_blank" class="underline font-medium">Enable Banking</a></li>
            <li>2. Gib mir die API Keys (Application ID + Secret)</li>
            <li>3. Verbinde dein N26 Konto (einmal autorisieren)</li>
            <li>4. Fertig — ab dann alles automatisch</li>
          </ol>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Manual CSV Import -->
  <div class="bg-white rounded-xl border p-6">
    <div class="flex items-start gap-4">
      <div class="w-12 h-12 rounded-xl bg-brand/10 flex items-center justify-center flex-shrink-0">
        <svg class="w-6 h-6 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
      </div>
      <div class="flex-1">
        <h2 class="text-lg font-semibold mb-1">Manueller CSV Import</h2>
        <p class="text-sm text-gray-500 mb-4">N26 CSV hochladen — System matched automatisch mit offenen Rechnungen.</p>
        <form method="POST" enctype="multipart/form-data" class="flex gap-3">
          <input type="hidden" name="action" value="upload"/>
          <label class="flex-1 border-2 border-dashed border-gray-300 rounded-xl px-4 py-3 cursor-pointer hover:border-brand hover:bg-brand/5 transition text-sm text-gray-500">
            <input type="file" name="csv" accept=".csv" required class="hidden" onchange="this.closest('label').querySelector('span').textContent=this.files[0].name"/>
            <span>CSV-Datei auswählen...</span>
          </label>
          <button type="submit" class="px-6 py-3 bg-brand text-white rounded-xl font-medium text-sm whitespace-nowrap">Importieren</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Recent Import History -->
  <?php
  $recentPayments = allLocal("SELECT ip.*, i.invoice_number, c.name as cname FROM invoice_payments ip LEFT JOIN invoices i ON ip.invoice_id_fk=i.inv_id LEFT JOIN customer c ON i.customer_id_fk=c.customer_id WHERE ip.payment_method LIKE '%Bank%' OR ip.payment_method LIKE '%Auto%' ORDER BY ip.payment_date DESC LIMIT 10");
  if (!empty($recentPayments)):
  ?>
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-3">Letzte Bank-Zahlungen</h3>
    <div class="divide-y">
      <?php foreach ($recentPayments as $p): ?>
      <div class="py-2 flex justify-between text-sm">
        <span><?= date('d.m.Y', strtotime($p['payment_date'])) ?> — <?= e($p['cname'] ?? '') ?> (<?= e($p['invoice_number'] ?? '') ?>)</span>
        <span class="font-medium text-green-600"><?= money($p['amount']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- Results -->
<div class="mb-4 grid grid-cols-3 gap-3">
  <div class="bg-white rounded-xl border p-4 text-center"><div class="text-2xl font-bold text-gray-900"><?= count($results) ?></div><div class="text-xs text-gray-400">Eingänge</div></div>
  <div class="bg-white rounded-xl border p-4 text-center"><div class="text-2xl font-bold text-green-700"><?= $matched ?></div><div class="text-xs text-gray-400">Gematcht</div></div>
  <div class="bg-white rounded-xl border p-4 text-center"><div class="text-2xl font-bold text-amber-600"><?= $unmatched ?></div><div class="text-xs text-gray-400">Kein Match</div></div>
</div>

<form method="POST">
  <input type="hidden" name="action" value="apply"/>
  <div class="bg-white rounded-xl border">
    <div class="p-5 border-b flex items-center justify-between">
      <h3 class="font-semibold">Matching-Ergebnisse</h3>
      <div class="flex gap-2">
        <a href="/admin/bank-import.php" class="px-3 py-2 border rounded-lg text-sm">Neu laden</a>
        <button type="submit" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-medium">Ausgewählte verbuchen</button>
      </div>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50"><tr>
          <th class="px-4 py-3 text-left font-medium text-gray-600 w-8"></th>
          <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
          <th class="px-4 py-3 text-left font-medium text-gray-600">Absender</th>
          <th class="px-4 py-3 text-left font-medium text-gray-600">Verwendungszweck</th>
          <th class="px-4 py-3 text-left font-medium text-gray-600">Betrag</th>
          <th class="px-4 py-3 text-left font-medium text-gray-600">Match</th>
          <th class="px-4 py-3 text-left font-medium text-gray-600">Rechnung</th>
        </tr></thead>
        <tbody class="divide-y">
        <?php foreach ($results as $r): $tx = $r['tx']; $inv = $r['invoice']; ?>
        <tr class="<?= $inv ? 'bg-green-50/50' : 'bg-gray-50/50' ?>">
          <td class="px-4 py-3">
            <?php if ($inv): ?>
            <input type="checkbox" checked name="match[<?= $inv['inv_id'] ?>]" value="<?= $tx['amount'] ?>" class="w-4 h-4 rounded text-brand"/>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 font-mono text-xs"><?= e($tx['date']) ?></td>
          <td class="px-4 py-3 text-xs"><?= e($tx['payee']) ?></td>
          <td class="px-4 py-3 text-xs text-gray-500 max-w-[200px] truncate" title="<?= e($tx['reference']) ?>"><?= e($tx['reference']) ?></td>
          <td class="px-4 py-3 font-medium text-green-700"><?= money($tx['amount']) ?></td>
          <td class="px-4 py-3">
            <?php if ($inv): ?>
            <span class="px-2 py-1 text-[10px] rounded-full bg-green-100 text-green-700 font-medium"><?= $r['match_type'] ?></span>
            <?php else: ?>
            <span class="px-2 py-1 text-[10px] rounded-full bg-gray-100 text-gray-500">Kein Match</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3">
            <?php if ($inv): ?>
            <div class="text-xs">
              <div class="font-medium"><?= e($inv['invoice_number']) ?></div>
              <div class="text-gray-400"><?= e($inv['cname']) ?> — <?= money($inv['remaining_price']) ?> offen</div>
            </div>
            <?php else: ?>
            <span class="text-xs text-gray-400">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
