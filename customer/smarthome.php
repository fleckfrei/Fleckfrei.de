<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
$title = 'Smart Home'; $page = 'smarthome';
$cid = me()['id'];
$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);

// Host-only
if (!in_array($customer['customer_type'] ?? '', ['Airbnb', 'Host', 'Booking', 'Short-Term Rental'], true)) {
    header('Location: /customer/'); exit;
}

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Back button -->
<div class="mb-4">
  <a href="/customer/" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

<!-- Hero -->
<div class="mb-6">
  <div class="flex items-center gap-3 mb-2">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Smart Home</h1>
    <span class="px-2 py-0.5 rounded-full bg-gradient-to-r from-brand to-brand-dark text-white text-[10px] font-bold uppercase tracking-wider">by Max Co-Host</span>
  </div>
  <p class="text-gray-500 text-sm">Verbinden Sie Ihre smarten Türschlösser, Thermostate und Geräte — Fleckfrei-Partner bekommen automatisch temporären Zugang, Sie behalten die volle Kontrolle.</p>
</div>

<!-- Supported Providers Grid — all clickable -->
<h2 class="text-lg font-bold text-gray-900 mb-3 mt-8">Unterstützte Anbieter</h2>
<p class="text-xs text-gray-500 mb-4">Klicken Sie auf einen Anbieter — Sie werden direkt zum Partner-Portal weitergeleitet, wo Sie Ihr Konto verbinden können.</p>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">

  <!-- 1. Nuki — LIVE -->
  <a href="/customer/locks.php" class="group block card-elev p-5 hover:border-orange-500 hover:shadow-lg transition bg-gradient-to-br from-orange-50 to-transparent">
    <div class="flex items-start justify-between mb-3">
      <div class="w-12 h-12 rounded-xl bg-orange-500 text-white flex items-center justify-center text-2xl shadow-lg shadow-orange-500/30 group-hover:scale-110 transition">🔐</div>
      <span class="px-2 py-0.5 rounded-full bg-green-500 text-white text-[10px] font-bold uppercase">Live</span>
    </div>
    <h3 class="font-bold text-gray-900">Nuki Smart Lock</h3>
    <p class="text-xs text-gray-500 mt-1 mb-3">Europas meistgenutztes Smart Lock. OAuth2-Authentifizierung, automatische temporäre Zugangscodes.</p>
    <div class="flex items-center gap-2 text-[11px] mb-3 flex-wrap">
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Smart Lock</span>
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Opener</span>
    </div>
    <div class="text-xs font-semibold text-orange-600 flex items-center gap-1">
      Jetzt verbinden
      <svg class="w-3 h-3 group-hover:translate-x-1 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
    </div>
  </a>

  <!-- 2. iCal / Smoobu / Airbnb Sync — LIVE (already built) -->
  <a href="/customer/ical.php" class="group block card-elev p-5 hover:border-purple-500 hover:shadow-lg transition bg-gradient-to-br from-purple-50 to-transparent">
    <div class="flex items-start justify-between mb-3">
      <div class="w-12 h-12 rounded-xl bg-purple-500 text-white flex items-center justify-center text-2xl shadow-lg shadow-purple-500/30 group-hover:scale-110 transition">🔄</div>
      <span class="px-2 py-0.5 rounded-full bg-green-500 text-white text-[10px] font-bold uppercase">Live</span>
    </div>
    <h3 class="font-bold text-gray-900">iCal / Smoobu / Airbnb</h3>
    <p class="text-xs text-gray-500 mt-1 mb-3">Verbinden Sie Ihre Channel-Manager-iCal-Feeds. Automatische Reinigungs-Vorschläge nach jedem Gast-Check-out.</p>
    <div class="flex items-center gap-2 text-[11px] mb-3 flex-wrap">
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Smoobu</span>
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Airbnb</span>
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Booking</span>
    </div>
    <div class="text-xs font-semibold text-purple-600 flex items-center gap-1">
      iCal-Feed verbinden
      <svg class="w-3 h-3 group-hover:translate-x-1 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
    </div>
  </a>

  <!-- 3. Tuya / Smart Life — direct to Tuya IoT Cloud -->
  <a href="https://iot.tuya.com/" target="_blank" rel="noopener" class="group block card-elev p-5 hover:border-blue-500 hover:shadow-lg transition">
    <div class="flex items-start justify-between mb-3">
      <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center text-2xl group-hover:scale-110 transition">🏠</div>
      <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[10px] font-bold uppercase">Phase 2</span>
    </div>
    <h3 class="font-bold text-gray-900">Tuya / Smart Life</h3>
    <p class="text-xs text-gray-500 mt-1 mb-3">Universelle Smart-Home-Plattform. Aqara, Moes, Zemismart — funktioniert mit allen Tuya-kompatiblen Geräten.</p>
    <div class="flex items-center gap-2 text-[11px] mb-3 flex-wrap">
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Lock</span>
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Licht</span>
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Steckdose</span>
    </div>
    <div class="text-xs font-semibold text-blue-600 flex items-center gap-1">
      Zum Tuya IoT Portal
      <svg class="w-3 h-3 group-hover:translate-x-1 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
    </div>
  </a>

  <!-- 4. Yale Home — direct to Yale Home -->
  <a href="https://www.yalehome.com/" target="_blank" rel="noopener" class="group block card-elev p-5 hover:border-yellow-500 hover:shadow-lg transition">
    <div class="flex items-start justify-between mb-3">
      <div class="w-12 h-12 rounded-xl bg-yellow-100 flex items-center justify-center text-2xl group-hover:scale-110 transition">🔒</div>
      <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[10px] font-bold uppercase">Phase 2</span>
    </div>
    <h3 class="font-bold text-gray-900">Yale Home</h3>
    <p class="text-xs text-gray-500 mt-1 mb-3">Yale Linus & Doorman Smart Locks. Yale Access App-Integration mit temporären Codes für Partner.</p>
    <div class="flex items-center gap-2 text-[11px] mb-3 flex-wrap">
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Linus</span>
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Doorman</span>
    </div>
    <div class="text-xs font-semibold text-yellow-700 flex items-center gap-1">
      Zum Yale Home Portal
      <svg class="w-3 h-3 group-hover:translate-x-1 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
    </div>
  </a>

  <!-- 5. Honeywell / Resideo — direct to Honeywell Home -->
  <a href="https://www.honeywellhome.com/" target="_blank" rel="noopener" class="group block card-elev p-5 hover:border-red-500 hover:shadow-lg transition">
    <div class="flex items-start justify-between mb-3">
      <div class="w-12 h-12 rounded-xl bg-red-100 flex items-center justify-center text-2xl group-hover:scale-110 transition">🌡</div>
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 text-[10px] font-bold uppercase">Auf Anfrage</span>
    </div>
    <h3 class="font-bold text-gray-900">Honeywell / Resideo</h3>
    <p class="text-xs text-gray-500 mt-1 mb-3">Total Connect Comfort API für Honeywell Home-Geräte. Thermostate, Kameras, Türschlösser.</p>
    <div class="flex items-center gap-2 text-[11px] mb-3 flex-wrap">
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Lock</span>
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Thermostat</span>
    </div>
    <div class="text-xs font-semibold text-red-600 flex items-center gap-1">
      Zum Honeywell Portal
      <svg class="w-3 h-3 group-hover:translate-x-1 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
    </div>
  </a>

  <!-- 6. LG ThinQ — direct to LG ThinQ -->
  <a href="https://www.lg.com/de/thinq" target="_blank" rel="noopener" class="group block card-elev p-5 hover:border-pink-500 hover:shadow-lg transition">
    <div class="flex items-start justify-between mb-3">
      <div class="w-12 h-12 rounded-xl bg-pink-100 flex items-center justify-center text-2xl group-hover:scale-110 transition">🧺</div>
      <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[10px] font-bold uppercase">Phase 3</span>
    </div>
    <h3 class="font-bold text-gray-900">LG ThinQ</h3>
    <p class="text-xs text-gray-500 mt-1 mb-3">LG Waschmaschinen Serie 8 & höher, Trockner und Smart-Appliances. Partner kann Wäsche-Programme starten nach der Reinigung.</p>
    <div class="flex items-center gap-2 text-[11px] mb-3 flex-wrap">
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Waschmaschine</span>
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Trockner</span>
    </div>
    <div class="text-xs font-semibold text-pink-600 flex items-center gap-1">
      Zum LG ThinQ Portal
      <svg class="w-3 h-3 group-hover:translate-x-1 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
    </div>
  </a>

  <!-- 7. Salto KS — direct to Salto KS Cloud Portal -->
  <a href="https://my.saltoks.com/" target="_blank" rel="noopener" class="group block card-elev p-5 hover:border-red-600 hover:shadow-lg transition">
    <div class="flex items-start justify-between mb-3">
      <div class="w-12 h-12 rounded-xl bg-red-100 flex items-center justify-center text-2xl group-hover:scale-110 transition">🏢</div>
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 text-[10px] font-bold uppercase">Enterprise</span>
    </div>
    <h3 class="font-bold text-gray-900">Salto KS</h3>
    <p class="text-xs text-gray-500 mt-1 mb-3">Professionelle Zugangskontrolle für B2B-Immobilien. OAuth2, Enterprise SLA, unbegrenzte Türen.</p>
    <div class="flex items-center gap-2 text-[11px] mb-3 flex-wrap">
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">B2B</span>
    </div>
    <div class="text-xs font-semibold text-red-600 flex items-center gap-1">
      Zum Salto KS Cloud
      <svg class="w-3 h-3 group-hover:translate-x-1 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
    </div>
  </a>

  <!-- 8. SimonsVoss — direct to SimonsVoss -->
  <a href="https://www.simons-voss.com/de/produkte/software/mobilekey.html" target="_blank" rel="noopener" class="group block card-elev p-5 hover:border-gray-700 hover:shadow-lg transition">
    <div class="flex items-start justify-between mb-3">
      <div class="w-12 h-12 rounded-xl bg-gray-200 flex items-center justify-center text-2xl group-hover:scale-110 transition">🔑</div>
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 text-[10px] font-bold uppercase">Auf Anfrage</span>
    </div>
    <h3 class="font-bold text-gray-900">SimonsVoss</h3>
    <p class="text-xs text-gray-500 mt-1 mb-3">MobileKey API für elektronische Schließsysteme. Speziell für größere Immobilien mit vielen Türen.</p>
    <div class="flex items-center gap-2 text-[11px] mb-3 flex-wrap">
      <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">MobileKey</span>
    </div>
    <div class="text-xs font-semibold text-gray-700 flex items-center gap-1">
      Zu SimonsVoss MobileKey
      <svg class="w-3 h-3 group-hover:translate-x-1 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
    </div>
  </a>

  <!-- 9. Klassische Schlüsselbox -->
  <a href="/customer/services.php" class="group block card-elev p-5 bg-gray-50 hover:border-brand hover:shadow-lg transition">
    <div class="flex items-start justify-between mb-3">
      <div class="w-12 h-12 rounded-xl bg-gray-200 flex items-center justify-center text-2xl group-hover:scale-110 transition">🗝️</div>
      <span class="px-2 py-0.5 rounded-full bg-green-500 text-white text-[10px] font-bold uppercase">Aktiv</span>
    </div>
    <h3 class="font-bold text-gray-900">Klassische Schlüsselbox</h3>
    <p class="text-xs text-gray-500 mt-1 mb-3">Bereits verfügbar: Speichern Sie den Code für Ihre Schlüsselbox im Service-Profil. Nur dem zugewiesenen Partner sichtbar.</p>
    <div class="flex items-center gap-2 text-[11px] mb-3 flex-wrap">
      <span class="px-2 py-0.5 rounded-full bg-gray-200 text-gray-700">Türcode</span>
    </div>
    <div class="text-xs font-semibold text-brand flex items-center gap-1">
      In Services verwalten
      <svg class="w-3 h-3 group-hover:translate-x-1 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
    </div>
  </a>
