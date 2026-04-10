<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
require_once __DIR__ . '/../includes/ontology.php';
db_ping_reconnect();

$objId = (int)($_GET['obj_id'] ?? 0);
if ($objId <= 0) { header('Location: /admin/scanner.php'); exit; }

$obj = ontology_get_object($objId);
if (!$obj) { http_response_code(404); exit('object not found'); }

$title = 'Gotham · ' . $obj['display_name'];
$page = 'gotham';
include __DIR__ . '/../includes/layout.php';
?>
<style>
#gotham-detail-canvas { width: 100%; height: 70vh; background: linear-gradient(135deg,#f8fafc 0%,#e2e8f0 100%); border-radius: 8px; border: 1px solid #cbd5e1; }
.type-badge { font-family: 'JetBrains Mono', monospace; font-size: 9px; letter-spacing: 0.5px; text-transform: uppercase; padding: 2px 8px; border-radius: 3px; }
.type-person  { background: rgba(59,130,246,0.15); color: #1e40af; }
.type-company { background: rgba(168,85,247,0.15); color: #6b21a8; }
.type-email   { background: rgba(16,185,129,0.15); color: #047857; }
.type-phone   { background: rgba(245,158,11,0.15); color: #b45309; }
.type-domain  { background: rgba(244,63,94,0.15); color: #be123c; }
.type-handle  { background: rgba(236,72,153,0.15); color: #9d174d; }
.type-address { background: rgba(20,184,166,0.15); color: #0f766e; }
.type-ip      { background: rgba(100,116,139,0.15); color: #334155; }
</style>

<div class="bg-white rounded-xl border mb-4 overflow-hidden">
  <div class="px-5 py-3 border-b flex items-center justify-between bg-gradient-to-r from-brand/5 to-transparent">
    <div class="flex items-center gap-3">
      <a href="/admin/scanner.php" class="text-xs text-gray-400 hover:text-brand">← Scanner</a>
      <h2 class="text-lg font-semibold text-gray-900">
        <?= e($obj['display_name']) ?>
        <span class="type-badge type-<?= e($obj['obj_type']) ?>"><?= e($obj['obj_type']) ?></span>
        <?php if ($obj['verified']): ?><span class="text-green-600 text-sm">✓ verified</span><?php endif; ?>
      </h2>
    </div>
    <div class="flex items-center gap-2">
      <span class="text-xs text-gray-400 font-mono">
        <?= round($obj['confidence'] * 100) ?>% · <?= $obj['source_count'] ?> sources · obj #<?= $obj['obj_id'] ?>
      </span>
      <a href="/admin/gotham-briefing.php?obj_id=<?= $obj['obj_id'] ?>" target="_blank"
         class="px-3 py-1 border border-gray-200 text-gray-600 rounded text-xs hover:bg-gray-50">📄 Briefing</a>
    </div>
  </div>

  <!-- Stats -->
  <div class="grid grid-cols-2 md:grid-cols-5 gap-3 p-4 border-b">
    <div class="bg-gray-50 rounded p-2">
      <div class="text-[9px] font-mono text-gray-500 uppercase">Confidence</div>
      <div class="text-lg font-bold text-brand"><?= round($obj['confidence'] * 100) ?>%</div>
    </div>
    <div class="bg-gray-50 rounded p-2">
      <div class="text-[9px] font-mono text-gray-500 uppercase">Sources</div>
      <div class="text-lg font-bold"><?= $obj['source_count'] ?></div>
    </div>
    <div class="bg-gray-50 rounded p-2">
      <div class="text-[9px] font-mono text-gray-500 uppercase">Links Out</div>
      <div class="text-lg font-bold"><?= count($obj['links_out'] ?? []) ?></div>
    </div>
    <div class="bg-gray-50 rounded p-2">
      <div class="text-[9px] font-mono text-gray-500 uppercase">Links In</div>
      <div class="text-lg font-bold"><?= count($obj['links_in'] ?? []) ?></div>
    </div>
    <div class="bg-gray-50 rounded p-2">
      <div class="text-[9px] font-mono text-gray-500 uppercase">Events</div>
      <div class="text-lg font-bold"><?= count($obj['events'] ?? []) ?></div>
    </div>
  </div>

  <!-- Full-screen graph -->
  <div class="p-4">
    <div class="flex items-center justify-between mb-2">
      <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider">Link Graph</h3>
      <div class="flex items-center gap-2 text-[10px]">
        <label>Depth: <select id="gdDepth" onchange="loadGraph()" class="border border-gray-200 rounded px-1 py-0.5">
          <option value="1">1</option>
          <option value="2" selected>2</option>
          <option value="3">3</option>
        </select></label>
        <button onclick="window.gdCy && window.gdCy.fit()" class="px-2 py-0.5 border border-gray-200 rounded">⊕ Fit</button>
        <span id="gdInfo" class="text-gray-400 font-mono">loading…</span>
      </div>
    </div>
    <div id="gotham-detail-canvas"></div>
  </div>

  <!-- Side detail panel -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 border-t bg-gray-50">
    <!-- Properties -->
    <div>
      <h4 class="text-xs font-semibold text-gray-700 uppercase mb-2">Properties</h4>
      <?php if (!empty($obj['properties'])): ?>
      <table class="w-full text-xs">
        <?php foreach ($obj['properties'] as $k => $v): ?>
        <tr class="border-b border-gray-200">
          <td class="py-1 text-gray-500 font-mono w-32"><?= e($k) ?></td>
          <td class="py-1 text-gray-900 break-all"><?= e(is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php else: ?>
      <div class="text-xs text-gray-400">No properties.</div>
      <?php endif; ?>
    </div>
    <!-- Timeline -->
    <div>
      <h4 class="text-xs font-semibold text-gray-700 uppercase mb-2">Timeline</h4>
      <?php if (!empty($obj['events'])): ?>
      <div class="space-y-2 max-h-96 overflow-y-auto pr-1">
        <?php foreach ($obj['events'] as $e): ?>
        <div class="border-l-2 border-brand pl-2 py-1">
          <div class="text-[10px] font-mono text-gray-400">
            <?= e($e['event_date'] ?? substr($e['created_at'], 0, 10)) ?> · <?= e($e['event_type']) ?>
            <?php if ($e['source']): ?>· <?= e($e['source']) ?><?php endif; ?>
          </div>
          <div class="text-xs text-gray-700"><?= e($e['title']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="text-xs text-gray-400">No events.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Outgoing + Incoming links -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 border-t">
    <div>
      <h4 class="text-xs font-semibold text-gray-700 uppercase mb-2">Links Out (<?= count($obj['links_out'] ?? []) ?>)</h4>
      <div class="flex flex-wrap gap-1">
        <?php foreach ($obj['links_out'] ?? [] as $l): ?>
        <a href="/admin/gotham-detail.php?obj_id=<?= (int)$l['to_obj'] ?>"
           class="text-[10px] px-2 py-1 bg-white border border-gray-200 rounded hover:border-brand hover:bg-brand/5">
          <span class="text-gray-500"><?= e($l['relation']) ?> →</span>
          <span class="text-gray-900 font-medium"><?= e($l['target_name']) ?></span>
          <span class="type-badge type-<?= e($l['target_type']) ?>"><?= e($l['target_type']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div>
      <h4 class="text-xs font-semibold text-gray-700 uppercase mb-2">Links In (<?= count($obj['links_in'] ?? []) ?>)</h4>
      <div class="flex flex-wrap gap-1">
        <?php foreach ($obj['links_in'] ?? [] as $l): ?>
        <a href="/admin/gotham-detail.php?obj_id=<?= (int)$l['from_obj'] ?>"
           class="text-[10px] px-2 py-1 bg-white border border-gray-200 rounded hover:border-brand hover:bg-brand/5">
          <span class="text-gray-900 font-medium"><?= e($l['source_name']) ?></span>
          <span class="type-badge type-<?= e($l['source_type']) ?>"><?= e($l['source_type']) ?></span>
          <span class="text-gray-500">→ <?= e($l['relation']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/cytoscape@3.28.1/dist/cytoscape.min.js"></script>
<script>
const GD = { objId: <?= (int)$objId ?>, cy: null };
window.gdCy = null;

async function loadGraph() {
  const depth = document.getElementById('gdDepth').value;
  document.getElementById('gdInfo').textContent = 'loading…';
  const r = await fetch(`/api/gotham-expand.php?action=graph&obj_id=${GD.objId}&depth=${depth}`);
  const j = await r.json();
  if (!j.success) {
    document.getElementById('gdInfo').textContent = 'error: ' + (j.error || 'unknown');
    return;
  }
  document.getElementById('gdInfo').textContent = `${j.data.nodes.length} nodes · ${j.data.edges.length} edges`;
  if (window.gdCy) window.gdCy.destroy();
  window.gdCy = cytoscape({
    container: document.getElementById('gotham-detail-canvas'),
    elements: [...j.data.nodes, ...j.data.edges],
    style: [
      { selector: 'node', style: {
          'label': 'data(label)', 'color': '#374151', 'font-size': '11px',
          'text-valign': 'bottom', 'text-margin-y': 6,
          'background-color': '#2E7D6B', 'width': 32, 'height': 32,
          'border-width': 2, 'border-color': '#fff',
      }},
      { selector: 'node[type="person"]',  style: {'background-color': '#3b82f6'} },
      { selector: 'node[type="company"]', style: {'background-color': '#a855f7'} },
      { selector: 'node[type="email"]',   style: {'background-color': '#10b981'} },
      { selector: 'node[type="phone"]',   style: {'background-color': '#f59e0b'} },
      { selector: 'node[type="domain"]',  style: {'background-color': '#f43f5e'} },
      { selector: 'node[type="handle"]',  style: {'background-color': '#ec4899'} },
      { selector: 'node[type="address"]', style: {'background-color': '#14b8a6'} },
      { selector: 'node[type="ip"]',      style: {'background-color': '#64748b'} },
      { selector: 'node[verified = 1]',   style: {'border-color': '#fbbf24', 'border-width': 4} },
      { selector: `node[obj_id = ${GD.objId}]`, style: {'width': 44, 'height': 44, 'border-color': '#dc2626', 'border-width': 4} },
      { selector: 'edge', style: {
          'width': 1.5, 'line-color': '#cbd5e1', 'target-arrow-color': '#cbd5e1',
          'target-arrow-shape': 'triangle', 'curve-style': 'bezier',
          'label': 'data(label)', 'font-size': '9px', 'color': '#94a3b8',
          'text-rotation': 'autorotate', 'text-margin-y': -6,
      }},
    ],
    layout: { name: 'cose', animate: true, animationDuration: 500, nodeRepulsion: 10000, idealEdgeLength: 100 },
  });
  window.gdCy.on('tap', 'node', e => {
    const objId = e.target.data('obj_id');
    if (objId && objId !== GD.objId) {
      window.location.href = '/admin/gotham-detail.php?obj_id=' + objId;
    }
  });
}
loadGraph();
</script>
