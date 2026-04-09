<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Buchungen'; $page = 'bookings';

// Local DB for channel_bookings
global $dbLocal;

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

if (FEATURE_SMOOBU) {
    try {
        $todayCheckins = $dbLocal->prepare("SELECT COUNT(*) FROM channel_bookings WHERE check_in=? AND status='confirmed'");
        $todayCheckins->execute([$today]); $todayCount = $todayCheckins->fetchColumn();

        $tomorrowCheckins = $dbLocal->prepare("SELECT COUNT(*) FROM channel_bookings WHERE check_in=? AND status='confirmed'");
        $tomorrowCheckins->execute([$tomorrow]); $tomorrowCount = $tomorrowCheckins->fetchColumn();

        $openBookings = $dbLocal->prepare("SELECT COUNT(*) FROM channel_bookings WHERE check_in >= ? AND status='confirmed'");
        $openBookings->execute([$today]); $openCount = $openBookings->fetchColumn();

        $lastSync = $dbLocal->query("SELECT MAX(synced_at) FROM channel_bookings")->fetchColumn();
    } catch (Exception $e) {
        $todayCount = $tomorrowCount = $openCount = 0; $lastSync = null;
    }
}

include __DIR__ . '/../includes/layout.php';
?>

<?php if (!FEATURE_SMOOBU): ?>
<!-- Setup Card -->
<div class="max-w-xl mx-auto mt-12">
  <div class="bg-white rounded-xl border p-8 text-center">
    <div class="w-16 h-16 rounded-full bg-brand-light flex items-center justify-center mx-auto mb-4">
      <svg class="w-8 h-8 text-brand" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
    </div>
    <h2 class="text-xl font-bold text-gray-900 mb-2">Smoobu verbinden</h2>
    <p class="text-gray-500 mb-6">Verbinde deinen Smoobu Channel Manager um Buchungen von Booking.com, VRBO, Agoda und Airbnb automatisch zu synchronisieren.</p>
    <div class="bg-gray-50 rounded-xl p-5 text-left mb-6">
      <h3 class="text-sm font-semibold text-gray-700 mb-3">Setup in 3 Schritten:</h3>
      <ol class="space-y-2 text-sm text-gray-600">
        <li class="flex gap-2"><span class="font-bold text-brand">1.</span> Gehe zu <strong>Smoobu Dashboard > Einstellungen > API</strong></li>
        <li class="flex gap-2"><span class="font-bold text-brand">2.</span> Kopiere deinen <strong>API Key</strong></li>
        <li class="flex gap-2"><span class="font-bold text-brand">3.</span> Trage ihn in <code class="bg-gray-200 px-1 rounded">includes/config.php</code> ein:<br>
          <code class="text-xs bg-gray-200 px-2 py-1 rounded mt-1 block">define('SMOOBU_API_KEY', 'dein-key-hier');</code>
        </li>
      </ol>
    </div>
    <div class="bg-blue-50 rounded-xl p-4 text-left">
      <h4 class="text-sm font-semibold text-blue-700 mb-1">Webhook (optional):</h4>
      <p class="text-xs text-blue-600">Smoobu > Einstellungen > Webhook URL:</p>
      <code class="text-xs bg-blue-100 px-2 py-1 rounded block mt-1">https://app.<?= SITE_DOMAIN ?>/api/index.php?action=smoobu/webhook</code>
    </div>
  </div>
</div>

