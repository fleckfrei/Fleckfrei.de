<?php
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Jetzt buchen · Fleckfrei</title>
<meta name="description" content="Fleckfrei Home Care in Berlin — online buchen in 3 Minuten. Fair. Kein Abo."/>
<meta name="theme-color" content="#2E7D6B"/>
<link rel="icon" href="https://fleckfrei.de/img/logo/favicon.svg"/>
<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<script>
tailwind.config = { theme: { extend: { colors: { brand: '#2E7D6B', 'brand-dark': '#1e5a4c' }, fontFamily: { sans: ['Inter','sans-serif'] } } } }
</script>
<style>
body{font-family:'Inter',sans-serif}
.step-dot{width:10px;height:10px;border-radius:50%;background:#e5e7eb;transition:all .3s}
.step-dot.active{background:#2E7D6B;width:28px;border-radius:5px}
.step-line{flex:1;height:2px;background:#e5e7eb;transition:background .5s}
.step-line.done{background:#2E7D6B}
[x-cloak]{display:none !important}
</style>
</head>
<body class="bg-gray-50">

<nav class="bg-white border-b sticky top-0 z-10">
  <div class="max-w-3xl mx-auto px-4 py-4 flex items-center justify-between">
    <a href="https://fleckfrei.de" class="font-bold text-xl text-brand">Fleckfrei</a>
    <a href="https://app.fleckfrei.de/login.php" class="text-sm text-gray-600 hover:text-brand">Login</a>
  </div>
</nav>

<main class="max-w-3xl mx-auto px-4 py-8" x-data="bookingFlow()" x-init="init()" x-cloak>

  <!-- Step indicator -->
  <div class="flex items-center gap-2 mb-6">
    <template x-for="i in 4" :key="i">
      <div class="flex items-center flex-1" :class="i === 4 ? 'flex-initial' : ''">
        <div class="step-dot" :class="{'active': step >= i-1}"></div>
        <div x-show="i < 4" class="step-line mx-1" :class="{'done': step >= i}"></div>
      </div>
    </template>
  </div>

  <!-- Step 0: Service -->
  <section x-show="step === 0" class="bg-white rounded-2xl shadow p-6 border">
    <h1 class="text-2xl font-bold mb-1">Welcher Service?</h1>
    <p class="text-sm text-gray-500 mb-5">Wähle, was du buchst.</p>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
      <template x-for="s in services" :key="s.id">
        <button type="button" @click="form.service_type = s.id; form.service_name = s.name; form.service_price_start = s.startingPrice; step = 1"
                class="p-5 border-2 rounded-xl text-left hover:border-brand transition"
                :class="form.service_type === s.id ? 'border-brand bg-brand/5' : 'border-gray-200'">
          <div class="text-3xl mb-2" x-text="s.icon"></div>
          <div class="font-bold" x-text="s.name"></div>
          <div class="text-sm text-gray-500 mt-1" x-text="s.tag"></div>
          <div class="mt-3 text-brand font-bold" x-text="'ab ' + (s.startingPrice || 0).toFixed(2).replace('.',',') + ' €'"></div>
        </button>
      </template>
    </div>
  </section>

  <!-- Step 1: Property + Address -->
  <section x-show="step === 1" class="bg-white rounded-2xl shadow p-6 border">
    <button @click="step = 0" class="text-sm text-gray-500 hover:text-brand mb-3">← Zurück</button>
    <h2 class="text-2xl font-bold mb-1">Deine Wohnung</h2>
    <p class="text-sm text-gray-500 mb-5">Ort + Größe — für exakten Preis.</p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Straße + Nr. *</label>
        <input x-model="form.street" class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none" required/>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1">PLZ *</label>
          <input x-model="form.plz" maxlength="5" pattern="[0-9]{5}" required class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1">Stadt</label>
          <input x-model="form.city" class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
        </div>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Größe (qm) *</label>
        <input x-model.number="form.qm" type="number" min="10" max="1000" required class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Zimmer</label>
        <input x-model.number="form.rooms" type="number" min="1" max="20" class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
      </div>
    </div>
    <button type="button" @click="step = 2" :disabled="!form.plz || !form.street || !form.qm"
            class="mt-5 w-full py-3 bg-brand text-white rounded-lg font-bold disabled:opacity-50 hover:bg-brand-dark transition">Weiter →</button>
  </section>

  <!-- Step 2: Date, Time, Extras -->
  <section x-show="step === 2" class="bg-white rounded-2xl shadow p-6 border">
    <button @click="step = 1" class="text-sm text-gray-500 hover:text-brand mb-3">← Zurück</button>
    <h2 class="text-2xl font-bold mb-1">Wann & wie lang?</h2>
    <p class="text-sm text-gray-500 mb-5">Termin + Dauer + Extras.</p>
    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Datum *</label>
        <input x-model="form.date" type="date" :min="minDate" required class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Uhrzeit *</label>
        <input x-model="form.time" type="time" value="09:00" required class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Stunden</label>
        <input x-model.number="form.hours" type="number" min="2" step="0.5" class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
      </div>
    </div>
    <div class="mt-4">
      <label class="block text-xs font-bold text-gray-600 mb-2">Häufigkeit</label>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
        <template x-for="f in freqs" :key="f.v">
          <label class="cursor-pointer">
            <input type="radio" x-model="form.frequency" :value="f.v" class="peer sr-only"/>
            <div class="px-3 py-2 border-2 rounded-lg text-center text-sm peer-checked:border-brand peer-checked:bg-brand/5 transition" x-text="f.l"></div>
          </label>
        </template>
      </div>
    </div>
    <div class="mt-4" x-show="addons.length">
      <label class="block text-xs font-bold text-gray-600 mb-2">Extras</label>
      <div class="space-y-1">
        <template x-for="a in addons" :key="a.id">
          <label class="flex items-center gap-2 p-2 hover:bg-gray-50 rounded cursor-pointer">
            <input type="checkbox" :value="a.id" x-model="form.extras" class="rounded"/>
            <span class="text-sm flex-1" x-text="(a.icon ? a.icon + ' ' : '') + a.name"></span>
            <span class="text-xs text-gray-500" x-text="'+' + a.price.toFixed(2).replace('.',',') + ' €'"></span>
          </label>
        </template>
      </div>
    </div>
    <button type="button" @click="step = 3" :disabled="!form.date || !form.time"
            class="mt-5 w-full py-3 bg-brand text-white rounded-lg font-bold disabled:opacity-50 hover:bg-brand-dark transition">Weiter →</button>
  </section>

  <!-- Step 3: Contact + Submit -->
  <section x-show="step === 3" class="bg-white rounded-2xl shadow p-6 border">
    <button @click="step = 2" class="text-sm text-gray-500 hover:text-brand mb-3">← Zurück</button>
    <h2 class="text-2xl font-bold mb-1">Deine Kontakt­daten</h2>
    <p class="text-sm text-gray-500 mb-5">Wir senden dir Bestätigung per Email.</p>

    <!-- Summary -->
    <div class="bg-gray-50 rounded-xl p-4 mb-5 text-sm">
      <div class="flex justify-between py-1"><span>Service</span><strong x-text="form.service_name"></strong></div>
      <div class="flex justify-between py-1"><span>Datum</span><strong x-text="form.date + ' um ' + form.time"></strong></div>
      <div class="flex justify-between py-1"><span>Dauer</span><strong x-text="form.hours + ' h'"></strong></div>
      <div class="flex justify-between py-1"><span>Adresse</span><strong x-text="form.street + ', ' + form.plz + ' ' + form.city"></strong></div>
      <div class="flex justify-between py-1"><span>Wohnung</span><strong x-text="form.qm + ' qm · ' + form.rooms + ' Zi.'"></strong></div>
      <div class="flex justify-between py-2 mt-1 border-t text-lg"><span class="font-bold">Basispreis</span><strong class="text-brand" x-text="(form.service_price_start || 0).toFixed(2).replace('.',',') + ' € netto'"></strong></div>
      <div class="text-xs text-gray-500 mt-1">Endpreis nach Adress- und Wohnungs-Check. Bestätigung via Email binnen 24h.</div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Name *</label>
        <input x-model="form.name" required class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Email *</label>
        <input x-model="form.email" type="email" required class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs font-bold text-gray-600 mb-1">Handy *</label>
        <input x-model="form.phone" type="tel" required placeholder="+49 ..." class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs font-bold text-gray-600 mb-1">Zusatz-Info (optional)</label>
        <textarea x-model="form.notes" rows="2" class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"></textarea>
      </div>
    </div>

    <div class="mt-4 space-y-2 text-xs bg-gray-50 p-3 rounded-lg">
      <label class="flex items-start gap-2 cursor-pointer">
        <input type="checkbox" x-model="form.consent_contact" required class="mt-0.5"/>
        <span>Fleckfrei darf mich zur Bestätigung per Email/Telefon kontaktieren. *</span>
      </label>
      <label class="flex items-start gap-2 cursor-pointer">
        <input type="checkbox" x-model="form.consent_privacy" required class="mt-0.5"/>
        <span>Ich akzeptiere die <a href="https://fleckfrei.de/datenschutz.html" target="_blank" class="text-brand underline">Datenschutzerklärung</a>. *</span>
      </label>
      <label class="flex items-start gap-2 cursor-pointer">
        <input type="checkbox" x-model="form.consent_marketing"/>
        <span>Fleckfrei darf mir gelegentlich Angebote per Email senden. Jederzeit widerrufbar.</span>
      </label>
    </div>

    <button type="button" @click="submit()" :disabled="!canSubmit || submitting"
            class="mt-5 w-full py-4 bg-brand text-white rounded-xl font-bold text-lg disabled:opacity-50 hover:bg-brand-dark transition">
      <span x-show="!submitting">📅 Jetzt verbindlich buchen →</span>
      <span x-show="submitting">⏳ Speichere…</span>
    </button>
    <div x-show="error" x-text="error" class="mt-3 text-sm text-red-600"></div>
  </section>

  <!-- Step 4: Success -->
  <section x-show="step === 4" class="bg-white rounded-2xl shadow p-8 border text-center">
    <div class="text-6xl mb-4">✅</div>
    <h2 class="text-3xl font-bold text-brand mb-2">Danke, wir haben's!</h2>
    <p class="text-gray-600 mb-4">Buchung <strong x-text="'#' + bookingId"></strong> ist eingegangen.</p>
    <p class="text-sm text-gray-500 mb-6">Bestätigung per Email unterwegs. Wir melden uns binnen 24h mit Partner-Details.</p>
    <div x-show="newCustomer" class="bg-amber-50 border border-amber-300 rounded-lg p-4 text-sm text-amber-900 mb-5">
      <div class="font-bold mb-1">🎁 Dein Kundenkonto wurde automatisch erstellt!</div>
      Log dich ein unter <a href="https://app.fleckfrei.de/login.php" class="underline font-semibold">app.fleckfrei.de/login</a> — mit Google-Sign-in oder setze ein Passwort via „Passwort vergessen".
    </div>
    <a href="https://app.fleckfrei.de/login.php" class="inline-block px-6 py-3 bg-brand text-white rounded-lg font-bold hover:bg-brand-dark">Zum Kundenportal →</a>
    <div class="mt-4"><a href="https://fleckfrei.de" class="text-sm text-gray-500 hover:text-brand">← zurück zur Startseite</a></div>
  </section>

</main>

<script>
function bookingFlow() {
  return {
    step: 0,
    submitting: false,
    error: '',
    bookingId: '',
    newCustomer: false,
    services: [],
    addons: [],
    freqs: [
      {v:'once',l:'Einmalig'},
      {v:'weekly',l:'Wöchentlich'},
      {v:'biweekly',l:'14-täglich'},
      {v:'monthly',l:'Monatlich'},
    ],
    form: {
      service_type: '', service_name: '', service_price_start: 0,
      street: '', plz: '', city: 'Berlin', qm: 60, rooms: 2,
      date: '', time: '09:00', hours: 3, frequency: 'once',
      extras: [], name: '', email: '', phone: '', notes: '',
      consent_contact: false, consent_privacy: false, consent_marketing: false,
    },
    get minDate() { const d = new Date(); d.setDate(d.getDate()+1); return d.toISOString().slice(0,10); },
    get canSubmit() {
      return this.form.name && this.form.email && this.form.phone
        && this.form.consent_contact && this.form.consent_privacy;
    },
    async init() {
      // Preselect service from ?service=hc|str|bs
      const params = new URLSearchParams(location.search);
      const preSvc = params.get('service') || params.get('type');
      try {
        const r = await fetch('/api/prices-public.php?t=' + Date.now());
        const d = await r.json();
        const t = d.website_titles || {};
        const mp = d.min_prices || {};
        this.services = [
          {id:'hc', icon:'🏠', name: t.home_care || 'Home Care', startingPrice: mp.private || 58.29, tag: 'Privat · regelmäßig'},
          {id:'str', icon:'🏨', name: t.str || 'Short-Term Rental', startingPrice: mp.str || 58.29, tag: '⭐ Meistgebucht · Airbnb/Booking'},
          {id:'bs', icon:'🏢', name: t.office || 'Business & Office', startingPrice: mp.office || 58.29, tag: 'Gewerbe · flexibel'},
        ];
        this.addons = (d.addons || []).filter(a => a.visibility !== 'hidden').map(a => ({
          id: a.id, name: a.name, price: parseFloat(a.price), icon: a.icon || ''
        }));
        if (preSvc) {
          const match = this.services.find(s => s.id === preSvc);
          if (match) { this.form.service_type = match.id; this.form.service_name = match.name; this.form.service_price_start = match.startingPrice; this.step = 1; }
        }
      } catch(e) { console.warn('load prices failed', e); }
    },
    async submit() {
      this.submitting = true;
      this.error = '';
      try {
        const r = await fetch('/api/booking-public.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(this.form),
        });
        const d = await r.json();
        if (!d.success) throw new Error(d.error || 'Fehler');
        this.bookingId = d.booking_id;
        this.newCustomer = d.new_customer;
        this.step = 4;
        if (window.plausible) plausible('booking-submit');
      } catch(e) {
        this.error = '❌ ' + e.message;
      } finally {
        this.submitting = false;
      }
    },
  };
}
</script>
<script defer data-domain="app.fleckfrei.de" src="https://plausible.io/js/script.js"></script>
</body>
</html>
