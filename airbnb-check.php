<?php
/**
 * PUBLIC Landing: "Kostenlose Airbnb-Check" — Host-Akquise-Tool
 * Kein Login. Rate-limited per IP.
 * Kollektiert Email im 2. Schritt für Lead.
 */
require_once __DIR__ . '/includes/config.php';
$title = 'Kostenloser Airbnb-Reinigungs-Check · Fleckfrei';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title><?= htmlspecialchars($title) ?></title>
<meta name="description" content="Zeige uns deinen Airbnb-Link — unsere AI analysiert Inserat + Reviews und sagt dir in 15 Sekunden, was bei der Reinigung verbessert werden muss. Kostenlos."/>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<script>
tailwind.config = { theme: { extend: { colors: { brand: '#2E7D6B', 'brand-dark': '#1e5a4c' }, fontFamily: { sans: ['Inter','sans-serif'] } } } }
</script>
<style>body{font-family:'Inter',sans-serif}</style>
</head>
<body class="bg-gray-50">

<nav class="bg-white border-b">
  <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
    <a href="https://fleckfrei.de" class="font-bold text-xl text-brand">Fleckfrei</a>
    <a href="/login.php" class="text-sm text-gray-600 hover:text-brand">Login</a>
  </div>
</nav>

<section class="max-w-3xl mx-auto px-4 py-12">
  <div class="text-center mb-10">
    <div class="inline-block text-xs px-3 py-1 bg-brand/10 text-brand rounded-full font-semibold mb-4">🧠 Kostenloser AI-Check</div>
    <h1 class="text-3xl md:text-5xl font-bold text-gray-900 mb-4">Was sagt die AI über deine Airbnb-Wohnung?</h1>
    <p class="text-lg text-gray-600 max-w-2xl mx-auto">Link rein → wir lesen dein Inserat + die Reviews → bekommst in 15 Sekunden: qm-Schätzung, Stunden-Bedarf, welche Hot-Spots Gäste kritisieren, passende Add-Ons.</p>
  </div>

  <div class="bg-white rounded-2xl shadow-lg border p-6 mb-6">
    <div class="flex gap-2 mb-4">
      <button type="button" id="tabUrl"  class="px-4 py-2 rounded-lg text-sm font-semibold bg-brand text-white">🔗 Airbnb-URL</button>
      <button type="button" id="tabText" class="px-4 py-2 rounded-lg text-sm font-semibold bg-gray-100 text-gray-700">📝 Text einfügen</button>
    </div>
    <form id="aaForm">
      <div id="modeUrl" class="flex flex-col sm:flex-row gap-2">
        <input type="url" id="aaUrl" placeholder="https://www.airbnb.de/rooms/12345678" class="flex-1 px-4 py-3 border rounded-lg focus:border-brand focus:ring-1 focus:ring-brand"/>
        <button type="submit" class="px-6 py-3 bg-brand text-white rounded-lg font-semibold hover:bg-brand-dark transition">AI-Check starten →</button>
      </div>
      <div id="modeText" class="hidden">
        <textarea id="aaText" rows="8" placeholder="Beschreibung + Reviews von deinem Airbnb-Inserat reinkopieren..." class="w-full px-4 py-3 border rounded-lg focus:border-brand focus:ring-1 focus:ring-brand font-mono text-sm"></textarea>
        <button type="submit" class="mt-2 px-6 py-3 bg-brand text-white rounded-lg font-semibold hover:bg-brand-dark transition">AI-Check starten →</button>
      </div>
    </form>
    <div id="aaStatus" class="text-sm text-gray-500 mt-3 hidden"></div>
    <p class="text-xs text-gray-500 mt-3">💡 Airbnb blockt Crawler oft — wenn URL zu wenig liefert, nutze den Text-Modus.</p>
  </div>

  <div id="aaResult" class="hidden bg-white rounded-2xl shadow-lg border p-6 mb-6"></div>

  <div id="leadForm" class="hidden bg-brand text-white rounded-2xl shadow-lg p-6 mb-6">
    <h3 class="font-bold text-xl mb-2">Willst du ein unverbindliches Angebot?</h3>
    <p class="text-sm text-white/80 mb-4">Auf Basis dieser Analyse bereiten wir dir einen individuellen Reinigungsplan + Festpreis vor. Kostenlos & unverbindlich.</p>
    <form id="leadFormInner" class="grid grid-cols-1 md:grid-cols-3 gap-2">
      <input name="name" placeholder="Dein Name" class="px-4 py-3 rounded-lg text-gray-900"/>
      <input name="email" type="email" required placeholder="Deine Email" class="px-4 py-3 rounded-lg text-gray-900"/>
      <button class="px-4 py-3 bg-white text-brand rounded-lg font-bold hover:bg-gray-100">Angebot anfragen</button>
    </form>
    <div id="leadStatus" class="text-sm mt-2"></div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-center">
    <div class="p-5"><div class="text-3xl font-bold text-brand mb-1">15s</div><div class="text-sm text-gray-600">von Link zu Analyse</div></div>
    <div class="p-5"><div class="text-3xl font-bold text-brand mb-1">100%</div><div class="text-sm text-gray-600">kostenlos & unverbindlich</div></div>
    <div class="p-5"><div class="text-3xl font-bold text-brand mb-1">99%</div><div class="text-sm text-gray-600">STR-Buchungs-Quote bei Fleckfrei</div></div>
  </div>
</section>

