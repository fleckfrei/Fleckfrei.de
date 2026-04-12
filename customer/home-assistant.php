<?php
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
$title = 'Home Assistant Bridge'; $page = 'smarthome';
$cid = me()['id'];
$customer = one("SELECT * FROM customer WHERE customer_id=?", [$cid]);

if (!in_array($customer['customer_type'] ?? '', ['Airbnb', 'Host', 'Booking', 'Short-Term Rental'], true)) {
    header('Location: /customer/'); exit;
}

// POST: Connect / Test / Disconnect
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) { header('Location: /customer/home-assistant.php?error=csrf'); exit; }
    $act = $_POST['action'] ?? '';

    if ($act === 'connect') {
        $url = rtrim(trim($_POST['ha_url'] ?? ''), '/');
        $token = trim($_POST['access_token'] ?? '');
        $name = trim($_POST['connection_name'] ?? 'Meine Home Assistant');

        if (!filter_var($url, FILTER_VALIDATE_URL) || strlen($token) < 20) {
            header('Location: /customer/home-assistant.php?error=invalid'); exit;
        }

        // Test connection: GET /api/
        $ch = curl_init($url . '/api/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            header('Location: /customer/home-assistant.php?error=connection_failed&code=' . $code); exit;
        }

        $info = json_decode($resp, true);
        $version = $info['version'] ?? null;

        // Fetch states for entity count
        $ch = curl_init($url . '/api/states');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        ]);
        $statesRaw = curl_exec($ch);
        curl_close($ch);
        $states = json_decode($statesRaw, true) ?: [];
        $entityCount = count($states);

        q("INSERT INTO home_assistant_bridges (customer_id_fk, ha_url, access_token, connection_name, version, entities_count, last_sync)
           VALUES (?, ?, ?, ?, ?, ?, NOW())
           ON DUPLICATE KEY UPDATE ha_url=VALUES(ha_url), access_token=VALUES(access_token), connection_name=VALUES(connection_name), version=VALUES(version), entities_count=VALUES(entities_count), last_sync=NOW(), is_active=1",
          [$cid, $url, $token, $name, $version, $entityCount]);

        audit('create', 'home_assistant_bridges', 0, "HA Bridge verbunden: $entityCount Entities gefunden");
        telegramNotify("🏡 Kunde #$cid hat Home Assistant verbunden: $entityCount Entities · v$version");
        header('Location: /customer/home-assistant.php?saved=connected'); exit;
    }

    if ($act === 'disconnect') {
        q("DELETE FROM home_assistant_bridges WHERE customer_id_fk=?", [$cid]);
        audit('delete', 'home_assistant_bridges', 0, 'HA Bridge getrennt');
        header('Location: /customer/home-assistant.php?saved=disconnected'); exit;
    }
}

$bridge = one("SELECT * FROM home_assistant_bridges WHERE customer_id_fk=? AND is_active=1", [$cid]);

// If connected, fetch live entity groups
$entityGroups = [];
if ($bridge) {
    $ch = curl_init($bridge['ha_url'] . '/api/states');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bridge['access_token']],
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $states = $raw ? json_decode($raw, true) : [];
    if (is_array($states)) {
        foreach ($states as $s) {
            $domain = explode('.', $s['entity_id'] ?? '')[0] ?? 'unknown';
            if (!isset($entityGroups[$domain])) $entityGroups[$domain] = 0;
            $entityGroups[$domain]++;
        }
        arsort($entityGroups);
    }
}

$savedMsg = $_GET['saved'] ?? '';
$errorMsg = $_GET['error'] ?? '';

include __DIR__ . '/../includes/layout-customer.php';
?>

<!-- Back button -->
<div class="mb-4">
  <a href="/customer/smarthome.php" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-brand transition">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Zurück
  </a>
</div>

<!-- Hero -->
<div class="mb-6">
  <div class="flex items-center gap-3 mb-2 flex-wrap">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">🏡 Home Assistant Bridge</h1>
    <span class="px-2 py-0.5 rounded-full bg-gradient-to-r from-blue-500 to-cyan-500 text-white text-[10px] font-bold uppercase tracking-wider">Universal</span>
  </div>
  <p class="text-gray-500 text-sm">Verbinden Sie Ihre lokale Home Assistant Instanz — Fleckfrei nutzt automatisch ALLE Ihre Integrationen. Kein einzelnes Setup pro Anbieter.</p>
</div>

<?php if ($savedMsg): ?>
<div class="mb-4 card-elev bg-green-50 border-green-200 p-4 text-sm text-green-800 flex items-center gap-2">
  ✓ <?= match($savedMsg) {
      'connected' => 'Home Assistant erfolgreich verbunden!',
      'disconnected' => 'Bridge getrennt.',
      default => 'Gespeichert.',
  } ?>
</div>
<?php endif; ?>

