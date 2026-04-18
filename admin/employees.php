<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Partner'; $page = 'employees';

// Impersonate employee (POST + CSRF only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'impersonate' && !empty($_POST['emp_id'])) {
    if (!verifyCsrf()) { header('Location: /admin/employees.php?error=csrf'); exit; }
    $emp = one("SELECT * FROM employee WHERE emp_id=?", [(int)$_POST['emp_id']]);
    if ($emp) {
        $_SESSION['admin_uid'] = $_SESSION['uid'];
        $_SESSION['admin_uname'] = $_SESSION['uname'];
        $_SESSION['admin_uemail'] = $_SESSION['uemail'];
        $_SESSION['uid'] = $emp['emp_id'];
        $_SESSION['uname'] = $emp['name'];
        $_SESSION['uemail'] = $emp['email'];
        $_SESSION['utype'] = 'employee';
        audit('impersonate', 'employee', $emp['emp_id'], 'Admin logged in as employee');
        header("Location: /employee/"); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
    $act = $_POST['action'] ?? '';
    if ($act === 'add_employee') {
        $pwd = $_POST['password'] ?? '';
        $hashedPwd = $pwd ? password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]) : password_hash(bin2hex(random_bytes(4)), PASSWORD_BCRYPT, ['cost' => 12]);
        q("INSERT INTO employee (name,surname,email,phone,password,status,tariff,location,nationality,notes,email_permissions) VALUES (?,?,?,?,?,1,?,?,?,?,'all')",
          [$_POST['name'],$_POST['surname']??'',$_POST['email'],$_POST['phone']??'',$hashedPwd,$_POST['tariff']??0,$_POST['location']??'',$_POST['nationality']??'',$_POST['notes']??'']);
        global $db; q("INSERT INTO users (email,type) VALUES (?,'employee')", [$_POST['email']]);
        audit('create', 'employee', $db->lastInsertId(), 'Neuer Partner: '.$_POST['name']);
        header("Location: /admin/employees.php?added=1"); exit;
    }
    if ($act === 'edit_employee') {
        $ptype = in_array($_POST['partner_type'] ?? '', ['mitarbeiter','freelancer','kleinunternehmen'], true) ? $_POST['partner_type'] : null;
        $ctype = $ptype === 'mitarbeiter' && in_array($_POST['contract_type'] ?? '', ['minijob','midijob','teilzeit','vollzeit'], true) ? $_POST['contract_type'] : null;
        $cname = $ptype === 'kleinunternehmen' ? trim($_POST['company_name'] ?? '') : null;
        $csize = $ptype === 'kleinunternehmen' ? min(10, max(1, (int)($_POST['company_size'] ?? 1))) : null;
        $taxId = trim($_POST['tax_id'] ?? '') ?: null;
        $maxH  = (int)($_POST['max_hours_month'] ?? 0) ?: null;
        q("UPDATE employee SET name=?,surname=?,email=?,phone=?,tariff=?,location=?,nationality=?,status=?,notes=?,
             partner_type=?,contract_type=?,company_name=?,company_size=?,tax_id=?,max_hours_month=?
           WHERE emp_id=?",
          [$_POST['name'],$_POST['surname']??'',$_POST['email'],$_POST['phone']??'',$_POST['tariff']??0,$_POST['location']??'',$_POST['nationality']??'',$_POST['status'],$_POST['notes']??'',
           $ptype,$ctype,$cname,$csize,$taxId,$maxH,$_POST['emp_id']]);
        audit('update', 'employee', $_POST['emp_id'], 'Bearbeitet');
        header("Location: /admin/employees.php?saved=1"); exit;
    }
    if ($act === 'delete_employee') {
        q("UPDATE employee SET status=0 WHERE emp_id=?", [$_POST['emp_id']]);
        audit('archive', 'employee', $_POST['emp_id'], 'Deaktiviert');
        header("Location: /admin/employees.php"); exit;
    }
    if ($act === 'reactivate_employee') {
        q("UPDATE employee SET status=1 WHERE emp_id=?", [$_POST['emp_id']]);
        audit('reactivate', 'employee', $_POST['emp_id'], 'Reaktiviert');
        header("Location: /admin/employees.php?tab=active&saved=1"); exit;
    }
}

