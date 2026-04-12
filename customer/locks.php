<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nuki-helpers.php';
requireCustomer();
$title = 'Smart Locks'; $page = 'locks';
$cid = me()['id'];
$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);

// Host-only
if (!in_array($customer['customer_type'] ?? '', ['Airbnb', 'Host', 'Booking', 'Short-Term Rental'], true)) {
    header('Location: /customer/'); exit;
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /customer/locks.php?error=csrf'); exit; }
    $action = $_POST['action'] ?? '';

    // Link lock to a service (apartment)
    if ($action === 'link_service') {
        $lockId = (int)($_POST['lock_id'] ?? 0);
        $sId = (int)($_POST['service_id'] ?? 0);
        $owned = one("SELECT * FROM smart_locks WHERE lock_id=? AND customer_id_fk=?", [$lockId, $cid]);
        if ($owned) {
            q("UPDATE smart_locks SET linked_service_id=? WHERE lock_id=?", [$sId ?: null, $lockId]);
            audit('update', 'smart_locks', $lockId, "Verknüpft mit Service #$sId");
        }
        header('Location: /customer/locks.php?saved=link'); exit;
    }

    // Disconnect lock (revokes auth, soft-delete)
    if ($action === 'disconnect') {
        $lockId = (int)($_POST['lock_id'] ?? 0);
        q("UPDATE smart_locks SET is_active=0 WHERE lock_id=? AND customer_id_fk=?", [$lockId, $cid]);
        logLockEvent($lockId, $cid, 'customer', $cid, $customer['name'] ?? '', 'revoked', 'success', 'Vom Kunden getrennt');
        audit('delete', 'smart_locks', $lockId, 'Lock disconnected');
        header('Location: /customer/locks.php?saved=disconnect'); exit;
    }

    // Test unlock (admin only in practice — marked here for demo)
    if ($action === 'test_unlock') {
        $lockId = (int)($_POST['lock_id'] ?? 0);
        $lock = one("SELECT * FROM smart_locks WHERE lock_id=? AND customer_id_fk=? AND is_active=1", [$lockId, $cid]);
        if ($lock && $lock['auth_token']) {
            $result = nukiUnlock($lock['auth_token'], $lock['device_id']);
            $ok = !isset($result['error']);
            logLockEvent($lockId, $cid, 'customer', $cid, $customer['name'] ?? '', 'unlock', $ok ? 'success' : 'failed', $ok ? 'Test-Öffnung' : ($result['error'] ?? ''));
            header('Location: /customer/locks.php?saved=' . ($ok ? 'unlocked' : 'unlock_failed')); exit;
        }
    }
}

$locks = all("SELECT l.*, s.title AS service_title FROM smart_locks l LEFT JOIN services s ON l.linked_service_id = s.s_id WHERE l.customer_id_fk=? AND l.is_active=1 ORDER BY l.created_at DESC", [$cid]);
$services = all("SELECT s_id, title FROM services WHERE customer_id_fk=? AND status=1 ORDER BY title", [$cid]);
$recentEvents = all("SELECT e.*, l.device_name FROM lock_events e LEFT JOIN smart_locks l ON e.lock_id_fk=l.lock_id WHERE e.customer_id_fk=? ORDER BY e.happened_at DESC LIMIT 20", [$cid]);

$nukiConfigured = defined('NUKI_CLIENT_ID') && NUKI_CLIENT_ID !== '';
$authState = bin2hex(random_bytes(8));
$_SESSION['nuki_oauth_state'] = $authState;
$_SESSION['nuki_oauth_cid'] = $cid;
$authUrl = $nukiConfigured ? nukiAuthorizeUrl($authState) : null;

$savedMsg = $_GET['saved'] ?? '';

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Back button -->
<div class="mb-4">
  <a href="/customer/smarthome.php" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

<div class="mb-6">
  <div class="flex items-center gap-3 mb-2 flex-wrap">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">🔐 Smart Locks</h1>
    <span class="px-2 py-0.5 rounded-full bg-brand/10 text-brand text-[10px] font-bold uppercase tracking-wider">Phase 1 · Nuki</span>
  </div>
  <p class="text-gray-500 text-sm">Verbinden Sie Ihre Nuki Smart Locks mit Fleckfrei. Partner erhalten automatisch temporären Zugang während des Reinigungstermins.</p>