<?php if ($errorMsg): ?>
<div class="mb-4 card-elev bg-red-50 border-red-200 p-4 text-sm text-red-800 flex items-center gap-2">
  ⚠ <?= match($errorMsg) {
      'invalid' => 'Ungültige URL oder Token zu kurz.',
      'connection_failed' => 'Verbindung fehlgeschlagen. HTTP Code: ' . e($_GET['code'] ?? '?') . ' — Prüfen Sie URL, Token und dass Home Assistant von außen erreichbar ist.',
      default => 'Es ist ein Fehler aufgetreten.',
  } ?>
</div>
<?php endif; ?>

<?php if ($bridge): ?>
<!-- ========================================================== -->
<!-- Verbunden — zeige Status + Entities                        -->
<!-- ========================================================== -->
<div class="card-elev p-6 mb-6 bg-gradient-to-br from-green-50 to-white border-green-200">
  <div class="flex items-start gap-4 flex-wrap">
    <div class="w-14 h-14 rounded-2xl bg-green-500 text-white flex items-center justify-center flex-shrink-0 shadow-lg shadow-green-500/30 text-2xl">✓</div>
    <div class="flex-1 min-w-0">
      <h2 class="font-bold text-gray-900 text-lg"><?= e($bridge['connection_name']) ?></h2>
      <div class="text-sm text-gray-600 mt-1 space-y-1">
        <div>🌐 <code class="text-xs bg-gray-100 px-2 py-0.5 rounded"><?= e($bridge['ha_url']) ?></code></div>
        <div>📦 <strong><?= (int)$bridge['entities_count'] ?></strong> Entities verfügbar
          <?php if ($bridge['version']): ?>· Version <?= e($bridge['version']) ?><?php endif; ?>
        </div>
        <div>⏱ Letzte Synchronisation: <?= $bridge['last_sync'] ? date('d.m.Y H:i', strtotime($bridge['last_sync'])) : '—' ?></div>
      </div>
      <form method="POST" class="mt-3 inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="disconnect"/>
        <button type="submit" onclick="return confirm('Bridge wirklich trennen?')" class="text-xs text-red-600 hover:underline">Bridge trennen</button>
      </form>
    </div>
  </div>
</div>

<?php if (!empty($entityGroups)): ?>
<h2 class="text-lg font-bold text-gray-900 mb-3">Verfügbare Geräte-Kategorien</h2>
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-6">
  <?php
  $domainLabels = [
      'light' => ['💡', 'Lichter'],
      'switch' => ['🔌', 'Schalter'],
      'lock' => ['🔐', 'Schlösser'],
      'sensor' => ['📊', 'Sensoren'],
      'binary_sensor' => ['📡', 'Sensoren'],
      'climate' => ['🌡', 'Klima'],
      'media_player' => ['📺', 'Media'],
      'camera' => ['📹', 'Kameras'],
      'vacuum' => ['🤖', 'Staubsauger'],
      'cover' => ['🪟', 'Rollläden'],
      'fan' => ['🌀', 'Ventilatoren'],
      'water_heater' => ['🚿', 'Warmwasser'],
      'device_tracker' => ['📍', 'Tracker'],
      'person' => ['👤', 'Personen'],
      'weather' => ['☁️', 'Wetter'],
      'automation' => ['⚙️', 'Automationen'],
      'script' => ['📜', 'Skripte'],
      'scene' => ['🎬', 'Szenen'],
      'zone' => ['🗺', 'Zonen'],
  ];
  foreach (array_slice($entityGroups, 0, 16) as $domain => $count):
      [$icon, $label] = $domainLabels[$domain] ?? ['❓', ucfirst($domain)];
  ?>
  <div class="card-elev p-4 text-center">
    <div class="text-3xl mb-1"><?= $icon ?></div>
    <div class="text-xl font-bold text-gray-900"><?= $count ?></div>
    <div class="text-[10px] text-gray-500 uppercase font-semibold tracking-wider mt-0.5"><?= e($label) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card-elev bg-blue-50 border-blue-200 p-4 mb-6">
  <div class="flex items-start gap-2 text-xs text-blue-900">
    <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <div>
      <strong>Partner-Integration aktiv:</strong> Wenn ein Reinigungstermin läuft, kann der zugewiesene Partner 15 Min vor bis 30 Min nach dem Termin Schlösser öffnen und Geräte steuern (z.B. Waschmaschine starten). Alle Aktionen werden protokolliert.
    </div>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ========================================================== -->
<!-- Nicht verbunden — Setup Form                               -->
<!-- ========================================================== -->

