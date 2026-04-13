<?php
/**
 * Admin: Partner Live-Status — Uber-style wer arbeitet gerade, wer ist frei
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Partner Live'; $page = 'partners-live';
include __DIR__ . '/../includes/layout.php';
?>

<div x-data="partnersLive()" x-init="load(); setInterval(load, 15000)" class="space-y-5">

  <!-- Stats -->
  <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
    <div class="bg-white rounded-xl border p-4 text-center">
      <div class="text-2xl font-bold text-green-600" x-text="summary.working || 0"></div>
      <div class="text-xs text-gray-600 mt-1">🟢 Arbeitet jetzt</div>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
      <div class="text-2xl font-bold text-amber-600" x-text="summary.starting_soon || 0"></div>
      <div class="text-xs text-gray-600 mt-1">🟡 Startet gleich</div>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
      <div class="text-2xl font-bold text-blue-600" x-text="summary.available || 0"></div>
      <div class="text-xs text-gray-600 mt-1">🔵 Verfügbar</div>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
      <div class="text-2xl font-bold text-gray-500" x-text="summary.offline || 0"></div>
      <div class="text-xs text-gray-600 mt-1">⚪ Offline</div>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
      <div class="text-2xl font-bold text-red-600" x-text="summary.overdue || 0"></div>
      <div class="text-xs text-gray-600 mt-1">🔴 Überfällig</div>
    </div>
  </div>

  <!-- Auto-refresh Indikator -->
  <div class="flex items-center justify-between">
    <div class="text-sm text-gray-700">
      <span class="font-semibold">Stand:</span> <span x-text="nowTime"></span>
      <span class="text-gray-500 ml-2">• auto-refresh alle 15 Sek.</span>
    </div>
    <button @click="load()" class="text-xs px-3 py-1.5 border rounded-lg hover:bg-gray-50 text-gray-700">🔄 Neu laden</button>
  </div>

  <!-- Partner Cards -->
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
    <template x-for="p in partners" :key="p.emp_id">
      <div class="bg-white rounded-xl border p-4"
           :class="{
             'border-l-4 border-l-green-500 bg-green-50/30': p.status === 'working',
             'border-l-4 border-l-amber-500 bg-amber-50/30': p.status === 'starting_soon',
             'border-l-4 border-l-blue-400': p.status === 'available',
             'border-l-4 border-l-gray-300': p.status === 'offline',
             'border-l-4 border-l-red-500 bg-red-50/30': p.status === 'overdue'
           }">
        <div class="flex items-start justify-between mb-2">
          <div class="flex-1 min-w-0">
            <div class="font-bold text-gray-900" x-text="p.name"></div>
            <div class="text-xs text-gray-600" x-text="p.phone"></div>
          </div>
          <span class="text-2xl"
            x-text="p.status === 'working' ? '🟢' : (p.status === 'starting_soon' ? '🟡' : (p.status === 'available' ? '🔵' : (p.status === 'overdue' ? '🔴' : '⚪')))"></span>
        </div>
        <div class="text-sm text-gray-800 font-medium" x-text="p.status_text"></div>
        <template x-if="p.free_at">
          <div class="text-xs text-brand-dark font-semibold mt-1">
            ➜ Frei ab <span x-text="p.free_at"></span>
          </div>
        </template>
        <template x-if="p.current_address">
          <div class="text-xs text-gray-600 mt-2 truncate">📍 <span x-text="p.current_address"></span></div>
        </template>
        <template x-if="p.next_address && p.status !== 'working'">
          <div class="text-xs text-gray-500 mt-1 truncate">→ <span x-text="p.next_address"></span></div>
        </template>
        <div class="flex gap-2 mt-3">
          <template x-if="p.current_job_id">
            <a :href="'/admin/jobs.php?view=' + p.current_job_id" class="flex-1 text-center text-xs px-2 py-1.5 bg-brand-light text-brand-dark rounded-lg font-medium">Aktueller Job</a>
          </template>
          <template x-if="p.next_job_id">
            <a :href="'/admin/jobs.php?view=' + p.next_job_id" class="flex-1 text-center text-xs px-2 py-1.5 border rounded-lg text-gray-700">Nächster Job</a>
          </template>
        </div>
      </div>
    </template>
  </div>
</div>

<?php $apiKey = API_KEY; $script = <<<JS
function partnersLive() {
  return {
    partners: [], summary: {}, nowTime: '-',
    load() {
      fetch('/api/index.php?action=partners/status', { headers: {'X-API-Key':'{$apiKey}'} })
        .then(r => r.json()).then(d => {
          if (d.success) { this.partners = d.data.partners; this.summary = d.data.summary; this.nowTime = d.data.now; }
        });
    }
  }
}
JS; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