<?php else: ?>
<!-- Main Bookings View -->
<div x-data="bookingsApp()" x-init="loadBookings()">

  <!-- Stats -->
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-5">
      <div class="text-sm text-gray-500">Heute Check-ins</div>
      <div class="text-2xl font-bold text-gray-900 mt-1"><?= $todayCount ?></div>
    </div>
    <div class="bg-white rounded-xl border p-5">
      <div class="text-sm text-gray-500">Morgen Check-ins</div>
      <div class="text-2xl font-bold text-gray-900 mt-1"><?= $tomorrowCount ?></div>
    </div>
    <div class="bg-white rounded-xl border p-5">
      <div class="text-sm text-gray-500">Offene Buchungen</div>
      <div class="text-2xl font-bold text-gray-900 mt-1"><?= $openCount ?></div>
      <?php if ($lastSync): ?>
      <div class="text-xs text-gray-400 mt-1">Letzte Sync: <?= date('d.m. H:i', strtotime($lastSync)) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Actions -->
  <div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-3">
      <input type="date" x-model="filterFrom" @change="loadBookings()" class="px-3 py-2 border rounded-xl text-sm"/>
      <span class="text-gray-400">—</span>
      <input type="date" x-model="filterTo" @change="loadBookings()" class="px-3 py-2 border rounded-xl text-sm"/>
      <select x-model="filterChannel" @change="loadBookings()" class="px-3 py-2 border rounded-xl text-sm">
        <option value="">Alle Kanäle</option>
        <option value="Airbnb">Airbnb</option>
        <option value="Booking.com">Booking.com</option>
        <option value="VRBO">VRBO</option>
        <option value="direct">Direkt</option>
      </select>
    </div>
    <button @click="syncNow()" :disabled="syncing" class="px-4 py-2 bg-brand text-white rounded-xl text-sm font-semibold hover:opacity-90 transition">
      <span x-text="syncing ? 'Synchronisiere...' : 'Jetzt synchronisieren'"></span>
    </button>
  </div>

  <!-- Sync result -->
  <div x-show="syncResult" x-transition class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl mb-4 text-sm" x-text="syncResult"></div>

  <!-- Table -->
  <div class="bg-white rounded-xl border overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 border-b">
        <tr>
          <th class="text-left px-4 py-3 font-medium">Gast</th>
          <th class="text-left px-4 py-3 font-medium">Property</th>
          <th class="text-left px-4 py-3 font-medium">Check-in</th>
          <th class="text-left px-4 py-3 font-medium">Check-out</th>
          <th class="text-left px-4 py-3 font-medium">Kanal</th>
          <th class="text-right px-4 py-3 font-medium">Preis</th>
          <th class="text-left px-4 py-3 font-medium">Status</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <template x-for="b in bookings" :key="b.cb_id || b.id">
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3">
              <div class="font-medium" x-text="b.guest_name || b['guest-name'] || '—'"></div>
              <div class="text-xs text-gray-400" x-text="b.guest_email || b.email || ''"></div>
            </td>
            <td class="px-4 py-3" x-text="b.property_name || b.apartment?.name || '—'"></td>
            <td class="px-4 py-3 font-mono text-xs" x-text="fmtDate(b.check_in || b.arrival)"></td>
            <td class="px-4 py-3 font-mono text-xs" x-text="fmtDate(b.check_out || b.departure)"></td>
            <td class="px-4 py-3">
              <span class="px-2 py-1 text-xs rounded-full"
                :class="channelColor(b.channel || b.channel?.name)"
                x-text="b.channel || b.channel?.name || 'direct'"></span>
            </td>
            <td class="px-4 py-3 text-right font-medium" x-text="(b.price || 0).toFixed(2) + ' €'"></td>
            <td class="px-4 py-3">
              <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700" x-text="b.status || 'confirmed'"></span>
            </td>
          </tr>
        </template>
        <template x-if="!loading && bookings.length === 0">
          <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Keine Buchungen im gewählten Zeitraum.</td></tr>
        </template>
        <template x-if="loading">
          <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Lade Buchungen...</td></tr>
        </template>
      </tbody>
    </table>
  </div>
</div>

<?php
$apiKey = API_KEY;
$script = <<<JS
function bookingsApp() {
  return {
    bookings: [],
    loading: false,
    syncing: false,
    syncResult: '',
    filterFrom: new Date().toISOString().slice(0,10),
    filterTo: new Date(Date.now() + 30*86400000).toISOString().slice(0,10),
    filterChannel: '',

    loadBookings() {
      this.loading = true;
      let url = '/api/index.php?action=channel/bookings&from=' + this.filterFrom + '&to=' + this.filterTo;
      if (this.filterChannel) url += '&channel=' + this.filterChannel;
      fetch(url, { headers: {'X-API-Key': '$apiKey'} })
        .then(r => r.json())
        .then(d => { this.bookings = d.data || []; this.loading = false; })
        .catch(() => { this.loading = false; });
    },

    syncNow() {
      this.syncing = true;
      this.syncResult = '';
      fetch('/api/index.php?action=smoobu/sync', {
        method: 'POST',
        headers: {'Content-Type':'application/json', 'X-API-Key':'$apiKey'}
      })
      .then(r => r.json())
      .then(d => {
        this.syncing = false;
        if (d.success) {
          this.syncResult = d.data.created + ' neue, ' + d.data.updated + ' aktualisiert';
          this.loadBookings();
        } else {
          this.syncResult = 'Fehler: ' + (d.error || 'Unbekannt');
        }
      })
      .catch(() => { this.syncing = false; this.syncResult = 'Netzwerk-Fehler'; });
    },

    fmtDate(d) {
      if (!d) return '—';
      const parts = d.split('-');
      return parts.length === 3 ? parts[2] + '.' + parts[1] + '.' + parts[0] : d;
    },

    channelColor(ch) {
      const c = (ch || '').toLowerCase();
      if (c.includes('airbnb')) return 'bg-red-100 text-red-700';
      if (c.includes('booking')) return 'bg-blue-100 text-blue-700';
      if (c.includes('vrbo') || c.includes('homeaway')) return 'bg-purple-100 text-purple-700';
      return 'bg-gray-100 text-gray-700';
    }
  };
}
JS;
?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
