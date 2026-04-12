<?php
/**
 * OSI — Open Source Intelligence
 * THE unified OSINT tool. One page. Everything.
 * Combines: Scanner (deep scan) + Vulture (data aggregation) + AI Synthesis
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'OSI'; $page = 'scanner';

// Target from URL params
$q = trim($_GET['q'] ?? '');
$cid = (int)($_GET['cid'] ?? 0);

// Resolve from customer ID
if ($cid && !$q) {
    $cust = one("SELECT name, email, phone FROM customer WHERE customer_id=?", [$cid]);
    if ($cust) $q = $cust['email'] ?: $cust['name'];
}

// Recent scans for sidebar
$recentScans = all("SELECT scan_id, scan_name, scan_email, created_at FROM osint_scans ORDER BY created_at DESC LIMIT 15");

// Customer list for quick-scan
$customers = all("SELECT customer_id, name, email, phone FROM customer WHERE status=1 AND email IS NOT NULL AND email!='' ORDER BY name");

// Scan history for this target
$targetHistory = [];
if ($q) {
    $targetHistory = all("SELECT scan_id, scan_name, scan_email, scan_phone, created_at,
                          LENGTH(scan_data) as data_size, LENGTH(deep_scan_data) as deep_size
                          FROM osint_scans
                          WHERE scan_name LIKE ? OR scan_email LIKE ?
                          ORDER BY created_at DESC LIMIT 20",
                         ['%'.$q.'%', '%'.$q.'%']);
}

include __DIR__ . '/../includes/layout.php';
?>

<style>
.osi-glow { box-shadow: 0 0 30px rgba(46,125,107,0.15); }
.scan-pulse { animation: scanPulse 2s infinite; }
@keyframes scanPulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
.module-card { @apply bg-white rounded-xl border p-4 transition hover:shadow-md; }
.score-ring { width: 80px; height: 80px; }
.score-ring circle { fill: none; stroke-width: 6; stroke-linecap: round; }
.score-bg { stroke: #e5e7eb; }
.score-fg { stroke: var(--brand, #2E7D6B); transition: stroke-dashoffset 1s ease; }
@media print { .no-print { display: none !important; } }
</style>

<!-- OSI HEADER -->
<div class="bg-gradient-to-br from-gray-900 via-gray-800 to-gray-900 rounded-2xl p-8 mb-6 text-white relative overflow-hidden osi-glow">
  <div class="absolute inset-0 opacity-5" style="background-image: url('data:image/svg+xml,%3Csvg width=%2220%22 height=%2220%22 viewBox=%220 0 20 20%22 xmlns=%22http://www.w3.org/2000/svg%22%3E%3Cg fill=%22%23fff%22%3E%3Ccircle cx=%221%22 cy=%221%22 r=%221%22/%3E%3C/g%3E%3C/svg%3E')"></div>
  <div class="relative z-10">
    <div class="flex items-start justify-between gap-6 mb-6">
      <div>
        <div class="text-[10px] uppercase tracking-[0.3em] text-brand font-mono mb-2">OPEN SOURCE INTELLIGENCE</div>
        <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight">OSI</h1>
        <p class="text-gray-400 text-sm mt-1">Scanner + Vulture + AI — unified.</p>
      </div>
      <div class="text-right text-xs text-gray-500 font-mono">
        <div><?= count($recentScans) ?> recent scans</div>
        <div><?= val("SELECT COUNT(*) FROM osint_scans") ?> total</div>
      </div>
    </div>

    <!-- UNIFIED SEARCH -->
    <form method="GET" class="flex gap-3" x-data="{ q: '<?= e($q) ?>' }" id="osiSearchForm">
      <div class="flex-1 relative">
        <input name="q" x-model="q" placeholder="Email, Name, Telefon, Domain, oder Firma..." autofocus
               class="w-full px-5 py-4 bg-white/10 border border-white/20 rounded-xl text-white placeholder-gray-500 text-lg focus:ring-2 focus:ring-brand focus:border-brand outline-none backdrop-blur"/>
        <div class="absolute right-3 top-1/2 -translate-y-1/2 flex gap-1">
          <kbd class="px-1.5 py-0.5 bg-white/10 rounded text-[10px] text-gray-400 font-mono">Enter</kbd>
        </div>
      </div>
      <button type="submit" class="px-8 py-4 bg-brand hover:bg-brand/80 rounded-xl font-bold text-lg transition flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        Scan
      </button>
    </form>

    <!-- Quick select: customers -->
    <div class="mt-4 flex items-center gap-2 flex-wrap">
      <span class="text-[10px] uppercase tracking-wider text-gray-500 font-bold">Schnell:</span>
      <?php foreach (array_slice($customers, 0, 8) as $c): ?>
      <a href="?q=<?= urlencode($c['email'] ?: $c['name']) ?>" class="px-2 py-1 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg text-[11px] text-gray-400 hover:text-white transition truncate max-w-[120px]"><?= e($c['name']) ?></a>
      <?php endforeach; ?>
      <?php if (count($customers) > 8): ?>
      <select onchange="if(this.value) location.href='?q='+encodeURIComponent(this.value)" class="bg-white/5 border border-white/10 rounded-lg text-[11px] text-gray-400 px-2 py-1">
        <option value="">+ <?= count($customers) - 8 ?> mehr...</option>
        <?php foreach (array_slice($customers, 8) as $c): ?>
        <option value="<?= e($c['email'] ?: $c['name']) ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($q): ?>
<!-- ============================================================ -->
<!-- MISSION CONTROL — Active Scan -->
<!-- ============================================================ -->
<div x-data="osiMission()" x-init="startScan()" class="space-y-4">

  <!-- PROGRESS BAR -->
  <div x-show="scanning" class="bg-white rounded-xl border p-5 osi-glow">
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-3">
        <div class="w-3 h-3 bg-brand rounded-full scan-pulse"></div>
        <span class="font-bold text-gray-900" x-text="currentModule"></span>
      </div>
      <span class="text-sm font-mono text-gray-500" x-text="Math.round(progress) + '%'"></span>
    </div>
    <div class="bg-gray-100 rounded-full h-2 overflow-hidden">
      <div class="h-full bg-gradient-to-r from-brand to-emerald-400 rounded-full transition-all duration-500" :style="'width:'+progress+'%'"></div>
    </div>
    <div class="mt-2 flex gap-2 flex-wrap">
      <template x-for="m in completedModules" :key="m">
        <span class="px-2 py-0.5 bg-green-50 text-green-700 text-[10px] font-semibold rounded" x-text="'✓ ' + m"></span>
      </template>
    </div>
  </div>

  <!-- SCORE + HEADER -->
  <div x-show="result" x-cloak class="bg-white rounded-2xl border overflow-hidden osi-glow">
    <div class="p-6 bg-gradient-to-r from-gray-50 to-white flex items-center gap-6 flex-wrap">
      <!-- Score Ring -->
      <div class="relative">
        <svg class="score-ring" viewBox="0 0 36 36">
          <circle class="score-bg" cx="18" cy="18" r="15.5"/>
          <circle class="score-fg" cx="18" cy="18" r="15.5"
                  :stroke-dasharray="97.4"
                  :stroke-dashoffset="97.4 - (97.4 * (result?.score || 0) / 100)"
                  transform="rotate(-90 18 18)"/>
        </svg>
        <div class="absolute inset-0 flex items-center justify-center">
          <span class="text-xl font-extrabold" x-text="result?.score || '—'"></span>
        </div>
      </div>
      <div class="flex-1 min-w-0">
        <div class="text-[10px] uppercase tracking-widest text-gray-400 font-mono">RISK & OPPORTUNITY SCORE</div>
        <h2 class="text-2xl font-extrabold text-gray-900 truncate" x-text="result?.target || '<?= e($q) ?>'"></h2>
        <div class="flex gap-2 mt-2 flex-wrap">
          <span class="px-2 py-0.5 text-[10px] font-bold rounded"
                :class="(result?.risk_level === 'HIGH') ? 'bg-red-100 text-red-800' : (result?.risk_level === 'MEDIUM' ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800')"
                x-text="'Risiko: ' + (result?.risk_level || '—')"></span>
          <span class="px-2 py-0.5 bg-blue-50 text-blue-700 text-[10px] font-bold rounded" x-text="(result?.total_findings || 0) + ' Web-Funde'"></span>
          <span class="px-2 py-0.5 bg-indigo-50 text-indigo-700 text-[10px] font-bold rounded" x-text="(result?.db?.total_hits || 0) + ' DB-Treffer'"></span>
          <span class="px-2 py-0.5 bg-gray-100 text-gray-600 text-[10px] font-bold rounded" x-text="(result?.modules_count || 0) + ' Module'"></span>
          <span class="px-2 py-0.5 bg-gray-100 text-gray-600 text-[10px] font-mono rounded" x-text="(result?.scan_time || '?') + 's'"></span>
        </div>
      </div>
      <div class="no-print flex gap-2">
        <button onclick="window.print()" class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-xs font-semibold">PDF</button>
        <button @click="startScan()" class="px-3 py-2 bg-brand text-white hover:bg-brand/80 rounded-lg text-xs font-semibold">Rescan</button>
      </div>
    </div>

    <!-- AI SYNTHESIS -->
    <div x-show="result?.ai_summary" class="px-6 py-4 border-t bg-gradient-to-r from-purple-50/50 to-transparent">
      <div class="flex items-start gap-3">
        <span class="text-xl">🤖</span>
        <div class="flex-1">
          <div class="text-[10px] uppercase tracking-wider text-purple-600 font-bold mb-1">AI INTELLIGENCE SYNTHESIS</div>
          <div class="text-sm text-gray-700 leading-relaxed" x-html="result?.ai_summary?.replace(/\n/g, '<br>')"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- MODULE RESULTS — Grid -->
  <div x-show="result" x-cloak class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    <!-- DB Cross-Reference — ALL TABLES -->
    <div class="module-card" x-show="result?.db">
      <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
        <span class="w-2 h-2 bg-brand rounded-full"></span> Datenbank
        <span class="px-1.5 py-0.5 bg-brand/10 text-brand text-[10px] font-bold rounded" x-text="(result?.db?.total_hits || 0) + ' Treffer'"></span>
      </h3>

      <!-- Customer -->
      <div x-show="result?.db?.customer" class="mb-3 p-3 bg-blue-50 rounded-lg">
        <div class="text-[10px] font-bold text-blue-600 uppercase mb-1">Kunde</div>
        <template x-for="(val, key) in (result?.db?.customer || {})" :key="key">
          <div class="flex justify-between text-sm py-0.5">
            <span class="text-gray-500 capitalize" x-text="key.replace(/_/g, ' ')"></span>
            <span class="font-medium text-gray-900 truncate max-w-[200px]" x-text="val"></span>
          </div>
        </template>
      </div>

      <!-- Employee -->
      <div x-show="result?.db?.employee" class="mb-3 p-3 bg-purple-50 rounded-lg">
        <div class="text-[10px] font-bold text-purple-600 uppercase mb-1">Partner</div>
        <template x-for="(val, key) in (result?.db?.employee || {})" :key="key">
          <div class="flex justify-between text-sm py-0.5">
            <span class="text-gray-500 capitalize" x-text="key.replace(/_/g, ' ')"></span>
            <span class="font-medium text-gray-900 truncate max-w-[200px]" x-text="val"></span>
          </div>
        </template>
      </div>

      <!-- Leads -->
      <div x-show="result?.db?.leads?.length > 0" class="mb-3 p-3 bg-amber-50 rounded-lg">
        <div class="text-[10px] font-bold text-amber-600 uppercase mb-1">Leads (<span x-text="result?.db?.leads?.length"></span>)</div>
        <template x-for="l in (result?.db?.leads || [])" :key="l.lead_id">
          <div class="flex justify-between text-xs py-0.5 border-b border-amber-100 last:border-0">
            <span class="text-gray-800" x-text="l.name + ' — ' + (l.email || l.phone || '')"></span>
            <span class="text-gray-500" x-text="l.source"></span>
          </div>
        </template>
      </div>

      <!-- Users -->
      <div x-show="result?.db?.users?.length > 0" class="mb-3 p-3 bg-gray-50 rounded-lg">
        <div class="text-[10px] font-bold text-gray-600 uppercase mb-1">User Accounts (<span x-text="result?.db?.users?.length"></span>)</div>
        <template x-for="u in (result?.db?.users || [])" :key="u.id">
          <div class="text-xs py-0.5"><span class="font-medium" x-text="u.email"></span> <span class="text-gray-400" x-text="'(' + u.type + ')'"></span></div>
        </template>
      </div>

      <!-- Ontology -->
      <div x-show="result?.db?.ontology?.length > 0" class="mb-3 p-3 bg-emerald-50 rounded-lg">
        <div class="text-[10px] font-bold text-emerald-600 uppercase mb-1">Ontologie (<span x-text="result?.db?.ontology?.length"></span>)</div>
        <template x-for="o in (result?.db?.ontology || [])" :key="o.obj_id">
          <div class="flex justify-between text-xs py-0.5">
            <span class="text-gray-800" x-text="o.display_name"></span>
            <span class="px-1 bg-emerald-100 text-emerald-700 rounded text-[9px]" x-text="o.obj_type + ' ' + Math.round(o.confidence*100) + '%'"></span>
          </div>
        </template>
      </div>

      <!-- OSINT History -->
      <div x-show="result?.db?.osint_history?.length > 0" class="mb-3 p-3 bg-red-50 rounded-lg">
        <div class="text-[10px] font-bold text-red-600 uppercase mb-1">Vorherige Scans (<span x-text="result?.db?.osint_history?.length"></span>)</div>
        <template x-for="h in (result?.db?.osint_history || [])" :key="h.scan_id">
          <div class="flex justify-between text-xs py-0.5">
            <span class="text-gray-800" x-text="'#' + h.scan_id + ' ' + (h.scan_name || h.scan_email)"></span>
            <span class="text-gray-400 font-mono" x-text="h.created_at"></span>
          </div>
        </template>
      </div>

      <!-- Google Sheets -->
      <div x-show="result?.db?.google_sheets?.length > 0" class="mb-3 p-3 bg-green-50 rounded-lg">
        <div class="text-[10px] font-bold text-green-600 uppercase mb-1">Google Sheets (<span x-text="result?.db?.google_sheets?.length"></span>)</div>
        <template x-for="g in (result?.db?.google_sheets || [])" :key="g.name">
          <div class="flex justify-between text-xs py-0.5 border-b border-green-100 last:border-0">
            <div>
              <span class="font-medium text-gray-800" x-text="g.name"></span>
              <span class="text-gray-500 ml-1" x-text="g.description"></span>
            </div>
            <a :href="g.link" target="_blank" x-show="g.link" class="text-brand hover:underline text-[10px]">Link</a>
          </div>
        </template>
      </div>

      <!-- Stats -->
      <div x-show="result?.db?.stats" class="mt-3 grid grid-cols-3 gap-2 text-center">
        <div class="bg-gray-50 rounded-lg p-2"><div class="text-lg font-bold" x-text="result?.db?.stats?.total_jobs || 0"></div><div class="text-[9px] text-gray-500 uppercase">Jobs</div></div>
        <div class="bg-gray-50 rounded-lg p-2"><div class="text-lg font-bold" x-text="result?.db?.stats?.revenue || '0'"></div><div class="text-[9px] text-gray-500 uppercase">Umsatz</div></div>
        <div class="bg-gray-50 rounded-lg p-2"><div class="text-lg font-bold" x-text="result?.db?.stats?.open_balance || '0'"></div><div class="text-[9px] text-gray-500 uppercase">Offen</div></div>
      </div>

      <div x-show="(result?.db?.total_hits || 0) === 0" class="text-sm text-gray-400 italic">Kein Treffer in der gesamten Datenbank</div>
    </div>

    <!-- Email Analysis -->
    <div class="module-card" x-show="result?.email">
      <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
        <span class="w-2 h-2 bg-green-500 rounded-full"></span> Email Intelligence
      </h3>
      <div class="space-y-2 text-sm">
        <div class="flex justify-between"><span class="text-gray-500">Domain</span><span class="font-mono font-semibold" x-text="result?.email?.domain"></span></div>
        <div class="flex justify-between"><span class="text-gray-500">Typ</span><span class="font-semibold" x-text="result?.email?.business ? 'Business' : 'Freemail'"></span></div>
        <div class="flex justify-between"><span class="text-gray-500">MX Records</span><span x-text="result?.email?.mx"></span></div>
        <div x-show="result?.email?.gravatar_url" class="flex justify-between items-center">
          <span class="text-gray-500">Gravatar</span>
          <img :src="result?.email?.gravatar_url" class="w-8 h-8 rounded-full" onerror="this.style.display='none'"/>
        </div>
      </div>
    </div>

    <!-- Deep Scan Results -->
    <div class="module-card lg:col-span-2" x-show="result?.deep_scan">
      <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
        <span class="w-2 h-2 bg-purple-500 rounded-full"></span> Deep Scan
      </h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <template x-for="(finding, i) in (result?.deep_scan?.findings || [])" :key="i">
          <div class="p-3 rounded-lg border" :class="finding.severity === 'critical' ? 'bg-red-50 border-red-200' : (finding.severity === 'high' ? 'bg-amber-50 border-amber-200' : 'bg-gray-50 border-gray-200')">
            <div class="text-[10px] font-bold uppercase" :class="finding.severity === 'critical' ? 'text-red-700' : (finding.severity === 'high' ? 'text-amber-700' : 'text-gray-500')" x-text="finding.category"></div>
            <div class="text-sm font-medium text-gray-900 mt-0.5" x-text="finding.title"></div>
            <a :href="finding.url" target="_blank" x-show="finding.url" class="text-[11px] text-brand hover:underline mt-1 block truncate" x-text="finding.url"></a>
          </div>
        </template>
      </div>
      <div x-show="!result?.deep_scan?.findings?.length" class="text-sm text-gray-400 italic">Keine Funde im Deep Scan</div>
    </div>

    <!-- OSINT Links -->
    <div class="module-card lg:col-span-2 no-print" x-show="result?.osint_links">
      <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
        <span class="w-2 h-2 bg-amber-500 rounded-full"></span> OSINT Quick Links
      </h3>
      <div class="space-y-3">
        <template x-for="(links, category) in (result?.osint_links || {})" :key="category">
          <div>
            <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1" x-text="category"></div>
            <div class="flex flex-wrap gap-1">
              <template x-for="link in links" :key="link.url">
                <a :href="link.url" target="_blank" rel="noopener" class="px-2 py-1 text-[11px] font-medium rounded-lg border border-gray-200 text-gray-700 hover:border-brand hover:text-brand hover:bg-brand/5 transition" x-text="link.label"></a>
              </template>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- Scan History Timeline -->
    <div class="module-card" x-show="result?.history?.length > 0">
      <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
        <span class="w-2 h-2 bg-gray-400 rounded-full"></span> Scan-Verlauf
      </h3>
      <div class="space-y-2 max-h-64 overflow-y-auto">
        <template x-for="h in (result?.history || [])" :key="h.scan_id">
          <div class="flex items-center justify-between text-sm py-1 border-b border-gray-50">
            <div class="flex items-center gap-2">
              <span class="w-1.5 h-1.5 bg-brand rounded-full"></span>
              <span class="font-mono text-xs text-gray-500" x-text="h.date"></span>
            </div>
            <span class="text-[10px] text-gray-400" x-text="h.data_size"></span>
          </div>
        </template>
      </div>
    </div>

    <!-- Messages -->
    <div class="module-card" x-show="result?.messages?.length > 0">
      <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
        <span class="w-2 h-2 bg-blue-500 rounded-full"></span> Nachrichten (<span x-text="result?.messages?.length || 0"></span>)
      </h3>
      <div class="space-y-2 max-h-64 overflow-y-auto">
        <template x-for="m in (result?.messages || [])" :key="m.id">
          <div class="p-2 rounded-lg text-xs" :class="m.mine ? 'bg-green-50' : 'bg-gray-50'">
            <div class="flex justify-between text-[10px] text-gray-500 mb-0.5">
              <span x-text="m.sender"></span><span x-text="m.date"></span>
            </div>
            <div class="text-gray-800" x-text="m.text"></div>
          </div>
        </template>
      </div>
    </div>
  </div>

  <!-- ERROR -->
  <div x-show="error" x-cloak class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-800" x-text="error"></div>
</div>

<?php else: ?>
<!-- NO QUERY — Show recent scans -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="lg:col-span-2">
    <div class="bg-white rounded-xl border overflow-hidden">
      <div class="px-5 py-3 border-b bg-gray-50 flex items-center justify-between">
        <h3 class="font-semibold text-gray-900 text-sm">Letzte Scans</h3>
        <span class="text-xs text-gray-400"><?= count($recentScans) ?> angezeigt</span>
      </div>
      <div class="divide-y">
        <?php foreach ($recentScans as $s): ?>
        <a href="?q=<?= urlencode($s['scan_email'] ?: $s['scan_name']) ?>" class="flex items-center justify-between px-5 py-3 hover:bg-gray-50 transition">
          <div>
            <div class="font-medium text-gray-900 text-sm"><?= e($s['scan_name'] ?: $s['scan_email']) ?></div>
            <div class="text-xs text-gray-500"><?= e($s['scan_email']) ?></div>
          </div>
          <div class="text-xs text-gray-400 font-mono"><?= date('d.m. H:i', strtotime($s['created_at'])) ?></div>
        </a>
        <?php endforeach; ?>
        <?php if (empty($recentScans)): ?>
        <div class="p-10 text-center text-gray-400 text-sm">Noch keine Scans. Starten Sie oben eine Suche.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Stats sidebar -->
  <div class="space-y-4">
    <div class="bg-white rounded-xl border p-5">
      <div class="text-[10px] uppercase tracking-wider text-gray-500 font-bold mb-3">OSI Statistics</div>
      <div class="space-y-3">
        <div class="flex justify-between"><span class="text-sm text-gray-500">Total Scans</span><span class="font-bold"><?= val("SELECT COUNT(*) FROM osint_scans") ?></span></div>
        <div class="flex justify-between"><span class="text-sm text-gray-500">Ontology Objects</span><span class="font-bold"><?= val("SELECT COUNT(*) FROM ontology_objects") ?></span></div>
        <div class="flex justify-between"><span class="text-sm text-gray-500">Ontology Links</span><span class="font-bold"><?= val("SELECT COUNT(*) FROM ontology_links") ?></span></div>
        <div class="flex justify-between"><span class="text-sm text-gray-500">Active Customers</span><span class="font-bold"><?= val("SELECT COUNT(*) FROM customer WHERE status=1") ?></span></div>
        <div class="flex justify-between"><span class="text-sm text-gray-500">Active Partners</span><span class="font-bold"><?= val("SELECT COUNT(*) FROM employee WHERE status=1") ?></span></div>
      </div>
    </div>
    <div class="bg-gradient-to-br from-gray-900 to-gray-800 rounded-xl p-5 text-white">
      <div class="text-2xl mb-2">🦅</div>
      <div class="text-sm font-bold">OSI Unified</div>
      <div class="text-[11px] text-gray-400 mt-1">Scanner + Vulture + AI Synthesis in einem Tool. 100+ OSINT-Quellen, KI-Risikobewertung, Full-History.</div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function osiMission() {
  return {
    scanning: true,
    progress: 0,
    currentModule: 'Initialisierung...',
    completedModules: [],
    result: null,
    error: null,

    async startScan() {
      this.scanning = true;
      this.progress = 0;
      this.error = null;
      this.result = null;
      this.completedModules = [];

      const modules = [
        { name: 'DB Cross-Reference', pct: 15 },
        { name: 'Email Intelligence', pct: 25 },
        { name: 'Deep Scan (VPS)', pct: 60 },
        { name: 'OSINT Links', pct: 75 },
        { name: 'AI Synthesis', pct: 90 },
        { name: 'Report Generation', pct: 100 },
      ];

      // Animate progress
      let mi = 0;
      const timer = setInterval(() => {
        if (mi < modules.length && this.progress < modules[mi].pct - 5) {
          this.progress += 2;
          this.currentModule = modules[mi].name;
        } else if (mi < modules.length) {
          this.completedModules.push(modules[mi].name);
          mi++;
        }
      }, 200);

      try {
        const r = await fetch('/api/osi-scan.php?q=<?= urlencode($q) ?>', {
          credentials: 'same-origin'
        });
        const d = await r.json();
        clearInterval(timer);

        if (d.success) {
          this.progress = 100;
          this.currentModule = 'Abgeschlossen';
          this.completedModules = modules.map(m => m.name);
          setTimeout(() => {
            this.scanning = false;
            this.result = d;
          }, 500);
        } else {
          this.scanning = false;
          this.error = d.error || 'Scan fehlgeschlagen';
        }
      } catch (e) {
        clearInterval(timer);
        this.scanning = false;
        this.error = 'Netzwerk-Fehler: ' + e.message;
      }
    }
  };
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
