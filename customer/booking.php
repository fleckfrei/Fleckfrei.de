<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
if (!customerCan('booking')) { header('Location: /customer/'); exit; }
$title = 'Neue Buchung'; $page = 'booking';
$cid = me()['id'];
$user = me();

$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);
try { $addresses = all("SELECT * FROM customer_address WHERE customer_id_fk=? ORDER BY ca_id DESC", [$cid]); } catch (Exception $e) { $addresses = []; }
$services = all("SELECT s_id, title, total_price FROM services WHERE customer_id_fk=? AND status=1 ORDER BY title", [$cid]);
// Fallback: show general services if customer has none
if (empty($services)) {
    $services = all("SELECT s_id, title, total_price FROM services WHERE status=1 AND title LIKE '%Fleckfrei%' ORDER BY title LIMIT 10");
}

include __DIR__ . '/../includes/layout.php';
?>

<div class="max-w-2xl" x-data="{ submitted:false, loading:false, result:null, error:null }">
  <div class="bg-white rounded-xl border p-6" x-show="!submitted">
    <h2 class="text-lg font-semibold mb-6"><?= t('booking.title') ?></h2>
    <form @submit.prevent="
      loading=true; error=null;
      const fd = new FormData($event.target);
      const data = Object.fromEntries(fd);
      data.name = '<?= e($customer['name']) ?>';
      data.email = '<?= e($customer['email']) ?>';
      data.phone = '<?= e($customer['phone']) ?>';
      data.platform = 'customer_portal';
      fetch('/api/index.php?action=webhook/booking', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-API-Key':'<?= API_KEY ?>'},
        body:JSON.stringify(data)
      }).then(r=>r.json()).then(d=>{
        loading=false;
        if(d.success){result=d.data;submitted=true}
        else{error=d.error||'Fehler'}
      }).catch(()=>{loading=false;error='Netzwerk-Fehler'})
    " class="space-y-5">

      <div>
        <label class="block text-sm font-medium text-gray-600 mb-1">Service</label>
        <select name="service" required class="w-full px-3 py-2.5 border rounded-xl">
          <option value="">Bitte wählen...</option>
          <?php foreach ($services as $s): ?>
          <option value="<?= $s['s_id'] ?>"><?= e($s['title']) ?> <?= $s['total_price'] ? '— '.money($s['total_price']).'/h' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-600 mb-1">Datum</label>
          <input type="date" name="date" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>" class="w-full px-3 py-2.5 border rounded-xl"/>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-600 mb-1">Uhrzeit</label>
          <select name="time" required class="w-full px-3 py-2.5 border rounded-xl">
            <?php for ($h = 7; $h <= 19; $h++): ?>
            <option value="<?= sprintf('%02d:00', $h) ?>"><?= sprintf('%02d:00', $h) ?></option>
            <option value="<?= sprintf('%02d:30', $h) ?>"><?= sprintf('%02d:30', $h) ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-600 mb-1">Stunden</label>
          <select name="hours" required class="w-full px-3 py-2.5 border rounded-xl">
            <option value="2">2 Stunden</option>
            <option value="3" selected>3 Stunden</option>
            <option value="4">4 Stunden</option>
            <option value="5">5 Stunden</option>
            <option value="6">6 Stunden</option>
            <option value="8">8 Stunden (Ganztag)</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-600 mb-1">Häufigkeit</label>
          <select name="frequency" class="w-full px-3 py-2.5 border rounded-xl">
            <option value="einmalig">Einmalig</option>
            <option value="woechentlich">Wöchentlich</option>
            <option value="2wochen">Alle 2 Wochen</option>
            <option value="monatlich">Monatlich</option>
          </select>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-600 mb-1">Adresse</label>
        <?php if (!empty($addresses)): ?>
        <select name="address" required class="w-full px-3 py-2.5 border rounded-xl">
          <?php foreach ($addresses as $a): ?>
          <option value="<?= e($a['street'].' '.$a['number'].', '.$a['postal_code'].' '.$a['city']) ?>"><?= e($a['street'].' '.$a['number'].', '.$a['postal_code'].' '.$a['city']) ?></option>
          <?php endforeach; ?>
          <option value="">Andere Adresse eingeben...</option>
        </select>
        <?php else: ?>
        <input type="text" name="address" required placeholder="Straße Nr., PLZ Stadt" class="w-full px-3 py-2.5 border rounded-xl"/>
        <?php endif; ?>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-600 mb-1">Türcode / Zugang (optional)</label>
        <input type="text" name="door_code" placeholder="z.B. Schlüsselbox: 1234" class="w-full px-3 py-2.5 border rounded-xl"/>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-600 mb-1">Anmerkungen (optional)</label>
        <textarea name="notes" rows="3" placeholder="Besondere Wünsche, Hinweise..." class="w-full px-3 py-2.5 border rounded-xl"></textarea>
      </div>

      <div x-show="error" class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl text-sm" x-text="error"></div>

      <button type="submit" :disabled="loading" class="w-full px-4 py-3 bg-brand text-white rounded-xl font-semibold text-lg hover:bg-brand/90 transition" x-text="loading ? 'Wird gesendet...' : 'Termin buchen'">Termin buchen</button>
    </form>
  </div>

  <!-- Success -->
  <div x-show="submitted" class="bg-white rounded-xl border p-8 text-center">
    <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-4">
      <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
    </div>
    <h2 class="text-xl font-bold text-gray-900 mb-2">Buchung eingegangen!</h2>
    <p class="text-gray-500 mb-2">Ihre Buchungsnummer: <strong x-text="result?.booking_id" class="text-brand"></strong></p>
    <p class="text-sm text-gray-400 mb-6">Wir bestätigen Ihren Termin in Kürze per E-Mail.</p>
    <div class="flex gap-3 justify-center">
      <a href="/customer/jobs.php" class="px-6 py-2.5 bg-brand text-white rounded-xl font-medium">Meine Jobs</a>
      <button @click="submitted=false;result=null" class="px-6 py-2.5 border rounded-xl">Noch eine Buchung</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
