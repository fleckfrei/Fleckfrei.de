<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
$title = 'Meine Services'; $page = 'services';
$cid = me()['id'];
$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);

// Host-only page
if (!in_array($customer['customer_type'] ?? '', ['Airbnb', 'Host', 'Booking', 'Short-Term Rental'], true)) {
    header('Location: /customer/'); exit;
}

// POST: create / update (NO delete — customer can't delete services)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /customer/services.php?error=csrf'); exit; }
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $title_  = trim($_POST['title'] ?? '');
        $price   = (float) str_replace(',', '.', $_POST['total_price'] ?? 0);
        $street  = trim($_POST['street'] ?? '');
        $number  = trim($_POST['number'] ?? '');
        $postal  = trim($_POST['postal_code'] ?? '');
        $city    = trim($_POST['city'] ?? '');
        $boxCode = trim($_POST['box_code'] ?? '');
        $bellName = trim($_POST['doorbell_name'] ?? '');
        $qm      = trim($_POST['qm'] ?? '');
        $room    = trim($_POST['room'] ?? '');
        $maxGuests = (int)($_POST['max_guests'] ?? 0);
        if ($maxGuests < 1) $maxGuests = null;

        if ($action === 'create' && $title_ !== '') {
            q("INSERT INTO services (title, unit, price, tax, tax_percentage, total_price, is_address, street, number, postal_code, city, country, box_code, doorbell_name, qm, room, max_guests, customer_id_fk, status)
               VALUES (?, 'Stunde', ?, 0, 0, ?, 1, ?, ?, ?, ?, 'Deutschland', ?, ?, ?, ?, ?, ?, 1)",
              [$title_, $price, $price, $street, $number, $postal, $city, $boxCode, $bellName, $qm, $room, $maxGuests, $cid]);
            audit('create', 'services', (int) lastInsertId(), 'Customer created service');
            header('Location: /customer/services.php?created=1'); exit;
        }

        if ($action === 'update' && !empty($_POST['s_id'])) {
            $sid = (int) $_POST['s_id'];
            $own = one("SELECT s_id FROM services WHERE s_id = ? AND customer_id_fk = ?", [$sid, $cid]);
            if ($own) {
                q("UPDATE services SET title=?, price=?, total_price=?, street=?, number=?, postal_code=?, city=?, box_code=?, doorbell_name=?, qm=?, room=?, max_guests=? WHERE s_id=? AND customer_id_fk=?",
                  [$title_, $price, $price, $street, $number, $postal, $city, $boxCode, $bellName, $qm, $room, $maxGuests, $sid, $cid]);
                audit('update', 'services', $sid, 'Customer updated service');
            }
            header('Location: /customer/services.php?saved=1'); exit;
        }
    }
}

$services = all("SELECT * FROM services WHERE customer_id_fk = ? AND status = 1 ORDER BY title", [$cid]);
$editId = (int) ($_GET['edit'] ?? 0);
$editService = $editId ? one("SELECT * FROM services WHERE s_id = ? AND customer_id_fk = ?", [$editId, $cid]) : null;
$showForm = !empty($_GET['new']) || $editService;

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Back button -->
<div class="mb-4">
  <a href="<?= $showForm ? '/customer/services.php' : '/customer/' ?>" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

<div class="flex items-start justify-between mb-6 flex-wrap gap-4">
  <div>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Ihre Unterkünfte</h1>
    <p class="text-gray-500 mt-1 text-sm">Alle Adressen und Pauschalen für regelmäßige Reinigungen.</p>
  </div>
  <?php if (!$showForm): ?>
  <a href="?new=1" class="inline-flex items-center gap-2 px-5 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-semibold text-sm shadow-lg shadow-brand/20 transition">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
    Neue Unterkunft
  </a>
  <?php endif; ?>
</div>

<?php
if (!empty($_GET['created'])) echo '<div class="card-elev bg-green-50 border-green-200 p-4 mb-6 text-sm text-green-800">✓ Unterkunft angelegt.</div>';
if (!empty($_GET['saved']))   echo '<div class="card-elev bg-green-50 border-green-200 p-4 mb-6 text-sm text-green-800">✓ Änderungen gespeichert.</div>';
?>

