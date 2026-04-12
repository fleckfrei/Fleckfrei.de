<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Kunden'; $page = 'customers';

// Impersonate: Admin logs in as customer (POST + CSRF only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'impersonate' && !empty($_POST['customer_id'])) {
    if (!verifyCsrf()) { header('Location: /admin/customers.php?error=csrf'); exit; }
    $cust = one("SELECT * FROM customer WHERE customer_id=?", [(int)$_POST['customer_id']]);
    if ($cust) {
        $_SESSION['admin_uid'] = $_SESSION['uid'];
        $_SESSION['admin_uname'] = $_SESSION['uname'];
        $_SESSION['admin_uemail'] = $_SESSION['uemail'];
        $_SESSION['uid'] = $cust['customer_id'];
        $_SESSION['uname'] = $cust['name'];
        $_SESSION['uemail'] = $cust['email'];
        $_SESSION['utype'] = 'customer';
        audit('impersonate', 'customer', $cust['customer_id'], 'Admin logged in as customer');
        header("Location: /customer/"); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
    $act = $_POST['action'] ?? '';
    if ($act === 'add_customer') {
        $tempPass = bin2hex(random_bytes(4));
        $hashedPass = password_hash($tempPass, PASSWORD_BCRYPT, ['cost' => 12]);
        q("INSERT INTO customer (name,surname,email,phone,customer_type,password,status,email_permissions) VALUES (?,?,?,?,?,?,1,'all')",
          [$_POST['name'],$_POST['surname']??'',$_POST['email'],$_POST['phone']??'',$_POST['customer_type']??'Private',$hashedPass]);
        global $db; $cid = $db->lastInsertId();
        if ($_POST['email']) q("INSERT INTO users (email,type) VALUES (?,'customer')", [$_POST['email']]);
        header("Location: /admin/customers.php?added=$cid"); exit;
    }
    if ($act === 'edit_customer') {
        q("UPDATE customer SET name=?,surname=?,email=?,phone=?,customer_type=?,status=?,notes=? WHERE customer_id=?",
          [$_POST['name'],$_POST['surname']??'',$_POST['email'],$_POST['phone']??'',$_POST['customer_type'],$_POST['status'],$_POST['notes']??'',$_POST['customer_id']]);
        header("Location: /admin/customers.php?saved=".$_POST['customer_id']); exit;
    }
    if ($act === 'delete_customer') {
        q("UPDATE customer SET status=0 WHERE customer_id=?", [$_POST['customer_id']]);
        header("Location: /admin/customers.php?deleted=1"); exit;
    }
    if ($act === 'reactivate_customer') {
        q("UPDATE customer SET status=1 WHERE customer_id=?", [$_POST['customer_id']]);
        header("Location: /admin/customers.php?tab=active&saved=".$_POST['customer_id']); exit;
    }
}

