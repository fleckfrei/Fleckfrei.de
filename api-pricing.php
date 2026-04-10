<?php
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>OSINT API — Pricing | <?= SITE ?></title>
  <meta name="description" content="Fleckfrei OSINT API — Email Verification, Phone Lookup, Social Media Scan, Dark Web Search. Ab 0 EUR/Monat."/>
  <link rel="icon" href="https://fleckfrei.de/img/logo/favicon.svg"/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700;800&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet"/>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    :root{--brand:#2E7D6B;--brand-dark:#245f54;--brand-light:#e8f5f1;--text:#1e2121;--text-sec:#5a6060;--text-muted:#8e9696;--border:#e8eaea;--radius:8px;--radius-lg:16px;--radius-pill:9999px;}
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Nunito Sans',system-ui,sans-serif;color:var(--text);background:#fff;line-height:1.6;-webkit-font-smoothing:antialiased}
    h1,h2,h3{font-family:'Montserrat',system-ui,sans-serif;font-weight:700;line-height:1.3}
    h1 em,h2 em{font-style:normal;color:var(--brand)}
    a{color:var(--brand);text-decoration:none}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:.75rem 1.5rem;border-radius:var(--radius-pill);font-weight:700;font-size:.9rem;cursor:pointer;border:2px solid transparent;font-family:inherit;transition:all .25s}
    .btn--cta{background:var(--brand);color:#fff;border-color:var(--brand);box-shadow:0 2px 8px rgba(46,125,107,.25)}
    .btn--cta:hover{background:var(--brand-dark);transform:translateY(-1px)}
    .btn--ghost{background:transparent;color:var(--text-sec);border-color:var(--border)}
    .btn--ghost:hover{border-color:var(--brand);color:var(--brand)}
    .container{max-width:1000px;margin:0 auto;padding:0 1.5rem}
    .nav{position:sticky;top:0;z-index:100;background:#fff;border-bottom:1px solid var(--border)}
    .nav__inner{display:flex;align-items:center;justify-content:space-between;height:64px}
    code{background:#f7f8f8;padding:.15rem .4rem;border-radius:4px;font-size:.85rem;color:var(--brand-dark)}
    .card{background:#fff;border:2px solid var(--border);border-radius:var(--radius-lg);padding:2rem;transition:all .3s}
    .card:hover{box-shadow:0 8px 30px rgba(0,0,0,.08)}
    .card--featured{border-color:var(--brand);position:relative}
    .card--featured::before{content:'Empfohlen';position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:var(--brand);color:#fff;padding:.2rem .8rem;border-radius:var(--radius-pill);font-size:.7rem;font-weight:700}
    .price{font-family:'Montserrat',sans-serif;font-size:2.5rem;font-weight:800;color:var(--text)}
    .price span{font-size:1rem;font-weight:600;color:var(--text-muted)}
    .features{list-style:none;margin:1.5rem 0}
    .features li{padding:.4rem 0;font-size:.9rem;color:var(--text-sec);display:flex;align-items:center;gap:.5rem}
    .features li::before{content:'';width:18px;height:18px;border-radius:50%;background:var(--brand-light);flex-shrink:0;display:flex;align-items:center;justify-content:center;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%232E7D6B' stroke-width='3'%3E%3Cpath d='M5 13l4 4L19 7'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:center}
    .endpoint{background:#f7f8f8;border-radius:var(--radius);padding:.6rem 1rem;margin:.3rem 0;font-family:monospace;font-size:.8rem;display:flex;justify-content:space-between;align-items:center}
    .endpoint .method{font-weight:700;color:var(--brand)}
    @media(max-width:768px){.pricing-grid{grid-template-columns:1fr!important}}
  </style>
</head>
<body>

<nav class="nav">
  <div class="container nav__inner">
    <a href="https://fleckfrei.de"><img src="https://fleckfrei.de/img/logo/logo-nav.svg" alt="fleckfrei" height="28"/></a>
    <div style="display:flex;gap:.5rem">
      <a href="https://fleckfrei.de" class="btn btn--ghost" style="padding:.4rem .75rem;font-size:.8rem">Website</a>
      <a href="/admin/osint-dashboard.php" class="btn btn--cta" style="padding:.4rem .75rem;font-size:.8rem">Dashboard</a>
    </div>
  </div>
</nav>

<!-- Hero -->
<section style="padding:4rem 0;text-align:center;background:linear-gradient(180deg,var(--brand-light) 0%,#fff 100%)">
  <div class="container">
    <div style="display:inline-block;padding:.3rem .8rem;background:var(--brand);color:#fff;border-radius:var(--radius-pill);font-size:.75rem;font-weight:700;margin-bottom:1rem">OSINT Intelligence API</div>
    <h1 style="font-size:clamp(1.8rem,4vw,2.8rem);margin-bottom:1rem">Verifiziere Personen<br/>und Firmen in <em>Sekunden</em></h1>
    <p style="color:var(--text-sec);max-width:550px;margin:0 auto 2rem;font-size:1.05rem">Email-Check, Telefon-Lookup, Social Media Scan, Dark Web Search — eine API, 9 Intelligence Tools.</p>
    <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap">
      <a href="#preise" class="btn btn--cta" style="padding:.8rem 2rem">Pricing ansehen</a>
      <a href="#docs" class="btn btn--ghost" style="padding:.8rem 2rem">API Docs</a>
    </div>
  </div>
</section>

<!-- Tools -->
<section style="padding:3rem 0">
  <div class="container">
    <h2 style="text-align:center;margin-bottom:2rem">9 Tools. <em>Eine API.</em></h2>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem">
      <div class="card" style="text-align:center;padding:1.5rem"><div style="font-size:1.5rem;margin-bottom:.5rem">&#128373;</div><h3 style="font-size:.95rem">Email Verify</h3><p style="font-size:.8rem;color:var(--text-muted)">MX, SPF, Disposable, Business-Check</p></div>
      <div class="card" style="text-align:center;padding:1.5rem"><div style="font-size:1.5rem;margin-bottom:.5rem">&#128241;</div><h3 style="font-size:.95rem">Phone Lookup</h3><p style="font-size:.8rem;color:var(--text-muted)">Land, Carrier, Typ, PhoneInfoga</p></div>
      <div class="card" style="text-align:center;padding:1.5rem"><div style="font-size:1.5rem;margin-bottom:.5rem">&#128101;</div><h3 style="font-size:.95rem">Social Scan</h3><p style="font-size:.8rem;color:var(--text-muted)">2.500+ Sites, Username-Suche</p></div>
      <div class="card" style="text-align:center;padding:1.5rem"><div style="font-size:1.5rem;margin-bottom:.5rem">&#128270;</div><h3 style="font-size:.95rem">SearXNG</h3><p style="font-size:.8rem;color:var(--text-muted)">30+ Suchmaschinen, keine Tracking</p></div>
      <div class="card" style="text-align:center;padding:1.5rem"><div style="font-size:1.5rem;margin-bottom:.5rem">&#127760;</div><h3 style="font-size:.95rem">WHOIS Deep</h3><p style="font-size:.8rem;color:var(--text-muted)">Domain-Besitzer, Registrar, Ablauf</p></div>
      <div class="card" style="text-align:center;padding:1.5rem"><div style="font-size:1.5rem;margin-bottom:.5rem">&#128274;</div><h3 style="font-size:.95rem">Dark Web</h3><p style="font-size:.8rem;color:var(--text-muted)">IntelX: Leaks, Pastes, Breaches</p></div>
      <div class="card" style="text-align:center;padding:1.5rem"><div style="font-size:1.5rem;margin-bottom:.5rem">&#129302;</div><h3 style="font-size:.95rem">AI Research</h3><p style="font-size:.8rem;color:var(--text-muted)">Perplexity AI Web-Recherche</p></div>
      <div class="card" style="text-align:center;padding:1.5rem"><div style="font-size:1.5rem;margin-bottom:.5rem">&#128231;</div><h3 style="font-size:.95rem">Holehe</h3><p style="font-size:.8rem;color:var(--text-muted)">Email auf 120+ Sites registriert?</p></div>
      <div class="card" style="text-align:center;padding:1.5rem"><div style="font-size:1.5rem;margin-bottom:.5rem">&#128373;</div><h3 style="font-size:.95rem">Google OSINT</h3><p style="font-size:.8rem;color:var(--text-muted)">Gravatar, Calendar, Web-Mentions</p></div>
    </div>
  </div>
</section>

<!-- Pricing -->
<section style="padding:3rem 0;background:var(--brand-light)" id="preise">
  <div class="container">
    <h2 style="text-align:center;margin-bottom:.5rem">Einfache <em>Preise</em></h2>
    <p style="text-align:center;color:var(--text-muted);margin-bottom:2rem">Keine versteckten Kosten. Jederzeit kuendbar.</p>
    <div class="pricing-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem">
      <!-- Free -->
      <div class="card">
        <h3 style="color:var(--text-muted);font-size:.9rem;text-transform:uppercase;letter-spacing:.05em">Free</h3>
        <div class="price" style="margin:.75rem 0">0 <span>EUR/mo</span></div>
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem">Zum Testen und fuer kleine Projekte.</p>
        <ul class="features">
          <li>50 Requests / Tag</li>
          <li>500 Requests / Monat</li>
          <li>Email + Phone Verify</li>
          <li>SearXNG Suche</li>
          <li>Community Support</li>
        </ul>
        <a href="mailto:api@fleckfrei.de?subject=OSINT API Free Key" class="btn btn--ghost" style="width:100%;margin-top:1rem">Key anfordern</a>
      </div>
      <!-- Pro -->
      <div class="card card--featured">
        <h3 style="color:var(--brand);font-size:.9rem;text-transform:uppercase;letter-spacing:.05em">Pro</h3>
        <div class="price" style="margin:.75rem 0">49 <span>EUR/mo</span></div>
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem">Fuer Unternehmen und regelmaessige Nutzung.</p>
        <ul class="features">
          <li>200 Requests / Tag</li>
          <li>5.000 Requests / Monat</li>
          <li>Alle 9 Tools</li>
          <li>Deep Scan (Full Dossier)</li>
          <li>Dark Web + AI Search</li>
          <li>Priority Support</li>
        </ul>
        <a href="mailto:api@fleckfrei.de?subject=OSINT API Pro Key" class="btn btn--cta" style="width:100%;margin-top:1rem">Jetzt starten</a>
      </div>
      <!-- Enterprise -->
      <div class="card">
        <h3 style="color:var(--text-muted);font-size:.9rem;text-transform:uppercase;letter-spacing:.05em">Enterprise</h3>
        <div class="price" style="margin:.75rem 0">199 <span>EUR/mo</span></div>
        <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem">Fuer grosse Teams und hohe Volumina.</p>
        <ul class="features">
          <li>1.000 Requests / Tag</li>
          <li>50.000 Requests / Monat</li>
          <li>Alle Tools + Batch API</li>
          <li>Dedizierter API Server</li>
          <li>Custom Integrationen</li>
          <li>SLA + Telefon-Support</li>
        </ul>
        <a href="mailto:api@fleckfrei.de?subject=OSINT API Enterprise" class="btn btn--ghost" style="width:100%;margin-top:1rem">Kontakt</a>
      </div>
    </div>
  </div>
</section>

<!-- API Docs -->
<section style="padding:3rem 0" id="docs">
  <div class="container" style="max-width:700px">
    <h2 style="text-align:center;margin-bottom:2rem">API <em>Dokumentation</em></h2>
    <div style="margin-bottom:1.5rem">
      <h3 style="font-size:1rem;margin-bottom:.5rem">Base URL</h3>
      <div class="endpoint"><code>https://app.fleckfrei.de/api/osint-api.php</code></div>
    </div>
    <div style="margin-bottom:1.5rem">
      <h3 style="font-size:1rem;margin-bottom:.5rem">Authentifizierung</h3>
      <div class="endpoint"><code>X-API-Key: flk_osi_...</code> <span style="font-size:.75rem;color:var(--text-muted)">Header</span></div>
      <div class="endpoint"><code>Authorization: Bearer flk_osi_...</code> <span style="font-size:.75rem;color:var(--text-muted)">Alternative</span></div>
    </div>
    <div style="margin-bottom:1.5rem">
      <h3 style="font-size:1rem;margin-bottom:.75rem">Endpoints</h3>
      <div class="endpoint"><span><span class="method">GET</span> <code>?action=health</code></span><span style="font-size:.75rem;color:var(--text-muted)">System-Status</span></div>
      <div class="endpoint"><span><span class="method">POST</span> <code>?action=verify-email</code></span><span style="font-size:.75rem;color:var(--text-muted)">Email pruefen</span></div>
      <div class="endpoint"><span><span class="method">POST</span> <code>?action=verify-phone</code></span><span style="font-size:.75rem;color:var(--text-muted)">Telefon pruefen</span></div>
      <div class="endpoint"><span><span class="method">POST</span> <code>?action=search</code></span><span style="font-size:.75rem;color:var(--text-muted)">SearXNG Suche</span></div>
      <div class="endpoint"><span><span class="method">POST</span> <code>?action=quick</code></span><span style="font-size:.75rem;color:var(--text-muted)">Quick Scan</span></div>
      <div class="endpoint"><span><span class="method">POST</span> <code>?action=scan</code></span><span style="font-size:.75rem;color:var(--text-muted)">Full Deep Scan</span></div>
      <div class="endpoint"><span><span class="method">GET</span> <code>?action=usage</code></span><span style="font-size:.75rem;color:var(--text-muted)">Verbrauch</span></div>
    </div>
    <div>
      <h3 style="font-size:1rem;margin-bottom:.75rem">Beispiel</h3>
      <pre style="background:#1e2121;color:#e8eaea;padding:1.25rem;border-radius:var(--radius-lg);font-size:.8rem;overflow-x:auto;line-height:1.5"><code>curl -X POST "https://app.fleckfrei.de/api/osint-api.php?action=verify-email" \
  -H "X-API-Key: flk_osi_your_key_here" \
  -H "Content-Type: application/json" \
  -d '{"email": "test@example.com"}'

# Response:
{
  "success": true,
  "data": {
    "email": "test@example.com",
    "domain": "example.com",
    "mx_records": 1,
    "is_free": false,
    "is_disposable": false,
    "is_business": true
  },
  "_meta": {"response_ms": 41}
}</code></pre>
    </div>
  </div>
</section>

<footer style="border-top:1px solid var(--border);padding:2rem;text-align:center;font-size:.8rem;color:var(--text-muted)">
  &copy; <?= date('Y') ?> <?= SITE ?> &mdash; <a href="https://<?= SITE_DOMAIN ?>"><?= SITE_DOMAIN ?></a> &mdash; <a href="mailto:api@fleckfrei.de">api@fleckfrei.de</a>
</footer>

</body>
</html>
