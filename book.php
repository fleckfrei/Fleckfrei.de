<?php
/**
 * Public Booking Page — No login required
 * Customers can book cleaning services and pay via Stripe or PayPal
 */
require_once __DIR__ . '/includes/config.php';
$services = all("SELECT s_id, title, total_price, street, city FROM services WHERE status=1 AND customer_id_fk IS NOT NULL GROUP BY title ORDER BY title");
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
  <title>Jetzt buchen — <?= SITE ?></title>
  <meta name="theme-color" content="<?= BRAND ?>"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:'<?= BRAND ?>','brand-dark':'<?= BRAND_DARK ?>'}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>body{font-family:'Inter',system-ui,sans-serif;}</style>
  <?php if (FEATURE_STRIPE && STRIPE_PK): ?>
  <script src="https://js.stripe.com/v3/"></script>
  <?php endif; ?>
</head>
<body class="bg-gray-50 min-h-screen">

<!-- Header -->
<header class="bg-white border-b">
  <div class="max-w-2xl mx-auto px-4 py-4 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-brand text-white flex items-center justify-center font-bold text-lg"><?= LOGO_LETTER ?></div>
      <div><h1 class="font-bold text-lg"><?= SITE ?></h1><p class="text-xs text-gray-400"><?= SITE_TAGLINE ?></p></div>
    </div>
    <a href="https://<?= SITE_DOMAIN ?>" class="text-sm text-brand hover:underline"><?= SITE_DOMAIN ?></a>
  </div>
</header>

