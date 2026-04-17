<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Tenants — User-Zuweisung';
$page  = 'tenants';

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/tenants.php?err=csrf'); exit; }
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $updates = $_POST['tenant'] ?? [];
        $changed = 0;
        foreach ($updates as $uid => $tenant) {
            $uid = (int) $uid;
            $tenant = in_array($tenant, ['fleckfrei', 'la-renting'], true) ? $tenant : 'fleckfrei';
            if ($uid <= 0) continue;
            try {
                // Update users + matching customer/employee row (by email)
                $u = one("SELECT email, type FROM users WHERE user_id=?", [$uid]);
                if (!$u) continue;
                q("UPDATE users SET tenant=? WHERE user_id=?", [$tenant, $uid]);
                if ($u['type'] === 'customer') {
                    q("UPDATE customer SET tenant=? WHERE email=?", [$tenant, $u['email']]);
                } elseif ($u['type'] === 'employee') {
                    q("UPDATE employee SET tenant=? WHERE email=?", [$tenant, $u['email']]);
                }
                $changed++;
            } catch (Exception $e) {}
        }
        audit('tenant_reassign', 'users', 0, "$changed users");
        header('Location: /admin/tenants.php?saved=' . $changed); exit;
    }
    if ($action === 'bulk_by_email_domain') {
        $domain = trim($_POST['domain'] ?? '');
        $tenant = in_array($_POST['bulk_tenant'] ?? '', ['fleckfrei','la-renting'], true) ? $_POST['bulk_tenant'] : 'fleckfrei';
        if ($domain) {
            $like = '%@' . ltrim($domain, '@');
            $affected = q("UPDATE users SET tenant=? WHERE email LIKE ?", [$tenant, $like])->rowCount();
            q("UPDATE customer SET tenant=? WHERE email LIKE ?", [$tenant, $like]);
            q("UPDATE employee SET tenant=? WHERE email LIKE ?", [$tenant, $like]);
            audit('tenant_bulk', 'users', 0, "$affected users → $tenant via *$domain");
            header('Location: /admin/tenants.php?bulk=' . $affected); exit;
        }
    }
}

// Filter & search
$typeFilter = $_GET['type'] ?? '';
$search     = trim($_GET['q'] ?? '');
$tenantFilter = $_GET['tenant'] ?? '';

