<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
$title = 'Kalender & iCal'; $page = 'calendar';
$cid = me()['id'];
$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);

// Host-only
if (!in_array($customer['customer_type'] ?? '', ['Airbnb', 'Host', 'Booking', 'Short-Term Rental'], true)) {
    header('Location: /customer/'); exit;
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /customer/calendar.php?error=csrf'); exit; }
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $label = trim($_POST['label'] ?? '');
        $url   = trim($_POST['url'] ?? '');
        $platform = trim($_POST['platform'] ?? 'airbnb');
        if ($label !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            try {
                q("INSERT INTO ical_feeds (customer_id_fk, label, url, platform, active) VALUES (?, ?, ?, ?, 1)",
                  [$cid, $label, $url, $platform]);
                audit('create', 'ical_feeds', (int) lastInsertId(), 'Customer added iCal feed (v2)');
            } catch (Exception $e) {
                header('Location: /customer/calendar.php?error=db'); exit;
            }
        }
        header('Location: /customer/calendar.php?added=1'); exit;
    }

    if ($action === 'toggle' && !empty($_POST['id'])) {
        $id = (int) $_POST['id'];
        try {
            $feed = one("SELECT * FROM ical_feeds WHERE id = ? AND customer_id_fk = ?", [$id, $cid]);
            if ($feed) {
                $new = $feed['active'] ? 0 : 1;
                q("UPDATE ical_feeds SET active = ? WHERE id = ? AND customer_id_fk = ?", [$new, $id, $cid]);
                audit('update', 'ical_feeds', $id, "Customer toggled iCal feed active=$new (v2)");
            }
        } catch (Exception $e) { }
        header('Location: /customer/calendar.php'); exit;
    }

    if ($action === 'delete' && !empty($_POST['id'])) {
        $id = (int) $_POST['id'];
        try {
            q("DELETE FROM ical_feeds WHERE id = ? AND customer_id_fk = ?", [$id, $cid]);
            audit('delete', 'ical_feeds', $id, 'Customer deleted iCal feed (v2)');
        } catch (Exception $e) { }
        header('Location: /customer/calendar.php?deleted=1'); exit;
    }
}

$feeds = [];
try {
    $feeds = all("SELECT * FROM ical_feeds WHERE customer_id_fk = ? ORDER BY id DESC", [$cid]);
} catch (Exception $e) { $feeds = []; }

include __DIR__ . '/../includes/layout-customer.php';
?>

<div class="flex items-start justify-between mb-6 flex-wrap gap-4">
  <div>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Kalender & iCal</h1>
    <p class="text-gray-500 mt-1 text-sm">Verbinden Sie Ihre Airbnb- oder Booking.com-Kalender, um Reinigungen automatisch nach jedem Checkout zu planen.</p>
  </div>
</div>

<?php if (!empty($_GET['added']))   echo '<div class="card-elev bg-green-50 border-green-200 p-4 mb-6 text-sm text-green-800">Kalender hinzugefügt.</div>'; ?>
<?php if (!empty($_GET['deleted'])) echo '<div class="card-elev bg-green-50 border-green-200 p-4 mb-6 text-sm text-green-800">Kalender entfernt.</div>'; ?>
<?php if (!empty($_GET['error']))   echo '<div class="card-elev bg-red-50 border-red-200 p-4 mb-6 text-sm text-red-800">Fehler: Bitte prüfen Sie Ihre Eingabe (URL gültig?).</div>'; ?>