<?php if ($showForm): ?>
<!-- ============ FORM ============ -->
<div class="card-elev p-6 sm:p-8 max-w-3xl" x-data="{ validating: false, validationResult: null }">
  <h2 class="font-bold text-lg mb-1"><?= $editService ? 'Unterkunft bearbeiten' : 'Neue Unterkunft' ?></h2>
  <p class="text-xs text-gray-500 mb-6">Alle Angaben werden verschlüsselt gespeichert und sind nur für Sie und Ihre Partner sichtbar.</p>

  <form method="POST" class="space-y-5">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="<?= $editService ? 'update' : 'create' ?>"/>
    <?php if ($editService): ?><input type="hidden" name="s_id" value="<?= (int) $editService['s_id'] ?>"/><?php endif; ?>

    <!-- Bezeichnung -->
    <div>
      <label class="block text-xs font-bold text-gray-600 mb-1.5 uppercase tracking-wider">Bezeichnung</label>
      <input type="text" name="title" required value="<?= e($editService['title'] ?? '') ?>" placeholder="z.B. Apartment Pasteurstr 17" class="w-full px-4 py-3 border-2 border-gray-100 bg-gray-50 rounded-2xl focus:ring-4 focus:ring-brand/10 focus:border-brand focus:bg-white outline-none transition"/>
    </div>

    <!-- Address section -->
    <div class="border-t border-gray-100 pt-5">
      <h3 class="text-xs font-bold text-gray-600 uppercase tracking-wider mb-3">📍 Adresse</h3>
      <div class="grid grid-cols-1 sm:grid-cols-6 gap-3">
        <div class="sm:col-span-4">
          <label class="block text-[11px] font-semibold text-gray-500 mb-1">Straße</label>
          <input type="text" name="street" required value="<?= e($editService['street'] ?? '') ?>" placeholder="Pasteurstraße" class="w-full px-3 py-2.5 border border-gray-200 bg-gray-50 rounded-xl focus:bg-white focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"/>
        </div>
        <div class="sm:col-span-2">
          <label class="block text-[11px] font-semibold text-gray-500 mb-1">Hausnummer</label>
          <input type="text" name="number" value="<?= e($editService['number'] ?? '') ?>" placeholder="17" class="w-full px-3 py-2.5 border border-gray-200 bg-gray-50 rounded-xl focus:bg-white focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"/>
        </div>
        <div class="sm:col-span-2">
          <label class="block text-[11px] font-semibold text-gray-500 mb-1">PLZ</label>
          <input type="text" name="postal_code" value="<?= e($editService['postal_code'] ?? '') ?>" placeholder="10407" class="w-full px-3 py-2.5 border border-gray-200 bg-gray-50 rounded-xl focus:bg-white focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"/>
        </div>
        <div class="sm:col-span-4">
          <label class="block text-[11px] font-semibold text-gray-500 mb-1">Stadt</label>
          <input type="text" name="city" required value="<?= e($editService['city'] ?? 'Berlin') ?>" class="w-full px-3 py-2.5 border border-gray-200 bg-gray-50 rounded-xl focus:bg-white focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"/>
        </div>
      </div>
      <p class="text-[11px] text-gray-400 mt-2">🔎 Adressen werden bei Speicherung automatisch geprüft (Google Maps API).</p>
    </div>

    <!-- Zugang section -->
    <div class="border-t border-gray-100 pt-5">
      <h3 class="text-xs font-bold text-gray-600 uppercase tracking-wider mb-3">🔑 Zugang</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-[11px] font-semibold text-gray-500 mb-1">Klingelname</label>
          <input type="text" name="doorbell_name" value="<?= e($editService['doorbell_name'] ?? '') ?>" placeholder="z.B. Schmidt · 3. OG links" class="w-full px-3 py-2.5 border border-gray-200 bg-gray-50 rounded-xl focus:bg-white focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"/>
          <p class="text-[10px] text-gray-400 mt-1">So findet der Partner die richtige Klingel.</p>
        </div>
        <div>
          <label class="block text-[11px] font-semibold text-gray-500 mb-1">Schlüsselbox / Türcode</label>
          <input type="text" name="box_code" value="<?= e($editService['box_code'] ?? '') ?>" placeholder="z.B. 1234" class="w-full px-3 py-2.5 border border-gray-200 bg-gray-50 rounded-xl focus:bg-white focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"/>
          <p class="text-[10px] text-gray-400 mt-1">Wird nur dem zugewiesenen Partner angezeigt.</p>
        </div>
      </div>
    </div>

    <!-- Details section -->
    <div class="border-t border-gray-100 pt-5">
      <h3 class="text-xs font-bold text-gray-600 uppercase tracking-wider mb-3">🏠 Details</h3>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div>
          <label class="block text-[11px] font-semibold text-gray-500 mb-1">Preis/h</label>
          <div class="relative">
            <input type="number" step="0.01" name="total_price" value="<?= e($editService['total_price'] ?? '') ?>" class="w-full px-3 py-2.5 pr-8 border border-gray-200 bg-gray-50 rounded-xl focus:bg-white focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"/>
            <span class="absolute right-3 top-2.5 text-gray-400 text-sm">€</span>
          </div>
        </div>
        <div>
          <label class="block text-[11px] font-semibold text-gray-500 mb-1">Größe</label>
          <div class="relative">
            <input type="text" name="qm" value="<?= e($editService['qm'] ?? '') ?>" class="w-full px-3 py-2.5 pr-10 border border-gray-200 bg-gray-50 rounded-xl focus:bg-white focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"/>
            <span class="absolute right-3 top-2.5 text-gray-400 text-xs">m²</span>
          </div>
        </div>
        <div>
          <label class="block text-[11px] font-semibold text-gray-500 mb-1">Zimmer</label>
          <input type="text" name="room" value="<?= e($editService['room'] ?? '') ?>" class="w-full px-3 py-2.5 border border-gray-200 bg-gray-50 rounded-xl focus:bg-white focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"/>
        </div>
        <div>
          <label class="block text-[11px] font-semibold text-gray-500 mb-1">👥 Max. Gäste</label>
          <input type="number" name="max_guests" min="1" max="20" value="<?= e($editService['max_guests'] ?? '') ?>" placeholder="z.B. 4" class="w-full px-3 py-2.5 border border-gray-200 bg-gray-50 rounded-xl focus:bg-white focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"/>
          <p class="text-[10px] text-gray-400 mt-0.5">Max. Belegung</p>
        </div>
      </div>
    </div>

    <div class="flex gap-3 pt-4 border-t border-gray-100">
      <button type="submit" class="px-6 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-bold text-sm shadow-lg shadow-brand/20">Speichern</button>
      <a href="/customer/services.php" class="px-6 py-3 border border-gray-200 hover:bg-gray-50 rounded-xl font-semibold text-sm text-gray-700">Abbrechen</a>
    </div>
  </form>
