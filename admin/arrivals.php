<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Ankünfte Berlin'; $page = 'arrivals';

/**
 * Fetch JSON from a derhuerst transport.rest endpoint.
 * These public APIs (v6.db.transport.rest, v6.bvg.transport.rest) require a
 * non-default User-Agent — using curl's default gets you a 503.
 */
function fetchTransport(string $url, int $timeout = 6): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT => 'fleckfrei-admin/1.0 (+https://app.fleckfrei.de; info@maxcohost.host)',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

// Berlin Hbf station id is 8011160 (DB IBNR)
// BVG Alexanderplatz stop id is 900100003
// BVG S+U Hauptbahnhof stop id is 900003201 (if we want BVG view of Hbf)
$arrivals = fetchTransport('https://v6.db.transport.rest/stops/8011160/arrivals?duration=60&results=15')['arrivals'] ?? [];
$bvgHbf = fetchTransport('https://v6.bvg.transport.rest/stops/900003201/departures?duration=15&results=10')['departures'] ?? [];
$bvgAlex = fetchTransport('https://v6.bvg.transport.rest/stops/900100003/departures?duration=15&results=10')['departures'] ?? [];

$fetchedAt = date('H:i:s');

function delayMinutes(?int $seconds): ?int {
    if ($seconds === null) return null;
    return (int) round($seconds / 60);
}

function fmtTime(?string $iso): string {
    if (!$iso) return '--:--';
    try {
        $dt = new DateTime($iso);
        $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        return $dt->format('H:i');
    } catch (Exception $e) { return '--:--'; }
}

function delayBadge(?int $delaySec): string {
    $m = delayMinutes($delaySec);
    if ($m === null) return '<span class="text-xs text-gray-400">—</span>';
    if ($m <= 0) return '<span class="text-xs font-medium text-green-600">pünktlich</span>';
    if ($m < 5)  return '<span class="inline-block px-2 py-0.5 rounded-full bg-yellow-50 text-yellow-700 text-xs font-semibold">+' . $m . ' min</span>';
    if ($m < 15) return '<span class="inline-block px-2 py-0.5 rounded-full bg-orange-100 text-orange-700 text-xs font-semibold">+' . $m . ' min</span>';
    return '<span class="inline-block px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-xs font-semibold">+' . $m . ' min</span>';
}

