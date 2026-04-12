<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
$title = 'Live Transport'; $page = 'transport';
$cid = me()['id'];
$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);

// Airbnb/Host-only — the use case is guest arrival coordination
$isHost = in_array($customer['customer_type'] ?? '', ['Airbnb', 'Host', 'Booking', 'Short-Term Rental'], true);
if (!$isHost) {
    header('Location: /customer/'); exit;
}

/**
 * Fetch JSON from a derhuerst transport.rest endpoint.
 * v6.db.transport.rest and v6.bvg.transport.rest block default curl UA → need
 * a real user-agent to get a 200 back.
 */
function cust_fetchTransport(string $url, int $timeout = 6): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT => 'fleckfrei-customer/1.0 (+https://app.fleckfrei.de; info@maxcohost.host)',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

// Station selector — default Berlin Hbf
$stationId  = $_GET['station'] ?? '8011160';  // DB IBNR for Berlin Hbf
$bvgStopId  = $_GET['bvg'] ?? '900003201';    // BVG S+U Hauptbahnhof
$duration   = (int) ($_GET['duration'] ?? 60);

// Preset stations (major Berlin arrival points)
$presetStations = [
    ['id' => '8011160', 'bvg' => '900003201', 'label' => 'Berlin Hauptbahnhof'],
    ['id' => '8089102', 'bvg' => '900100003', 'label' => 'Alexanderplatz'],
    ['id' => '8011102', 'bvg' => '900120003', 'label' => 'Ostbahnhof'],
    ['id' => '8010255', 'bvg' => '900058101', 'label' => 'Südkreuz'],
    ['id' => '8089100', 'bvg' => '900024101', 'label' => 'Gesundbrunnen'],
    ['id' => '8089021', 'bvg' => '900023201', 'label' => 'Zoologischer Garten'],
    ['id' => '8089081', 'bvg' => '900017104', 'label' => 'Spandau'],
];

// Fetch data
$arrivals = cust_fetchTransport("https://v6.db.transport.rest/stops/$stationId/arrivals?duration=$duration&results=15")['arrivals'] ?? [];
$bvgDep = cust_fetchTransport("https://v6.bvg.transport.rest/stops/$bvgStopId/departures?duration=30&results=15")['departures'] ?? [];

$fetchedAt = date('H:i:s');
$currentStation = array_values(array_filter($presetStations, fn($s) => $s['id'] === $stationId))[0] ?? ['label' => 'Station ' . $stationId, 'id' => $stationId, 'bvg' => $bvgStopId];

