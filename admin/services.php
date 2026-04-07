<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Services'; $page = 'services';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'add_service') {
        q("INSERT INTO services (title,customer_id_fk,street,number,postal_code,city,country,price,tax_percentage,total_price,unit,coin,status,box_code,client_code,deposit_code,access_phone,wifi_name,wifi_password,qm,room,is_cleaning) VALUES (?,?,?,?,?,?,?,?,19,?,?,'€',1,?,?,?,?,?,?,?,?,?)",
          [$_POST['title'],$_POST['customer_id_fk']??0,$_POST['street']??'',$_POST['number']??'',$_POST['postal_code']??'',$_POST['city']??'',$_POST['country']??'Deutschland',
           $_POST['price']??0,$_POST['total_price']??0,$_POST['unit']??'hour',$_POST['box_code']??'',$_POST['client_code']??'',$_POST['deposit_code']??'',$_POST['access_phone']??'',
           $_POST['wifi_name']??'',$_POST['wifi_password']??'',$_POST['qm']??'',$_POST['room']??'',$_POST['is_cleaning']??0]);
        header("Location: /admin/services.php?added=1"); exit;
    }
    if ($act === 'edit_service') {
        q("UPDATE services SET title=?,customer_id_fk=?,street=?,number=?,postal_code=?,city=?,country=?,price=?,total_price=?,unit=?,box_code=?,client_code=?,deposit_code=?,access_phone=?,wifi_name=?,wifi_password=?,qm=?,room=?,is_cleaning=?,status=? WHERE s_id=?",
          [$_POST['title'],$_POST['customer_id_fk'],$_POST['street']??'',$_POST['number']??'',$_POST['postal_code']??'',$_POST['city']??'',$_POST['country']??'',
           $_POST['price']??0,$_POST['total_price']??0,$_POST['unit']??'hour',$_POST['box_code']??'',$_POST['client_code']??'',$_POST['deposit_code']??'',$_POST['access_phone']??'',
           $_POST['wifi_name']??'',$_POST['wifi_password']??'',$_POST['qm']??'',$_POST['room']??'',$_POST['is_cleaning']??0,$_POST['status'],$_POST['s_id']]);
        header("Location: /admin/services.php?saved=1"); exit;
    }
    if ($act === 'delete_service') { q("UPDATE services SET status=0 WHERE s_id=?", [$_POST['s_id']]); header("Location: /admin/services.php"); exit; }
    if ($act === 'reactivate_service') { q("UPDATE services SET status=1 WHERE s_id=?", [$_POST['s_id']]); header("Location: /admin/services.php?tab=active&saved=1"); exit; }
}

