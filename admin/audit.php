<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Protokoll'; $page = 'audit';

$filter = $_GET['entity'] ?? '';
$actionFilter = $_GET['action'] ?? '';
$userFilter = $_GET['user'] ?? '';
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$search = $_GET['q'] ?? '';

// Build query
$where = "created_at BETWEEN ? AND ?";
$p = [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'];
if ($filter) { $where .= " AND entity=?"; $p[] = $filter; }
if ($actionFilter) { $where .= " AND action=?"; $p[] = $actionFilter; }
if ($userFilter) { $where .= " AND user_name LIKE ?"; $p[] = "%$userFilter%"; }
if ($search) { $where .= " AND (details LIKE ? OR entity LIKE ? OR user_name LIKE ?)"; $p[] = "%$search%"; $p[] = "%$search%"; $p[] = "%$search%"; }

try {
    $logs = allLocal("SELECT * FROM audit_log WHERE $where ORDER BY created_at DESC LIMIT 500", $p);
} catch (Exception $e) {
    try {
        $logs = all("SELECT * FROM audit_log WHERE $where ORDER BY created_at DESC LIMIT 500", $p);
    } catch (Exception $e2) {
        $logs = [];
    }
}

// Stats
$totalActions = count($logs);
$actionCounts = [];
$entityCounts = [];
$userCounts = [];
foreach ($logs as $l) {
    $actionCounts[$l['action']] = ($actionCounts[$l['action']] ?? 0) + 1;
    $entityCounts[$l['entity']] = ($entityCounts[$l['entity']] ?? 0) + 1;
    $userCounts[$l['user_name']] = ($userCounts[$l['user_name']] ?? 0) + 1;
}
arsort($actionCounts);
arsort($entityCounts);
arsort($userCounts);

// Get unique entities and actions for filter dropdowns
$allEntities = array_unique(array_column($logs, 'entity'));
$allActions = array_unique(array_column($logs, 'action'));
sort($allEntities);
sort($allActions);

include __DIR__ . '/../includes/layout.php';
?>

<!-- Filters -->
<form class="bg-white rounded-xl border p-4 mb-4">
  <div class="grid grid-cols-2 md:grid-cols-7 gap-3">
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Von</label>
      <input type="date" name="from" value="<?= e($dateFrom) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/></div>
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Bis</label>
      <input type="date" name="to" value="<?= e($dateTo) ?>" class="w-full px-3 py-2 border rounded-lg text-sm"/></div>
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Bereich</label>
      <select name="entity" class="w-full px-3 py-2 border rounded-lg text-sm">
        <option value="">Alle</option>
        <?php foreach (['job'=>'Jobs','customer'=>'Kunden','invoice'=>'Rechnungen','payment'=>'Zahlungen','employee'=>'Partner','service'=>'Services','osint'=>'OSI Scans','login'=>'Login','settings'=>'Einstellungen'] as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $filter===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Aktion</label>
      <select name="action" class="w-full px-3 py-2 border rounded-lg text-sm">
        <option value="">Alle</option>
        <?php foreach (['create'=>'Erstellt','update'=>'Geändert','delete'=>'Gelöscht','view'=>'Angesehen','login'=>'Login','payment'=>'Zahlung','scan'=>'Scan','export'=>'Export'] as $k=>$v): ?>
        <option value="<?= $k ?>" <?= $actionFilter===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Benutzer</label>
      <input type="text" name="user" value="<?= e($userFilter) ?>" placeholder="Name..." class="w-full px-3 py-2 border rounded-lg text-sm"/></div>
    <div><label class="block text-xs font-medium text-gray-500 mb-1">Suche</label>
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="Details suchen..." class="w-full px-3 py-2 border rounded-lg text-sm"/></div>
    <div class="flex items-end"><button class="w-full px-4 py-2 bg-brand text-white rounded-lg text-sm font-medium">Filtern</button></div>
  </div>
</form>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-bold text-brand"><?= $totalActions ?></div>
    <div class="text-xs text-gray-500">Aktionen im Zeitraum</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-bold text-blue-600"><?= count($userCounts) ?></div>
    <div class="text-xs text-gray-500">Aktive Benutzer</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-xs text-gray-500 mb-1">Top Bereich</div>
    <?php $topEntity = array_key_first($entityCounts); ?>
    <div class="font-semibold"><?= e($topEntity ?? '-') ?> <span class="text-gray-400 font-normal">(<?= $entityCounts[$topEntity] ?? 0 ?>)</span></div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-xs text-gray-500 mb-1">Top Aktion</div>
    <?php $topAction = array_key_first($actionCounts); ?>
    <div class="font-semibold"><?= e($topAction ?? '-') ?> <span class="text-gray-400 font-normal">(<?= $actionCounts[$topAction] ?? 0 ?>)</span></div>
  </div>
</div>

<!-- Log Table -->
<div class="bg-white rounded-xl border">
  <div class="p-5 border-b flex items-center justify-between">
    <h3 class="font-semibold">Aktivitäts-Protokoll (<?= $totalActions ?>)</h3>
    <div class="flex gap-2">
      <input type="text" placeholder="Live-Suche..." oninput="filterAudit(this.value)" class="px-3 py-1.5 border rounded-lg text-sm w-48"/>
      <a href="/api/index.php?action=export/audit&from=<?= $dateFrom ?>&to=<?= $dateTo ?>&key=<?= API_KEY ?>" class="px-3 py-1.5 border rounded-lg text-sm text-gray-600 hover:bg-gray-50">CSV Export</a>
    </div>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm" id="auditTable">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500">Zeitpunkt</th>
        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500">Benutzer</th>
        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500">Aktion</th>
        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500">Bereich</th>
        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500">ID</th>
        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500">Details</th>
      </tr></thead>
      <tbody class="divide-y">
      <?php foreach ($logs as $log):
        $actionIcon = match($log['action'] ?? '') {
            'create' => '<span class="text-green-600">+</span>',
            'update' => '<span class="text-blue-600">~</span>',
            'delete' => '<span class="text-red-600">-</span>',
            'payment' => '<span class="text-brand">$</span>',
            'login' => '<span class="text-purple-600">→</span>',
            'scan' => '<span class="text-amber-600">⌕</span>',
            default => '<span class="text-gray-400">·</span>',
        };
        $entityBadge = match($log['entity'] ?? '') {
            'job' => 'bg-blue-50 text-blue-700',
            'customer' => 'bg-green-50 text-green-700',
            'invoice' => 'bg-amber-50 text-amber-700',
            'payment' => 'bg-brand-light text-brand',
            'employee' => 'bg-purple-50 text-purple-700',
            'osint' => 'bg-red-50 text-red-700',
            default => 'bg-gray-50 text-gray-600',
        };
      ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-2 text-xs font-mono text-gray-500 whitespace-nowrap"><?= date('d.m.Y H:i:s', strtotime($log['created_at'])) ?></td>
        <td class="px-4 py-2 text-sm">
          <div class="flex items-center gap-2">
            <div class="w-6 h-6 rounded-full bg-gray-100 flex items-center justify-center text-[10px] font-bold text-gray-500"><?= strtoupper(mb_substr($log['user_name']??'?',0,1)) ?></div>
            <span class="font-medium"><?= e($log['user_name']??'-') ?></span>
          </div>
        </td>
        <td class="px-4 py-2 text-sm"><?= $actionIcon ?> <?= e($log['action']??'-') ?></td>
        <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-md text-xs font-medium <?= $entityBadge ?>"><?= e($log['entity']??'-') ?></span></td>
        <td class="px-4 py-2 text-xs font-mono text-gray-500">#<?= $log['entity_id']??'' ?></td>
        <td class="px-4 py-2 text-xs text-gray-500 max-w-xs truncate" title="<?= e($log['details']??'') ?>"><?= e($log['details']??'') ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($logs)): ?>
      <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Keine Einträge im Zeitraum.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$script = <<<JS
function filterAudit(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#auditTable tbody tr').forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
JS;
include __DIR__ . '/../includes/footer.php';
?>
