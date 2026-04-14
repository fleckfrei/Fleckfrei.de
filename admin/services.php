<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Services'; $page = 'services';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/services.php'); exit; }
    $act = $_POST['action'] ?? '';
    if ($act === 'add_service') {
        q("INSERT INTO services (title,customer_id_fk,street,number,postal_code,city,country,price,tax_percentage,total_price,unit,coin,status,box_code,client_code,deposit_code,access_phone,wifi_name,wifi_password,qm,room,is_cleaning) VALUES (?,?,?,?,?,?,?,?,19,?,?,'€',1,?,?,?,?,?,?,?,?,?)",
          [$_POST['title'],$_POST['customer_id_fk']??0,$_POST['street']??'',$_POST['number']??'',$_POST['postal_code']??'',$_POST['city']??'',$_POST['country']??'Deutschland',
           $_POST['price']??0,$_POST['total_price']??0,$_POST['unit']??'hour',$_POST['box_code']??'',$_POST['client_code']??'',$_POST['deposit_code']??'',$_POST['access_phone']??'',
           $_POST['wifi_name']??'',$_POST['wifi_password']??'',$_POST['qm']??'',$_POST['room']??'',$_POST['is_cleaning']??0]);
        header("Location: /admin/services.php?added=1"); exit;
    }
    if ($act === 'edit_service') {
        $waKey = trim(preg_replace('/[^\w\-]/u', '', $_POST['wa_keyword'] ?? ''));
        q("UPDATE services SET title=?,customer_id_fk=?,street=?,number=?,postal_code=?,city=?,country=?,price=?,total_price=?,unit=?,box_code=?,client_code=?,deposit_code=?,access_phone=?,wifi_name=?,wifi_password=?,qm=?,room=?,is_cleaning=?,status=?,wa_keyword=? WHERE s_id=?",
          [$_POST['title'],$_POST['customer_id_fk'],$_POST['street']??'',$_POST['number']??'',$_POST['postal_code']??'',$_POST['city']??'',$_POST['country']??'',
           $_POST['price']??0,$_POST['total_price']??0,$_POST['unit']??'hour',$_POST['box_code']??'',$_POST['client_code']??'',$_POST['deposit_code']??'',$_POST['access_phone']??'',
           $_POST['wifi_name']??'',$_POST['wifi_password']??'',$_POST['qm']??'',$_POST['room']??'',$_POST['is_cleaning']??0,$_POST['status'],$waKey ?: null,$_POST['s_id']]);
        header("Location: /admin/services.php?saved=1"); exit;
    }
    if ($act === 'delete_service') { q("UPDATE services SET status=0 WHERE s_id=?", [$_POST['s_id']]); header("Location: /admin/services.php"); exit; }
    if ($act === 'reactivate_service') { q("UPDATE services SET status=1 WHERE s_id=?", [$_POST['s_id']]); header("Location: /admin/services.php?tab=active&saved=1"); exit; }
}

