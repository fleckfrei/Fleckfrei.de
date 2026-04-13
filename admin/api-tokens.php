<?php
/**
 * Admin: API-Token-Management pro Kunde
 * Generieren, regenerieren, blockieren, entblockieren.
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Kunden-API-Tokens'; $page = 'api-tokens';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $cid = (int)($_POST['customer_id'] ?? 0);
    $act = $_POST['action'] ?? '';

    if ($act === 'generate' || $act === 'regenerate') {
        $token = bin2hex(random_bytes(24)); // 48 Zeichen
        q("UPDATE customer SET api_token=?, api_created_at=NOW(), api_requests_count=0, api_access_blocked=0 WHERE customer_id=?", [$token, $cid]);
        audit('api_token_' . $act, 'customer', $cid, 'Admin: ' . me()['email']);
        header("Location: /admin/api-tokens.php?saved=1#cust-$cid"); exit;
    }
    if ($act === 'revoke') {
        q("UPDATE customer SET api_token=NULL WHERE customer_id=?", [$cid]);
        audit('api_token_revoke', 'customer', $cid, 'Admin: ' . me()['email']);
        header("Location: /admin/api-tokens.php?saved=1"); exit;
    }
    if ($act === 'toggle_block') {
        q("UPDATE customer SET api_access_blocked = 1 - COALESCE(api_access_blocked, 0) WHERE customer_id=?", [$cid]);
        header("Location: /admin/api-tokens.php?saved=1"); exit;
    }
}

$filter = trim($_GET['q'] ?? '');
$sql = "SELECT customer_id, name, email, customer_type, api_token, api_created_at, api_last_used, api_requests_count, api_access_blocked FROM customer WHERE status=1";
$params = [];
if ($filter !== '') { $sql .= " AND (name LIKE ? OR email LIKE ?)"; $params[] = "%$filter%"; $params[] = "%$filter%"; }
$sql .= " ORDER BY api_token IS NULL, api_last_used DESC, name LIMIT 200";
$customers = all($sql, $params);

$withToken = array_filter($customers, fn($c) => !empty($c['api_token']));
$blocked = array_filter($customers, fn($c) => !empty($c['api_access_blocked']));

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['saved'])): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">✓ Gespeichert</div>
<?php endif; ?>

<div class="mb-6">
  <h1 class="text-2xl font-bold text-gray-900">Kunden-API-Tokens</h1>
  <p class="text-sm text-gray-600 mt-1">Jeder Kunde kann einen eigenen API-Key haben, um Jobs programmatisch zu buchen (z.B. n8n, Zapier, Smoobu, eigene Apps).</p>
</div>

<div class="grid grid-cols-3 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-5">
    <div class="text-3xl font-bold text-gray-900"><?= count($customers) ?></div>
    <div class="text-xs text-gray-600 mt-1">Kunden gesamt</div>
  </div>
  <div class="bg-white rounded-xl border p-5">
    <div class="text-3xl font-bold text-brand-dark"><?= count($withToken) ?></div>
    <div class="text-xs text-gray-600 mt-1">Mit API-Token</div>
  </div>
  <div class="bg-white rounded-xl border p-5">
    <div class="text-3xl font-bold text-red-600"><?= count($blocked) ?></div>
    <div class="text-xs text-gray-600 mt-1">API gesperrt</div>
  </div>
</div>

<!-- Dokumentation -->
<div class="bg-brand-light border border-brand/30 rounded-xl p-5 mb-6">
  <h3 class="font-bold text-brand-dark mb-2">📘 API-Endpoints</h3>
  <p class="text-xs text-gray-800 mb-3">Base-URL: <code class="bg-white px-2 py-0.5 rounded font-mono">https://app.<?= SITE_DOMAIN ?>/api/customer-api.php</code> · Auth: <code class="bg-white px-2 py-0.5 rounded">X-Customer-Token</code> Header oder <code class="bg-white px-2 py-0.5 rounded">?token=XXX</code></p>
  <table class="w-full text-xs">
    <thead><tr class="text-left font-bold text-gray-700"><th class="pb-1">Action</th><th class="pb-1">Method</th><th class="pb-1">Zweck</th></tr></thead>
    <tbody class="text-gray-800">
      <tr><td><code>?action=me</code></td><td>GET</td><td>Kundendaten + Stats</td></tr>
      <tr><td><code>?action=jobs&from=YYYY-MM-DD&to=…</code></td><td>GET</td><td>Eigene Jobs in Zeitraum</td></tr>
      <tr><td><code>?action=job/123</code></td><td>GET</td><td>Job-Detail</td></tr>
      <tr><td><code>?action=services</code></td><td>GET</td><td>Eigene Properties</td></tr>
      <tr><td><code>?action=invoices</code></td><td>GET</td><td>Rechnungen</td></tr>
      <tr><td><code>?action=book</code></td><td>POST</td><td>Neue Buchung (JSON: service_id, date, time, hours, notes)</td></tr>
      <tr><td><code>?action=cancel/123</code></td><td>POST</td><td>Job stornieren</td></tr>
    </tbody>
  </table>
</div>

<!-- Filter + Liste -->
<form method="GET" class="mb-4 flex items-center gap-2">
  <input type="search" name="q" value="<?= e($filter) ?>" placeholder="Kunde suchen..." class="flex-1 px-3 py-2 border rounded-lg text-sm"/>
  <button class="px-4 py-2 bg-brand text-white rounded-lg text-sm font-semibold">Suchen</button>
</form>

<div class="bg-white rounded-xl border overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-gray-50 border-b">
      <tr>
        <th class="text-left px-4 py-3 font-medium">Kunde</th>
        <th class="text-left px-4 py-3 font-medium">Typ</th>
        <th class="text-left px-4 py-3 font-medium">Token</th>
        <th class="text-left px-4 py-3 font-medium">Erstellt</th>
        <th class="text-left px-4 py-3 font-medium">Letzte Nutzung</th>
        <th class="text-right px-4 py-3 font-medium">Requests</th>
        <th class="text-right px-4 py-3 font-medium">Aktionen</th>
      </tr>
    </thead>
    <tbody class="divide-y">
      <?php foreach ($customers as $c): ?>
      <tr id="cust-<?= $c['customer_id'] ?>" class="<?= !empty($c['api_access_blocked']) ? 'bg-red-50' : '' ?>">
        <td class="px-4 py-3">
          <div class="font-semibold text-gray-900"><?= e($c['name']) ?></div>
          <div class="text-[10px] text-gray-500"><?= e($c['email']) ?> · #<?= $c['customer_id'] ?></div>
        </td>
        <td class="px-4 py-3"><span class="text-xs px-2 py-0.5 bg-gray-100 rounded"><?= e($c['customer_type']) ?></span></td>
        <td class="px-4 py-3 font-mono">
          <?php if ($c['api_token']): ?>
            <div class="flex items-center gap-1">
              <span class="text-[10px] text-gray-900 break-all"><?= substr($c['api_token'], 0, 12) ?>...<?= substr($c['api_token'], -6) ?></span>
              <button onclick="navigator.clipboard.writeText('<?= e($c['api_token']) ?>'); this.textContent='✓'" class="text-[10px] px-1.5 py-0.5 bg-brand text-white rounded font-bold">Copy</button>
            </div>
            <?php if (!empty($c['api_access_blocked'])): ?>
            <span class="text-[10px] text-red-700 font-bold">🚫 GESPERRT</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-gray-400 text-xs">—</span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-3 text-xs text-gray-600"><?= $c['api_created_at'] ? date('d.m.Y', strtotime($c['api_created_at'])) : '—' ?></td>
        <td class="px-4 py-3 text-xs text-gray-600"><?= $c['api_last_used'] ? date('d.m.Y H:i', strtotime($c['api_last_used'])) : '—' ?></td>
        <td class="px-4 py-3 text-right font-mono text-xs"><?= (int)$c['api_requests_count'] ?></td>
        <td class="px-4 py-3 text-right space-x-1">
          <?php if (!$c['api_token']): ?>
            <form method="POST" class="inline"><?= csrfField() ?>
              <input type="hidden" name="customer_id" value="<?= $c['customer_id'] ?>"/>
              <input type="hidden" name="action" value="generate"/>
              <button class="px-2 py-1 bg-brand text-white rounded text-xs font-bold">+ Generieren</button>
            </form>
          <?php else: ?>
            <form method="POST" class="inline" onsubmit="return confirm('Neuen Token erstellen? Alter wird ungültig.')"><?= csrfField() ?>
              <input type="hidden" name="customer_id" value="<?= $c['customer_id'] ?>"/>
              <input type="hidden" name="action" value="regenerate"/>
              <button class="px-2 py-1 bg-amber-500 text-white rounded text-xs">🔄 Neu</button>
            </form>
            <form method="POST" class="inline"><?= csrfField() ?>
              <input type="hidden" name="customer_id" value="<?= $c['customer_id'] ?>"/>
              <input type="hidden" name="action" value="toggle_block"/>
              <button class="px-2 py-1 <?= !empty($c['api_access_blocked']) ? 'bg-green-500' : 'bg-red-500' ?> text-white rounded text-xs">
                <?= !empty($c['api_access_blocked']) ? '✓ Freigeben' : '🚫 Sperren' ?>
              </button>
            </form>
            <form method="POST" class="inline" onsubmit="return confirm('Token löschen?')"><?= csrfField() ?>
              <input type="hidden" name="customer_id" value="<?= $c['customer_id'] ?>"/>
              <input type="hidden" name="action" value="revoke"/>
              <button class="px-2 py-1 bg-gray-400 text-white rounded text-xs">Entfernen</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
