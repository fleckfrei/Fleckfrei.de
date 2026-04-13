<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Partner-Bewerbungen'; $page = 'partners';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/partner-applications.php'); exit; }
    $act = $_POST['action'] ?? '';
    $appId = (int)($_POST['app_id'] ?? 0);

    if ($act === 'set_status' && $appId) {
        $st = in_array($_POST['status'], ['new','reviewing','accepted','rejected']) ? $_POST['status'] : 'reviewing';
        q("UPDATE partner_applications SET status=?, reviewed_at=NOW(), reviewed_by=?, notes=? WHERE app_id=?",
          [$st, $_SESSION['uid'], $_POST['notes'] ?? '', $appId]);
        audit('update', 'partner_application', $appId, "Status: $st");
        header("Location: /admin/partner-applications.php?updated=$appId"); exit;
    }
    if ($act === 'convert_to_employee' && $appId) {
        $a = one("SELECT * FROM partner_applications WHERE app_id=?", [$appId]);
        if ($a) {
            // Create employee from application
            q("INSERT INTO employee (name, surname, email, phone, address, status, contract_type, created_at)
               VALUES (?, '', ?, ?, ?, 1, ?, NOW())",
               [$a['full_name'], $a['email'], $a['phone'],
                trim($a['street'] . ', ' . $a['postal_code'] . ' ' . $a['city']),
                $a['contract_type']]);
            $empId = (int)lastInsertId();
            q("UPDATE partner_applications SET status='accepted', reviewed_at=NOW(), reviewed_by=?, notes=CONCAT(IFNULL(notes,''),'\nKonvertiert zu emp_id=$empId') WHERE app_id=?",
              [$_SESSION['uid'], $appId]);
            audit('convert', 'partner_application', $appId, "→ employee #$empId");
            header("Location: /admin/view-employee.php?id=$empId&saved=converted"); exit;
        }
    }
}

$status = $_GET['status'] ?? 'all';
$where = $status === 'all' ? '1=1' : 'status = ' . (in_array($status, ['new','reviewing','accepted','rejected']) ? "'$status'" : "'new'");
$apps = all("SELECT * FROM partner_applications WHERE $where ORDER BY created_at DESC LIMIT 200");
$counts = [
    'new' => (int)val("SELECT COUNT(*) FROM partner_applications WHERE status='new'"),
    'reviewing' => (int)val("SELECT COUNT(*) FROM partner_applications WHERE status='reviewing'"),
    'accepted' => (int)val("SELECT COUNT(*) FROM partner_applications WHERE status='accepted'"),
    'rejected' => (int)val("SELECT COUNT(*) FROM partner_applications WHERE status='rejected'"),
];

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['updated'])): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">✓ Bewerbung aktualisiert.</div>
<?php endif; ?>

<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-bold">📩 Partner-Bewerbungen</h1>
  <a href="/partner-bewerbung.php" target="_blank" class="px-4 py-2 bg-brand text-white rounded-lg text-sm font-medium">🔗 Public-Form öffnen</a>
</div>

<div class="flex gap-2 mb-4 overflow-x-auto">
  <?php foreach (['all'=>'Alle','new'=>'Neu','reviewing'=>'In Prüfung','accepted'=>'Angenommen','rejected'=>'Abgelehnt'] as $k=>$lbl):
    $cnt = $k==='all' ? array_sum($counts) : $counts[$k];
    $active = $status === $k ? 'bg-brand text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200';
  ?>
  <a href="?status=<?= $k ?>" class="px-4 py-2 rounded-lg text-sm font-medium <?= $active ?>"><?= $lbl ?> (<?= $cnt ?>)</a>
  <?php endforeach; ?>
</div>

