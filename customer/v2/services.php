<?php
require_once __DIR__ . '/../../includes/auth.php';
requireCustomer();
$title = 'Meine Services'; $page = 'services';
$cid = me()['id'];
$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);

// Host-only page
if (!in_array($customer['customer_type'] ?? '', ['Airbnb', 'Host', 'Booking', 'Short-Term Rental'], true)) {
    header('Location: /customer/v2/'); exit;
}

// POST: create / update / delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /customer/v2/services.php?error=csrf'); exit; }
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $title_ = trim($_POST['title'] ?? '');
        $price = (float) str_replace(',', '.', $_POST['total_price'] ?? 0);
        $street = trim($_POST['street'] ?? '');
        $number = trim($_POST['number'] ?? '');
        $postal = trim($_POST['postal_code'] ?? '');
        $city   = trim($_POST['city'] ?? '');
        $boxCode = trim($_POST['box_code'] ?? '');
        $qm = trim($_POST['qm'] ?? '');
        $room = trim($_POST['room'] ?? '');

        if ($action === 'create' && $title_ !== '') {
            q("INSERT INTO services (title, unit, price, tax, tax_percentage, total_price, is_address, street, number, postal_code, city, country, box_code, qm, room, customer_id_fk, status)
               VALUES (?, 'Stunde', ?, 0, 0, ?, 1, ?, ?, ?, ?, 'Deutschland', ?, ?, ?, ?, 1)",
              [$title_, $price, $price, $street, $number, $postal, $city, $boxCode, $qm, $room, $cid]);
            audit('create', 'services', (int) lastInsertId(), 'Customer created service (v2)');
            header('Location: /customer/v2/services.php?created=1'); exit;
        }

        if ($action === 'update' && !empty($_POST['s_id'])) {
            $sid = (int) $_POST['s_id'];
            // Verify ownership before update
            $own = one("SELECT s_id FROM services WHERE s_id = ? AND customer_id_fk = ?", [$sid, $cid]);
            if ($own) {
                q("UPDATE services SET title=?, price=?, total_price=?, street=?, number=?, postal_code=?, city=?, box_code=?, qm=?, room=? WHERE s_id=? AND customer_id_fk=?",
                  [$title_, $price, $price, $street, $number, $postal, $city, $boxCode, $qm, $room, $sid, $cid]);
                audit('update', 'services', $sid, 'Customer updated service (v2)');
            }
            header('Location: /customer/v2/services.php?saved=1'); exit;
        }
    }

    if ($action === 'delete' && !empty($_POST['s_id'])) {
        $sid = (int) $_POST['s_id'];
        $own = one("SELECT s_id FROM services WHERE s_id = ? AND customer_id_fk = ?", [$sid, $cid]);
        if ($own) {
            q("UPDATE services SET status = 0 WHERE s_id = ? AND customer_id_fk = ?", [$sid, $cid]);
            audit('delete', 'services', $sid, 'Customer soft-deleted service (v2)');
        }
        header('Location: /customer/v2/services.php?deleted=1'); exit;
    }
}

$services = all("SELECT * FROM services WHERE customer_id_fk = ? AND status = 1 ORDER BY title", [$cid]);
$editId = (int) ($_GET['edit'] ?? 0);
$editService = $editId ? one("SELECT * FROM services WHERE s_id = ? AND customer_id_fk = ?", [$editId, $cid]) : null;
$showForm = !empty($_GET['new']) || $editService;

include __DIR__ . '/../../includes/layout-v2.php';
?>

<div class="flex items-start justify-between mb-6 flex-wrap gap-4">
  <div>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Meine Services</h1>
    <p class="text-gray-500 mt-1 text-sm">Ihre Unterkünfte mit Adressen und Pauschalen für regelmäßige Reinigungen.</p>
  </div>
  <?php if (!$showForm): ?>
  <a href="?new=1" class="inline-flex items-center gap-2 px-5 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-semibold text-sm shadow-sm transition">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
    Neuer Service
  </a>
  <?php endif; ?>
</div>

<?php
if (!empty($_GET['created'])) echo '<div class="card-elev bg-green-50 border-green-200 p-4 mb-6 text-sm text-green-800">Service angelegt.</div>';
if (!empty($_GET['saved']))   echo '<div class="card-elev bg-green-50 border-green-200 p-4 mb-6 text-sm text-green-800">Änderungen gespeichert.</div>';
if (!empty($_GET['deleted'])) echo '<div class="card-elev bg-green-50 border-green-200 p-4 mb-6 text-sm text-green-800">Service entfernt.</div>';
?>

