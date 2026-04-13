<?php
/**
 * Host: Airbnb-Link AI-Analyse → Auto-Reinigungsplan
 */
require_once __DIR__ . '/../includes/auth.php';
requireCustomer();
$title = 'Airbnb-Analyse'; $page = 'airbnb';

$customerId = me()['id'];
$previous = all("SELECT * FROM airbnb_analyses WHERE customer_id_fk=? ORDER BY created_at DESC LIMIT 10", [$customerId]) ?: [];

include __DIR__ . '/../includes/layout.php';
?>
<div class="max-w-4xl mx-auto">
  <div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">🧠 Airbnb-Link Analyzer</h1>
    <p class="text-sm text-gray-600 mt-1">Gib den Link deines Airbnb-Inserats → AI liest Beschreibung + Reviews → Reinigungsplan-Vorschlag.</p>
  </div>

  <div class="bg-white rounded-xl border p-5 mb-6">
    <div class="flex gap-2 mb-3">
      <button type="button" id="tabUrl"  class="px-4 py-2 rounded-lg text-sm font-semibold bg-brand text-white">🔗 URL</button>
      <button type="button" id="tabText" class="px-4 py-2 rounded-lg text-sm font-semibold bg-gray-100 text-gray-700">📝 Text einfügen</button>
    </div>
    <form id="aaForm">
      <div id="modeUrl" class="flex gap-2">
        <input type="url" id="aaUrl" placeholder="https://www.airbnb.de/rooms/12345678" class="flex-1 px-4 py-3 border rounded-lg focus:border-brand focus:ring-1 focus:ring-brand"/>
        <button type="submit" id="aaBtn" class="px-5 py-3 bg-brand text-white rounded-lg font-semibold whitespace-nowrap">Analysieren</button>
      </div>
      <div id="modeText" class="hidden">
        <textarea id="aaText" rows="8" placeholder="Beschreibung + Reviews vom Airbnb-Inserat hier reinkopieren..." class="w-full px-4 py-3 border rounded-lg focus:border-brand focus:ring-1 focus:ring-brand font-mono text-sm"></textarea>
        <button type="submit" class="mt-2 px-5 py-3 bg-brand text-white rounded-lg font-semibold">Text analysieren</button>
      </div>
    </form>
    <div class="text-xs text-gray-500 mt-2">💡 Airbnb blockiert Crawler oft — wenn URL-Analyse mager ist, nimm den Text-Modus: Inserat-Seite öffnen → alles markieren (Cmd+A) → hier reinkopieren.</div>
    <div id="aaStatus" class="text-sm text-gray-500 mt-2 hidden"></div>
  </div>

  <div id="aaResult" class="hidden bg-white rounded-xl border p-5 mb-6"></div>

  <?php if ($previous): ?>
  <div class="bg-white rounded-xl border p-5">
    <h3 class="font-semibold mb-3">🕓 Frühere Analysen</h3>
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
        <tr><th class="px-3 py-2 text-left">Datum</th><th class="px-3 py-2 text-left">Titel</th><th class="px-3 py-2 text-right">Aktion</th></tr>
      </thead>
      <tbody>
        <?php foreach ($previous as $p): ?>
        <tr class="border-t hover:bg-gray-50">
          <td class="px-3 py-2 text-xs"><?= e(substr($p['created_at'], 0, 16)) ?></td>
          <td class="px-3 py-2"><?= e($p['title'] ?: '(ohne Titel)') ?></td>
          <td class="px-3 py-2 text-right">
            <button onclick='showPrev(<?= json_encode(['url'=>$p['url'],'title'=>$p['title'],'plan'=>json_decode($p['plan_json'],true)], JSON_HEX_APOS) ?>)' class="text-brand hover:underline text-xs">Öffnen</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<script>
const form = document.getElementById('aaForm');
const btn = document.getElementById('aaBtn');
const status = document.getElementById('aaStatus');
const result = document.getElementById('aaResult');