<main class="max-w-2xl mx-auto px-4 py-8" x-data="bookingForm()">
  <!-- Steps indicator -->
  <div class="flex items-center justify-center gap-2 mb-8">
    <template x-for="(s,i) in ['Service','Details','Bezahlung']" :key="i">
      <div class="flex items-center gap-2">
        <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold transition"
             :class="step > i ? 'bg-brand text-white' : (step === i ? 'bg-brand text-white ring-4 ring-brand/20' : 'bg-gray-200 text-gray-500')"
             x-text="step > i ? '✓' : (i+1)"></div>
        <span class="text-sm font-medium" :class="step === i ? 'text-brand' : 'text-gray-400'" x-text="s"></span>
        <template x-if="i < 2"><div class="w-8 h-0.5 bg-gray-200"></div></template>
      </div>
    </template>
  </div>

  <!-- Step 0: Service -->
  <div x-show="step===0" x-transition>
    <h2 class="text-2xl font-bold mb-6 text-center">Was brauchst du?</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <button @click="selectService('Standardreinigung', 35)" class="p-5 bg-white rounded-xl border-2 hover:border-brand transition text-left" :class="form.service==='Standardreinigung' ? 'border-brand bg-brand/5' : 'border-gray-200'">
        <div class="font-bold">Standardreinigung</div>
        <div class="text-sm text-gray-500 mt-1">Wohnung, Haus, Buero — ab 2h</div>
        <div class="text-brand font-bold mt-2">ab 35 EUR/h</div>
      </button>
      <button @click="selectService('Grundreinigung', 45)" class="p-5 bg-white rounded-xl border-2 hover:border-brand transition text-left" :class="form.service==='Grundreinigung' ? 'border-brand bg-brand/5' : 'border-gray-200'">
        <div class="font-bold">Grundreinigung</div>
        <div class="text-sm text-gray-500 mt-1">Tiefenreinigung, Umzug, Renovierung</div>
        <div class="text-brand font-bold mt-2">ab 45 EUR/h</div>
      </button>
      <button @click="selectService('Fensterreinigung', 40)" class="p-5 bg-white rounded-xl border-2 hover:border-brand transition text-left" :class="form.service==='Fensterreinigung' ? 'border-brand bg-brand/5' : 'border-gray-200'">
        <div class="font-bold">Fensterreinigung</div>
        <div class="text-sm text-gray-500 mt-1">Fenster innen + aussen</div>
        <div class="text-brand font-bold mt-2">ab 40 EUR/h</div>
      </button>
      <button @click="selectService('Buroreinigung', 38)" class="p-5 bg-white rounded-xl border-2 hover:border-brand transition text-left" :class="form.service==='Buroreinigung' ? 'border-brand bg-brand/5' : 'border-gray-200'">
        <div class="font-bold">Bueroreinigung</div>
        <div class="text-sm text-gray-500 mt-1">Regelmaessig oder einmalig</div>
        <div class="text-brand font-bold mt-2">ab 38 EUR/h</div>
      </button>
    </div>
  </div>

  <!-- Step 1: Details -->
  <div x-show="step===1" x-transition>
    <h2 class="text-2xl font-bold mb-6 text-center">Deine Daten</h2>
    <div class="bg-white rounded-xl border p-6 space-y-4">
      <div class="grid grid-cols-2 gap-4">
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Name *</label>
          <input type="text" x-model="form.name" required class="w-full px-4 py-3 border rounded-xl"/></div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Telefon *</label>
          <input type="tel" x-model="form.phone" required class="w-full px-4 py-3 border rounded-xl"/></div>
      </div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Email *</label>
        <input type="email" x-model="form.email" required class="w-full px-4 py-3 border rounded-xl"/></div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Adresse *</label>
        <input type="text" x-model="form.address" required placeholder="Strasse Nr, PLZ Stadt" class="w-full px-4 py-3 border rounded-xl"/></div>
      <div class="grid grid-cols-2 gap-4">
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Datum *</label>
          <input type="date" x-model="form.date" required :min="minDate" class="w-full px-4 py-3 border rounded-xl"/></div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Uhrzeit *</label>
          <select x-model="form.time" class="w-full px-4 py-3 border rounded-xl">
            <option value="08:00">08:00</option><option value="09:00">09:00</option><option value="10:00">10:00</option>
            <option value="11:00">11:00</option><option value="12:00">12:00</option><option value="13:00">13:00</option>
            <option value="14:00">14:00</option><option value="15:00">15:00</option><option value="16:00">16:00</option>
          </select></div>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Stunden *</label>
          <select x-model="form.hours" class="w-full px-4 py-3 border rounded-xl">
            <option value="2">2 Stunden</option><option value="3">3 Stunden</option><option value="4">4 Stunden</option>
            <option value="5">5 Stunden</option><option value="6">6 Stunden</option><option value="8">8 Stunden</option>
          </select></div>
        <div><label class="block text-sm font-medium text-gray-600 mb-1">Haeufigkeit</label>
          <select x-model="form.frequency" class="w-full px-4 py-3 border rounded-xl">
            <option value="once">Einmalig</option><option value="weekly">Woechentlich</option>
            <option value="biweekly">Alle 2 Wochen</option><option value="monthly">Monatlich</option>
          </select></div>
      </div>
      <div><label class="block text-sm font-medium text-gray-600 mb-1">Anmerkungen</label>
        <textarea x-model="form.notes" rows="2" class="w-full px-4 py-3 border rounded-xl" placeholder="Besondere Wuensche..."></textarea></div>
    </div>
  </div>

  <!-- Step 2: Payment -->
  <div x-show="step===2" x-transition>
    <h2 class="text-2xl font-bold mb-6 text-center">Zusammenfassung & Bezahlung</h2>
    <div class="bg-white rounded-xl border p-6 mb-4">
      <div class="space-y-2 text-sm">
        <div class="flex justify-between"><span class="text-gray-500">Service</span><span class="font-medium" x-text="form.service"></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Datum</span><span x-text="form.date + ' um ' + form.time"></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Dauer</span><span x-text="form.hours + ' Stunden'"></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Adresse</span><span x-text="form.address"></span></div>
        <div class="border-t pt-2 mt-2 flex justify-between">
          <span class="font-bold">Gesamtpreis (netto)</span>
          <span class="text-2xl font-bold text-brand" x-text="totalPrice + ' EUR'"></span>
        </div>
        <div class="flex justify-between text-gray-400">
          <span>inkl. 19% MwSt</span><span x-text="totalBrutto + ' EUR'"></span>
        </div>
      </div>
    </div>

    <!-- Payment Options -->
    <div class="space-y-3">
      <?php if (FEATURE_STRIPE): ?>
      <button @click="payStripe()" :disabled="paying" class="w-full py-4 bg-indigo-600 text-white rounded-xl font-bold text-lg hover:bg-indigo-700 transition flex items-center justify-center gap-2">
        <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.683-1.305 1.901-1.305 2.227 0 4.515.858 6.09 1.631l.89-5.494C18.252.975 15.697 0 12.165 0 9.667 0 7.589.654 6.104 1.872 4.56 3.147 3.757 4.992 3.757 7.218c0 4.039 2.467 5.76 6.476 7.219 2.585.92 3.445 1.574 3.445 2.583 0 .98-.84 1.545-2.354 1.545-1.875 0-4.965-.921-7.076-2.19l-.9 5.555C5.748 22.825 8.93 24 12.256 24c2.594 0 4.715-.635 6.237-1.85 1.659-1.317 2.506-3.289 2.506-5.705 0-4.158-2.508-5.829-7.023-7.295z"/></svg>
        <span x-text="paying ? 'Verarbeite...' : 'Mit Karte bezahlen'"></span>
      </button>
      <?php endif; ?>
      <?php if (FEATURE_PAYPAL): ?>
      <button @click="payPaypal()" :disabled="paying" class="w-full py-4 bg-yellow-500 text-gray-900 rounded-xl font-bold text-lg hover:bg-yellow-600 transition flex items-center justify-center gap-2">
        <span x-text="paying ? 'Verarbeite...' : 'Mit PayPal bezahlen'"></span>
      </button>
      <?php endif; ?>
      <button @click="payLater()" :disabled="paying" class="w-full py-3 border-2 border-gray-200 text-gray-600 rounded-xl font-medium hover:bg-gray-50 transition">
        Auf Rechnung (Zahlung nach Service)
      </button>
    </div>
  </div>

  <!-- Success -->
  <div x-show="step===3" x-transition>
    <div class="bg-white rounded-xl border p-8 text-center">
      <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-4">
        <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
      </div>
      <h2 class="text-2xl font-bold mb-2">Buchung bestaetigt!</h2>
      <p class="text-gray-500 mb-4">Wir melden uns in Kuerze bei dir.</p>
      <div class="bg-gray-50 rounded-lg p-4 text-sm text-left mb-4">
        <div class="flex justify-between"><span class="text-gray-500">Buchungs-Nr</span><span class="font-mono font-bold" x-text="bookingId"></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Service</span><span x-text="form.service"></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Datum</span><span x-text="form.date + ' ' + form.time"></span></div>
      </div>
      <a href="/book.php" class="px-6 py-3 bg-brand text-white rounded-xl font-medium inline-block">Weitere Buchung</a>
    </div>
  </div>

  <!-- Navigation -->
  <div class="flex justify-between mt-6" x-show="step < 3">
    <button @click="step > 0 && step--" x-show="step > 0" class="px-6 py-3 border rounded-xl font-medium text-gray-600 hover:bg-gray-50">Zurueck</button>
    <div x-show="step === 0"></div>
    <button @click="nextStep()" x-show="step < 2" :disabled="!canNext" class="px-8 py-3 bg-brand text-white rounded-xl font-bold hover:opacity-90 transition disabled:opacity-50">Weiter</button>
  </div>
