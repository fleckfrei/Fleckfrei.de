<?php
require_once __DIR__ . '/../../includes/auth.php';
requireCustomer();
if (!customerCan('booking')) { header('Location: /customer/v2/'); exit; }
$title = 'Neue Buchung'; $page = 'booking';
$cid = me()['id'];
$user = me();

$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);
$isAirbnb = in_array($customer['customer_type'] ?? '', ['Airbnb', 'Booking', 'Short-Term Rental', 'Company', 'Host']);

try { $addresses = all("SELECT * FROM customer_address WHERE customer_id_fk=? ORDER BY ca_id DESC", [$cid]); } catch (Exception $e) { $addresses = []; }

// Customer-own services first, fall back to shared catalog
$services = all("SELECT s_id, title, total_price FROM services WHERE customer_id_fk=? AND status=1 ORDER BY title", [$cid]);
if (empty($services)) {
    $services = all("SELECT s_id, title, total_price FROM services WHERE (customer_id_fk IS NULL OR customer_id_fk=0) AND status=1 ORDER BY title");
}

include __DIR__ . '/../../includes/layout-v2.php';
?>

<div class="mb-6">
  <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Neue Buchung</h1>
  <p class="text-gray-500 mt-1 text-sm"><?= $isAirbnb ? 'Turnover-Service für Ihre Unterkunft' : 'Reinigungstermin buchen — Sie sehen den Preis live' ?></p>
</div>