$tab = in_array($_GET['tab'] ?? '', ['active', 'archive'], true) ? $_GET['tab'] : 'active';
$statusFilter = $tab === 'archive' ? 0 : 1;
$customers = all("SELECT c.*, COUNT(j.j_id) as jobs, (SELECT COUNT(*) FROM invoices i WHERE i.customer_id_fk=c.customer_id AND i.invoice_paid='no') as unpaid FROM customer c LEFT JOIN jobs j ON j.customer_id_fk=c.customer_id WHERE c.status=? GROUP BY c.customer_id ORDER BY c.name", [$statusFilter]);
$activeCount = val("SELECT COUNT(*) FROM customer WHERE status=1");
$archiveCount = val("SELECT COUNT(*) FROM customer WHERE status=0");

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Kunde gespeichert.</div><?php endif; ?>
<?php if (!empty($_GET['added'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Neuer Kunde erstellt.</div><?php endif; ?>

<!-- Tabs: Aktive / Archiv -->
<div class="flex gap-1 mb-4 bg-white rounded-xl border p-1 w-fit">
  <a href="/admin/customers.php?tab=active" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $tab==='active' ? 'bg-brand text-white' : 'text-gray-500 hover:bg-gray-100' ?>">
    Aktive Kunden <span class="ml-1 text-xs opacity-75">(<?= $activeCount ?>)</span>
  </a>
  <a href="/admin/customers.php?tab=archive" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $tab==='archive' ? 'bg-gray-700 text-white' : 'text-gray-500 hover:bg-gray-100' ?>">
    Archiv / Datenbank <span class="ml-1 text-xs opacity-75">(<?= $archiveCount ?>)</span>
  </a>
</div>

<div x-data="{ editOpen:false, c:{} }" class="bg-white rounded-xl border">
  <div class="p-5 border-b flex items-center justify-between">
    <h3 class="font-semibold"><?= $tab==='archive' ? 'Archivierte Kunden' : 'Aktive Kunden' ?> (<?= count($customers) ?>)</h3>
    <div class="flex gap-3">
      <input type="text" placeholder="Suchen..." class="px-3 py-2 border rounded-lg text-sm w-64" oninput="filterRows(this.value)"/>
      <?php if ($tab === 'active'): ?>
      <button @click="c={customer_type:'Private',status:'1'}; editOpen=true" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-medium">+ Neuer Kunde</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm" id="tbl">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left font-medium text-gray-600">#</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Name</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Typ</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">E-Mail</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Telefon</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Jobs</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
        <th class="px-4 py-3 text-left font-medium text-gray-600">Aktionen</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($customers as $row): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 text-gray-400"><?= $row['customer_id'] ?></td>
        <td class="px-4 py-3 font-medium"><?= e($row['name']) ?> <span class="text-gray-400"><?= e($row['surname']) ?></span></td>
        <td class="px-4 py-2">
          <select onchange="updateCustField(<?= $row['customer_id'] ?>,'customer_type',this.value,this)" class="px-2 py-1 text-xs font-medium border rounded-lg cursor-pointer bg-blue-50 text-blue-700 border-blue-200">
            <?php foreach (['Private Person','Airbnb','Company','Host'] as $t): ?>
            <option value="<?= $t ?>" <?= $row['customer_type']===$t?'selected':'' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td class="px-4 py-3"><a href="mailto:<?= e($row['email']) ?>" class="text-brand hover:underline"><?= e($row['email']) ?></a></td>
        <td class="px-4 py-3">
          <?php if ($row['phone']): $ph = preg_replace('/[^+0-9]/','',$row['phone']); ?>
          <div class="flex items-center gap-1.5">
            <a href="tel:<?= e($ph) ?>" class="text-brand hover:underline"><?= e($row['phone']) ?></a>
            <a href="https://wa.me/<?= ltrim($ph,'+') ?>" target="_blank" title="WhatsApp" class="text-green-600 hover:text-green-700"><svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg></a>
          </div>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3"><?= $row['jobs'] ?></td>
        <td class="px-4 py-2">
          <select onchange="updateCustStatus(<?= $row['customer_id'] ?>, this.value, this)" class="px-2 py-1 text-xs font-medium border rounded-lg cursor-pointer <?= $row['status'] ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-50 text-gray-500 border-gray-200' ?>">
            <option value="1" <?= $row['status'] ? 'selected' : '' ?>>Aktiv</option>
            <option value="0" <?= !$row['status'] ? 'selected' : '' ?>>Inaktiv</option>
          </select>
        </td>
        <td class="px-4 py-3">
          <div class="flex gap-1">
            <a href="/admin/view-customer.php?id=<?= $row['customer_id'] ?>" class="px-2 py-1 text-xs bg-brand text-white rounded-lg">Öffnen</a>
            <?php if ($tab === 'archive'): ?>
              <form method="POST" class="inline"><input type="hidden" name="action" value="reactivate_customer"/><input type="hidden" name="customer_id" value="<?= $row['customer_id'] ?>"/><?= csrfField() ?><button class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded-lg font-medium">Aktivieren</button></form>
            <?php else: ?>
              <form method="POST" class="inline"><input type="hidden" name="action" value="impersonate"/><input type="hidden" name="customer_id" value="<?= $row['customer_id'] ?>"/><input type="hidden" name="_csrf" value="<?= csrfToken() ?>"/><button class="px-2 py-1 text-xs bg-blue-50 text-blue-700 rounded-lg">Login</button></form>
              <form method="POST" class="inline" onsubmit="return confirm('Kunde deaktivieren?')"><input type="hidden" name="action" value="delete_customer"/><input type="hidden" name="customer_id" value="<?= $row['customer_id'] ?>"/><?= csrfField() ?><button class="px-2 py-1 text-xs bg-red-50 text-red-600 rounded-lg">Archiv</button></form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Edit/Add Modal -->
  <template x-if="editOpen">
    <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center overflow-y-auto py-8">
      <div class="bg-white rounded-2xl p-6 w-full max-w-lg shadow-2xl m-4">
        <h3 class="text-lg font-semibold mb-4" x-text="c.customer_id ? 'Kunde bearbeiten' : 'Neuer Kunde'"></h3>
        <form method="POST" class="space-y-4">
          <?= csrfField() ?>
          <input type="hidden" name="action" :value="c.customer_id ? 'edit_customer' : 'add_customer'"/>
          <input type="hidden" name="customer_id" :value="c.customer_id"/>
          <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-600 mb-1">Name *</label><input name="name" :value="c.name" required class="w-full px-3 py-2.5 border rounded-xl"/></div>
            <div><label class="block text-sm font-medium text-gray-600 mb-1">Nachname</label><input name="surname" :value="c.surname" class="w-full px-3 py-2.5 border rounded-xl"/></div>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-600 mb-1">E-Mail *</label><input type="email" name="email" :value="c.email" required class="w-full px-3 py-2.5 border rounded-xl"/></div>
            <div><label class="block text-sm font-medium text-gray-600 mb-1">Telefon</label><input name="phone" :value="c.phone" class="w-full px-3 py-2.5 border rounded-xl"/></div>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-600 mb-1">Typ</label>
              <select name="customer_type" class="w-full px-3 py-2.5 border rounded-xl">
                <template x-for="t in ['Private','Company','Airbnb','Host']"><option :value="t" :selected="c.customer_type===t" x-text="t"></option></template>
              </select></div>
            <div x-show="c.customer_id"><label class="block text-sm font-medium text-gray-600 mb-1">Status</label>
              <select name="status" class="w-full px-3 py-2.5 border rounded-xl"><option value="1" :selected="c.status=='1'">Aktiv</option><option value="0" :selected="c.status=='0'">Inaktiv</option></select></div>
          </div>
          <div x-show="c.customer_id"><label class="block text-sm font-medium text-gray-600 mb-1">Notizen</label><textarea name="notes" x-text="c.notes" class="w-full px-3 py-2.5 border rounded-xl" rows="2"></textarea></div>
          <div class="flex gap-3 mt-2">
            <button type="button" @click="editOpen=false" class="flex-1 px-4 py-2.5 border rounded-xl text-gray-600">Abbrechen</button>
            <button type="submit" class="flex-1 px-4 py-2.5 bg-brand text-white rounded-xl font-medium">Speichern</button>
          </div>
        </form>
      </div>
    </div>
  </template>
</div>

<?php
$apiKey = API_KEY;
$script = <<<JS
function filterRows(q){q=q.toLowerCase();document.querySelectorAll('#tbl tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'})}
function updateCustField(id, field, val, el) {
    fetch('/api/index.php?action=customer/update', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-API-Key':'$apiKey'},
        body:JSON.stringify({customer_id:id, field:field, value:val})
    }).then(r=>r.json()).then(d=>{
        if(d.success){el.closest('tr').style.transition='background 0.3s';el.closest('tr').style.background='#dcfce7';setTimeout(()=>{el.closest('tr').style.background='';},800);}
        else alert(d.error||'Fehler');
    });
}
function updateCustStatus(id, val, el) {
    fetch('/api/index.php?action=customer/status', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-API-Key':'$apiKey'},
        body:JSON.stringify({customer_id:id, status:parseInt(val)})
    }).then(r=>r.json()).then(d=>{
        if(d.success){
            el.className='px-2 py-1 text-xs font-medium border rounded-lg cursor-pointer '+(val==='1'?'bg-green-50 text-green-700 border-green-200':'bg-gray-50 text-gray-500 border-gray-200');
            el.closest('tr').style.transition='background 0.3s';
            el.closest('tr').style.background='#dcfce7';
            setTimeout(()=>{el.closest('tr').style.background='';},800);
        } else alert(d.error||'Fehler');
    });
}
JS;
include __DIR__ . '/../includes/footer.php';
?>
