<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
if (!customerCan('booking')) { header('Location: /customer/'); exit; }
$title = 'Neue Buchung'; $page = 'booking';
$cid = me()['id'];
$user = me();

$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);
$isAirbnb = in_array($customer['customer_type'] ?? '', ['Airbnb', 'Booking', 'Short-Term Rental', 'Company']);
try { $addresses = all("SELECT * FROM customer_address WHERE customer_id_fk=? ORDER BY ca_id DESC", [$cid]); } catch (Exception $e) { $addresses = []; }
// Show customer's own services first, then shared/general services (customer_id_fk IS NULL)
$services = all("SELECT s_id, title, total_price FROM services WHERE customer_id_fk=? AND status=1 ORDER BY title", [$cid]);
if (empty($services)) {
    $services = all("SELECT s_id, title, total_price FROM services WHERE (customer_id_fk IS NULL OR customer_id_fk=0) AND status=1 ORDER BY title");
}

include __DIR__ . '/../includes/layout.php';
?>

<div class="max-w-2xl" x-data="{ submitted:false, loading:false, result:null, error:null }">
  <div class="bg-white rounded-xl border p-6" x-show="!submitted">
    <h2 class="text-lg font-semibold mb-1"><?= t('booking.title') ?></h2>
    <?php if ($isAirbnb): ?>
    <p class="text-sm text-gray-400 mb-6">Turnover-Service für Ihre Unterkunft</p>
    <?php else: ?>
    <p class="text-sm text-gray-400 mb-6">Neuen Termin buchen</p>
    <?php endif; ?>

    <form @submit.prevent="
      loading=true; error=null;
      const fd = new FormData($event.target);
      const data = Object.fromEntries(fd);
      // Collect optional_products checkboxes
      data.optional_products = Array.from($event.target.querySelectorAll('input[name=optional_products]:checked')).map(c=>c.value).join(', ');
      data.name = '<?= e($customer['name']) ?>';
      data.email = '<?= e($customer['email']) ?>';
      data.phone = '<?= e($customer['phone']) ?>';
      data.type = '<?= e($customer['customer_type']) ?>';
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
          <input type="date" name="date" required min="<?= date('Y-m-d') ?>" class="w-full px-3 py-2.5 border rounded-xl"/>
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

      <?php if ($isAirbnb): ?>
      <!-- ===== AIRBNB / HOST SPECIFIC FIELDS ===== -->
      <div class="border-t pt-5">
        <h3 class="text-sm font-semibold text-gray-700 mb-4 flex items-center gap-2">
          <svg class="w-4 h-4 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
          Gast-Informationen
        </h3>

        <div class="grid grid-cols-2 gap-4 mb-4">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Gast-Name</label>
            <input type="text" name="guest_name" placeholder="Name des Gastes" class="w-full px-3 py-2.5 border rounded-xl"/>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Gast-Telefon</label>
            <input type="tel" name="guest_phone" placeholder="+49..." class="w-full px-3 py-2.5 border rounded-xl"/>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-4">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Check-out Datum</label>
            <input type="date" name="guest_checkout_date" class="w-full px-3 py-2.5 border rounded-xl"/>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Check-out Uhrzeit</label>
            <select name="guest_checkout_time" class="w-full px-3 py-2.5 border rounded-xl">
              <option value="">--</option>
              <?php for ($h = 8; $h <= 14; $h++): ?>
              <option value="<?= sprintf('%02d:00', $h) ?>"><?= sprintf('%02d:00', $h) ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-4">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Check-in Datum</label>
            <input type="date" name="check_in_date" class="w-full px-3 py-2.5 border rounded-xl"/>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Check-in Uhrzeit</label>
            <select name="check_in_time" class="w-full px-3 py-2.5 border rounded-xl">
              <option value="">--</option>
              <?php for ($h = 14; $h <= 20; $h++): ?>
              <option value="<?= sprintf('%02d:00', $h) ?>"><?= sprintf('%02d:00', $h) ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-600 mb-1">Plattform</label>
          <select name="booking_platform" class="w-full px-3 py-2.5 border rounded-xl">
            <option value="airbnb">Airbnb</option>
            <option value="booking">Booking.com</option>
            <option value="vrbo">VRBO</option>
            <option value="direct">Direktbuchung</option>
            <option value="other">Andere</option>
          </select>
        </div>
      </div>

      <!-- Optional Products -->
      <div class="border-t pt-5">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">Zusatzleistungen</h3>
        <div class="space-y-2">
          <label class="flex items-center gap-3 p-3 border rounded-xl hover:bg-gray-50 cursor-pointer">
            <input type="checkbox" name="optional_products" value="Reinigungsmittel" class="w-4 h-4 rounded text-brand"/>
            <span class="text-sm">Reinigungsmittel</span>
          </label>
          <label class="flex items-center gap-3 p-3 border rounded-xl hover:bg-gray-50 cursor-pointer">
            <input type="checkbox" name="optional_products" value="Werkzeug" class="w-4 h-4 rounded text-brand"/>
            <span class="text-sm">Werkzeug / Tools</span>
          </label>
          <label class="flex items-center gap-3 p-3 border rounded-xl hover:bg-gray-50 cursor-pointer">
            <input type="checkbox" name="optional_products" value="Kinderbetten" class="w-4 h-4 rounded text-brand"/>
            <span class="text-sm">Kinderbetten</span>
          </label>
          <label class="flex items-center gap-3 p-3 border rounded-xl hover:bg-gray-50 cursor-pointer">
            <input type="checkbox" name="optional_products" value="Bettwaesche" class="w-4 h-4 rounded text-brand"/>
            <span class="text-sm">Bettwäsche-Wechsel</span>
          </label>
          <label class="flex items-center gap-3 p-3 border rounded-xl hover:bg-gray-50 cursor-pointer">
            <input type="checkbox" name="optional_products" value="Waescheservice" class="w-4 h-4 rounded text-brand"/>
            <span class="text-sm">Wäscheservice</span>
          </label>
        </div>
      </div>

      <!-- Number of People -->
      <div class="border-t pt-5">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Anzahl Reinigungskräfte</label>
            <select name="no_people" class="w-full px-3 py-2.5 border rounded-xl">
              <option value="1">1 Person</option>
              <option value="2">2 Personen</option>
              <option value="3">3 Personen</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-600 mb-1">Anzahl Schlüssel</label>
            <input type="number" name="num_keys" min="0" max="5" value="1" class="w-full px-3 py-2.5 border rounded-xl"/>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div>
        <label class="block text-sm font-medium text-gray-600 mb-1">Türcode / Zugang</label>
        <input type="text" name="door_code" placeholder="z.B. Schlüsselbox: 1234, Smartlock-Code" class="w-full px-3 py-2.5 border rounded-xl"/>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-600 mb-1">Anmerkungen<?= $isAirbnb ? ' / Spezielle Anweisungen' : ' (optional)' ?></label>
        <textarea name="notes" rows="3" placeholder="<?= $isAirbnb ? 'Waschmaschine starten, Handtücher falten, Willkommenspaket...' : 'Besondere Wünsche, Hinweise...' ?>" class="w-full px-3 py-2.5 border rounded-xl"></textarea>
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