$tab = $_GET['tab'] ?? 'active';
$statusFilter = $tab === 'archive' ? 0 : 1;
$services = all("SELECT s.*,
    COALESCE(c.name, (SELECT c2.name FROM jobs j2 LEFT JOIN customer c2 ON j2.customer_id_fk=c2.customer_id WHERE j2.s_id_fk=s.s_id AND j2.status=1 AND c2.name IS NOT NULL ORDER BY j2.j_date DESC LIMIT 1)) as cname,
    COUNT(j.j_id) as jobs
    FROM services s LEFT JOIN customer c ON s.customer_id_fk=c.customer_id LEFT JOIN jobs j ON j.s_id_fk=s.s_id AND j.status=1 WHERE s.status=? GROUP BY s.s_id ORDER BY s.title", [$statusFilter]);
$activeCount = val("SELECT COUNT(*) FROM services WHERE status=1");
$archiveCount = val("SELECT COUNT(*) FROM services WHERE status=0");
$customers = all("SELECT customer_id, name, customer_type FROM customer WHERE status=1 ORDER BY name");
include __DIR__ . '/../includes/layout.php';
?>


<?php
// Website Service-Cards (fleckfrei.de) — Quick-Edit Block
$_ws = one("SELECT website_title_home_care, website_title_str, website_title_office, website_price_private_override, website_price_str_override, website_price_office_override FROM settings LIMIT 1") ?: [];
$_wsCalc = json_decode(@file_get_contents("https://app.fleckfrei.de/api/prices-public.php"), true) ?: [];
$_wsMin = $_wsCalc["min_prices"] ?? [];
?>
<div class="bg-gradient-to-br from-amber-50 to-orange-50 border-2 border-amber-400 rounded-2xl p-5 mb-6">
  <div class="flex items-center justify-between mb-4">
    <div>
      <h2 class="text-lg font-bold text-amber-900">🌐 Website Service-Cards (fleckfrei.de)</h2>
      <p class="text-xs text-amber-800">Die 3 Cards die Kunden zuerst sehen. Bearbeite direkt hier oder auf <a href="/admin/pricing.php#website" class="underline font-semibold">Pricing-Seite</a>.</p>
    </div>
    <a href="https://fleckfrei.de" target="_blank" class="text-xs px-3 py-1.5 bg-amber-600 text-white rounded-lg hover:bg-amber-700">Live anschauen →</a>
  </div>
  <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
    <?php foreach ([
      ["private", "home_care", "🏠", "Home Care", "website_price_private_override", "website_title_home_care"],
      ["str", "str", "🏨", "Short-Term Rental ★", "website_price_str_override", "website_title_str"],
      ["office", "office", "🏢", "Business & Office", "website_price_office_override", "website_title_office"],
    ] as [$k, $tk, $icon, $default_title, $priceKey, $titleKey]): ?>
    <div class="bg-white rounded-xl border-2 border-amber-300 p-4">
      <div class="text-2xl mb-2"><?= $icon ?></div>
      <div class="font-bold text-sm"><?= e($_ws[$titleKey] ?? $default_title) ?></div>
      <div class="text-xs text-gray-500 mb-2">Auto-Preis: <strong class="text-gray-700"><?= isset($_wsMin[$k]) ? number_format($_wsMin[$k], 2, ",", ".") . " €" : "-" ?></strong></div>
      <div class="text-xs text-gray-500">Override: <strong class="<?= $_ws[$priceKey] !== null ? "text-amber-700" : "text-gray-400" ?>"><?= $_ws[$priceKey] !== null ? number_format($_ws[$priceKey], 2, ",", ".") . " €" : "auto" ?></strong></div>
      <a href="/admin/pricing.php#website" class="mt-3 block text-center text-xs px-3 py-1.5 bg-amber-600 text-white rounded hover:bg-amber-700">✏ Bearbeiten</a>
    </div>
    <?php endforeach; ?>
  </div>
</div>
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

<div x-data="serviceForm()" class="bg-white rounded-xl border">
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
      <td class="px-4 py-3"><?= $sv['cname'] ? e($sv['cname']) : '<span class="text-gray-300">—</span>' ?></td>
      <td class="px-4 py-3 text-gray-500 text-xs"><?= e($sv['street'].' '.$sv['number'].', '.$sv['postal_code'].' '.$sv['city']) ?></td>
      <td class="px-4 py-3"><?= money($sv['price']) ?></td>
      <td class="px-4 py-3 font-medium"><?= money($sv['total_price']) ?></td>
      <td class="px-4 py-3"><?= $sv['jobs'] ?></td>
      <td class="px-4 py-3"><div class="flex gap-1">
        <?php if ($tab === 'archive'): ?>
          <form method="POST" class="inline">
  <?= csrfField() ?><input type="hidden" name="action" value="reactivate_service"/><input type="hidden" name="s_id" value="<?= $sv['s_id'] ?>"/><button class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded-lg font-medium">Aktivieren</button></form>
        <?php else: ?>
          <button @click='s=<?= json_encode(["s_id"=>$sv["s_id"],"title"=>$sv["title"],"customer_id_fk"=>$sv["customer_id_fk"],"street"=>$sv["street"],"number"=>$sv["number"],"postal_code"=>$sv["postal_code"],"city"=>$sv["city"],"country"=>$sv["country"],"price"=>$sv["price"],"total_price"=>$sv["total_price"],"unit"=>$sv["unit"],"box_code"=>$sv["box_code"]??"","client_code"=>$sv["client_code"]??"","deposit_code"=>$sv["deposit_code"]??"","access_phone"=>$sv["access_phone"]??"","wifi_name"=>$sv["wifi_name"]??"","wifi_password"=>$sv["wifi_password"]??"","qm"=>$sv["qm"]??"","room"=>$sv["room"]??"","is_cleaning"=>$sv["is_cleaning"]??0,"status"=>$sv["status"]],JSON_HEX_APOS) ?>; editOpen=true' class="px-2 py-1 text-xs bg-brand/10 text-brand rounded-lg">Edit</button>
          <form method="POST" class="inline" onsubmit="return confirm('Service archivieren?')">
  <?= csrfField() ?><input type="hidden" name="action" value="delete_service"/><input type="hidden" name="s_id" value="<?= $sv['s_id'] ?>"/><button class="px-2 py-1 text-xs bg-red-50 text-red-600 rounded-lg">Archiv</button></form>
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
  <?= csrfField() ?>
          <input type="hidden" name="action" :value="s.s_id ? 'edit_service' : 'add_service'"/>
          <input type="hidden" name="s_id" :value="s.s_id"/>
          <div class="col-span-2"><label class="block text-xs font-medium text-gray-500 mb-1">Titel *</label><input name="title" :value="s.title" required class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Kunde</label><select name="customer_id_fk" @change="autoFillCustomer($event.target.value)" class="w-full px-3 py-2 border rounded-xl text-sm"><option value="0">—</option><?php foreach($customers as $c): ?><option value="<?=$c['customer_id']?>" :selected="s.customer_id_fk==<?=$c['customer_id']?>"><?=e($c['name'])?></option><?php endforeach; ?></select></div>
          <div class="col-span-2"><label class="block text-xs font-medium text-gray-500 mb-1">Strasse</label><input name="street" x-model="s.street" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Nr.</label><input name="number" x-model="s.number" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">PLZ</label><input name="postal_code" x-model="s.postal_code" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Stadt</label><input name="city" x-model="s.city" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
          <div><label class="block text-xs font-medium text-gray-500 mb-1">Land</label><input name="country" x-model="s.country" class="w-full px-3 py-2 border rounded-xl text-sm"/></div>
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
          <div class="col-span-2">
            <label class="block text-xs font-medium text-gray-700 mb-1">💬 WhatsApp-Keyword <span class="text-[10px] text-gray-500 font-normal">(für n8n Flow-Trigger, z.B. "Svea", "Auroerei", "Decebal")</span></label>
            <input name="wa_keyword" :value="s.wa_keyword || ''" placeholder="z.B. Svea" pattern="[A-Za-z0-9_-]+" class="w-full px-3 py-2 border-2 border-brand/30 bg-brand-light/30 rounded-xl text-sm font-mono"/>
            <div class="text-[10px] text-gray-600 mt-1">Kunde schreibt "Keyword: Svea" auf WhatsApp → Bot erkennt Property automatisch. Nur Buchstaben/Zahlen, keine Leerzeichen.</div>
          </div>
          <div class="col-span-3 flex gap-3 mt-2">
            <button type="button" @click="editOpen=false" class="flex-1 px-4 py-2.5 border rounded-xl">Abbrechen</button>
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

function serviceForm() {
    return {
        editOpen: false,
        s: {},
        autoFillCustomer(custId) {
            if (!custId || custId === '0') return;
            fetch('/api/index.php?action=customer/details&id=' + custId + '&key=$apiKey')
                .then(r => r.json())
                .then(d => {
                    if (!d.success || !d.data.address) return;
                    const a = d.data.address;
                    if (!this.s.street) this.s.street = a.street || '';
                    if (!this.s.number) this.s.number = a.number || '';
                    if (!this.s.postal_code) this.s.postal_code = a.postal_code || '';
                    if (!this.s.city) this.s.city = a.city || '';
                    if (!this.s.country || this.s.country === 'Deutschland') this.s.country = a.country || 'Deutschland';
                })
                .catch(() => {});
        }
    };
}
JS;
include __DIR__.'/../includes/footer.php'; ?>