</div>

<?php if ($savedMsg): ?>
<div class="mb-4 card-elev p-4 flex items-center gap-2 text-sm <?= str_contains($savedMsg, 'failed') ? 'border-red-200 bg-red-50 text-red-800' : 'border-green-200 bg-green-50 text-green-800' ?>">
  <?= match($savedMsg) {
      'link' => '✓ Verknüpfung gespeichert.',
      'disconnect' => '✓ Schloss getrennt.',
      'unlocked' => '✓ Schloss wurde entsperrt.',
      'unlock_failed' => '⚠ Entsperren fehlgeschlagen.',
      'connected' => '✓ Nuki-Konto erfolgreich verbunden.',
      default => 'Gespeichert.',
  } ?>
</div>
<?php endif; ?>

<?php if (!$nukiConfigured): ?>
<!-- Setup-Hinweis (wenn API-Keys nicht konfiguriert) -->
<div class="card-elev p-6 mb-6 border-amber-200 bg-amber-50">
  <div class="flex items-start gap-3">
    <svg class="w-6 h-6 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
    <div class="flex-1">
      <h3 class="font-bold text-amber-900 mb-1">Nuki API noch nicht eingerichtet</h3>
      <p class="text-sm text-amber-800 mb-3">Der System-Administrator muss zuerst eine Nuki Web API App registrieren:</p>
      <ol class="text-xs text-amber-800 space-y-1 list-decimal pl-5 mb-3">
        <li>Bei <a href="https://web.nuki.io/de/#/admin/web-api" target="_blank" class="underline font-semibold">web.nuki.io → Admin → Web API</a> anmelden</li>
        <li>Neue App registrieren mit Redirect-URI: <code class="px-1 bg-amber-100 rounded text-[10px]">https://app.fleckfrei.de/api/nuki-callback.php</code></li>
        <li>Client ID und Client Secret in <code class="px-1 bg-amber-100 rounded text-[10px]">includes/config.php</code> eintragen</li>
        <li>Erforderliche Scopes: <code class="text-[10px]">account, smartlock, smartlock.action, smartlock.auth</code></li>
      </ol>
      <p class="text-xs text-amber-800">Danach wird hier der "Nuki verbinden" Button erscheinen.</p>
    </div>
  </div>
</div>
<?php else: ?>
<!-- Nuki verbinden Button -->
<div class="card-elev p-6 mb-6 bg-gradient-to-br from-orange-50 to-transparent border-orange-200">
  <div class="flex items-start gap-4 flex-wrap">
    <div class="w-14 h-14 rounded-2xl bg-orange-500 text-white flex items-center justify-center flex-shrink-0 text-3xl">🔐</div>
    <div class="flex-1 min-w-0">
      <h2 class="font-bold text-gray-900">Nuki-Konto verbinden</h2>
      <p class="text-sm text-gray-600 mt-1 mb-3">Melden Sie sich mit Ihrem Nuki-Account an und autorisieren Sie Fleckfrei, Ihre Schlösser temporär zu öffnen wenn ein Partner zur Reinigung kommt.</p>
      <a href="<?= e($authUrl) ?>" class="inline-flex items-center gap-2 px-5 py-3 bg-orange-500 hover:bg-orange-600 text-white rounded-xl font-bold text-sm shadow-lg shadow-orange-500/30 transition">
        Nuki-Konto verbinden
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
      </a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Connected Locks -->
<h2 class="text-lg font-bold text-gray-900 mb-3"><?= count($locks) ?> verbundene Schlösser</h2>
<?php if (empty($locks)): ?>
<div class="card-elev p-10 text-center">
  <div class="w-16 h-16 mx-auto rounded-full bg-gray-100 flex items-center justify-center mb-3">
    <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
  </div>
  <p class="text-gray-500 font-medium">Noch keine Smart Locks verbunden</p>
  <p class="text-xs text-gray-400 mt-1">Verbinden Sie oben Ihr Nuki-Konto, um alle Schlösser automatisch zu importieren.</p>