function renderResult(data) {
  const p = data.plan || {};
  if (p.parse_error) {
    result.innerHTML = `<div class="text-sm text-amber-700">AI-Antwort nicht parsebar:</div><pre class="text-xs bg-gray-50 p-3 rounded mt-2 whitespace-pre-wrap">${(p.raw||'').replace(/</g,'&lt;')}</pre>`;
    result.classList.remove('hidden'); return;
  }
  const addons = (p.recommended_addons || []).map(a => `<span class="text-xs px-2 py-0.5 bg-emerald-100 text-emerald-700 rounded">${a}</span>`).join(' ');
  const spots  = (p.hot_spots || []).map(a => `<span class="text-xs px-2 py-0.5 bg-amber-100 text-amber-700 rounded">${a}</span>`).join(' ');
  const tasks  = (p.special_tasks || []).map(a => `<li>${a}</li>`).join('');
  const risks  = (p.review_risks  || []).map(a => `<li class="text-red-700">${a}</li>`).join('');
  result.innerHTML = `
    <h3 class="font-semibold mb-2">${data.meta?.title || 'Analyse'}</h3>
    <p class="text-sm text-gray-600 mb-4">${p.summary_de || ''}</p>
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4 text-center">
      <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Typ</div><div class="font-semibold text-sm">${p.apartment_type||'-'}</div></div>
      <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">qm</div><div class="font-semibold text-sm">${p.estimated_sqm||'?'}</div></div>
      <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Betten</div><div class="font-semibold text-sm">${p.beds||'?'}</div></div>
      <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Bäder</div><div class="font-semibold text-sm">${p.baths||'?'}</div></div>
      <div class="p-3 bg-brand/10 rounded"><div class="text-xs text-gray-500">Empf. h</div><div class="font-semibold text-sm">${p.recommended_hours||'?'}</div></div>
    </div>
    <div class="mb-3"><span class="text-xs text-gray-500">Hot Spots:</span> ${spots || '-'}</div>
    <div class="mb-3"><span class="text-xs text-gray-500">Empfohlene Add-Ons:</span> ${addons || '-'}</div>
    ${tasks ? `<div class="mb-3"><div class="text-xs text-gray-500 mb-1">Spezial-Aufgaben:</div><ul class="list-disc list-inside text-sm">${tasks}</ul></div>` : ''}
    ${risks ? `<div class="mb-3"><div class="text-xs text-gray-500 mb-1">Review-Risiken:</div><ul class="list-disc list-inside text-sm">${risks}</ul></div>` : ''}
    <div class="flex gap-2 mt-4 pt-3 border-t">
      <a href="/customer/booking.php?prefill_hours=${encodeURIComponent(p.recommended_hours||'')}&prefill_sqm=${encodeURIComponent(p.estimated_sqm||'')}" class="px-4 py-2 bg-brand text-white rounded-lg text-sm font-semibold">Buchung erstellen</a>
      <a href="${data.url}" target="_blank" class="px-4 py-2 border rounded-lg text-sm">Inserat öffnen</a>
    </div>
  `;
  result.classList.remove('hidden');
}

function showPrev(p) {
  renderResult({ meta: { title: p.title }, url: p.url, plan: p.plan || {} });
  window.scrollTo({top: result.offsetTop - 80, behavior: 'smooth'});
}

let mode = 'url';
document.getElementById('tabUrl').addEventListener('click', () => {
  mode = 'url';
  document.getElementById('tabUrl').className = 'px-4 py-2 rounded-lg text-sm font-semibold bg-brand text-white';
  document.getElementById('tabText').className = 'px-4 py-2 rounded-lg text-sm font-semibold bg-gray-100 text-gray-700';
  document.getElementById('modeUrl').classList.remove('hidden');
  document.getElementById('modeText').classList.add('hidden');
});
document.getElementById('tabText').addEventListener('click', () => {
  mode = 'text';
  document.getElementById('tabUrl').className = 'px-4 py-2 rounded-lg text-sm font-semibold bg-gray-100 text-gray-700';
  document.getElementById('tabText').className = 'px-4 py-2 rounded-lg text-sm font-semibold bg-brand text-white';
  document.getElementById('modeUrl').classList.add('hidden');
  document.getElementById('modeText').classList.remove('hidden');
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = mode === 'url'
    ? { url: document.getElementById('aaUrl').value.trim() }
    : { text: document.getElementById('aaText').value.trim() };
  if (!payload.url && !payload.text) { status.textContent = '❌ Leer'; status.classList.remove('hidden'); return; }
  btn.disabled = true; btn.textContent = 'Analysiere...';
  status.classList.remove('hidden');
  status.textContent = '⏳ AI liest Inserat (≈15s)...';
  result.classList.add('hidden');
  try {
    const r = await fetch('/api/airbnb-analyze.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Unbekannter Fehler');
    renderResult(d);
    const note = d.scrape_mode === 'blocked' ? ' (URL geblockt — nutze Text-Modus für mehr Details)' : '';
    status.textContent = (d._cache_hit ? '✓ aus Cache' : '✓ analysiert') + note;
  } catch (err) {
    status.textContent = '❌ ' + err.message;
    result.classList.add('hidden');
  } finally {
    btn.disabled = false; btn.textContent = 'Analysieren';
  }
});
</script>
