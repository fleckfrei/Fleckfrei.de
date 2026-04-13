<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
if (!customerCan('booking')) { header('Location: /customer/'); exit; }
$title = 'Neue Buchung'; $page = 'booking';
$cid = me()['id'];
// Frequency-Discounts aus settings
$_st = one("SELECT discount_weekly, discount_biweekly, discount_monthly, discount_active FROM settings LIMIT 1") ?: [];
$discountActive = (int)($_st['discount_active'] ?? 1);
$discountWeekly = (float)($_st['discount_weekly'] ?? 7);
$discountBiweekly = (float)($_st['discount_biweekly'] ?? 5);
$discountMonthly = (float)($_st['discount_monthly'] ?? 3);
$user = me();

$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);
$isAirbnb = in_array($customer['customer_type'] ?? '', ['Airbnb', 'Booking', 'Short-Term Rental', 'Company', 'Host']);
$customerTypeKey = match(true) {
    in_array($customer['customer_type'] ?? '', ['Airbnb', 'Booking', 'Short-Term Rental', 'Host']) => 'airbnb',
    in_array($customer['customer_type'] ?? '', ['B2B', 'Company', 'GmbH', 'Business']) => 'business',
    default => 'private',
};

// Pricing config for this customer type
try {
    $pricingCfg = one("SELECT * FROM pricing_config WHERE customer_type = ? LIMIT 1", [$customerTypeKey]);
    if (!$pricingCfg) $pricingCfg = one("SELECT * FROM pricing_config WHERE customer_type = 'all' LIMIT 1");
} catch (Exception $e) { $pricingCfg = null; }
$lastMinutePct = (float)($pricingCfg['last_minute_discount_pct'] ?? 0);
$lastMinuteHours = (int)($pricingCfg['last_minute_threshold_hours'] ?? 24);
$defaultStart = $pricingCfg['default_window_start'] ?? '11:00:00';
$defaultEnd = $pricingCfg['default_window_end'] ?? '16:00:00';

// Dynamic pricing features
// Floor deaktiviert — Echtpreis zeigen (Max: kein aggressives Clampen)
$floorPrice = 0;
$minBillableHours = (float)($pricingCfg['min_billable_hours'] ?? 2);

// Loyalty check: customer active 3+ months → admin-konfigurierbarer Bonus
// Host/Airbnb/B2B: KEINE Rabatte (Festpreis-Logik)
$firstJobDate = val("SELECT MIN(j_date) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='COMPLETED'", [$cid]);
$monthsActive = $firstJobDate ? max(0, (int)((time() - strtotime($firstJobDate)) / (30 * 86400))) : 0;
$isLoyalCustomer = !$isAirbnb && $monthsActive >= 3;
try { $loyaltyBonusAmount = (float) val("SELECT config_value FROM app_config WHERE config_key='loyalty_bonus_amount'"); } catch (Exception $e) { $loyaltyBonusAmount = 0; }
if ($loyaltyBonusAmount <= 0) $loyaltyBonusAmount = 10.0;
$loyaltyBonus = $isLoyalCustomer ? $loyaltyBonusAmount : 0;
// Host/Airbnb: auch kein Last-Minute-Rabatt
if ($isAirbnb) { $lastMinutePct = 0; }

// Stammkunden: check if customer has a fixed price (override dynamic pricing)
$hasFixedPrice = isset($customer['fixed_hourly_rate']) && $customer['fixed_hourly_rate'] > 0;
$fixedRate = (float)($customer['fixed_hourly_rate'] ?? 0);
// Column may not exist yet — safe fallback
if (!array_key_exists('fixed_hourly_rate', $customer)) { $hasFixedPrice = false; $fixedRate = 0; }

// Completed jobs count (for "Stammkunde" badge)
$completedJobs = (int) val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk=? AND status=1 AND job_status='COMPLETED'", [$cid]);
$isStammkunde = $completedJobs >= 5;

try { $addresses = all("SELECT * FROM customer_address WHERE customer_id_fk=? ORDER BY ca_id DESC", [$cid]); } catch (Exception $e) { $addresses = []; }

// Customer-own services first (full data so we can pre-fill address/codes/etc.), fall back to shared catalog
$services = all("SELECT s_id, title, price, tax_percentage, total_price, max_guests, street, number, postal_code, city, box_code, client_code, deposit_code, doorbell_name, wa_keyword FROM services WHERE customer_id_fk=? AND status=1 ORDER BY title", [$cid]);
if (empty($services)) {
    $services = all("SELECT s_id, title, price, tax_percentage, total_price, max_guests, street, number, postal_code, city, wa_keyword FROM services WHERE (customer_id_fk IS NULL OR customer_id_fk=0) AND status=1 ORDER BY title");
}

$servicesMap = [];
foreach ($services as $s) {
    $addr = trim(trim(($s['street'] ?? '') . ' ' . ($s['number'] ?? '')) . ', ' . trim(($s['postal_code'] ?? '') . ' ' . ($s['city'] ?? '')), ', ');
    // NETTO korrekt ermitteln: wenn price=0 aber total_price gesetzt → aus Brutto rückrechnen
    $taxPct = (float)($s['tax_percentage'] ?? 19);
    $rawNetto = (float)($s['price'] ?? 0);
    $rawBrutto = (float)($s['total_price'] ?? 0);
    $effNetto = $rawNetto > 0 ? $rawNetto : ($rawBrutto > 0 ? round($rawBrutto / (1 + $taxPct/100), 2) : 0);
    $effBrutto = $rawBrutto > 0 ? $rawBrutto : ($effNetto > 0 ? round($effNetto * (1 + $taxPct/100), 2) : 0);
    $servicesMap[$s['s_id']] = [
        'max_guests' => (int)($s['max_guests'] ?? 0),
        'price' => $effNetto,            // NETTO (immer korrekt)
        'tax_percentage' => $taxPct,
        'brutto' => $effBrutto,           // BRUTTO
        'wa_keyword' => $s['wa_keyword'] ?? null,
        'title' => $s['title'] ?? '',
        'address' => $addr,
        'box_code' => $s['box_code'] ?? '',
    ];
}

// Prefill via URL: ?service_id=X&date=Y&hours=Z
$prefillServiceId = (int)($_GET['service_id'] ?? 0);
$prefillDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : '';
$prefillHours = (int)($_GET['hours'] ?? 0);

