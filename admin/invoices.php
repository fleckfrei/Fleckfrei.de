<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Rechnungen'; $page = 'invoices';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
    $act = $_POST['action'] ?? '';
    if ($act === 'mark_paid') {
        q("UPDATE invoices SET invoice_paid='yes', remaining_price=0 WHERE inv_id=?", [$_POST['inv_id']]);
        audit('payment', 'invoice', $_POST['inv_id'], 'Komplett bezahlt');
        header("Location: /admin/invoices.php?paid=1"); exit;
    }
    if ($act === 'add_payment') {
        $amount = (float)$_POST['amount'];
        if ($amount <= 0) { header('Location: /admin/invoices.php?error=1'); exit; }
        q("INSERT INTO invoice_payments (invoice_id_fk, amount, payment_date, payment_method, note) VALUES (?,?,?,?,?)",
          [$_POST['inv_id'], $amount, $_POST['payment_date']??date('Y-m-d'), $_POST['payment_method']??'', $_POST['note']??'']);
        $inv = one("SELECT * FROM invoices WHERE inv_id=?", [$_POST['inv_id']]);
        $newRemaining = max(0, $inv['remaining_price'] - $amount);
        $paid = $newRemaining <= 0 ? 'yes' : 'no';
        q("UPDATE invoices SET remaining_price=?, invoice_paid=? WHERE inv_id=?", [$newRemaining, $paid, $_POST['inv_id']]);
        audit('payment', 'invoice', $_POST['inv_id'], "Zahlung: $amount €");
        header("Location: /admin/invoices.php?payment=1"); exit;
    }
    if ($act === 'delete_invoice') {
        // Unlink jobs from this invoice
        q("UPDATE jobs SET invoice_id=NULL WHERE invoice_id=?", [$_POST['inv_id']]);
        q("DELETE FROM invoices WHERE inv_id=?", [$_POST['inv_id']]);
        audit('delete', 'invoice', $_POST['inv_id'], 'Rechnung gelöscht');
        header("Location: /admin/invoices.php"); exit;
    }
}

$invoices = all("SELECT i.*, c.name as cname FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id ORDER BY i.issue_date DESC");
$totalUnpaid = val("SELECT COALESCE(SUM(remaining_price),0) FROM invoices WHERE invoice_paid='no'");
$customers = all("SELECT customer_id, name FROM customer WHERE status=1 ORDER BY name");
include __DIR__ . '/../includes/layout.php';
?>

