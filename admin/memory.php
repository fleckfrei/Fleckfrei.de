<?php
/**
 * Admin: Memory Search (ontology_objects Hybrid-Search UI)
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'Memory · Hybrid Search'; $page = 'memory';

$stats = one("SELECT COUNT(*) AS total, SUM(verified=1) AS verified, COUNT(DISTINCT obj_type) AS types FROM ontology_objects");
$byType = all("SELECT obj_type, COUNT(*) AS n FROM ontology_objects GROUP BY obj_type ORDER BY n DESC LIMIT 10");

include __DIR__ . '/../includes/layout.php';
?>
<div class="max-w-5xl mx-auto">
  <div class="mb-5">
    <h1 class="text-2xl font-bold">🧠 Memory · Hybrid Search</h1>
    <p class="text-sm text-gray-600">FULLTEXT-Lexical-Search + Groq-Semantic-Rerank auf <strong><?= (int)$stats['total'] ?></strong> Ontology-Objects (<?= (int)$stats['verified'] ?> verified, <?= (int)$stats['types'] ?> types).</p>
  </div>

  <div class="bg-white rounded-xl border p-5 mb-6">
    <form id="memSearchForm" class="flex gap-2 items-center">
      <input type="text" id="memQ" placeholder="Suche: Name · Email · Firma · 'Cleaning' · 'Airbnb' ..." class="flex-1 px-4 py-3 border rounded-lg focus:border-brand focus:ring-1 focus:ring-brand"/>
      <label class="text-xs flex items-center gap-1 px-2"><input type="checkbox" id="memRerank" checked/> AI-Rerank</label>
      <button type="submit" class="px-5 py-3 bg-brand text-white rounded-lg font-semibold">🔍 Suchen</button>
    </form>
    <div id="memStatus" class="hidden text-xs mt-2"></div>
  </div>

  <div id="memResult" class="hidden space-y-3 mb-8"></div>

  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-3">📊 Memory Stats</h3>
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
      <?php foreach ($byType as $t): ?>
        <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500"><?= e($t['obj_type']) ?></div><div class="font-bold"><?= (int)$t['n'] ?></div></div>
      <?php endforeach; ?>
    </div>
    <p class="text-xs text-gray-500">Hybrid-Search: MySQL FULLTEXT (~5ms) → optional Groq-Rerank (~800ms) auf Top-20 Kandidaten.</p>
  </div>
</div>

<script>
const form = document.getElementById('memSearchForm');
const status = document.getElementById('memStatus');
const result = document.getElementById('memResult');
function esc(s){return String(s??'').replace(/[<>&"']/g,c=>({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'})[c]);}

function renderResults(data) {
  const rs = data.results || [];
  if (!rs.length) { result.innerHTML = `<div class="bg-white rounded-xl border p-5 text-center text-gray-500">Keine Treffer für „${esc(data.query)}"</div>`; result.classList.remove('hidden'); return; }
  let html = `<div class="text-xs text-gray-500 mb-2">${rs.length} Treffer · Lexical ${data.timing_ms?.lexical||0}ms · Rerank ${data.timing_ms?.rerank||0}ms · Gesamt ${data.timing_ms?.total||0}ms (${esc(data.mode||'')})</div>`;
  html += rs.map(r => `
    <div class="bg-white rounded-xl border p-4 hover:border-brand transition">
      <div class="flex items-start justify-between gap-3 mb-1">
        <div class="flex-1 min-w-0">
          <div class="font-bold">${esc(r.display_name)}</div>
          <div class="text-xs text-gray-500">${esc(r.obj_type)} · id=${r.obj_id} · ${r.verified==1?'✓ verified':'unverified'} · score=${(+r.score||0).toFixed(3)} · gesehen ${r.source_count}×</div>
        </div>
        <div class="text-xs text-gray-400">${esc((r.last_updated||'').substring(0,16))}</div>
      </div>
      <div class="text-xs text-gray-600 mt-2 font-mono">${esc(r.properties_preview||'')}</div>
    </div>
  `).join('');
  result.innerHTML = html;
  result.classList.remove('hidden');
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const q = document.getElementById('memQ').value.trim();
  if (!q) return;
  const rerank = document.getElementById('memRerank').checked;
  status.classList.remove('hidden');
  status.textContent = '⏳ Suche' + (rerank ? ' + Rerank' : '') + '...';
  try {
    const r = await fetch('/api/memory-search.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({query:q, rerank, limit: 15})});
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Fehler');
    renderResults(d);
    status.textContent = '✓ ' + d.results.length + ' Treffer in ' + d.timing_ms.total + 'ms';
  } catch (err) {
    status.textContent = '❌ ' + err.message;
  }
});
</script>