// Compute smart defaults from last completed job on the same service
$lastJobDefaults = null;
if ($prefillServiceId) {
    $lastJobDefaults = one("
        SELECT j_time, j_hours, total_hours, no_people
        FROM jobs
        WHERE customer_id_fk=? AND s_id_fk=? AND status=1 AND job_status='COMPLETED'
        ORDER BY j_date DESC LIMIT 1
    ", [$cid, $prefillServiceId]);
}
// Also: last job across ALL services → fallback
if (!$lastJobDefaults) {
    $lastJobDefaults = one("
        SELECT j_time, j_hours, total_hours, no_people
        FROM jobs
        WHERE customer_id_fk=? AND status=1 AND job_status='COMPLETED'
        ORDER BY j_date DESC LIMIT 1
    ", [$cid]);
}

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Back button -->
<div class="mb-4">
  <?php $backUrl = '/customer/calendar.php'; if (!empty($_SERVER['HTTP_REFERER']) && str_starts_with($_SERVER['HTTP_REFERER'], 'https://app.fleckfrei.de/')) $backUrl = $_SERVER['HTTP_REFERER']; ?>
  <a href="<?= e($backUrl) ?>" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

<div class="mb-6">
  <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Neue Buchung</h1>
  <p class="text-gray-500 mt-1 text-sm"><?= $isAirbnb ? 'Turnover-Service für Ihre Unterkunft' : 'Reinigungstermin buchen — Sie sehen den Preis live' ?></p>
</div>

<?php if ($prefillServiceId || $lastJobDefaults): ?>
<div class="mb-5 p-3 rounded-xl bg-brand-light border border-brand/20 flex items-center gap-3 text-sm">
  <svg class="w-5 h-5 text-brand flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
  <div class="flex-1">
    <div class="font-bold text-brand-dark">Daten automatisch übernommen</div>
    <div class="text-xs text-gray-600">Service, Adresse, Zugangscode, Dauer und gewohnte Uhrzeit aus Ihrem letzten Termin. Bitte nur noch Datum und ggf. Gästezahl bestätigen.</div>
  </div>
</div>
<?php endif; ?>

<div x-data="bookingForm()" x-init="init()" class="grid grid-cols-1 lg:grid-cols-3 gap-6">

  <!-- ============ MAIN FORM (left, 2 cols) ============ -->
  <div class="lg:col-span-2 space-y-6" x-show="!submitted">

    <?php if ($isAirbnb): ?>
    <!-- Stammkunden-Banner statt Frequency-Auswahl -->
    <div class="card-elev p-5 bg-gradient-to-br from-brand/5 to-transparent border-brand/30">
      <div class="flex items-start gap-3">
        <div class="w-10 h-10 rounded-full bg-brand text-white flex items-center justify-center text-lg font-bold flex-shrink-0"><?= e(strtoupper(substr($custFullName2 ?: 'K', 0, 1))) ?></div>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <h2 class="font-bold text-gray-900"><?= e($custFullName2 ?: $greetingName) ?></h2>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-brand text-white">⭐ <?= e($customer['customer_type']) ?></span>
          </div>
          <p class="text-xs text-gray-600 mt-1">Festpreis aktiv · Sie zahlen Ihren individuellen Stundenpreis. Keine dynamischen Zuschläge oder Rabatte — nur optional gewählte Zusatzleistungen werden zusätzlich berechnet.</p>
        </div>
      </div>
    </div>
    <?php else: ?>
    <!-- 1. Häufigkeit (nur für Privatkunden) -->
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
    <?php endif; ?>

    <!-- 2. Service + Details -->
    <div class="card-elev p-6">
      <h2 class="font-bold text-lg mb-5">2. Details der Reinigung</h2>

      <div class="space-y-4">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Service</label>
          <select x-model="form.service" required class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none">
            <option value="">Bitte wählen…</option>
            <?php foreach ($services as $s): ?>
            <?php
              $sTax = (float)($s['tax_percentage'] ?? 19);
              $sNetto = ($s['price'] ?? 0) > 0 ? (float)$s['price'] : (($s['total_price'] ?? 0) > 0 ? round($s['total_price'] / (1 + $sTax/100), 2) : 0);
              $sBrutto = ($s['total_price'] ?? 0) > 0 ? (float)$s['total_price'] : ($sNetto > 0 ? round($sNetto * (1 + $sTax/100), 2) : 0);
              $displayPrice = $customerTypeKey === 'private' ? $sBrutto : $sNetto;
              $priceSuffix = $customerTypeKey === 'private' ? ' inkl. MwSt' : ' netto';
            ?>
            <option value="<?= $s['s_id'] ?>" data-price="<?= $sNetto ?>"><?= e($s['title']) ?><?= $displayPrice ? ' — ' . money($displayPrice) . '/h' . $priceSuffix : '' ?></option>
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
          <?php if ($isAirbnb): ?>
          <!-- Host: Anzahl Gäste (basiert auf service.max_guests) -->
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Anzahl Gäste</label>
            <select x-model="form.no_people" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none">
              <template x-for="i in (currentMaxGuests || 6)" :key="i">
                <option :value="i" x-text="i + (i === 1 ? ' Gast' : ' Gäste')"></option>
              </template>
            </select>
            <p x-show="currentMaxGuests" class="text-[10px] text-gray-400 mt-0.5">max. <span x-text="currentMaxGuests"></span> laut Unterkunft</p>
          </div>
          <?php else: ?>
          <!-- Private: Anzahl Reinigungskräfte -->
          <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Anzahl Reinigungskräfte</label>
            <select x-model="form.no_people" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none">
              <option value="1">1 Person</option>
              <option value="2">2 Personen</option>
              <option value="3">3 Personen</option>
            </select>
          </div>
          <?php endif; ?>
        </div>

        <!-- Datum -->
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Datum</label>
          <input type="date" x-model="form.date" required min="<?= date('Y-m-d') ?>"
                 class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
        </div>

        <!-- Hinweis wenn noch kein Service gewählt -->
        <div x-show="!form.service" class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-sm text-amber-900 flex items-center gap-2">
          <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
          Bitte zuerst einen <strong>Service</strong> oben auswählen — danach erscheinen die verfügbaren Uhrzeiten mit Preis.
        </div>

        <!-- Uhrzeit-Slots (nur sichtbar wenn Service UND Datum gewählt) -->
        <div x-show="form.date && form.service">
          <div class="flex items-center justify-between mb-2">
            <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wider">Uhrzeit</label>
            <div class="flex items-center gap-3 text-[10px] text-gray-600 font-medium">
              <div class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>frei</div>
              <div class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>fast voll</div>
              <div class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-red-400"></span>ausgebucht</div>
            </div>
          </div>
          <div x-show="timeslotsLoading" class="text-sm text-gray-500 py-3">Verfügbarkeit wird geladen...</div>
          <div x-show="!timeslotsLoading && timeslots.length > 0" class="grid grid-cols-4 sm:grid-cols-6 gap-1.5">
            <template x-for="slot in timeslots" :key="slot.time">
              <button type="button" @click="slot.bookable && (form.time = slot.time)"
                :disabled="!slot.bookable"
                :class="{
                  'bg-brand text-white ring-2 ring-brand shadow-sm': form.time === slot.time,
                  'bg-white border border-gray-200 hover:border-brand text-gray-900': form.time !== slot.time && slot.bookable,
                  'bg-gray-50 border border-gray-100 text-gray-300 cursor-not-allowed': !slot.bookable
                }"
                class="rounded-lg py-2 px-1 text-center transition">
                <div class="font-bold text-sm leading-tight" x-text="slot.time"></div>
                <div class="flex items-center justify-center gap-0.5 mt-0.5">
                  <span class="inline-block w-1 h-1 rounded-full"
                    :class="form.time === slot.time ? 'bg-white' : (slot.status === 'available' ? 'bg-green-500' : (slot.status === 'limited' ? 'bg-amber-500' : 'bg-red-400'))"></span>
                  <span class="text-[9px] font-medium leading-none"
                    :class="form.time === slot.time ? 'text-white/90' : (slot.status === 'available' ? 'text-green-700' : (slot.status === 'limited' ? 'text-amber-700' : 'text-red-500'))"
                    x-text="slot.status === 'full' ? 'ausgebucht' : (slot.free_partners === 1 ? 'fast voll' : 'frei')"></span>
                </div>
                <!-- Preis: Privat → Brutto gross / B2B/Host → Netto gross -->
                <div x-show="slot.price_netto && slot.bookable" class="mt-1 leading-tight">
                  <?php if ($customerTypeKey === 'private'): ?>
                    <!-- Privatkunde: Brutto ist der Preis den er zahlt -->
                    <div class="text-sm font-bold"
                         :class="form.time === slot.time ? 'text-white' : 'text-gray-900'"
                         x-text="slot.price_brutto ? slot.price_brutto.toFixed(2).replace('.', ',') + ' €' : ''"></div>
                    <div class="text-[8px] opacity-70"
                         :class="form.time === slot.time ? 'text-white' : 'text-gray-500'"
                         x-text="'inkl. MwSt'"></div>
                  <?php else: ?>
                    <!-- Host / B2B / Airbnb: Netto ist relevant (MwSt absetzbar) -->
                    <div class="text-sm font-bold"
                         :class="form.time === slot.time ? 'text-white' : 'text-gray-900'"
                         x-text="slot.price_netto ? slot.price_netto.toFixed(2).replace('.', ',') + ' €' : ''"></div>
                    <div class="text-[8px] opacity-70"
                         :class="form.time === slot.time ? 'text-white' : 'text-gray-500'"
                         x-text="'netto · zzgl. MwSt'"></div>
                  <?php endif; ?>
                </div>
              </button>
            </template>
          </div>
          <div x-show="isStammkunde" class="text-[11px] text-brand-dark font-semibold mt-2 flex items-center gap-1">
            <span>⭐</span> Stammkunden-Preise aktiv
          </div>
          <div x-show="!isStammkunde && timeslots.some(s => s.price)" class="text-[11px] text-gray-600 font-medium mt-2">
            💡 Dynamische Preise — günstiger an freien Tageszeiten
          </div>
        </div>

        <!-- Stats -->
        <div x-show="timeslotsStats && (timeslotsStats.absent_today > 0 || timeslotsStats.too_far > 0)" class="text-[11px] text-gray-700 bg-gray-50 rounded-lg px-3 py-2 border border-gray-200">
          <template x-if="timeslotsStats?.absent_today > 0">
            <div>📅 <span x-text="timeslotsStats.absent_today"></span> Partner im Urlaub/krank</div>
          </template>
          <template x-if="timeslotsStats?.too_far > 0">
            <div>📍 <span x-text="timeslotsStats.too_far"></span> Partner zu weit entfernt (>25 km)</div>
          </template>
        </div>
      </div>
    </div>

    <!-- 3. Adresse — nur wenn Service gewählt -->
    <div class="card-elev p-6" x-show="form.service" x-cloak>
      <h2 class="font-bold text-lg mb-1">3. Reinigungsadresse</h2>
      <p class="text-xs text-gray-500 mb-5" x-text="serviceAddress && !overrideAddress ? 'Adresse aus gewähltem Service übernommen.' : 'Gespeicherte Adressen wählen oder eine neue anlegen.'"></p>

      <!-- Service-locked address (when service has its own address) -->
      <div x-show="serviceAddress && !overrideAddress" class="space-y-2 mb-4">
        <div class="flex items-start gap-3 p-3 border-2 border-brand bg-brand-light rounded-xl">
          <svg class="w-5 h-5 text-brand flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          <div class="flex-1 min-w-0">
            <div class="text-xs font-semibold text-brand uppercase tracking-wider mb-0.5">Service-Adresse</div>
            <div class="font-medium text-gray-900 text-sm" x-text="serviceAddress"></div>
          </div>
          <button type="button" @click="overrideAddress = true" class="text-xs text-brand hover:text-brand-dark font-medium whitespace-nowrap">Andere wählen</button>
        </div>
      </div>

      <!-- Saved addresses als Dropdown (kompakt) -->
      <div class="mb-4" x-show="addresses.length > 0 && (!serviceAddress || overrideAddress)">
        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Adresse wählen</label>
        <select x-model="form.address" required class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none bg-white text-sm">
          <option value="">— Bitte wählen —</option>
          <template x-for="(a, idx) in addresses" :key="a.ca_id || idx">
            <option :value="a.full" x-text="(a.address_for ? a.address_for + ': ' : '') + a.full"></option>
          </template>
        </select>
        <button x-show="serviceAddress && overrideAddress" type="button" @click="overrideAddress = false; form.address = serviceAddress"
                class="text-xs text-gray-500 hover:text-brand underline mt-2">&larr; Zurück zur Service-Adresse</button>
      </div>

      <!-- Toggle: add new address -->
      <button type="button" @click="showNewAddr = !showNewAddr"
              x-show="!serviceAddress || overrideAddress"
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
    <!-- 4. Airbnb-spezifisch — nur wenn Service gewählt -->
    <div class="card-elev p-6" x-show="form.service" x-cloak>
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

      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Check-out Datum</label>
          <input type="date" x-model="form.guest_checkout_date" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Check-out Zeit</label>
          <input type="time" x-model="form.guest_checkout_time" value="11:00" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Check-in Datum</label>
          <input type="date" x-model="form.check_in_date" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Check-in Zeit</label>
          <input type="time" x-model="form.check_in_time" value="15:00" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"/>
        </div>
      </div>
      <div class="text-[11px] text-gray-700 bg-blue-50 border border-blue-200 rounded-lg px-3 py-2 mb-4">
        💡 <strong>Turnover-Tipp:</strong> Reinigung zwischen Check-out (meist 11 Uhr) und Check-in (meist 16 Uhr). Partner sieht diese Zeiten und plant entsprechend.
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

    </div>
    <?php endif; ?>

    <?php
      // Dynamische Zusatzleistungen aus DB (Admin-verwaltet) — eigene Sektion für ALLE Kundentypen
      $visFilter = $isAirbnb ? "('all','host','business')" : "('all','private')";
      try {
          $optionalProducts = all("SELECT op_id, name, description, pricing_type, customer_price, icon
              FROM optional_products
              WHERE is_active=1 AND visibility IN $visFilter
              ORDER BY sort_order ASC, op_id ASC");
      } catch (Exception $e) { $optionalProducts = []; }
    ?>
    <?php if (!empty($optionalProducts)): ?>
    <!-- 5. Zusatzleistungen — nur wenn Service gewählt -->
    <div class="card-elev p-6" x-show="form.service" x-cloak>
      <h2 class="font-bold text-lg mb-1"><?= $isAirbnb ? '5' : '4' ?>. Zusatzleistungen</h2>
      <p class="text-xs text-gray-500 mb-4">Optional — jede Zusatzleistung wird einzeln in der Preisübersicht rechts angezeigt.</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <?php foreach ($optionalProducts as $op):
          $priceSuffix = match($op['pricing_type']) {
              'per_hour' => '/h', 'percentage' => '% Aufschlag', default => ''
          };
          $priceLabel = $op['customer_price'] > 0 ? ' · +' . number_format($op['customer_price'], 2, ',', '.') . ($op['pricing_type']==='percentage' ? '% Aufschlag' : ' €' . $priceSuffix) : '';
        ?>
        <label class="flex items-start gap-2 p-3 border border-gray-200 rounded-lg hover:border-brand cursor-pointer transition">
          <input type="checkbox" value="<?= (int)$op['op_id'] ?>" @change="toggleOption(<?= (int)$op['op_id'] ?>, <?= htmlspecialchars(json_encode($op['name']), ENT_QUOTES) ?>)" class="w-4 h-4 rounded text-brand focus:ring-brand mt-0.5"/>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-semibold text-gray-900 flex items-center gap-1 flex-wrap">
              <?php if ($op['icon']): ?><span><?= e($op['icon']) ?></span><?php endif; ?>
              <span><?= e($op['name']) ?></span>
              <?php if ($op['customer_price'] > 0): ?>
              <span class="text-xs text-brand-dark font-bold"><?= e($priceLabel) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($op['description']): ?>
            <div class="text-[11px] text-gray-600 mt-0.5"><?= e($op['description']) ?></div>
            <?php endif; ?>
          </div>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Notes — nur wenn Service gewählt -->
    <div class="card-elev p-6" x-show="form.service" x-cloak>
      <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wider">Anmerkungen <?= $isAirbnb ? '/ spezielle Anweisungen' : '(optional)' ?></label>
      <textarea x-model="form.notes" rows="3" placeholder="<?= $isAirbnb ? 'Waschmaschine starten, Handtücher falten, Willkommenspaket…' : 'Besondere Wünsche, Hinweise…' ?>" class="w-full px-3 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none"></textarea>
    </div>

    <div x-show="error" x-cloak class="card-elev bg-red-50 border-red-200 p-4 text-sm text-red-800" x-text="error"></div>
  </div>

  <!-- ============ STICKY SIDEBAR (right, 1 col) ============ -->
  <div class="lg:col-span-1" x-show="!submitted">
    <div class="card-elev p-6 sticky top-[84px]">
      <!-- Kunden-Header -->
      <div class="flex items-center gap-2 pb-3 mb-4 border-b border-gray-200">
        <div class="w-8 h-8 rounded-full bg-brand text-white flex items-center justify-center text-sm font-bold flex-shrink-0"><?= e(strtoupper(substr($custFullName2 ?: 'K', 0, 1))) ?></div>
        <div class="flex-1 min-w-0">
          <div class="font-bold text-sm text-gray-900 truncate"><?= e($custFullName2 ?: $greetingName) ?></div>
          <div class="text-[10px] text-gray-600 truncate">
            <?php if ($isAirbnb): ?>
              <span class="text-brand-dark font-semibold">⭐ <?= e($customer['customer_type']) ?>-Tarif</span>
            <?php elseif ($isStammkunde): ?>
              <span class="text-amber-700 font-semibold">⭐ Stammkunde · <?= $completedJobs ?> Aufträge</span>
            <?php else: ?>
              <span class="text-blue-700 font-semibold">🆕 Privatkunde</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
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

      <?php if ($isStammkunde): ?>
      <div class="mb-3 p-2.5 rounded-lg bg-brand-light border border-brand/20 flex items-center gap-2 text-xs">
        <span class="text-base">⭐</span>
        <div><strong class="text-brand-dark">Stammkunde</strong> · <?= $completedJobs ?> Aufträge · Ihre Konditionen sind gesichert</div>
      </div>
      <?php endif; ?>
      <?php if ($isLoyalCustomer && !$isAirbnb): ?>
      <div class="mb-3 p-2.5 rounded-lg bg-amber-50 border border-amber-200 flex items-center gap-2 text-xs">
        <span class="text-base">🎁</span>
        <div><strong class="text-amber-800">Treue-Bonus</strong> · <?= $monthsActive ?> Monate dabei · <?= money($loyaltyBonusAmount) ?> Guthaben verfügbar</div>
      </div>
      <?php endif; ?>

      <!-- Transparente Preis-Aufschlüsselung -->
      <div class="space-y-1.5 text-[13px]">
        <!-- Reinigung (Basis) -->
        <div class="flex justify-between" x-show="basePrice > 0">
          <span class="text-gray-700">Reinigung (<span x-text="form.hours"></span>h × <span x-text="formatMoney(basePrice)"></span>)</span>
          <span class="text-gray-900 font-semibold" x-text="formatMoney(baseNetto)"></span>
        </div>
        <!-- Frequency / Last-minute Rabatte — NUR für Privatkunden (nicht Host/B2B) -->
        <div class="flex justify-between text-brand-dark" x-show="!fixedPriceMode && discount > 0">
          <span>Rabatt (<span x-text="frequencyLabel()"></span>)</span>
          <span>−<span x-text="formatMoney(discount)"></span></span>
        </div>
        <div class="flex justify-between text-amber-700" x-show="!fixedPriceMode && isLastMinute" x-cloak>
          <span>⏰ Last-Minute (−<span x-text="lastMinutePct"></span>%)</span>
          <span>−<span x-text="formatMoney(lastMinuteDiscount)"></span></span>
        </div>
        <!-- Optional Products einzeln -->
        <template x-for="op in selectedOptionalProducts" :key="op.op_id">
          <div class="flex justify-between text-gray-700">
            <span class="flex items-center gap-1 text-[12px]">
              <span x-text="op.icon || '•'"></span>
              <span x-text="op.name"></span>
            </span>
            <span class="font-semibold text-gray-900" x-text="'+' + formatMoney(op.total)"></span>
          </div>
        </template>

        <!-- Netto Zwischensumme -->
        <div class="flex justify-between pt-1.5 border-t border-gray-200 text-gray-800" x-show="basePrice > 0">
          <span>Netto</span>
          <span class="font-semibold" x-text="formatMoney(totalNetto)"></span>
        </div>
        <!-- MwSt -->
        <div class="flex justify-between text-gray-600 text-[12px]" x-show="basePrice > 0">
          <span>zzgl. 19% MwSt</span>
          <span x-text="formatMoney(totalMwst)"></span>
        </div>
        <!-- Brutto -->
        <div class="flex justify-between font-semibold text-gray-900" x-show="basePrice > 0">
          <span>Brutto</span>
          <span x-text="formatMoney(totalBrutto)"></span>
        </div>

        <!-- Treue-Bonus Abzug (falls aktiviert) -->
        <div class="flex justify-between text-green-700" x-show="loyaltyBonus > 0 && useLoyalty" x-cloak>
          <span>🎁 Treue-Bonus</span>
          <span class="font-semibold">−<span x-text="formatMoney(loyaltyBonus)"></span></span>
        </div>

        <!-- Gesamt -->
        <div class="flex justify-between text-lg font-bold pt-2 mt-1 border-t-2 border-gray-300">
          <span class="text-gray-900">Zu zahlen</span>
          <span class="text-brand-dark" x-text="formatMoney(total)"></span>
        </div>
        <div class="text-[10px] text-gray-500 text-right">inkl. MwSt<span x-show="loyaltyBonus > 0 && useLoyalty"> · Bonus abgezogen</span></div>

        <?php if ($isLoyalCustomer): ?>
        <label class="flex items-center gap-2 text-xs text-gray-700 cursor-pointer mt-2 p-2 bg-green-50 rounded-lg border border-green-200">
          <input type="checkbox" x-model="useLoyalty" class="w-4 h-4 text-brand rounded focus:ring-brand"/>
          <span>Treue-Bonus (<?= money($loyaltyBonus) ?>) einlösen</span>
        </label>
        <?php endif; ?>
      </div>

      <!-- Marketing-Einwilligungen (DSGVO) -->
      <div class="mt-4 pt-4 border-t border-gray-200">
        <p class="text-[11px] text-gray-700 mb-2 font-semibold">📬 Möchten Sie Updates von Fleckfrei erhalten?</p>
        <div class="space-y-1.5">
          <label class="flex items-start gap-2 text-[11px] text-gray-700 cursor-pointer">
            <input type="checkbox" x-model="consent.email" class="mt-0.5 w-3.5 h-3.5 rounded text-brand focus:ring-brand"/>
            <span>📧 <strong>E-Mail</strong> — Angebote, Tipps, neue Services</span>
          </label>
          <label class="flex items-start gap-2 text-[11px] text-gray-700 cursor-pointer">
            <input type="checkbox" x-model="consent.whatsapp" class="mt-0.5 w-3.5 h-3.5 rounded text-brand focus:ring-brand"/>
            <span>💬 <strong>WhatsApp</strong> — schnelle Reminder, Terminbestätigungen</span>
          </label>
          <label class="flex items-start gap-2 text-[11px] text-gray-700 cursor-pointer">
            <input type="checkbox" x-model="consent.phone" class="mt-0.5 w-3.5 h-3.5 rounded text-brand focus:ring-brand"/>
            <span>📞 <strong>Telefon/SMS</strong> — wichtige Rückrufe</span>
          </label>
        </div>
        <p class="text-[9px] text-gray-500 mt-2">Sie können Ihre Einwilligung jederzeit unter <a href="/customer/profile.php" class="underline text-brand-dark">Profil</a> widerrufen. DSGVO-konform.</p>
      </div>

      <button @click="submit()" :disabled="loading || !canSubmit()"
              class="w-full mt-4 px-6 py-3.5 bg-brand hover:bg-brand-dark disabled:bg-gray-300 disabled:cursor-not-allowed text-white rounded-xl font-semibold text-sm transition shadow-sm">
        <span x-show="!loading">Termin verbindlich buchen</span>
        <span x-show="loading" x-cloak>Wird gesendet…</span>
      </button>

      <!-- AGB / Datenschutz Hinweis beim Buchen -->
      <p class="text-[10px] text-gray-600 text-center mt-2">
        Mit Klick auf „Verbindlich buchen" akzeptieren Sie unsere
        <a href="/agb.php" target="_blank" class="underline text-brand-dark">AGB</a> und
        <a href="/datenschutz.php" target="_blank" class="underline text-brand-dark">Datenschutzerklärung</a>.
      </p>

      <p class="text-[10px] text-gray-400 text-center mt-3">
        Der angezeigte Preis ist eine Schätzung. Der finale Preis wird nach der Reinigung berechnet und auf der Rechnung ausgewiesen.
      </p>
    </div>
  </div>

  <!-- ============ SUCCESS ============ -->
  <div x-show="submitted" x-cloak class="lg:col-span-3">
    <div class="max-w-xl mx-auto">
      <!-- Hero: Danke -->
      <div class="bg-gradient-to-br from-brand to-brand-dark text-white rounded-2xl p-8 text-center mb-4 shadow-lg relative overflow-hidden">
        <div class="absolute inset-0 opacity-10">
          <svg class="w-full h-full" viewBox="0 0 100 100" preserveAspectRatio="none"><circle cx="20" cy="20" r="2" fill="white"/><circle cx="80" cy="40" r="1.5" fill="white"/><circle cx="50" cy="70" r="2" fill="white"/><circle cx="10" cy="80" r="1" fill="white"/><circle cx="90" cy="90" r="2" fill="white"/></svg>
        </div>
        <div class="relative">
          <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-white/20 backdrop-blur mb-4">
            <span class="text-4xl">🎉</span>
          </div>
          <h2 class="text-2xl sm:text-3xl font-extrabold mb-2">Danke <?= e($greetingName) ?>!</h2>
          <p class="text-white/90 text-base mb-3">Wir kümmern uns jetzt um alles.</p>
          <div class="inline-block bg-white/20 backdrop-blur rounded-lg px-4 py-2">
            <div class="text-[10px] uppercase font-semibold text-white/80 tracking-wider">Buchungsnummer</div>
            <div class="font-mono font-bold text-lg" x-text="result?.booking_id || '—'"></div>
          </div>
        </div>
      </div>

      <!-- Details der Buchung -->
      <div class="card-elev p-5 mb-4">
        <h3 class="font-bold text-gray-900 text-sm mb-3 flex items-center gap-2">
          <svg class="w-4 h-4 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
          Ihre Buchung im Überblick
        </h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between items-center py-1.5 border-b border-gray-100">
            <dt class="text-gray-600">📅 Datum</dt>
            <dd class="font-semibold text-gray-900" x-text="formatDate() + ' · ' + form.time + ' Uhr'"></dd>
          </div>
          <div class="flex justify-between items-center py-1.5 border-b border-gray-100">
            <dt class="text-gray-600">⏱ Dauer</dt>
            <dd class="font-semibold text-gray-900"><span x-text="form.hours"></span> Stunden</dd>
          </div>
          <div class="flex justify-between items-start py-1.5 border-b border-gray-100">
            <dt class="text-gray-600">📍 Adresse</dt>
            <dd class="font-semibold text-gray-900 text-right max-w-[60%]" x-text="form.address || '—'"></dd>
          </div>
          <template x-if="selectedOptionalProducts.length > 0">
            <div class="py-1.5 border-b border-gray-100">
              <dt class="text-gray-600 mb-1">➕ Zusatzleistungen</dt>
              <dd class="space-y-1">
                <template x-for="op in selectedOptionalProducts" :key="op.op_id">
                  <div class="flex justify-between text-xs">
                    <span class="text-gray-800" x-text="(op.icon || '•') + ' ' + op.name"></span>
                    <span class="text-gray-600" x-text="formatMoney(op.total)"></span>
                  </div>
                </template>
              </dd>
            </div>
          </template>
          <div class="flex justify-between items-center pt-2 text-base">
            <dt class="font-bold text-gray-900">Gesamt</dt>
            <dd class="font-extrabold text-brand-dark" x-text="formatMoney(total)"></dd>
          </div>
        </dl>
      </div>

      <!-- Was als nächstes passiert -->
      <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4 text-sm">
        <div class="font-bold text-blue-900 mb-2">📋 Was passiert jetzt?</div>
        <ol class="space-y-1.5 text-blue-800 text-[13px]">
          <li class="flex gap-2"><span class="font-bold">1.</span> Bestätigungs-E-Mail geht in wenigen Minuten raus</li>
          <li class="flex gap-2"><span class="font-bold">2.</span> Wir weisen einen Partner zu — Sie sehen den Namen in „Meine Termine"</li>
          <li class="flex gap-2"><span class="font-bold">3.</span> Sie können jederzeit umbuchen, Notizen für den Partner hinzufügen oder Fotos hochladen</li>
        </ol>
      </div>

      <!-- CTA Buttons — Brand-Farbe durchgängig -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <a href="/customer/calendar.php" class="px-5 py-3.5 bg-brand hover:bg-brand-dark text-white rounded-xl font-bold text-sm text-center shadow-md flex items-center justify-center gap-2 transition">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
          Meine Termine ansehen
        </a>
        <a :href="waFlowUrl" target="_blank" rel="noopener"
           class="px-5 py-3.5 bg-brand-dark hover:bg-brand text-white rounded-xl font-bold text-sm text-center shadow-md flex items-center justify-center gap-2 transition">
          <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347"/></svg>
          <span>Nächste via WhatsApp</span>
          <span class="text-[10px] opacity-80" x-show="currentServiceKeyword" x-text="'· ' + currentServiceKeyword"></span>
        </a>
      </div>

      <!-- Alternativ: nochmal direkt hier buchen -->
      <button @click="resetForm()" class="w-full mt-2 text-xs text-gray-700 hover:text-brand underline py-2">
        Oder nochmal hier auf der Website buchen
      </button>

      <p class="text-center text-xs text-gray-600 mt-4">
        Fragen? <a href="<?= CONTACT_WHATSAPP_URL ?>" class="text-brand-dark hover:underline font-bold" target="_blank">WhatsApp uns</a> · <a href="mailto:<?= CONTACT_EMAIL ?>" class="text-brand-dark hover:underline font-bold"><?= CONTACT_EMAIL ?></a>
      </p>
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
      service: <?= json_encode((string)$prefillServiceId ?: '') ?>,
      hours: <?= json_encode((string) max(MIN_HOURS, ($prefillHours ?: (int)($lastJobDefaults['total_hours'] ?? $lastJobDefaults['j_hours'] ?? 3)))) ?>,
      no_people: <?= json_encode((string)($lastJobDefaults['no_people'] ?? '1')) ?>,
      frequency: 'einmalig',
      date: <?= json_encode($prefillDate) ?>,
      time: <?= json_encode(substr($lastJobDefaults['j_time'] ?? $defaultStart, 0, 5)) ?>,
      time_end: '<?= substr($defaultEnd, 0, 5) ?>',
      address: <?= json_encode($addresses[0] ?? null ? trim($addresses[0]['street'].' '.$addresses[0]['number'].', '.$addresses[0]['postal_code'].' '.$addresses[0]['city']) : '', JSON_UNESCAPED_UNICODE) ?>,
      door_code: '',
      notes: '',
      optional_products: [],
      guest_name: '',
      guest_phone: '',
      guest_checkout_date: '',
      guest_checkout_time: '11:00',
      check_in_date: '',
      check_in_time: '15:00',
      booking_platform: 'airbnb',
    },

    timeslots: [],
    timeslotsLoading: false,
    timeslotsStats: null,
    formatDateDe(iso) {
      if (!iso) return '';
      const [y, m, d] = iso.split('-');
      const wd = ['So','Mo','Di','Mi','Do','Fr','Sa'][new Date(iso).getDay()];
      return `${wd} ${d}.${m}.${y}`;
    },
    monthGrid: [],
    monthAnchor: new Date(),
    monthLabel: '',
    isStammkunde: <?= $isStammkunde ? 'true' : 'false' ?>,
    isHostType: <?= $isAirbnb ? 'true' : 'false' ?>,
    get fixedPriceMode() { return this.isStammkunde || this.isHostType; },
    // Marketing-Consent — prefill aus bestehenden Werten
    consent: {
      email: <?= !empty($customer['consent_email']) ? 'true' : 'false' ?>,
      whatsapp: <?= !empty($customer['consent_whatsapp']) ? 'true' : 'false' ?>,
      phone: <?= !empty($customer['consent_phone']) ? 'true' : 'false' ?>,
    },
    // WhatsApp-Flow: Keyword = Property-Keyword (wa_keyword oder Service-Titel)
    get currentServiceKeyword() {
      const svc = this.servicesMap[this.form.service];
      return svc?.wa_keyword || (svc?.title ? svc.title.split(/[_\s]/)[0] : '') || '';
    },
    get waFlowUrl() {
      const kw = this.currentServiceKeyword || 'Buchung';
      const msg = `Hallo Fleckfrei, ich möchte nochmal buchen für: ${kw}`;
      const phone = '<?= preg_replace('/[^0-9]/', '', CONTACT_WHATSAPP ?? '') ?>';
      const base = phone ? `https://wa.me/${phone}` : '<?= CONTACT_WHATSAPP_URL ?>';
      return `${base}?text=${encodeURIComponent(msg)}`;
    },
    monthAnyPrice: false,
    customerId: <?= (int)$cid ?>,

    init() {
      // If a service is pre-selected, auto-fill its address + door code
      if (this.form.service) {
        this.$nextTick(() => this.applyServiceDefaults());
      }
      // Watch service changes → auto-fill from service record
      this.$watch('form.service', () => { this.applyServiceDefaults(); this.loadTimeslots(); });
      // Watch date + hours + address → load timeslots
      this.$watch('form.date', () => this.loadTimeslots());
      this.$watch('form.hours', () => this.loadTimeslots());
      this.$watch('form.address', () => this.loadTimeslots());
      if (this.form.date) this.loadTimeslots();
    },

    loadMonth() {
      // Calculate month range
      const year = this.monthAnchor.getFullYear();
      const month = this.monthAnchor.getMonth();
      const firstOfMonth = new Date(year, month, 1);
      const lastOfMonth = new Date(year, month + 1, 0);
      const monthNames = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
      this.monthLabel = monthNames[month] + ' ' + year;

      // Base price from selected service
      const svc = this.servicesMap[this.form.service];
      const basePrice = svc?.price || 0;

      // API: fetch availability for this month (up to 31 days)
      const fromStr = firstOfMonth.toISOString().slice(0,10);
      const daysInMonth = lastOfMonth.getDate();
      const params = new URLSearchParams({
        action: 'timeslots/range',
        from: fromStr,
        days: daysInMonth,
        hours: this.form.hours || 2,
        customer_id: this.customerId,
        base_price: basePrice
      });
      if (this.form.address) params.set('address', this.form.address);
      fetch('/api/index.php?' + params.toString(), {
        headers: { 'X-API-Key': '<?= API_KEY ?>' }
      })
      .then(r => r.json())
      .then(d => {
        const dailyByDate = {};
        if (d.success && d.data.daily) {
          d.data.daily.forEach(day => { dailyByDate[day.date] = day; });
          this.isStammkunde = !!d.data.is_stammkunde;
          this.monthAnyPrice = d.data.daily.some(day => day.min_price != null);
        }
        this.buildMonthGrid(year, month, dailyByDate);
      })
      .catch(() => { this.buildMonthGrid(year, month, {}); });
    },

    selectSlot(date, time) {
      this.form.date = date;
      this.form.time = time;
    },

    buildMonthGrid(year, month, dailyByDate) {
      const firstOfMonth = new Date(year, month, 1);
      const lastOfMonth = new Date(year, month + 1, 0);
      const todayStr = new Date().toISOString().slice(0,10);
      // First weekday (0=Sun, 1=Mon) — we want Mon-based (0=Mon)
      let firstWd = firstOfMonth.getDay(); // 0=So, 1=Mo, ... 6=Sa
      firstWd = (firstWd + 6) % 7; // Convert to 0=Mo, 6=So
      const cells = [];
      // Padding before first day
      for (let i = 0; i < firstWd; i++) cells.push({ day: null });
      // Days of month
      for (let d = 1; d <= lastOfMonth.getDate(); d++) {
        const dateStr = year + '-' + String(month+1).padStart(2,'0') + '-' + String(d).padStart(2,'0');
        const dayData = dailyByDate[dateStr];
        const isPast = dateStr < todayStr;
        cells.push({
          day: dayData ? { ...dayData, past: isPast } : { date: dateStr, status: isPast ? 'past' : 'full', max_free: 0, past: isPast }
        });
      }
      // Pad to complete last row
      while (cells.length % 7 !== 0) cells.push({ day: null });
      this.monthGrid = cells;
    },

    shiftMonth(delta) {
      this.monthAnchor = new Date(this.monthAnchor.getFullYear(), this.monthAnchor.getMonth() + delta, 1);
      this.loadMonth();
    },

    goToToday() {
      this.monthAnchor = new Date();
      this.loadMonth();
      this.form.date = new Date().toISOString().slice(0,10);
    },

    selectDay(day) {
      if (day.status === 'full' || day.past) return;
      this.form.date = day.date;
    },

    loadTimeslots() {
      if (!this.form.date) { this.timeslots = []; return; }
      this.timeslotsLoading = true;
      const svc = this.servicesMap[this.form.service];
      const basePrice = svc?.price || 0;
      const params = new URLSearchParams({
        action: 'timeslots',
        date: this.form.date,
        hours: this.form.hours || 2,
        customer_id: this.customerId,
        base_price: basePrice
      });
      if (this.form.address) params.set('address', this.form.address);
      fetch('/api/index.php?' + params.toString(), {
        headers: { 'X-API-Key': '<?= API_KEY ?>' }
      })
      .then(r => r.json())
      .then(d => {
        this.timeslotsLoading = false;
        if (d.success && d.data.slots) {
          this.timeslots = d.data.slots;
          this.timeslotsStats = d.data.stats || null;
          this.isStammkunde = !!d.data.is_stammkunde;
          // Auto-select first available if current selection is not bookable
          const current = this.timeslots.find(s => s.time === this.form.time);
          if (!current || !current.bookable) {
            const first = this.timeslots.find(s => s.bookable);
            if (first) this.form.time = first.time;
          }
        }
      })
      .catch(() => { this.timeslotsLoading = false; });
    },

    applyServiceDefaults() {
      const sid = this.form.service;
      if (!sid || !this.servicesMap[sid]) {
        // Service deselected → release lock
        this.overrideAddress = false;
        return;
      }
      const svc = this.servicesMap[sid];
      // Service has its own address → lock and use it (overrides user selection)
      if (svc.address) {
        this.form.address = svc.address;
        this.overrideAddress = false;
        this.showNewAddr = false;
      }
      if (svc.box_code && !this.form.door_code) {
        this.form.door_code = svc.box_code;
      }
    },
    overrideAddress: false,
    get serviceAddress() {
      const sid = this.form.service;
      if (!sid || !this.servicesMap[sid]) return '';
      return this.servicesMap[sid].address || '';
    },
    showNewAddr: <?= empty($addresses) ? 'true' : 'false' ?>,
    newAddr: { street: '', number: '', postal_code: '', city: '', address_for: 'Wohnung' },
    newAddrSaving: false,
    newAddrError: null,
    frequencies: [
      { value: 'einmalig', label: 'Einmalig', discount: '' },
      { value: 'monatlich', label: 'Monatlich', discount: <?= $discountActive ? json_encode('Sparen Sie ' . rtrim(rtrim(number_format($discountMonthly, 2, ',', ''), '0'), ',') . ' %') : "''" ?> },
      { value: '2wochen', label: 'Alle 2 Wochen', discount: <?= $discountActive ? json_encode('Sparen Sie ' . rtrim(rtrim(number_format($discountBiweekly, 2, ',', ''), '0'), ',') . ' %') : "''" ?> },
      { value: 'woechentlich', label: 'Wöchentlich', discount: <?= $discountActive ? json_encode('Sparen Sie ' . rtrim(rtrim(number_format($discountWeekly, 2, ',', ''), '0'), ',') . ' %') : "''" ?> },
    ],
    loading: false,
    submitted: false,
    error: null,
    result: null,
    servicesMap: <?= json_encode($servicesMap, JSON_UNESCAPED_UNICODE) ?>,
    get basePrice() {
      const opt = document.querySelector(`option[value="${this.form.service}"]`);
      return opt ? parseFloat(opt.dataset.price || 0) : 0;
    },
    get currentMaxGuests() {
      if (!this.form.service) return 0;
      return (this.servicesMap[this.form.service]?.max_guests) || 0;
    },
    get discount() {
      if (this.fixedPriceMode) return 0; // Host/B2B: keine Rabatte
      const rates = <?= $discountActive ? json_encode(['woechentlich' => $discountWeekly/100, '2wochen' => $discountBiweekly/100, 'monatlich' => $discountMonthly/100, 'einmalig' => 0]) : '{einmalig:0, monatlich:0, "2wochen":0, woechentlich:0}' ?>;
      return this.basePrice * parseInt(this.form.hours) * (rates[this.form.frequency] || 0);
    },
    // Last-minute discount: cheaper if booking within threshold (idle slot fill)
    lastMinutePct: <?= $lastMinutePct ?>,
    lastMinuteHours: <?= $lastMinuteHours ?>,
    get isLastMinute() {
      if (this.fixedPriceMode) return false; // Host/B2B: kein Last-Minute-Rabatt
      if (!this.form.date || !this.form.time || this.lastMinutePct <= 0) return false;
      const target = new Date(this.form.date + 'T' + this.form.time);
      const hoursDiff = (target - new Date()) / 3600000;
      return hoursDiff > 0 && hoursDiff <= this.lastMinuteHours;
    },
    get lastMinuteDiscount() {
      if (!this.isLastMinute) return 0;
      return (this.basePrice * parseInt(this.form.hours)) * (this.lastMinutePct / 100);
    },
    floorPrice: <?= $floorPrice ?>,
    loyaltyBonus: <?= $loyaltyBonus ?>,
    useLoyalty: false,
    optionalProductsMap: <?= json_encode(array_map(fn($op) => [
        'op_id' => (int)$op['op_id'],
        'name' => $op['name'],
        'icon' => $op['icon'] ?? '',
        'pricing_type' => $op['pricing_type'],
        'customer_price' => (float)$op['customer_price'],
    ], $optionalProducts ?? []), JSON_UNESCAPED_UNICODE) ?>,
    get selectedOptionalProducts() {
      // Aktuell enthält form.optional_products die Namen-Strings
      // Finde entsprechende Produkte + berechne Preis je nach pricing_type
      const hours = parseInt(this.form.hours) || 2;
      return (this.form.optional_products || []).map(sel => {
        const op = this.optionalProductsMap.find(o => o.name === sel || o.op_id === sel);
        if (!op) return null;
        let total = 0;
        if (op.pricing_type === 'per_hour') total = op.customer_price * hours;
        else if (op.pricing_type === 'percentage') total = (this.basePrice * hours) * (op.customer_price / 100);
        else total = op.customer_price;
        return { ...op, total: Math.round(total * 100) / 100 };
      }).filter(Boolean);
    },
    get optionalProductsTotal() {
      return this.selectedOptionalProducts.reduce((sum, op) => sum + op.total, 0);
    },
    get baseNetto() {
      return Math.max(0, this.basePrice * parseInt(this.form.hours) - this.discount - this.lastMinuteDiscount);
    },
    get totalNetto() {
      return this.baseNetto + this.optionalProductsTotal;
    },
    get totalMwst() {
      return Math.round(this.totalNetto * 0.19 * 100) / 100;
    },
    get totalBrutto() {
      return Math.round((this.totalNetto + this.totalMwst) * 100) / 100;
    },
    get rawTotal() {
      return this.totalBrutto;
    },
    get total() {
      let t = this.totalBrutto;
      if (this.useLoyalty && this.loyaltyBonus > 0) t = Math.max(0, t - this.loyaltyBonus);
      return t;
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
    toggleOption(opId, name) {
      // Unterstützt sowohl Legacy (Name-String) als auch neue DB-IDs
      const key = name || opId;
      const i = this.form.optional_products.findIndex(x => x === key || x.op_id === opId);
      if (i >= 0) this.form.optional_products.splice(i, 1);
      else this.form.optional_products.push(name || opId);
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
      fetch('/customer/address-save.php', {
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
      payload.use_loyalty_bonus = this.useLoyalty;
      payload.estimated_price = this.total;
      payload.consent_email = this.consent.email ? 1 : 0;
      payload.consent_whatsapp = this.consent.whatsapp ? 1 : 0;
      payload.consent_phone = this.consent.phone ? 1 : 0;
      // Consents sofort in customer Tabelle updaten
      fetch('/customer/consent-save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          consent_email: this.consent.email ? 1 : 0,
          consent_whatsapp: this.consent.whatsapp ? 1 : 0,
          consent_phone: this.consent.phone ? 1 : 0
        })
      }).catch(() => {});
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

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