$tab = $_GET['tab'] ?? 'active';
$statusFilter = $tab === 'archive' ? 0 : 1;
$typeFilter = in_array($_GET['type'] ?? '', ['mitarbeiter','freelancer','kleinunternehmen'], true) ? $_GET['type'] : '';
$typeSql = $typeFilter ? "AND e.partner_type=?" : '';
$typeParams = $typeFilter ? [$typeFilter] : [];

// Stellt sicher dass display_name-Spalte existiert (gitignored Migration, idempotent)
try { q("ALTER TABLE employee ADD COLUMN display_name VARCHAR(100) NULL AFTER surname"); } catch (Exception $e) {}

// Bulk-Action: automatisch Kunden-Namen für alle die leer sind generieren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'autofill_display_names' && verifyCsrf()) {
    $empsNeedFill = all("SELECT emp_id, name, surname FROM employee WHERE (display_name IS NULL OR display_name='')");
    $filled = 0;
    foreach ($empsNeedFill as $e) {
        $first = explode(' ', trim($e['name'] ?? ''))[0];
        $last = trim($e['surname'] ?? '');
        $disp = $first . ($last ? ' ' . strtoupper(substr($last, 0, 1)) . '.' : '');
        if ($disp !== '') { q("UPDATE employee SET display_name=? WHERE emp_id=?", [$disp, $e['emp_id']]); $filled++; }
    }
    header("Location: /admin/employees.php?autofilled=$filled"); exit;
}