$tab = $_GET['tab'] ?? 'active';
$statusFilter = $tab === 'archive' ? 0 : 1;
$services = all("SELECT s.*, c.name as cname, COUNT(j.j_id) as jobs FROM services s LEFT JOIN customer c ON s.customer_id_fk=c.customer_id LEFT JOIN jobs j ON j.s_id_fk=s.s_id WHERE s.status=? GROUP BY s.s_id ORDER BY s.title", [$statusFilter]);
$activeCount = val("SELECT COUNT(*) FROM services WHERE status=1");
$archiveCount = val("SELECT COUNT(*) FROM services WHERE status=0");
$customers = all("SELECT customer_id, name, customer_type FROM customer WHERE status=1 ORDER BY name");
include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])||!empty($_GET['added'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Gespeichert.</div><?php endif; ?>

<!-- Tabs -->
<div class="flex gap-1 mb-4 bg-white rounded-xl border p-1 w-fit">
  <a href="/admin/services.php?tab=active" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $tab==='active' ? 'bg-brand text-white' : 'text-gray-500 hover:bg-gray-100' ?>">
    Aktive Services <span class="ml-1 text-xs opacity-75">(<?= $activeCount ?>)</span>
  </a>
  <a href="/admin/services.php?tab=archive" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $tab==='archive' ? 'bg-gray-700 text-white' : 'text-gray-500 hover:bg-gray-100' ?>">
    Archiv <span class="ml-1 text-xs opacity-75">(<?= $archiveCount ?>)</span>
  </a>
</div>

<div x-data="{ editOpen:false, s:{} }" class="bg-white rounded-xl border">
  <div class="p-5 border-b flex items-center justify-between">
    <h3 class="font-semibold"><?= $tab==='archive' ? 'Archivierte' : 'Aktive' ?> Services (<?= count($services) ?>)</h3>
    <div class="flex gap-3">
      <input type="text" placeholder="Suchen..." class="px-3 py-2 border rounded-lg text-sm w-64" oninput="filterRows(this.value)"/>
      <?php if ($tab === 'active'): ?>
      <button @click="s={status:'1',country:'Deutschland',coin:'€',unit:'hour'}; editOpen=true" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-medium">+ Neuer Service</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm" id="tbl"><thead class="bg-gray-50"><tr>
      <th class="px-4 py-3 text-left font-medium text-gray-600">#</th><th class="px-4 py-3 text-left font-medium text-gray-600">Titel</th>
      <th class="px-4 py-3 text-left font-medium text-gray-600">Kunde</th><th class="px-4 py-3 text-left font-medium text-gray-600">Adresse</th>
      <th class="px-4 py-3 text-left font-medium text-gray-600">Preis/h</th><th class="px-4 py-3 text-left font-medium text-gray-600">Gesamt</th>
      <th class="px-4 py-3 text-left font-medium text-gray-600">Jobs</th><th class="px-4 py-3 text-left font-medium text-gray-600">Aktionen</th>
    </tr></thead><tbody class="divide-y">
    <?php foreach ($services as $sv): ?>
    <tr class="hover:bg-gray-50">
      <td class="px-4 py-3 text-gray-400"><?= $sv['s_id'] ?></td>
      <td class="px-4 py-3 font-medium"><?= e($sv['title']) ?></td>
      <td class="px-4 py-3"><?= e($sv['cname']) ?></td>
      <td class="px-4 py-3 text-gray-500 text-xs"><?= e($sv['street'].' '.$sv['number'].', '.$sv['postal_code'].' '.$sv['city']) ?></td>
      <td class="px-4 py-3"><?= money($sv['price']) ?></td>
      <td class="px-4 py-3 font-medium"><?= money($sv['total_price']) ?></td>
      <td class="px-4 py-3"><?= $sv['jobs'] ?></td>
      <td class="px-4 py-3"><div class="flex gap-1">
        <?php if ($tab === 'archive'): ?>
          <form method="POST" class="inline"><input type="hidden" name="action" value="reactivate_service"/><input type="hidden" name="s_id" value="<?= $sv['s_id'] ?>"/><button class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded-lg font-medium">Aktivieren</button></form>
        <?php else: ?>
          <button @click='s=<?= json_encode(["s_id"=>$sv["s_id"],"title"=>$sv["title"],"customer_id_fk"=>$sv["customer_id_fk"],"street"=>$sv["street"],"number"=>$sv["number"],"postal_code"=>$sv["postal_code"],"city"=>$sv["city"],"country"=>$sv["country"],"price"=>$sv["price"],"total_price"=>$sv["total_price"],"unit"=>$sv["unit"],"box_code"=>$sv["box_code"]??"","client_code"=>$sv["client_code"]??"","deposit_code"=>$sv["deposit_code"]??"","access_phone"=>$sv["access_phone"]??"","wifi_name"=>$sv["wifi_name"]??"","wifi_password"=>$sv["wifi_password"]??"","qm"=>$sv["qm"]??"","room"=>$sv["room"]??"","is_cleaning"=>$sv["is_cleaning"]??0,"status"=>$sv["status"]],JSON_HEX_APOS) ?>; editOpen=true' class="px-2 py-1 text-xs bg-brand/10 text-brand rounded-lg">Edit</button>
          <form method="POST" class="inline" onsubmit="return confirm('Service archivieren?')"><input type="hidden" name="action" value="delete_service"/><input type="hidden" name="s_id" value="<?= $sv['s_id'] ?>"/><button class="px-2 py-1 text-xs bg-red-50 text-red-600 rounded-lg">Archiv</button></form>
        <?php endif; ?>
      </div></td>
    </tr>
    <?php endforeach; ?></tbody></table>
  </div>
  <template x-if="editOpen">
    <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center overflow-y-auto py-4">
      <div class="bg-white rounded-2xl p-6 w-full max-w-3xl shadow-2xl m-4">
        <h3 class="text-lg font-semibold mb-4" x-text="s.s_id ? 'Service bearbeiten' : 'Neuer Service'"></h3>
        <form method="POST" class="grid grid-cols-3 gap-4">
          <input type="hidden" name="action" :value="s.s_id ? 'edit_service' : 'add_service'"/>
          <input type="hidden" name="s_id" :value="s.s_id"/>
          <div class="col-span-2"><label class="block text-xs font-medium text-gray-500 mb-1">Titel *</label><input name="title" :value="s.title" required class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Kunde</label><select name="customer_id_fk" class="w-full px-3 py-2 border rounded-xl text-sm"><option value="0">—</option><?php foreach($customers as $c): ?><option value="<?=$c['customer_id']?>" :selected="s.customer_id_fk==<?=$c['customer_id']?>"><?=e($c['name'])?></option><?php endforeach; ?></select></div>
          <div class="col-span-2"><label class="block text-xs font-medium text-gray-500 mb-1">Strasse</label><input name="street" :value="s.street" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Nr.</label><input name="number" :value="s.number" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">PLZ</label><input name="postal_code" :value="s.postal_code" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Stadt</label><input name="city" :value="s.city" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Land</label><input name="country" :value="s.country" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Netto/h</label><input type="number" name="price" :value="s.price" step="0.01" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Brutto/h</label><input type="number" name="total_price" :value="s.total_price" step="0.01" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Einheit</label><input name="unit" :value="s.unit" class="w-full px-3 py-2 border rounded-xl text-sm" placeholder="hour"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Box Code</label><input name="box_code" :value="s.box_code" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Client Code</label><input name="client_code" :value="s.client_code" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Deposit Code</label><input name="deposit_code" :value="s.deposit_code" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Telefon (Zugang)</label><input name="access_phone" :value="s.access_phone" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">WiFi Name</label><input name="wifi_name" :value="s.wifi_name" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">WiFi Passwort</label><input name="wifi_password" :value="s.wifi_password" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">m²</label><input name="qm" :value="s.qm" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Zimmer</label><input name="room" :value="s.room" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="flex items-center gap-2 mt-5"><input type="checkbox" name="is_cleaning" value="1" :checked="s.is_cleaning==1"/> Spezialreinigung</label></div>
          <div x-show="s.s_id"><label class="block text-xs font-medium text-gray-500 mb-1">Status</label><select name="status" class="w-full px-3 py-2 border rounded-xl text-sm"><option value="1" :selected="s.status=='1'">Aktiv</option><option value="0" :selected="s.status=='0'">Inaktiv</option></select></div>
          <div class="col-span-3 flex gap-3 mt-2">
            <button type="button" @click="editOpen=false" class="flex-1 px-4 py-2.5 border rounded-xl">Abbrechen</button>
            <button type="submit" class="flex-1 px-4 py-2.5 bg-brand text-white rounded-xl font-medium">Speichern</button>
          </div>
        </form>
      </div>
    </div>
  </template>
</div>
<?php $script="function filterRows(q){q=q.toLowerCase();document.querySelectorAll('#tbl tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'})}"; include __DIR__.'/../includes/footer.php'; ?>