<?php if ($showForm): ?>
<!-- CREATE / EDIT FORM -->
<div class="card-elev p-6 max-w-3xl">
  <h2 class="font-bold text-lg mb-5"><?= $editService ? 'Service bearbeiten' : 'Neuer Service' ?></h2>
  <form method="POST" class="space-y-4">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="<?= $editService ? 'update' : 'create' ?>"/>
    <?php if ($editService): ?><input type="hidden" name="s_id" value="<?= (int) $editService['s_id'] ?>"/><?php endif; ?>

    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Bezeichnung (z.B. „Apartment Mitte")</label>
      <input type="text" name="title" required value="<?= e($editService['title'] ?? '') ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div class="sm:col-span-2">
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Straße</label>
        <input type="text" name="street" value="<?= e($editService['street'] ?? '') ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Nr.</label>
        <input type="text" name="number" value="<?= e($editService['number'] ?? '') ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">PLZ</label>
        <input type="text" name="postal_code" value="<?= e($editService['postal_code'] ?? '') ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
      </div>
      <div class="sm:col-span-2">
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Stadt</label>
        <input type="text" name="city" value="<?= e($editService['city'] ?? '') ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
      </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Preis/h (€)</label>
        <input type="number" step="0.01" name="total_price" value="<?= e($editService['total_price'] ?? '') ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Größe (m²)</label>
        <input type="text" name="qm" value="<?= e($editService['qm'] ?? '') ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Zimmer</label>
        <input type="text" name="room" value="<?= e($editService['room'] ?? '') ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
      </div>
    </div>

    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Schlüsselbox / Türcode</label>
      <input type="text" name="box_code" value="<?= e($editService['box_code'] ?? '') ?>" placeholder="z.B. Schlüsselbox 1234" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
    </div>

    <div class="flex gap-3 pt-2">
      <button type="submit" class="px-6 py-3 bg-brand hover:bg-brand-dark text-white rounded-lg font-semibold text-sm">Speichern</button>
      <a href="/customer/v2/services.php" class="px-6 py-3 border border-gray-200 hover:bg-gray-50 rounded-lg font-semibold text-sm">Abbrechen</a>
    </div>
  </form>
</div>

<?php elseif (empty($services)): ?>
<div class="card-elev text-center py-16 px-4">
  <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-brand-light mb-5">
    <svg class="w-10 h-10 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
  </div>
  <h3 class="text-lg font-semibold text-gray-900 mb-2">Keine Services angelegt</h3>
  <p class="text-sm text-gray-500 max-w-md mx-auto mb-6">Legen Sie Ihre Unterkünfte an, um regelmäßige Reinigungen nach jedem Gäste-Checkout zu buchen.</p>
  <a href="?new=1" class="inline-flex items-center gap-2 px-6 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-semibold text-sm">Ersten Service anlegen</a>
</div>

<?php else: ?>
<div class="grid gap-3">
<?php foreach ($services as $s): ?>
<div class="card-elev p-5 flex items-start justify-between gap-4">
  <div class="flex items-start gap-4 min-w-0 flex-1">
    <div class="w-12 h-12 rounded-xl bg-brand-light flex items-center justify-center flex-shrink-0">
      <svg class="w-6 h-6 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
    </div>
    <div class="min-w-0 flex-1">
      <div class="font-bold text-gray-900"><?= e($s['title']) ?></div>
      <div class="text-xs text-gray-500 mt-0.5">
        <?= e(trim(($s['street'] ?? '') . ' ' . ($s['number'] ?? ''))) ?>
        <?php if (!empty($s['postal_code']) || !empty($s['city'])): ?>
          , <?= e(trim(($s['postal_code'] ?? '') . ' ' . ($s['city'] ?? ''))) ?>
        <?php endif; ?>
      </div>
      <div class="flex flex-wrap gap-3 text-[11px] text-gray-500 mt-2">
        <?php if (!empty($s['qm'])): ?><span>📐 <?= e($s['qm']) ?> m²</span><?php endif; ?>
        <?php if (!empty($s['room'])): ?><span>🛏 <?= e($s['room']) ?></span><?php endif; ?>
        <?php if (!empty($s['box_code'])): ?><span>🔑 <?= e($s['box_code']) ?></span><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="flex flex-col items-end gap-2">
    <div class="text-right">
      <div class="font-bold text-gray-900"><?= money($s['total_price'] ?? 0) ?></div>
      <div class="text-[11px] text-gray-400">pro Stunde</div>
    </div>
    <div class="flex gap-2">
      <a href="?edit=<?= (int) $s['s_id'] ?>" class="px-3 py-1.5 border border-gray-200 hover:bg-gray-50 rounded-lg text-xs font-semibold">Bearbeiten</a>
      <form method="POST" onsubmit="return confirm('Service wirklich entfernen?')" class="inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete"/>
        <input type="hidden" name="s_id" value="<?= (int) $s['s_id'] ?>"/>
        <button type="submit" class="px-3 py-1.5 border border-red-200 text-red-600 hover:bg-red-50 rounded-lg text-xs font-semibold">Entfernen</button>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer-v2.php'; ?>
