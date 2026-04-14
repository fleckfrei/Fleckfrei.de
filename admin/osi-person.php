<?php
/**
 * Admin: OSI Person-Dossier — 1-click cross-platform person recon, EU/US-aware.
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$title = 'OSI · Person-Dossier'; $page = 'osi-person';

$recent = all("SELECT * FROM osi_person_scans ORDER BY created_at DESC LIMIT 15") ?: [];
include __DIR__ . '/../includes/layout.php';
?>
<div class="max-w-6xl mx-auto">
  <div class="mb-5">
    <h1 class="text-2xl font-bold">🕵️ OSI · Person-Dossier</h1>
    <p class="text-sm text-gray-600">Name/Email/Handy/Username → 8 parallele Quellen (Maigret, Holehe, Phoneinfoga, HIBP, SearXNG) · EU/US-Routing · AI-konsolidiert</p>
  </div>

  <div class="bg-white rounded-xl border p-5 mb-6">
    <form id="opForm" class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <div><label class="block text-xs text-gray-500 mb-1">Name</label><input name="name" class="w-full px-3 py-2 border rounded-lg" placeholder="Max Mustermann"/></div>
      <div><label class="block text-xs text-gray-500 mb-1">Email</label><input name="email" type="email" class="w-full px-3 py-2 border rounded-lg" placeholder="max@example.com"/></div>
      <div><label class="block text-xs text-gray-500 mb-1">Handy</label><input name="phone" class="w-full px-3 py-2 border rounded-lg" placeholder="+49 30 12345678"/></div>
      <div><label class="block text-xs text-gray-500 mb-1">Username / Handle</label><input name="username" class="w-full px-3 py-2 border rounded-lg" placeholder="maxmust"/></div>
      <div class="md:col-span-2 flex items-center gap-3">
        <label class="text-xs text-gray-500">Region:</label>
        <select name="region" class="px-3 py-2 border rounded-lg text-sm">
          <option value="auto">🌍 Auto-detect</option>
          <option value="EU">🇪🇺 Europa</option>
          <option value="US">🇺🇸 USA</option>
        </select>
        <button type="submit" class="ml-auto px-6 py-2 bg-brand text-white rounded-lg font-semibold">🔍 Scan starten</button>
      </div>
    </form>
    <div id="opStatus" class="hidden text-sm mt-3"></div>
  </div>

  <div id="opResult" class="hidden space-y-5 mb-8"></div>

  <?php if ($recent): ?>
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-3">🕓 Letzte Scans</h3>
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-xs text-gray-500 uppercase"><tr><th class="px-3 py-2 text-left">Datum</th><th class="px-3 py-2 text-left">Query</th><th class="px-3 py-2 text-left">Region</th><th class="px-3 py-2 text-right"></th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): $q = json_decode($r['query_json'], true) ?: []; ?>
        <tr class="border-t">
          <td class="px-3 py-2 text-xs text-gray-500"><?= e(substr($r['created_at'],0,16)) ?></td>
          <td class="px-3 py-2 text-xs"><?= e(($q['name']??'') . ' ' . ($q['email']??'') . ' ' . ($q['username']??'') . ' ' . ($q['phone']??'')) ?></td>
          <td class="px-3 py-2 text-xs"><?= e($r['region']) ?></td>
          <td class="px-3 py-2 text-right"><button onclick='loadPrev(<?= json_encode(["findings"=>json_decode($r['findings_json'],true),"dossier"=>json_decode($r['dossier_json'],true),"region"=>$r['region']], JSON_HEX_APOS) ?>)' class="text-xs text-brand hover:underline">Öffnen</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
const form = document.getElementById('opForm');
const status = document.getElementById('opStatus');
const result = document.getElementById('opResult');

function card(title, body, bg='bg-white') {
  return `<div class="${bg} rounded-xl border p-5"><h3 class="font-bold text-base mb-3">${title}</h3>${body}</div>`;
}
function esc(s){return String(s??'').replace(/[<>&"']/g,c=>({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'})[c]);}
function link(url, label){return `<a href="${url}" target="_blank" rel="noopener" class="text-brand hover:underline">${esc(label||url)} ↗</a>`;}

function renderResult(data) {
  const f = data.findings || {}, d = data.dossier || {};
  const id = d.identity || {};
  let html = '';

  // Identity
  html += card('👤 Identität <span class="ml-auto text-xs text-gray-400">Region: ' + data.region + '</span>',
    `<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Wahrscheinlicher Name</div><div class="font-semibold">${esc(id.likely_full_name||'-')}</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Konfidenz</div><div class="font-semibold">${id.confidence_pct||0}%</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Alter-Schätzung</div><div class="font-semibold">${esc(id.age_estimate||'?')}</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Aliase</div><div class="text-sm">${(id.aliases||[]).map(esc).join(', ')||'-'}</div></div>
     </div>
     <div class="mt-3 text-sm text-gray-700">${esc(d.summary_de||'')}</div>`);

  // Accounts
  const accs = d.accounts_found || [];
  if (accs.length) {
    html += card(`🔗 Gefundene Accounts (${accs.length})`,
      `<div class="grid grid-cols-1 md:grid-cols-2 gap-2">${accs.map(a=>`<div class="p-2 border rounded text-xs"><strong>${esc(a.platform)}</strong> · ${link(a.url,a.handle||a.url)} <span class="text-gray-400">(${esc(a.confidence||'?')})</span></div>`).join('')}</div>`);
  }

  // Maigret raw (if we got hits)
  const mg = f.maigret || {};
  const mgHits = Object.entries(mg).filter(([k,v]) => typeof v === 'object' && v && (v.exists === true || v.status === 'Claimed' || v.url)).slice(0, 40);
  if (mgHits.length) {
    html += card(`🕵️ Maigret (${mgHits.length} Treffer)`,
      `<div class="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">${mgHits.map(([site,v]) => `<div class="p-2 bg-gray-50 rounded"><strong>${esc(site)}</strong>: ${link(v.url||'#', 'open')}</div>`).join('')}</div>`);
  }

  // Holehe
  const hh = f.holehe || {};
  const hhHits = (Array.isArray(hh) ? hh : (hh.results || [])).filter(x => x && (x.exists === true || x.rateLimit === false && x.exists));
  if (hhHits.length) {
    html += card(`📧 Holehe (Email-Accounts: ${hhHits.length})`,
      `<div class="flex flex-wrap gap-1">${hhHits.map(x=>`<span class="text-xs px-2 py-1 bg-emerald-100 text-emerald-700 rounded">${esc(x.name||x.domain||'?')}</span>`).join('')}</div>`);
  }

  // Phoneinfoga
  if (f.phoneinfoga && typeof f.phoneinfoga === 'object') {
    const p = f.phoneinfoga;
    html += card('📱 Phoneinfoga',
      `<div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm"><div class="p-2 bg-gray-50 rounded"><div class="text-xs text-gray-500">Land</div><div>${esc(p.country||p.country_name||'?')}</div></div><div class="p-2 bg-gray-50 rounded"><div class="text-xs text-gray-500">Carrier</div><div>${esc(p.carrier||'?')}</div></div><div class="p-2 bg-gray-50 rounded"><div class="text-xs text-gray-500">Typ</div><div>${esc(p.line_type||p.type||'?')}</div></div><div class="p-2 bg-gray-50 rounded"><div class="text-xs text-gray-500">Valid</div><div>${p.valid===true?'✓':'?'}</div></div></div>`);
  }

  // Breaches
  const b = f.breaches || [];
  if (Array.isArray(b) && b.length) {
    html += card(`⚠️ HIBP Breaches (${b.length})`,
      `<ul class="list-disc list-inside text-sm">${b.slice(0,15).map(x=>`<li><strong>${esc(x.Name||x.Title)}</strong> (${esc(x.BreachDate||'')}) — ${esc((x.DataClasses||[]).join(', '))}</li>`).join('')}</ul>`);
  } else if (b.count || d.breach_summary?.count) {
    html += card('⚠️ Breach-Summary', `<div class="text-sm">${d.breach_summary?.count||0} Breach(s) · ${esc(d.breach_summary?.worst_breach||'-')}</div>`);
  }

  // Region-specific manual hints
  const regionHints = f.eu_hint || f.us_hint;
  if (regionHints) {
    html += card(`🌐 ${f.eu_hint?'EU':'US'} Manual-Lookups`,
      `<div class="grid grid-cols-2 gap-2 text-sm">${Object.entries(regionHints).map(([k,v])=>`<div class="p-2 bg-gray-50 rounded">${link(v,k)}</div>`).join('')}</div>`);
  }

  // Google-Dorks
  if (f.dorks && Object.keys(f.dorks).length) {
    html += card('🔎 Google-Dork-Treffer',
      Object.entries(f.dorks).map(([q,rs])=>`<div class="mb-3"><div class="text-xs text-gray-500 font-mono mb-1">${esc(q)}</div><div class="space-y-1">${rs.map(r=>`<div class="p-2 border rounded text-xs">${link(r.url,r.title||r.url)}<div class="text-gray-500 mt-1">${esc(r.content||'')}</div></div>`).join('')}</div></div>`).join(''));
  }

  // Image Search
  if (f.image_search) {
    html += card('🖼️ Reverse Image / Photo Search',
      `<div class="flex flex-wrap gap-2 text-sm">${Object.entries(f.image_search).map(([k,v])=>`<a href="${v}" target="_blank" class="px-3 py-1 border rounded text-brand hover:bg-brand/5">${esc(k)} ↗</a>`).join('')}</div>`);
  }

  // Next Steps
  const steps = d.next_osint_steps || [];
  if (steps.length) {
    html += card('📋 Nächste OSINT-Schritte', `<ul class="list-disc list-inside text-sm space-y-1">${steps.map(s=>`<li>${esc(s)}</li>`).join('')}</ul>`);
  }

  // Raw JSON collapse
  html += `<details class="bg-gray-50 rounded-xl border p-4"><summary class="cursor-pointer text-sm font-semibold">🧪 Raw JSON (für Debug/Export)</summary><pre class="text-xs mt-3 overflow-auto max-h-96">${esc(JSON.stringify({findings:f,dossier:d}, null, 2))}</pre></details>`;

  result.innerHTML = html;
  result.classList.remove('hidden');
}

function loadPrev(p) {
  renderResult({findings:p.findings||{}, dossier:p.dossier||{}, region:p.region||'?'});
  window.scrollTo({top: result.offsetTop-80, behavior:'smooth'});
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const d = new FormData(form);
  const payload = {name:d.get('name'), email:d.get('email'), phone:d.get('phone'), username:d.get('username'), region:d.get('region')};
  if (!payload.name && !payload.email && !payload.phone && !payload.username) {
    status.innerHTML = '<div class="text-red-600 text-sm">Mindestens 1 Feld ausfüllen.</div>'; status.classList.remove('hidden'); return;
  }
  status.classList.remove('hidden');
  status.innerHTML = '⏳ <strong>Scanne parallel</strong> — Maigret · Holehe · Phoneinfoga · HIBP · SearXNG · AI-Konsolidierung (~30s)';
  result.classList.add('hidden');
  try {
    const r = await fetch('/api/osi-person.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const data = await r.json();
    if (!data.success) throw new Error(data.error || 'Fehler');
    renderResult(data);
    status.textContent = '✓ Scan fertig · Region: ' + data.region;
  } catch (err) {
    status.innerHTML = '<div class="text-red-600 text-sm">❌ '+err.message+'</div>';
  }
});
</script>