<!-- Add new feed form -->
<div class="card-elev p-6 mb-6 max-w-3xl">
  <h2 class="font-bold text-lg mb-4">Neuen Kalender verbinden</h2>
  <form method="POST" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create"/>
    <div class="sm:col-span-3">
      <label class="block text-[10px] font-semibold text-gray-500 mb-1 uppercase tracking-wider">Plattform</label>
      <select name="platform" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none">
        <option value="airbnb">Airbnb</option>
        <option value="booking">Booking.com</option>
        <option value="vrbo">VRBO</option>
        <option value="smoobu">Smoobu</option>
        <option value="google">Google Cal</option>
        <option value="other">Andere</option>
      </select>
    </div>
    <div class="sm:col-span-3">
      <label class="block text-[10px] font-semibold text-gray-500 mb-1 uppercase tracking-wider">Name / Label</label>
      <input type="text" name="label" required placeholder="z.B. Apt Mitte – Airbnb" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
    </div>
    <div class="sm:col-span-4">
      <label class="block text-[10px] font-semibold text-gray-500 mb-1 uppercase tracking-wider">iCal-URL</label>
      <input type="url" name="url" required placeholder="https://…" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
    </div>
    <div class="sm:col-span-2 flex items-end">
      <button type="submit" class="w-full px-4 py-2.5 bg-brand hover:bg-brand-dark text-white rounded-lg font-semibold text-sm">Verbinden</button>
    </div>
  </form>
  <p class="text-[11px] text-gray-400 mt-3">
    In Airbnb: Kalender → Kalender verfügbar machen → „iCal exportieren" → URL kopieren und hier einfügen.
  </p>
</div>

<!-- Connected feeds list -->
<?php if (empty($feeds)): ?>
<div class="card-elev text-center py-16 px-4">
  <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-brand-light mb-5">
    <svg class="w-10 h-10 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
  </div>
  <h3 class="text-lg font-semibold text-gray-900 mb-2">Keine Kalender verbunden</h3>
  <p class="text-sm text-gray-500 max-w-md mx-auto">Verbinden Sie Ihre Airbnb-, Booking.com- oder VRBO-iCal URLs mit dem Formular oben.</p>
</div>
<?php else: ?>
<h2 class="font-bold text-lg mb-4">Verbundene Kalender (<?= count($feeds) ?>)</h2>
<div class="grid gap-3">
<?php foreach ($feeds as $f):
    $platform = strtolower($f['platform'] ?? '');
    $emoji = match ($platform) {
        'airbnb' => '🅰️',
        'booking', 'booking.com' => '🏨',
        'vrbo' => '🏡',
        'smoobu' => '📅',
        'google' => '📆',
        default => '🌐',
    };
?>
<div class="card-elev p-5 flex items-center justify-between gap-4 flex-wrap">
  <div class="flex items-center gap-4 min-w-0 flex-1">
    <div class="w-12 h-12 rounded-xl bg-brand-light flex items-center justify-center flex-shrink-0 text-2xl"><?= $emoji ?></div>
    <div class="min-w-0 flex-1">
      <div class="flex items-center gap-2">
        <div class="font-bold text-gray-900 truncate"><?= e($f['label']) ?></div>
        <?php if ($f['active']): ?>
          <span class="inline-block px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-[10px] font-semibold">Aktiv</span>
        <?php else: ?>
          <span class="inline-block px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 text-[10px] font-semibold">Pausiert</span>
        <?php endif; ?>
      </div>
      <div class="text-xs text-gray-500 font-mono truncate mt-0.5" title="<?= e($f['url']) ?>"><?= e(substr($f['url'], 0, 70)) ?><?= strlen($f['url']) > 70 ? '…' : '' ?></div>
      <div class="text-[11px] text-gray-400 mt-1">
        <?= ucfirst($f['platform'] ?? 'other') ?>
        <?php if (!empty($f['last_sync'])): ?> · Letzter Sync: <?= date('d.m. H:i', strtotime($f['last_sync'])) ?><?php endif; ?>
        <?php if (!empty($f['jobs_created'])): ?> · <?= (int) $f['jobs_created'] ?> Jobs erstellt<?php endif; ?>
      </div>
    </div>
  </div>
  <div class="flex gap-2 flex-shrink-0">
    <form method="POST" class="inline">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="toggle"/>
      <input type="hidden" name="id" value="<?= (int) $f['id'] ?>"/>
      <button type="submit" class="px-3 py-1.5 border border-gray-200 hover:bg-gray-50 rounded-lg text-xs font-semibold"><?= $f['active'] ? 'Pausieren' : 'Aktivieren' ?></button>
    </form>
    <form method="POST" onsubmit="return confirm('Kalender wirklich entfernen?')" class="inline">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="delete"/>
      <input type="hidden" name="id" value="<?= (int) $f['id'] ?>"/>
      <button type="submit" class="px-3 py-1.5 border border-red-200 text-red-600 hover:bg-red-50 rounded-lg text-xs font-semibold">Entfernen</button>
    </form>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