<div x-data="bookingForm()" class="grid grid-cols-1 lg:grid-cols-3 gap-6">

  <!-- ============ MAIN FORM (left, 2 cols) ============ -->
  <div class="lg:col-span-2 space-y-6" x-show="!submitted">

    <!-- 1. Häufigkeit -->
    <div class="card-elev p-6">
      <h2 class="font-bold text-lg mb-1">1. Wie oft soll gereinigt werden?</h2>
      <p class="text-xs text-gray-500 mb-5">Regelmäßige Termine werden günstiger.</p>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <template x-for="opt in frequencies" :key="opt.value">
          <button type="button"
                  @click="form.frequency = opt.value"
                  :class="form.frequency === opt.value ? 'border-brand bg-brand-light ring-2 ring-brand' : 'border-gray-200 hover:border-brand'"
                  class="relative border-2 rounded-xl p-4 text-left transition">
            <div class="font-bold text-sm text-gray-900" x-text="opt.label"></div>
            <div x-show="opt.discount" class="text-[11px] text-brand font-semibold mt-1" x-text="opt.discount"></div>
            <svg x-show="form.frequency === opt.value" class="absolute top-2 right-2 w-5 h-5 text-brand" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
          </button>
        </template>
      </div>
    </div>

    <!-- 2. Service + Details -->
    <div class="card-elev p-6">
      <h2 class="font-bold text-lg mb-5">2. Details der Reinigung</h2>

      <div class="space-y-4">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Service</label>
          <select x-model="form.service" required class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none">
            <option value="">Bitte wählen…</option>
            <?php foreach ($services as $s): ?>
            <option value="<?= $s['s_id'] ?>" data-price="<?= (float) ($s['total_price'] ?? 0) ?>"><?= e($s['title']) ?><?= $s['total_price'] ? ' — ' . money($s['total_price']) . '/h' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Dauer</label>
            <select x-model="form.hours" required class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none">
              <option value="2">2 Stunden</option>
              <option value="3">3 Stunden</option>
              <option value="4">4 Stunden</option>
              <option value="5">5 Stunden</option>
              <option value="6">6 Stunden</option>
              <option value="8">8 Stunden (Ganztag)</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Anzahl Reinigungskräfte</label>
            <select x-model="form.no_people" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none">
              <option value="1">1 Person</option>
              <option value="2">2 Personen</option>
              <option value="3">3 Personen</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Datum</label>
            <input type="date" x-model="form.date" required min="<?= date('Y-m-d') ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Uhrzeit</label>
            <select x-model="form.time" required class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none">
              <?php for ($h = 7; $h <= 19; $h++): ?>
              <option value="<?= sprintf('%02d:00', $h) ?>"><?= sprintf('%02d:00', $h) ?> Uhr</option>
              <option value="<?= sprintf('%02d:30', $h) ?>"><?= sprintf('%02d:30', $h) ?> Uhr</option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- 3. Adresse -->
    <div class="card-elev p-6">
      <h2 class="font-bold text-lg mb-1">3. Reinigungsadresse</h2>
      <p class="text-xs text-gray-500 mb-5">Gespeicherte Adressen wählen oder eine neue anlegen.</p>

      <!-- Saved addresses list -->
      <div class="space-y-2 mb-4" x-show="addresses.length > 0">
        <template x-for="(a, idx) in addresses" :key="a.ca_id || idx">
          <label class="flex items-start gap-3 p-3 border-2 rounded-xl cursor-pointer transition"
                 :class="form.address === a.full ? 'border-brand bg-brand-light' : 'border-gray-200 hover:border-brand'">
            <input type="radio" :value="a.full" x-model="form.address" class="mt-1 w-4 h-4 text-brand focus:ring-brand"/>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span class="text-xs font-semibold text-brand uppercase tracking-wider" x-text="a.address_for || 'Adresse'"></span>
              </div>
              <div class="font-medium text-gray-900 text-sm truncate" x-text="a.full"></div>
            </div>
          </label>
        </template>
      </div>

      <!-- Toggle: add new address -->
      <button type="button" @click="showNewAddr = !showNewAddr"
              class="w-full flex items-center justify-center gap-2 px-4 py-3 border-2 border-dashed border-gray-300 hover:border-brand hover:bg-brand-light rounded-xl text-sm font-semibold text-gray-600 hover:text-brand transition">
        <svg class="w-4 h-4 transition-transform" :class="showNewAddr ? 'rotate-45' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        <span x-text="showNewAddr ? 'Abbrechen' : 'Neue Adresse hinzufügen'"></span>
      </button>

      <!-- New address form -->
      <div x-show="showNewAddr" x-cloak x-transition class="mt-4 p-4 bg-gray-50 rounded-xl border border-gray-200 space-y-3">
        <div>
          <label class="block text-[10px] font-semibold text-gray-500 mb-1 uppercase tracking-wider">Typ / Zweck</label>
          <select x-model="newAddr.address_for" class="w-full px-3 py-2.5 border border-gray-200 bg-white rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none">
            <option value="Wohnung">🏠 Wohnung</option>
            <option value="Büro">🏢 Büro</option>
            <option value="Ferienwohnung">🏖 Ferienwohnung</option>
            <option value="Garage">🚗 Garage</option>
            <option value="Praxis">⚕ Praxis / Studio</option>
            <option value="Laden">🛍 Laden / Geschäft</option>
            <option value="Treppenhaus">🪜 Treppenhaus</option>
            <option value="Baustelle">🧱 Baustelle</option>
            <option value="Sonstige">📍 Sonstige</option>
          </select>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="sm:col-span-2">
            <label class="block text-[10px] font-semibold text-gray-500 mb-1 uppercase tracking-wider">Straße</label>
            <input type="text" x-model="newAddr.street" placeholder="z.B. Hauptstraße" class="w-full px-3 py-2.5 border border-gray-200 bg-white rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
          </div>
          <div>
            <label class="block text-[10px] font-semibold text-gray-500 mb-1 uppercase tracking-wider">Nr.</label>
            <input type="text" x-model="newAddr.number" placeholder="12a" class="w-full px-3 py-2.5 border border-gray-200 bg-white rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
          </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label class="block text-[10px] font-semibold text-gray-500 mb-1 uppercase tracking-wider">PLZ</label>
            <input type="text" x-model="newAddr.postal_code" placeholder="10115" class="w-full px-3 py-2.5 border border-gray-200 bg-white rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
          </div>
          <div class="sm:col-span-2">
            <label class="block text-[10px] font-semibold text-gray-500 mb-1 uppercase tracking-wider">Stadt</label>
            <input type="text" x-model="newAddr.city" placeholder="Berlin" class="w-full px-3 py-2.5 border border-gray-200 bg-white rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
          </div>
        </div>

        <div x-show="newAddrError" x-cloak class="text-xs text-red-600" x-text="newAddrError"></div>

        <div class="flex gap-2 pt-1">
          <button type="button" @click="saveNewAddress()" :disabled="newAddrSaving"
                  class="flex-1 px-4 py-2.5 bg-brand hover:bg-brand-dark disabled:bg-gray-300 disabled:cursor-not-allowed text-white rounded-lg text-sm font-semibold transition flex items-center justify-center gap-2">
            <svg x-show="!newAddrSaving" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <span x-text="newAddrSaving ? 'Wird gespeichert…' : 'Adresse speichern & verwenden'"></span>
          </button>
        </div>
      </div>

      <!-- Türcode (always visible) -->
      <div class="mt-5">
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Türcode / Zugang (optional)</label>
        <input type="text" x-model="form.door_code" placeholder="z.B. Schlüsselbox 1234, Smartlock-Code" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
      </div>
    </div>

    <?php if ($isAirbnb): ?>
    <!-- 4. Airbnb-spezifisch -->
    <div class="card-elev p-6">
      <h2 class="font-bold text-lg mb-1">4. Gast-Informationen</h2>
      <p class="text-xs text-gray-500 mb-5">Damit der Turnover-Service reibungslos läuft.</p>

      <div class="grid grid-cols-2 gap-4 mb-4">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Gast-Name</label>
          <input type="text" x-model="form.guest_name" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Gast-Telefon</label>
          <input type="tel" x-model="form.guest_phone" placeholder="+49…" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4 mb-4">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Check-out</label>
          <input type="date" x-model="form.guest_checkout_date" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Check-in</label>
          <input type="date" x-model="form.check_in_date" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
        </div>
      </div>

      <div class="mb-4">
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Plattform</label>
        <select x-model="form.booking_platform" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none">
          <option value="airbnb">Airbnb</option>
          <option value="booking">Booking.com</option>
          <option value="vrbo">VRBO</option>
          <option value="direct">Direktbuchung</option>
          <option value="other">Andere</option>
        </select>
      </div>

      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wider">Zusatzleistungen</label>
        <div class="grid grid-cols-2 gap-2">
          <?php foreach (['Reinigungsmittel','Werkzeug','Kinderbetten','Bettwaesche','Waescheservice'] as $opt): ?>
          <label class="flex items-center gap-2 p-3 border border-gray-200 rounded-lg hover:bg-gray-50 cursor-pointer">
            <input type="checkbox" value="<?= $opt ?>" @change="toggleOption('<?= $opt ?>')" class="w-4 h-4 rounded text-brand focus:ring-brand"/>
            <span class="text-sm"><?= $opt ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Notes -->
    <div class="card-elev p-6">
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Anmerkungen <?= $isAirbnb ? '/ spezielle Anweisungen' : '(optional)' ?></label>
      <textarea x-model="form.notes" rows="3" placeholder="<?= $isAirbnb ? 'Waschmaschine starten, Handtücher falten, Willkommenspaket…' : 'Besondere Wünsche, Hinweise…' ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"></textarea>
    </div>

    <div x-show="error" x-cloak class="card-elev bg-red-50 border-red-200 p-4 text-sm text-red-800" x-text="error"></div>
  </div>

  <!-- ============ STICKY SIDEBAR (right, 1 col) ============ -->
  <div class="lg:col-span-1" x-show="!submitted">
    <div class="card-elev p-6 sticky top-[84px]">
      <h3 class="font-bold text-gray-900 mb-4">Ihre Buchungsübersicht</h3>
      <div class="space-y-3 text-sm">
        <div class="flex justify-between">
          <span class="text-gray-500">Häufigkeit</span>
          <span class="font-semibold text-gray-900" x-text="frequencyLabel()"></span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Dauer</span>
          <span class="font-semibold text-gray-900" x-text="form.hours + ' Stunden'"></span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Reinigungskräfte</span>
          <span class="font-semibold text-gray-900" x-text="form.no_people + (form.no_people === '1' ? ' Person' : ' Personen')"></span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Datum</span>
          <span class="font-semibold text-gray-900" x-text="formatDate()"></span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Uhrzeit</span>
          <span class="font-semibold text-gray-900" x-text="form.time + ' Uhr'"></span>
        </div>
      </div>

      <div class="border-t my-4"></div>

      <div class="space-y-2 text-sm">
        <div class="flex justify-between" x-show="basePrice > 0">
          <span class="text-gray-500">Basis (<span x-text="form.hours"></span>h × <span x-text="formatMoney(basePrice)"></span>)</span>
          <span class="text-gray-900" x-text="formatMoney(basePrice * parseInt(form.hours))"></span>
        </div>
        <div class="flex justify-between" x-show="discount > 0">
          <span class="text-brand">Rabatt (<span x-text="frequencyLabel()"></span>)</span>
          <span class="text-brand">−<span x-text="formatMoney(discount)"></span></span>
        </div>
        <div class="flex justify-between text-base font-bold pt-2 border-t">
          <span class="text-gray-900">Geschätzter Preis</span>
          <span class="text-brand" x-text="formatMoney(total)"></span>
        </div>
      </div>

      <button @click="submit()" :disabled="loading || !canSubmit()"
              class="w-full mt-5 px-6 py-3.5 bg-brand hover:bg-brand-dark disabled:bg-gray-300 disabled:cursor-not-allowed text-white rounded-xl font-semibold text-sm transition shadow-sm">
        <span x-show="!loading">Termin verbindlich buchen</span>
        <span x-show="loading" x-cloak>Wird gesendet…</span>
      </button>

      <p class="text-[10px] text-gray-400 text-center mt-3">
        Der angezeigte Preis ist eine Schätzung. Der finale Preis wird nach der Reinigung berechnet und auf der Rechnung ausgewiesen.
      </p>
    </div>
  </div>

  <!-- ============ SUCCESS ============ -->
  <div x-show="submitted" x-cloak class="lg:col-span-3">
    <div class="card-elev p-10 text-center max-w-lg mx-auto">
      <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-green-100 mb-5">
        <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
      </div>
      <h2 class="text-2xl font-bold text-gray-900 mb-2">Buchung eingegangen!</h2>
      <p class="text-gray-600 mb-2">Ihre Buchungsnummer:</p>
      <p class="text-3xl font-mono font-bold text-brand mb-6" x-text="result?.booking_id || '—'"></p>
      <p class="text-sm text-gray-500 mb-6">Wir bestätigen Ihren Termin in Kürze per E-Mail. Sie können den Status unter „Meine Termine" verfolgen.</p>
      <div class="flex gap-3 justify-center">
        <a href="/customer/v2/jobs.php" class="px-6 py-3 bg-brand hover:bg-brand-dark text-white rounded-lg font-semibold text-sm">Meine Termine</a>
        <button @click="resetForm()" class="px-6 py-3 border border-gray-200 hover:bg-gray-50 rounded-lg font-semibold text-sm">Noch eine Buchung</button>
      </div>
    </div>
  </div>
