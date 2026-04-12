<?php
/**
 * Admin — Smart Locks Overview
 * Lists all connected locks (Nuki/Tuya/etc), battery, last state, recent events.
 * Links to customer, allows manual revoke/sync.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nuki-helpers.php';
requireAdmin();
$title = 'Smart Locks'; $page = 'locks';

// Manual actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /admin/locks.php'); exit; }
    $act = $_POST['action'] ?? '';
    $lockId = (int)($_POST['lock_id'] ?? 0);

    if ($act === 'deactivate' && $lockId) {
        q("UPDATE smart_locks SET is_active=0 WHERE lock_id=?", [$lockId]);
        audit('deactivate', 'smart_lock', $lockId, 'Admin deactivated lock');
        header('Location: /admin/locks.php?msg=deactivated'); exit;
    }
    if ($act === 'activate' && $lockId) {
        q("UPDATE smart_locks SET is_active=1 WHERE lock_id=?", [$lockId]);
        header('Location: /admin/locks.php?msg=activated'); exit;
    }
    if ($act === 'sync' && $lockId) {
        $lk = one("SELECT * FROM smart_locks WHERE lock_id=?", [$lockId]);
        if ($lk && $lk['provider'] === 'nuki' && $lk['auth_token']) {
            $state = nukiGetSmartlock($lk['auth_token'], $lk['device_id']);
            if ($state && !isset($state['error'])) {
                $battery = $state['state']['batteryCharge'] ?? null;
                $stateName = $state['state']['stateName'] ?? null;
                q("UPDATE smart_locks SET battery_level=?, last_state=?, last_checked_at=NOW() WHERE lock_id=?",
                  [$battery, $stateName, $lockId]);
                logLockEvent($lockId, (int)$lk['customer_id_fk'], 'admin', (int)$_SESSION['uid'], $_SESSION['uname'] ?? 'admin', 'sync', 'success', 'Manual admin sync');
            }
        }
        header('Location: /admin/locks.php?msg=synced'); exit;
    }
    if ($act === 'revoke_code') {
        $codeId = (int)($_POST['code_id'] ?? 0);
        $code = one("SELECT ac.*, sl.auth_token, sl.device_id, sl.provider, sl.customer_id_fk
                     FROM lock_access_codes ac
                     LEFT JOIN smart_locks sl ON ac.lock_id_fk = sl.lock_id
                     WHERE ac.code_id=?", [$codeId]);
        if ($code && $code['auth_id_remote'] && $code['provider'] === 'nuki') {
            nukiRevokeAuth($code['auth_token'], $code['device_id'], $code['auth_id_remote']);
        }
        q("UPDATE lock_access_codes SET status='revoked' WHERE code_id=?", [$codeId]);
        logLockEvent((int)$code['lock_id_fk'], (int)$code['customer_id_fk'], 'admin', (int)$_SESSION['uid'], $_SESSION['uname'] ?? 'admin', 'code_revoked', 'success', "Code #$codeId revoked manually");
        header('Location: /admin/locks.php?msg=revoked'); exit;
    }
}

$locks = all("SELECT sl.*, c.name AS customer_name, c.customer_type, s.title AS service_title,
                     (SELECT COUNT(*) FROM lock_events le WHERE le.lock_id_fk = sl.lock_id) AS total_events,
                     (SELECT MAX(happened_at) FROM lock_events le WHERE le.lock_id_fk = sl.lock_id) AS last_event_at,
                     (SELECT COUNT(*) FROM lock_access_codes ac WHERE ac.lock_id_fk = sl.lock_id AND ac.status='active') AS active_codes
              FROM smart_locks sl
              LEFT JOIN customer c ON sl.customer_id_fk = c.customer_id
              LEFT JOIN services s ON sl.linked_service_id = s.s_id
              ORDER BY sl.is_active DESC, sl.created_at DESC");

$recentEvents = all("SELECT le.*, sl.device_name, c.name AS customer_name
                     FROM lock_events le
                     LEFT JOIN smart_locks sl ON le.lock_id_fk = sl.lock_id
                     LEFT JOIN customer c ON le.customer_id_fk = c.customer_id
                     ORDER BY le.happened_at DESC LIMIT 30");

$activeCodes = all("SELECT ac.*, sl.device_name, c.name AS customer_name
                    FROM lock_access_codes ac
                    LEFT JOIN smart_locks sl ON ac.lock_id_fk = sl.lock_id
                    LEFT JOIN customer c ON sl.customer_id_fk = c.customer_id
                    WHERE ac.status='active' AND (ac.allowed_until IS NULL OR ac.allowed_until > NOW())
                    ORDER BY ac.allowed_from DESC LIMIT 30");

// Stats
$totalLocks = count($locks);
$activeLocks = count(array_filter($locks, fn($l) => $l['is_active']));
$lowBattery = count(array_filter($locks, fn($l) => $l['battery_level'] !== null && $l['battery_level'] < 20));
$eventsToday = (int) val("SELECT COUNT(*) FROM lock_events WHERE DATE(happened_at)=CURDATE()");

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!empty($_GET['msg'])): ?>
<div class="mb-4 px-4 py-3 rounded-xl bg-green-50 border border-green-200 text-green-800 text-sm">
  <?php
  $map = ['deactivated' => 'Lock deaktiviert.', 'activated' => 'Lock aktiviert.', 'synced' => 'Zustand synchronisiert.', 'revoked' => 'Code widerrufen.'];
  echo e($map[$_GET['msg']] ?? 'Gespeichert.');
  ?>
</div>
<?php endif; ?>

<div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
  <div>
    <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
      <span>🔐</span> Smart Locks
    </h1>
    <p class="text-sm text-gray-500 mt-1">Übersicht aller verbundenen smarten Schlösser — Nuki, Tuya, Yale, Salto, etc.</p>
  </div>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-extrabold text-gray-900"><?= $totalLocks ?></div>
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mt-1">Schlösser gesamt</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-extrabold text-green-600"><?= $activeLocks ?></div>
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mt-1">Aktiv</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-extrabold <?= $lowBattery > 0 ? 'text-red-600' : 'text-gray-900' ?>"><?= $lowBattery ?></div>
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mt-1">Batterie niedrig</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-extrabold text-brand"><?= $eventsToday ?></div>
    <div class="text-[11px] uppercase tracking-wide text-gray-500 mt-1">Events heute</div>
  </div>
</div>

<!-- Locks table -->
<div class="bg-white rounded-xl border overflow-hidden mb-6">
  <div class="px-5 py-3 border-b bg-gray-50 flex items-center justify-between">
    <h2 class="font-bold text-gray-900">Alle Schlösser</h2>
    <span class="text-xs text-gray-500"><?= $totalLocks ?> Einträge</span>
  </div>
  <?php if (empty($locks)): ?>
    <div class="p-8 text-center text-sm text-gray-400">Noch keine Schlösser verbunden. Kunden können im Customer Portal unter Smart Home einen Nuki-Account verknüpfen.</div>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
        <tr>
          <th class="px-4 py-2 text-left">Gerät</th>
          <th class="px-4 py-2 text-left">Kunde</th>
          <th class="px-4 py-2 text-left">Service</th>
          <th class="px-4 py-2 text-left">Provider</th>
          <th class="px-4 py-2 text-left">Batterie</th>
          <th class="px-4 py-2 text-left">Status</th>
          <th class="px-4 py-2 text-left">Letztes Event</th>
          <th class="px-4 py-2 text-left">Events</th>
          <th class="px-4 py-2 text-left">Aktionen</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($locks as $lk): ?>
        <tr class="<?= $lk['is_active'] ? '' : 'bg-gray-50 opacity-60' ?>">
          <td class="px-4 py-3">
            <div class="font-semibold text-gray-900"><?= e($lk['device_name'] ?: 'Lock #'.$lk['lock_id']) ?></div>
            <div class="text-[10px] text-gray-400 font-mono truncate max-w-[140px]" title="<?= e($lk['device_id']) ?>"><?= e($lk['device_id']) ?></div>
          </td>
          <td class="px-4 py-3">
            <a href="/admin/view-customer.php?id=<?= (int)$lk['customer_id_fk'] ?>" class="text-brand hover:underline"><?= e($lk['customer_name'] ?: '—') ?></a>
            <div class="text-[10px] text-gray-400"><?= e($lk['customer_type'] ?: '') ?></div>
          </td>
          <td class="px-4 py-3 text-gray-700"><?= e($lk['service_title'] ?: '<alle>') ?></td>
          <td class="px-4 py-3">
            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-gray-100 text-gray-700"><?= e($lk['provider']) ?></span>
          </td>
          <td class="px-4 py-3">
            <?php if ($lk['battery_level'] !== null): ?>
              <span class="font-mono <?= $lk['battery_level'] < 20 ? 'text-red-600 font-bold' : ($lk['battery_level'] < 40 ? 'text-amber-600' : 'text-gray-700') ?>">
                <?= (int)$lk['battery_level'] ?>%
              </span>
            <?php else: ?>
              <span class="text-gray-300">—</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3">
            <?= e($lk['last_state'] ?: '—') ?>
            <?php if ($lk['active_codes'] > 0): ?>
              <div class="text-[10px] text-green-700 mt-0.5"><?= (int)$lk['active_codes'] ?> aktive Codes</div>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-xs text-gray-600">
            <?= $lk['last_event_at'] ? e(date('d.m. H:i', strtotime($lk['last_event_at']))) : '<span class="text-gray-300">—</span>' ?>
          </td>
          <td class="px-4 py-3 text-gray-600"><?= (int)$lk['total_events'] ?></td>
          <td class="px-4 py-3">
            <div class="flex items-center gap-1">
              <form method="POST" class="inline">
                <input type="hidden" name="_csrf" value="<?= csrfToken() ?>"/>
                <input type="hidden" name="action" value="sync"/>
                <input type="hidden" name="lock_id" value="<?= (int)$lk['lock_id'] ?>"/>
                <button class="px-2 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded text-[11px] font-semibold" title="Zustand jetzt abfragen">Sync</button>
              </form>
              <?php if ($lk['is_active']): ?>
              <form method="POST" class="inline" onsubmit="return confirm('Lock wirklich deaktivieren?')">
                <input type="hidden" name="_csrf" value="<?= csrfToken() ?>"/>
                <input type="hidden" name="action" value="deactivate"/>
                <input type="hidden" name="lock_id" value="<?= (int)$lk['lock_id'] ?>"/>
                <button class="px-2 py-1 bg-red-50 hover:bg-red-100 text-red-700 rounded text-[11px] font-semibold">Deakt.</button>
              </form>
              <?php else: ?>
              <form method="POST" class="inline">
                <input type="hidden" name="_csrf" value="<?= csrfToken() ?>"/>
                <input type="hidden" name="action" value="activate"/>
                <input type="hidden" name="lock_id" value="<?= (int)$lk['lock_id'] ?>"/>
                <button class="px-2 py-1 bg-green-50 hover:bg-green-100 text-green-700 rounded text-[11px] font-semibold">Aktivieren</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Active access codes -->
<?php if (!empty($activeCodes)): ?>
<div class="bg-white rounded-xl border overflow-hidden mb-6">
  <div class="px-5 py-3 border-b bg-gray-50">
    <h2 class="font-bold text-gray-900">Aktive Zugangscodes</h2>
    <div class="text-xs text-gray-500 mt-0.5">Temporäre Partner-Codes, die aktuell gültig sind</div>
  </div>
  <table class="w-full text-sm">
    <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-500">
      <tr>
        <th class="px-4 py-2 text-left">Lock</th>
        <th class="px-4 py-2 text-left">Kunde</th>
        <th class="px-4 py-2 text-left">Name</th>
        <th class="px-4 py-2 text-left">Code</th>
        <th class="px-4 py-2 text-left">Gültig</th>
        <th class="px-4 py-2 text-left">Aktion</th>
      </tr>
    </thead>
    <tbody class="divide-y">
      <?php foreach ($activeCodes as $ac): ?>
      <tr>
        <td class="px-4 py-3 font-semibold"><?= e($ac['device_name'] ?: '—') ?></td>
        <td class="px-4 py-3"><?= e($ac['customer_name'] ?: '—') ?></td>
        <td class="px-4 py-3 text-gray-700"><?= e($ac['name']) ?></td>
        <td class="px-4 py-3 font-mono text-xs bg-gray-50"><?= e(substr($ac['code'], 0, 2)) ?>••••</td>
        <td class="px-4 py-3 text-xs text-gray-600">
          <?= e(date('d.m. H:i', strtotime($ac['allowed_from']))) ?> — <?= e(date('d.m. H:i', strtotime($ac['allowed_until']))) ?>
        </td>
        <td class="px-4 py-3">
          <form method="POST" onsubmit="return confirm('Code wirklich widerrufen?')">
            <input type="hidden" name="_csrf" value="<?= csrfToken() ?>"/>
            <input type="hidden" name="action" value="revoke_code"/>
            <input type="hidden" name="code_id" value="<?= (int)$ac['code_id'] ?>"/>
            <button class="px-2 py-1 bg-red-50 hover:bg-red-100 text-red-700 rounded text-[11px] font-semibold">Widerrufen</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Recent events -->
<div class="bg-white rounded-xl border overflow-hidden mb-8">
  <div class="px-5 py-3 border-b bg-gray-50">
    <h2 class="font-bold text-gray-900">Letzte Ereignisse</h2>
    <div class="text-xs text-gray-500 mt-0.5">Lückenloses Audit-Log aller Lock-Aktionen</div>
  </div>
  <?php if (empty($recentEvents)): ?>
    <div class="p-8 text-center text-sm text-gray-400">Noch keine Events protokolliert.</div>
  <?php else: ?>
  <div class="divide-y">
    <?php foreach ($recentEvents as $ev):
      $actColor = match($ev['action']) {
        'unlock' => 'text-green-700 bg-green-50',
        'lock' => 'text-blue-700 bg-blue-50',
        'code_generated' => 'text-purple-700 bg-purple-50',
        'code_revoked' => 'text-amber-700 bg-amber-50',
        'failed' => 'text-red-700 bg-red-50',
        default => 'text-gray-600 bg-gray-50',
      };
      $typeColor = match($ev['triggered_by_type']) {
        'partner' => 'text-blue-600',
        'customer' => 'text-green-600',
        'admin' => 'text-purple-600',
        'auto','system' => 'text-gray-500',
        default => 'text-gray-500',
      };
    ?>
    <div class="px-5 py-3 text-sm flex items-center gap-3 <?= $ev['result'] === 'failed' ? 'bg-red-50/30' : '' ?>">
      <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $actColor ?>"><?= e($ev['action']) ?></span>
      <div class="flex-1 min-w-0">
        <div class="font-semibold text-gray-900 truncate">
          <?= e($ev['device_name'] ?: 'Lock') ?>
          <span class="text-gray-400">·</span>
          <?= e($ev['customer_name'] ?: '—') ?>
        </div>
        <div class="text-[11px] text-gray-500 flex items-center gap-2 flex-wrap">
          <span class="<?= $typeColor ?> font-semibold"><?= e($ev['triggered_by_type']) ?></span>
          <span>·</span>
          <span><?= e($ev['triggered_by_name'] ?: '—') ?></span>
          <?php if ($ev['job_id_fk']): ?>
            <span>·</span>
            <span>Job #<?= (int)$ev['job_id_fk'] ?></span>
          <?php endif; ?>
          <?php if ($ev['notes']): ?>
            <span>·</span>
            <span class="italic"><?= e($ev['notes']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="text-[11px] text-gray-400 font-mono whitespace-nowrap"><?= e(date('d.m. H:i:s', strtotime($ev['happened_at']))) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