<?php if(!empty($_GET['saved'])||!empty($_GET['added'])||!empty($_GET['paid'])||!empty($_GET['payment'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Gespeichert.</div><?php endif; ?>

<?php if($totalUnpaid > 0): ?>
<div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6 flex items-center justify-between">
  <span class="text-red-800 font-medium">Offene Rechnungen: <?= money($totalUnpaid) ?></span>
</div>
<?php endif; ?>

<div x-data="{ genOpen:false, payOpen:false, payInv:{} }">
  <!-- Auto-Generate Info -->
  <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4 flex items-center gap-3">
    <svg class="w-5 h-5 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span class="text-sm text-blue-800">Rechnungen werden automatisch aus erledigten Jobs erstellt. Gehe zu <a href="/admin/work-hours.php" class="font-medium underline">Arbeitszeit</a>, filtere nach Kunde + Monat und klicke "Rechnung erstellen".</span>
  </div>

  <div class="bg-white rounded-xl border">
    <div class="p-5 border-b flex items-center justify-between flex-wrap gap-3">
      <h3 class="font-semibold">Rechnungen (<?= count($invoices) ?>)</h3>
      <div class="flex gap-3 flex-wrap">
        <input type="text" placeholder="Suchen..." class="px-3 py-2 border rounded-lg text-sm w-64" oninput="filterRows(this.value)"/>
        <button @click="genOpen=true" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-medium">Auto-Generieren</button>
        <a href="/admin/bank-import.php" class="px-3 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Bank Import</a>
        <a href="/api/index.php?action=export/invoices&key=<?= API_KEY ?>" class="px-3 py-2 border rounded-lg text-sm text-gray-600 hover:bg-gray-50">CSV Export</a>
      </div>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm" id="tbl"><thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Nr.</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Kunde</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Zeitraum</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Gesamt</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Offen</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Aktionen</th>
      </tr></thead><tbody class="divide-y">
      <?php foreach ($invoices as $inv): ?>
      <tr class="hover:bg-gray-50" id="inv-row-<?= $inv['inv_id'] ?>">
        <td class="px-4 py-3 font-mono font-medium text-xs"><?= e($inv['invoice_number']) ?></td>
        <td class="px-4 py-3 text-xs"><?= e($inv['cname']) ?></td>
        <!-- Datum editierbar -->
        <td class="px-3 py-2">
          <input type="date" value="<?= $inv['issue_date'] ?>" onchange="updateInv(<?= $inv['inv_id'] ?>,'issue_date',this.value)" class="px-2 py-1 text-xs border border-gray-200 rounded-lg bg-white cursor-pointer hover:border-brand"/>
        </td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= $inv['start_date'] ? date('d.m',strtotime($inv['start_date'])) : '' ?> — <?= $inv['end_date'] ? date('d.m',strtotime($inv['end_date'])) : '' ?></td>
        <td class="px-4 py-3 font-medium text-xs"><?= money($inv['total_price']) ?></td>
        <td class="px-4 py-3 text-xs"><?= $inv['remaining_price']>0 ? '<span class="text-red-600 font-medium">'.money($inv['remaining_price']).'</span>' : '<span class="text-green-600">0,00 €</span>' ?></td>
        <!-- Status Dropdown -->
        <td class="px-3 py-2">
          <select onchange="updateInvStatus(<?= $inv['inv_id'] ?>,this.value,this)" class="px-2 py-1 text-xs font-medium border rounded-lg cursor-pointer <?= $inv['invoice_paid']==='yes' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200' ?>">
            <option value="no" <?= $inv['invoice_paid']!=='yes'?'selected':'' ?>>Offen</option>
            <option value="yes" <?= $inv['invoice_paid']==='yes'?'selected':'' ?>>Bezahlt</option>
          </select>
        </td>
        <td class="px-4 py-3"><div class="flex gap-1">
          <a href="/admin/invoice-pdf.php?id=<?=$inv['inv_id']?>" target="_blank" class="px-2 py-1 text-xs bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200" title="PDF anzeigen">PDF</a>
          <?php if ($inv['invoice_paid']!=='yes'): ?>
            <button @click="payInv={inv_id:<?=$inv['inv_id']?>,remaining:<?=$inv['remaining_price']?>}; payOpen=true" class="px-2 py-1 text-xs bg-blue-100 text-blue-700 rounded-lg">Zahlung</button>
          <?php endif; ?>
          <form method="POST" class="inline" onsubmit="return confirm('Rechnung löschen?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_invoice"/><input type="hidden" name="inv_id" value="<?=$inv['inv_id']?>"/><button class="px-2 py-1 text-xs bg-red-50 text-red-600 rounded-lg">Del</button></form>
        </div></td>
      </tr>
      <?php endforeach; ?></tbody></table>
    </div>
  </div>

  <!-- Auto-Generate Invoice Modal -->
  <template x-if="genOpen">
    <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center"><div class="bg-white rounded-2xl p-6 w-full max-w-lg shadow-2xl m-4">
      <h3 class="text-lg font-semibold mb-2">Rechnung auto-generieren</h3>
      <p class="text-sm text-gray-500 mb-4">Erstellt eine Rechnung aus allen erledigten, noch nicht fakturierten Jobs des gewählten Kunden im gewählten Monat.</p>
      <div class="space-y-4" x-data="{ genLoading:false, genResult:null }">
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Kunde</label><select id="gen-customer" required class="w-full px-3 py-2.5 border rounded-xl"><?php foreach($customers as $c): ?><option value="<?=$c['customer_id']?>"><?=e($c['name'])?></option><?php endforeach; ?></select></div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Monat</label><input type="month" id="gen-month" value="<?=date('Y-m')?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div x-show="genResult" class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl text-sm" x-text="genResult"></div>
        <div class="flex gap-3">
          <button type="button" @click="genOpen=false" class="flex-1 px-4 py-2.5 border rounded-xl">Abbrechen</button>
          <button type="button" :disabled="genLoading" @click="
            genLoading=true; genResult=null;
            fetch('/api/index.php?action=invoice/generate',{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':'<?=API_KEY?>'},body:JSON.stringify({customer_id:document.getElementById('gen-customer').value,month:document.getElementById('gen-month').value})})
            .then(r=>r.json()).then(d=>{genLoading=false;if(d.success){genResult=d.data.invoice_number+' erstellt: '+d.data.jobs_count+' Jobs, '+d.data.hours+'h, '+d.data.total.toFixed(2)+' €';setTimeout(()=>location.reload(),1500)}else{genResult='Fehler: '+(d.error||'Unbekannt')}}).catch(()=>{genLoading=false;genResult='Netzwerk-Fehler'})
          " class="flex-1 px-4 py-2.5 bg-brand text-white rounded-xl font-medium" x-text="genLoading ? 'Wird erstellt...' : 'Generieren'"></button>
        </div>
      </div>
    </div></div>
  </template>

  <!-- Add Payment Modal -->
  <template x-if="payOpen">
    <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center"><div class="bg-white rounded-2xl p-6 w-full max-w-md shadow-2xl m-4">
      <h3 class="text-lg font-semibold mb-4">Zahlung erfassen</h3>
      <p class="text-sm text-gray-500 mb-4">Offener Betrag: <span class="font-bold text-red-600" x-text="payInv.remaining?.toFixed(2) + ' €'"></span></p>
      <form method="POST" class="space-y-4">
        <?= csrfField() ?><input type="hidden" name="action" value="add_payment"/><input type="hidden" name="inv_id" :value="payInv.inv_id"/>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Betrag</label><input type="number" name="amount" :value="payInv.remaining" step="0.01" required class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Datum</label><input type="date" name="payment_date" value="<?=date('Y-m-d')?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Methode</label><select name="payment_method" class="w-full px-3 py-2.5 border rounded-xl"><option>Überweisung</option><option>PayPal</option><option>Bar</option><option>Kreditkarte</option></select></div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Notiz</label><input name="note" class="w-full px-3 py-2.5 border rounded-xl"/></div>
        <div class="flex gap-3"><button type="button" @click="payOpen=false" class="flex-1 px-4 py-2.5 border rounded-xl">Abbrechen</button><button type="submit" class="flex-1 px-4 py-2.5 bg-brand text-white rounded-xl font-medium">Zahlung buchen</button></div>
      </form>
    </div></div>
  </template>
</div>
<?php
$apiKey = API_KEY;
$script = <<<JS
function filterRows(q){q=q.toLowerCase();document.querySelectorAll('#tbl tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'})}
function updateInv(id,field,val){
    fetch('/api/index.php?action=invoice/update',{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':'$apiKey'},body:JSON.stringify({inv_id:id,field:field,value:val})})
    .then(r=>r.json()).then(d=>{if(d.success){const row=document.getElementById('inv-row-'+id);row.style.transition='background 0.3s';row.style.background='#dcfce7';setTimeout(()=>{row.style.background='';},800);}else alert(d.error||'Fehler');});
}
function updateInvStatus(id,val,el){
    fetch('/api/index.php?action=invoice/update',{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':'$apiKey'},body:JSON.stringify({inv_id:id,field:'invoice_paid',value:val})})
    .then(r=>r.json()).then(d=>{if(d.success){
        if(val==='yes'){el.className='px-2 py-1 text-xs font-medium border rounded-lg cursor-pointer bg-green-50 text-green-700 border-green-200';}
        else{el.className='px-2 py-1 text-xs font-medium border rounded-lg cursor-pointer bg-red-50 text-red-700 border-red-200';}
        const row=document.getElementById('inv-row-'+id);row.style.transition='background 0.3s';row.style.background='#dcfce7';setTimeout(()=>{row.style.background='';},800);
    }else alert(d.error||'Fehler');});
}
JS;
include __DIR__.'/../includes/footer.php'; ?>
