<?php
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
  <title>Jetzt buchen — <?= SITE ?></title>
  <meta name="description" content="<?= SITE ?> — <?= SITE_TAGLINE ?> Buche jetzt online."/>
  <meta name="theme-color" content="<?= BRAND ?>"/>
  <link rel="icon" href="/icons/icon.php?s=32"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{brand:'<?= BRAND ?>','brand-dark':'<?= BRAND_DARK ?>','brand-light':'<?= BRAND_LIGHT ?>'},fontFamily:{sans:['Inter','system-ui','sans-serif']}}}}</script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    body { font-family: 'Inter', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
    .glass { background: rgba(255,255,255,0.85); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
    .card-hover { transition: all 0.3s cubic-bezier(0.4,0,0.2,1); }
    .card-hover:hover { transform: translateY(-4px); box-shadow: 0 20px 40px rgba(0,0,0,0.08); }
    .service-card { position: relative; overflow: hidden; }
    .service-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: <?= BRAND ?>; transform: scaleX(0); transition: transform 0.3s; }
    .service-card.selected::before, .service-card:hover::before { transform: scaleX(1); }
    .fade-up { animation: fadeUp 0.5s ease-out; }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    .input-modern { border: 2px solid #e5e7eb; transition: all 0.2s; }
    .input-modern:focus { border-color: <?= BRAND ?>; box-shadow: 0 0 0 4px rgba(<?= BRAND_RGB ?>,0.1); outline: none; }
    .step-line { height: 2px; transition: background 0.5s; }
    .pulse-dot { animation: pulse 2s infinite; }
    @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
    @media (max-width: 640px) { .hero-title { font-size: 2rem; } }
  </style>
</head>
<body class="bg-gray-50 min-h-screen" x-data="bookingForm()" x-cloak>

<!-- Hero Header -->
<header class="relative overflow-hidden">
  <div class="absolute inset-0 bg-gradient-to-br from-brand via-brand-dark to-emerald-900"></div>
  <div class="absolute inset-0 opacity-10" style="background-image:url('data:image/svg+xml,%3Csvg width=60 height=60 xmlns=%22http://www.w3.org/2000/svg%22%3E%3Cpath d=%22M0 0h60v60H0z%22 fill=%22none%22/%3E%3Cpath d=%22M30 0v60M0 30h60%22 stroke=%22%23fff%22 stroke-width=%220.5%22/%3E%3C/svg%3E')"></div>
  <div class="relative max-w-3xl mx-auto px-6 py-12 sm:py-16">
    <div class="flex items-center gap-3 mb-8">
      <div class="w-12 h-12 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center text-white font-bold text-xl"><?= LOGO_LETTER ?></div>
      <div><span class="text-white font-bold text-lg"><?= SITE ?></span><span class="text-white/60 text-sm ml-2 hidden sm:inline"><?= SITE_TAGLINE ?></span></div>
    </div>
    <h1 class="hero-title text-4xl sm:text-5xl font-extrabold text-white mb-4 leading-tight">Professionelle<br/>Reinigung buchen</h1>
    <p class="text-white/70 text-lg max-w-md">In 60 Sekunden zum sauberen Zuhause. Flexible Termine, faire Preise, verifizierte Partner.</p>
    <!-- Trust badges -->
    <div class="flex items-center gap-4 mt-8">
      <div class="flex items-center gap-1.5 text-white/80 text-sm"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg> 4.9/5 Bewertungen</div>
      <div class="flex items-center gap-1.5 text-white/80 text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg> Versichert</div>
      <div class="flex items-center gap-1.5 text-white/80 text-sm"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Flexible Termine</div>
    </div>
  </div>
</header>

<!-- Progress Bar -->
<div class="sticky top-0 z-50 glass border-b">
  <div class="max-w-3xl mx-auto px-6 py-4">
    <div class="flex items-center justify-between">
      <template x-for="(s,i) in [{n:'Service',icon:'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'},{n:'Details',icon:'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'},{n:'Buchen',icon:'M5 13l4 4L19 7'}]" :key="i">
        <div class="flex items-center gap-2">
          <div class="w-10 h-10 rounded-xl flex items-center justify-center transition-all duration-300"
               :class="step > i ? 'bg-brand text-white shadow-lg shadow-brand/30' : (step === i ? 'bg-brand text-white shadow-lg shadow-brand/30 ring-4 ring-brand/20' : 'bg-gray-100 text-gray-400')">
            <template x-if="step > i"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg></template>
            <template x-if="step <= i"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="s.icon"/></svg></template>
          </div>
          <span class="text-sm font-semibold hidden sm:block" :class="step >= i ? 'text-gray-900' : 'text-gray-400'" x-text="s.n"></span>
        </div>
        <template x-if="i < 2"><div class="flex-1 mx-3 step-line rounded" :class="step > i ? 'bg-brand' : 'bg-gray-200'"></div></template>
      </template>
    </div>
  </div>
</div>

<!-- Main Content -->
<main class="max-w-3xl mx-auto px-6 py-10">

  <!-- Step 0: Service -->
  <div x-show="step===0" x-transition:enter="fade-up" class="fade-up">
    <div class="text-center mb-8">
      <h2 class="text-3xl font-extrabold text-gray-900">Welchen Service brauchst du?</h2>
      <p class="text-gray-500 mt-2">Waehle deinen Reinigungsservice — wir erledigen den Rest.</p>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <template x-for="s in services" :key="s.id">
        <button @click="selectService(s)" class="service-card card-hover bg-white rounded-2xl border-2 p-6 text-left transition-all"
                :class="form.service===s.name ? 'selected border-brand shadow-lg shadow-brand/10' : 'border-gray-100 hover:border-brand/30'">
          <div class="flex items-start justify-between mb-3">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center text-2xl" :style="'background:' + s.color + '15'" x-text="s.emoji"></div>
            <div x-show="form.service===s.name" class="w-6 h-6 rounded-full bg-brand flex items-center justify-center">
              <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
            </div>
          </div>
          <h3 class="font-bold text-lg text-gray-900" x-text="s.name"></h3>
          <p class="text-sm text-gray-500 mt-1" x-text="s.desc"></p>
          <div class="mt-4 flex items-baseline gap-1">
            <span class="text-2xl font-extrabold text-brand" x-text="'ab ' + s.price"></span>
            <span class="text-sm text-gray-400"><?= CURRENCY ?>/h</span>
          </div>
          <div class="flex items-center gap-2 mt-3">
            <span class="px-2 py-0.5 bg-gray-100 rounded text-[11px] text-gray-500" x-text="'ab ' + s.min_hours + 'h'"></span>
            <span class="px-2 py-0.5 bg-gray-100 rounded text-[11px] text-gray-500" x-text="s.tag"></span>
          </div>
        </button>
      </template>
    </div>
  </div>

  <!-- Step 1: Details -->
  <div x-show="step===1" x-transition:enter="fade-up">
    <div class="text-center mb-8">
      <h2 class="text-3xl font-extrabold text-gray-900">Wann und wo?</h2>
      <p class="text-gray-500 mt-2">Sag uns wann und wo wir kommen sollen.</p>
    </div>
    <!-- Selected service summary -->
    <div class="bg-brand/5 rounded-2xl p-4 mb-6 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <span class="text-2xl" x-text="services.find(s=>s.name===form.service)?.emoji || ''"></span>
        <div><span class="font-bold" x-text="form.service"></span><span class="text-sm text-gray-500 ml-2" x-text="form.price_per_hour + ' <?= CURRENCY ?>/h'"></span></div>
      </div>
      <button @click="step=0" class="text-sm text-brand font-medium hover:underline">Aendern</button>
    </div>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 sm:p-8 space-y-5">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div><label class="block text-sm font-semibold text-gray-700 mb-1.5">Dein Name</label>
          <input type="text" x-model="form.name" placeholder="Max Mustermann" class="input-modern w-full px-4 py-3.5 rounded-xl"/></div>
        <div><label class="block text-sm font-semibold text-gray-700 mb-1.5">Telefon</label>
          <input type="tel" x-model="form.phone" placeholder="+49 170 123 4567" class="input-modern w-full px-4 py-3.5 rounded-xl"/></div>
      </div>
      <div><label class="block text-sm font-semibold text-gray-700 mb-1.5">Email</label>
        <input type="email" x-model="form.email" placeholder="deine@email.de" class="input-modern w-full px-4 py-3.5 rounded-xl"/></div>
      <div><label class="block text-sm font-semibold text-gray-700 mb-1.5">Adresse</label>
        <input type="text" x-model="form.address" placeholder="Musterstr. 12, 10115 Berlin" class="input-modern w-full px-4 py-3.5 rounded-xl"/></div>
      <div class="grid grid-cols-2 gap-4">
        <div><label class="block text-sm font-semibold text-gray-700 mb-1.5">Wunschtermin</label>
          <input type="date" x-model="form.date" :min="minDate" class="input-modern w-full px-4 py-3.5 rounded-xl"/></div>
        <div><label class="block text-sm font-semibold text-gray-700 mb-1.5">Uhrzeit</label>
          <select x-model="form.time" class="input-modern w-full px-4 py-3.5 rounded-xl bg-white">
            <option value="08:00">08:00</option><option value="09:00">09:00</option><option value="10:00">10:00</option>
            <option value="11:00">11:00</option><option value="12:00">12:00</option><option value="13:00">13:00</option>
            <option value="14:00">14:00</option><option value="15:00">15:00</option><option value="16:00">16:00</option>
          </select></div>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div><label class="block text-sm font-semibold text-gray-700 mb-1.5">Dauer</label>
          <select x-model="form.hours" class="input-modern w-full px-4 py-3.5 rounded-xl bg-white">
            <option value="2">2 Stunden</option><option value="3">3 Stunden</option><option value="4">4 Stunden</option>
            <option value="5">5 Stunden</option><option value="6">6 Stunden</option><option value="8">Ganzer Tag (8h)</option>
          </select></div>
        <div><label class="block text-sm font-semibold text-gray-700 mb-1.5">Wie oft?</label>
          <select x-model="form.frequency" class="input-modern w-full px-4 py-3.5 rounded-xl bg-white">
            <option value="once">Einmalig</option><option value="weekly">Jede Woche</option>
            <option value="biweekly">Alle 2 Wochen</option><option value="monthly">Monatlich</option>
          </select></div>
      </div>
      <div><label class="block text-sm font-semibold text-gray-700 mb-1.5">Besondere Wuensche <span class="text-gray-400 font-normal">(optional)</span></label>
        <textarea x-model="form.notes" rows="2" placeholder="z.B. Haustiere im Haushalt, bestimmte Bereiche..." class="input-modern w-full px-4 py-3.5 rounded-xl resize-none"></textarea></div>
    </div>
  </div>

  <!-- Step 2: Summary + Book -->
  <div x-show="step===2" x-transition:enter="fade-up">
    <div class="text-center mb-8">
      <h2 class="text-3xl font-extrabold text-gray-900">Fast geschafft!</h2>
      <p class="text-gray-500 mt-2">Pruefe deine Angaben und buche.</p>
    </div>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden mb-6">
      <div class="bg-gradient-to-r from-brand to-brand-dark p-5 text-white">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span class="text-3xl" x-text="services.find(s=>s.name===form.service)?.emoji || ''"></span>
            <div><div class="font-bold text-lg" x-text="form.service"></div><div class="text-white/70 text-sm" x-text="form.hours + ' Stunden'"></div></div>
          </div>
          <div class="text-right"><div class="text-3xl font-extrabold" x-text="totalBrutto + ' <?= CURRENCY ?>'"></div><div class="text-white/60 text-xs">inkl. MwSt</div></div>
        </div>
      </div>
      <div class="p-5 space-y-3 text-sm">
        <div class="flex items-center gap-3"><svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg><span x-text="form.name + ' — ' + form.email"></span></div>
        <div class="flex items-center gap-3"><svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg><span x-text="form.phone"></span></div>
        <div class="flex items-center gap-3"><svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg><span x-text="form.address"></span></div>
        <div class="flex items-center gap-3"><svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg><span x-text="formatDate(form.date) + ' um ' + form.time + ' Uhr'"></span></div>
        <template x-if="form.frequency !== 'once'"><div class="flex items-center gap-3"><svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg><span x-text="freqLabel"></span></div></template>
      </div>
      <div class="border-t px-5 py-4 bg-gray-50 flex items-center justify-between">
        <div class="text-xs text-gray-400">Netto: <span x-text="totalPrice + ' <?= CURRENCY ?>'"></span></div>
        <div class="text-xs text-gray-400">MwSt (19%): <span x-text="(totalBrutto - totalPrice).toFixed(2) + ' <?= CURRENCY ?>'"></span></div>
      </div>
    </div>

    <!-- Book Button -->
    <button @click="submitBooking('invoice')" :disabled="paying"
            class="w-full py-5 bg-gradient-to-r from-brand to-brand-dark text-white rounded-2xl font-bold text-xl hover:shadow-xl hover:shadow-brand/20 transition-all duration-300 disabled:opacity-50 flex items-center justify-center gap-3">
      <template x-if="!paying">
        <span class="flex items-center gap-2"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Jetzt verbindlich buchen</span>
      </template>
      <template x-if="paying">
        <span class="flex items-center gap-2"><svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg> Wird gebucht...</span>
      </template>
    </button>
    <p class="text-center text-xs text-gray-400 mt-3">Zahlung nach Service — keine Vorauszahlung noetig</p>
    <?php if (FEATURE_STRIPE || FEATURE_PAYPAL): ?>
    <div class="flex items-center gap-3 justify-center mt-4">
      <?php if (FEATURE_STRIPE): ?><button @click="submitBooking('stripe')" class="px-5 py-2.5 border-2 border-gray-200 rounded-xl text-sm font-medium text-gray-600 hover:border-brand/30 transition">Mit Karte bezahlen</button><?php endif; ?>
      <?php if (FEATURE_PAYPAL): ?><button @click="submitBooking('paypal')" class="px-5 py-2.5 border-2 border-gray-200 rounded-xl text-sm font-medium text-gray-600 hover:border-brand/30 transition">PayPal</button><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Step 3: Success -->
  <div x-show="step===3" x-transition:enter="fade-up">
    <div class="text-center py-8">
      <div class="w-20 h-20 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-6" style="animation: fadeUp 0.5s ease-out">
        <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
      </div>
      <h2 class="text-3xl font-extrabold text-gray-900 mb-2">Buchung bestaetigt!</h2>
      <p class="text-gray-500 text-lg mb-8">Wir melden uns innerhalb von 2 Stunden bei dir.</p>
      <div class="bg-white rounded-2xl border p-6 text-left max-w-sm mx-auto mb-8">
        <div class="space-y-2 text-sm">
          <div class="flex justify-between"><span class="text-gray-500">Buchungs-Nr.</span><span class="font-mono font-bold text-brand" x-text="'#' + bookingId"></span></div>
          <div class="flex justify-between"><span class="text-gray-500">Service</span><span class="font-medium" x-text="form.service"></span></div>
          <div class="flex justify-between"><span class="text-gray-500">Termin</span><span x-text="formatDate(form.date) + ', ' + form.time"></span></div>
          <div class="flex justify-between border-t pt-2 mt-2"><span class="font-bold">Betrag</span><span class="font-bold text-brand" x-text="totalBrutto + ' <?= CURRENCY ?>'"></span></div>
        </div>
      </div>
      <div class="flex items-center justify-center gap-4">
        <a href="/book.php" class="px-6 py-3 bg-brand text-white rounded-xl font-semibold hover:opacity-90 transition">Weitere Buchung</a>
        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', CONTACT_WA ?: '') ?>" target="_blank" class="px-6 py-3 border-2 border-gray-200 rounded-xl font-semibold text-gray-600 hover:border-brand/30 transition">WhatsApp</a>
      </div>
    </div>
  </div>

  <!-- Navigation -->
  <div class="flex justify-between mt-8" x-show="step > 0 && step < 3">
    <button @click="step--" class="px-6 py-3 rounded-xl font-semibold text-gray-500 hover:bg-gray-100 transition flex items-center gap-2">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg> Zurueck
    </button>
    <button @click="nextStep()" x-show="step < 2" :disabled="!canNext"
            class="px-8 py-3 bg-brand text-white rounded-xl font-bold hover:shadow-lg hover:shadow-brand/20 transition-all disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-2">
      Weiter <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </button>
  </div>
</main>

<!-- Footer -->
<footer class="border-t mt-16 py-8 text-center">
  <p class="text-sm text-gray-400">&copy; <?= date('Y') ?> <?= SITE ?> &mdash; <a href="https://<?= SITE_DOMAIN ?>" class="text-brand hover:underline"><?= SITE_DOMAIN ?></a></p>
</footer>

<script>
function bookingForm() {
  return {
    step: 0, paying: false, bookingId: '',
    services: [
      {id:'std', name:'Standardreinigung', price:35, min_hours:2, emoji:'&#128171;', color:'#2E7D6B', desc:'Wohnung, Haus oder Buero — regelmaessig oder einmalig.', tag:'Am beliebtesten'},
      {id:'grd', name:'Grundreinigung', price:45, min_hours:3, emoji:'&#10024;', color:'#7c3aed', desc:'Tiefenreinigung nach Umzug, Renovierung oder Fruehjahrputz.', tag:'Intensiv'},
      {id:'fen', name:'Fensterreinigung', price:40, min_hours:2, emoji:'&#129695;', color:'#0ea5e9', desc:'Alle Fenster innen + aussen, Rahmen und Fensterbaenke.', tag:'Innen + Aussen'},
      {id:'bue', name:'Bueroreinigung', price:38, min_hours:2, emoji:'&#127970;', color:'#f59e0b', desc:'Professionelle Buero- und Gewerbereinigung.', tag:'Gewerbe'},
    ],
    form: { service:'', price_per_hour:0, name:'', phone:'', email:'', address:'', date:'', time:'09:00', hours:'3', frequency:'once', notes:'' },
    get minDate() { var d=new Date(); d.setDate(d.getDate()+1); return d.toISOString().slice(0,10); },
    get totalPrice() { return (this.form.price_per_hour * parseInt(this.form.hours)).toFixed(2); },
    get totalBrutto() { return (this.totalPrice * 1.19).toFixed(2); },
    get freqLabel() { return {once:'Einmalig',weekly:'Jede Woche',biweekly:'Alle 2 Wochen',monthly:'Monatlich'}[this.form.frequency]; },
    get canNext() {
      if (this.step===0) return !!this.form.service;
      if (this.step===1) return this.form.name && this.form.phone && this.form.email && this.form.address && this.form.date;
      return true;
    },
    formatDate(d) { if(!d) return ''; var p=d.split('-'); return p[2]+'.'+p[1]+'.'+p[0]; },
    selectService(s) { this.form.service=s.name; this.form.price_per_hour=s.price; this.step=1; },
    nextStep() { if(this.canNext) this.step++; },
    async submitBooking(method) {
      this.paying = true;
      try {
        var resp = await fetch('/api/index.php?action=webhook/booking', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({
            name:this.form.name, phone:this.form.phone, email:this.form.email,
            address:this.form.address, service:this.form.service,
            date:this.form.date, time:this.form.time, hours:parseInt(this.form.hours),
            frequency:this.form.frequency, notes:this.form.notes,
            platform:'website', customer_type:'private',
            payment_method:method, amount:parseFloat(this.totalBrutto)
          })
        });
        var d = await resp.json();
        this.paying = false;
        if(d.success) { this.bookingId = d.data?.booking_id || d.data?.j_id || Math.floor(Math.random()*9000+1000); this.step=3; }
        else alert(d.error || 'Fehler');
      } catch(e) { this.paying=false; alert('Netzwerkfehler'); }
    }
  };
}
</script>
</body>
</html>
