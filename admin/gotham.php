<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/schema.php'; // ensure ontology tables exist
require_once __DIR__ . '/../includes/ontology.php';
$title = 'Gotham'; $page = 'gotham';

// Summary stats for header
$stats = [
    'objects' => (int)valLocal("SELECT COUNT(*) FROM ontology_objects"),
    'verified'=> (int)valLocal("SELECT COUNT(*) FROM ontology_objects WHERE verified=1"),
    'links'   => (int)valLocal("SELECT COUNT(*) FROM ontology_links"),
    'events'  => (int)valLocal("SELECT COUNT(*) FROM ontology_events"),
    'scans'   => (int)valLocal("SELECT COUNT(*) FROM osint_scans WHERE deep_scan_data IS NOT NULL"),
];
$typeCounts = allLocal("SELECT obj_type, COUNT(*) as cnt FROM ontology_objects GROUP BY obj_type ORDER BY cnt DESC");

include __DIR__ . '/../includes/layout.php';
?>

<style>
#gotham-root { background:#0b1020; color:#e2e8f0; min-height:calc(100vh - 120px); font-family:'Inter',system-ui,sans-serif; }
.gotham-glass { background:rgba(15,23,42,0.7); backdrop-filter:blur(10px); border:1px solid rgba(148,163,184,0.15); }
.gotham-search-input { background:rgba(30,41,59,0.9); border:1px solid #475569; color:#f1f5f9; transition:all 0.2s; }
.gotham-search-input:focus { border-color:#f59e0b; box-shadow:0 0 0 3px rgba(245,158,11,0.2); outline:none; }
.gotham-chip { background:rgba(30,41,59,0.8); border:1px solid #475569; color:#cbd5e1; transition:all 0.15s; cursor:pointer; }
.gotham-chip:hover { background:rgba(245,158,11,0.15); border-color:#f59e0b; color:#fef3c7; }
.gotham-chip.active { background:linear-gradient(135deg,#f59e0b,#dc2626); border-color:#f59e0b; color:#fff; font-weight:600; }
.result-card { background:rgba(30,41,59,0.6); border:1px solid rgba(148,163,184,0.15); transition:all 0.15s; cursor:pointer; }
.result-card:hover { border-color:#f59e0b; background:rgba(245,158,11,0.08); transform:translateX(2px); }
.type-badge { font-family:'JetBrains Mono',monospace; font-size:9px; letter-spacing:0.5px; text-transform:uppercase; padding:1px 6px; border-radius:3px; }
.type-person  { background:rgba(59,130,246,0.2); color:#93c5fd; }
.type-company { background:rgba(168,85,247,0.2); color:#d8b4fe; }
.type-email   { background:rgba(16,185,129,0.2); color:#6ee7b7; }
.type-phone   { background:rgba(245,158,11,0.2); color:#fcd34d; }
.type-domain  { background:rgba(244,63,94,0.2); color:#fda4af; }
.type-handle  { background:rgba(236,72,153,0.2); color:#f9a8d4; }
.type-address { background:rgba(20,184,166,0.2); color:#5eead4; }
#cy-canvas { background:linear-gradient(135deg,#0b1020 0%,#1e293b 100%); border-radius:8px; height:500px; }
.gotham-btn { background:linear-gradient(135deg,#f59e0b,#dc2626); color:#fff; font-weight:600; }
.gotham-btn:hover { filter:brightness(1.1); }
.gotham-btn:disabled { opacity:0.5; cursor:not-allowed; }
</style>

<div id="gotham-root" class="p-4">
  <!-- HEADER -->
  <div class="max-w-6xl mx-auto mb-6">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold text-white flex items-center gap-2">
          🦅 <span>GOTHAM</span>
          <span class="text-[10px] font-mono text-amber-400 uppercase tracking-wider ml-2">Palantir-Lite · Investigation OS</span>
        </h1>
        <div class="text-xs text-slate-400 mt-1">
          <?= number_format($stats['objects']) ?> objects · <?= number_format($stats['verified']) ?> verified ·
          <?= number_format($stats['links']) ?> links · <?= number_format($stats['events']) ?> events · <?= number_format($stats['scans']) ?> indexed scans
        </div>
      </div>
      <a href="/admin/scanner.php" class="text-xs text-slate-400 hover:text-amber-400">→ Classic Scanner</a>
    </div>

    <!-- SEARCH BAR — Google-style -->
    <div class="relative">
      <input type="text" id="gothamQuery" placeholder="Search any name, email, phone, domain, address, company…"
             class="gotham-search-input w-full px-6 py-4 rounded-full text-lg pr-40"
             autocomplete="off" autofocus>
      <div class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-2">
        <label class="text-[10px] text-slate-400 font-mono flex items-center gap-1 cursor-pointer">
          <input type="checkbox" id="includeLive" class="accent-amber-500"> LIVE
        </label>
        <button onclick="gothamSearch()" class="gotham-btn px-5 py-2 rounded-full text-sm">SEARCH</button>
      </div>
    </div>

    <!-- Type filter chips -->
    <div class="flex flex-wrap gap-2 mt-3">
      <span class="gotham-chip active px-3 py-1 rounded-full text-xs" data-type="all" onclick="setTypeFilter(this,'all')">
        ALL · <?= array_sum(array_column($typeCounts,'cnt')) ?>
      </span>
      <?php foreach ($typeCounts as $tc): ?>
      <span class="gotham-chip px-3 py-1 rounded-full text-xs" data-type="<?= e($tc['obj_type']) ?>" onclick="setTypeFilter(this,'<?= e($tc['obj_type']) ?>')">
        <?= strtoupper(e($tc['obj_type'])) ?> · <?= $tc['cnt'] ?>
      </span>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- RESULTS + CANVAS -->
  <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-4">
    <!-- LEFT: Results list -->
    <div class="lg:col-span-4">
      <div class="gotham-glass rounded-xl p-4">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-bold text-white">RESULTS</h3>
          <span id="resultCount" class="text-[10px] font-mono text-slate-400">—</span>
        </div>
        <div id="resultsList" class="space-y-2 max-h-[70vh] overflow-y-auto">
          <div class="text-center text-slate-500 text-xs py-12">
            Start searching to build your investigation.<br>
            All historical scans are indexed.
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT: Canvas + Detail -->
    <div class="lg:col-span-8 space-y-4">
      <!-- Graph canvas -->
      <div class="gotham-glass rounded-xl p-4">
        <div class="flex items-center justify-between mb-2">
          <h3 class="text-sm font-bold text-white">LINK GRAPH <span class="text-[10px] font-mono text-slate-400 ml-2" id="graphInfo">select a result</span></h3>
          <div class="flex items-center gap-2" id="graphActions" style="display:none">
            <button onclick="expandSelected()" class="gotham-btn px-3 py-1 rounded text-[11px]" id="expandBtn">🦅 CASCADE EXPAND</button>
            <button onclick="exportBriefing()" class="px-3 py-1 bg-slate-700 hover:bg-slate-600 text-white rounded text-[11px]">📄 BRIEFING BOOK</button>
          </div>
        </div>
        <div id="cy-canvas"></div>
      </div>

      <!-- Detail panel -->
      <div class="gotham-glass rounded-xl p-4 hidden" id="detailPanel">
        <h3 class="text-sm font-bold text-white mb-3" id="detailTitle">—</h3>
        <div id="detailBody" class="text-xs text-slate-300"></div>
      </div>
    </div>
  </div>
</div>

<!-- Cytoscape.js -->
<script src="https://cdn.jsdelivr.net/npm/cytoscape@3.28.1/dist/cytoscape.min.js"></script>
<script>
const GOTHAM = {
  query: '',
  typeFilter: 'all',
  currentObj: null,
  cy: null,
  debounceTimer: null,
};

function setTypeFilter(el, type) {
  GOTHAM.typeFilter = type;
  document.querySelectorAll('.gotham-chip').forEach(c => c.classList.remove('active'));
  el.classList.add('active');
  if (GOTHAM.query) gothamSearch();
}

async function gothamSearch() {
  const q = document.getElementById('gothamQuery').value.trim();
  if (q.length < 2) return;
  GOTHAM.query = q;
  document.getElementById('resultCount').textContent = 'searching…';
  try {
    const r = await fetch('/api/gotham-search.php', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        query: q,
        type_filter: GOTHAM.typeFilter,
        include_live: document.getElementById('includeLive').checked,
      }),
    });
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'search failed');
    renderResults(j.data);
  } catch (e) {
    document.getElementById('resultsList').innerHTML =
      '<div class="text-red-400 text-xs p-4">Error: ' + e.message + '</div>';
  }
}

function typeBadge(t) { return `<span class="type-badge type-${t||'unknown'}">${t||'?'}</span>`; }

function renderResults(d) {
  const list = document.getElementById('resultsList');
  document.getElementById('resultCount').textContent =
    `${d.counts.total} hits · ${d.counts.ontology} ontology · ${d.counts.scans} scans · ${d.counts.live} live`;

  let html = '';
  if (d.ontology.length) {
    html += '<div class="text-[10px] font-mono text-amber-400 uppercase mb-1 mt-2">ONTOLOGY</div>';
    d.ontology.forEach(o => {
      const verified = o.verified == 1 ? '<span class="text-green-400 ml-1">✓</span>' : '';
      html += `
        <div class="result-card rounded-lg p-3" onclick="selectObject(${o.obj_id}, ${JSON.stringify(o).replace(/"/g,'&quot;')})">
          <div class="flex items-center justify-between mb-1">
            <span class="font-semibold text-white text-xs truncate">${o.display_name}${verified}</span>
            ${typeBadge(o.obj_type)}
          </div>
          <div class="text-[10px] text-slate-400 font-mono">
            conf ${Math.round(o.confidence*100)}% · ${o.source_count} sources
          </div>
        </div>`;
    });
  }
  if (d.scans.length) {
    html += '<div class="text-[10px] font-mono text-amber-400 uppercase mb-1 mt-4">PAST SCANS</div>';
    d.scans.forEach(s => {
      const name = s.scan_name || s.scan_email || s.scan_phone || s.scan_address || '(no identifier)';
      html += `
        <div class="result-card rounded-lg p-3 opacity-80">
          <div class="text-white text-xs font-semibold truncate">${name}</div>
          <div class="text-[10px] text-slate-400 font-mono mt-1">
            scan #${s.scan_id} · ${s.created_at ? s.created_at.substring(0,10) : ''}
          </div>
        </div>`;
    });
  }
  if (d.live.length) {
    html += '<div class="text-[10px] font-mono text-amber-400 uppercase mb-1 mt-4">LIVE (SearXNG)</div>';
    d.live.forEach(l => {
      html += `
        <a href="${l.url}" target="_blank" class="result-card rounded-lg p-3 block no-underline">
          <div class="text-white text-xs font-semibold truncate">${l.title}</div>
          <div class="text-[10px] text-slate-500 truncate">${l.url}</div>
          <div class="text-[10px] text-slate-400 mt-1">${l.snippet}</div>
        </a>`;
    });
  }
  if (!html) html = '<div class="text-slate-500 text-xs p-4 text-center">No results. Try enabling LIVE or broadening your query.</div>';
  list.innerHTML = html;
}

async function selectObject(objId, objInline) {
  GOTHAM.currentObj = objInline || { obj_id: objId };
  document.getElementById('graphInfo').textContent = 'loading…';
  document.getElementById('graphActions').style.display = 'flex';

  // Fetch graph + detail in parallel
  const [graphR, detailR] = await Promise.all([
    fetch(`/api/gotham-expand.php?action=graph&obj_id=${objId}&depth=2`).then(r => r.json()),
    fetch(`/api/gotham-expand.php?action=detail&obj_id=${objId}`).then(r => r.json()),
  ]);

  if (graphR.success) renderGraph(graphR.data);
  if (detailR.success) renderDetail(detailR.data);
}

function renderGraph(cyData) {
  document.getElementById('graphInfo').textContent = `${cyData.nodes.length} nodes · ${cyData.edges.length} edges`;
  if (GOTHAM.cy) GOTHAM.cy.destroy();
  GOTHAM.cy = cytoscape({
    container: document.getElementById('cy-canvas'),
    elements: [...cyData.nodes, ...cyData.edges],
    style: [
      { selector: 'node', style: {
          'label': 'data(label)', 'color': '#e2e8f0', 'font-size': '10px',
          'text-valign': 'bottom', 'text-margin-y': 6,
          'background-color': '#3b82f6', 'width': 28, 'height': 28,
          'border-width': 2, 'border-color': '#1e293b',
      }},
      { selector: 'node[type = "person"]',  style: {'background-color': '#3b82f6'} },
      { selector: 'node[type = "company"]', style: {'background-color': '#a855f7'} },
      { selector: 'node[type = "email"]',   style: {'background-color': '#10b981'} },
      { selector: 'node[type = "phone"]',   style: {'background-color': '#f59e0b'} },
      { selector: 'node[type = "domain"]',  style: {'background-color': '#f43f5e'} },
      { selector: 'node[type = "handle"]',  style: {'background-color': '#ec4899'} },
      { selector: 'node[type = "address"]', style: {'background-color': '#14b8a6'} },
      { selector: 'node[verified = 1]', style: {'border-color': '#fbbf24', 'border-width': 3} },
      { selector: 'edge', style: {
          'width': 1, 'line-color': '#475569', 'target-arrow-color': '#475569',
          'target-arrow-shape': 'triangle', 'curve-style': 'bezier',
          'label': 'data(label)', 'font-size': '8px', 'color': '#94a3b8',
          'text-rotation': 'autorotate', 'text-margin-y': -6,
      }},
    ],
    layout: { name: 'cose', animate: true, animationDuration: 400, nodeRepulsion: 8000, idealEdgeLength: 80 },
  });
  GOTHAM.cy.on('tap', 'node', evt => {
    const objId = evt.target.data('obj_id');
    selectObject(objId);
  });
}

function renderDetail(obj) {
  document.getElementById('detailPanel').classList.remove('hidden');
  document.getElementById('detailTitle').innerHTML =
    `${obj.display_name} ${typeBadge(obj.obj_type)} ${obj.verified == 1 ? '<span class="text-green-400 text-xs">✓ verified</span>' : ''}`;
  let html = `
    <div class="grid grid-cols-3 gap-3 mb-3">
      <div class="bg-slate-800/50 rounded p-2"><div class="text-[9px] text-slate-400 font-mono">CONFIDENCE</div><div class="text-white font-bold">${Math.round(obj.confidence*100)}%</div></div>
      <div class="bg-slate-800/50 rounded p-2"><div class="text-[9px] text-slate-400 font-mono">SOURCES</div><div class="text-white font-bold">${obj.source_count}</div></div>
      <div class="bg-slate-800/50 rounded p-2"><div class="text-[9px] text-slate-400 font-mono">LAST SEEN</div><div class="text-white font-bold">${(obj.last_updated||'').substring(0,10)}</div></div>
    </div>
  `;
  if (obj.events && obj.events.length) {
    html += '<div class="text-[10px] font-mono text-amber-400 uppercase mb-2">TIMELINE</div>';
    html += '<div class="space-y-2 mb-3">';
    obj.events.slice(0,10).forEach(e => {
      html += `<div class="border-l-2 border-slate-600 pl-2 py-1">
        <div class="text-[10px] text-slate-500 font-mono">${e.event_date || e.created_at.substring(0,10)} · ${e.event_type}</div>
        <div class="text-xs text-slate-200">${e.title}</div>
      </div>`;
    });
    html += '</div>';
  }
  if (obj.links_out && obj.links_out.length) {
    html += '<div class="text-[10px] font-mono text-amber-400 uppercase mb-2">LINKS OUT</div>';
    html += '<div class="flex flex-wrap gap-1 mb-3">';
    obj.links_out.forEach(l => {
      html += `<span class="gotham-chip px-2 py-0.5 rounded text-[10px]" onclick="selectObject(${l.to_obj})">
        ${l.relation} → ${l.target_name}</span>`;
    });
    html += '</div>';
  }
  document.getElementById('detailBody').innerHTML = html;
}

async function expandSelected() {
  if (!GOTHAM.currentObj) return;
  const btn = document.getElementById('expandBtn');
  btn.disabled = true; btn.textContent = '🦅 CASCADING…';
  try {
    const fd = new FormData();
    fd.append('action', 'cascade');
    fd.append('obj_id', GOTHAM.currentObj.obj_id);
    fd.append('depth', '2');
    fd.append('mode', 'fast');
    const r = await fetch('/api/gotham-expand.php', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'cascade failed');
    // Refresh graph
    await selectObject(GOTHAM.currentObj.obj_id);
    alert(`✓ Cascade: ${j.cascade_stats?.objects_created || 0} new objects, ${j.cascade_stats?.links_created || 0} links. Confidence: ${Math.round((j.confidence||0)*100)}%`);
  } catch (e) {
    alert('Error: ' + e.message);
  } finally {
    btn.disabled = false; btn.textContent = '🦅 CASCADE EXPAND';
  }
}

function exportBriefing() {
  if (!GOTHAM.currentObj) return;
  window.open(`/admin/gotham-briefing.php?obj_id=${GOTHAM.currentObj.obj_id}`, '_blank');
}

// Debounced search-on-type
document.getElementById('gothamQuery').addEventListener('input', e => {
  clearTimeout(GOTHAM.debounceTimer);
  GOTHAM.debounceTimer = setTimeout(() => {
    if (e.target.value.trim().length >= 2) gothamSearch();
  }, 400);
});
document.getElementById('gothamQuery').addEventListener('keydown', e => {
  if (e.key === 'Enter') { clearTimeout(GOTHAM.debounceTimer); gothamSearch(); }
});
</script>