// Helpers
function cust_delayMinutes(?int $seconds): ?int {
    return $seconds === null ? null : (int) round($seconds / 60);
}
function cust_fmtTime(?string $iso): string {
    if (!$iso) return '--:--';
    try {
        $dt = new DateTime($iso);
        $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        return $dt->format('H:i');
    } catch (Exception $e) { return '--:--'; }
}
function cust_delayBadge(?int $delaySec): string {
    $m = cust_delayMinutes($delaySec);
    if ($m === null) return '<span class="text-[11px] text-gray-400">—</span>';
    if ($m <= 0) return '<span class="text-[11px] font-semibold text-green-600">pünktlich</span>';
    if ($m < 5)  return '<span class="inline-block px-2 py-0.5 rounded-full bg-yellow-50 text-yellow-700 text-[11px] font-semibold">+' . $m . ' min</span>';
    if ($m < 15) return '<span class="inline-block px-2 py-0.5 rounded-full bg-orange-100 text-orange-700 text-[11px] font-semibold">+' . $m . ' min</span>';
    return '<span class="inline-block px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-[11px] font-semibold">+' . $m . ' min</span>';
}
function cust_productIcon(string $product): string {
    return match ($product) {
        'nationalExpress', 'national' => '🚄',
        'regionalExpress', 'regional' => '🚆',
        'suburban' => '🟢',
        'subway' => '🟦',
        'tram' => '🟥',
        'bus' => '🚌',
        'ferry' => '⛴',
        default => '•',
    };
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

<div class="flex items-start justify-between mb-6 flex-wrap gap-4">
  <div>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Live Transport</h1>
    <p class="text-gray-500 mt-1 text-sm">
      Bahn- und ÖPNV-Ankünfte in Berlin — nützlich für Gäste-Koordination bei Check-in.
      Daten direkt von <a href="https://v6.db.transport.rest/" class="underline" target="_blank">Deutsche Bahn</a> &amp;
      <a href="https://v6.bvg.transport.rest/" class="underline" target="_blank">BVG</a>.
    </p>
  </div>
  <div class="text-right">
    <div class="text-[11px] text-gray-500 uppercase font-semibold tracking-wider">Stand</div>
    <div class="text-sm font-mono text-gray-700"><?= $fetchedAt ?></div>
    <button onclick="location.reload()" class="mt-1 text-[11px] px-2 py-1 bg-brand-light text-brand rounded font-semibold hover:bg-brand hover:text-white transition">↻ Aktualisieren</button>
  </div>
</div>

<!-- Station picker -->
<div class="card-elev p-4 mb-6">
  <div class="flex items-start gap-3 flex-wrap">
    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mt-2 mr-2">Station:</div>
    <div class="flex flex-wrap gap-2">
      <?php foreach ($presetStations as $s):
          $active = $s['id'] === $stationId;
      ?>
      <a href="?station=<?= $s['id'] ?>&bvg=<?= $s['bvg'] ?>"
         class="px-3 py-1.5 rounded-full text-xs font-semibold transition <?= $active ? 'bg-brand text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-brand-light hover:text-brand' ?>">
        <?= e($s['label']) ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Stats row -->
<?php
$delayed = array_filter($arrivals, fn($a) => ($a['delay'] ?? 0) > 300);
$severe = array_filter($arrivals, fn($a) => ($a['delay'] ?? 0) > 900);
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <div class="card-elev p-4">
    <div class="text-2xl font-extrabold text-gray-900"><?= count($arrivals) ?></div>
    <div class="text-[11px] text-gray-500 mt-1 uppercase font-semibold">Fernzüge nächste <?= $duration ?> min</div>
  </div>
  <div class="card-elev p-4">
    <div class="text-2xl font-extrabold <?= count($delayed) > 0 ? 'text-orange-600' : 'text-green-600' ?>"><?= count($delayed) ?></div>
    <div class="text-[11px] text-gray-500 mt-1 uppercase font-semibold">Verspätet &gt;5 min</div>
  </div>
  <div class="card-elev p-4">
    <div class="text-2xl font-extrabold <?= count($severe) > 0 ? 'text-red-600' : 'text-gray-400' ?>"><?= count($severe) ?></div>
    <div class="text-[11px] text-gray-500 mt-1 uppercase font-semibold">Stark &gt;15 min</div>
  </div>
  <div class="card-elev p-4">
    <div class="text-2xl font-extrabold text-gray-900"><?= count($bvgDep) ?></div>
    <div class="text-[11px] text-gray-500 mt-1 uppercase font-semibold">BVG Abfahrten (30 min)</div>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

  <!-- Fernzug-Ankünfte -->
  <div class="card-elev overflow-hidden">
    <div class="px-5 py-4 border-b bg-gray-50">
      <h3 class="font-bold text-gray-900 flex items-center gap-2">🚄 Fernzug-Ankünfte</h3>
      <p class="text-[11px] text-gray-500 mt-0.5"><?= e($currentStation['label']) ?> · nächste <?= $duration ?> min · DB + FlixTrain + Regional</p>
    </div>
    <?php if (empty($arrivals)): ?>
      <div class="p-8 text-center text-sm text-gray-400">Keine Ankünfte gefunden (API nicht erreichbar oder Station hat grade keine Züge).</div>
    <?php else: ?>
      <div class="divide-y max-h-[500px] overflow-y-auto">
        <?php foreach ($arrivals as $a):
            $line = $a['line']['name'] ?? '?';
            $product = $a['line']['product'] ?? '';
            $origin = $a['provenance'] ?? ($a['origin']['name'] ?? '?');
            $platform = $a['platform'] ?? ($a['plannedPlatform'] ?? '?');
            $plannedPlatform = $a['plannedPlatform'] ?? $platform;
            $platChanged = $platform !== $plannedPlatform;
            $planned = cust_fmtTime($a['plannedWhen'] ?? null);
            $actual = cust_fmtTime($a['when'] ?? null);
            $delay = $a['delay'] ?? null;
            $cancelled = !empty($a['cancelled']);
        ?>
        <div class="px-5 py-3 flex items-center justify-between gap-3 <?= $cancelled ? 'bg-red-50' : '' ?>">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-0.5">
              <span class="text-base"><?= cust_productIcon($product) ?></span>
              <span class="font-bold text-sm text-gray-900"><?= e($line) ?></span>
              <?php if ($cancelled): ?>
                <span class="inline-block px-1.5 py-0.5 rounded bg-red-600 text-white text-[9px] font-bold">AUSGEFALLEN</span>
              <?php endif; ?>
            </div>
            <div class="text-[11px] text-gray-500 truncate">aus <?= e($origin) ?></div>
          </div>
          <div class="flex flex-col items-end text-right flex-shrink-0">
            <div class="text-sm font-mono">
              <?php if ($delay && $delay > 60): ?>
                <span class="line-through text-gray-400"><?= $planned ?></span>
                <span class="text-orange-600 font-bold"><?= $actual ?></span>
              <?php else: ?>
                <span class="font-semibold"><?= $planned ?></span>
              <?php endif; ?>
            </div>
            <div class="flex items-center gap-1.5 mt-0.5">
              <span class="text-[11px] text-gray-500 <?= $platChanged ? 'text-orange-600 font-bold' : '' ?>">Gl. <?= e($platform) ?></span>
              <?= cust_delayBadge($delay) ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- BVG ÖPNV -->
  <div class="card-elev overflow-hidden">
    <div class="px-5 py-4 border-b bg-gray-50">
      <h3 class="font-bold text-gray-900 flex items-center gap-2">🟦 BVG Abfahrten</h3>
      <p class="text-[11px] text-gray-500 mt-0.5"><?= e($currentStation['label']) ?> · S+U+Tram+Bus · nächste 30 min</p>
    </div>
    <?php if (empty($bvgDep)): ?>
      <div class="p-8 text-center text-sm text-gray-400">Keine BVG-Abfahrten gefunden.</div>
    <?php else: ?>
      <div class="divide-y max-h-[500px] overflow-y-auto">
        <?php foreach ($bvgDep as $d):
            $line = $d['line']['name'] ?? '?';
            $product = $d['line']['product'] ?? '';
            $direction = $d['direction'] ?? '?';
            $planned = cust_fmtTime($d['plannedWhen'] ?? null);
            $actual = cust_fmtTime($d['when'] ?? null);
            $delay = $d['delay'] ?? null;
        ?>
        <div class="px-5 py-3 flex items-center justify-between gap-3">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-0.5">
              <span class="text-base"><?= cust_productIcon($product) ?></span>
              <span class="font-bold text-sm text-gray-900"><?= e($line) ?></span>
            </div>
            <div class="text-[11px] text-gray-500 truncate">→ <?= e($direction) ?></div>
          </div>
          <div class="flex flex-col items-end text-right flex-shrink-0">
            <div class="text-sm font-mono font-semibold"><?= $actual ?: $planned ?></div>
            <?= cust_delayBadge($delay) ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="mt-6 p-4 card-elev bg-brand-light border-brand text-sm">
  <div class="font-semibold text-brand-dark mb-2">💡 Tipp für Gäste-Koordination</div>
  <ul class="text-xs text-gray-700 space-y-1 list-disc list-inside">
    <li>Gast meldet Ankunft per ICE/RE → Station oben wählen → Sie sehen ob pünktlich</li>
    <li>Bei &gt;30 min Verspätung: Reinigungskraft informieren, Check-in verschieben</li>
    <li>BVG-Spalte zeigt Anschluss-Optionen vom Bahnhof zu Ihrer Unterkunft</li>
  </ul>
</div>

<script>
// Auto-refresh every 60s (only when tab visible)
setTimeout(() => { if (!document.hidden) location.reload(); }, 60000);
</script>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