$employees = all("SELECT e.*, COUNT(CASE WHEN j.job_status='COMPLETED' THEN 1 END) as done, COUNT(CASE WHEN j.job_status='PENDING' AND j.j_date>=CURDATE() THEN 1 END) as pending
                 FROM employee e LEFT JOIN jobs j ON j.emp_id_fk=e.emp_id AND j.status=1
                 WHERE e.status=? $typeSql
                 GROUP BY e.emp_id ORDER BY e.name", array_merge([$statusFilter], $typeParams));

$activeCount = val("SELECT COUNT(*) FROM employee WHERE status=1");
$archiveCount = val("SELECT COUNT(*) FROM employee WHERE status=0");

// Type counts (for filter tabs)
$typeCounts = [
    'mitarbeiter' => (int) val("SELECT COUNT(*) FROM employee WHERE status=1 AND partner_type='mitarbeiter'"),
    'freelancer' => (int) val("SELECT COUNT(*) FROM employee WHERE status=1 AND partner_type='freelancer'"),
    'kleinunternehmen' => (int) val("SELECT COUNT(*) FROM employee WHERE status=1 AND partner_type='kleinunternehmen'"),
    'unset' => (int) val("SELECT COUNT(*) FROM employee WHERE status=1 AND partner_type IS NULL"),
];

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved']) || !empty($_GET['added'])): ?><div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">Gespeichert.</div><?php endif; ?>

<!-- Tabs -->
<div class="flex gap-1 mb-3 bg-white rounded-xl border p-1 w-fit">
  <a href="/admin/employees.php?tab=active" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $tab==='active' ? 'bg-brand text-white' : 'text-gray-500 hover:bg-gray-100' ?>">
    Aktive Partner <span class="ml-1 text-xs opacity-75">(<?= $activeCount ?>)</span>
  </a>
  <a href="/admin/employees.php?tab=archive" class="px-4 py-2 rounded-lg text-sm font-medium transition <?= $tab==='archive' ? 'bg-gray-700 text-white' : 'text-gray-500 hover:bg-gray-100' ?>">
    Archiv <span class="ml-1 text-xs opacity-75">(<?= $archiveCount ?>)</span>
  </a>
</div>

<?php if ($tab === 'active'): ?>
<!-- Type filter -->
<div class="flex gap-2 mb-4 flex-wrap">
  <a href="?tab=active" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition <?= !$typeFilter ? 'bg-brand text-white' : 'bg-white border border-gray-200 text-gray-600 hover:border-brand' ?>">
    Alle <span class="opacity-75">(<?= $activeCount ?>)</span>
  </a>
  <a href="?tab=active&type=mitarbeiter" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition <?= $typeFilter === 'mitarbeiter' ? 'bg-blue-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:border-blue-500' ?>">
    👷 Mitarbeiter <span class="opacity-75">(<?= $typeCounts['mitarbeiter'] ?>)</span>
  </a>
  <a href="?tab=active&type=freelancer" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition <?= $typeFilter === 'freelancer' ? 'bg-purple-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:border-purple-500' ?>">
    🧑‍💼 Freelancer <span class="opacity-75">(<?= $typeCounts['freelancer'] ?>)</span>
  </a>
  <a href="?tab=active&type=kleinunternehmen" class="px-3 py-1.5 rounded-lg text-xs font-semibold transition <?= $typeFilter === 'kleinunternehmen' ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:border-indigo-500' ?>">
    🏢 Firma <span class="opacity-75">(<?= $typeCounts['kleinunternehmen'] ?>)</span>
  </a>
  <?php if ($typeCounts['unset'] > 0): ?>
  <span class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-amber-50 border border-amber-200 text-amber-700">
    ⚠️ <?= $typeCounts['unset'] ?> ohne Typ
  </span>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Privacy-Banner: real names vs customer-facing display names -->
<div class="bg-amber-50 border border-amber-200 rounded-xl p-3 mb-4 text-xs flex items-center justify-between flex-wrap gap-3">
  <div class="flex items-center gap-2">
    <span class="text-lg">🔒</span>
    <div>
      <b class="text-amber-900">Privacy-Hinweis:</b>
      Echte Namen, E-Mails & Telefonnummern sieht <b>nur der Admin</b>. Kunden sehen nur den <b class="text-emerald-800">Kunden-Namen</b> (z.B. "Adrian H.").
      Spalte <span class="text-emerald-800 font-semibold">👁 öffentlich</span> ist bearbeitbar — leer = Auto-Generierung.
    </div>
  </div>
  <?php if ($tab === 'active'): $missing = (int) val("SELECT COUNT(*) FROM employee WHERE status=1 AND (display_name IS NULL OR display_name='')"); ?>
  <?php if ($missing > 0): ?>
  <form method="POST" class="inline">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="autofill_display_names"/>
    <button class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-semibold whitespace-nowrap">⚡ <?= $missing ?> Auto-füllen</button>
  </form>
  <?php endif; endif; ?>
</div>

<?php if (isset($_GET['autofilled'])): ?>
<div class="bg-emerald-50 border border-emerald-300 text-emerald-900 px-4 py-3 rounded-xl mb-4">✓ <?= (int)$_GET['autofilled'] ?> Kunden-Namen automatisch gesetzt (Vorname + Initial Nachname).</div>
<?php endif; ?>

<div x-data="{ editOpen:false, emp:{} }" class="bg-white rounded-xl border">
  <div class="p-5 border-b flex items-center justify-between">
    <h3 class="font-semibold"><?= $tab==='archive' ? 'Archivierte' : 'Aktive' ?> Partner (<?= count($employees) ?>)</h3>
    <div class="flex gap-3">
      <input type="text" placeholder="Suchen..." class="px-3 py-2 border rounded-lg text-sm w-64" oninput="filterRows(this.value)"/>
      <?php if ($tab === 'active'): ?>
      <button @click="emp={status:'1',tariff:'13'}; editOpen=true" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-medium">+ Neuer Partner</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm" id="tbl"><thead class="bg-gray-50"><tr>
      <th class="px-4 py-3 text-left font-medium text-gray-600">#</th>
      <th class="px-4 py-3 text-left font-medium text-gray-600">Name <span class="text-[9px] font-normal text-amber-600 uppercase">🔒 admin-only</span></th>
      <th class="px-4 py-3 text-left font-medium text-gray-600">Kunden-Name <span class="text-[9px] font-normal text-emerald-600 uppercase">👁 öffentlich</span></th>
      <th class="px-4 py-3 text-left font-medium text-gray-600">E-Mail <span class="text-[9px] font-normal text-amber-600 uppercase">🔒</span></th>
      <th class="px-4 py-3 text-left font-medium text-gray-600">Telefon <span class="text-[9px] font-normal text-amber-600 uppercase">🔒</span></th>
      <th class="px-4 py-3 text-left font-medium text-gray-600">Tarif</th>
      <th class="px-4 py-3 text-left font-medium text-gray-600">Typ</th>
      <th class="px-4 py-3 text-left font-medium text-gray-600">Erledigt</th>
      <th class="px-4 py-3 text-left font-medium text-gray-600">Offen</th>
      <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
      <th class="px-4 py-3 text-left font-medium text-gray-600">Aktionen</th>
    </tr></thead><tbody class="divide-y">
    <?php foreach ($employees as $e2): ?>
    <tr class="hover:bg-gray-50">
      <td class="px-4 py-3 text-gray-400"><?= $e2['emp_id'] ?></td>
      <td class="px-3 py-2">
        <input value="<?= e($e2['name']) ?>" onchange="updateEmp(<?=$e2['emp_id']?>,'name',this.value)" class="w-full px-2 py-1 text-sm font-medium border border-amber-200 rounded-lg focus:border-brand focus:outline-none bg-amber-50/40" title="Echter Name — nur Admin sieht das"/>
      </td>
      <td class="px-3 py-2">
        <?php
          // Default: Vorname + Initial Nachname (z.B. "Adrian H.")
          $_first = explode(' ', trim($e2['name'] ?? ''))[0];
          $_last  = trim($e2['surname'] ?? '');
          $_suggested = $_first . ($_last ? ' ' . strtoupper(substr($_last, 0, 1)) . '.' : '');
          $_display = trim($e2['display_name'] ?? '') ?: $_suggested;
        ?>
        <input value="<?= e($_display) ?>" placeholder="<?= e($_suggested) ?>" onchange="updateEmp(<?=$e2['emp_id']?>,'display_name',this.value)" class="w-full px-2 py-1 text-sm font-medium border border-emerald-300 rounded-lg focus:border-brand focus:outline-none bg-emerald-50/40" title="Das sehen Kunden — nicht der echte Name"/>
      </td>
      <td class="px-3 py-2">
        <input type="email" value="<?= e($e2['email']) ?>" onchange="updateEmp(<?=$e2['emp_id']?>,'email',this.value)" class="w-full px-2 py-1 text-sm border border-transparent rounded-lg hover:border-gray-200 focus:border-brand focus:outline-none bg-transparent text-brand"/>
      </td>
      <td class="px-3 py-2">
        <div class="flex items-center gap-1">
          <input value="<?= e($e2['phone']) ?>" onchange="updateEmp(<?=$e2['emp_id']?>,'phone',this.value)" class="w-full px-2 py-1 text-sm border border-transparent rounded-lg hover:border-gray-200 focus:border-brand focus:outline-none bg-transparent"/>
          <?php if ($e2['phone']): $ph = preg_replace('/[^+0-9]/','',$e2['phone']); ?>
          <a href="https://wa.me/<?= ltrim($ph,'+') ?>" target="_blank" title="WhatsApp" class="text-green-600 hover:text-green-700 flex-shrink-0"><svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg></a>
          <?php endif; ?>
        </div>
      </td>
      <td class="px-3 py-2">
        <input type="number" value="<?= $e2['tariff'] ?>" step="0.5" onchange="updateEmp(<?=$e2['emp_id']?>,'tariff',this.value)" class="w-20 px-2 py-1 text-sm font-medium border border-transparent rounded-lg hover:border-gray-200 focus:border-brand focus:outline-none bg-transparent text-right"/> <span class="text-xs text-gray-400">€/h</span>
      </td>
      <td class="px-4 py-3">
        <?php
          $ptBadge = match($e2['partner_type'] ?? '') {
            'mitarbeiter' => ['👷', 'bg-blue-50 text-blue-700', ucfirst($e2['contract_type'] ?? 'MA')],
            'freelancer'  => ['🧑‍💼', 'bg-purple-50 text-purple-700', 'Freelancer'],
            'kleinunternehmen' => ['🏢', 'bg-indigo-50 text-indigo-700', 'Firma' . (($e2['company_size'] ?? 0) ? ' ('.(int)$e2['company_size'].')' : '')],
            default => null,
          };
        ?>
        <?php if ($ptBadge): ?>
          <span class="px-2 py-1 text-[10px] font-bold rounded <?= $ptBadge[1] ?>"><?= $ptBadge[0] ?> <?= e($ptBadge[2]) ?></span>
        <?php else: ?>
          <span class="text-[10px] text-gray-300">—</span>
        <?php endif; ?>
      </td>
      <td class="px-4 py-3"><span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700"><?= $e2['done'] ?></span></td>
      <td class="px-4 py-3"><span class="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-700"><?= $e2['pending'] ?></span></td>
      <td class="px-4 py-2">
        <select onchange="updateEmpStatus(<?= $e2['emp_id'] ?>, this.value, this)" class="px-2 py-1 text-xs font-medium border rounded-lg cursor-pointer <?= $e2['status'] ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-50 text-gray-500 border-gray-200' ?>">
          <option value="1" <?= $e2['status'] ? 'selected' : '' ?>>Aktiv</option>
          <option value="0" <?= !$e2['status'] ? 'selected' : '' ?>>Inaktiv</option>
        </select>
      </td>
      <td class="px-4 py-3">
        <div class="flex gap-1">
          <?php if ($tab === 'archive'): ?>
            <form method="POST" class="inline"><input type="hidden" name="action" value="reactivate_employee"/><input type="hidden" name="emp_id" value="<?= $e2['emp_id'] ?>"/><?= csrfField() ?><button class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded-lg font-medium">Aktivieren</button></form>
          <?php else: ?>
            <a href="/admin/view-employee.php?id=<?= $e2['emp_id'] ?>" class="px-2 py-1 text-xs bg-brand text-white rounded-lg">Öffnen</a>
            <button @click='emp=<?= json_encode(["emp_id"=>$e2["emp_id"],"name"=>$e2["name"],"surname"=>$e2["surname"],"email"=>$e2["email"],"phone"=>$e2["phone"],"tariff"=>$e2["tariff"],"location"=>$e2["location"]??"","nationality"=>$e2["nationality"]??"","status"=>$e2["status"],"notes"=>$e2["notes"]??"","partner_type"=>$e2["partner_type"]??"","contract_type"=>$e2["contract_type"]??"","company_name"=>$e2["company_name"]??"","company_size"=>$e2["company_size"]??"","tax_id"=>$e2["tax_id"]??"","max_hours_month"=>$e2["max_hours_month"]??""],JSON_HEX_APOS) ?>; editOpen=true' class="px-2 py-1 text-xs bg-brand/10 text-brand rounded-lg">Edit</button>
            <form method="POST" class="inline"><input type="hidden" name="action" value="impersonate"/><input type="hidden" name="emp_id" value="<?= $e2['emp_id'] ?>"/><input type="hidden" name="_csrf" value="<?= csrfToken() ?>"/><button class="px-2 py-1 text-xs bg-blue-50 text-blue-700 rounded-lg">Login</button></form>
            <form method="POST" class="inline" onsubmit="return confirm('Partner archivieren?')"><input type="hidden" name="action" value="delete_employee"/><input type="hidden" name="emp_id" value="<?= $e2['emp_id'] ?>"/><?= csrfField() ?><button class="px-2 py-1 text-xs bg-red-50 text-red-600 rounded-lg">Archiv</button></form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?></tbody></table>
  </div>

  <template x-if="editOpen">
    <div class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center overflow-y-auto py-8">
      <div class="bg-white rounded-2xl p-6 w-full max-w-lg shadow-2xl m-4">
        <h3 class="text-lg font-semibold mb-4" x-text="emp.emp_id ? 'Partner bearbeiten' : 'Neuer Partner'"></h3>
        <form method="POST" class="space-y-4">
          <?= csrfField() ?>
          <input type="hidden" name="action" :value="emp.emp_id ? 'edit_employee' : 'add_employee'"/>
          <input type="hidden" name="emp_id" :value="emp.emp_id"/>
          <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-600 mb-1">Name *</label><input name="name" :value="emp.name" required class="w-full px-3 py-2.5 border rounded-xl"/></div>
            <div><label class="block text-sm font-medium text-gray-600 mb-1">Nachname</label><input name="surname" :value="emp.surname" class="w-full px-3 py-2.5 border rounded-xl"/></div>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-sm font-medium text-gray-600 mb-1">E-Mail *</label><input type="email" name="email" :value="emp.email" required class="w-full px-3 py-2.5 border rounded-xl"/></div>
            <div><label class="block text-sm font-medium text-gray-600 mb-1">Telefon</label><input name="phone" :value="emp.phone" class="w-full px-3 py-2.5 border rounded-xl"/></div>
          </div>
          <div class="grid grid-cols-3 gap-4">
            <div><label class="block text-sm font-medium text-gray-600 mb-1">Tarif (€/h)</label><input type="number" name="tariff" :value="emp.tariff" step="0.5" class="w-full px-3 py-2.5 border rounded-xl"/></div>
            <div><label class="block text-sm font-medium text-gray-600 mb-1">Ort</label><input name="location" :value="emp.location" class="w-full px-3 py-2.5 border rounded-xl"/></div>
            <div><label class="block text-sm font-medium text-gray-600 mb-1">Nationalität</label><input name="nationality" :value="emp.nationality" class="w-full px-3 py-2.5 border rounded-xl"/></div>
          </div>
          <div x-show="emp.emp_id"><label class="block text-sm font-medium text-gray-600 mb-1">Status</label>
            <select name="status" class="w-full px-3 py-2.5 border rounded-xl"><option value="1" :selected="emp.status=='1'">Aktiv</option><option value="0" :selected="emp.status=='0'">Inaktiv</option></select></div>

          <!-- Partner-Typ -->
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-2">Partner-Typ</label>
            <div class="grid grid-cols-3 gap-2 mb-2">
              <label class="cursor-pointer">
                <input type="radio" name="partner_type" value="mitarbeiter" x-model="emp.partner_type" class="sr-only peer"/>
                <div class="p-2 border-2 border-gray-200 rounded-lg text-center text-xs font-bold transition peer-checked:border-brand peer-checked:bg-brand/5">👷 Mitarbeiter</div>
              </label>
              <label class="cursor-pointer">
                <input type="radio" name="partner_type" value="freelancer" x-model="emp.partner_type" class="sr-only peer"/>
                <div class="p-2 border-2 border-gray-200 rounded-lg text-center text-xs font-bold transition peer-checked:border-brand peer-checked:bg-brand/5">🧑‍💼 Freelancer</div>
              </label>
              <label class="cursor-pointer">
                <input type="radio" name="partner_type" value="kleinunternehmen" x-model="emp.partner_type" class="sr-only peer"/>
                <div class="p-2 border-2 border-gray-200 rounded-lg text-center text-xs font-bold transition peer-checked:border-brand peer-checked:bg-brand/5">🏢 Firma</div>
              </label>
            </div>
            <div x-show="emp.partner_type === 'mitarbeiter'" x-cloak class="grid grid-cols-2 gap-2">
              <select name="contract_type" class="px-3 py-2 border rounded-xl text-sm">
                <option value="">— Arbeitsverhältnis —</option>
                <option value="minijob" :selected="emp.contract_type==='minijob'">Minijob</option>
                <option value="midijob" :selected="emp.contract_type==='midijob'">Midijob</option>
                <option value="teilzeit" :selected="emp.contract_type==='teilzeit'">Teilzeit</option>
                <option value="vollzeit" :selected="emp.contract_type==='vollzeit'">Vollzeit</option>
              </select>
              <input type="number" name="max_hours_month" :value="emp.max_hours_month" placeholder="Max Std/Monat" class="px-3 py-2 border rounded-xl text-sm"/>
            </div>
            <div x-show="emp.partner_type === 'freelancer'" x-cloak>
              <input type="text" name="tax_id" :value="emp.tax_id" placeholder="Steuernummer / USt-IdNr" class="w-full px-3 py-2 border rounded-xl text-sm font-mono"/>
            </div>
            <div x-show="emp.partner_type === 'kleinunternehmen'" x-cloak class="space-y-2">
              <input type="text" name="company_name" :value="emp.company_name" placeholder="Firmenname" class="w-full px-3 py-2 border rounded-xl text-sm"/>
              <div class="grid grid-cols-2 gap-2">
                <input type="number" name="company_size" :value="emp.company_size" min="1" max="10" placeholder="MA-Anzahl (1-10)" class="px-3 py-2 border rounded-xl text-sm"/>
                <input type="text" name="tax_id" :value="emp.tax_id" placeholder="USt-IdNr" class="px-3 py-2 border rounded-xl text-sm font-mono"/>
              </div>
            </div>
          </div>

          <div><label class="block text-sm font-medium text-gray-600 mb-1">Notizen</label><textarea name="notes" x-text="emp.notes" rows="2" class="w-full px-3 py-2.5 border rounded-xl"></textarea></div>
          <div x-show="!emp.emp_id"><label class="block text-sm font-medium text-gray-600 mb-1">Passwort</label><input name="password" value="<?= bin2hex(random_bytes(4)) ?>" class="w-full px-3 py-2.5 border rounded-xl"/></div>
          <div class="flex gap-3"><button type="button" @click="editOpen=false" class="flex-1 px-4 py-2.5 border rounded-xl">Abbrechen</button><button type="submit" class="flex-1 px-4 py-2.5 bg-brand text-white rounded-xl font-medium">Speichern</button></div>
        </form>
      </div>
    </div>
  </template>
</div>
<?php
$apiKey = API_KEY;
$script = <<<JS
function filterRows(q){q=q.toLowerCase();document.querySelectorAll('#tbl tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'})}
function updateEmp(id,field,val){
    fetch('/api/index.php?action=employee/update',{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':'$apiKey'},body:JSON.stringify({emp_id:id,field:field,value:val})})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            const el=event.target;el.style.transition='background 0.3s';el.style.background='#dcfce7';setTimeout(()=>{el.style.background='';},800);
        }else alert(d.error||'Fehler');
    });
}
function updateEmpStatus(id,val,el){
    fetch('/api/index.php?action=employee/status',{method:'POST',headers:{'Content-Type':'application/json','X-API-Key':'$apiKey'},body:JSON.stringify({emp_id:id,status:parseInt(val)})})
    .then(r=>r.json()).then(d=>{if(d.success){el.className='px-2 py-1 text-xs font-medium border rounded-lg cursor-pointer '+(val==='1'?'bg-green-50 text-green-700 border-green-200':'bg-gray-50 text-gray-500 border-gray-200');el.closest('tr').style.transition='background 0.3s';el.closest('tr').style.background='#dcfce7';setTimeout(()=>{el.closest('tr').style.background='';},800);}else alert(d.error||'Fehler');});
}
JS;
include __DIR__.'/../includes/footer.php'; ?>
