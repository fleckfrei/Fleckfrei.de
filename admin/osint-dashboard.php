<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'OSINT API'; $page = 'osint-dashboard';
global $dbLocal;

// Create tables if not exist
try {
    $dbLocal->exec("CREATE TABLE IF NOT EXISTS osint_api_keys (
        id INT AUTO_INCREMENT PRIMARY KEY, api_key VARCHAR(128) UNIQUE NOT NULL, name VARCHAR(255) NOT NULL,
        email VARCHAR(255), tier VARCHAR(32) DEFAULT 'free', rate_limit INT DEFAULT 10,
        daily_limit INT DEFAULT 50, monthly_limit INT DEFAULT 500,
        active TINYINT DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_used DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $dbLocal->exec("CREATE TABLE IF NOT EXISTS osint_api_usage (
        id INT AUTO_INCREMENT PRIMARY KEY, api_key_id INT, action VARCHAR(100), target VARCHAR(255),
        ip VARCHAR(64), response_time_ms INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_key (api_key_id), INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $act = $_POST['action'] ?? '';
    if ($act === 'create_key') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $tier = $_POST['tier'] ?? 'free';
        $limits = ['free' => [10, 50, 500], 'pro' => [30, 200, 5000], 'enterprise' => [100, 1000, 50000]];
        $l = $limits[$tier] ?? $limits['free'];
        $key = 'flk_osi_' . bin2hex(random_bytes(16));
        try {
            $dbLocal->prepare("INSERT INTO osint_api_keys (api_key, name, email, tier, rate_limit, daily_limit, monthly_limit) VALUES (?,?,?,?,?,?,?)")
                ->execute([$key, $name, $email, $tier, $l[0], $l[1], $l[2]]);
            $newKey = $key;
        } catch (Exception $e) { $error = $e->getMessage(); }
    }
    if ($act === 'toggle_key') {
        $id = (int)($_POST['key_id'] ?? 0);
        $dbLocal->prepare("UPDATE osint_api_keys SET active = CASE WHEN active=1 THEN 0 ELSE 1 END WHERE id=?")->execute([$id]);
    }
    if ($act === 'delete_key') {
        $id = (int)($_POST['key_id'] ?? 0);
        $dbLocal->prepare("DELETE FROM osint_api_keys WHERE id=?")->execute([$id]);
        $dbLocal->prepare("DELETE FROM osint_api_usage WHERE api_key_id=?")->execute([$id]);
    }
}

// Load data
$keys = []; $usage = []; $stats = [];
try {
    $keys = $dbLocal->query("SELECT k.*, (SELECT COUNT(*) FROM osint_api_usage u WHERE u.api_key_id=k.id AND u.created_at > datetime('now','-1 day')) as today_usage, (SELECT COUNT(*) FROM osint_api_usage u WHERE u.api_key_id=k.id) as total_usage FROM osint_api_keys k ORDER BY k.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $usage = $dbLocal->query("SELECT u.*, k.name as key_name FROM osint_api_usage u LEFT JOIN osint_api_keys k ON u.api_key_id=k.id ORDER BY u.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $stats = $dbLocal->query("SELECT action, COUNT(*) as cnt, AVG(response_time_ms) as avg_ms FROM osint_api_usage WHERE created_at > datetime('now','-7 day') GROUP BY action ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

include __DIR__ . '/../includes/layout.php';
?>

<!-- New Key Created -->
<?php if (!empty($newKey)): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-5 py-4 rounded-xl mb-4">
  <div class="font-semibold mb-1">API Key erstellt!</div>
  <code class="text-sm bg-green-100 px-3 py-1 rounded block font-mono select-all"><?= e($newKey) ?></code>
  <div class="text-xs mt-1 text-green-600">Kopiere diesen Key jetzt — er wird nicht erneut angezeigt.</div>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-4">
    <div class="text-xs text-gray-500">API Keys</div>
    <div class="text-2xl font-bold mt-1"><?= count($keys) ?></div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-xs text-gray-500">Requests (7 Tage)</div>
    <div class="text-2xl font-bold mt-1"><?= array_sum(array_column($stats, 'cnt')) ?></div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-xs text-gray-500">Avg Response</div>
    <div class="text-2xl font-bold mt-1"><?= $stats ? round(array_sum(array_column($stats, 'avg_ms')) / count($stats)) : 0 ?>ms</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-xs text-gray-500">VPS Tools</div>
    <div class="text-2xl font-bold mt-1">9</div>
  </div>
</div>

<!-- Create Key -->
<div class="bg-white rounded-xl border mb-4 overflow-hidden">
  <div class="px-5 py-3 border-b"><h3 class="font-semibold">Neuen API Key erstellen</h3></div>
  <form method="POST" class="p-5">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create_key"/>
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Name</label>
        <input type="text" name="name" required placeholder="Kundenname" class="w-full px-3 py-2 border rounded-lg"/></div>
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Email</label>
        <input type="email" name="email" placeholder="kunde@firma.de" class="w-full px-3 py-2 border rounded-lg"/></div>
      <div><label class="block text-xs font-medium text-gray-500 mb-1">Tier</label>
        <select name="tier" class="w-full px-3 py-2 border rounded-lg">
          <option value="free">Free (50/Tag)</option>
          <option value="pro">Pro (200/Tag)</option>
          <option value="enterprise">Enterprise (1000/Tag)</option>
        </select></div>
      <div class="flex items-end"><button class="w-full px-4 py-2 bg-brand text-white rounded-lg font-medium">Key erstellen</button></div>
    </div>
  </form>
</div>

<!-- API Keys Table -->
<div class="bg-white rounded-xl border mb-4 overflow-hidden">
  <div class="px-5 py-3 border-b"><h3 class="font-semibold">API Keys (<?= count($keys) ?>)</h3></div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50"><tr>
        <th class="px-4 py-3 text-left">Name</th><th class="px-4 py-3 text-left">Key</th>
        <th class="px-4 py-3 text-left">Tier</th><th class="px-4 py-3 text-right">Heute</th>
        <th class="px-4 py-3 text-right">Gesamt</th><th class="px-4 py-3 text-left">Letzte Nutzung</th>
        <th class="px-4 py-3">Status</th><th class="px-4 py-3">Aktionen</th>
      </tr></thead>
      <tbody class="divide-y">
        <?php foreach ($keys as $k): ?>
        <tr class="hover:bg-gray-50 <?= !$k['active'] ? 'opacity-50' : '' ?>">
          <td class="px-4 py-3"><div class="font-medium"><?= e($k['name']) ?></div><div class="text-xs text-gray-400"><?= e($k['email'] ?? '') ?></div></td>
          <td class="px-4 py-3 font-mono text-xs"><?= substr($k['api_key'], 0, 12) ?>...<?= substr($k['api_key'], -4) ?></td>
          <td class="px-4 py-3"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $k['tier']==='enterprise'?'bg-purple-100 text-purple-700':($k['tier']==='pro'?'bg-blue-100 text-blue-700':'bg-gray-100 text-gray-700') ?>"><?= ucfirst($k['tier']) ?></span></td>
          <td class="px-4 py-3 text-right"><?= $k['today_usage'] ?>/<?= $k['daily_limit'] ?></td>
          <td class="px-4 py-3 text-right"><?= $k['total_usage'] ?></td>
          <td class="px-4 py-3 text-xs text-gray-400"><?= $k['last_used'] ? date('d.m. H:i', strtotime($k['last_used'])) : '—' ?></td>
          <td class="px-4 py-3 text-center">
            <span class="px-2 py-0.5 rounded text-xs <?= $k['active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>"><?= $k['active'] ? 'Aktiv' : 'Inaktiv' ?></span>
          </td>
          <td class="px-4 py-3 text-center">
            <form method="POST" class="inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle_key"/><input type="hidden" name="key_id" value="<?= $k['id'] ?>"/>
              <button class="text-xs text-brand hover:underline"><?= $k['active'] ? 'Deaktivieren' : 'Aktivieren' ?></button></form>
            <form method="POST" class="inline ml-2" onsubmit="return confirm('Key wirklich loeschen?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_key"/><input type="hidden" name="key_id" value="<?= $k['id'] ?>"/>
              <button class="text-xs text-red-500 hover:underline">Loeschen</button></form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($keys)): ?><tr><td colspan="8" class="px-4 py-8 text-center text-gray-400">Noch keine API Keys erstellt</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Usage by Endpoint -->
<?php if (!empty($stats)): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="px-5 py-3 border-b"><h3 class="font-semibold">Nutzung nach Endpoint (7 Tage)</h3></div>
    <div class="p-5 space-y-2">
      <?php foreach ($stats as $s): ?>
      <div class="flex items-center gap-3">
        <span class="text-sm font-mono w-28"><?= e($s['action']) ?></span>
        <div class="flex-1 bg-gray-100 rounded-full h-2"><div class="bg-brand rounded-full h-2" style="width:<?= min(100, ($s['cnt'] / max(1, $stats[0]['cnt'])) * 100) ?>%"></div></div>
        <span class="text-sm font-medium w-12 text-right"><?= $s['cnt'] ?></span>
        <span class="text-xs text-gray-400 w-16 text-right"><?= round($s['avg_ms']) ?>ms</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Recent Requests -->
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="px-5 py-3 border-b"><h3 class="font-semibold">Letzte Requests</h3></div>
    <div class="overflow-y-auto" style="max-height:300px">
      <table class="w-full text-xs">
        <thead class="bg-gray-50 sticky top-0"><tr><th class="px-3 py-2 text-left">Zeit</th><th class="px-3 py-2 text-left">Key</th><th class="px-3 py-2 text-left">Action</th><th class="px-3 py-2 text-left">Target</th><th class="px-3 py-2 text-right">ms</th></tr></thead>
        <tbody class="divide-y">
          <?php foreach ($usage as $u): ?>
          <tr><td class="px-3 py-1.5 text-gray-400"><?= date('d.m H:i', strtotime($u['created_at'])) ?></td>
            <td class="px-3 py-1.5"><?= e($u['key_name'] ?? '?') ?></td>
            <td class="px-3 py-1.5 font-mono"><?= e($u['action']) ?></td>
            <td class="px-3 py-1.5 truncate max-w-[120px]"><?= e($u['target']) ?></td>
            <td class="px-3 py-1.5 text-right"><?= $u['response_time_ms'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- API Docs -->
<div class="bg-white rounded-xl border overflow-hidden">
  <div class="px-5 py-3 border-b"><h3 class="font-semibold">API Dokumentation</h3></div>
  <div class="p-5 text-sm space-y-3">
    <p class="text-gray-600">Base URL: <code class="bg-gray-100 px-2 py-0.5 rounded">https://app.fleckfrei.de/api/osint-api.php</code></p>
    <p class="text-gray-600">Auth: <code class="bg-gray-100 px-2 py-0.5 rounded">X-API-Key: &lt;key&gt;</code> oder <code class="bg-gray-100 px-2 py-0.5 rounded">Authorization: Bearer &lt;key&gt;</code></p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-3">
      <div class="border rounded-lg p-3"><code class="text-brand font-mono text-xs">GET ?action=health</code><div class="text-xs text-gray-400 mt-1">System-Status + verfuegbare Tools</div></div>
      <div class="border rounded-lg p-3"><code class="text-brand font-mono text-xs">POST ?action=verify-email</code><div class="text-xs text-gray-400 mt-1">Email pruefen (MX, Typ, Gravatar)</div></div>
      <div class="border rounded-lg p-3"><code class="text-brand font-mono text-xs">POST ?action=verify-phone</code><div class="text-xs text-gray-400 mt-1">Telefon pruefen (Land, Carrier)</div></div>
      <div class="border rounded-lg p-3"><code class="text-brand font-mono text-xs">POST ?action=search</code><div class="text-xs text-gray-400 mt-1">SearXNG Suche (30+ Engines)</div></div>
      <div class="border rounded-lg p-3"><code class="text-brand font-mono text-xs">POST ?action=quick</code><div class="text-xs text-gray-400 mt-1">Quick Scan (Holehe + PhoneInfoga)</div></div>
      <div class="border rounded-lg p-3"><code class="text-brand font-mono text-xs">POST ?action=scan</code><div class="text-xs text-gray-400 mt-1">Full Deep Scan (alle 9 Tools)</div></div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
