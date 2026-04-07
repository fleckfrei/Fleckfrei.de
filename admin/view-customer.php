<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$cid = (int)($_GET['id'] ?? 0);
if (!$cid) { header('Location: /admin/customers.php'); exit; }

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        // Build permissions JSON from checkboxes
        $perms = [];
        $allPerms = ['dashboard','jobs','invoices','workhours','profile','booking','documents','messages','cancel','recurring',
            'wh_datum','wh_service','wh_mitarbeiter','wh_stunden','wh_umsatz','wh_fotos','wh_start_ende',
            'inv_betrag','inv_pdf','inv_status',
            'jobs_status','jobs_ma','jobs_adresse','jobs_zeit'];
        foreach ($allPerms as $p) {
            $perms[$p] = !empty($_POST['perm_'.$p]) ? 1 : 0;
        }
        $permsJson = json_encode($perms);
        $pw = !empty($_POST['password']) ? $_POST['password'] : null;
        if ($pw) {
            q("UPDATE customer SET name=?,surname=?,email=?,phone=?,customer_type=?,status=?,notes=?,password=?,email_permissions=? WHERE customer_id=?",
              [$_POST['name'],$_POST['surname']??'',$_POST['email'],$_POST['phone']??'',$_POST['customer_type'],$_POST['status'],$_POST['notes']??'',$pw,$permsJson,$cid]);
        } else {
            q("UPDATE customer SET name=?,surname=?,email=?,phone=?,customer_type=?,status=?,notes=?,email_permissions=? WHERE customer_id=?",
              [$_POST['name'],$_POST['surname']??'',$_POST['email'],$_POST['phone']??'',$_POST['customer_type'],$_POST['status'],$_POST['notes']??'',$permsJson,$cid]);
        }
        audit('update', 'customer', $cid, 'Stammdaten bearbeitet');
        header("Location: /admin/view-customer.php?id=$cid&saved=1"); exit;
    }
    if ($act === 'add_note') {
        q("INSERT INTO customer_notes (customer_id_fk, author, message, created_at) VALUES (?,?,?,NOW())",
          [$cid, $_POST['author']??'Admin', $_POST['message']]);
        header("Location: /admin/view-customer.php?id=$cid&tab=notes#notes"); exit;
    }
}

$c = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);
if (!$c) { header('Location: /admin/customers.php'); exit; }

$title = $c['name']; $page = 'customers';
$tab = $_GET['tab'] ?? 'info';

// Customer data
try { $addresses = all("SELECT * FROM customer_address WHERE customer_id_fk=?", [$cid]); } catch (Exception $e) { $addresses = []; }
$jobs = all("SELECT j.*, s.title as stitle, e.name as ename, e.surname as esurname FROM jobs j LEFT JOIN services s ON j.s_id_fk=s.s_id LEFT JOIN employee e ON j.emp_id_fk=e.emp_id WHERE j.customer_id_fk=? AND j.status=1 ORDER BY j.j_date DESC LIMIT 100", [$cid]);
$invoices = all("SELECT inv_id, customer_id_fk, invoice_number, issue_date, total_price, remaining_price, invoice_paid FROM invoices WHERE customer_id_fk=? ORDER BY issue_date DESC", [$cid]);
$services = all("SELECT * FROM services WHERE customer_id_fk=? AND status=1", [$cid]);

// Work hours not used here — computed in workhours tab from jobs table

// Stats
$totalJobs = val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1", [$cid]);
$completedJobs = val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='COMPLETED'", [$cid]);
$totalRevenue = val("SELECT COALESCE(SUM(total_price),0) FROM invoices WHERE customer_id_fk=? AND invoice_paid='yes'", [$cid]);
$openAmount = val("SELECT COALESCE(SUM(remaining_price),0) FROM invoices WHERE customer_id_fk=? AND invoice_paid='no'", [$cid]);

include __DIR__ . '/../includes/layout.php';
?>

