<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Bank Import'; $page = 'invoices';

$results = null;
$matched = 0;
$unmatched = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/bank-import.php'); exit; }
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

// Check bank connection status (before layout)
$obAccountFile = __DIR__ . '/../includes/openbanking_account.txt';
$obAccountIds = [];
if (file_exists($obAccountFile)) {
    $obAccountIds = array_filter(array_map('trim', explode("\n", file_get_contents($obAccountFile))));
    $obAccountIds = array_filter($obAccountIds, fn($a) => $a && $a !== 'Array');
}
$obAccountId = $obAccountIds[0] ?? OPENBANKING_ACCOUNT_ID;
$obConnected = !empty($obAccountId) && $obAccountId !== 'Array';

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['connected'])): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">N26 erfolgreich verbunden!</div>
<?php endif; ?>

<?php if (!empty($_GET['applied'])): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4"><?= (int)$_GET['applied'] ?> Zahlungen verbucht!</div>
<?php endif; ?>

<!-- Bank Import Options -->
<?php if (!$results): ?>
<div class="max-w-2xl mx-auto space-y-6">

  <!-- Auto Bank (Open Banking) -->
  <div class="bg-white rounded-xl border p-6">
    <div class="flex items-start gap-4">
      <div class="w-12 h-12 rounded-xl <?= $obConnected ? 'bg-green-50' : 'bg-brand/10' ?> flex items-center justify-center flex-shrink-0">
        <svg class="w-6 h-6 <?= $obConnected ? 'text-green-600' : 'text-brand' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
      </div>
      <div class="flex-1">
        <div class="flex items-center gap-2 mb-1">
          <h2 class="text-lg font-semibold">Automatischer Bank-Import</h2>
          <?php if ($obConnected): ?>
          <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-700">Verbunden</span>
          <?php else: ?>
          <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-500">Nicht verbunden</span>
          <?php endif; ?>
        </div>
        <p class="text-sm text-gray-500 mb-4">Verbinde dein N26 Konto direkt. Zahlungen werden automatisch gematcht — täglich um 9 Uhr, ohne manuellen Aufwand.</p>

        <?php // $obConnected already set above ?>
        <?php if ($obConnected): ?>
        <div class="flex gap-3 mb-3">
          <button onclick="runAutoSync()" id="syncBtn" class="px-4 py-2.5 bg-brand text-white rounded-xl font-medium text-sm">Jetzt synchronisieren</button>
          <span id="syncStatus" class="text-sm text-gray-400 self-center"></span>
        </div>
        <p class="text-xs text-gray-400">Account: <?= e($obAccountId) ?> | Automatisch täglich 9 Uhr</p>
        <?php elseif (FEATURE_AUTO_BANK): ?>
        <?php
        // Generate auth URL server-side (includes user's IP)
        require_once __DIR__ . '/../includes/openbanking.php';
        $ob = new OpenBanking();
        $authResult = $ob->startAuth('N26', 'DE', 'business');
        $authUrl = $authResult['url'] ?? '';
        ?>
        <?php if ($authUrl): ?>
        <a href="<?= e($authUrl) ?>" class="inline-block px-4 py-2.5 bg-blue-600 text-white rounded-xl font-medium text-sm hover:bg-blue-700 transition">N26 Konto verbinden</a>
        <span class="text-sm text-gray-400 ml-3">Einmalige Autorisierung bei N26</span>
        <?php else: ?>
        <p class="text-sm text-red-600">Auth-URL konnte nicht erstellt werden. <?= e($authResult['message'] ?? '') ?></p>
        <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="bg-blue-50 rounded-lg p-4 text-sm">
          <p class="text-blue-800 font-medium mb-2">Auto-Import nicht konfiguriert</p>
          <p class="text-blue-700">API-Keys fehlen in der Konfiguration.</p>
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

  <!-- Export -->
  <div class="bg-white rounded-xl border p-5 mb-6">
    <h3 class="font-semibold mb-3">Kontoauszug exportieren</h3>
    <div class="flex items-end gap-3">
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Monat</label>
        <input type="month" id="exportMonth" value="<?= date('Y-m') ?>" class="px-3 py-2 border rounded-lg text-sm"/></div>
      <a href="#" onclick="this.href='/api/index.php?action=bank/export&month='+document.getElementById('exportMonth').value+'&key=<?= API_KEY ?>'" class="px-4 py-2 border rounded-lg text-sm font-medium hover:bg-gray-50">CSV Export</a>
      <a href="#" onclick="this.href='/admin/bank-statement.php?month='+document.getElementById('exportMonth').value" target="_blank" class="px-4 py-2 bg-brand text-white rounded-lg text-sm font-medium">PDF Kontoauszug</a>
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

<?php
$apiKey = API_KEY;
$script = <<<JS
function connectBank() {
    fetch('/api/index.php?action=bank/connect', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-API-Key': '$apiKey'},
        body: JSON.stringify({bank_id: 'N26'})
    }).then(r => r.json()).then(d => {
        if (d.success && d.data.url) {
            window.location.href = d.data.url; // Redirect to bank auth
        } else {
            alert('Fehler: ' + (d.error || d.data?.error || 'Verbindung fehlgeschlagen'));
        }
    }).catch(() => alert('Netzwerk-Fehler'));
}

function runAutoSync() {
    const btn = document.getElementById('syncBtn');
    const status = document.getElementById('syncStatus');
    btn.disabled = true; btn.textContent = 'Synchronisiere...';
    status.textContent = '';

    fetch('/api/index.php?action=bank/auto-sync', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-API-Key': '$apiKey'},
        body: '{}'
    }).then(r => r.json()).then(d => {
        btn.disabled = false; btn.textContent = 'Jetzt synchronisieren';
        if (d.success) {
            const data = d.data;
            status.textContent = data.applied + ' Zahlungen gematcht | ' + data.unmatched + ' offen | Kontostand: ' + (data.balance || '?') + ' EUR';
            status.className = 'text-sm text-green-600 self-center';
            if (data.applied > 0) setTimeout(() => location.reload(), 2000);
        } else {
            status.textContent = 'Fehler: ' + (d.error || 'Unbekannt');
            status.className = 'text-sm text-red-600 self-center';
        }
    }).catch(() => { btn.disabled = false; btn.textContent = 'Jetzt synchronisieren'; status.textContent = 'Netzwerk-Fehler'; });
}
JS;
include __DIR__ . '/../includes/footer.php'; ?>
