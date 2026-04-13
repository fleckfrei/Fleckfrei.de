<?php
/**
 * PUBLIC Landing: Fleckfrei Host-Check (Rebrand — kein Plattform-Branding)
 * Sales-Funnel: Teaser-Analyse → Lead-Capture → Full-Dossier nur nach Kontakt
 */
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Kostenlose Umsatz-Analyse für Vermieter · Fleckfrei</title>
<meta name="description" content="Wie viel Umsatz verlierst du durch schlechte Reinigung? AI-Analyse deiner Ferien-/STR-Wohnung in 30 Sekunden. Kostenlos. Konkrete Zahlen."/>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"/>
<script>
tailwind.config = { theme: { extend: { colors: { brand: '#2E7D6B', 'brand-dark': '#1e5a4c', danger: '#dc2626' }, fontFamily: { sans: ['Inter','sans-serif'] } } } }
</script>
<style>
body{font-family:'Inter',sans-serif}
.gradient-header{background:linear-gradient(135deg,#2E7D6B 0%,#1e5a4c 100%)}
.pulse-cta{animation:pulse-brand 2s ease-in-out infinite}
@keyframes pulse-brand{0%,100%{box-shadow:0 0 0 0 rgba(46,125,107,.7)}50%{box-shadow:0 0 0 12px rgba(46,125,107,0)}}
</style>
</head>
<body class="bg-gray-50">

<nav class="bg-white border-b sticky top-0 z-10">
  <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
    <a href="https://fleckfrei.de" class="font-bold text-xl text-brand">Fleckfrei</a>
    <a href="/login.php" class="text-sm text-gray-600 hover:text-brand">Login</a>
  </div>
</nav>

<!-- HERO — Sales-driven, no platform branding -->
<section class="gradient-header text-white py-16 px-4">
  <div class="max-w-4xl mx-auto text-center">
    <div class="inline-block text-xs px-3 py-1 bg-white/20 rounded-full font-semibold mb-4">💸 Kostenlose Umsatz-Analyse · 30 Sekunden</div>
    <h1 class="text-3xl md:text-5xl font-bold mb-4 leading-tight">Wie viel Umsatz<br class="md:hidden"> verlierst du gerade<br class="md:hidden"> durch schlechte Reinigung?</h1>
    <p class="text-lg text-white/90 max-w-2xl mx-auto mb-2">AI-gestützte Analyse für Vermieter von Ferien-/Kurzzeit-Wohnungen.</p>
    <p class="text-sm text-white/75 max-w-2xl mx-auto">Konkrete Zahlen · Marktvergleich · ROI-Rechnung. Keine Registrierung.</p>
  </div>
</section>

<!-- Trust-Bar -->
<section class="bg-white border-b">
  <div class="max-w-5xl mx-auto px-4 py-4 grid grid-cols-3 gap-4 text-center text-xs md:text-sm">
    <div><div class="font-bold text-brand text-lg md:text-2xl">99%</div><div class="text-gray-600">Turnover-Quote</div></div>
    <div><div class="font-bold text-brand text-lg md:text-2xl">&lt;30s</div><div class="text-gray-600">bis zum Ergebnis</div></div>
    <div><div class="font-bold text-brand text-lg md:text-2xl">100%</div><div class="text-gray-600">kostenlos</div></div>
  </div>
</section>

<!-- INPUT -->
<section class="max-w-4xl mx-auto px-4 py-8">
  <div class="bg-white rounded-2xl shadow-xl border p-6 mb-8">
    <div class="flex gap-2 mb-4">
      <button type="button" id="tabUrl"  class="px-4 py-2 rounded-lg text-sm font-semibold bg-brand text-white">🔗 Link deiner Wohnung</button>
      <button type="button" id="tabText" class="px-4 py-2 rounded-lg text-sm font-semibold bg-gray-100 text-gray-700">📝 Text einfügen</button>
    </div>
    <form id="aaForm">
      <div id="modeUrl" class="flex flex-col sm:flex-row gap-2">
        <input type="url" id="aaUrl" placeholder="Link deiner Inserate-Seite" class="flex-1 px-4 py-3 border rounded-lg focus:border-brand focus:ring-2 focus:ring-brand/20"/>
        <button type="submit" class="px-6 py-3 bg-brand text-white rounded-lg font-bold hover:bg-brand-dark transition whitespace-nowrap pulse-cta">🔍 Gratis Analyse →</button>
      </div>
      <div id="modeText" class="hidden">
        <textarea id="aaText" rows="10" placeholder="Beschreibung + Gästebewertungen hier reinkopieren (je mehr Text, desto präziser)..." class="w-full px-4 py-3 border rounded-lg focus:border-brand focus:ring-2 focus:ring-brand/20 font-mono text-sm"></textarea>
        <button type="submit" class="mt-2 px-6 py-3 bg-brand text-white rounded-lg font-bold hover:bg-brand-dark transition pulse-cta">🔍 Gratis Analyse →</button>
      </div>
    </form>
    <p class="text-xs text-gray-500 mt-3">💡 Manche Portale blocken automatische Abfragen. Wenn Link nicht geht → Text-Modus: Seite öffnen, Cmd+A, Cmd+C, hier einfügen.</p>
    <div id="aaStatus" class="text-sm mt-3 hidden"></div>
  </div>

  <div id="aaResult" class="hidden space-y-6 pb-6"></div>

  <!-- PRIMARY CTA — Lead Capture (appears after analysis) -->
  <div id="leadForm" class="hidden bg-gradient-to-br from-brand to-brand-dark text-white rounded-2xl shadow-2xl p-8 mb-8">
    <div class="text-center mb-5">
      <div class="text-xs font-bold bg-white/20 inline-block px-3 py-1 rounded-full mb-3">🎯 NÄCHSTER SCHRITT</div>
      <h3 class="font-bold text-3xl mb-2">Bereit, diesen Verlust zu stoppen?</h3>
      <p class="text-white/90 max-w-xl mx-auto">Wir erstellen dir einen maßgeschneiderten Reinigungsplan mit Festpreis. Kostenlos & unverbindlich. Erste Reinigung in 48h möglich.</p>
    </div>
    <form id="leadFormInner" class="max-w-lg mx-auto space-y-3">
      <input name="name" placeholder="Name" class="w-full px-4 py-3 rounded-lg text-gray-900"/>
      <input name="email" type="email" required placeholder="Email-Adresse *" class="w-full px-4 py-3 rounded-lg text-gray-900"/>
      <input name="phone" placeholder="Handy (optional — schnellere Rückmeldung)" class="w-full px-4 py-3 rounded-lg text-gray-900"/>

      <!-- DSGVO Consent Checkboxes -->
      <div class="bg-white/10 rounded-lg p-3 space-y-2 text-left">
        <label class="flex items-start gap-2 text-xs cursor-pointer">
          <input type="checkbox" name="consent_contact" required class="mt-0.5 shrink-0 rounded"/>
          <span>Ich bin einverstanden, dass Fleckfrei mich zur Angebotserstellung per <strong>Email / Telefon</strong> kontaktiert.*</span>
        </label>
        <label class="flex items-start gap-2 text-xs cursor-pointer">
          <input type="checkbox" name="consent_privacy" required class="mt-0.5 shrink-0 rounded"/>
          <span>Ich habe die <a href="https://fleckfrei.de/datenschutz.html" target="_blank" class="underline">Datenschutzerklärung</a> gelesen und akzeptiere sie.*</span>
        </label>
        <label class="flex items-start gap-2 text-xs cursor-pointer">
          <input type="checkbox" name="consent_marketing" class="mt-0.5 shrink-0 rounded"/>
          <span>Ich bin einverstanden, dass Fleckfrei mir gelegentlich Angebote, News oder Tipps per Email sendet (kann jederzeit widerrufen werden). <span class="text-white/60">optional</span></span>
        </label>
      </div>

      <button class="w-full py-4 bg-white text-brand rounded-lg font-bold text-lg hover:bg-gray-100 transition">💬 Mein kostenloses Angebot anfordern →</button>
      <p class="text-xs text-white/70 text-center">* Pflichtfeld · Rückmeldung binnen 24h · Widerruf jederzeit möglich</p>
    </form>
    <div id="leadStatus" class="text-sm mt-4 text-center"></div>
  </div>

  <!-- Secondary Social Proof -->
  <div class="bg-white rounded-2xl border p-6 mb-8">
    <h3 class="font-bold text-lg mb-4 text-center">🏆 Warum Hosts Fleckfrei wählen</h3>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="p-4 border rounded-lg">
        <div class="text-brand font-bold mb-1">⚡ Blitz-Turnover</div>
        <div class="text-sm text-gray-600">Zwischen Check-out und Check-in reinigen — auch am selben Tag. 99% Buchungsquote.</div>
      </div>
      <div class="p-4 border rounded-lg">
        <div class="text-brand font-bold mb-1">📸 Foto-Dokumentation</div>
        <div class="text-sm text-gray-600">Jede Reinigung mit Vorher/Nachher-Fotos. Kein Streit mit Gästen. Schaden-Nachweis inklusive.</div>
      </div>
      <div class="p-4 border rounded-lg">
        <div class="text-brand font-bold mb-1">💰 Festpreis-Garantie</div>
        <div class="text-sm text-gray-600">Kein Stundensatz-Chaos. Fixer Preis pro Turnover. Staffelpreise ab 3 Wohnungen.</div>
      </div>
    </div>
  </div>
</section>

<!-- DSGVO Info Section -->
<section class="max-w-4xl mx-auto px-4 py-6">
  <div class="bg-white rounded-xl border p-5 text-xs text-gray-600 space-y-2">
    <h4 class="font-bold text-sm text-gray-800">🔒 Deine Daten bei uns</h4>
    <p>Bei Einsendung des Formulars speichern wir <strong>Name, Email und (optional) Handynummer</strong> sowie die Ergebnisse der Analyse ausschließlich zum Zweck der Angebotserstellung. Rechtsgrundlage: Art. 6(1)(b) DSGVO (vorvertragliche Maßnahmen).</p>
    <p>Wir geben deine Daten <strong>nicht an Dritte</strong> weiter. Du kannst jederzeit Auskunft, Löschung oder Widerruf verlangen: <a href="mailto:info@fleckfrei.de" class="underline">info@fleckfrei.de</a>.</p>
    <p>Diese Seite nutzt <strong>Plausible Analytics</strong> — ein cookieloses, DSGVO-konformes Analyse-Tool. Es werden <strong>keine persönlichen Daten, keine IPs, keine Cookies</strong> gespeichert (<a href="https://plausible.io/data-policy" target="_blank" class="underline">Datenpolicy Plausible</a>).</p>
    <p>Die URL-/Text-Analyse läuft über unseren Server + Groq (EU-Hosted, US-Firma). Inhalte werden <strong>max. 24h</strong> gecacht, dann gelöscht.</p>
  </div>
</section>

<footer class="text-center py-8 text-xs text-gray-500 border-t">
  © <?= date('Y') ?> Fleckfrei · <a href="https://fleckfrei.de" class="underline">fleckfrei.de</a> · <a href="https://fleckfrei.de/impressum.html" class="underline">Impressum</a> · <a href="https://fleckfrei.de/datenschutz.html" class="underline">Datenschutz</a> · <a href="https://fleckfrei.de/agb.html" class="underline">AGB</a>
</footer>

<script>
const form = document.getElementById('aaForm');
const status = document.getElementById('aaStatus');
const result = document.getElementById('aaResult');
const leadSection = document.getElementById('leadForm');
let mode = 'url', lastAnalysis = null;

function card(title, content, bg='bg-white') {
  return `<div class="${bg} rounded-2xl shadow-sm border p-6"><h3 class="font-bold text-lg mb-4 flex items-center gap-2">${title}</h3>${content}</div>`;
}
function tag(text, color='gray') {
  const colors = {green:'bg-emerald-100 text-emerald-700',red:'bg-red-100 text-red-700',amber:'bg-amber-100 text-amber-700',blue:'bg-blue-100 text-blue-700',gray:'bg-gray-100 text-gray-700',brand:'bg-brand/10 text-brand'};
  return `<span class="inline-block text-xs px-2 py-0.5 rounded ${colors[color]||colors.gray} mr-1 mb-1">${text}</span>`;
}
function list(items) {
  return items && items.length ? `<ul class="list-disc list-inside text-sm space-y-1">${items.map(i=>`<li>${i}</li>`).join('')}</ul>` : '<span class="text-xs text-gray-400">-</span>';
}

function renderDossier(data) {
  lastAnalysis = data;
  const d = data.dossier || {};
  if (d.parse_error) {
    result.innerHTML = card('⚠️', `<pre class="text-xs bg-gray-50 p-3 rounded whitespace-pre-wrap">${(d.raw||'').replace(/</g,'&lt;')}</pre>`);
    result.classList.remove('hidden'); return;
  }

  // Blocked-platform warning
  let warn = '';
  if (data.scrape_mode === 'blocked' || data.reviews_captured === 0 && data.url) {
    warn = `<div class="bg-amber-50 border-2 border-amber-400 rounded-2xl p-5">
      <div class="flex items-start gap-3">
        <div class="text-3xl">⚠️</div>
        <div class="flex-1">
          <h3 class="font-bold text-amber-900 mb-1">Link konnte nicht vollständig ausgelesen werden</h3>
          <p class="text-sm text-amber-800 mb-3">Dein Inserate-Portal blockt automatische Abfragen. Die Analyse unten basiert nur auf dem URL-Pfad. <strong>Wechsle zum Text-Modus für ein präzises Dossier.</strong></p>
          <button onclick="switchToTextMode()" class="px-4 py-2 bg-amber-600 text-white rounded-lg font-semibold text-sm hover:bg-amber-700">📝 Text-Modus öffnen →</button>
        </div>
      </div>
    </div>`;
  }

  const la = d.listing_audit || {}, mp = d.market_position || {}, rf = d.review_forensics || {};
  const ri = d.revenue_impact || {}, sw = d.swot || {}, cp = d.cleaning_plan || {};
  const bc = d.business_case || {}, ap = d.action_plan || {};
  const market = data.market || {};
  const risk = parseInt(d.risk_score) || 5;
  const riskColor = risk >= 7 ? 'red' : (risk >= 4 ? 'amber' : 'green');
  const riskBg = risk >= 7 ? 'bg-red-50 border-red-200' : (risk >= 4 ? 'bg-amber-50 border-amber-200' : 'bg-emerald-50 border-emerald-200');

  let html = '';

  // HEADLINE ROI HERO — biggest number first (sales-driven)
  html += `<div class="bg-gradient-to-br from-red-50 to-amber-50 border-2 border-red-200 rounded-2xl shadow-lg p-8 text-center">
    <div class="text-sm font-semibold text-red-700 mb-2">💸 Dein geschätzter jährlicher Verlust durch Reinigungs-Issues:</div>
    <div class="text-6xl font-black text-red-600 mb-2">${ri.lost_revenue_eur_per_year||'?'}€</div>
    <div class="text-sm text-gray-600">≙ ca. ${ri.lost_bookings_per_year_estimate||'?'} verlorene Buchungen/Jahr · ROI ${ri.fleckfrei_roi_ratio||'?'}</div>
  </div>`;

  // Executive Summary
  html += card(`🎯 Executive Summary`,
    `<p class="text-gray-700 mb-4">${d.summary_de || '-'}</p>
     <div class="flex items-center gap-3 p-3 rounded-lg ${riskBg} border">
       <div class="text-3xl font-bold">${risk}/10</div>
       <div><div class="text-xs text-gray-500">Risk-Score</div><div class="text-sm font-semibold">${risk>=7?'⚠️ Kritisch — sofortiges Handeln nötig':risk>=4?'⚡ Handlungsbedarf':'✓ Stabil'}</div></div>
     </div>`);

  // Mid-funnel CTA after first payoff
  html += `<div class="text-center py-4">
    <button onclick="scrollToLead()" class="px-8 py-4 bg-brand text-white rounded-xl font-bold text-lg hover:bg-brand-dark transition shadow-lg pulse-cta">💬 Angebot in 24h anfordern →</button>
  </div>`;

  // Listing Audit
  html += card(`🏠 Deine Wohnung im Audit`,
    `<div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center">
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Typ</div><div class="font-semibold text-sm">${la.apartment_type||'-'}</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">qm</div><div class="font-semibold text-sm">${la.estimated_sqm||'?'}</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Gäste</div><div class="font-semibold text-sm">${la.guests||'?'}</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">€/Nacht est.</div><div class="font-semibold text-sm">${la.estimated_price_per_night_eur||'?'}€</div></div>
     </div>`);

  // Market Position
  if (market && market.avg_rating) {
    html += card(`📊 Berlin-Marktvergleich`,
      `<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 text-center">
         <div class="p-3 bg-brand/10 rounded"><div class="text-xs text-gray-500">Ø-Rating Markt</div><div class="font-bold text-brand">${market.avg_rating}/5</div></div>
         <div class="p-3 bg-brand/10 rounded"><div class="text-xs text-gray-500">Ø Preis/Nacht</div><div class="font-bold text-brand">€${market.avg_price_eur||'?'}</div></div>
         <div class="p-3 bg-brand/10 rounded"><div class="text-xs text-gray-500">Median Reviews</div><div class="font-bold text-brand">${market.median_reviews||'?'}</div></div>
         <div class="p-3 bg-amber-50 rounded"><div class="text-xs text-gray-500">Top-20% braucht</div><div class="font-bold text-amber-700">${mp.rating_benchmark_needed||'?'}</div></div>
       </div>
       <p class="text-sm text-gray-700"><strong>Preis-Position:</strong> ${tag(mp.price_vs_market || '?', mp.price_vs_market && mp.price_vs_market.includes('over') ? 'amber' : 'green')} ${mp.price_comment_de||''}</p>`);
  }

  // Review-Forensik — der Schmerzpunkt
  html += card(`🔍 Was deine Gäste wirklich kritisieren`,
    `<div class="p-3 bg-red-50 border border-red-200 rounded mb-3">
       <div class="text-xs text-red-700 font-semibold mb-1">⚠️ SOFORT FIXEN:</div>
       <div class="text-sm font-medium">${rf.top_priority_fix||'-'}</div>
     </div>
     <div class="mb-3"><div class="text-xs text-gray-500 font-semibold mb-1">Konkrete Beschwerden:</div>${list(rf.identified_complaints)}</div>
     <div><div class="text-xs text-gray-500 font-semibold mb-1">Sauberkeits-Red-Flags:</div>${list(rf.cleanliness_red_flags)}</div>
     <div class="text-sm text-gray-600 mt-3">💡 Review-Verlust durch Sauberkeit: <strong class="text-red-600">${rf.estimated_review_impact_pct||'?'}</strong></div>`);

  // Revenue Impact — ROI Hero 2
  html += card(`💸 Revenue-Impact`,
    `<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
       <div class="p-4 bg-red-50 rounded text-center"><div class="text-xs text-gray-500">Verlorene Buchungen/J</div><div class="text-2xl font-bold text-red-600">${ri.lost_bookings_per_year_estimate||'?'}</div></div>
       <div class="p-4 bg-red-50 rounded text-center"><div class="text-xs text-gray-500">Verlorener Umsatz/J</div><div class="text-2xl font-bold text-red-600">${ri.lost_revenue_eur_per_year||'?'}€</div></div>
       <div class="p-4 bg-emerald-50 rounded text-center"><div class="text-xs text-gray-500">Fleckfrei-ROI</div><div class="text-2xl font-bold text-emerald-700">${ri.fleckfrei_roi_ratio||'-'}</div></div>
     </div>
     <p class="text-sm text-gray-600">${ri.reasoning_de||''}</p>`);

  // SWOT (smaller — less sales-critical)
  html += card(`🎯 Cleaning-SWOT`,
    `<div class="grid grid-cols-2 gap-3">
       <div class="p-3 bg-emerald-50 rounded"><div class="text-xs text-emerald-700 font-bold mb-1">✓ Stärken</div>${list(sw.strengths)}</div>
       <div class="p-3 bg-red-50 rounded"><div class="text-xs text-red-700 font-bold mb-1">✗ Schwächen</div>${list(sw.weaknesses)}</div>
       <div class="p-3 bg-blue-50 rounded"><div class="text-xs text-blue-700 font-bold mb-1">→ Chancen</div>${list(sw.opportunities)}</div>
       <div class="p-3 bg-amber-50 rounded"><div class="text-xs text-amber-700 font-bold mb-1">⚠ Bedrohungen</div>${list(sw.threats)}</div>
     </div>`);

  // Business Case — ROI zahlen groß
  html += card(`💼 Dein Business-Case mit Fleckfrei`,
    `<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3 text-center">
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Pro Turnover</div><div class="font-bold">${bc.fleckfrei_cost_per_turnover_eur||'?'}€</div></div>
       <div class="p-3 bg-gray-50 rounded"><div class="text-xs text-gray-500">Pro Monat ~</div><div class="font-bold">${bc.fleckfrei_cost_per_month_estimate_eur||'?'}€</div></div>
       <div class="p-3 bg-amber-50 rounded"><div class="text-xs text-gray-500">Break-even</div><div class="font-bold">${bc.break_even_bookings_per_month||'?'}/Mo</div></div>
       <div class="p-3 bg-emerald-50 rounded"><div class="text-xs text-gray-500">12-Mon Netto-Gewinn</div><div class="font-bold text-emerald-700">+${bc.12_month_net_gain_eur||'?'}€</div></div>
     </div>
     <p class="text-sm text-gray-700">${bc.summary_de||''}</p>`);

  // FINAL MEGA CTA
  html += `<div class="bg-gradient-to-br from-brand to-brand-dark text-white rounded-2xl shadow-2xl p-8 text-center">
    <div class="text-sm font-bold text-white/80 mb-2">🚀 BEREIT FÜR +${bc['12_month_net_gain_eur']||'?'}€ MEHR GEWINN?</div>
    <h3 class="text-2xl md:text-3xl font-black mb-3">Hol dir jetzt dein persönliches Angebot</h3>
    <p class="text-white/90 mb-5">Kostenlos · Unverbindlich · Rückmeldung binnen 24h</p>
    <button onclick="scrollToLead()" class="px-8 py-4 bg-white text-brand rounded-xl font-bold text-lg hover:bg-gray-100 transition shadow-xl">💬 Mein Angebot anfordern →</button>
  </div>`;

  result.innerHTML = (warn ? warn : '') + html;
  result.classList.remove('hidden');
  leadSection.classList.remove('hidden');
}

function switchToTextMode() {
  document.getElementById('tabText').click();
  window.scrollTo({top: 0, behavior: 'smooth'});
  setTimeout(() => document.getElementById('aaText').focus(), 400);
}
function scrollToLead() {
  leadSection.scrollIntoView({behavior:'smooth', block:'center'});
  setTimeout(()=>leadSection.querySelector('input[name="email"]').focus(), 600);
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
  status.innerHTML = '⏳ <strong>Berechne deinen Revenue-Impact</strong> — Marktdaten, Review-Forensik, ROI (~30s)';
  result.classList.add('hidden'); leadSection.classList.add('hidden');
  try {
    const r = await fetch('/api/airbnb-check-public.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Fehler');
    renderDossier(d);
    status.textContent = '✓ Analyse fertig';
    if (window.plausible) plausible('check-complete');
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
    const payload = {
      name: data.get('name'),
      email: data.get('email'),
      phone: data.get('phone'),
      consent_contact: !!data.get('consent_contact'),
      consent_privacy: !!data.get('consent_privacy'),
      consent_marketing: !!data.get('consent_marketing'),
      analysis: lastAnalysis
    };
    const r = await fetch('/api/airbnb-lead.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const d = await r.json();
    if (!d.success) throw new Error(d.error || 'Fehler');
    leadStatus.innerHTML = '<div class="text-2xl mb-1">✅</div><div class="font-bold">Danke! Wir melden uns binnen 24h.</div>';
    e.target.style.display = 'none';
    if (window.plausible) plausible('check-lead');
  } catch (err) {
    leadStatus.textContent = '❌ ' + err.message;
  }
});

if (window.plausible) plausible('check-view');
</script>
<script defer data-domain="app.fleckfrei.de" src="https://plausible.io/js/script.js"></script>
</body>
</html>