<?php if(!empty($_GET['saved'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Gespeichert!</div><?php endif; ?>

<!-- Header -->
<div class="flex flex-wrap items-center justify-between gap-4 mb-5">
  <div class="flex items-center gap-4">
    <a href="/admin/customers.php" class="text-gray-400 hover:text-gray-600">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>
    <div class="w-12 h-12 rounded-xl bg-brand text-white flex items-center justify-center text-lg font-bold"><?= strtoupper(substr($c['name'],0,1)) ?></div>
    <div>
      <h2 class="text-lg font-semibold text-gray-900"><?= e($c['name']) ?> <?= e($c['surname']) ?></h2>
      <div class="flex items-center gap-2 text-xs text-gray-400">
        <span class="px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-medium"><?= e($c['customer_type']) ?></span>
        <span>#<?= $c['customer_id'] ?></span>
        <span><?= $c['status'] ? '● Aktiv' : '○ Inaktiv' ?></span>
      </div>
    </div>
  </div>
  <div class="flex gap-2">
    <?php if ($c['phone']): $ph = preg_replace('/[^+0-9]/','',$c['phone']); ?>
    <a href="tel:<?= e($ph) ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">📞 Anrufen</a>
    <a href="https://wa.me/<?= ltrim($ph,'+') ?>" target="_blank" class="px-3 py-2 bg-green-600 text-white rounded-lg text-sm">💬 WhatsApp</a>
    <?php endif; ?>
    <a href="mailto:<?= e($c['email']) ?>" class="px-3 py-2 border rounded-lg text-sm hover:bg-gray-50">✉ E-Mail</a>
    <a href="/admin/customers.php?impersonate=<?= $cid ?>" class="px-3 py-2 bg-blue-600 text-white rounded-lg text-sm">Als Kunde einloggen</a>
  </div>
</div>

<!-- Quick Stats -->
<div class="grid grid-cols-4 gap-3 mb-5">
  <div class="bg-white rounded-xl border p-4 text-center">
    <div class="text-2xl font-bold text-gray-900"><?= $totalJobs ?></div>
    <div class="text-xs text-gray-400">Jobs gesamt</div>
  </div>
  <div class="bg-white rounded-xl border p-4 text-center">
    <div class="text-2xl font-bold text-green-700"><?= $completedJobs ?></div>
    <div class="text-xs text-gray-400">Erledigt</div>
  </div>
  <div class="bg-white rounded-xl border p-4 text-center">
    <div class="text-2xl font-bold text-brand"><?= money($totalRevenue) ?></div>
    <div class="text-xs text-gray-400">Umsatz</div>
  </div>
  <div class="bg-white rounded-xl border p-4 text-center">
    <div class="text-2xl font-bold <?= $openAmount > 0 ? 'text-red-600' : 'text-gray-400' ?>"><?= money($openAmount) ?></div>
    <div class="text-xs text-gray-400">Offen</div>
  </div>
</div>

<!-- Tabs -->
<div class="flex gap-1 mb-5 bg-white rounded-xl border p-1 w-fit">
  <?php
  $tabs = ['info'=>'Stammdaten','jobs'=>'Jobs ('.$totalJobs.')','invoices'=>'Rechnungen ('.count($invoices).')','workhours'=>'Arbeitsstunden','services'=>'Services ('.count($services).')','notes'=>'Notizen'];
  foreach ($tabs as $tk=>$tl):
    $active = $tab===$tk ? 'bg-brand text-white' : 'text-gray-500 hover:bg-gray-100';
  ?>
  <a href="?id=<?= $cid ?>&tab=<?= $tk ?>" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $active ?>"><?= $tl ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'info'): ?>
<!-- Stammdaten -->
<form method="POST">
  <input type="hidden" name="action" value="save"/>
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold mb-4">Persönliche Daten</h3>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Name *</label><input name="name" value="<?= e($c['name']) ?>" required class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Nachname</label><input name="surname" value="<?= e($c['surname']) ?>" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">E-Mail *</label><input type="email" name="email" value="<?= e($c['email']) ?>" required class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Telefon</label><input name="phone" value="<?= e($c['phone']) ?>" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Typ</label>
          <select name="customer_type" class="w-full px-3 py-2 border rounded-xl text-sm"><?php foreach(['Private Person','Company','Airbnb','Host'] as $t): ?><option <?= $c['customer_type']===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
          <select name="status" class="w-full px-3 py-2 border rounded-xl text-sm"><option value="1" <?= $c['status']?'selected':'' ?>>Aktiv</option><option value="0" <?= !$c['status']?'selected':'' ?>>Inaktiv</option></select></div>
        <div><label class="block text-xs font-medium text-gray-500 mb-1">Passwort setzen</label><input type="password" name="password" value="" placeholder="Neues Passwort (leer = unverändert)" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
      </div>
      <!-- Portal-Rechte: granular mit Sub-Permissions -->
      <div class="mt-4 pt-4 border-t">
        <h4 class="text-xs font-semibold text-gray-500 uppercase mb-3">Portal-Rechte (Kunde sieht/kann)</h4>
        <?php
        $perms = json_decode($c['email_permissions'] ?? '{}', true);
        if (!is_array($perms)) $perms = ($c['email_permissions'] === 'all' || $c['email_permissions'] === '') ?
          ['dashboard'=>1,'jobs'=>1,'invoices'=>1,'workhours'=>1,'profile'=>1,'booking'=>1,'documents'=>1,'messages'=>1,
           'wh_datum'=>1,'wh_service'=>1,'wh_mitarbeiter'=>1,'wh_stunden'=>1,'wh_umsatz'=>1,'wh_fotos'=>1,'wh_start_ende'=>1,
           'inv_betrag'=>1,'inv_pdf'=>1,'inv_status'=>1,
           'jobs_status'=>1,'jobs_ma'=>1,'jobs_adresse'=>1,'jobs_zeit'=>1] : [];

        // Main permissions with groups
        $groups = [
          'Seiten' => [
            'dashboard' => ['Dashboard', 'Übersicht mit Stats'],
            'profile' => ['Profil bearbeiten', 'Name, Telefon, Adresse'],
            'booking' => ['Neue Buchung', 'Job über Portal buchen'],
            'messages' => ['Nachrichten', 'An Admin senden'],
          ],
          'Jobs' => [
            'jobs' => ['Jobs sehen', 'Liste eigener Jobs'],
            'jobs_status' => ['— Status', 'Status-Badge sehen'],
            'jobs_ma' => ['— Partner', 'Wer kommt'],
            'jobs_adresse' => ['— Adresse', 'Adresse sehen'],
            'jobs_zeit' => ['— Start/Ende', 'Echte Zeiten sehen'],
            'cancel' => ['— Stornieren', 'Jobs selbst absagen'],
            'recurring' => ['— Wiederkehrende', 'Serien verwalten'],
          ],
          'Rechnungen' => [
            'invoices' => ['Rechnungen sehen', 'Rechnungsliste'],
            'inv_betrag' => ['— Betrag', 'Preise sehen'],
            'inv_pdf' => ['— PDF Download', 'Rechnung herunterladen'],
            'inv_status' => ['— Zahlstatus', 'Bezahlt/Offen sehen'],
          ],
          'Arbeitsstunden' => [
            'workhours' => ['Arbeitsstunden sehen', 'Stunden-Übersicht'],
            'wh_datum' => ['— Datum', 'Wann gearbeitet'],
            'wh_service' => ['— Service', 'Welcher Service'],
            'wh_mitarbeiter' => ['— Partner', 'Wer gearbeitet hat'],
            'wh_stunden' => ['— Stunden', 'Wie lange'],
            'wh_start_ende' => ['— Start/Ende', 'Echte Start- & Endzeit'],
            'wh_umsatz' => ['— Preis/Umsatz', 'Kosten pro Job'],
            'wh_fotos' => ['— Fotos/Videos', 'Bilder vom Job sehen'],
          ],
          'Medien' => [
            'documents' => ['Dokumente', 'Fotos/Videos ansehen'],
          ],
        ];
        foreach ($groups as $gLabel => $gPerms): ?>
        <div class="mb-3">
          <div class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide mb-1 mt-2"><?= $gLabel ?></div>
          <?php foreach ($gPerms as $pk => $pv):
            $isSub = str_starts_with($pv[0], '—');
          ?>
          <label class="flex items-center gap-2.5 py-1 cursor-pointer hover:bg-gray-50 rounded-lg px-2 -mx-2 <?= $isSub ? 'ml-4' : '' ?>">
            <input type="checkbox" name="perm_<?= $pk ?>" value="1" <?= !empty($perms[$pk]) ? 'checked' : '' ?> class="w-3.5 h-3.5 rounded border-gray-300 text-brand focus:ring-brand"/>
            <div class="flex items-center gap-2 flex-1">
              <span class="text-xs font-medium <?= $isSub ? 'text-gray-500' : 'text-gray-700' ?>"><?= $pv[0] ?></span>
              <span class="text-[9px] text-gray-300"><?= $pv[1] ?></span>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <div class="flex gap-2 mt-2 pt-2 border-t">
          <button type="button" onclick="document.querySelectorAll('[name^=perm_]').forEach(c=>c.checked=true)" class="text-xs text-brand hover:underline">Alle an</button>
          <button type="button" onclick="document.querySelectorAll('[name^=perm_]').forEach(c=>c.checked=false)" class="text-xs text-red-500 hover:underline">Alle aus</button>
        </div>
      </div>
      </div>
      <div class="mt-3"><label class="block text-xs font-medium text-gray-500 mb-1">Notizen</label><textarea name="notes" rows="3" class="w-full px-3 py-2 border rounded-xl text-sm"><?= e($c['notes']) ?></textarea></div>
      <button type="submit" class="w-full px-4 py-2.5 bg-brand text-white rounded-xl font-medium mt-3">Speichern</button>
    </div>

    <div class="space-y-5">
      <!-- Addresses -->
      <div class="bg-white rounded-xl border p-5">
        <h3 class="font-semibold mb-3">Adressen (<?= count($addresses) ?>)</h3>
        <?php foreach ($addresses as $a): ?>
        <div class="bg-gray-50 rounded-lg p-3 mb-2 text-sm">
          <div class="font-medium"><?= e($a['street']) ?> <?= e($a['number']) ?></div>
          <div class="text-gray-500"><?= e($a['postal_code']) ?> <?= e($a['city']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($addresses)): ?><p class="text-sm text-gray-400">Keine Adressen.</p><?php endif; ?>
      </div>

      <!-- Meta -->
      <div class="bg-white rounded-xl border p-5">
        <h3 class="font-semibold mb-3">System-Info</h3>
        <div class="grid grid-cols-2 gap-2 text-sm">
          <div class="text-gray-500">Kunden-ID</div><div class="font-mono"><?= $c['customer_id'] ?></div>
          <div class="text-gray-500">Erstellt</div><div><?= $c['created_at'] ?? '-' ?></div>
          <div class="text-gray-500">WhatsApp ID</div><div class="font-mono"><?= e($c['wa_id'] ?: '-') ?></div>
          <div class="text-gray-500">iCal Token</div><div class="font-mono text-xs"><?= e($c['ical_token'] ?: '-') ?></div>
        </div>
      </div>
    </div>
  </div>
</form>

<?php elseif ($tab === 'jobs'): ?>
<!-- Jobs -->
<div class="bg-white rounded-xl border">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Zeit</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Service</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Partner</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Std</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($jobs as $j): ?>
      <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='/admin/jobs.php?view=<?= $j['j_id'] ?>'">
        <td class="px-4 py-3 font-mono"><?= date('d.m.Y', strtotime($j['j_date'])) ?></td>
        <td class="px-4 py-3 font-mono"><?= substr($j['j_time'],0,5) ?></td>
        <td class="px-4 py-3"><?= e($j['stitle'] ?: '-') ?></td>
        <td class="px-4 py-3"><?= e(($j['ename']??'').' '.($j['esurname']??'')) ?: '<span class="text-red-400">—</span>' ?></td>
        <td class="px-4 py-3"><?= $j['j_hours'] ?>h</td>
        <td class="px-4 py-3"><?= badge($j['job_status']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'invoices'): ?>
<!-- Rechnungen -->
<div class="bg-white rounded-xl border">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Nr.</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Betrag</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Bezahlt</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Offen</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($invoices as $inv): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 font-mono"><?= e($inv['invoice_number']) ?></td>
        <td class="px-4 py-3"><?= $inv['issue_date'] ? date('d.m.Y', strtotime($inv['issue_date'])) : '-' ?></td>
        <td class="px-4 py-3 font-medium"><?= money($inv['total_price']) ?></td>
        <td class="px-4 py-3"><?= money($inv['total_price'] - $inv['remaining_price']) ?></td>
        <td class="px-4 py-3 <?= $inv['remaining_price'] > 0 ? 'text-red-600 font-medium' : '' ?>"><?= money($inv['remaining_price']) ?></td>
        <td class="px-4 py-3"><?= $inv['invoice_paid']==='yes' ? '<span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Bezahlt</span>' : '<span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700">Offen</span>' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'workhours'): ?>
<!-- Arbeitsstunden -->
<div class="flex gap-2 mb-4">
  <a href="/api/index.php?action=export/workhours&customer_id=<?= $cid ?>&month=<?= date('Y-m') ?>&key=<?= API_KEY ?>" class="px-3 py-2 border rounded-lg text-sm text-gray-600 hover:bg-gray-50">CSV Export (<?= date('M Y') ?>)</a>
  <button onclick="genInvoice(<?= $cid ?>)" class="px-3 py-2 bg-brand text-white rounded-lg text-sm font-medium">Rechnung aus Jobs erstellen</button>
</div>
<script>
function genInvoice(cid){
    const m=prompt('Für welchen Monat? (YYYY-MM)','<?= date('Y-m') ?>');
    if(!m)return;
    fetch('/api/index.php?action=invoice/generate',{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':'<?= API_KEY ?>'},body:JSON.stringify({customer_id:cid,month:m})})
    .then(r=>r.json()).then(d=>{if(d.success)alert('Rechnung '+d.data.invoice_number+' erstellt! '+d.data.jobs_count+' Jobs, '+d.data.total.toFixed(2)+' €');else alert(d.error||'Fehler');});
}
</script>
<?php
$wh = all("SELECT j.j_date, j.j_time, j.j_hours, j.total_hours, j.job_status,
    s.title as stitle, s.total_price as sprice,
    e.name as ename, e.surname as esurname, e.tariff
    FROM jobs j
    LEFT JOIN services s ON j.s_id_fk=s.s_id
    LEFT JOIN employee e ON j.emp_id_fk=e.emp_id
    WHERE j.customer_id_fk=? AND j.status=1 AND j.job_status='COMPLETED'
    ORDER BY j.j_date DESC", [$cid]);
$totalH = 0; $totalRev = 0;
foreach ($wh as $w) {
    $h = $w['total_hours'] ?: $w['j_hours'];
    $custH = max(2, $h); // Min 2h Regel
    $totalH += $custH;
    $price = $w['sprice'] ?: 0;
    $totalRev += $custH * $price;
}
?>
<div class="bg-white rounded-xl border mb-4 p-4">
  <div class="grid grid-cols-3 gap-4 text-center">
    <div><div class="text-2xl font-bold text-gray-900"><?= count($wh) ?></div><div class="text-xs text-gray-400">Erledigte Jobs</div></div>
    <div><div class="text-2xl font-bold text-brand"><?= round($totalH, 1) ?>h</div><div class="text-xs text-gray-400">Stunden gesamt (Kd.)</div></div>
    <div><div class="text-2xl font-bold text-emerald-700"><?= money($totalRev) ?></div><div class="text-xs text-gray-400">Umsatz (berechnet)</div></div>
  </div>
</div>
<div class="bg-white rounded-xl border">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Datum</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Service</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Partner</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Std (real)</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Std (Kunde)</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">€/h</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Umsatz</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($wh as $w):
        $realH = $w['total_hours'] ?: $w['j_hours'];
        $custH = max(2, $realH);
        $price = $w['sprice'] ?: 0;
        $rev = $custH * $price;
      ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 font-mono"><?= date('d.m.Y', strtotime($w['j_date'])) ?></td>
        <td class="px-4 py-3"><?= e($w['stitle'] ?: '-') ?></td>
        <td class="px-4 py-3"><?= e(($w['ename']??'').' '.($w['esurname']??'')) ?></td>
        <td class="px-4 py-3"><?= round($realH,1) ?>h</td>
        <td class="px-4 py-3 font-medium"><?= round($custH,1) ?>h<?= $realH < 2 ? ' <span class="text-xs text-gray-400">(Min.2h)</span>' : '' ?></td>
        <td class="px-4 py-3"><?= money($price) ?></td>
        <td class="px-4 py-3 font-medium text-brand"><?= money($rev) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($wh)): ?><tr><td colspan="7" class="px-4 py-6 text-center text-gray-400">Keine erledigten Jobs.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($tab === 'services'): ?>
<!-- Services -->
<div class="flex items-center justify-between mb-4">
  <h3 class="font-semibold"><?= count($services) ?> Services</h3>
  <a href="/admin/services.php" onclick="localStorage.setItem('prefillCustomer','<?= $cid ?>')" class="px-3 py-1.5 bg-brand text-white rounded-lg text-sm font-medium">+ Neuer Service</a>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
  <?php foreach ($services as $sv): ?>
  <div class="bg-white rounded-xl border p-5">
    <div class="flex items-start justify-between">
      <h4 class="font-semibold mb-2"><?= e($sv['title']) ?></h4>
      <a href="/admin/services.php" class="text-xs text-brand hover:underline">Edit</a>
    </div>
    <div class="space-y-1 text-sm text-gray-600">
      <div><?= e($sv['street']) ?> <?= e($sv['number']) ?>, <?= e($sv['postal_code']) ?> <?= e($sv['city']) ?></div>
      <?php if ($sv['total_price']): ?><div class="font-medium text-brand"><?= money($sv['total_price']) ?>/h</div><?php endif; ?>
      <?php if ($sv['box_code']): ?><div class="text-xs text-gray-500">Box: <?= e($sv['box_code']) ?><?= $sv['client_code'] ? ' | Client: '.e($sv['client_code']) : '' ?><?= $sv['deposit_code'] ? ' | Deposit: '.e($sv['deposit_code']) : '' ?></div><?php endif; ?>
      <?php if ($sv['wifi_name']): ?><div class="text-xs text-gray-500">WiFi: <?= e($sv['wifi_name']) ?> / <?= e($sv['wifi_password']) ?></div><?php endif; ?>
      <?php if ($sv['qm']): ?><div class="text-xs text-gray-500"><?= $sv['qm'] ?>m² | <?= $sv['room'] ?? '?' ?> Zimmer</div><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if(empty($services)): ?><p class="text-sm text-gray-400">Keine Services zugewiesen. <a href="/admin/services.php" class="text-brand hover:underline">Service erstellen</a></p><?php endif; ?>
</div>

<?php elseif ($tab === 'notes'): ?>
<!-- Notizen / Interne Nachrichten -->
<div class="max-w-2xl">
  <form method="POST" class="bg-white rounded-xl border p-5 mb-4">
    <input type="hidden" name="action" value="add_note"/>
    <input type="hidden" name="author" value="Admin"/>
    <textarea name="message" required rows="2" placeholder="Interne Notiz schreiben..." class="w-full px-3 py-2 border rounded-xl text-sm mb-2"></textarea>
    <button type="submit" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-medium">Notiz speichern</button>
  </form>
  <?php
  // Try to load notes (table may not exist yet)
  try { $notes = all("SELECT * FROM customer_notes WHERE customer_id_fk=? ORDER BY created_at DESC", [$cid]); } catch (Exception $e) { $notes = []; }
  foreach ($notes as $n): ?>
  <div class="bg-white rounded-xl border p-4 mb-2">
    <div class="flex justify-between text-xs text-gray-400 mb-1">
      <span class="font-medium text-gray-600"><?= e($n['author']) ?></span>
      <span><?= $n['created_at'] ?></span>
    </div>
    <p class="text-sm"><?= nl2br(e($n['message'])) ?></p>
  </div>
  <?php endforeach; ?>
  <?php if(empty($notes)): ?><p class="text-sm text-gray-400">Noch keine Notizen.</p><?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