<footer class="text-center py-8 text-xs text-gray-500">
  © <?= date('Y') ?> Fleckfrei · <a href="https://fleckfrei.de" class="underline">fleckfrei.de</a> · <a href="https://fleckfrei.de/impressum.html" class="underline">Impressum</a>
</footer>

<script>
const form = document.getElementById('aaForm');
const status = document.getElementById('aaStatus');
const result = document.getElementById('aaResult');
const leadSection = document.getElementById('leadForm');
let mode = 'url';
let lastAnalysis = null;

function renderResult(data) {
  lastAnalysis = data;
  const p = data.plan || {};
  if (p.parse_error) {
    result.innerHTML = `<div class="text-sm text-amber-700">AI-Antwort nicht parsebar. Versuch nochmal im Text-Modus.</div>`;
    result.classList.remove('hidden'); return;
  }
  const addons = (p.recommended_addons || []).map(a => `<span class="text-xs px-2 py-0.5 bg-emerald-100 text-emerald-700 rounded">${a}</span>`).join(' ');
  const spots  = (p.hot_spots || []).map(a => `<span class="text-xs px-2 py-0.5 bg-amber-100 text-amber-700 rounded">${a}</span>`).join(' ');
  const risks  = (p.review_risks  || []).map(a => `<li class="text-red-700 text-sm">${a}</li>`).join('');
  result.innerHTML = `
    <div class="mb-4">
      <div class="text-xs text-gray-500">Dein Inserat:</div>
      <h3 class="font-bold text-xl">${data.meta?.title || 'Analyse'}</h3>
      <p class="text-sm text-gray-600 mt-1">${p.summary_de || ''}</p>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5 text-center">
      <div class="p-3 bg-gray-50 rounded-lg"><div class="text-xs text-gray-500">Typ</div><div class="font-semibold">${p.apartment_type||'-'}</div></div>
      <div class="p-3 bg-gray-50 rounded-lg"><div class="text-xs text-gray-500">qm</div><div class="font-semibold">${p.estimated_sqm||'?'}</div></div>
      <div class="p-3 bg-gray-50 rounded-lg"><div class="text-xs text-gray-500">Betten</div><div class="font-semibold">${p.beds||'?'}</div></div>
      <div class="p-3 bg-gray-50 rounded-lg"><div class="text-xs text-gray-500">Bäder</div><div class="font-semibold">${p.baths||'?'}</div></div>
      <div class="p-3 bg-brand/10 rounded-lg"><div class="text-xs text-gray-500">Empf. h</div><div class="font-bold text-brand">${p.recommended_hours||'?'}</div></div>
    </div>
    <div class="mb-3"><span class="text-xs text-gray-500 font-semibold">Hot Spots:</span><br>${spots || '-'}</div>
    <div class="mb-3"><span class="text-xs text-gray-500 font-semibold">Empfohlene Add-Ons:</span><br>${addons || '-'}</div>
    ${risks ? `<div class="mb-3 p-3 bg-red-50 rounded-lg"><div class="text-xs text-red-700 font-semibold mb-1">⚠ Was Gäste kritisiert haben:</div><ul class="list-disc list-inside space-y-1">${risks}</ul></div>` : ''}
  `;
  result.classList.remove('hidden');
  leadSection.classList.remove('hidden');
}

document.getElementById('tabUrl').onclick = () => {
  mode = 'url';
  document.getElementById('tabUrl').className = 'px-4 py-2 rounded-lg text-sm font-semibold bg-brand text-white';
  document.getElementById('tabText').className = 'px-4 py-2 rounded-lg text-sm font-semibold bg-gray-100 text-gray-700';
  document.getElementById('modeUrl').classList.remove('hidden');
  document.getElementById('modeText').classList.add('hidden');
};
document.getElementById('tabText').onclick = () => {
  mode = 'text';
  document.getElementById('tabUrl').className = 'px-4 py-2 rounded-lg text-sm font-semibold bg-gray-100 text-gray-700';
  document.getElementById('tabText').className = 'px-4 py-2 rounded-lg text-sm font-semibold bg-brand text-white';
  document.getElementById('modeUrl').classList.add('hidden');
  document.getElementById('modeText').classList.remove('hidden');
};

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = mode === 'url' ? { url: document.getElementById('aaUrl').value.trim() } : { text: document.getElementById('aaText').value.trim() };
  if (!payload.url && !payload.text) return;
  status.classList.remove('hidden');
  status.textContent = '⏳ AI liest Inserat…';
  result.classList.add('hidden');
  try {
    const r = await fetch('/api/airbnb-check-public.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Fehler');
    renderResult(d);
    status.textContent = '✓ Analyse fertig';
    if (window.plausible) plausible('airbnb-check-complete');
  } catch (err) {
    status.textContent = '❌ ' + err.message;
  }
});

document.getElementById('leadFormInner').addEventListener('submit', async (e) => {
  e.preventDefault();
  const data = new FormData(e.target);
  const body = { name: data.get('name'), email: data.get('email'), analysis: lastAnalysis };
  const leadStatus = document.getElementById('leadStatus');
  leadStatus.textContent = '⏳ Sende…';
  try {
    const r = await fetch('/api/airbnb-lead.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Fehler');
    leadStatus.innerHTML = '✓ Danke! Wir melden uns in 24h.';
    e.target.style.display = 'none';
    if (window.plausible) plausible('airbnb-check-lead');
  } catch (err) {
    leadStatus.textContent = '❌ ' + err.message;
  }
});

if (window.plausible) plausible('airbnb-check-view');
</script>
<script defer data-domain="app.fleckfrei.de" src="https://plausible.io/js/script.js"></script>
</body>
</html>
