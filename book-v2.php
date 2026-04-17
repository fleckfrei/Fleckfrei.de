<?php
require_once __DIR__ . '/includes/config.php';
// Preis-Konstanten kommen zentral aus includes/pricing.php — single source of truth.

// Prebooking-Link-Prefill (via /prebook.php?t=TOKEN → redirectet hierher)
$prefill = [
    'email'    => trim($_GET['email']   ?? ''),
    'name'     => trim($_GET['name']    ?? ''),
    'phone'    => trim($_GET['phone']   ?? ''),
    'street'   => trim($_GET['street']  ?? ''),
    'plz'      => trim($_GET['plz']     ?? ''),
    'city'     => trim($_GET['city']    ?? 'Berlin'),
    'service'  => in_array($_GET['service'] ?? '', ['home_care','str','office'], true) ? $_GET['service'] : 'home_care',
    'hours'    => max(2, min(12, (int)($_GET['hours'] ?? 2))),
    'voucher'  => strtoupper(trim($_GET['voucher'] ?? '')),
    'pb_token' => trim($_GET['pb']      ?? ''),
    'district' => trim($_GET['district']?? ''),
    'rate'     => (float)($_GET['rate'] ?? 0),
    'tickets'  => max(0, (int)($_GET['tickets'] ?? 0)),
    'tix_p'    => max(0, (float)($_GET['tix_p'] ?? 3.80)),
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Termin buchen · Fleckfrei</title>
<meta name="theme-color" content="<?= BRAND ?>"/>
<link rel="icon" href="https://fleckfrei.de/img/logo/favicon.svg"/>
<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<script>
tailwind.config = { theme: { extend: { colors: { brand: '<?= BRAND ?>', 'brand-dark': '<?= BRAND_DARK ?>', 'brand-light': '<?= BRAND_LIGHT ?>' }, fontFamily: { sans: ['Inter','sans-serif'] } } } }
</script>
<style>
  body{font-family:'Inter',sans-serif;-webkit-font-smoothing:antialiased}
  [x-cloak]{display:none !important}
  @keyframes pulseSlow { 0%,100% { transform: scale(1); } 50% { transform: scale(1.04); } }
  .animate-pulse-slow { animation: pulseSlow 1.6s ease-in-out infinite; display: inline-block; }
  .slot{transition:all .15s ease}
  .slot-free{background:#fff;border:1px solid #E5E7EB;color:#111827;cursor:pointer}
  .slot-free:hover{border-color:<?= BRAND ?>;background:<?= BRAND_LIGHT ?>;color:<?= BRAND_DARK ?>}
  .slot-busy{background:#F9FAFB;border:1px solid #E5E7EB;color:#9CA3AF;cursor:not-allowed}
  .slot-past{background:#FAFAFA;border:1px solid #F3F4F6;color:#D1D5DB;cursor:not-allowed}
  .slot-selected{background:<?= BRAND ?> !important;border-color:<?= BRAND ?> !important;color:#fff !important}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;box-shadow:0 1px 2px rgba(0,0,0,0.04)}
</style>
</head>
<body class="bg-gray-50 text-gray-900">

<!-- Top-Nav -->
<header class="bg-white border-b sticky top-0 z-30">
  <div class="max-w-7xl mx-auto px-4 lg:px-6 py-3 flex items-center justify-between gap-4">
    <a href="https://fleckfrei.de" class="font-bold text-xl text-brand flex-shrink-0">Fleckfrei</a>
    <nav class="hidden lg:flex items-center gap-6 text-sm font-medium text-gray-600">
      <a href="https://fleckfrei.de" class="hover:text-brand">Home</a>
      <a href="https://fleckfrei.de/#service" class="hover:text-brand">Reinigung</a>
      <a href="https://fleckfrei.de/#preise" class="hover:text-brand">Services</a>
      <a href="#details" class="hover:text-brand">Details</a>
      <a href="#code" class="hover:text-brand">Check-Code</a>
      <a href="#kontakt" class="hover:text-brand">Kontakt</a>
      <a href="#" class="px-3 py-1.5 rounded-lg bg-brand text-white hover:bg-brand-dark">Jetzt buchen</a>
    </nav>
    <a href="/login.php" class="text-sm font-medium text-gray-600 hover:text-brand lg:hidden">Login</a>
  </div>
</header>

<main class="max-w-7xl mx-auto px-4 lg:px-6 py-6" x-data="bookingV2()" x-init="init()" x-cloak>

  <!-- Info banner: all data in one place -->
  <div class="card mb-4 flex items-start gap-3 border-l-4 border-brand bg-brand-light/40">
    <div class="w-8 h-8 rounded-full bg-brand text-white flex items-center justify-center flex-shrink-0">✓</div>
    <div class="text-sm text-gray-700">
      <b>Daten automatisch übernommen.</b> Service, Adresse, Zugangscode, Dauer und andere werden hier dynamisch übernommen. Du kannst Datum und ggf. Uhrzeit nachträglich ändern — und auf Wunsch ggf. zusätzliche Leistungen hinzufügen oder wegnehmen.
    </div>
  </div>

  <!-- HERO / Service-Cards — Wow-Effekt · versteckt wenn Prebook-Link (Service ist schon festgelegt) -->
  <section id="services" class="mb-6" <?= !empty($prefill['pb_token']) ? 'style="display:none"' : '' ?>>
    <div class="text-center mb-6">
      <h1 class="text-3xl md:text-4xl font-extrabold text-gray-900">Drei Welten. <span class="text-brand">Ein Standard.</span></h1>
      <p class="text-gray-500 mt-2 text-sm md:text-base">Ob Zuhause, im Office oder zwischen zwei Gästen — wir liefern Qualität, die man sieht.</p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <?php foreach (['home_care','str','office'] as $_svc):
        $meta  = fleckfrei_service_meta($_svc);
        $price = fleckfrei_price($_svc, 2);
      ?>
      <button type="button"
              @click="selectService('<?= $_svc ?>')"
              class="card text-left transition relative overflow-hidden hover:shadow-lg hover:-translate-y-0.5"
              :class="form.service_type === '<?= $_svc ?>' ? 'border-brand ring-2 ring-brand/40' : 'border-gray-200'">
        <?php if ($meta['badge']): ?>
          <div class="absolute top-0 right-0 bg-brand text-white text-[10px] font-bold px-3 py-1 rounded-bl-lg"><?= e($meta['badge']) ?></div>
        <?php endif; ?>
        <div class="flex items-start justify-between mb-3">
          <span class="text-3xl font-extrabold text-gray-200"><?= $meta['num'] ?></span>
          <span class="text-3xl"><?= $meta['icon'] ?></span>
        </div>
        <h3 class="font-bold text-lg"><?= e($meta['label']) ?></h3>
        <div class="text-xs text-gray-500 mb-3"><?= e($meta['tag']) ?></div>
        <div class="mb-3">
          <div class="text-2xl font-extrabold text-brand">
            ab <?= number_format($meta['display_mode']==='brutto' ? $price['gross'] : $price['net'], 2, ',', '.') ?> EUR
          </div>
          <div class="text-[11px] text-gray-400"><?= $meta['display_mode']==='brutto' ? 'inkl. 19% MwSt.' : 'netto zzgl. 19% MwSt.' ?></div>
        </div>
        <ul class="space-y-1.5 text-xs text-gray-700">
          <?php foreach ($meta['features'] as $f): ?>
            <li class="flex items-start gap-1.5"><span class="text-brand mt-0.5">✓</span><span><?= e($f) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </button>
      <?php endforeach; ?>
    </div>
    <div class="flex items-center justify-center gap-6 mt-5 text-[11px] text-gray-500">
      <span class="flex items-center gap-1">🔒 DSGVO-konform</span>
      <span class="flex items-center gap-1">⚡ Antwort &lt; 2h</span>
      <span class="flex items-center gap-1">💸 Bis 2h vor Termin kostenlos stornieren</span>
      <span class="flex items-center gap-1">⭐ Zufriedenheitsgarantie</span>
    </div>
  </section>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- LEFT: Details -->
    <div class="lg:col-span-2 space-y-6">

      <!-- Card 2: Details -->
      <section class="card" id="details">
        <h2 class="text-xl font-bold mb-4">2. Details der Reinigung</h2>
        <div class="mb-3 p-3 bg-brand-light/60 rounded-lg border border-brand/20 text-sm flex items-center gap-2">
          <span class="text-xl" x-text="currentMeta().icon"></span>
          <span><b x-text="currentMeta().label"></b> · <span class="text-gray-500" x-text="currentMeta().tag"></span></span>
          <span x-show="form.custom_rate && form.custom_rate > 0" class="ml-auto text-xs font-semibold text-brand-dark bg-white px-2 py-0.5 rounded" x-text="money(form.custom_rate) + '/h · persönlich'"></span>
          <a x-show="!form.pb_token" href="#services" class="ml-auto text-xs text-brand hover:underline">Service ändern</a>
        </div>

        <!-- Customer-Apartments: nur wenn pb_token + services vorhanden -->
        <div x-show="form.pb_token && myServices.length > 0" x-cloak class="mb-4 p-3 bg-white border-2 border-brand/30 rounded-xl">
          <label class="block text-xs font-bold text-gray-700 mb-2">🏨 Welche Ihrer Unterkünfte? (<span x-text="myServices.length"></span>)</label>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <template x-for="s in myServices" :key="s.id">
              <button type="button" @click="selectMyService(s)"
                      class="text-left px-3 py-2.5 rounded-lg border transition"
                      :class="form.selected_service_id === s.id ? 'border-brand bg-brand-light ring-2 ring-brand/30' : 'border-gray-200 hover:border-brand/60 hover:bg-gray-50'">
                <div class="font-semibold text-sm" x-text="s.title"></div>
                <div class="text-[10px] text-gray-500 mt-0.5" x-text="s.address || '—'"></div>
                <div class="flex items-center justify-between mt-1">
                  <div class="text-[10px] text-gray-400">
                    <span x-show="s.qm" x-text="'📐 ' + s.qm + 'm²'"></span>
                    <span x-show="s.room" class="ml-1" x-text="'🛏 ' + s.room"></span>
                    <span x-show="s.max_guests" class="ml-1" x-text="'👥 ' + s.max_guests"></span>
                  </div>
                  <div class="text-xs font-bold text-brand" x-text="money(s.hourly_gross) + '/h'"></div>
                </div>
              </button>
            </template>
          </div>
          <div x-show="form.selected_service_id" class="mt-2 text-[11px] text-brand">✓ Adresse, Preis & Klingelname werden automatisch übernommen</div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
          <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Häufigkeit</label>
            <select x-model="form.frequency" class="w-full px-3 py-2.5 border rounded-lg bg-white">
              <option value="once">Einmalig</option>
              <option value="weekly">Wöchentlich</option>
              <option value="biweekly">Alle 2 Wochen</option>
              <option value="monthly">Monatlich</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Dauer (Stunden)</label>
            <select x-model.number="form.duration" @change="loadSlots(); loadCalendar()" class="w-full px-3 py-2.5 border rounded-lg bg-white">
              <template x-for="n in [2,3,4,5,6,7,8]"><option :value="n" x-text="n + ' Stunden'"></option></template>
            </select>
          </div>
          <div x-show="form.service_type === 'str'">
            <label class="block text-xs font-bold text-gray-600 mb-1">Gäste (Personen)</label>
            <input type="number" x-model.number="form.guests" min="1" max="20" class="w-full px-3 py-2.5 border rounded-lg"/>
          </div>
        </div>

        <div class="mb-4">
          <label class="block text-xs font-bold text-gray-600 mb-1">Datum</label>
          <input type="date" x-model="form.date" :min="minDate" @change="loadSlots()" class="w-full md:w-64 px-3 py-2.5 border rounded-lg"/>
        </div>

        <!-- MONATS-HEATMAP: kompakt, mit Monats-Split -->
        <div class="mb-4 border rounded-xl bg-gray-50/30 p-3">
          <div class="flex items-center justify-between mb-2">
            <label class="text-xs font-bold text-gray-700">📅 Nächste 4 Wochen · grün klicken</label>
            <div class="flex gap-3 text-[10px] items-center">
              <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-brand"></span>Partner verfügbar</span>
              <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-brand-light border border-brand/30"></span>Teils</span>
              <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded bg-gray-100 border border-gray-200"></span>Ausgebucht</span>
            </div>
          </div>
          <div x-show="loadingCal" class="text-xs text-gray-400 py-3 text-center">Lade Kalender…</div>
          <template x-if="!loadingCal && calWeeks.length">
            <div>
              <div class="grid grid-cols-7 gap-1 mb-1 text-center text-[9px] text-gray-400 font-semibold uppercase tracking-wider">
                <div>Mo</div><div>Di</div><div>Mi</div><div>Do</div><div>Fr</div><div class="text-amber-600">Sa</div><div class="text-amber-600">So</div>
              </div>
              <template x-for="(week, wi) in calWeeks" :key="wi">
                <div class="grid grid-cols-7 gap-1 mb-1">
                  <template x-for="(day, di) in week" :key="di">
                    <div>
                      <template x-if="day">
                        <button type="button"
                                @click="if(day.status !== 'past' && day.status !== 'busy') { form.date = day.date; loadSlots(); }"
                                :disabled="day.status === 'past' || day.status === 'busy'"
                                class="w-full h-9 rounded text-[10px] font-semibold flex flex-col items-center justify-center relative leading-tight transition"
                                :class="{
                                  'bg-brand text-white hover:bg-brand-dark shadow-sm': day.status === 'free' && form.date !== day.date,
                                  'bg-brand-light text-brand-dark border border-brand/30 hover:bg-brand/20': day.status === 'limited' && form.date !== day.date,
                                  'bg-gray-100 text-gray-400 cursor-not-allowed border border-gray-200': day.status === 'busy',
                                  'bg-white text-gray-300 cursor-not-allowed border border-gray-100': day.status === 'past',
                                  'ring-2 ring-brand-dark ring-offset-1 bg-brand-dark text-white': form.date === day.date,
                                }">
                          <span x-text="parseInt(day.date.slice(-2))"></span>
                          <span x-show="day.monthLabel" class="text-[7px] opacity-80 -mt-0.5" x-text="day.monthLabel"></span>
                          <span x-show="day.district_bonus > 0" class="absolute top-0 right-0.5 text-[7px]" title="Kunde im gleichen Bezirk">🗺</span>
                        </button>
                      </template>
                      <template x-if="!day">
                        <div class="w-full h-9"></div>
                      </template>
                    </div>
                  </template>
                </div>
              </template>
            </div>
          </template>
          <div x-show="calSummary" class="text-[11px] text-gray-500 mt-2 flex items-center justify-between">
            <span>
              <span class="text-brand font-semibold" x-text="calSummary.free + ' Partner verfügbar'"></span> ·
              <span class="text-gray-500" x-text="calSummary.limited + ' teils'"></span> ·
              <span class="text-gray-400" x-text="calSummary.busy + ' ausgebucht'"></span>
            </span>
            <span x-show="form.district" class="text-brand text-[10px]">🗺 Bezirk: <b x-text="form.district"></b></span>
          </div>
        </div>

        <!-- Timeslot Grid -->
        <div>
          <div class="flex items-center justify-between mb-2">
            <label class="text-xs font-bold text-gray-600">Verfügbare Uhrzeiten</label>
            <div class="text-[10px] text-gray-400" x-show="freeSlots.length"><span x-text="freeSlots.length"></span> Slots verfügbar · Fleckfrei-Partner frei</div>
          </div>
          <div x-show="loadingSlots" class="text-sm text-gray-400 py-6 text-center">Lade Slots…</div>
          <div x-show="!loadingSlots && freeSlots.length" class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-2">
            <template x-for="s in freeSlots" :key="s.time">
              <button type="button" @click="form.time = s.time"
                      class="slot px-2 py-2.5 rounded-lg text-sm font-semibold text-center"
                      :class="form.time === s.time ? 'slot-selected' : 'slot-free'">
                <div x-text="s.time + ' – ' + s.end"></div>
              </button>
            </template>
          </div>
          <div x-show="!loadingSlots && !freeSlots.length && slots.length" class="text-sm text-gray-400 py-6 text-center">
            An diesem Tag sind alle <?= 'Partner' ?>-Kapazitäten ausgebucht. Bitte anderen Tag wählen.
          </div>
          <div x-show="!loadingSlots && !slots.length" class="text-sm text-gray-400 py-6 text-center">Datum wählen.</div>
        </div>
      </section>

      <!-- Card 2b: Räume, Aufgaben, Extras -->
      <section class="card">
        <h2 class="text-xl font-bold mb-4">2b. Was soll gereinigt werden?</h2>

        <!-- Räume -->
        <div class="mb-5">
          <label class="block text-sm font-semibold mb-2">🚪 Räume (wo?)</label>
          <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
            <template x-for="r in catalog.rooms" :key="r.id">
              <label class="flex items-center gap-2 px-3 py-2 border rounded-lg cursor-pointer hover:border-brand transition"
                     :class="form.rooms.includes(r.id) ? 'border-brand bg-brand/5' : 'border-gray-200'">
                <input type="checkbox" :value="r.id" x-model="form.rooms" class="rounded"/>
                <span x-text="r.icon"></span>
                <span class="text-sm" x-text="r.label"></span>
              </label>
            </template>
          </div>
        </div>

        <!-- Tasks -->
        <div class="mb-5">
          <label class="block text-sm font-semibold mb-2">✨ Aufgaben (was?)</label>
          <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
            <template x-for="t in catalog.tasks" :key="t.id">
              <label class="flex items-center gap-2 px-3 py-2 border rounded-lg cursor-pointer hover:border-brand transition"
                     :class="form.tasks.includes(t.id) ? 'border-brand bg-brand/5' : 'border-gray-200'">
                <input type="checkbox" :value="t.id" x-model="form.tasks" class="rounded"/>
                <span x-text="t.icon"></span>
                <span class="text-sm" x-text="t.label"></span>
              </label>
            </template>
          </div>
        </div>

        <!-- Optional Products / Add-ons -->
        <div x-show="catalog.addons.length">
          <label class="block text-sm font-semibold mb-2">⭐ Zusatzleistungen (Extra buchen)</label>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <template x-for="a in catalog.addons" :key="a.id">
              <label class="flex items-center justify-between gap-2 px-3 py-2 border rounded-lg cursor-pointer hover:border-brand transition"
                     :class="form.extras.includes(a.id) ? 'border-brand bg-brand/5' : 'border-gray-200'">
                <div class="flex items-center gap-2 flex-1">
                  <input type="checkbox" :value="a.id" x-model="form.extras" class="rounded"/>
                  <span x-text="a.icon || '+'"></span>
                  <span class="text-sm" x-text="a.label"></span>
                </div>
                <span class="text-xs text-brand font-semibold" x-text="a.price ? '+' + money(Number(a.price)) : ''"></span>
              </label>
            </template>
          </div>
        </div>

        <label class="flex items-center gap-2 mt-5 px-3 py-2 border rounded-lg bg-amber-50 border-amber-200">
          <input type="checkbox" x-model="form.is_trial" class="rounded"/>
          <span class="text-sm">🧪 <b>Probereinigung</b> — erster Termin zum Testen (Partner bekommt Hinweis)</span>
        </label>
      </section>

      <!-- Card 3: Address -->
      <section class="card" id="kontakt">
        <h2 class="text-xl font-bold mb-4">3. Adresse &amp; Kontakt</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <label class="block text-xs font-bold text-gray-600 mb-1">Email *</label>
            <input type="email" x-model="form.email" @blur="emailLookup()" class="w-full px-3 py-2.5 border rounded-lg" placeholder="du@example.de"/>
            <div x-show="lookupMsg" class="text-xs mt-1 text-emerald-600" x-text="lookupMsg"></div>
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs font-bold text-gray-600 mb-1">Name *</label>
            <input type="text" x-model="form.name" class="w-full px-3 py-2.5 border rounded-lg" placeholder="Ihr Name"/>
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Telefon *</label>
            <input type="tel" x-model="form.phone" class="w-full px-3 py-2.5 border rounded-lg" placeholder="+49 176 …"/>
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Wohnfläche (m²)</label>
            <input type="number" x-model.number="form.qm" class="w-full px-3 py-2.5 border rounded-lg"/>
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs font-bold text-gray-600 mb-1">Straße &amp; Nr. *</label>
            <input type="text" x-model="form.street" class="w-full px-3 py-2.5 border rounded-lg" placeholder="Alexanderplatz 1"/>
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">PLZ *</label>
            <input type="text" x-model="form.plz" maxlength="5" class="w-full px-3 py-2.5 border rounded-lg" placeholder="10178"/>
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Stadt *</label>
            <input type="text" x-model="form.city" class="w-full px-3 py-2.5 border rounded-lg" placeholder="Berlin"/>
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Klingelname *</label>
            <input type="text" x-model="form.doorbell_name" class="w-full px-3 py-2.5 border rounded-lg" placeholder="Müller / Fleckfrei GmbH"/>
            <div class="text-[10px] text-gray-400 mt-0.5">Damit der Partner die richtige Klingel findet.</div>
          </div>
          <div>
            <label class="block text-xs font-bold text-gray-600 mb-1">Etage *</label>
            <select x-model="form.floor" class="w-full px-3 py-2.5 border rounded-lg bg-white">
              <option value="">— auswählen —</option>
              <option value="UG">Untergeschoss</option>
              <option value="EG">Erdgeschoss</option>
              <option value="Hochparterre">Hochparterre</option>
              <option value="1">1. Etage</option>
              <option value="2">2. Etage</option>
              <option value="3">3. Etage</option>
              <option value="4">4. Etage</option>
              <option value="5">5. Etage</option>
              <option value="6+">6. Etage oder höher</option>
              <option value="DG">Dachgeschoss</option>
            </select>
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs font-bold text-gray-600 mb-1">Zusatz-Info (optional)</label>
            <textarea x-model="form.notes" rows="2" class="w-full px-3 py-2.5 border rounded-lg" placeholder="Wichtige Hinweise, Code, etc."></textarea>
          </div>
        </div>
      </section>

      <!-- Card: Code -->
      <section class="card" id="code">
        <h2 class="text-xl font-bold mb-4">🎟 Gutschein-Code (optional)</h2>
        <div class="flex gap-2">
          <input type="text" x-model="form.voucher_input" class="flex-1 px-3 py-2.5 border rounded-lg font-mono uppercase" placeholder="z.B. WELCOME10"/>
          <button type="button" @click="applyVoucher()" :disabled="!form.voucher_input || voucher.loading" class="px-5 py-2.5 bg-brand text-white rounded-lg font-semibold hover:bg-brand-dark disabled:opacity-50">
            <span x-show="!voucher.loading">Einlösen</span><span x-show="voucher.loading">…</span>
          </button>
        </div>
        <div x-show="voucher.message" class="text-sm mt-2" :class="voucher.valid ? 'text-emerald-600' : 'text-red-600'" x-text="voucher.message"></div>
      </section>

      <!-- Consents -->
      <section class="card">
        <label class="flex items-start gap-2 text-sm mb-2">
          <input type="checkbox" x-model="form.consent_contact" class="mt-0.5"/>
          <span>Fleckfrei darf mich zur Bestätigung per Email/Telefon kontaktieren. *</span>
        </label>
        <label class="flex items-start gap-2 text-sm mb-2">
          <input type="checkbox" x-model="form.consent_privacy" class="mt-0.5"/>
          <span>Ich akzeptiere die <a href="https://fleckfrei.de/datenschutz" target="_blank" class="text-brand underline">Datenschutzerklärung</a>. *</span>
        </label>
        <label class="flex items-start gap-2 text-sm">
          <input type="checkbox" x-model="form.consent_marketing" class="mt-0.5"/>
          <span>Fleckfrei darf mir gelegentlich Angebote per Email senden. Jederzeit widerrufbar.</span>
        </label>
      </section>
    </div>

    <!-- RIGHT: Sticky Summary -->
    <aside class="lg:col-span-1">
      <div class="card lg:sticky lg:top-24">
        <h3 class="font-bold text-lg mb-4">Ihre Buchungsübersicht</h3>

        <div class="space-y-2 text-sm mb-4 pb-4 border-b">
          <div class="flex justify-between"><span class="text-gray-500">Häufigkeit</span><strong x-text="freqLabel()"></strong></div>
          <div class="flex justify-between"><span class="text-gray-500">Dauer</span><strong x-text="form.duration + ' Stunden'"></strong></div>
          <div x-show="form.service_type === 'str'" class="flex justify-between"><span class="text-gray-500">Gäste</span><strong x-text="form.guests"></strong></div>
          <div class="flex justify-between"><span class="text-gray-500">Datum</span><strong x-text="form.date ? dateLabel() : '—'"></strong></div>
          <div class="flex justify-between"><span class="text-gray-500">Uhrzeit</span><strong x-text="form.time ? (form.time + ' – ' + stopTime()) : '—'"></strong></div>
        </div>

        <!-- WOW-Rabatt-Banner bei aktivem Gutschein -->
        <div x-show="voucher.valid" x-cloak class="-mx-6 -mt-6 mb-4 px-6 py-4 bg-gradient-to-r from-emerald-500 to-brand text-white rounded-t-2xl relative overflow-hidden">
          <div class="absolute -top-4 -right-4 w-24 h-24 bg-white/10 rounded-full blur-2xl"></div>
          <div class="flex items-baseline justify-between relative">
            <div>
              <div class="text-[11px] uppercase tracking-wider opacity-90">🎉 Ihr Rabatt aktiv</div>
              <div class="text-2xl font-extrabold" x-text="'−' + money(voucher.discount_amount)"></div>
            </div>
            <div class="text-right">
              <div class="text-[11px] uppercase tracking-wider opacity-90">Sie sparen</div>
              <div class="text-3xl font-extrabold" x-text="savingPct() + '%'"></div>
            </div>
          </div>
          <div class="text-xs opacity-90 mt-1" x-text="'Code &quot;' + voucher.code + '&quot; wurde angewendet · ' + (voucher.description || 'Willkommensrabatt')"></div>
        </div>

        <!-- Preis-Aufschlüsselung -->
        <div class="space-y-1.5 text-sm mb-4">
          <div class="flex justify-between" :class="voucher.valid ? 'text-gray-400 line-through' : 'text-gray-800'">
            <span>
              Reinigung (<span x-text="form.duration"></span>h)
              <span x-show="form.custom_rate && form.custom_rate > 0" class="text-[10px] text-brand ml-1" x-text="'à ' + money(form.custom_rate)"></span>
            </span>
            <span x-text="money(totalGross())"></span>
          </div>
          <div x-show="form.custom_rate && form.custom_rate > 0" class="text-[11px] text-brand flex items-center gap-1 pb-1">
            <span>🎯</span><span>Persönlicher Preis vom Admin</span>
          </div>
          <div x-show="voucher.valid" class="flex justify-between text-emerald-600 font-semibold">
            <span>🎟 <span x-text="voucher.code" class="font-mono"></span></span>
            <span x-text="'−' + money(voucher.discount_amount)"></span>
          </div>
          <div x-show="form.travel_tickets > 0" class="flex justify-between text-orange-700 text-xs bg-orange-50 -mx-2 px-2 py-1 rounded border border-orange-200">
            <span>🚇 <span x-text="form.travel_tickets"></span> × <span x-text="money(form.travel_ticket_price)"></span> BVG · Cash an Partner</span>
            <span x-text="money(form.travel_tickets * form.travel_ticket_price)"></span>
          </div>
          <div class="flex justify-between pt-2 border-t-2 border-gray-900">
            <span class="font-bold text-base">= Sie zahlen</span>
            <span class="text-3xl font-extrabold text-brand animate-pulse-slow" x-text="money(final())"></span>
          </div>
          <!-- Transparenz-Breakdown -->
          <div class="pt-2 mt-2 border-t border-dashed border-gray-200 text-xs text-gray-500 space-y-0.5">
            <div class="flex justify-between"><span>davon Netto</span><span class="tabular-nums" x-text="money(finalNet())"></span></div>
            <div class="flex justify-between"><span>+ 19% MwSt.</span><span class="tabular-nums" x-text="money(finalVat())"></span></div>
          </div>
        </div>

        <!-- Weiterempfehlungs-Card — 50€ Gutschrift -->
        <a href="/empfehlen.php" class="block bg-gradient-to-br from-amber-50 to-orange-50 border-2 border-amber-200 rounded-lg p-3 mb-4 hover:border-amber-400 transition group">
          <div class="flex items-start gap-2">
            <div class="text-2xl">🎁</div>
            <div class="flex-1 text-xs">
              <div class="font-bold text-amber-900">50 € Gutschrift — empfehlen Sie uns weiter</div>
              <div class="text-amber-800 leading-snug mt-0.5">Freund bucht über Ihren Code → nach 3 Monaten bekommen Sie <b>50 €</b> gutgeschrieben.</div>
              <div class="text-amber-700 underline group-hover:text-amber-900 mt-1">Konditionen →</div>
            </div>
          </div>
        </a>

        <div class="bg-brand-light/40 border border-brand/20 rounded-lg p-3 text-xs text-gray-700 mb-4">
          <b>⭐ Neukunde?</b> Wir freuen uns! Erstmaliger Login wird per Email bestätigt, danach 1-Klick-Buchungen und persönliche Übersicht.
        </div>

        <label class="flex items-start gap-2 text-xs mb-3">
          <input type="checkbox" x-model="form.create_account" class="mt-0.5"/>
          <span>Kundenkonto kostenlos erstellen — Buchungen einsehen, wiederholt buchen.</span>
        </label>

        <button type="button" @click="submitBooking()" :disabled="!canSubmit() || submitting"
                class="w-full py-3 bg-brand text-white rounded-xl font-bold text-lg disabled:opacity-50 hover:bg-brand-dark transition">
          <span x-show="!submitting">Termin verbindlich buchen →</span>
          <span x-show="submitting">Wird gebucht…</span>
        </button>
        <div x-show="submitError" class="text-red-600 text-sm mt-2" x-text="submitError"></div>
      </div>
    </aside>

  </div>

  <!-- Wow-Section: Warum Fleckfrei? -->
  <section class="mt-12 bg-gradient-to-br from-brand to-brand-dark text-white rounded-3xl p-8 md:p-12 overflow-hidden relative">
    <div class="absolute -top-16 -right-16 w-64 h-64 bg-white/10 rounded-full blur-3xl"></div>
    <div class="absolute -bottom-20 -left-20 w-72 h-72 bg-white/5 rounded-full blur-3xl"></div>
    <div class="relative">
      <h2 class="text-3xl md:text-4xl font-extrabold mb-3">Warum 2.847 Kunden uns vertrauen.</h2>
      <p class="text-white/80 mb-8 max-w-2xl">Weil wir nicht nur putzen, sondern Zeit zurückgeben. Transparent. Live. Und ohne Abo-Falle.</p>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
        <div>
          <div class="text-4xl font-extrabold mb-1">4,9<span class="text-lg opacity-70">/5</span></div>
          <div class="text-xs text-white/70">1.300+ verifizierte Bewertungen</div>
        </div>
        <div>
          <div class="text-4xl font-extrabold mb-1">&lt; 2h</div>
          <div class="text-xs text-white/70">Reaktionszeit — garantiert</div>
        </div>
        <div>
          <div class="text-4xl font-extrabold mb-1">99,4%</div>
          <div class="text-xs text-white/70">Termine pünktlich geliefert</div>
        </div>
        <div>
          <div class="text-4xl font-extrabold mb-1">100%</div>
          <div class="text-xs text-white/70">Foto-Doku nach jedem Einsatz</div>
        </div>
      </div>
    </div>
  </section>

  <!-- USP-Grid -->
  <section class="mt-8 grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="card">
      <div class="text-3xl mb-2">⚡</div>
      <h3 class="font-bold mb-1">Sofort buchen</h3>
      <p class="text-sm text-gray-600">Online in 90 Sekunden. Bestätigung in &lt; 2h. Zahlung erst nach dem Termin.</p>
    </div>
    <div class="card">
      <div class="text-3xl mb-2">📸</div>
      <h3 class="font-bold mb-1">Live mitverfolgen</h3>
      <p class="text-sm text-gray-600">Fotos direkt nach Fertigstellung. Keine Überraschungen. Voll dokumentiert.</p>
    </div>
    <div class="card">
      <div class="text-3xl mb-2">🔒</div>
      <h3 class="font-bold mb-1">Kein Abo, keine Bindung</h3>
      <p class="text-sm text-gray-600">Einmalig, wöchentlich, monatlich — du entscheidest. Jederzeit kündbar.</p>
    </div>
  </section>

  <!-- Social Proof -->
  <section class="mt-8 card bg-brand-light/30 border-brand/20">
    <div class="flex flex-col md:flex-row items-start md:items-center gap-4">
      <div class="flex -space-x-2">
        <div class="w-10 h-10 rounded-full bg-brand text-white flex items-center justify-center text-xs font-bold border-2 border-white">LM</div>
        <div class="w-10 h-10 rounded-full bg-brand-dark text-white flex items-center justify-center text-xs font-bold border-2 border-white">TK</div>
        <div class="w-10 h-10 rounded-full bg-gray-700 text-white flex items-center justify-center text-xs font-bold border-2 border-white">AS</div>
        <div class="w-10 h-10 rounded-full bg-gray-500 text-white flex items-center justify-center text-xs font-bold border-2 border-white">+9k</div>
      </div>
      <div class="flex-1">
        <div class="text-sm font-semibold">„Endlich ein Service, bei dem ich nicht selbst nachputzen muss." ★★★★★</div>
        <div class="text-xs text-gray-500">Laura M. · Prenzlauer Berg · Short-Term Rental-Host seit 2024</div>
      </div>
      <a href="#services" class="px-4 py-2 bg-brand text-white rounded-lg font-semibold text-sm hover:bg-brand-dark">Jetzt dazugehören →</a>
    </div>
  </section>

  <!-- FAQ -->
  <section class="mt-8 card">
    <h3 class="font-bold text-lg mb-4">Häufige Fragen</h3>
    <div class="space-y-3" x-data="{open:null}">
      <?php
      $faqs = [
        ['Kann ich kostenlos stornieren?', 'Ja — bis 2 Stunden vor dem Termin kostenfrei. Danach fällt eine kleine Aufwandspauschale an.'],
        ['Wie zahle ich?', 'Per Rechnung, SEPA, Kreditkarte oder PayPal — immer NACH dem Termin. Keine Vorauskasse.'],
        ['Sind die Reinigungskräfte versichert?', 'Ja. Alle Partner sind haftpflichtversichert und über uns angestellt/vertraglich gebunden.'],
        ['Kann ich den gleichen Partner wieder buchen?', 'Natürlich. Bei Home Care arbeiten wir bewusst mit festen Partnern, damit Sie Vertrauen aufbauen können.'],
        ['Werden Reinigungsmittel mitgebracht?', 'Auf Wunsch ja — wir haben umweltfreundliche Profi-Produkte. Alternativ nutzen wir Ihre Mittel.'],
      ];
      foreach ($faqs as $i => [$q, $a]): ?>
      <div class="border-b last:border-b-0 pb-3 last:pb-0">
        <button type="button" @click="open = open === <?= $i ?> ? null : <?= $i ?>" class="w-full text-left flex items-center justify-between py-1 hover:text-brand">
          <span class="font-semibold text-sm"><?= e($q) ?></span>
          <svg class="w-4 h-4 transition" :class="open === <?= $i ?> ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <div x-show="open === <?= $i ?>" x-collapse class="text-sm text-gray-600 mt-1"><?= e($a) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

</main>

<!-- Chat-Widget (every public page) -->
<script defer src="/api/widget.js"></script>

<footer class="bg-white border-t py-8 mt-12">
  <div class="max-w-7xl mx-auto px-4 lg:px-6 flex flex-wrap items-center justify-between gap-4 text-sm text-gray-500">
    <span class="font-bold text-brand">Fleckfrei</span>
    <div class="flex gap-6">
      <a href="https://fleckfrei.de/impressum" class="hover:text-brand">Impressum</a>
      <a href="https://fleckfrei.de/datenschutz" class="hover:text-brand">Datenschutz</a>
      <a href="https://fleckfrei.de/agb" class="hover:text-brand">AGB</a>
    </div>
  </div>
</footer>

<script>
// Preis-Tabelle pro Service (zentrale Quelle, matcht fleckfrei.de)
const VAT_RATE  = <?= FLECKFREI_VAT_RATE ?>;
const MIN_HOURS = <?= FLECKFREI_MIN_BOOKING_HOURS ?>;
const SERVICES = <?= json_encode([
  'home_care' => array_merge(fleckfrei_service_meta('home_care'), fleckfrei_hourly('home_care'), ['base' => fleckfrei_service_base('home_care')]),
  'str'       => array_merge(fleckfrei_service_meta('str'),       fleckfrei_hourly('str'),       ['base' => fleckfrei_service_base('str')]),
  'office'    => array_merge(fleckfrei_service_meta('office'),    fleckfrei_hourly('office'),    ['base' => fleckfrei_service_base('office')]),
], JSON_UNESCAPED_UNICODE) ?>;

function bookingV2() {
  return {
    form: {
      service_type: <?= json_encode($prefill['service']) ?>,
      frequency: 'once',
      duration: <?= $prefill['hours'] ?>,
      guests: 1,
      date: new Date(Date.now() + 86400000).toISOString().slice(0,10),
      time: '',
      name: <?= json_encode($prefill['name']) ?>,
      email: <?= json_encode($prefill['email']) ?>,
      phone: <?= json_encode($prefill['phone']) ?>,
      qm: 50,
      street: <?= json_encode($prefill['street']) ?>,
      plz: <?= json_encode($prefill['plz']) ?>,
      city: <?= json_encode($prefill['city']) ?>,
      pb_token: <?= json_encode($prefill['pb_token']) ?>,
      voucher_input: <?= json_encode($prefill['voucher']) ?>,
      district: <?= json_encode($prefill['district']) ?>,
      custom_rate: <?= (float)$prefill['rate'] ?>,
      travel_tickets: <?= (int)$prefill['tickets'] ?>,
      travel_ticket_price: <?= (float)$prefill['tix_p'] ?>,
      doorbell_name: '', floor: '',
      rooms: [], tasks: [], extras: [], is_trial: false,
      selected_service_id: 0,
      notes: '',
      create_account: true,
      consent_contact: false, consent_privacy: false, consent_marketing: false,
    },
    slots: [],
    loadingSlots: false,
    cal: [],
    loadingCal: false,
    calSummary: null,
    catalog: { rooms: [], tasks: [], addons: [] },
    myServices: [],
    voucher: { valid: false, code: '', discount_amount: 0, message: '', loading: false },
    lookupMsg: '',
    submitting: false,
    submitError: '',

    get minDate() {
      return new Date().toISOString().slice(0,10);
    },

    init() {
      this.loadSlots();
      this.loadCatalog();
      this.loadCalendar();
      if (this.form.pb_token) this.loadMyServices();
    },

    async loadMyServices() {
      try {
        const r = await fetch('/api/my-services.php?pb=' + encodeURIComponent(this.form.pb_token));
        const d = await r.json();
        this.myServices = d.services || [];
      } catch (e) {}
    },

    selectMyService(s) {
      this.form.selected_service_id = s.id;
      this.form.custom_rate = s.hourly_gross;
      if (s.street)        this.form.street = s.street;
      if (s.plz)           this.form.plz = s.plz;
      if (s.city)          this.form.city = s.city;
      if (s.doorbell_name) this.form.doorbell_name = s.doorbell_name;
      if (s.qm)            this.form.qm = parseInt(s.qm) || this.form.qm;
      if (s.max_guests)    this.form.guests = s.max_guests;
      // Beim STR auch die Gäste vorbelegen
    },

    async loadCalendar() {
      this.loadingCal = true;
      try {
        const p = new URLSearchParams({ days: '28', duration: String(this.form.duration) });
        if (this.form.district) p.set('district', this.form.district);
        if (this.voucher.valid && this.voucher.code) p.set('voucher_code', this.voucher.code);
        const r = await fetch('/api/calendar-availability.php?' + p.toString());
        const d = await r.json();
        this.cal = d.days_data || [];
        this.calSummary = d.summary || null;
      } catch (e) {}
      this.loadingCal = false;
    },

    get freeSlots() {
      return (this.slots || []).filter(s => s.status === 'free');
    },

    get calWeeks() {
      // Build proper Mo-So weeks. Each day slot = object or null.
      if (!this.cal.length) return [];
      const MONATE = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez'];
      const first = new Date(this.cal[0].date + 'T00:00:00');
      const wd = (first.getDay() + 6) % 7; // Mo=0..So=6
      const padded = [];
      for (let i = 0; i < wd; i++) padded.push(null);
      let prevMonth = -1;
      for (const d of this.cal) {
        const dt = new Date(d.date + 'T00:00:00');
        const m = dt.getMonth();
        const isFirstOfMonth = (parseInt(d.date.slice(-2)) === 1) || (prevMonth !== -1 && m !== prevMonth);
        padded.push({ ...d, monthLabel: isFirstOfMonth ? MONATE[m] : '' });
        prevMonth = m;
      }
      // Pad tail to full week
      while (padded.length % 7) padded.push(null);
      const weeks = [];
      for (let i = 0; i < padded.length; i += 7) weeks.push(padded.slice(i, i+7));
      return weeks;
    },

    async loadCatalog() {
      try {
        const r = await fetch('/api/booking-catalog.php');
        const d = await r.json();
        this.catalog.rooms  = d.rooms  || [];
        this.catalog.tasks  = d.tasks  || [];
        this.catalog.addons = d.addons || [];
      } catch (e) {}
    },

    async loadSlots() {
      if (!this.form.date) return;
      this.loadingSlots = true;
      this.slots = [];
      try {
        const params = new URLSearchParams({
          date: this.form.date,
          duration: String(this.form.duration),
        });
        if (this.voucher.valid && this.voucher.code) params.set('voucher_code', this.voucher.code);
        if (this.form.email && this.form.email.includes('@')) params.set('email', this.form.email);
        const r = await fetch('/api/timeslots.php?' + params.toString());
        const d = await r.json();
        this.slots = d.slots || [];
      } catch (e) {}
      this.loadingSlots = false;
    },

    async emailLookup() {
      if (!this.form.email || !this.form.email.includes('@')) return;
      try {
        const r = await fetch('/api/customer-lookup.php?email=' + encodeURIComponent(this.form.email));
        const d = await r.json();
        if (!d || !d.found) { this.lookupMsg = ''; return; }
        if (!this.form.name && d.name) this.form.name = d.name;
        if (!this.form.street && d.street) this.form.street = (d.street + (d.number ? ' ' + d.number : '')).trim();
        if (!this.form.plz && d.plz) this.form.plz = d.plz;
        if (!this.form.city && d.city) this.form.city = d.city;
        this.lookupMsg = '✓ Willkommen zurück — Adresse übernommen';
      } catch (e) {}
    },

    async applyVoucher() {
      const code = (this.form.voucher_input || '').trim().toUpperCase();
      if (!code) return;
      this.voucher.loading = true;
      try {
        const r = await fetch('/api/coupon-validate.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ code, subtotal: this.totalGross(), hours: this.form.duration, service_type: this.form.service_type === 'str' ? 'str' : (this.form.service_type === 'office' ? 'bs' : 'hc'), email: this.form.email }),
        });
        const d = await r.json();
        if (d.valid) {
          this.voucher = { valid: true, code: d.code, discount_amount: d.discount_amount, message: d.message, loading: false };
          // Premium/Travel-Block: slots + kalender neu laden
          this.loadSlots();
          this.loadCalendar();
        } else {
          this.voucher = { valid: false, code: '', discount_amount: 0, message: d.error || 'Ungültig', loading: false };
        }
      } catch (e) { this.voucher.loading = false; this.voucher.message = '❌ Fehler'; }
    },

    // Brutto-first: 2h × 27,29 = 54,58 EXAKT (keine Rundungs-Drift)
    currentMeta() { return SERVICES[this.form.service_type] || SERVICES.home_care; },
    showGross()   { return this.currentMeta().display_mode === 'brutto'; },
    hoursEff()    { return Math.max(MIN_HOURS, this.form.duration); },
    totalGross()  {
      // Custom-Rate vom Admin (via Prebook-Link) hat Vorrang vor Service-Default
      const rate = (this.form.custom_rate && Number(this.form.custom_rate) > 0) ? Number(this.form.custom_rate) : this.currentMeta().gross;
      return +(this.hoursEff() * rate).toFixed(2);
    },
    totalNet()    { return +(this.totalGross() / (1 + VAT_RATE)).toFixed(2); },
    // Nach Gutschein (Gutschein wird IMMER als Brutto-Betrag abgezogen):
    final()       { return Math.max(0, +(this.totalGross() - (this.voucher.valid ? this.voucher.discount_amount : 0)).toFixed(2)); },
    finalNet()    { return +(this.final() / (1 + VAT_RATE)).toFixed(2); },
    finalVat()    { return +(this.final() - this.finalNet()).toFixed(2); },
    savingPct()   {
      const g = this.totalGross();
      if (!g) return 0;
      return Math.round((this.voucher.discount_amount / g) * 100);
    },
    // Alte Aliasse
    netto()       { return this.finalNet(); },
    mwst()        { return this.finalVat(); },
    selectService(s) {
      this.form.service_type = s;
      this.loadCalendar();
      setTimeout(() => { document.getElementById('details')?.scrollIntoView({behavior:'smooth', block:'start'}); }, 100);
    },
    stopTime() {
      if (!this.form.time) return '—';
      const [h, m] = this.form.time.split(':').map(Number);
      const total = h * 60 + m + this.form.duration * 60;
      const eh = Math.floor(total / 60) % 24;
      const em = total % 60;
      return String(eh).padStart(2,'0') + ':' + String(em).padStart(2,'0');
    },

    money(n) { return (Number(n)||0).toFixed(2).replace('.', ',') + ' €'; },

    freqLabel() {
      return {once:'Einmalig',weekly:'Wöchentlich',biweekly:'Alle 2 Wochen',monthly:'Monatlich'}[this.form.frequency] || this.form.frequency;
    },
    dateLabel() {
      try { return new Date(this.form.date).toLocaleDateString('de-DE', {weekday:'short', day:'numeric', month:'short'}); }
      catch(e) { return this.form.date; }
    },

    canSubmit() {
      return this.form.name && this.form.email && this.form.phone && this.form.street && this.form.plz && this.form.city && this.form.doorbell_name && this.form.floor && this.form.date && this.form.time && this.form.consent_contact && this.form.consent_privacy && !this.submitting;
    },

    async submitBooking() {
      this.submitError = '';
      if (!this.canSubmit()) { this.submitError = 'Bitte alle Pflichtfelder ausfüllen.'; return; }
      this.submitting = true;
      const payload = {
        name: this.form.name,
        email: this.form.email,
        phone: this.form.phone,
        date: this.form.date,
        time: this.form.time,
        hours: this.form.duration,
        qm: this.form.qm,
        rooms: 1,
        beds: 0,
        street: this.form.street,
        plz: this.form.plz,
        city: this.form.city,
        country: 'Deutschland',
        doorbell_name: this.form.doorbell_name,
        floor: this.form.floor,
        rooms_selected: this.form.rooms,
        tasks_selected: this.form.tasks,
        extras: this.form.extras,
        is_trial: this.form.is_trial ? 1 : 0,
        pb_token: this.form.pb_token || '',
        service_id: this.form.selected_service_id || 0,
        travel_tickets: this.form.travel_tickets || 0,
        travel_ticket_price: this.form.travel_ticket_price || 0,
        customer_type: this.form.service_type === 'str' ? 'str' : (this.form.service_type === 'office' ? 'office' : 'private'),
        service_type: this.form.service_type,
        extras: [],
        notes: this.form.notes,
        frequency: this.form.frequency,
        coupon_code: this.voucher.valid ? this.voucher.code : '',
        subtotal: this.totalGross(),
        create_account: !!this.form.create_account,
        consent_marketing: !!this.form.consent_marketing,
      };
      try {
        const r = await fetch('/api/booking-public.php', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        const d = await r.json();
        if (d.success || d.ok) {
          window.location.href = '/booking-success.php?id=' + (d.job_id || '');
        } else {
          this.submitError = d.error || 'Buchung fehlgeschlagen.';
          this.submitting = false;
        }
      } catch (e) {
        this.submitError = '❌ Netzwerk-Fehler. Bitte erneut versuchen.';
        this.submitting = false;
      }
    },
  };
}
</script>
</body>
</html>