<div class="bg-white rounded-xl border overflow-hidden">
  <?php if ($apps): ?>
  <table class="w-full text-sm">
    <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
      <tr>
        <th class="px-4 py-3 text-left">Name</th>
        <th class="px-4 py-3 text-left">Kontakt</th>
        <th class="px-4 py-3 text-left">Vertrag</th>
        <th class="px-4 py-3 text-left">Docs</th>
        <th class="px-4 py-3 text-left">Status</th>
        <th class="px-4 py-3 text-left">Eingang</th>
        <th class="px-4 py-3 text-right">Aktion</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($apps as $a):
        $statusBadge = match($a['status']) {
          'new' => '<span class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs">🆕 Neu</span>',
          'reviewing' => '<span class="px-2 py-0.5 bg-amber-100 text-amber-700 rounded text-xs">👀 Prüfung</span>',
          'accepted' => '<span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs">✅ Angenommen</span>',
          'rejected' => '<span class="px-2 py-0.5 bg-red-100 text-red-700 rounded text-xs">❌ Abgelehnt</span>',
          default => '—'
        };
      ?>
      <tr class="border-t hover:bg-gray-50">
        <td class="px-4 py-3">
          <div class="font-medium"><?= e($a['full_name']) ?></div>
          <div class="text-xs text-gray-500"><?= $a['birth_date'] ? date('d.m.Y', strtotime($a['birth_date'])) : '' ?> · <?= e($a['city'] ?? '') ?></div>
        </td>
        <td class="px-4 py-3">
          <div><a href="mailto:<?= e($a['email']) ?>" class="text-brand hover:underline"><?= e($a['email']) ?></a></div>
          <div class="text-xs text-gray-500"><a href="tel:<?= e($a['phone']) ?>"><?= e($a['phone']) ?></a></div>
        </td>
        <td class="px-4 py-3">
          <div class="text-xs"><?= e($a['contract_type']) ?></div>
          <div class="text-xs text-gray-400"><?= e($a['desired_role']) ?></div>
        </td>
        <td class="px-4 py-3 text-xs">
          <?= $a['has_gewerbe'] ? '✓ Gewerbe<br>' : '' ?>
          <?= $a['has_haftpflicht'] ? '✓ Haftpfl.<br>' : '' ?>
          <?= $a['has_fuehrungszeugnis'] ? '✓ FZeugnis' : '' ?>
        </td>
        <td class="px-4 py-3"><?= $statusBadge ?></td>
        <td class="px-4 py-3 text-xs text-gray-500"><?= date('d.m.Y H:i', strtotime($a['created_at'])) ?></td>
        <td class="px-4 py-3 text-right whitespace-nowrap">
          <button onclick="document.getElementById('detail-<?= $a['app_id'] ?>').classList.toggle('hidden')" class="text-xs text-brand hover:underline">📄 Details</button>
          <?php if ($a['status'] !== 'accepted'): ?>
          <form method="POST" class="inline" onsubmit="return confirm('Diese Bewerbung als Partner anlegen?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="convert_to_employee"/>
            <input type="hidden" name="app_id" value="<?= $a['app_id'] ?>"/>
            <button class="text-xs text-green-700 hover:underline ml-2">✅ → Partner</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <tr id="detail-<?= $a['app_id'] ?>" class="hidden bg-gray-50 border-t">
        <td colspan="7" class="px-4 py-4">
          <?php if ($a['experience']): ?>
          <div class="mb-2"><strong class="text-xs text-gray-500">ERFAHRUNG:</strong><br><div class="text-sm whitespace-pre-wrap"><?= e($a['experience']) ?></div></div>
          <?php endif; ?>
          <?php if ($a['motivation']): ?>
          <div class="mb-2"><strong class="text-xs text-gray-500">MOTIVATION:</strong><br><div class="text-sm whitespace-pre-wrap"><?= e($a['motivation']) ?></div></div>
          <?php endif; ?>
          <div class="mb-2"><strong class="text-xs text-gray-500">ADRESSE:</strong> <?= e($a['street']) ?>, <?= e($a['postal_code']) ?> <?= e($a['city']) ?>, <?= e($a['country']) ?></div>
          <form method="POST" class="flex gap-2 mt-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="set_status"/>
            <input type="hidden" name="app_id" value="<?= $a['app_id'] ?>"/>
            <select name="status" class="px-2 py-1.5 border rounded-lg text-xs">
              <option value="new" <?= $a['status']==='new'?'selected':'' ?>>🆕 Neu</option>
              <option value="reviewing" <?= $a['status']==='reviewing'?'selected':'' ?>>👀 In Prüfung</option>
              <option value="accepted" <?= $a['status']==='accepted'?'selected':'' ?>>✅ Angenommen</option>
              <option value="rejected" <?= $a['status']==='rejected'?'selected':'' ?>>❌ Abgelehnt</option>
            </select>
            <input name="notes" placeholder="Notiz (optional)" value="<?= e($a['notes'] ?? '') ?>" class="flex-1 px-2 py-1.5 border rounded-lg text-xs"/>
            <button class="px-3 py-1.5 bg-brand text-white rounded-lg text-xs">Speichern</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div class="text-center text-gray-400 py-12">Keine Bewerbungen in dieser Kategorie.</div>
  <?php endif; ?>
</div>

<div class="mt-6 bg-blue-50 border border-blue-200 rounded-xl p-4 text-sm">
  💡 <strong>Public-Form-URL:</strong> <a href="https://app.fleckfrei.de/partner-bewerbung.php" target="_blank" class="text-brand hover:underline">https://app.fleckfrei.de/partner-bewerbung.php</a> — direkt teilen oder als Link auf der Website einbauen.
</div>
