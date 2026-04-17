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
<meta name="theme-color" content="<?= BRAND ?>"/>
<link rel="icon" href="https://fleckfrei.de/img/logo/favicon.svg"/>
<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<script>
tailwind.config = { theme: { extend: { colors: { brand: '<?= BRAND ?>', 'brand-dark': '<?= BRAND_DARK ?>' }, fontFamily: { sans: ['Inter','sans-serif'] } } } }
</script>
<style>
body{font-family:'Inter',sans-serif}
.step-dot{width:10px;height:10px;border-radius:50%;background:#e5e7eb;transition:all .3s}
.step-dot.active{background:<?= BRAND ?>;width:28px;border-radius:5px}
.step-line{flex:1;height:2px;background:#e5e7eb;transition:background .5s}
.step-line.done{background:<?= BRAND ?>}
[x-cloak]{display:none !important}
</style>
</head>
<body class="bg-gray-50">

<nav class="bg-white border-b sticky top-0 z-10">
  <div class="max-w-3xl mx-auto px-4 py-4 flex items-center justify-between">
    <a href="https://<?= SITE_DOMAIN ?>" class="font-bold text-xl text-brand"><?= SITE ?></a>
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

  <!-- Step 1: Property + Address with OSM Autocomplete -->
  <section x-show="step === 1" class="bg-white rounded-2xl shadow p-6 border">
    <button @click="step = 0" class="text-sm text-gray-500 hover:text-brand mb-3">← Zurück</button>
    <h2 class="text-2xl font-bold mb-1">Deine Wohnung</h2>
    <p class="text-sm text-gray-500 mb-5">Tippe die Adresse — wir prüfen sie automatisch.</p>

    <!-- Address-Autocomplete Field -->
    <div class="relative mb-3">
      <label class="block text-xs font-bold text-gray-600 mb-1">Adresse suchen *</label>
      <input x-model="addrQuery" @input.debounce.400="searchAddress()" @focus="addrFocus=true" @blur="setTimeout(()=>addrFocus=false,200)"
             placeholder="z.B. Alexanderplatz 1, 10178 Berlin"
             class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"
             :class="form.address_verified ? 'border-emerald-500 bg-emerald-50' : ''"/>
      <div x-show="addrLoading" class="absolute right-3 top-9 text-xs text-gray-400">⏳ Suche...</div>
      <div x-show="form.address_verified" class="absolute right-3 top-9 text-emerald-600 text-xl">✓</div>

      <!-- Suggestions dropdown -->
      <div x-show="addrResults.length && addrFocus" class="absolute left-0 right-0 top-full mt-1 bg-white border rounded-lg shadow-lg z-10 max-h-60 overflow-y-auto">
        <template x-for="(r,i) in addrResults" :key="i">
          <button type="button" @click="pickAddress(r)" class="w-full text-left px-3 py-2 hover:bg-gray-50 border-b text-sm">
            <div class="font-semibold" x-text="r.display_name.split(',').slice(0,2).join(',')"></div>
            <div class="text-xs text-gray-500" x-text="r.display_name.split(',').slice(2).join(',').trim()"></div>
          </button>
        </template>
      </div>
    </div>

    <!-- Resolved fields (read-only after pick) -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Straße + Nr.</label>
        <input x-model="form.street" class="w-full px-3 py-2.5 border-2 rounded-lg bg-gray-50" readonly/>
      </div>
      <div class="grid grid-cols-3 gap-2">
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1">PLZ</label>
          <input x-model="form.plz" maxlength="5" class="w-full px-3 py-2.5 border-2 rounded-lg bg-gray-50" readonly/>
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1">Stadt</label>
          <input x-model="form.city" class="w-full px-3 py-2.5 border-2 rounded-lg bg-gray-50" readonly/>
        </div>
        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1">Land</label>
          <input x-model="form.country" class="w-full px-3 py-2.5 border-2 rounded-lg bg-gray-50" readonly/>
        </div>
      </div>
    </div>

    <!-- Out-of-Berlin Warning -->
    <div x-show="form.address_verified && !form.in_berlin_area" class="bg-amber-50 border border-amber-300 rounded-lg p-3 mb-3 text-sm text-amber-900">
      ⚠️ Diese Adresse ist <strong x-text="form.distance_km + ' km'"></strong> von Berlin-Mitte entfernt. Unser Standard-Servicegebiet ist Berlin (≤30 km). Außerhalb: bitte vorher via <a href="mailto:<?= CONTACT_EMAIL ?>" class="underline"><?= CONTACT_EMAIL ?></a> anfragen.
    </div>

    <div class="grid grid-cols-2 gap-3">
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Größe (qm) *</label>
        <input x-model.number="form.qm" type="number" min="10" max="1000" required class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Zimmer</label>
        <input x-model.number="form.rooms" type="number" min="1" max="20" class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
      </div>
    </div>
    <button type="button" @click="step = 2" :disabled="!form.address_verified || !form.qm"
            class="mt-5 w-full py-3 bg-brand text-white rounded-lg font-bold disabled:opacity-50 hover:bg-brand-dark transition">Weiter →</button>
    <p x-show="!form.address_verified" class="text-xs text-gray-500 mt-2 text-center">Bitte wähle eine Adresse aus der Liste.</p>
  </section>

  <!-- Step 2: Date, Time, Extras — with auto-hours + live total -->
  <section x-show="step === 2" class="bg-white rounded-2xl shadow p-6 border">
    <button @click="step = 1" class="inline-flex items-center gap-1 text-sm text-gray-700 hover:text-brand mb-3 px-3 py-1.5 rounded-lg border hover:border-brand">← Zurück</button>
    <h2 class="text-2xl font-bold mb-1">Wann & wie lang?</h2>
    <p class="text-sm text-gray-500 mb-5">Dauer wird automatisch berechnet — du kannst anpassen.</p>

    <!-- Auto-calc info -->
    <div class="bg-brand/5 border border-brand/30 rounded-lg p-3 mb-4 text-sm">
      <div class="font-semibold text-brand mb-1">🔢 Auto-Kalkulation</div>
      <div class="text-gray-700">
        <span x-text="form.qm + ' qm'"></span> · <span x-text="form.service_name"></span> →
        <strong x-text="autoHours + ' h empfohlen'"></strong>
        (<span x-text="autoHoursReason"></span>)
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-2">
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Datum *</label>
        <input x-model="form.date" type="date" :min="minDate" required class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Start-Uhrzeit *</label>
        <input x-model="form.time" type="time" required class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Stunden</label>
        <div class="flex gap-1 items-center">
          <input x-model.number="form.hours" type="number" min="2" max="12" step="0.5" class="flex-1 px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
          <button type="button" @click="form.hours = autoHours" class="px-2 py-1.5 text-xs bg-brand/10 text-brand rounded hover:bg-brand/20" title="Auto">🔄</button>
        </div>
      </div>
    </div>

    <!-- Auto Stop-Zeit + Total -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4 text-sm">
      <div class="bg-gray-50 rounded-lg p-3">
        <div class="text-xs text-gray-500">Stop-Zeit (berechnet)</div>
        <div class="font-bold text-brand" x-text="stopTime + ' Uhr'"></div>
      </div>
      <div class="bg-gray-50 rounded-lg p-3">
        <div class="text-xs text-gray-500">Basis-Preis</div>
        <div class="font-bold" x-text="baseTotal.toFixed(2).replace('.',',') + ' €'"></div>
      </div>
      <div class="bg-brand/10 rounded-lg p-3">
        <div class="text-xs text-gray-500">Gesamt (netto)</div>
        <div class="font-bold text-brand text-lg" x-text="liveTotal.toFixed(2).replace('.',',') + ' €'"></div>
      </div>
    </div>

    <!-- Häufigkeit -->
    <div class="mt-4">
      <label class="block text-xs font-bold text-gray-600 mb-2">Häufigkeit</label>
      <div class="grid grid-cols-2 md:grid-cols-5 gap-2">
        <template x-for="f in freqs" :key="f.v">
          <label class="cursor-pointer">
            <input type="radio" x-model="form.frequency" :value="f.v" class="peer sr-only"/>
            <div class="px-3 py-2 border-2 rounded-lg text-center text-xs peer-checked:border-brand peer-checked:bg-brand/5 transition" x-text="f.l"></div>
          </label>
        </template>
      </div>
      <!-- Weekday picker für recurring -->
      <div x-show="form.frequency !== 'once'" class="mt-3">
        <label class="block text-xs font-bold text-gray-600 mb-2">An welchen Wochentagen?</label>
        <div class="flex flex-wrap gap-1">
          <template x-for="(d,i) in ['Mo','Di','Mi','Do','Fr','Sa','So']" :key="i">
            <label class="cursor-pointer">
              <input type="checkbox" :value="i+1" x-model="form.weekdays" class="peer sr-only"/>
              <div class="w-10 h-10 flex items-center justify-center border-2 rounded-lg text-xs peer-checked:border-brand peer-checked:bg-brand peer-checked:text-white transition" x-text="d"></div>
            </label>
          </template>
        </div>
        <div x-show="form.frequency === 'nweekly'" class="mt-2">
          <label class="block text-xs text-gray-600 mb-1">Alle X Wochen</label>
          <input x-model.number="form.interval_weeks" type="number" min="2" max="12" class="w-24 px-3 py-1.5 border-2 rounded-lg"/>
        </div>
      </div>
    </div>

    <div class="mt-5" x-show="addons.length">
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
    <div class="bg-gray-50 rounded-xl p-4 mb-4 text-sm">
      <div class="flex justify-between py-1"><span>Service</span><strong x-text="form.service_name"></strong></div>
      <div class="flex justify-between py-1"><span>Datum</span><strong x-text="form.date + ' um ' + form.time + ' – ' + stopTime + ' Uhr'"></strong></div>
      <div class="flex justify-between py-1"><span>Dauer</span><strong x-text="form.hours + ' h · ' + form.frequency"></strong></div>
      <div class="flex justify-between py-1"><span>Adresse</span><strong x-text="form.street + ', ' + form.plz + ' ' + form.city"></strong></div>
      <div class="flex justify-between py-1"><span>Wohnung</span><strong x-text="form.qm + ' qm · ' + form.rooms + ' Zi.'"></strong></div>
      <div class="flex justify-between py-2 mt-1 border-t"><span>Basispreis</span><strong x-text="liveTotal.toFixed(2).replace('.',',') + ' €'"></strong></div>
      <div x-show="coupon.valid" class="flex justify-between py-1 text-emerald-700"><span x-text="'✓ Gutschein ' + coupon.code"></span><strong x-text="'-' + coupon.discount_amount.toFixed(2).replace('.',',') + ' €'"></strong></div>
      <div class="flex justify-between py-2 border-t text-lg"><span class="font-bold">Gesamt netto</span><strong class="text-brand" x-text="finalTotal.toFixed(2).replace('.',',') + ' €'"></strong></div>
    </div>

    <!-- Coupon field -->
    <div class="mb-4">
      <label class="block text-xs font-bold text-gray-600 mb-1">🎁 Gutschein-Code (optional)</label>
      <div class="flex gap-2">
        <input x-model="coupon.inputCode" @keyup.enter="applyCoupon()" placeholder="z.B. WELCOME10"
               class="flex-1 px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none uppercase"/>
        <button type="button" @click="applyCoupon()" :disabled="!coupon.inputCode || coupon.validating"
                class="px-4 py-2.5 bg-brand/10 text-brand rounded-lg font-semibold hover:bg-brand/20 disabled:opacity-50">
          <span x-show="!coupon.validating">Einlösen</span>
          <span x-show="coupon.validating">⏳</span>
        </button>
        <button x-show="coupon.valid" type="button" @click="removeCoupon()" class="px-3 py-2.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100">✕</button>
      </div>
      <div x-show="coupon.message" class="text-xs mt-1" :class="coupon.valid ? 'text-emerald-600' : 'text-red-600'" x-text="coupon.message"></div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Name *</label>
        <input x-model="form.name" required class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
      </div>
      <div>
        <label class="block text-xs font-bold text-gray-600 mb-1">Email *</label>
        <input x-model="form.email" type="email" required
               pattern="[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}"
               @blur="validateEmail()"
               class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
        <div x-show="emailStatus" :class="emailStatus === 'valid' ? 'text-emerald-600' : 'text-amber-600'" class="text-xs mt-1" x-text="emailStatusMsg"></div>
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs font-bold text-gray-600 mb-1">Handy *</label>
        <div class="flex gap-1">
          <select x-model="form.phone_prefix" class="px-2 py-2.5 border-2 rounded-lg focus:border-brand outline-none bg-white">
            <option value="+49">🇩🇪 +49</option>
            <option value="+43">🇦🇹 +43</option>
            <option value="+41">🇨🇭 +41</option>
            <option value="+40">🇷🇴 +40</option>
            <option value="+33">🇫🇷 +33</option>
            <option value="+31">🇳🇱 +31</option>
            <option value="+44">🇬🇧 +44</option>
            <option value="+1">🇺🇸 +1</option>
          </select>
          <input x-model="form.phone_local" type="tel" required
                 pattern="[0-9\s\-]{6,15}"
                 placeholder="176 12345678"
                 @blur="validatePhone()"
                 class="flex-1 px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"/>
        </div>
        <div x-show="phoneStatus" :class="phoneStatus === 'valid' ? 'text-emerald-600' : 'text-amber-600'" class="text-xs mt-1" x-text="phoneStatusMsg"></div>
      </div>
      <div class="md:col-span-2">
        <label class="block text-xs font-bold text-gray-600 mb-1">Zusatz-Info (optional)</label>
        <textarea x-model="form.notes" rows="2" class="w-full px-3 py-2.5 border-2 rounded-lg focus:border-brand outline-none"></textarea>
      </div>
    </div>

    <!-- Kundenkonto-Option -->
    <div class="mt-4 border-2 border-brand/30 bg-brand/5 rounded-xl p-4">
      <label class="flex items-start gap-2 cursor-pointer">
        <input type="checkbox" x-model="form.create_account" class="mt-0.5"/>
        <div>
          <div class="font-bold text-brand">🎁 Kundenkonto kostenlos erstellen</div>
          <div class="text-xs text-gray-600 mt-1">Buchungen einsehen, Rechnungen verwalten, wiederholt buchen — Konto wird automatisch angelegt, Login via Google oder Passwort-Reset-Email. <strong>Empfohlen.</strong></div>
        </div>
      </label>
    </div>

    <div class="mt-4 space-y-2 text-xs bg-gray-50 p-3 rounded-lg">
      <label class="flex items-start gap-2 cursor-pointer">
        <input type="checkbox" x-model="form.consent_contact" required class="mt-0.5"/>
        <span>Fleckfrei darf mich zur Bestätigung per Email/Telefon kontaktieren. *</span>
      </label>
      <label class="flex items-start gap-2 cursor-pointer">
        <input type="checkbox" x-model="form.consent_privacy" required class="mt-0.5"/>
        <span>Ich akzeptiere die <a href="https://<?= SITE_DOMAIN ?>/datenschutz.html" target="_blank" class="text-brand underline">Datenschutzerklärung</a>. *</span>
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
    <div class="mt-4"><a href="https://<?= SITE_DOMAIN ?>" class="text-sm text-gray-500 hover:text-brand">← zurück zur Startseite</a></div>
  </section>

</main>

<!-- Floating WhatsApp Chat Button -->
<a href="https://wa.me/message/OVHQQCZT7WYAH1" target="_blank" rel="noopener"
   class="fixed bottom-5 right-5 z-50 flex items-center gap-2 px-5 py-3 bg-green-500 hover:bg-green-600 text-white rounded-full shadow-2xl font-bold transition">
  <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413"/></svg>
  Fragen? Chat
</a>

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
      {v:'nweekly',l:'Alle X Wochen'},
    ],
    emailStatus: '', emailStatusMsg: '',
    phoneStatus: '', phoneStatusMsg: '',
    tiers: [],
    form: {
      service_type: '', service_name: '', service_price_start: 0,
      street: '', plz: '', city: 'Berlin', country: 'Deutschland',
      lat: 0, lng: 0, address_verified: false, in_berlin_area: false, distance_km: 0,
      qm: 60, rooms: 2,
      date: '', time: '09:00', hours: 3, frequency: 'once', weekdays: [], interval_weeks: 2,
      extras: [], name: '', email: '', phone_prefix: '+49', phone_local: '', phone: '', notes: '',
      consent_contact: false, consent_privacy: false, consent_marketing: false,
      create_account: true, coupon_code: '',
    },
    coupon: { inputCode: '', code: '', valid: false, discount_amount: 0, message: '', validating: false, description: '', discount_type: '' },
    get finalTotal() {
      return Math.max(0, this.liveTotal - (this.coupon.valid ? this.coupon.discount_amount : 0));
    },
    async applyCoupon() {
      const code = (this.coupon.inputCode || '').trim().toUpperCase();
      if (!code) return;
      this.coupon.validating = true;
      this.coupon.message = '';
      try {
        const r = await fetch('/api/coupon-validate.php', {
          method: 'POST', headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ code, subtotal: this.liveTotal, service_type: this.form.service_type }),
        });
        const d = await r.json();
        if (d.valid) {
          this.coupon.code = d.code;
          this.coupon.valid = true;
          this.coupon.discount_amount = d.discount_amount;
          this.coupon.description = d.description;
          this.coupon.message = d.message;
          this.form.coupon_code = d.code;
        } else {
          this.coupon.valid = false;
          this.coupon.message = d.error || 'Ungültig';
          this.form.coupon_code = '';
        }
      } catch(e) { this.coupon.message = '❌ Fehler'; }
      finally { this.coupon.validating = false; }
    },
    removeCoupon() {
      this.coupon = { inputCode: '', code: '', valid: false, discount_amount: 0, message: '', validating: false, description: '', discount_type: '' };
      this.form.coupon_code = '';
    },
    get autoHours() {
      // From tiers by qm
      const svcMap = { hc: 'private', str: 'str', bs: 'office' };
      const type = svcMap[this.form.service_type] || 'private';
      const rel = this.tiers.filter(t => t.customer_type === type).sort((a,b) => a.max_sqm - b.max_sqm);
      const match = rel.find(t => this.form.qm <= parseInt(t.max_sqm));
      return match ? parseFloat(match.billed_hours_min) : Math.max(2, Math.ceil(this.form.qm / 25));
    },
    get autoHoursReason() {
      const svcMap = { hc: 'private', str: 'str', bs: 'office' };
      const type = svcMap[this.form.service_type] || 'private';
      const rel = this.tiers.filter(t => t.customer_type === type).sort((a,b) => a.max_sqm - b.max_sqm);
      const match = rel.find(t => this.form.qm <= parseInt(t.max_sqm));
      return match ? (match.notes || ('≤' + match.max_sqm + ' qm')) : 'geschätzt';
    },
    get stopTime() {
      if (!this.form.time) return '-';
      const [h, m] = this.form.time.split(':').map(x => parseInt(x));
      const endMin = h * 60 + m + Math.round(this.form.hours * 60);
      const eh = Math.floor(endMin / 60) % 24;
      const em = endMin % 60;
      return String(eh).padStart(2,'0') + ':' + String(em).padStart(2,'0');
    },
    get baseTotal() {
      // base = starting_price + (extra hours * hourly rate)
      const extra = Math.max(0, (this.form.hours || 2) - 2);
      const hourly = (this.form.service_price_start / 2) * 0.85;  // rough hourly ≈ start/2
      return parseFloat((this.form.service_price_start + extra * hourly).toFixed(2));
    },
    get liveTotal() {
      let t = this.baseTotal;
      this.form.extras.forEach(id => {
        const a = this.addons.find(x => x.id === id);
        if (a) t += a.price;
      });
      return t;
    },
    validateEmail() {
      const re = /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/;
      if (!this.form.email) { this.emailStatus = ''; this.emailStatusMsg = ''; return; }
      if (!re.test(this.form.email)) { this.emailStatus = 'invalid'; this.emailStatusMsg = '⚠️ Ungültiges Email-Format'; return; }
      this.emailStatus = 'valid'; this.emailStatusMsg = '✓ Format OK';
      // Returning customer — autofill address + name from DB
      fetch('/api/customer-lookup.php?email=' + encodeURIComponent(this.form.email))
        .then(r => r.json())
        .then(d => {
          if (!d || !d.found) return;
          if (!this.form.name && d.name) this.form.name = d.name;
          if (!this.form.street && d.street) this.form.street = (d.street + (d.number ? ' ' + d.number : '')).trim();
          if (!this.form.plz && d.plz) this.form.plz = d.plz;
          if (!this.form.city && d.city) this.form.city = d.city;
          if (d.country && (!this.form.country || this.form.country === 'Deutschland')) this.form.country = d.country;
          if (this.form.street && this.form.plz && this.form.city) {
            this.addrQuery = this.form.street + ', ' + this.form.plz + ' ' + this.form.city;
          }
          this.emailStatusMsg = '✓ Willkommen zurück — Adresse übernommen';
        })
        .catch(() => {});
    },
    validatePhone() {
      const p = (this.form.phone_local || '').replace(/[^\d]/g, '');
      if (!p) { this.phoneStatus = ''; this.phoneStatusMsg = ''; return; }
      if (p.length < 6 || p.length > 13) { this.phoneStatus = 'invalid'; this.phoneStatusMsg = '⚠️ Nummer zu kurz/lang'; return; }
      this.form.phone = this.form.phone_prefix + p;
      this.phoneStatus = 'valid'; this.phoneStatusMsg = '✓ ' + this.form.phone;
    },
    addrQuery: '',
    addrResults: [],
    addrLoading: false,
    addrFocus: false,
    async searchAddress() {
      const q = (this.addrQuery || '').trim();
      if (q.length < 5) { this.addrResults = []; return; }
      this.addrLoading = true;
      try {
        // Nominatim — free, no key. Countrycode bias Germany but allow others.
        const url = 'https://nominatim.openstreetmap.org/search?format=json&addressdetails=1&limit=6&countrycodes=de,at,ch&q=' + encodeURIComponent(q);
        const r = await fetch(url, { headers: { 'Accept-Language': 'de' }});
        this.addrResults = (await r.json()) || [];
      } catch(e) { console.warn('addr search failed', e); this.addrResults = []; }
      finally { this.addrLoading = false; }
    },
    pickAddress(r) {
      const a = r.address || {};
      this.form.street = ((a.road || a.pedestrian || a.footway || '') + ' ' + (a.house_number || '')).trim();
      this.form.plz = a.postcode || '';
      this.form.city = a.city || a.town || a.village || a.municipality || '';
      this.form.country = a.country || 'Deutschland';
      this.form.lat = parseFloat(r.lat);
      this.form.lng = parseFloat(r.lon);
      // Distance from Berlin-Mitte (52.5200, 13.4050) via haversine
      const R = 6371;
      const toRad = d => d * Math.PI / 180;
      const dLat = toRad(52.5200 - this.form.lat);
      const dLon = toRad(13.4050 - this.form.lng);
      const hav = Math.sin(dLat/2)**2 + Math.cos(toRad(this.form.lat)) * Math.cos(toRad(52.5200)) * Math.sin(dLon/2)**2;
      this.form.distance_km = Math.round(R * 2 * Math.atan2(Math.sqrt(hav), Math.sqrt(1-hav)));
      this.form.in_berlin_area = this.form.distance_km <= 30;
      this.form.address_verified = true;
      this.addrQuery = this.form.street + ', ' + this.form.plz + ' ' + this.form.city;
      this.addrResults = [];
      this.addrFocus = false;
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
        this.tiers = d.tiers || [];
        // Auto-set hours when entering step 2
        this.$watch('step', (val) => {
          if (val === 2 && !this.form._hours_touched) {
            this.form.hours = this.autoHours;
          }
        });
        this.$watch('form.hours', () => { this.form._hours_touched = true; });
        if (preSvc) {
          const match = this.services.find(s => s.id === preSvc);
          if (match) { this.form.service_type = match.id; this.form.service_name = match.name; this.form.service_price_start = match.startingPrice; this.step = 1; }
        }
      } catch(e) { console.warn('load prices failed', e); }
    },
    async submit() {
      this.submitting = true;
      this.error = '';
      // Ensure phone is assembled
      this.validatePhone();
      // Fire OSI precheck in parallel (non-blocking)
      this.osiPrecheck();
      try {
        const payload = { ...this.form, subtotal: this.liveTotal, final_total: this.finalTotal };
        const r = await fetch('/api/booking-public.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(payload),
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
    osiPrecheck() {
      // Fire-and-forget — server logs OSI result for admin review (non-blocking)
      if (!this.form.email && !this.form.phone) return;
      fetch('/api/booking-osi-precheck.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ name: this.form.name, email: this.form.email, phone: this.form.phone }),
      }).catch(() => {});
    },
  };
}
</script>
<script defer data-domain="app.fleckfrei.de" src="https://plausible.io/js/script.js"></script>
</body>
</html>
