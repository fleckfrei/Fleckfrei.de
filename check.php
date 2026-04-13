<?php
/**
 * PUBLIC Landing v3: LEAD-FIRST flow — single form, report goes to inbox + display.
 */
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Kostenlose Umsatz-Analyse für Vermieter · Fleckfrei</title>
<meta name="description" content="Revenue-Report für Ihre Ferien-/STR-Wohnung — AI-Analyse mit Marktvergleich & ROI. Erhalten Sie den Report in 30 Sekunden per Email."/>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"/>
<script>
tailwind.config = { theme: { extend: { colors: { brand: '#2E7D6B', 'brand-dark': '#1e5a4c' }, fontFamily: { sans: ['Inter','sans-serif'] } } } }
</script>
<style>
body{font-family:'Inter',sans-serif}
.gradient-header{background:linear-gradient(135deg,#2E7D6B 0%,#1e5a4c 100%)}
.pulse-cta{animation:pulse-brand 2s ease-in-out infinite}
@keyframes pulse-brand{0%,100%{box-shadow:0 0 0 0 rgba(46,125,107,.7)}50%{box-shadow:0 0 0 14px rgba(46,125,107,0)}}
</style>
</head>
<body class="bg-gray-50">

<nav class="bg-white border-b sticky top-0 z-10">
  <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
    <a href="https://fleckfrei.de" class="font-bold text-xl text-brand">Fleckfrei</a>
    <a href="/login.php" class="text-sm text-gray-600 hover:text-brand">Login</a>
  </div>
</nav>

<section class="gradient-header text-white py-14 px-4">
  <div class="max-w-4xl mx-auto text-center">
    <div class="inline-block text-xs px-3 py-1 bg-white/20 rounded-full font-semibold mb-4">💸 Kostenlose Umsatz-Analyse · Report per Email</div>
    <h1 class="text-3xl md:text-5xl font-bold mb-4 leading-tight">Wie viel Umsatz verlieren Sie gerade durch schlechte Reinigung?</h1>
    <p class="text-lg text-white/90 max-w-2xl mx-auto">AI-Analyse für Vermieter von Ferien-/Kurzzeit-Wohnungen. Konkrete Zahlen, Markt-Benchmark, ROI. Report kommt direkt in Ihr Postfach.</p>
  </div>
</section>

<section class="bg-white border-b">
  <div class="max-w-5xl mx-auto px-4 py-4 grid grid-cols-3 gap-4 text-center text-xs md:text-sm">
    <div><div class="font-bold text-brand text-lg md:text-2xl">99%</div><div class="text-gray-600">Turnover-Quote</div></div>
    <div><div class="font-bold text-brand text-lg md:text-2xl">&lt;60s</div><div class="text-gray-600">Report per Email</div></div>
    <div><div class="font-bold text-brand text-lg md:text-2xl">100%</div><div class="text-gray-600">kostenlos</div></div>
  </div>
</section>

<section class="max-w-3xl mx-auto px-4 py-10">
  <div class="bg-white rounded-2xl shadow-xl border p-6 mb-8">
    <h2 class="font-bold text-xl mb-4">Holen Sie sich Ihren persönlichen Revenue-Report</h2>
    <form id="checkForm" class="space-y-4">
      <!-- Listing: URL oder Text -->
      <div>
        <label class="block text-sm font-semibold mb-1">📍 Link oder Beschreibung Ihrer Wohnung *</label>
        <input type="url" id="fUrl" placeholder="Link zur Inserate-Seite einfügen..." class="w-full px-4 py-3 border rounded-lg focus:border-brand focus:ring-2 focus:ring-brand/20"/>
        <details class="mt-2"><summary class="text-xs text-gray-500 cursor-pointer hover:text-brand">Kein Link? Beschreibung einfügen ↓</summary>
          <textarea id="fText" rows="6" placeholder="Beschreibung + Gästebewertungen hier reinkopieren..." class="mt-2 w-full px-4 py-3 border rounded-lg focus:border-brand focus:ring-2 focus:ring-brand/20 font-mono text-sm"></textarea>
        </details>
      </div>

      <!-- Kontakt -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2 border-t">
        <div>
          <label class="block text-sm font-semibold mb-1">Ihr Name</label>
          <input name="name" class="w-full px-4 py-3 border rounded-lg"/>
        </div>
        <div>
          <label class="block text-sm font-semibold mb-1">Ihre Email *</label>
          <input name="email" type="email" required class="w-full px-4 py-3 border rounded-lg"/>
        </div>
      </div>
      <div>
        <label class="block text-sm font-semibold mb-1">Handy <span class="text-xs text-gray-500 font-normal">(optional, für schnellere Rückmeldung)</span></label>
        <input name="phone" class="w-full px-4 py-3 border rounded-lg"/>
      </div>

      <!-- Consent -->
      <div class="bg-gray-50 rounded-lg p-4 space-y-2 text-left">
        <label class="flex items-start gap-2 text-xs cursor-pointer">
          <input type="checkbox" name="consent_contact" required checked class="mt-0.5 shrink-0 rounded"/>
          <span>Fleckfrei darf mich zur Angebotserstellung per Email/Telefon kontaktieren.<span class="text-red-500">*</span></span>
        </label>
        <label class="flex items-start gap-2 text-xs cursor-pointer">
          <input type="checkbox" name="consent_privacy" required checked class="mt-0.5 shrink-0 rounded"/>
          <span>Ich akzeptiere die <a href="https://fleckfrei.de/datenschutz.html" target="_blank" class="text-brand underline">Datenschutzerklärung</a>.<span class="text-red-500">*</span></span>
        </label>
        <label class="flex items-start gap-2 text-xs cursor-pointer">
          <input type="checkbox" name="consent_marketing" checked class="mt-0.5 shrink-0 rounded"/>
          <span>Fleckfrei darf mir gelegentlich Angebote & Tipps per Email senden. Jederzeit widerrufbar.</span>
        </label>
      </div>

      <button type="submit" id="submitBtn" class="w-full py-5 bg-brand text-white rounded-xl font-black text-lg hover:bg-brand-dark transition pulse-cta shadow-lg">📊 REPORT ZUSENDEN →</button>
      <p class="text-xs text-gray-500 text-center">Report im Browser + per Email · keine Registrierung · DSGVO-konform</p>
    </form>

    <div id="status" class="hidden mt-4"></div>
  </div>

  <div id="result" class="hidden space-y-6 mb-8"></div>

  <!-- Trust -->
  <div class="bg-white rounded-2xl border p-6 mb-8">
    <h3 class="font-bold text-lg mb-4 text-center">🏆 Warum Fleckfrei</h3>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="p-4 border rounded-lg"><div class="text-brand font-bold mb-1">⚡ Blitz-Turnover</div><div class="text-sm text-gray-600">Zwischen Check-out und Check-in. Auch am selben Tag. 99% Quote.</div></div>
      <div class="p-4 border rounded-lg"><div class="text-brand font-bold mb-1">📸 Foto-Dokumentation</div><div class="text-sm text-gray-600">Vorher/Nachher pro Reinigung. Schaden-Nachweis inklusive.</div></div>
      <div class="p-4 border rounded-lg"><div class="text-brand font-bold mb-1">💰 Festpreis-Garantie</div><div class="text-sm text-gray-600">Fixpreis pro Turnover. Staffelpreise ab 3 Wohnungen.</div></div>
    </div>
  </div>

  <!-- DSGVO Info -->
  <div class="bg-white rounded-xl border p-5 text-xs text-gray-600 space-y-2">
    <h4 class="font-bold text-sm text-gray-800">🔒 Deine Daten</h4>
    <p>Wir speichern Name, Email, Handy und die Analyse zur Angebotserstellung (Art. 6(1)(b) DSGVO). Keine Weitergabe an Dritte. Auskunft/Löschung: <a href="mailto:info@fleckfrei.de" class="underline">info@fleckfrei.de</a>.</p>
    <p>Diese Seite nutzt <strong>Plausible Analytics</strong> — cookielos, IP-anonym, DSGVO-konform (<a href="https://plausible.io/data-policy" target="_blank" class="underline">Datenpolicy</a>).</p>
  </div>
</section>

<footer class="text-center py-8 text-xs text-gray-500 border-t">
  © <?= date('Y') ?> Fleckfrei · <a href="https://fleckfrei.de" class="underline">fleckfrei.de</a> · <a href="https://fleckfrei.de/impressum.html" class="underline">Impressum</a> · <a href="https://fleckfrei.de/datenschutz.html" class="underline">Datenschutz</a> · <a href="https://fleckfrei.de/agb.html" class="underline">AGB</a>
</footer>

<script>
const form = document.getElementById('checkForm');
const status = document.getElementById('status');
const result = document.getElementById('result');
const btn = document.getElementById('submitBtn');

function card(title, content) { return `<div class="bg-white rounded-2xl shadow-sm border p-6"><h3 class="font-bold text-lg mb-4">${title}</h3>${content}</div>`; }
function list(items) { return items && items.length ? `<ul class="list-disc list-inside text-sm space-y-1">${items.map(i=>`<li>${i}</li>`).join('')}</ul>` : '-'; }
function tag(t,c='gray'){const m={red:'bg-red-100 text-red-700',amber:'bg-amber-100 text-amber-700',green:'bg-emerald-100 text-emerald-700',brand:'bg-brand/10 text-brand',gray:'bg-gray-100 text-gray-700'};return `<span class="text-xs px-2 py-0.5 rounded ${m[c]||m.gray} mr-1 mb-1 inline-block">${t}</span>`;}

function renderDossier(data) {
  const d = data.dossier?.dossier || data.dossier || {};
  if (d.parse_error) { result.innerHTML = card('⚠️','AI-Parse-Fehler. Report wurde per Email gesendet.'); result.classList.remove('hidden'); return; }
  const la=d.listing_audit||{}, mp=d.market_position||{}, rf=d.review_forensics||{}, ri=d.revenue_impact||{}, sw=d.swot||{}, bc=d.business_case||{}, ap=d.action_plan||{};
  const market = data.dossier?.market || {};
  const risk = parseInt(d.risk_score)||5;

  let html = '';
  // Email confirmation on top
  html += `<div class="bg-emerald-50 border-2 border-emerald-400 rounded-2xl p-5 text-emerald-900"><div class="flex items-start gap-3"><div class="text-3xl">✅</div><div><div class="font-bold text-lg">Report ist unterwegs!</div><div class="text-sm">Wir haben Ihnen den kompletten Report auch per Email geschickt${data.email_sent===false?' (falls Email nicht ankommt: Spam-Ordner prüfen)':''}. Unten sehen Sie die Vorschau.</div></div></div></div>`;

  // ROI Hero
  html += `<div class="bg-gradient-to-br from-red-50 to-amber-50 border-2 border-red-200 rounded-2xl shadow-lg p-8 text-center"><div class="text-sm font-semibold text-red-700 mb-2">💸 Ihr geschätzter jährlicher Verlust:</div><div class="text-6xl font-black text-red-600 mb-2">${ri.lost_revenue_eur_per_year||'?'}€</div><div class="text-sm text-gray-600">ROI Fleckfrei: ${ri.fleckfrei_roi_ratio||'?'}</div></div>`;

  // Summary
  html += card('🎯 Executive Summary', `<p class="mb-4">${d.summary_de||'-'}</p><div class="p-3 rounded-lg ${risk>=7?'bg-red-50':risk>=4?'bg-amber-50':'bg-emerald-50'} border"><strong>Risk: ${risk}/10</strong> — ${risk>=7?'⚠️ Kritisch':risk>=4?'⚡ Handlung nötig':'✓ Stabil'}</div>`);

  // Listing
  html += card('🏠 Ihre Wohnung', `<div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center"><div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Typ</div><div class="font-semibold">${la.apartment_type||'-'}</div></div><div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">qm</div><div class="font-semibold">${la.estimated_sqm||'?'}</div></div><div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Gäste</div><div class="font-semibold">${la.guests||'?'}</div></div><div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">€/Nacht est.</div><div class="font-semibold">${la.estimated_price_per_night_eur||'?'}€</div></div></div>`);

  // Market
  if (market.avg_rating) {
    html += card('📊 Berlin-Markt', `<div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center"><div class="p-3 bg-brand/10 rounded"><div class="text-xs text-gray-500">Ø Rating</div><div class="font-bold text-brand">${market.avg_rating}/5</div></div><div class="p-3 bg-brand/10 rounded"><div class="text-xs text-gray-500">Ø Preis/N</div><div class="font-bold text-brand">€${market.avg_price_eur||'?'}</div></div><div class="p-3 bg-brand/10 rounded"><div class="text-xs text-gray-500">Median Reviews</div><div class="font-bold text-brand">${market.median_reviews||'?'}</div></div><div class="p-3 bg-amber-50 rounded"><div class="text-xs text-gray-500">Top-20% braucht</div><div class="font-bold text-amber-700">${mp.rating_benchmark_needed||'?'}</div></div></div>`);
  }

  // Review Forensik
  html += card('🔍 Review-Forensik', `<div class="p-3 bg-red-50 border border-red-200 rounded mb-3"><div class="text-xs text-red-700 font-semibold">⚠️ SOFORT FIXEN:</div><div class="font-medium">${rf.top_priority_fix||'-'}</div></div><div class="text-sm">${list(rf.identified_complaints)}</div>`);

  // Business Case
  html += card('💼 Business-Case', `<div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center mb-3"><div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">pro Turnover</div><div class="font-bold">${bc.fleckfrei_cost_per_turnover_eur||'?'}€</div></div><div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">pro Monat</div><div class="font-bold">${bc.fleckfrei_cost_per_month_estimate_eur||'?'}€</div></div><div class="p-3 bg-amber-50 rounded"><div class="text-xs text-gray-500">Break-even</div><div class="font-bold">${bc.break_even_bookings_per_month||'?'}/Mo</div></div><div class="p-3 bg-emerald-50 rounded"><div class="text-xs text-gray-500">12-Mon Netto</div><div class="font-bold text-emerald-700">+${bc['12_month_net_gain_eur']||'?'}€</div></div></div><p class="text-sm">${bc.summary_de||''}</p>`);

  // Final CTA
  html += `<div class="bg-gradient-to-br from-brand to-brand-dark text-white rounded-2xl shadow-xl p-8 text-center"><div class="text-sm font-bold opacity-80 mb-2">🚀 NÄCHSTER SCHRITT</div><h3 class="text-2xl md:text-3xl font-black mb-3">Wir rufen Sie binnen 24h an</h3><p class="opacity-90 mb-5">Sie haben den Report und wir melden uns mit einem individuellen Angebot. Kein Druck. Keine Abo-Falle.</p><a href="mailto:info@fleckfrei.de?subject=Angebot%20anfordern" class="inline-block px-8 py-4 bg-white text-brand rounded-xl font-bold text-lg hover:bg-gray-100 shadow-xl">💬 Jetzt Angebot anfordern →</a></div>`;

  result.innerHTML = html;
  result.classList.remove('hidden');
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const data = new FormData(form);
  const payload = {
    url: document.getElementById('fUrl').value.trim(),
    text: document.getElementById('fText').value.trim(),
    name: data.get('name'),
    email: data.get('email'),
    phone: data.get('phone'),
    consent_contact: !!data.get('consent_contact'),
    consent_privacy: !!data.get('consent_privacy'),
    consent_marketing: !!data.get('consent_marketing'),
  };
  if (!payload.url && !payload.text) { status.innerHTML = '<div class="text-red-600 text-sm">❌ Bitte Link oder Beschreibung eingeben</div>'; status.classList.remove('hidden'); return; }
  if (!payload.email) return;
  btn.disabled = true; btn.innerHTML = '⏳ Analysiere… Report wird erstellt (~30s)';
  status.innerHTML = '<div class="text-sm text-gray-600">🧠 AI analysiert · Berlin-Marktvergleich · ROI-Kalkulation · Email wird gesendet…</div>';
  status.classList.remove('hidden');
  result.classList.add('hidden');
  try {
    const r = await fetch('/api/check-submit.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Fehler');
    renderDossier(d);
    status.innerHTML = `<div class="text-emerald-700 text-sm">✅ Report ${d.email_sent?'per Email gesendet':'(Email-Versand fehlgeschlagen, aber unten sehen Sie alles)'}</div>`;
    btn.innerHTML = '✓ Report erhalten';
    btn.disabled = true;
    if (window.plausible) plausible('check-lead');
    window.scrollTo({top: result.offsetTop - 80, behavior:'smooth'});
  } catch (err) {
    status.innerHTML = '<div class="text-red-600 text-sm">❌ '+err.message+'</div>';
    btn.disabled = false; btn.innerHTML = '📊 REPORT ZUSENDEN →';
  }
});

if (window.plausible) plausible('check-view');
</script>
<script defer data-domain="app.fleckfrei.de" src="https://plausible.io/js/script.js"></script>
</body>
</html>