</div>

<?php elseif (empty($services)): ?>
<div class="card-elev text-center py-16 px-4">
  <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-brand-light mb-5">
    <svg class="w-10 h-10 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
  </div>
  <h3 class="text-lg font-semibold text-gray-900 mb-2">Noch keine Unterkünfte</h3>
  <p class="text-sm text-gray-500 max-w-md mx-auto mb-6">Legen Sie Ihre Unterkünfte an, damit Ihre Partner immer wissen wohin und was zu tun ist.</p>
  <a href="?new=1" class="inline-flex items-center gap-2 px-6 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-semibold text-sm shadow-lg shadow-brand/20">Erste Unterkunft anlegen</a>
</div>

<?php else: ?>
<div class="grid gap-3">
<?php foreach ($services as $s): ?>
<a href="?edit=<?= (int) $s['s_id'] ?>" class="block card-elev p-5 hover:border-brand hover:shadow-md transition cursor-pointer">
  <div class="flex items-start justify-between gap-4">
    <div class="flex items-start gap-4 min-w-0 flex-1">
      <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-brand-light to-brand/20 flex items-center justify-center flex-shrink-0">
        <svg class="w-6 h-6 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
      </div>
      <div class="min-w-0 flex-1">
        <div class="font-bold text-gray-900 text-base"><?= e($s['title']) ?></div>
        <div class="text-xs text-gray-500 mt-0.5 flex items-center gap-1">
          <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
          <?= e(trim(($s['street'] ?? '') . ' ' . ($s['number'] ?? ''))) ?>
          <?php if (!empty($s['postal_code']) || !empty($s['city'])): ?>
            , <?= e(trim(($s['postal_code'] ?? '') . ' ' . ($s['city'] ?? ''))) ?>
          <?php endif; ?>
        </div>
        <div class="flex flex-wrap items-center gap-3 text-[11px] text-gray-500 mt-2">
          <?php if (!empty($s['qm'])): ?><span class="flex items-center gap-1">📐 <?= e($s['qm']) ?> m²</span><?php endif; ?>
          <?php if (!empty($s['room'])): ?><span class="flex items-center gap-1">🛏 <?= e($s['room']) ?></span><?php endif; ?>
          <?php if (!empty($s['max_guests'])): ?><span class="flex items-center gap-1">👥 max. <?= e($s['max_guests']) ?></span><?php endif; ?>
          <?php if (!empty($s['doorbell_name'])): ?><span class="flex items-center gap-1">🔔 <?= e($s['doorbell_name']) ?></span><?php endif; ?>
          <?php if (!empty($s['box_code'])): ?><span class="flex items-center gap-1">🔑 Box</span><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="flex flex-col items-end gap-2 flex-shrink-0">
      <div class="text-right">
        <div class="font-bold text-gray-900 text-lg"><?= money($s['total_price'] ?? 0) ?></div>
        <div class="text-[11px] text-gray-400">pro Stunde</div>
      </div>
      <svg class="w-5 h-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </div>
  </div>
</a>
<?php endforeach; ?>
</div>

<!-- Info: no delete -->
<div class="mt-6 card-elev bg-blue-50 border-blue-200 p-4 text-xs text-blue-800 flex items-start gap-2 max-w-2xl">
  <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
  <span><strong>Hinweis:</strong> Unterkünfte können nicht selbst gelöscht werden, um die Historie Ihrer Rechnungen zu erhalten. Wenden Sie sich per <a href="<?= CONTACT_WHATSAPP_URL ?>" target="_blank" class="font-semibold underline">WhatsApp</a> an uns, wenn Sie eine Unterkunft archivieren möchten.</span>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
