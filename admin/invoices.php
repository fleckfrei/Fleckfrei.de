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
        $delId = (int)$_POST['inv_id'];
        $delInv = one("SELECT invoice_number FROM invoices WHERE inv_id=?", [$delId]);
        // Unlink jobs (local + remote)
        q("UPDATE jobs SET invoice_id=NULL WHERE invoice_id=?", [$delId]);
        try { qRemote("UPDATE jobs SET invoice_id=NULL WHERE invoice_id=?", [$delId]); } catch (Exception $e) {}
        // Delete invoice (local + remote)
        q("DELETE FROM invoices WHERE inv_id=?", [$delId]);
        try { qRemote("DELETE FROM invoices WHERE inv_id=?", [$delId]); } catch (Exception $e) {}
        audit('delete', 'invoice', $delId, 'Rechnung ' . ($delInv['invoice_number'] ?? '') . ' gelöscht (lokal + remote)');
        telegramNotify("Rechnung " . ($delInv['invoice_number'] ?? $delId) . " gelöscht");
        header("Location: /admin/invoices.php?deleted=1"); exit;
    }
}

$invoices = all("SELECT i.*, c.name as cname FROM invoices i LEFT JOIN customer c ON i.customer_id_fk=c.customer_id ORDER BY i.issue_date DESC");
$totalUnpaid = val("SELECT COALESCE(SUM(remaining_price),0) FROM invoices WHERE invoice_paid='no'");
$customers = all("SELECT c.customer_id, c.name, c.email, c.phone, c.customer_type, CONCAT_WS(' ', ca.street, ca.number, ca.postal_code, ca.city) as address FROM customer c LEFT JOIN customer_address ca ON ca.customer_id_fk=c.customer_id WHERE c.status=1 GROUP BY c.customer_id ORDER BY c.name");
$services = all("SELECT s.s_id, s.title, s.price, s.total_price, s.customer_id_fk, c.name as cname FROM services s LEFT JOIN customer c ON s.customer_id_fk=c.customer_id WHERE s.status=1 ORDER BY s.title");
$nextInvNum = 'FF-' . str_pad((int)val("SELECT COUNT(*)+1 FROM invoices"), 4, '0', STR_PAD_LEFT);
include __DIR__ . '/../includes/layout.php';
?>