</main>

<footer class="border-t bg-white mt-12 py-6 text-center text-sm text-gray-400">
  &copy; <?= date('Y') ?> <?= SITE ?> — <a href="https://<?= SITE_DOMAIN ?>" class="text-brand"><?= SITE_DOMAIN ?></a>
</footer>

<script>
function bookingForm() {
  return {
    step: 0, paying: false, bookingId: '',
    form: {
      service: '', price_per_hour: 0, name: '', phone: '', email: '',
      address: '', date: '', time: '09:00', hours: '3', frequency: 'once', notes: ''
    },
    get minDate() { var d=new Date(); d.setDate(d.getDate()+1); return d.toISOString().slice(0,10); },
    get totalPrice() { return (this.form.price_per_hour * parseInt(this.form.hours)).toFixed(2); },
    get totalBrutto() { return (this.totalPrice * 1.19).toFixed(2); },
    get canNext() {
      if (this.step===0) return !!this.form.service;
      if (this.step===1) return this.form.name && this.form.phone && this.form.email && this.form.address && this.form.date;
      return true;
    },

    selectService(name, price) { this.form.service = name; this.form.price_per_hour = price; this.step = 1; },
    nextStep() { if (this.canNext) this.step++; },

    async submitBooking(payment_method) {
      this.paying = true;
      try {
        var resp = await fetch('/api/index.php?action=webhook/booking', {
          method: 'POST', headers: {'Content-Type':'application/json'},
          body: JSON.stringify({
            name: this.form.name, phone: this.form.phone, email: this.form.email,
            address: this.form.address, service: this.form.service,
            date: this.form.date, time: this.form.time, hours: parseInt(this.form.hours),
            frequency: this.form.frequency, notes: this.form.notes,
            platform: 'website', customer_type: 'private',
            payment_method: payment_method, amount: parseFloat(this.totalBrutto)
          })
        });
        var d = await resp.json();
        this.paying = false;
        if (d.success) { this.bookingId = d.data?.booking_id || d.data?.j_id || '—'; this.step = 3; }
        else alert(d.error || 'Fehler bei der Buchung');
      } catch(e) { this.paying = false; alert('Netzwerkfehler'); }
    },

    async payStripe() {
      await this.submitBooking('stripe');
      // Stripe redirect would happen here via payment intent
    },
    async payPaypal() {
      await this.submitBooking('paypal');
    },
    async payLater() {
      await this.submitBooking('invoice');
    }
  };
}
</script>
</body>
</html>