</div>

<!-- Benefits -->
<h2 class="text-lg font-bold text-gray-900 mb-3">Vorteile für Sie</h2>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
  <div class="card-elev p-5">
    <div class="text-2xl mb-2">⏱</div>
    <h3 class="font-bold text-gray-900 mb-1">Temporäre Codes</h3>
    <p class="text-xs text-gray-500">Partner erhält 15 Min vor dem Job einen automatischen Zugangscode. 30 Min nach Job wird er automatisch widerrufen.</p>
  </div>
  <div class="card-elev p-5">
    <div class="text-2xl mb-2">📋</div>
    <h3 class="font-bold text-gray-900 mb-1">Lückenloser Audit-Log</h3>
    <p class="text-xs text-gray-500">Jede Türöffnung wird protokolliert: Wer, wann, wie lange. Sie sehen alles in Echtzeit.</p>
  </div>
  <div class="card-elev p-5">
    <div class="text-2xl mb-2">🔒</div>
    <h3 class="font-bold text-gray-900 mb-1">Keine Schlüsselübergabe</h3>
    <p class="text-xs text-gray-500">Keine physischen Schlüssel mehr verschenken. Kein Verlust-Risiko. Kein Austausch bei Personalwechsel.</p>
  </div>
  <div class="card-elev p-5">
    <div class="text-2xl mb-2">🤖</div>
    <h3 class="font-bold text-gray-900 mb-1">Automatisierung</h3>
    <p class="text-xs text-gray-500">Verknüpfung mit Smoobu/Airbnb: Gast-Check-in = Gast-Code erzeugt, Check-out = Reinigungs-Code erzeugt.</p>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