<?php if(!empty($_GET['saved'])||!empty($_GET['added'])||!empty($_GET['paid'])||!empty($_GET['payment'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Gespeichert.</div><?php endif; ?>

<?php if($totalUnpaid > 0): ?>
<div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6 flex items-center justify-between">
  <span class="text-red-800 font-medium">Offene Rechnungen: <?= money($totalUnpaid) ?></span>
</div>
<?php endif; ?>

<div x-data="{ genOpen:false, manualOpen:false, payOpen:false, payInv:{} }">
  <!-- Auto-Generate Info -->
  <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4 flex items-center gap-3">
    <svg class="w-5 h-5 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span class="text-sm text-blue-800">Rechnungen werden automatisch aus erledigten Jobs erstellt. Gehe zu <a href="/admin/work-hours.php" class="font-medium underline">Arbeitszeit</a>, filtere nach Kunde + Monat und klicke "Rechnung erstellen".</span>
  </div>

  <div class="bg-white rounded-xl border">
    <div class="p-5 border-b flex items-center justify-between flex-wrap gap-3">
      <div class="flex items-center gap-4">
        <h3 class="font-semibold">Rechnungen (<?= count($invoices) ?>)</h3>
        <!-- Filter Tabs -->
        <div class="flex bg-gray-100 rounded-lg p-0.5" id="filterTabs">
          <button onclick="filterStatus('all')" class="filter-tab px-3 py-1.5 text-xs font-medium rounded-md bg-white text-gray-900 shadow-sm" data-filter="all">Alle <span class="text-gray-400 ml-1"><?= count($invoices) ?></span></button>
          <button onclick="filterStatus('open')" class="filter-tab px-3 py-1.5 text-xs font-medium rounded-md text-gray-500" data-filter="open">Offen <span class="text-red-500 ml-1"><?= count(array_filter($invoices, fn($i) => $i['invoice_paid'] !== 'yes')) ?></span></button>
          <button onclick="filterStatus('paid')" class="filter-tab px-3 py-1.5 text-xs font-medium rounded-md text-gray-500" data-filter="paid">Bezahlt <span class="text-green-500 ml-1"><?= count(array_filter($invoices, fn($i) => $i['invoice_paid'] === 'yes')) ?></span></button>
        </div>
      </div>
      <div class="flex gap-3 flex-wrap">
        <input type="text" id="searchInput" placeholder="Nr, Kunde, Betrag..." class="px-3 py-2 border rounded-lg text-sm w-64" oninput="applyFilters()"/>
        <button @click="manualOpen=true" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-medium">+ Neue Rechnung</button>
        <button @click="genOpen=true" class="px-4 py-2 border border-brand text-brand rounded-xl text-sm font-medium hover:bg-brand-light">Auto-Generieren</button>
        <a href="/admin/bank-import.php" class="px-3 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium">Bank Import</a>
        <?php if (FEATURE_STRIPE): ?><a href="https://dashboard.stripe.com" target="_blank" class="px-3 py-2 border border-purple-200 text-purple-700 rounded-lg text-sm font-medium hover:bg-purple-50">Stripe ↗</a><?php endif; ?>
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
      <tr class="hover:bg-gray-50" id="inv-row-<?= $inv['inv_id'] ?>" data-paid="<?= $inv['invoice_paid']==='yes'?'paid':'open' ?>">
        <td class="px-4 py-3 font-mono font-medium text-xs"><?= e($inv['invoice_number']) ?></td>
        <td class="px-4 py-3 text-xs"><?= e($inv['cname']) ?></td>
        <!-- Datum editierbar -->
        <td class="px-3 py-2">
          <input type="date" value="<?= $inv['issue_date'] ?>" onchange="updateInv(<?= $inv['inv_id'] ?>,'issue_date',this.value)" class="px-2 py-1 text-xs border border-gray-200 rounded-lg bg-white cursor-pointer hover:border-brand"/>
        </td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= $inv['start_date'] ? date('d.m',strtotime($inv['start_date'])) : '' ?> — <?= $inv['end_date'] ? date('d.m',strtotime($inv['end_date'])) : '' ?></td>
        <td class="px-4 py-3 font-medium text-xs"><?= money($inv['total_price']) ?></td>
        <td class="px-4 py-3 text-xs"><?= $inv['remaining_price']>0 ? '<span class="text-red-600 font-medium">'.money($inv['remaining_price']).'</span>' : '<span class="text-green-600">0,00 €</span>' ?></td>
        <!-- Status -->
        <td class="px-3 py-2">
          <?php if ($inv['invoice_paid']==='yes'): ?>
            <span class="px-2 py-1 text-xs font-medium rounded-lg bg-green-50 text-green-700 border border-green-200">Bezahlt ✓</span>
          <?php else: ?>
            <select onchange="updateInvStatus(<?= $inv['inv_id'] ?>,this.value,this)" class="px-2 py-1 text-xs font-medium border rounded-lg cursor-pointer bg-red-50 text-red-700 border-red-200">
              <option value="no" selected>Offen</option>
              <option value="yes">Bezahlt</option>
            </select>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3"><div class="flex gap-1">
          <a href="/admin/invoice-pdf.php?id=<?=$inv['inv_id']?>" target="_blank" class="px-2 py-1 text-xs bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">PDF</a>
          <?php if ($inv['invoice_paid']!=='yes'): ?>
            <button @click="payInv={inv_id:<?=$inv['inv_id']?>,remaining:<?=$inv['remaining_price']?>}; payOpen=true" class="px-2 py-1 text-xs bg-blue-100 text-blue-700 rounded-lg">Zahlung</button>
            <?php if (FEATURE_STRIPE && $inv['remaining_price'] > 0): ?><button onclick="stripeLink(<?=$inv['inv_id']?>)" class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded-lg">Stripe</button><?php endif; ?>
            <form method="POST" class="inline" onsubmit="return confirm('Rechnung #<?= e($inv['invoice_number']) ?> wirklich löschen? Kann nicht rückgängig gemacht werden!')"><?= csrfField() ?><input type="hidden" name="action" value="delete_invoice"/><input type="hidden" name="inv_id" value="<?=$inv['inv_id']?>"/><button class="px-2 py-1 text-xs bg-red-50 text-red-600 rounded-lg">Del</button></form>
          <?php endif; ?>
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

  <!-- Manual Invoice Modal (Full-Width) -->
  <template x-if="manualOpen">
    <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center overflow-y-auto py-6">
      <div class="bg-white rounded-2xl w-full max-w-4xl shadow-2xl m-4" x-data="{
        mLoading:false, mResult:null, mError:null,
        custId:'', custAddr:'', custEmail:'', custPhone:'',
        invNum:'<?= $nextInvNum ?>',
        issueDate:'<?= date('Y-m-d') ?>',
        fromDate:'<?= date('Y-m-01') ?>',
        tillDate:'<?= date('Y-m-t') ?>',
        lines:[{nr:1, service:'', date:'<?= date('Y-m-d') ?>', unit:'Std', hours:2, price:0, tax:19}],
        customers:<?= json_encode(array_map(fn($c)=>['id'=>$c['customer_id'],'name'=>$c['name'],'email'=>$c['email']??'','phone'=>$c['phone']??'','addr'=>$c['address']??'','type'=>$c['customer_type']??''], $customers)) ?>,
        services:<?= json_encode(array_map(fn($s)=>['id'=>$s['s_id'],'title'=>$s['title'],'netto'=>(float)$s['price'],'brutto'=>(float)$s['total_price'],'cust_id'=>$s['customer_id_fk']], $services)) ?>,
        get netto() { return this.lines.reduce((s,l) => s + (l.hours * l.price), 0) },
        get mwst() { return Math.round(this.netto * 0.19 * 100) / 100 },
        get brutto() { return Math.round((this.netto + this.mwst) * 100) / 100 },
        selectCustomer(id) {
            this.custId = id;
            const c = this.customers.find(x => x.id == id);
            if (c) { this.custAddr = c.addr; this.custEmail = c.email; this.custPhone = c.phone; }
            else { this.custAddr = ''; this.custEmail = ''; this.custPhone = ''; }
            this.fetchJobs();
        },
        selectService(idx, svcId) {
            const s = this.services.find(x => x.id == svcId);
            if (s) { this.lines[idx].service = s.title; this.lines[idx].price = s.netto; }
        },
        addLine() { this.lines.push({nr:this.lines.length+1, service:'', date:this.issueDate, unit:'Std', hours:2, price:0, tax:19}); },
        removeLine(idx) { if (this.lines.length > 1) { this.lines.splice(idx,1); this.lines.forEach((l,i) => l.nr = i+1); } },
        fetchJobs() {
            if (!this.custId) return;
            fetch('/api/index.php?action=invoice/jobs&customer_id='+this.custId+'&start='+this.fromDate+'&end='+this.tillDate+'&key=<?=API_KEY?>')
                .then(r=>r.json()).then(d=>{
                    if (d.success && d.data.jobs && d.data.jobs.length > 0) {
                        this.lines = d.data.jobs.filter(j=>!j.invoiced).map((j,i) => ({
                            nr: i+1,
                            service: j.service,
                            date: j.date,
                            unit: 'Std',
                            hours: j.hours,
                            price: j.netto_price,
                            tax: 19
                        }));
                        if (this.lines.length === 0) this.lines = [{nr:1, service:'', date:this.issueDate, unit:'Std', hours:2, price:0, tax:19}];
                    }
                }).catch(()=>{});
        }
      }">
        <!-- Header -->
        <div class="bg-brand-light rounded-t-2xl px-8 py-5 flex items-center justify-between">
          <div>
            <h3 class="text-lg font-bold text-brand">Neue Rechnung</h3>
            <p class="text-sm text-gray-500 mt-0.5">Manuelle Rechnung erstellen</p>
          </div>
          <button @click="manualOpen=false" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>

        <div class="p-8 space-y-6">
          <!-- Row 1: Customer + Address -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <label class="block text-xs font-semibold text-brand uppercase tracking-wide mb-1.5">Kunde</label>
              <select @change="selectCustomer($event.target.value)" x-model="custId" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm focus:border-brand focus:ring-0 transition">
                <option value="">Kunde auswählen...</option>
                <?php foreach($customers as $c): ?><option value="<?=$c['customer_id']?>"><?=e($c['name'])?></option><?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-brand uppercase tracking-wide mb-1.5">Adresse</label>
              <input x-model="custAddr" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm bg-gray-50" readonly/>
              <div class="flex gap-3 mt-1.5 text-xs text-gray-400">
                <span x-show="custEmail" x-text="custEmail"></span>
                <span x-show="custPhone" x-text="custPhone"></span>
              </div>
            </div>
          </div>

          <!-- Row 2: Date + Invoice Number -->
          <div class="grid grid-cols-2 md:grid-cols-4 gap-5">
            <div>
              <label class="block text-xs font-semibold text-brand uppercase tracking-wide mb-1.5">Datum</label>
              <input type="date" x-model="issueDate" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm focus:border-brand focus:ring-0"/>
            </div>
            <div>
              <label class="block text-xs font-semibold text-brand uppercase tracking-wide mb-1.5">Rechnungsnr.</label>
              <input x-model="invNum" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm bg-gray-50 font-mono" readonly/>
            </div>
            <div>
              <label class="block text-xs font-semibold text-brand uppercase tracking-wide mb-1.5">Von</label>
              <input type="date" x-model="fromDate" @change="fetchJobs()" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm focus:border-brand focus:ring-0"/>
            </div>
            <div>
              <label class="block text-xs font-semibold text-brand uppercase tracking-wide mb-1.5">Bis</label>
              <input type="date" x-model="tillDate" @change="fetchJobs()" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm focus:border-brand focus:ring-0"/>
            </div>
          </div>

          <!-- Line Items Table -->
          <div>
            <label class="block text-xs font-semibold text-brand uppercase tracking-wide mb-3">Positionen</label>
            <div class="border-2 border-gray-200 rounded-xl overflow-hidden">
              <table class="w-full text-sm">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 w-10">Nr.</th>
                    <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500">Service / Beschreibung</th>
                    <th class="px-3 py-2.5 text-left text-xs font-semibold text-gray-500 w-28">Datum</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-500 w-16">Einheit</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-500 w-20">Menge</th>
                    <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-500 w-24">Preis €</th>
                    <th class="px-3 py-2.5 text-right text-xs font-semibold text-gray-500 w-24">Gesamt</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-500 w-16">MwSt</th>
                    <th class="w-10"></th>
                  </tr>
                </thead>
                <tbody class="divide-y">
                  <template x-for="(line, idx) in lines" :key="idx">
                    <tr class="hover:bg-gray-50">
                      <td class="px-3 py-2 text-center text-xs text-gray-400 font-mono" x-text="line.nr"></td>
                      <td class="px-3 py-2">
                        <select @change="selectService(idx, $event.target.value)" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm focus:border-brand focus:ring-0">
                          <option value="">Service auswählen...</option>
                          <template x-if="custId && services.some(sv => sv.cust_id == custId)">
                            <optgroup label="Kunde Services">
                              <template x-for="s in services.filter(sv => sv.cust_id == custId)" :key="'c'+s.id">
                                <option :value="s.id" x-text="s.title + (s.netto ? ' — ' + s.netto.toFixed(2) + ' € netto' : '')"></option>
                              </template>
                            </optgroup>
                          </template>
                          <optgroup :label="custId ? 'Alle Services' : 'Service-Katalog'">
                            <template x-for="s in services.filter(sv => !custId || sv.cust_id != custId)" :key="'a'+s.id">
                              <option :value="s.id" x-text="s.title + (s.netto ? ' — ' + s.netto.toFixed(2) + ' €' : '')"></option>
                            </template>
                          </optgroup>
                        </select>
                        <input x-model="line.service" placeholder="oder manuell eingeben..." class="w-full px-2 py-1 border-0 text-xs text-gray-500 focus:ring-0 mt-0.5"/>
                      </td>
                      <td class="px-3 py-2"><input type="date" x-model="line.date" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs focus:border-brand focus:ring-0"/></td>
                      <td class="px-3 py-2">
                        <select x-model="line.unit" class="w-full px-1 py-1.5 border border-gray-200 rounded-lg text-xs text-center focus:border-brand focus:ring-0">
                          <option>Std</option><option>Stk</option><option>Psch</option><option>m²</option>
                        </select>
                      </td>
                      <td class="px-3 py-2"><input type="number" x-model.number="line.hours" min="0.5" step="0.5" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm text-center focus:border-brand focus:ring-0"/></td>
                      <td class="px-3 py-2"><input type="number" x-model.number="line.price" min="0" step="0.01" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm text-right focus:border-brand focus:ring-0"/></td>
                      <td class="px-3 py-2 text-right font-medium text-sm" x-text="(line.hours * line.price).toFixed(2) + ' €'"></td>
                      <td class="px-3 py-2 text-center text-xs text-gray-500" x-text="line.tax + '%'"></td>
                      <td class="px-1 py-2 text-center">
                        <button @click="removeLine(idx)" x-show="lines.length > 1" class="text-red-300 hover:text-red-500 text-lg">&times;</button>
                      </td>
                    </tr>
                  </template>
                </tbody>
              </table>
              <div class="px-3 py-2 bg-gray-50 border-t">
                <button @click="addLine()" class="text-sm text-brand font-medium hover:underline">+ Position hinzufügen</button>
              </div>
            </div>
          </div>

          <!-- Totals + Note -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-xs font-semibold text-brand uppercase tracking-wide mb-1.5">Notiz</label>
              <textarea id="man-desc" rows="3" class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl text-sm focus:border-brand focus:ring-0 resize-none" placeholder="Interne Notiz oder Beschreibung..."></textarea>
            </div>
            <div class="flex flex-col justify-end">
              <div class="bg-gray-50 rounded-xl p-5 space-y-2">
                <div class="flex justify-between text-sm"><span class="text-gray-500">Netto</span><span class="font-medium" x-text="netto.toFixed(2) + ' €'"></span></div>
                <div class="flex justify-between text-sm"><span class="text-gray-500">MwSt 19%</span><span x-text="mwst.toFixed(2) + ' €'"></span></div>
                <div class="flex justify-between text-base border-t-2 border-gray-200 pt-2 mt-1"><span class="font-bold">Brutto</span><span class="font-bold text-brand text-lg" x-text="brutto.toFixed(2) + ' €'"></span></div>
              </div>
            </div>
          </div>

          <!-- Feedback + Actions -->
          <div x-show="mResult" class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl text-sm" x-text="mResult"></div>
          <div x-show="mError" class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl text-sm" x-text="mError"></div>

          <div class="flex gap-3 pt-2">
            <button @click="manualOpen=false" class="px-6 py-3 border-2 border-gray-200 rounded-xl font-medium text-gray-600 hover:bg-gray-50">Abbrechen</button>
            <button :disabled="mLoading" @click="
              mLoading=true; mResult=null; mError=null;
              if (!custId) { mError='Bitte Kunde auswählen'; mLoading=false; return; }
              if (netto <= 0) { mError='Betrag muss > 0 sein'; mLoading=false; return; }
              const desc = lines.map(l => (l.service||'Position') + ' (' + l.hours + 'x ' + l.price.toFixed(2) + ' €)').join(', ');
              const noteEl = document.getElementById('man-desc');
              fetch('/api/index.php?action=invoice/create',{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':'<?=API_KEY?>'},body:JSON.stringify({customer_id:custId, issue_date:issueDate, netto:netto, description:desc + (noteEl.value ? ' | ' + noteEl.value : ''), start_date:fromDate, end_date:tillDate})})
              .then(r=>r.json()).then(d=>{mLoading=false;if(d.success){mResult=d.data.invoice_number+' erstellt: '+d.data.total.toFixed(2)+' € brutto';setTimeout(()=>location.reload(),1500)}else{mError=d.error||'Fehler'}}).catch(()=>{mLoading=false;mError='Netzwerk-Fehler'})
            " class="flex-1 px-6 py-3 bg-brand text-white rounded-xl font-semibold text-base hover:bg-brand-dark transition" x-text="mLoading ? 'Wird erstellt...' : 'Rechnung erstellen'"></button>
          </div>
        </div>
      </div>
    </div>
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
let activeFilter = 'all';
function filterStatus(f) {
    activeFilter = f;
    document.querySelectorAll('.filter-tab').forEach(t => {
        if (t.dataset.filter === f) { t.className = 'filter-tab px-3 py-1.5 text-xs font-medium rounded-md bg-white text-gray-900 shadow-sm'; }
        else { t.className = 'filter-tab px-3 py-1.5 text-xs font-medium rounded-md text-gray-500'; }
    });
    applyFilters();
}
function applyFilters() {
    const q = (document.getElementById('searchInput').value || '').toLowerCase();
    let shown = 0;
    document.querySelectorAll('#tbl tbody tr').forEach(r => {
        const matchStatus = activeFilter === 'all' || r.dataset.paid === activeFilter;
        const matchSearch = !q || r.textContent.toLowerCase().includes(q);
        const visible = matchStatus && matchSearch;
        r.style.display = visible ? '' : 'none';
        if (visible) shown++;
    });
}
function filterRows(q){applyFilters()}
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
function stripeLink(invId){
    const btn=event.target;btn.textContent='...';
    fetch('/api/index.php?action=stripe/checkout',{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':'$apiKey'},body:JSON.stringify({inv_id:invId})})
    .then(r=>r.json()).then(d=>{
        if(d.success&&d.data.checkout_url){
            navigator.clipboard.writeText(d.data.checkout_url).then(()=>{btn.textContent='Kopiert!';setTimeout(()=>{btn.textContent='Stripe';},2000);}).catch(()=>{window.open(d.data.checkout_url,'_blank');btn.textContent='Stripe';});
        }else{alert(d.error||'Fehler');btn.textContent='Stripe';}
    }).catch(()=>{alert('Fehler');btn.textContent='Stripe';});
}
JS;
include __DIR__.'/../includes/footer.php'; ?>