function productIcon(string $product): string {
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

include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-6 flex items-center justify-between">
  <div>
    <h2 class="text-2xl font-bold">Ankünfte Berlin <span class="text-sm font-normal text-gray-500">— Live-Daten für Gast-Koordination</span></h2>
    <p class="text-sm text-gray-500 mt-1">
      Wenn dein Airbnb-Gast Bahn/ÖPNV meldet, kannst du hier den Live-Status sehen.
      Daten von <a href="https://v6.db.transport.rest/" class="underline" target="_blank">Deutsche Bahn</a> und
      <a href="https://v6.bvg.transport.rest/" class="underline" target="_blank">BVG</a> (beide gratis, kein Key).
    </p>
  </div>
  <div class="text-right">
    <div class="text-xs text-gray-500">Letzte Abfrage</div>
    <div class="text-sm font-mono"><?= $fetchedAt ?></div>
    <button onclick="location.reload()" class="mt-1 text-xs px-2 py-1 bg-blue-50 text-blue-600 rounded hover:bg-blue-100">↻ Aktualisieren</button>
  </div>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-bold"><?= count($arrivals) ?></div>
    <div class="text-xs text-gray-500">Fernzüge nächste 60 min</div>
  </div>
  <?php
    $delayed = array_filter($arrivals, fn($a) => ($a['delay'] ?? 0) > 300);
    $big = array_filter($arrivals, fn($a) => ($a['delay'] ?? 0) > 900);
  ?>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-bold <?= count($delayed) > 0 ? 'text-orange-600' : 'text-green-600' ?>"><?= count($delayed) ?></div>
    <div class="text-xs text-gray-500">davon verspätet (>5 min)</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-bold <?= count($big) > 0 ? 'text-red-600' : 'text-gray-400' ?>"><?= count($big) ?></div>
    <div class="text-xs text-gray-500">stark verspätet (>15 min)</div>
  </div>
  <div class="bg-white rounded-xl border p-4">
    <div class="text-2xl font-bold"><?= count($bvgHbf) + count($bvgAlex) ?></div>
    <div class="text-xs text-gray-500">BVG Abfahrten (15 min)</div>
  </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

  <!-- Fernzug-Ankünfte Berlin Hbf -->
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="px-4 py-3 border-b bg-gray-50">
      <h3 class="font-semibold">🚄 Fernzug-Ankünfte — Berlin Hbf</h3>
      <p class="text-xs text-gray-500">Nächste 60 Minuten · DB + FlixTrain + Regionalzüge</p>
    </div>
    <?php if (empty($arrivals)): ?>
      <div class="p-6 text-center text-sm text-gray-500">Keine Ankünfte gefunden (API nicht erreichbar oder nachts).</div>
    <?php else: ?>
      <div class="divide-y">
        <?php foreach ($arrivals as $a):
            $line = $a['line']['name'] ?? '?';
            $product = $a['line']['product'] ?? '';
            $origin = $a['provenance'] ?? ($a['origin']['name'] ?? '?');
            $platform = $a['platform'] ?? ($a['plannedPlatform'] ?? '?');
            $plannedPlatform = $a['plannedPlatform'] ?? $platform;
            $platChanged = $platform !== $plannedPlatform;
            $planned = fmtTime($a['plannedWhen'] ?? null);
            $actual = fmtTime($a['when'] ?? null);
            $delay = $a['delay'] ?? null;
            $cancelled = !empty($a['cancelled']);
        ?>
        <div class="px-4 py-3 flex items-center justify-between <?= $cancelled ? 'bg-red-50' : '' ?>">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1">
              <span class="text-lg"><?= productIcon($product) ?></span>
              <span class="font-bold text-sm"><?= htmlspecialchars($line) ?></span>
              <?php if ($cancelled): ?>
                <span class="inline-block px-2 py-0.5 rounded bg-red-600 text-white text-[10px] font-bold">AUSGEFALLEN</span>
              <?php endif; ?>
            </div>
            <div class="text-xs text-gray-600 truncate">aus <?= htmlspecialchars($origin) ?></div>
          </div>
          <div class="flex flex-col items-end ml-3">
            <div class="text-sm font-mono">
              <?php if ($delay && $delay > 60): ?>
                <span class="line-through text-gray-400"><?= $planned ?></span>
                <span class="text-orange-600 font-bold"><?= $actual ?></span>
              <?php else: ?>
                <span><?= $planned ?></span>
              <?php endif; ?>
            </div>
            <div class="flex items-center gap-1 mt-0.5">
              <span class="text-xs text-gray-500 <?= $platChanged ? 'text-orange-600 font-bold' : '' ?>">Gl. <?= htmlspecialchars($platform) ?></span>
              <?= delayBadge($delay) ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- BVG ÖPNV -->
  <div class="bg-white rounded-xl border overflow-hidden">
    <div class="px-4 py-3 border-b bg-gray-50">
      <h3 class="font-semibold">🟦 BVG Abfahrten</h3>
      <p class="text-xs text-gray-500">S+U Hauptbahnhof · Alexanderplatz · nächste 15 min</p>
    </div>
    <?php
      $bvgAll = array_merge(
        array_map(fn($d) => $d + ['_origin' => 'Hbf'], $bvgHbf),
        array_map(fn($d) => $d + ['_origin' => 'Alex'], $bvgAlex)
      );
      // sort by when
      usort($bvgAll, fn($a, $b) => strcmp($a['when'] ?? $a['plannedWhen'] ?? '', $b['when'] ?? $b['plannedWhen'] ?? ''));
    ?>
    <?php if (empty($bvgAll)): ?>
      <div class="p-6 text-center text-sm text-gray-500">Keine Abfahrten gefunden.</div>
    <?php else: ?>
      <div class="divide-y">
        <?php foreach (array_slice($bvgAll, 0, 15) as $d):
            $line = $d['line']['name'] ?? '?';
            $product = $d['line']['product'] ?? '';
            $direction = $d['direction'] ?? '?';
            $planned = fmtTime($d['plannedWhen'] ?? null);
            $actual = fmtTime($d['when'] ?? null);
            $delay = $d['delay'] ?? null;
            $origin = $d['_origin'] ?? '';
        ?>
        <div class="px-4 py-2 flex items-center justify-between">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="text-base"><?= productIcon($product) ?></span>
              <span class="font-bold text-sm"><?= htmlspecialchars($line) ?></span>
              <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600"><?= $origin ?></span>
            </div>
            <div class="text-xs text-gray-600 truncate">→ <?= htmlspecialchars($direction) ?></div>
          </div>
          <div class="flex flex-col items-end ml-3">
            <div class="text-sm font-mono"><?= $actual ?: $planned ?></div>
            <?= delayBadge($delay) ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded-xl text-sm">
  <strong class="text-blue-900">Session-11 Roadmap:</strong>
  <ul class="mt-2 list-disc list-inside text-blue-800 space-y-1">
    <li>Smoobu-Integration: wenn Gast "ICE 691" / "FlixBus" / Flugnummer in Buchung angibt → Live-Status hier anzeigen</li>
    <li>Auto-Benachrichtigung an Putzfrau wenn Zug >30 min verspätet</li>
    <li>Flug-Tracking via AviationStack + OpenSky hinzufügen</li>
    <li>Cluj/Wien ÖBB-Integration für Rumänien-Gäste</li>
  </ul>
</div>

<script>
// Auto-refresh alle 60 Sekunden (nur wenn Tab aktiv)
let refreshTimer;
function scheduleRefresh() {
  clearTimeout(refreshTimer);
  refreshTimer = setTimeout(() => {
    if (!document.hidden) location.reload();
    else document.addEventListener('visibilitychange', () => { if (!document.hidden) location.reload(); }, { once: true });
  }, 60000);
}
scheduleRefresh();
</script>
