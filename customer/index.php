<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
$title = 'Mein Konto'; $page = 'dashboard';
$user = me();
$cid = $user['id'];

$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);
$today = date('Y-m-d');

// Next upcoming job
$nextJob = one("
    SELECT j.*, s.title as stitle, e.display_name as edisplay, e.profile_pic as eavatar
    FROM jobs j
    LEFT JOIN services s ON j.s_id_fk = s.s_id
    LEFT JOIN employee e ON j.emp_id_fk = e.emp_id
    WHERE j.customer_id_fk = ?
      AND j.j_date >= ?
      AND j.status = 1
      AND j.job_status NOT IN ('CANCELLED', 'COMPLETED')
    ORDER BY j.j_date ASC, j.j_time ASC
    LIMIT 1
", [$cid, $today]);

// Open invoices
$unpaid = customerCan('invoices') ? all("
    SELECT * FROM invoices
    WHERE customer_id_fk = ? AND invoice_paid = 'no' AND remaining_price > 0
    ORDER BY issue_date DESC
", [$cid]) : [];
$totalUnpaid = array_sum(array_column($unpaid, 'remaining_price'));

// Counts
$upcomingCount = (int) val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk = ? AND j_date >= ? AND status = 1 AND job_status NOT IN ('CANCELLED','COMPLETED')", [$cid, $today]);
$completedCount = (int) val("SELECT COUNT(*) FROM jobs WHERE customer_id_fk = ? AND job_status = 'COMPLETED' AND status = 1", [$cid]);

// THIS MONTH stats — all-in-one summary
$thisMonth = date('Y-m');
$monthStart = date('Y-m-01');
$monthJobs = all("
    SELECT j.*, s.price as sprice, r.stars as r_stars
    FROM jobs j
    LEFT JOIN services s ON j.s_id_fk = s.s_id
    LEFT JOIN job_ratings r ON r.j_id_fk = j.j_id
    WHERE j.customer_id_fk = ? AND j.j_date LIKE ? AND j.status = 1
", [$cid, "$thisMonth%"]);

$mhHours = 0; $mhCost = 0; $mhDone = 0; $mhPlanned = 0; $mhCancel = 0;
$mhStars = 0; $mhRated = 0;
foreach ($monthJobs as $j) {
    if ($j['job_status'] === 'COMPLETED') {
        $mhDone++;
        $hrs = max(MIN_HOURS, $j['total_hours'] ?: $j['j_hours']);
        $mhHours += $hrs;
        $mhCost += $hrs * ($j['sprice'] ?: 0);
        if ($j['r_stars']) { $mhRated++; $mhStars += $j['r_stars']; }
    } elseif ($j['job_status'] === 'CANCELLED') {
        $mhCancel++;
    } else {
        $mhPlanned++;
    }
}
$mhAvgRating = $mhRated > 0 ? round($mhStars / $mhRated, 1) : 0;
$mhMonthLabel = strtr(date('F Y'), [
    'January'=>'Januar','February'=>'Februar','March'=>'März','April'=>'April',
    'May'=>'Mai','June'=>'Juni','July'=>'Juli','August'=>'August',
    'September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Dezember'
]);

// Host check for transport widget
$isHostCustomer = in_array($customer['customer_type'] ?? '', ['Airbnb', 'Host', 'Booking', 'Short-Term Rental'], true);

// Rumänien-Hilfe: 1% vom offenen Betrag (live) — so weiß der Kunde wie viel bei Zahlung gespendet wird
$donationRate = 0.01;
$donationOpen = round($totalUnpaid * $donationRate, 2);

// Personalized greeting — use surname for business, first name for private
$custFullName = trim($customer['name'] ?? '');
$custSurname = trim($customer['surname'] ?? '');
$isBusiness = in_array($customer['customer_type'] ?? '', ['Airbnb', 'B2B', 'Host', 'Business', 'Booking', 'Short-Term Rental', 'Firma', 'GmbH'], true);
if ($isBusiness) {
    // Business: Firmenname
    $displayName = $custFullName;
} else {
    // Private: "Herr/Frau Nachname" wenn Surname gesetzt, sonst erster Vorname
    $displayName = $custSurname ? $custSurname : (explode(' ', $custFullName)[0] ?? '');
}
$greetingName = $displayName ?: 'Willkommen';

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Welcome header with live clock + weather -->
<div class="mb-6" x-data="dashLive()" x-init="init()">
  <div class="flex items-start justify-between flex-wrap gap-4">
    <div>
      <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">
        Guten Tag, <?= e($greetingName) ?> 👋
      </h1>
      <p class="text-gray-500 mt-1 text-sm">Hier ist Ihr persönliches Dashboard.</p>
    </div>
    <div class="flex items-center gap-3 flex-wrap">
      <!-- Live Clock -->
      <div class="bg-white rounded-2xl border border-gray-100 px-4 py-2 shadow-sm flex items-center gap-3">
        <svg class="w-5 h-5 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <div>
          <div class="text-lg font-bold text-gray-900 font-mono" x-text="clock"></div>
          <div class="text-[10px] text-gray-500 uppercase font-semibold" x-text="dateLabel"></div>
        </div>
      </div>
      <!-- Weather -->
      <div class="bg-gradient-to-br from-blue-50 to-amber-50 rounded-2xl border border-gray-100 px-4 py-2 shadow-sm flex items-center gap-3 min-w-[140px]">
        <div class="text-3xl" x-text="weather.icon"></div>
        <div>
          <div class="text-lg font-bold text-gray-900"><span x-text="weather.temp"></span>°C</div>
          <div class="text-[10px] text-gray-500 uppercase font-semibold">Berlin · <span x-text="weather.label"></span></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Berlin News — für alle Kundentypen (Privat, Host, B2B) -->
<div class="mb-6" x-data="newsWidget()" x-init="loadNews()">
  <div class="card-elev overflow-hidden">
    <!-- Header -->
    <div class="px-5 py-3 border-b border-gray-100 bg-gradient-to-r from-brand/10 via-brand/5 to-transparent flex items-center justify-between">
      <div class="flex items-center gap-2.5">
        <div class="w-8 h-8 rounded-lg bg-brand/10 flex items-center justify-center">
          <span class="text-base">📰</span>
        </div>
        <div>
          <h3 class="font-bold text-gray-900 text-sm leading-tight">Berlin Nachrichten</h3>
          <div class="text-[10px] text-gray-500 flex items-center gap-1.5">
            <span class="w-1.5 h-1.5 bg-green-500 rounded-full animate-pulse"></span>
            <span x-text="news.length + ' Artikel live'"></span>
          </div>
        </div>
      </div>
      <div class="flex items-center gap-1.5">
        <select x-model="bezirk" @change="saveBezirk(); loadNews()" class="text-[11px] font-semibold border border-gray-200 rounded-lg px-2 py-1 bg-white focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none">
          <option value="">Ganz Berlin</option>
          <option value="mitte">Mitte</option>
          <option value="kreuzberg">Kreuzberg</option>
          <option value="friedrichshain">Friedrichshain</option>
          <option value="prenzlauer_berg">Prenzlauer Berg</option>
          <option value="charlottenburg">Charlottenburg</option>
          <option value="wilmersdorf">Wilmersdorf</option>
          <option value="neukoelln">Neukölln</option>
          <option value="schoeneberg">Schöneberg</option>
          <option value="pankow">Pankow</option>
          <option value="tempelhof">Tempelhof</option>
          <option value="steglitz">Steglitz</option>
          <option value="spandau">Spandau</option>
          <option value="reinickendorf">Reinickendorf</option>
          <option value="marzahn">Marzahn</option>
          <option value="lichtenberg">Lichtenberg</option>
          <option value="treptow">Treptow-Köpenick</option>
        </select>
        <button @click="loadNews()" :disabled="loading" class="w-8 h-8 rounded-lg hover:bg-white/70 text-gray-500 hover:text-brand flex items-center justify-center transition" title="Aktualisieren">
          <svg class="w-4 h-4" :class="loading ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        </button>
      </div>
    </div>

    <!-- Loading / Empty -->
    <template x-if="news.length === 0 && loading">
      <div class="p-8 text-center text-xs text-gray-400">Lade aktuelle Nachrichten...</div>
    </template>
    <template x-if="news.length === 0 && !loading">
      <div class="p-8 text-center text-xs text-gray-400">Keine Nachrichten verfügbar</div>
    </template>

    <!-- Content -->
    <template x-if="news.length > 0">
      <div class="p-4">
        <!-- Featured article (first one) -->
        <template x-if="news[0]">
          <a :href="news[0].link" target="_blank" rel="noopener"
             class="block mb-3 p-4 rounded-xl bg-gradient-to-br from-brand/5 to-brand/10 hover:from-brand/10 hover:to-brand/15 border border-brand/10 transition group">
            <div class="flex items-center gap-2 mb-2">
              <span class="px-2 py-0.5 rounded-md text-[10px] font-bold text-white bg-brand uppercase tracking-wide">Top</span>
              <span class="text-[10px] font-semibold text-brand" x-text="news[0].source"></span>
              <span class="text-[10px] text-gray-400">·</span>
              <span class="text-[10px] text-gray-500" x-text="news[0].date"></span>
            </div>
            <div class="font-bold text-gray-900 text-[15px] leading-snug line-clamp-3 group-hover:text-brand transition" x-text="news[0].title"></div>
            <div class="mt-2 text-[11px] text-brand font-semibold flex items-center gap-1">
              Artikel lesen
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            </div>
          </a>
        </template>

        <!-- Remaining articles — compact grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          <template x-for="n in news.slice(1, 9)" :key="n.link">
            <a :href="n.link" target="_blank" rel="noopener"
               class="block p-3 rounded-lg hover:bg-gray-50 border border-gray-100 hover:border-brand/30 transition group">
              <div class="flex items-start gap-2.5">
                <div class="w-1 self-stretch rounded-full flex-shrink-0"
                     :class="sourceColor(n.source)"></div>
                <div class="flex-1 min-w-0">
                  <div class="font-semibold text-gray-900 text-[12px] leading-snug line-clamp-2 group-hover:text-brand transition" x-text="n.title"></div>
                  <div class="text-[10px] text-gray-400 mt-1.5 flex items-center gap-1.5">
                    <span class="font-semibold" :class="sourceTextColor(n.source)" x-text="n.source"></span>
                    <span>·</span>
                    <span x-text="n.date"></span>
                  </div>
                </div>
              </div>
            </a>
          </template>
        </div>

        <!-- Attribution footer — make clear this is aggregated, not Fleckfrei -->
        <div class="mt-3 pt-3 border-t border-gray-100 text-center">
          <div class="text-[10px] text-gray-400">
            Quellen <span x-text="source"></span> · aggregiert via RSS · Artikelrechte bei Verlag
          </div>
        </div>
      </div>
    </template>
  </div>
</div>

<!-- Rumänien-Hilfe — live 1% vom offenen Rechnungsbetrag -->
<?php if ($donationOpen > 0): ?>
<a href="https://www.rumaenienhilfe-spiez.ch/detailseite-unterst%C3%BCtzung-alter-menschen" target="_blank" rel="noopener"
   class="block mb-6 rounded-2xl bg-gradient-to-r from-amber-50 to-red-50 border border-amber-200 px-5 py-4 hover:shadow-md transition">
  <div class="flex items-center gap-4">
    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-400 to-red-500 text-white flex items-center justify-center flex-shrink-0 shadow text-2xl">🤝</div>
    <div class="flex-1 min-w-0">
      <div class="text-sm text-gray-700">
        Offener Betrag <strong><?= money($totalUnpaid) ?></strong> → <strong class="text-amber-700 text-base"><?= money($donationOpen) ?></strong> gehen an <strong>Rumänien-Hilfe</strong>
      </div>
      <div class="text-[11px] text-gray-500 mt-0.5">1 % Ihrer Rechnung · automatisch bei Zahlung · <span class="text-amber-700 underline">mehr erfahren</span></div>
    </div>
    <svg class="w-4 h-4 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
  </div>
</a>
<?php endif; ?>

<!-- Open invoice warning -->
<?php if ($totalUnpaid > 0): ?>
<div class="card-elev border-red-200 bg-red-50 p-5 mb-6 flex items-start gap-4">
  <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
  </div>
  <div class="flex-1 min-w-0">
    <div class="font-semibold text-red-900"><?= count($unpaid) ?> offene Rechnung<?= count($unpaid) === 1 ? '' : 'en' ?></div>
    <div class="text-sm text-red-700 mt-0.5">Gesamtbetrag: <strong><?= money($totalUnpaid) ?></strong></div>
  </div>
  <a href="/customer/invoices.php" class="flex-shrink-0 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-semibold whitespace-nowrap">Jetzt bezahlen</a>
</div>
<?php endif; ?>

<!-- Stats row -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="card-elev p-5">
    <div class="text-3xl font-extrabold text-brand"><?= $upcomingCount ?></div>
    <div class="text-xs text-gray-500 mt-1 uppercase font-semibold tracking-wider">Anstehende Termine</div>
  </div>
  <div class="card-elev p-5">
    <div class="text-3xl font-extrabold text-gray-900"><?= $completedCount ?></div>
    <div class="text-xs text-gray-500 mt-1 uppercase font-semibold tracking-wider">Abgeschlossen</div>
  </div>
  <div class="card-elev p-5">
    <div class="text-3xl font-extrabold <?= $totalUnpaid > 0 ? 'text-red-600' : 'text-green-600' ?>"><?= money($totalUnpaid) ?></div>
    <div class="text-xs text-gray-500 mt-1 uppercase font-semibold tracking-wider">Offen</div>
  </div>
  <a href="/customer/booking.php" class="card-elev p-5 flex items-center justify-between hover:bg-brand-light group">
    <div>
      <div class="text-base font-bold text-brand">Neuer Termin</div>
      <div class="text-xs text-gray-500 mt-1">Jetzt buchen</div>
    </div>
    <div class="w-10 h-10 rounded-full bg-brand text-white flex items-center justify-center group-hover:bg-brand-dark transition">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
    </div>
  </a>
</div>

<!-- Next appointment highlight -->
<?php if ($nextJob):
    $cd = '';
    $target = strtotime($nextJob['j_date'] . ' ' . $nextJob['j_time']);
    $diff = $target - time();
    if ($diff > 0) {
        $days = floor($diff / 86400);
        $hours = floor(($diff % 86400) / 3600);
        if ($days > 0) $cd = "in $days Tag" . ($days > 1 ? 'en' : '');
        elseif ($hours > 0) $cd = "in $hours Std";
        else $cd = "gleich";
    }
?>
<div class="card-elev p-6 mb-6 bg-gradient-to-br from-white to-brand-light relative overflow-hidden">
  <div class="absolute top-0 right-0 w-32 h-32 opacity-10">
    <svg fill="currentColor" class="text-brand" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
  </div>
  <div class="relative z-10">
    <div class="text-[11px] uppercase font-bold tracking-wider text-brand mb-2">Nächster Termin</div>
    <div class="text-xl sm:text-2xl font-bold text-gray-900 mb-1">
      <?= date('d.m.Y', strtotime($nextJob['j_date'])) ?> · <?= substr($nextJob['j_time'], 0, 5) ?> Uhr
      <?php if ($cd): ?><span class="text-brand text-sm font-semibold ml-2">(<?= $cd ?>)</span><?php endif; ?>
    </div>
    <div class="text-sm text-gray-600 mb-4">
      <?= e($nextJob['stitle'] ?? 'Reinigungsservice') ?>
      <?php if (!empty($nextJob['emp_id_fk'])): ?> · mit <a href="/customer/partner.php?id=<?= (int)$nextJob['emp_id_fk'] ?>" class="text-brand font-semibold hover:underline"><?= e(partnerDisplayName($nextJob)) ?></a><?php endif; ?>
    </div>
    <a href="/customer/jobs.php" class="inline-flex items-center gap-2 text-sm font-semibold text-brand hover:text-brand-dark">
      Alle Termine anzeigen
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
    </a>
  </div>
</div>
<?php endif; ?>

<!-- ========================================================== -->
<!-- LIVE PARTNER TRACKING — Uber-style map when partner is en route -->
<!-- ========================================================== -->
<div x-data="partnerTracker()" x-init="load()" x-show="hasActive" x-cloak class="mb-6">
  <div class="card-elev overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 bg-gradient-to-r from-emerald-50 to-transparent flex items-center justify-between">
      <div class="flex items-center gap-2.5">
        <div class="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center">
          <span class="text-base">📍</span>
        </div>
        <div>
          <h3 class="font-bold text-gray-900 text-sm">Live — Ihr Partner unterwegs</h3>
          <div class="text-[10px] text-gray-500 flex items-center gap-1.5">
            <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
            <span x-text="statusText"></span>
          </div>
        </div>
      </div>
      <div class="text-right">
        <div class="text-[11px] text-gray-400">ETA</div>
        <div class="text-base font-extrabold text-emerald-600" x-text="etaText">—</div>
      </div>
    </div>

    <!-- Map -->
    <div id="partnerMap" class="w-full" style="height: 280px; background: #f1f5f9;"></div>

    <!-- Info row -->
    <div class="px-5 py-3 border-t border-gray-100 flex items-center justify-between gap-3 flex-wrap text-xs">
      <div class="flex items-center gap-2 min-w-0">
        <div class="w-8 h-8 rounded-full bg-emerald-500 text-white flex items-center justify-center flex-shrink-0 font-bold">
          <template x-if="data.partner_pic"><img :src="'/uploads/' + data.partner_pic" class="w-8 h-8 rounded-full object-cover"/></template>
          <template x-if="!data.partner_pic"><span x-text="initial"></span></template>
        </div>
        <div class="min-w-0">
          <div class="font-bold text-gray-900 truncate" x-text="data.partner_name || 'Ihr Partner'"></div>
          <div class="text-[10px] text-gray-500 truncate" x-text="data.service_title"></div>
        </div>
      </div>
      <div class="text-right">
        <div class="text-[10px] text-gray-400" x-show="data.distance_km !== null">Entfernung</div>
        <div class="font-semibold text-gray-900" x-text="data.distance_km !== null ? data.distance_km + ' km' : ''"></div>
      </div>
    </div>

    <div class="px-5 py-2 bg-gray-50 text-[10px] text-gray-400 text-center" x-show="data.updated_min_ago !== undefined">
      GPS aktualisiert vor <span x-text="data.updated_min_ago"></span> Min · automatische Aktualisierung alle 30s
    </div>
  </div>
</div>

<!-- Leaflet for the live map (only loaded if tracking is active) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<style>
@keyframes pulse { 0%, 100% { transform: scale(1); opacity: 0.6; } 50% { transform: scale(1.4); opacity: 0; } }
#partnerMap .leaflet-marker-icon > div { pointer-events: none; }
</style>

<!-- ========================================================== -->
<!-- MEIN MONAT — All-in-one summary for current month          -->
<!-- ========================================================== -->
<a href="/customer/workhours.php?month=<?= $thisMonth ?>" class="block card-elev p-0 mb-6 hover:border-brand transition overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-100 bg-gradient-to-r from-brand/5 to-transparent flex items-center justify-between">
    <div class="flex items-center gap-2">
      <span class="text-lg">📊</span>
      <h3 class="font-bold text-gray-900">Mein Monat <span class="text-gray-400 font-normal">— <?= $mhMonthLabel ?></span></h3>
    </div>
    <span class="text-xs text-brand font-semibold flex items-center gap-1">
      Details
      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </span>
  </div>
  <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-gray-100 border-b border-gray-100">
    <div class="p-4 text-center">
      <div class="text-2xl font-bold text-gray-900"><?= $mhDone ?></div>
      <div class="text-[10px] text-gray-500 uppercase font-semibold tracking-wider mt-0.5">Erledigt</div>
    </div>
    <div class="p-4 text-center">
      <div class="text-2xl font-bold text-gray-900"><?= number_format($mhHours, 1) ?>h</div>
      <div class="text-[10px] text-gray-500 uppercase font-semibold tracking-wider mt-0.5">Stunden</div>
    </div>
    <div class="p-4 text-center">
      <div class="text-2xl font-bold text-gray-900"><?= money($mhCost) ?></div>
      <div class="text-[10px] text-gray-500 uppercase font-semibold tracking-wider mt-0.5">Kosten</div>
    </div>
    <div class="p-4 text-center">
      <div class="text-2xl font-bold text-gray-900"><?= $mhAvgRating > 0 ? number_format($mhAvgRating, 1) . '★' : '—' ?></div>
      <div class="text-[10px] text-gray-500 uppercase font-semibold tracking-wider mt-0.5">Ø Bewertung</div>
    </div>
  </div>
  <?php if ($mhPlanned > 0 || $mhCancel > 0): ?>
  <div class="px-5 py-3 bg-gray-50 flex flex-wrap items-center gap-4 text-xs text-gray-600">
    <?php if ($mhPlanned > 0): ?>
    <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-brand"></span><?= $mhPlanned ?> geplant</div>
    <?php endif; ?>
    <?php if ($mhCancel > 0): ?>
    <div class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-gray-400"></span><?= $mhCancel ?> abgesagt</div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</a>

<!-- ========================================================== -->
<!-- Quick actions                                              -->
<!-- ========================================================== -->
<div class="grid grid-cols-2 sm:grid-cols-3 <?= $isHostCustomer ? 'lg:grid-cols-5' : 'lg:grid-cols-4' ?> gap-4">
  <a href="/customer/calendar.php" class="card-elev p-5 hover:border-brand transition">
    <div class="text-2xl mb-2">🗓️</div>
    <div class="font-semibold text-gray-900 text-sm">Kalender</div>
    <div class="text-xs text-gray-500 mt-0.5">Monats-Übersicht</div>
  </a>
  <a href="/customer/jobs.php" class="card-elev p-5 hover:border-brand transition">
    <div class="text-2xl mb-2">📅</div>
    <div class="font-semibold text-gray-900 text-sm">Meine Termine</div>
    <div class="text-xs text-gray-500 mt-0.5">Alle Buchungen</div>
  </a>
  <a href="/customer/invoices.php" class="card-elev p-5 hover:border-brand transition">
    <div class="text-2xl mb-2">🧾</div>
    <div class="font-semibold text-gray-900 text-sm">Rechnungen</div>
    <div class="text-xs text-gray-500 mt-0.5">Zahlungen & Belege</div>
  </a>
  <a href="/customer/messages.php" class="card-elev p-5 hover:border-brand transition">
    <div class="text-2xl mb-2">💬</div>
    <div class="font-semibold text-gray-900 text-sm">Chat</div>
    <div class="text-xs text-gray-500 mt-0.5">An den Partner</div>
  </a>
</div>

<?php if ($isHostCustomer): ?>
<!-- ========================================================== -->
<!-- TRANSPORT WIDGET — inline für Hosts, keine separate Page   -->
<!-- ========================================================== -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6" x-data="transportWidget()" x-init="loadDepartures()">

  <!-- Berlin Hbf Ankünfte -->
  <div class="card-elev overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 bg-gradient-to-r from-red-50 to-transparent flex items-center justify-between">
      <div class="flex items-center gap-2">
        <span class="text-lg">🚆</span>
        <h3 class="font-bold text-gray-900 text-sm">Berlin Hbf — Ankünfte</h3>
      </div>
      <button @click="loadDepartures()" class="text-xs text-brand hover:text-brand-dark" :disabled="loading">
        <svg class="w-4 h-4" :class="loading ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
      </button>
    </div>
    <div class="divide-y divide-gray-100 max-h-72 overflow-y-auto">
      <template x-if="arrivals.length === 0 && !loading">
        <div class="p-6 text-center text-xs text-gray-400">Keine Ankünfte verfügbar</div>
      </template>
      <template x-if="loading && arrivals.length === 0">
        <div class="p-6 text-center text-xs text-gray-400">Lade...</div>
      </template>
      <template x-for="a in arrivals.slice(0, 5)" :key="a.tripId">
        <div class="px-4 py-2.5 flex items-center justify-between hover:bg-gray-50 transition">
          <div class="min-w-0 flex-1">
            <div class="font-semibold text-gray-900 text-xs truncate" x-text="a.line"></div>
            <div class="text-[11px] text-gray-500 truncate" x-text="'von ' + a.origin"></div>
          </div>
          <div class="text-right flex-shrink-0 ml-2">
            <div class="font-mono text-xs font-bold" :class="a.delay > 0 ? 'text-red-600' : 'text-gray-900'" x-text="a.time"></div>
            <div x-show="a.delay > 0" class="text-[9px] text-red-600" x-text="'+' + a.delay + ' min'"></div>
          </div>
        </div>
      </template>
    </div>
  </div>

  <!-- Berlin Alexanderplatz -->
  <div class="card-elev overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 bg-gradient-to-r from-amber-50 to-transparent flex items-center justify-between">
      <div class="flex items-center gap-2">
        <span class="text-lg">🚌</span>
        <h3 class="font-bold text-gray-900 text-sm">Alexanderplatz — BVG</h3>
      </div>
    </div>
    <div class="divide-y divide-gray-100 max-h-72 overflow-y-auto">
      <template x-if="bvg.length === 0 && !loading">
        <div class="p-6 text-center text-xs text-gray-400">Keine Daten verfügbar</div>
      </template>
      <template x-for="b in bvg.slice(0, 5)" :key="b.tripId">
        <div class="px-4 py-2.5 flex items-center justify-between hover:bg-gray-50 transition">
          <div class="min-w-0 flex-1">
            <div class="font-semibold text-gray-900 text-xs truncate" x-text="b.line"></div>
            <div class="text-[11px] text-gray-500 truncate" x-text="'nach ' + b.direction"></div>
          </div>
          <div class="text-right flex-shrink-0 ml-2">
            <div class="font-mono text-xs font-bold" x-text="b.time"></div>
          </div>
        </div>
      </template>
    </div>
  </div>
</div>
<?php endif; ?>

<?php /* Berlin News wurde nach oben verschoben für bessere Host-Übersicht */ ?>

<!-- Quick WA-Booking tile — with name instruction so n8n can auto-match -->
<?php
// Pick the best recognizable name for this customer (business → Firmenname, private → Vorname)
$waDisplayName = trim($customer['surname'] ?? '') ?: trim(explode(' ', $custFullName)[0] ?? $custFullName) ?: 'Ihr Name';
?>
<div class="mt-6 rounded-2xl overflow-hidden border-2 border-brand bg-gradient-to-br from-brand-light via-white to-brand-light shadow-sm">
  <div class="p-5">
    <div class="flex items-start gap-4 mb-4">
      <div class="w-12 h-12 rounded-full bg-brand text-white flex items-center justify-center flex-shrink-0 shadow-lg shadow-brand/30">
        <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487"/></svg>
      </div>
      <div class="flex-1 min-w-0">
        <div class="font-bold text-gray-900 text-base">Schneller per WhatsApp buchen</div>
        <div class="text-xs text-gray-600 mt-0.5">Schreiben Sie uns direkt — wir organisieren alles für Sie.</div>
      </div>
    </div>

    <!-- Instruction: name prefix so bot matches customer -->
    <div class="bg-brand-light border border-brand/20 rounded-xl p-3 mb-3">
      <div class="text-[11px] font-bold text-brand-dark uppercase tracking-wide mb-1.5 flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Wichtig — beginnen Sie Ihre Nachricht so:
      </div>
      <div class="bg-white border border-brand/10 rounded-lg px-3 py-2 font-mono text-sm text-gray-900 flex items-center justify-between gap-2" x-data="{ copied: false }">
        <span>Name: <strong><?= e($waDisplayName) ?></strong></span>
        <button @click="navigator.clipboard.writeText('Name: <?= e($waDisplayName) ?>'); copied = true; setTimeout(() => copied = false, 2000)"
                class="text-[10px] font-semibold text-brand hover:text-brand-dark px-2 py-1 bg-brand/5 rounded transition whitespace-nowrap">
          <span x-show="!copied">Kopieren</span>
          <span x-show="copied" x-cloak>✓ Kopiert</span>
        </button>
      </div>
      <div class="mt-2 space-y-1 text-[11px] text-gray-700">
        <div class="flex items-start gap-1.5">
          <svg class="w-3 h-3 text-brand mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
          <span>Sobald Sie das Keyword benutzen, werden Sie automatisch erkannt</span>
        </div>
        <div class="flex items-start gap-1.5">
          <svg class="w-3 h-3 text-brand mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
          <span><strong>Direkt mit Ihrem Partner chatten</strong> — keine Warteschleife</span>
        </div>
        <div class="flex items-start gap-1.5">
          <svg class="w-3 h-3 text-brand mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
          <span><strong>Neue Termine buchen oder ändern</strong> — alles per WhatsApp</span>
        </div>
      </div>
    </div>

    <a href="<?= CONTACT_WHATSAPP_URL ?>" target="_blank" rel="noopener"
       class="w-full flex items-center justify-center gap-2 py-3 bg-brand hover:bg-brand-dark text-white rounded-xl font-bold text-sm transition shadow-lg shadow-brand/20">
      Jetzt WhatsApp öffnen
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
    </a>
  </div>
</div>

<!-- Dashboard widgets JavaScript -->
<script>
function dashLive() {
  return {
    clock: '--:--',
    dateLabel: '',
    weather: { temp: '--', icon: '☁️', label: '...' },

    init() {
      this.tick();
      setInterval(() => this.tick(), 1000);
      this.loadWeather();
    },

    tick() {
      const d = new Date();
      this.clock = d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
      this.dateLabel = d.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' });
    },

    async loadWeather() {
      // Open-Meteo (kein API-Key nötig)
      try {
        const r = await fetch('https://api.open-meteo.com/v1/forecast?latitude=52.52&longitude=13.41&current_weather=true&timezone=Europe/Berlin');
        const d = await r.json();
        const t = Math.round(d.current_weather?.temperature || 0);
        const code = d.current_weather?.weathercode || 0;
        const icons = { 0:'☀️', 1:'🌤️', 2:'⛅', 3:'☁️', 45:'🌫️', 48:'🌫️', 51:'🌦️', 53:'🌧️', 55:'🌧️', 61:'🌧️', 63:'🌧️', 65:'⛈️', 71:'🌨️', 73:'❄️', 75:'❄️', 80:'🌦️', 81:'🌧️', 82:'⛈️', 95:'⛈️', 96:'⛈️', 99:'⛈️' };
        const labels = { 0:'Sonnig', 1:'Heiter', 2:'Wolkig', 3:'Bedeckt', 45:'Nebel', 48:'Nebel', 51:'Nieselregen', 53:'Regen', 55:'Regen', 61:'Regen', 63:'Regen', 65:'Starkregen', 71:'Schnee', 73:'Schnee', 75:'Schnee', 80:'Schauer', 81:'Regen', 82:'Starkregen', 95:'Gewitter', 96:'Gewitter', 99:'Gewitter' };
        this.weather = { temp: t, icon: icons[code] || '☁️', label: labels[code] || 'Wetter' };
      } catch(e) {
        this.weather = { temp: '--', icon: '☁️', label: 'Offline' };
      }
    },
  };
}

function transportWidget() {
  return {
    arrivals: [],
    bvg: [],
    loading: false,

    async loadDepartures() {
      this.loading = true;
      try {
        // DB API — Berlin Hbf arrivals
        const r1 = await fetch('https://v6.db.transport.rest/stops/8011160/arrivals?duration=30&results=5');
        const j1 = await r1.json();
        this.arrivals = (j1.arrivals || j1 || []).slice(0, 10).map(a => ({
          tripId: a.tripId || Math.random(),
          line: a.line?.name || 'Zug',
          origin: a.origin?.name || a.provenance || '—',
          time: a.when ? new Date(a.when).toLocaleTimeString('de-DE', {hour:'2-digit', minute:'2-digit'}) : '--:--',
          delay: a.delay ? Math.round(a.delay / 60) : 0,
        }));
      } catch(e) { /* silent */ }

      try {
        // BVG API — Alexanderplatz departures
        const r2 = await fetch('https://v6.bvg.transport.rest/stops/900100003/departures?duration=30&results=5');
        const j2 = await r2.json();
        this.bvg = (j2.departures || j2 || []).slice(0, 10).map(b => ({
          tripId: b.tripId || Math.random(),
          line: b.line?.name || 'Bus',
          direction: b.direction || '—',
          time: b.when ? new Date(b.when).toLocaleTimeString('de-DE', {hour:'2-digit', minute:'2-digit'}) : '--:--',
        }));
      } catch(e) { /* silent */ }

      this.loading = false;
    },
  };
}

function partnerTracker() {
  return {
    data: {},
    hasActive: false,
    statusText: '',
    etaText: '—',
    initial: '?',
    map: null,
    partnerMarker: null,
    serviceMarker: null,
    pollTimer: null,

    async load() {
      try {
        const r = await fetch('/api/customer-track-partner.php', { credentials: 'same-origin' });
        const d = await r.json();
        if (!d.success || d.status === 'no_active_job') {
          this.hasActive = false;
          return;
        }
        this.data = d;
        this.hasActive = true;
        this.statusText = d.status === 'RUNNING' ? 'Job läuft gerade' : 'Partner unterwegs';
        this.etaText = d.eta_min !== undefined ? ('~' + d.eta_min + ' Min') : '—';
        this.initial = (d.partner_name || '?').charAt(0).toUpperCase();
        this.$nextTick(() => this.renderMap());
        // Poll every 30s
        if (!this.pollTimer) this.pollTimer = setInterval(() => this.refresh(), 30000);
      } catch(e) {
        this.hasActive = false;
      }
    },

    async refresh() {
      try {
        const r = await fetch('/api/customer-track-partner.php', { credentials: 'same-origin' });
        const d = await r.json();
        if (!d.success || d.status === 'no_active_job') {
          this.hasActive = false;
          if (this.pollTimer) { clearInterval(this.pollTimer); this.pollTimer = null; }
          return;
        }
        this.data = d;
        this.statusText = d.status === 'RUNNING' ? 'Job läuft gerade' : 'Partner unterwegs';
        this.etaText = d.eta_min !== undefined ? ('~' + d.eta_min + ' Min') : '—';
        this.updateMap();
      } catch(e) {}
    },

    renderMap() {
      if (this.map || !window.L) {
        if (!window.L) { setTimeout(() => this.renderMap(), 500); }
        return;
      }
      var el = document.getElementById('partnerMap');
      if (!el) return;

      // Center on partner if available, else service, else fallback Berlin
      var centerLat = this.data.partner_lat || this.data.service_lat || 52.52;
      var centerLng = this.data.partner_lng || this.data.service_lng || 13.405;

      this.map = L.map('partnerMap', { zoomControl: true, attributionControl: false }).setView([centerLat, centerLng], 13);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18 }).addTo(this.map);

      // Service marker (home pin)
      if (this.data.service_lat && this.data.service_lng) {
        var homeIcon = L.divIcon({
          html: '<div style="background:#2E7D6B;color:white;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.3);font-size:16px;">🏠</div>',
          iconSize: [32, 32], iconAnchor: [16, 16], className: '',
        });
        this.serviceMarker = L.marker([this.data.service_lat, this.data.service_lng], { icon: homeIcon })
          .addTo(this.map).bindPopup('Ihr Objekt');
      }

      // Partner marker (pulsing dot)
      if (this.data.partner_lat && this.data.partner_lng) {
        var partnerIcon = L.divIcon({
          html: '<div style="position:relative;"><div style="background:#10b981;color:white;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.3);font-size:14px;font-weight:bold;">' + this.initial + '</div><div style="position:absolute;top:-4px;left:-4px;width:40px;height:40px;border-radius:50%;background:rgba(16,185,129,0.3);animation:pulse 2s infinite;"></div></div>',
          iconSize: [32, 32], iconAnchor: [16, 16], className: '',
        });
        this.partnerMarker = L.marker([this.data.partner_lat, this.data.partner_lng], { icon: partnerIcon })
          .addTo(this.map).bindPopup(this.data.partner_name || 'Partner');

        // Fit bounds if both markers present
        if (this.serviceMarker) {
          var group = L.featureGroup([this.partnerMarker, this.serviceMarker]);
          this.map.fitBounds(group.getBounds(), { padding: [40, 40] });
        }
      }
    },

    updateMap() {
      if (!this.map) { this.renderMap(); return; }
      if (this.data.partner_lat && this.data.partner_lng) {
        if (this.partnerMarker) {
          this.partnerMarker.setLatLng([this.data.partner_lat, this.data.partner_lng]);
        } else {
          this.renderMap();
        }
      }
    },
  };
}

function newsWidget() {
  return {
    news: [],
    loading: false,
    source: '',
    bezirk: localStorage.getItem('ff_news_bezirk') || '',

    saveBezirk() {
      localStorage.setItem('ff_news_bezirk', this.bezirk || '');
    },

    async loadNews() {
      this.loading = true;
      this.news = [];
      // Server-side proxy — reliable, cached 15min, parses multiple Berlin feeds
      try {
        const url = '/api/news-feed.php' + (this.bezirk ? ('?bezirk=' + encodeURIComponent(this.bezirk)) : '');
        const r = await fetch(url, { credentials: 'same-origin' });
        const d = await r.json();
        if (d.success && d.items && d.items.length > 0) {
          this.news = d.items;
          this.source = d.source;
        } else {
          this.news = [];
          this.source = this.bezirk ? 'Keine Treffer für diesen Bezirk' : 'offline';
        }
      } catch(e) {
        this.source = 'offline';
      }
      this.loading = false;
    },

    // Colored left-bar per source — gives visual rhythm to the grid
    sourceColor(src) {
      if (!src) return 'bg-gray-300';
      if (src.includes('Tagesspiegel')) return 'bg-blue-500';
      if (src.includes('Berliner Zeitung')) return 'bg-red-500';
      if (src.includes('Welt')) return 'bg-amber-500';
      if (src.includes('rbb')) return 'bg-purple-500';
      return 'bg-brand';
    },
    sourceTextColor(src) {
      if (!src) return 'text-gray-500';
      if (src.includes('Tagesspiegel')) return 'text-blue-600';
      if (src.includes('Berliner Zeitung')) return 'text-red-600';
      if (src.includes('Welt')) return 'text-amber-600';
      if (src.includes('rbb')) return 'text-purple-600';
      return 'text-brand';
    },
  };
}
</script>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