</div>
<?php else: ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-8">
  <?php foreach ($locks as $l): ?>
  <div class="card-elev p-5">
    <div class="flex items-start justify-between gap-3 mb-3">
      <div class="flex items-start gap-3 min-w-0 flex-1">
        <div class="w-12 h-12 rounded-xl bg-orange-100 flex items-center justify-center text-2xl flex-shrink-0">🔐</div>
        <div class="min-w-0 flex-1">
          <div class="font-bold text-gray-900 truncate"><?= e($l['device_name'] ?: 'Nuki Smart Lock') ?></div>
          <div class="text-[11px] text-gray-400">ID: <?= e(substr($l['device_id'], 0, 12)) ?>…</div>
        </div>
      </div>
      <span class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-[10px] font-semibold">Aktiv</span>
    </div>

    <?php if ($l['battery_level']): ?>
    <div class="flex items-center gap-1 text-xs text-gray-500 mb-2">
      <svg class="w-4 h-4 <?= $l['battery_level'] < 20 ? 'text-red-500' : 'text-green-500' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 5v14M6 7h11a2 2 0 012 2v6a2 2 0 01-2 2H6M16 11v2"/></svg>
      Batterie <?= $l['battery_level'] ?>%
    </div>
    <?php endif; ?>

    <!-- Service link form -->
    <form method="POST" class="mt-3">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="link_service"/>
      <input type="hidden" name="lock_id" value="<?= $l['lock_id'] ?>"/>
      <label class="block text-[11px] font-semibold text-gray-600 mb-1 uppercase tracking-wider">Verknüpft mit Unterkunft</label>
      <div class="flex gap-2">
        <select name="service_id" onchange="this.form.submit()" class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white">
          <option value="">— Keine Verknüpfung —</option>
          <?php foreach ($services as $s): ?>
          <option value="<?= $s['s_id'] ?>" <?= $l['linked_service_id'] == $s['s_id'] ? 'selected' : '' ?>><?= e($s['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>

    <div class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between gap-2">
      <form method="POST" class="inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="test_unlock"/>
        <input type="hidden" name="lock_id" value="<?= $l['lock_id'] ?>"/>
        <button type="submit" onclick="return confirm('Test: Schloss jetzt öffnen?')" class="px-3 py-1.5 border border-orange-200 bg-orange-50 hover:bg-orange-100 text-orange-700 rounded-lg text-xs font-semibold">🔓 Test-Öffnung</button>
      </form>
      <form method="POST" class="inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="disconnect"/>
        <input type="hidden" name="lock_id" value="<?= $l['lock_id'] ?>"/>
        <button type="submit" onclick="return confirm('Schloss wirklich trennen?')" class="text-xs text-gray-400 hover:text-red-600">Trennen</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Access Log -->
<?php if (!empty($recentEvents)): ?>
<h2 class="text-lg font-bold text-gray-900 mb-3">Letzte Aktivitäten</h2>
<div class="card-elev overflow-hidden">
  <div class="divide-y divide-gray-100 max-h-96 overflow-y-auto">
    <?php foreach ($recentEvents as $e):
      $icon = match($e['action']) {
          'unlock' => '🔓',
          'lock' => '🔒',
          'code_generated' => '🔑',
          'code_revoked' => '❌',
          'sync' => '🔄',
          'failed' => '⚠',
          default => '•',
      };
      $color = $e['result'] === 'success' ? 'text-green-600' : 'text-red-600';
    ?>
    <div class="px-5 py-3 flex items-start gap-3 hover:bg-gray-50">
      <div class="text-xl flex-shrink-0"><?= $icon ?></div>
      <div class="flex-1 min-w-0 text-sm">
        <div class="font-semibold text-gray-900"><?= e($e['device_name'] ?: 'Lock') ?> — <?= e($e['action']) ?></div>
        <div class="text-xs text-gray-500"><?= e($e['triggered_by_name']) ?> (<?= e($e['triggered_by_type']) ?>) · <?= e($e['notes'] ?: '') ?></div>
        <div class="text-[10px] text-gray-400 mt-0.5"><?= date('d.m.Y H:i:s', strtotime($e['happened_at'])) ?></div>
      </div>
      <span class="text-[10px] font-semibold <?= $color ?>"><?= e($e['result']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