</div>

<script>
function bookingForm() {
  return {
    addresses: <?= json_encode(array_map(function($a) {
        return [
            'ca_id' => (int) $a['ca_id'],
            'full' => trim($a['street'] . ' ' . $a['number'] . ', ' . $a['postal_code'] . ' ' . $a['city']),
            'address_for' => $a['address_for'] ?? 'Wohnung',
            'street' => $a['street'],
            'number' => $a['number'],
            'postal_code' => $a['postal_code'],
            'city' => $a['city'],
        ];
    }, $addresses), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    form: {
      service: '',
      hours: '3',
      no_people: '1',
      frequency: 'einmalig',
      date: '',
      time: '10:00',
      address: <?= json_encode($addresses[0] ?? null ? trim($addresses[0]['street'].' '.$addresses[0]['number'].', '.$addresses[0]['postal_code'].' '.$addresses[0]['city']) : '', JSON_UNESCAPED_UNICODE) ?>,
      door_code: '',
      notes: '',
      optional_products: [],
      guest_name: '',
      guest_phone: '',
      guest_checkout_date: '',
      check_in_date: '',
      booking_platform: 'airbnb',
    },
    showNewAddr: <?= empty($addresses) ? 'true' : 'false' ?>,
    newAddr: { street: '', number: '', postal_code: '', city: '', address_for: 'Wohnung' },
    newAddrSaving: false,
    newAddrError: null,
    frequencies: [
      { value: 'einmalig', label: 'Einmalig', discount: '' },
      { value: 'monatlich', label: 'Monatlich', discount: 'Sparen Sie 3 %' },
      { value: '2wochen', label: 'Alle 2 Wochen', discount: 'Sparen Sie 5 %' },
      { value: 'woechentlich', label: 'Wöchentlich', discount: 'Sparen Sie 7 %' },
    ],
    loading: false,
    submitted: false,
    error: null,
    result: null,
    get basePrice() {
      const opt = document.querySelector(`option[value="${this.form.service}"]`);
      return opt ? parseFloat(opt.dataset.price || 0) : 0;
    },
    get discount() {
      const rates = { woechentlich: 0.07, '2wochen': 0.05, monatlich: 0.03, einmalig: 0 };
      return this.basePrice * parseInt(this.form.hours) * (rates[this.form.frequency] || 0);
    },
    get total() {
      return Math.max(0, this.basePrice * parseInt(this.form.hours) - this.discount);
    },
    frequencyLabel() {
      return this.frequencies.find(f => f.value === this.form.frequency)?.label || '—';
    },
    formatDate() {
      if (!this.form.date) return '—';
      const d = new Date(this.form.date);
      return d.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: 'short' });
    },
    formatMoney(v) {
      return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v || 0);
    },
    toggleOption(opt) {
      const i = this.form.optional_products.indexOf(opt);
      if (i >= 0) this.form.optional_products.splice(i, 1);
      else this.form.optional_products.push(opt);
    },
    canSubmit() {
      return this.form.service && this.form.date && this.form.time && this.form.address;
    },
    saveNewAddress() {
      this.newAddrError = null;
      if (!this.newAddr.street || !this.newAddr.city) {
        this.newAddrError = 'Bitte mindestens Straße und Stadt angeben.';
        return;
      }
      this.newAddrSaving = true;
      fetch('/customer/v2/address-save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(this.newAddr),
      }).then(r => r.json()).then(d => {
        this.newAddrSaving = false;
        if (d.success && d.data) {
          this.addresses.push(d.data);
          this.form.address = d.data.full;
          this.showNewAddr = false;
          this.newAddr = { street: '', number: '', postal_code: '', city: '', address_for: 'Wohnung' };
        } else {
          this.newAddrError = d.error || 'Fehler beim Speichern.';
        }
      }).catch(e => {
        this.newAddrSaving = false;
        this.newAddrError = 'Netzwerk-Fehler.';
      });
    },
    submit() {
      if (!this.canSubmit()) { this.error = 'Bitte Service, Datum, Uhrzeit und Adresse ausfüllen.'; return; }
      this.loading = true; this.error = null;
      const payload = { ...this.form };
      payload.optional_products = this.form.optional_products.join(', ');
      payload.name = '<?= e($customer['name'] ?? '') ?>';
      payload.email = '<?= e($customer['email'] ?? '') ?>';
      payload.phone = '<?= e($customer['phone'] ?? '') ?>';
      payload.type = '<?= e($customer['customer_type'] ?? '') ?>';
      payload.platform = 'customer_portal_v2';
      fetch('/api/index.php?action=webhook/booking', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-API-Key': '<?= API_KEY ?>' },
        body: JSON.stringify(payload)
      }).then(r => r.json()).then(d => {
        this.loading = false;
        if (d.success) { this.result = d.data; this.submitted = true; }
        else { this.error = d.error || 'Unbekannter Fehler bei der Buchung.'; }
      }).catch(e => { this.loading = false; this.error = 'Netzwerk-Fehler.'; });
    },
    resetForm() {
      this.submitted = false; this.result = null; this.error = null;
      this.form.notes = ''; this.form.door_code = '';
    },
  };
}
</script>

<?php include __DIR__ . '/../../includes/footer-v2.php'; ?>