<div class="card-elev p-6 mb-6">
  <h2 class="font-bold text-gray-900 text-lg mb-4">Home Assistant verbinden</h2>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Setup instructions -->
    <div>
      <h3 class="text-sm font-bold text-gray-600 uppercase tracking-wider mb-3">So geht's</h3>
      <ol class="space-y-3 text-sm text-gray-700">
        <li class="flex gap-3">
          <span class="w-6 h-6 rounded-full bg-brand text-white text-xs font-bold flex items-center justify-center flex-shrink-0">1</span>
          <div>
            <strong>Long-Lived Access Token erstellen:</strong><br/>
            In Home Assistant: Klicken Sie auf Ihren Avatar unten links → Scrollen nach unten → <strong>"Langlebige Zugriffstoken"</strong> → "Token erstellen" → Namen "Fleckfrei" eingeben → Token kopieren
          </div>
        </li>
        <li class="flex gap-3">
          <span class="w-6 h-6 rounded-full bg-brand text-white text-xs font-bold flex items-center justify-center flex-shrink-0">2</span>
          <div>
            <strong>Home Assistant URL:</strong><br/>
            Die Adresse unter der Ihre HA von außen erreichbar ist. Z.B. <code class="text-xs bg-gray-100 px-1 rounded">https://ha.yourdomain.com</code> oder bei Nabu Casa <code class="text-xs bg-gray-100 px-1 rounded">https://XXX.ui.nabu.casa</code>
          </div>
        </li>
        <li class="flex gap-3">
          <span class="w-6 h-6 rounded-full bg-brand text-white text-xs font-bold flex items-center justify-center flex-shrink-0">3</span>
          <div>
            <strong>In Formular rechts eintragen</strong> → Verbinden
          </div>
        </li>
      </ol>
      <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-800">
        💡 <strong>Nabu Casa</strong> Cloud-User haben die einfachste Setup — einfach Token aus HA holen und Nabu-Casa-URL eintragen. Keine Port-Forwarding, kein VPN nötig.
      </div>
    </div>

    <!-- Connect form -->
    <div>
      <form method="POST" class="space-y-4">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="connect"/>

        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1.5 uppercase tracking-wider">Verbindungs-Name</label>
          <input type="text" name="connection_name" value="Meine Home Assistant" class="w-full px-4 py-3 border-2 border-gray-100 bg-gray-50 rounded-2xl focus:ring-4 focus:ring-brand/10 focus:border-brand focus:bg-white outline-none transition"/>
        </div>

        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1.5 uppercase tracking-wider">Home Assistant URL</label>
          <input type="url" name="ha_url" required placeholder="https://ha.meinhost.de:8123" class="w-full px-4 py-3 border-2 border-gray-100 bg-gray-50 rounded-2xl focus:ring-4 focus:ring-brand/10 focus:border-brand focus:bg-white outline-none transition font-mono text-sm"/>
          <p class="text-[11px] text-gray-400 mt-1">Mit https:// am Anfang, ohne / am Ende</p>
        </div>

        <div>
          <label class="block text-xs font-bold text-gray-600 mb-1.5 uppercase tracking-wider">Long-Lived Access Token</label>
          <textarea name="access_token" required rows="3" placeholder="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..." class="w-full px-4 py-3 border-2 border-gray-100 bg-gray-50 rounded-2xl focus:ring-4 focus:ring-brand/10 focus:border-brand focus:bg-white outline-none transition font-mono text-xs"></textarea>
          <p class="text-[11px] text-gray-400 mt-1">🔒 Token wird verschlüsselt gespeichert</p>
        </div>

        <button type="submit" class="w-full px-6 py-3 bg-gradient-to-r from-blue-500 to-cyan-500 hover:from-blue-600 hover:to-cyan-600 text-white rounded-2xl font-bold shadow-lg shadow-blue-500/30">
          🏡 Bridge verbinden
        </button>
      </form>
    </div>
  </div>
</div>

<!-- Why Home Assistant — Max's reference to "Aurorei 8 / La-Renting" -->
<div class="card-elev p-5 bg-gray-50">
  <h3 class="font-bold text-gray-900 mb-2">Warum Home Assistant?</h3>
  <p class="text-sm text-gray-600 mb-3">Statt jedes Smart-Home-Gerät einzeln zu verbinden (Nuki + Tuya + Yale + Honeywell + LG + ...), nutzen Sie Ihre bestehende Home Assistant Instanz als zentrale Brücke:</p>
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
    <div class="bg-white rounded-xl p-3">
      <div class="text-2xl mb-1">⚡</div>
      <div class="font-semibold text-gray-900 text-sm">Einmal einrichten</div>
      <p class="text-[11px] text-gray-500 mt-0.5">Eine Verbindung = alle Geräte. Kein OAuth-Chaos pro Hersteller.</p>
    </div>
    <div class="bg-white rounded-xl p-3">
      <div class="text-2xl mb-1">🔒</div>
      <div class="font-semibold text-gray-900 text-sm">Volle Kontrolle</div>
      <p class="text-[11px] text-gray-500 mt-0.5">Daten bleiben bei Ihnen. Fleckfrei bekommt nur API-Zugriff den Sie erlauben.</p>
    </div>
    <div class="bg-white rounded-xl p-3">
      <div class="text-2xl mb-1">🌐</div>
      <div class="font-semibold text-gray-900 text-sm">3000+ Integrationen</div>
      <p class="text-[11px] text-gray-500 mt-0.5">Alles was HA unterstützt funktioniert sofort — auch künftige Geräte.</p>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer-customer.php'; ?>