$where = ['1=1'];
$params = [];
if ($typeFilter && in_array($typeFilter, ['customer','employee','admin'], true)) { $where[] = 'type=?'; $params[] = $typeFilter; }
if ($search) { $where[] = '(email LIKE ? OR name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($tenantFilter && in_array($tenantFilter, ['fleckfrei','la-renting'], true)) { $where[] = 'tenant=?'; $params[] = $tenantFilter; }
$users = all("SELECT user_id, email, name, type, tenant FROM users WHERE " . implode(' AND ', $where) . " ORDER BY type, email LIMIT 1000", $params);

$counts = [
    'fleckfrei_customer'   => (int) val("SELECT COUNT(*) FROM users WHERE type='customer' AND tenant='fleckfrei'"),
    'la_renting_customer'  => (int) val("SELECT COUNT(*) FROM users WHERE type='customer' AND tenant='la-renting'"),
    'fleckfrei_employee'   => (int) val("SELECT COUNT(*) FROM users WHERE type='employee' AND tenant='fleckfrei'"),
    'la_renting_employee'  => (int) val("SELECT COUNT(*) FROM users WHERE type='employee' AND tenant='la-renting'"),
];

include __DIR__ . '/../includes/layout.php';
?>

<?php if (isset($_GET['saved'])): ?>
  <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">
    ✓ <?= (int) $_GET['saved'] ?> User-Zuweisungen gespeichert.
  </div>
<?php endif; ?>
<?php if (isset($_GET['bulk'])): ?>
  <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4">
    ✓ <?= (int) $_GET['bulk'] ?> Benutzer per Bulk-Regel zugewiesen.
  </div>
<?php endif; ?>

<div class="bg-white rounded-xl border p-5 mb-5">
  <h3 class="font-semibold mb-3">Übersicht</h3>
  <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
    <div class="p-3 rounded-lg bg-emerald-50 border border-emerald-200">
      <div class="text-xs text-gray-500">🟢 Fleckfrei — Kunden</div>
      <div class="text-2xl font-bold text-emerald-700"><?= $counts['fleckfrei_customer'] ?></div>
    </div>
    <div class="p-3 rounded-lg bg-amber-50 border border-amber-200">
      <div class="text-xs text-gray-500">🟡 Max Co-Host — Kunden</div>
      <div class="text-2xl font-bold text-amber-700"><?= $counts['la_renting_customer'] ?></div>
    </div>
    <div class="p-3 rounded-lg bg-emerald-50 border border-emerald-200">
      <div class="text-xs text-gray-500">🟢 Fleckfrei — Partner</div>
      <div class="text-2xl font-bold text-emerald-700"><?= $counts['fleckfrei_employee'] ?></div>
    </div>
    <div class="p-3 rounded-lg bg-amber-50 border border-amber-200">
      <div class="text-xs text-gray-500">🟡 Max Co-Host — Partner</div>
      <div class="text-2xl font-bold text-amber-700"><?= $counts['la_renting_employee'] ?></div>
    </div>
  </div>
</div>

<div class="bg-white rounded-xl border p-5 mb-5">
  <h3 class="font-semibold mb-3">Bulk-Zuweisung per E-Mail-Domain</h3>
  <form method="POST" class="flex flex-wrap gap-3 items-end">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="bulk_by_email_domain"/>
    <div>
      <label class="block text-xs font-medium text-gray-500 mb-1">Domain (z.B. <code>la-renting.de</code>)</label>
      <input name="domain" class="px-3 py-2 border rounded-lg text-sm" placeholder="la-renting.de" required/>
    </div>
    <div>
      <label class="block text-xs font-medium text-gray-500 mb-1">Tenant</label>
      <select name="bulk_tenant" class="px-3 py-2 border rounded-lg text-sm">
        <option value="la-renting">Max Co-Host</option>
        <option value="fleckfrei">Fleckfrei</option>
      </select>
    </div>
    <button class="px-4 py-2 bg-brand text-white rounded-lg text-sm font-medium">Alle mit dieser Domain zuweisen</button>
  </form>
  <p class="text-xs text-gray-500 mt-2">Alle Benutzer mit passender E-Mail-Domain werden auf den gewählten Tenant gesetzt.</p>
</div>

<form method="GET" class="bg-white rounded-xl border p-4 mb-4 flex flex-wrap gap-3 items-end">
  <div>
    <label class="block text-xs font-medium text-gray-500 mb-1">Typ</label>
    <select name="type" class="px-3 py-2 border rounded-lg text-sm">
      <option value="">Alle</option>
      <option value="customer" <?= $typeFilter==='customer'?'selected':'' ?>>Kunde</option>
      <option value="employee" <?= $typeFilter==='employee'?'selected':'' ?>>Partner</option>
      <option value="admin"    <?= $typeFilter==='admin'?'selected':'' ?>>Admin</option>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-500 mb-1">Tenant</label>
    <select name="tenant" class="px-3 py-2 border rounded-lg text-sm">
      <option value="">Alle</option>
      <option value="fleckfrei"  <?= $tenantFilter==='fleckfrei'?'selected':'' ?>>Fleckfrei</option>
      <option value="la-renting" <?= $tenantFilter==='la-renting'?'selected':'' ?>>Max Co-Host</option>
    </select>
  </div>
  <div class="flex-1 min-w-[200px]">
    <label class="block text-xs font-medium text-gray-500 mb-1">Suche (E-Mail / Name)</label>
    <input name="q" value="<?= e($search) ?>" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="..."/>
  </div>
  <button class="px-4 py-2 bg-brand text-white rounded-lg text-sm font-medium">Filtern</button>
</form>

<form method="POST">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="save"/>
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">E-Mail</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Name</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Typ</th>
            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 w-40">Tenant</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php foreach ($users as $u): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2 font-mono text-xs"><?= e($u['email']) ?></td>
              <td class="px-4 py-2"><?= e($u['name']) ?></td>
              <td class="px-4 py-2">
                <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100"><?= e($u['type']) ?></span>
              </td>
              <td class="px-4 py-2">
                <select name="tenant[<?= (int)$u['user_id'] ?>]" class="w-full px-2 py-1 border rounded text-xs">
                  <option value="fleckfrei"  <?= $u['tenant']==='fleckfrei'?'selected':'' ?>>🟢 Fleckfrei</option>
                  <option value="la-renting" <?= $u['tenant']==='la-renting'?'selected':'' ?>>🟡 Max Co-Host</option>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($users)): ?>
            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">Keine Benutzer gefunden.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if (!empty($users)): ?>
      <div class="p-4 border-t bg-gray-50 flex items-center justify-between">
        <div class="text-sm text-gray-500"><?= count($users) ?> Benutzer angezeigt (max. 1000). Änderungen werden auch in Kunden/Partner-Tabellen synchronisiert.</div>
        <button class="px-5 py-2 bg-brand text-white rounded-lg text-sm font-medium">Alle Änderungen speichern</button>
      </div>
    <?php endif; ?>
  </div>
</form>
