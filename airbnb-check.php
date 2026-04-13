<?php
/**
 * PUBLIC Landing: Airbnb Deep-Check (v2 Business Dossier)
 */
require_once __DIR__ . '/includes/config.php';
$title = 'Airbnb Business-Dossier · kostenlos · Fleckfrei';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title><?= htmlspecialchars($title) ?></title>
<meta name="description" content="Business-Dossier für dein Airbnb — Marktvergleich, Review-Forensik, Revenue-Impact, Cleaning-SWOT, 12-Monat-ROI. Kostenlos, 30 Sekunden."/>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<script>
tailwind.config = { theme: { extend: { colors: { brand: '#2E7D6B', 'brand-dark': '#1e5a4c', danger: '#dc2626' }, fontFamily: { sans: ['Inter','sans-serif'] } } } }
</script>
<style>body{font-family:'Inter',sans-serif} .gradient-header{background:linear-gradient(135deg,#2E7D6B 0%,#1e5a4c 100%)}</style>
</head>
<body class="bg-gray-50">

<nav class="bg-white border-b sticky top-0 z-10">
  <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
    <a href="https://fleckfrei.de" class="font-bold text-xl text-brand">Fleckfrei</a>
    <a href="/login.php" class="text-sm text-gray-600 hover:text-brand">Login →</a>
  </div>
</nav>

<section class="gradient-header text-white py-16 px-4">
  <div class="max-w-4xl mx-auto text-center">
    <div class="inline-block text-xs px-3 py-1 bg-white/20 rounded-full font-semibold mb-4">🧠 AI-gestütztes Business-Dossier · kostenlos</div>
    <h1 class="text-3xl md:text-5xl font-bold mb-4 leading-tight">Was kostet dich eine schlechte Reinigung wirklich?</h1>
    <p class="text-lg text-white/90 max-w-2xl mx-auto">Airbnb-Link rein → Live-Marktvergleich · Review-Forensik · Revenue-Verlust-Rechnung · 12-Monat-ROI. In 30 Sekunden.</p>
  </div>
</section>

<section class="max-w-4xl mx-auto px-4 -mt-8">
  <div class="bg-white rounded-2xl shadow-xl border p-6 mb-8">
    <div class="flex gap-2 mb-4">
      <button type="button" id="tabUrl"  class="px-4 py-2 rounded-lg text-sm font-semibold bg-brand text-white">🔗 Airbnb-URL</button>
      <button type="button" id="tabText" class="px-4 py-2 rounded-lg text-sm font-semibold bg-gray-100 text-gray-700">📝 Beschreibung einfügen</button>
    </div>
    <form id="aaForm">
      <div id="modeUrl" class="flex flex-col sm:flex-row gap-2">
        <input type="url" id="aaUrl" placeholder="https://www.airbnb.de/rooms/12345678" class="flex-1 px-4 py-3 border rounded-lg focus:border-brand focus:ring-2 focus:ring-brand/20"/>
        <button type="submit" class="px-6 py-3 bg-brand text-white rounded-lg font-semibold hover:bg-brand-dark transition whitespace-nowrap">🔍 Dossier generieren</button>
      </div>
      <div id="modeText" class="hidden">
        <textarea id="aaText" rows="10" placeholder="Beschreibung + alle Reviews von deinem Airbnb-Inserat hier reinkopieren (je mehr Text, desto präziser)..." class="w-full px-4 py-3 border rounded-lg focus:border-brand focus:ring-2 focus:ring-brand/20 font-mono text-sm"></textarea>
        <button type="submit" class="mt-2 px-6 py-3 bg-brand text-white rounded-lg font-semibold hover:bg-brand-dark transition">🔍 Dossier generieren</button>
      </div>
    </form>
    <p class="text-xs text-gray-500 mt-3">💡 Airbnb blockt Crawler. Für präzise Ergebnisse: Listing-Seite öffnen → Cmd+A → hier einfügen.</p>
    <div id="aaStatus" class="text-sm mt-3 hidden"></div>
  </div>

  <div id="aaResult" class="hidden space-y-6 pb-12"></div>

  <div id="leadForm" class="hidden bg-brand text-white rounded-2xl shadow-xl p-6 mb-8">
    <h3 class="font-bold text-2xl mb-2">💰 Unverbindliches Angebot anfragen</h3>
    <p class="text-sm text-white/90 mb-4">Auf Basis dieser Analyse bereiten wir dir einen maßgeschneiderten Reinigungsplan + Festpreis. Kostenlos & unverbindlich.</p>
    <form id="leadFormInner" class="grid grid-cols-1 md:grid-cols-3 gap-2">
      <input name="name" placeholder="Dein Name" class="px-4 py-3 rounded-lg text-gray-900"/>
      <input name="email" type="email" required placeholder="Deine Email" class="px-4 py-3 rounded-lg text-gray-900"/>
      <button class="px-4 py-3 bg-white text-brand rounded-lg font-bold hover:bg-gray-100">Angebot anfragen →</button>
    </form>
    <div id="leadStatus" class="text-sm mt-2"></div>
  </div>
</section>

<footer class="text-center py-8 text-xs text-gray-500">
  © <?= date('Y') ?> Fleckfrei · <a href="https://fleckfrei.de" class="underline">fleckfrei.de</a> · <a href="https://fleckfrei.de/impressum.html" class="underline">Impressum</a> · <a href="https://fleckfrei.de/datenschutz.html" class="underline">Datenschutz</a>
</footer>

<script>
const form = document.getElementById('aaForm');
const status = document.getElementById('aaStatus');
const result = document.getElementById('aaResult');
const leadSection = document.getElementById('leadForm');
let mode = 'url', lastAnalysis = null;

function card(title, content, bg='bg-white', border='') {
  return `<div class="${bg} ${border} rounded-2xl shadow-sm border p-6">
    <h3 class="font-bold text-lg mb-4 flex items-center gap-2">${title}</h3>
    ${content}
  </div>`;
}
function tag(text, color='gray') {
  const colors = {
    green:'bg-emerald-100 text-emerald-700',
    red:'bg-red-100 text-red-700',
    amber:'bg-amber-100 text-amber-700',
    blue:'bg-blue-100 text-blue-700',
    gray:'bg-gray-100 text-gray-700',
    brand:'bg-brand/10 text-brand'
  };
  return `<span class="inline-block text-xs px-2 py-0.5 rounded ${colors[color]||colors.gray} mr-1 mb-1">${text}</span>`;
}
function list(items, className='list-disc list-inside text-sm space-y-1') {
  return items && items.length ? `<ul class="${className}">${items.map(i=>`<li>${i}</li>`).join('')}</ul>` : '<span class="text-xs text-gray-400">-</span>';
}
function renderDossier(data) {
  lastAnalysis = data;
  const d = data.dossier || {};
  if (d.parse_error) {
    result.innerHTML = card('⚠️ Parse-Fehler', `<pre class="text-xs bg-gray-50 p-3 rounded whitespace-pre-wrap">${(d.raw||'').replace(/</g,'&lt;')}</pre>`);
    result.classList.remove('hidden'); return;
  }
  const la = d.listing_audit || {};
  const mp = d.market_position || {};
  const rf = d.review_forensics || {};
  const ri = d.revenue_impact || {};
  const sw = d.swot || {};
  const cp = d.cleaning_plan || {};
  const bc = d.business_case || {};
  const ap = d.action_plan || {};
  const market = data.market || {};
  const risk = parseInt(d.risk_score) || 5;
  const riskColor = risk >= 7 ? 'red' : (risk >= 4 ? 'amber' : 'green');
  const riskBg = risk >= 7 ? 'bg-red-50 border-red-200' : (risk >= 4 ? 'bg-amber-50 border-amber-200' : 'bg-emerald-50 border-emerald-200');

  let html = '';

  // Executive Summary + Risk
  html += card(`🎯 Executive Summary`,
    `<p class="text-gray-700 mb-4">${d.summary_de || '-'}</p>
     <div class="flex items-center gap-3 p-3 rounded-lg ${riskBg} border">
       <div class="text-3xl font-bold">${risk}/10</div>
       <div><div class="text-xs text-gray-500">Risk-Score</div><div class="text-sm font-semibold">${risk>=7?'⚠️ Kritisch':risk>=4?'⚡ Handlungsbedarf':'✓ Stabil'}</div></div>
     </div>`);

  // Listing Audit
  const signalClr = la.signal_quality === 'high' ? 'green' : (la.signal_quality === 'medium' ? 'amber' : 'red');
  html += card(`🏠 Listing-Audit <span class="ml-auto text-xs font-normal">${tag('Signal: '+la.signal_quality, signalClr)}</span>`,
    `<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3 text-center">
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Typ</div><div class="font-semibold text-sm">${la.apartment_type||'-'}</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">qm</div><div class="font-semibold text-sm">${la.estimated_sqm||'?'}</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Gäste</div><div class="font-semibold text-sm">${la.guests||'?'}</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">€/Nacht est.</div><div class="font-semibold text-sm">${la.estimated_price_per_night_eur||'?'}€</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Betten</div><div class="font-semibold text-sm">${la.beds||'?'}</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Bäder</div><div class="font-semibold text-sm">${la.baths||'?'}</div></div>
       <div class="p-3 bg-gray-50 rounded col-span-2"><div class="text-xs text-gray-500">Klasse</div><div class="font-semibold text-sm">${la.apartment_class||'?'}</div></div>
     </div>`);

  // Market Position
  if (market && market.avg_rating) {
    html += card(`📊 Markt-Position Berlin <span class="ml-auto text-xs font-normal text-gray-400">${market.sample_size} Listings</span>`,
      `<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 text-center">
         <div class="p-3 bg-brand/10 rounded"><div class="text-xs text-gray-500">Berlin-Ø-Rating</div><div class="font-bold text-brand">${market.avg_rating}/5</div></div>
         <div class="p-3 bg-brand/10 rounded"><div class="text-xs text-gray-500">Berlin-Ø Preis/N</div><div class="font-bold text-brand">$${market.avg_price_usd}</div></div>
         <div class="p-3 bg-brand/10 rounded"><div class="text-xs text-gray-500">Median Reviews</div><div class="font-bold text-brand">${market.median_reviews}</div></div>
         <div class="p-3 bg-amber-50 rounded"><div class="text-xs text-gray-500">Top-20% benötigt</div><div class="font-bold text-amber-700">${mp.rating_benchmark_needed||'?'}</div></div>
       </div>
       <p class="text-sm text-gray-700 mb-2"><strong>Preis-Position:</strong> ${tag(mp.price_vs_market || '?', mp.price_vs_market && mp.price_vs_market.includes('over') ? 'amber' : 'green')} ${mp.price_comment_de||''}</p>
       <div class="mt-3 grid md:grid-cols-2 gap-3">
         <div class="p-3 bg-emerald-50 rounded"><div class="text-xs text-emerald-700 font-semibold">✓ Vorteil</div><div class="text-sm">${mp.competitive_advantage||'-'}</div></div>
         <div class="p-3 bg-red-50 rounded"><div class="text-xs text-red-700 font-semibold">✗ Schwäche</div><div class="text-sm">${mp.competitive_weakness||'-'}</div></div>
       </div>`);
  }

  // Review Forensics
  html += card(`🔍 Review-Forensik`,
    `<div class="p-3 bg-red-50 border border-red-200 rounded mb-3">
       <div class="text-xs text-red-700 font-semibold mb-1">⚠️ SOFORT FIXEN:</div>
       <div class="text-sm font-medium">${rf.top_priority_fix||'-'}</div>
     </div>
     <div class="mb-3"><div class="text-xs text-gray-500 font-semibold mb-1">Identifizierte Beschwerden:</div>${list(rf.identified_complaints)}</div>
     <div class="mb-3"><div class="text-xs text-gray-500 font-semibold mb-1">Sauberkeits-Red-Flags:</div>${list(rf.cleanliness_red_flags)}</div>
     <div class="text-sm text-gray-600">💡 Geschätzter Review-Verlust durch Sauberkeits-Issues: <strong class="text-red-600">${rf.estimated_review_impact_pct||'?'}</strong></div>`);

  // Revenue Impact
  html += card(`💸 Revenue-Impact`,
    `<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
       <div class="p-4 bg-red-50 rounded text-center"><div class="text-xs text-gray-500">verlorene Buchungen/Jahr</div><div class="text-2xl font-bold text-red-600">${ri.lost_bookings_per_year_estimate||'?'}</div></div>
       <div class="p-4 bg-red-50 rounded text-center"><div class="text-xs text-gray-500">verlorener Umsatz/Jahr</div><div class="text-2xl font-bold text-red-600">${ri.lost_revenue_eur_per_year||'?'}€</div></div>
       <div class="p-4 bg-emerald-50 rounded text-center"><div class="text-xs text-gray-500">Fleckfrei-ROI</div><div class="text-2xl font-bold text-emerald-700">${ri.fleckfrei_roi_ratio||'-'}</div></div>
     </div>
     <p class="text-sm text-gray-600">${ri.reasoning_de||''}</p>`);

  // SWOT
  html += card(`🎯 Cleaning-SWOT`,
    `<div class="grid grid-cols-2 gap-3">
       <div class="p-3 bg-emerald-50 rounded"><div class="text-xs text-emerald-700 font-bold mb-1">Strengths</div>${list(sw.strengths)}</div>
       <div class="p-3 bg-red-50 rounded"><div class="text-xs text-red-700 font-bold mb-1">Weaknesses</div>${list(sw.weaknesses)}</div>
       <div class="p-3 bg-blue-50 rounded"><div class="text-xs text-blue-700 font-bold mb-1">Opportunities</div>${list(sw.opportunities)}</div>
       <div class="p-3 bg-amber-50 rounded"><div class="text-xs text-amber-700 font-bold mb-1">Threats</div>${list(sw.threats)}</div>
     </div>`);

  // Cleaning Plan
  const addons = (cp.recommended_addons || []).map(a => tag(a, 'brand')).join('');
  const spots  = (cp.hot_spots || []).map(a => tag(a, 'amber')).join('');
  html += card(`🧹 Reinigungsplan`,
    `<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 text-center">
       <div class="p-3 bg-brand/10 rounded"><div class="text-xs text-gray-500">Empf. Stunden</div><div class="font-bold text-brand">${cp.recommended_hours||'?'}h</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Typ</div><div class="font-semibold text-sm">${cp.cleaning_type||'-'}</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Frequenz</div><div class="font-semibold text-sm">${cp.frequency||'-'}</div></div>
       <div class="p-3 bg-gray-50 rounded col-span-1 md:col-span-1"><div class="text-xs text-gray-500">Add-Ons</div><div class="font-semibold text-sm">${(cp.recommended_addons||[]).length}</div></div>
     </div>
     <div class="mb-3"><div class="text-xs text-gray-500 font-semibold mb-1">Hot-Spots:</div>${spots||'-'}</div>
     <div class="mb-3"><div class="text-xs text-gray-500 font-semibold mb-1">Empfohlene Add-Ons:</div>${addons||'-'}</div>
     <div><div class="text-xs text-gray-500 font-semibold mb-1">Spezial-Aufgaben:</div>${list(cp.special_tasks)}</div>`);

  // Business Case
  html += card(`💼 Business-Case`,
    `<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3 text-center">
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Fleckfrei/Turnover</div><div class="font-bold">${bc.fleckfrei_cost_per_turnover_eur||'?'}€</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Fleckfrei/Monat est.</div><div class="font-bold">${bc.fleckfrei_cost_per_month_estimate_eur||'?'}€</div></div>
       <div class="p-3 bg-amber-50 rounded"><div class="text-xs text-gray-500">Break-even (Buchungen/Monat)</div><div class="font-bold">${bc.break_even_bookings_per_month||'?'}</div></div>
       <div class="p-3 bg-emerald-50 rounded"><div class="text-xs text-gray-500">12-Mon. Netto-Gewinn</div><div class="font-bold text-emerald-700">+${bc.12_month_net_gain_eur||'?'}€</div></div>
     </div>
     <p class="text-sm text-gray-700">${bc.summary_de||''}</p>`);

  // Action Plan
  html += card(`📋 Action-Plan`,
    `<div class="space-y-3">
       <div class="p-3 bg-red-50 border-l-4 border-red-500 rounded"><div class="text-xs text-red-700 font-bold mb-1">🔥 SOFORT</div>${list(ap.immediate)}</div>
       <div class="p-3 bg-amber-50 border-l-4 border-amber-500 rounded"><div class="text-xs text-amber-700 font-bold mb-1">30 TAGE</div>${list(ap.within_30_days)}</div>
       <div class="p-3 bg-blue-50 border-l-4 border-blue-500 rounded"><div class="text-xs text-blue-700 font-bold mb-1">90 TAGE</div>${list(ap.within_90_days)}</div>
       <div class="p-3 bg-emerald-50 border-l-4 border-emerald-500 rounded"><div class="text-xs text-emerald-700 font-bold mb-1">📈 KPIs tracken</div>${list(ap.kpis_to_track)}</div>
     </div>`);

  result.innerHTML = html;
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
  status.innerHTML = '⏳ <strong>Generiere Business-Dossier</strong> — Marktdaten, Review-Forensik, ROI-Kalkulation... (~30s)';
  result.classList.add('hidden');
  leadSection.classList.add('hidden');
  try {
    const r = await fetch('/api/airbnb-check-public.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Fehler');
    renderDossier(d);
    status.textContent = '✓ Dossier generiert' + (d.scrape_mode === 'blocked' ? ' (URL geblockt — im Text-Modus wird die Analyse präziser)' : '');
    if (window.plausible) plausible('airbnb-check-complete');
  } catch (err) {
    status.textContent = '❌ ' + err.message;
  }
});

document.getElementById('leadFormInner').addEventListener('submit', async (e) => {
  e.preventDefault();
  const data = new FormData(e.target);
  const leadStatus = document.getElementById('leadStatus');
  leadStatus.textContent = '⏳ Sende...';
  try {
    const r = await fetch('/api/airbnb-lead.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({name:data.get('name'), email:data.get('email'), analysis:lastAnalysis})});
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Fehler');
    leadStatus.innerHTML = '✅ Danke! Wir melden uns in 24h.';
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
