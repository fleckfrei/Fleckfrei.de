<?php
require_once __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Jetzt buchen — <?= SITE ?></title>
  <meta name="description" content="<?= SITE ?> Service online buchen. Flexible Termine, faire Preise."/>
  <meta name="theme-color" content="<?= BRAND ?>"/>
  <link rel="icon" href="https://fleckfrei.de/img/logo/favicon.svg"/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;500;600;700;800&family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet"/>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    :root { --brand:#2E7D6B; --brand-dark:#245f54; --brand-light:#e8f5f1; --accent:#d25f66; --text:#1e2121; --text-sec:#5a6060; --text-muted:#8e9696; --bg:#ffffff; --bg-light:#f7f8f8; --border:#e8eaea; --radius:8px; --radius-lg:16px; --radius-pill:9999px; --shadow:0 0 2px rgba(0,0,0,.08),0 2px 8px rgba(0,0,0,.06); --shadow-lg:0 4px 24px rgba(0,0,0,.08); --ease:cubic-bezier(.4,0,.2,1); --max-w:680px; }
    *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
    html { scroll-behavior:smooth; }
    body { font-family:'Nunito Sans',system-ui,sans-serif; color:var(--text); background:var(--bg); line-height:1.6; -webkit-font-smoothing:antialiased; }
    h1,h2,h3 { font-family:'Montserrat',system-ui,sans-serif; font-weight:700; line-height:1.3; letter-spacing:-.01em; }
    h1 em, h2 em { font-style:normal; color:var(--brand); }
    a { color:var(--brand); text-decoration:none; }
    [x-cloak] { display:none !important; }

    /* Nav */
    .nav { position:sticky; top:0; z-index:100; background:#fff; border-bottom:1px solid var(--border); }
    .nav__inner { max-width:var(--max-w); margin:0 auto; padding:0 1.5rem; height:64px; display:flex; align-items:center; justify-content:space-between; }
    .logo__img { height:28px; }

    /* Buttons */
    .btn { display:inline-flex; align-items:center; justify-content:center; gap:.4rem; padding:.75rem 1.5rem; border-radius:var(--radius-pill); font-weight:700; font-size:.9rem; transition:all .25s var(--ease); cursor:pointer; border:2px solid transparent; font-family:inherit; }
    .btn--cta { background:var(--brand); color:#fff; border-color:var(--brand); box-shadow:0 2px 8px rgba(46,125,107,.25); }
    .btn--cta:hover { background:var(--brand-dark); border-color:var(--brand-dark); transform:translateY(-1px); box-shadow:0 4px 16px rgba(46,125,107,.3); }
    .btn--cta:disabled { opacity:.5; cursor:not-allowed; transform:none; }
    .btn--ghost { background:transparent; color:var(--text-sec); border-color:var(--border); }
    .btn--ghost:hover { border-color:var(--brand); color:var(--brand); }
    .btn--full { width:100%; }
    .btn--xl { padding:1rem 2rem; font-size:1.05rem; }

    /* Cards */
    .card { background:#fff; border:1px solid var(--border); border-radius:var(--radius-lg); padding:1.5rem; transition:all .25s var(--ease); }
    .card:hover { box-shadow:var(--shadow-lg); }
    .card--selected { border-color:var(--brand); background:var(--brand-light); }

    /* Inputs */
    .field { display:flex; flex-direction:column; gap:.35rem; }
    .field label { font-size:.8rem; font-weight:700; color:var(--text-sec); text-transform:uppercase; letter-spacing:.04em; }
    .field input, .field select, .field textarea { padding:.75rem 1rem; border:2px solid var(--border); border-radius:var(--radius); font-family:inherit; font-size:.95rem; color:var(--text); transition:border .2s; background:#fff; }
    .field input:focus, .field select:focus, .field textarea:focus { outline:none; border-color:var(--brand); box-shadow:0 0 0 3px rgba(46,125,107,.1); }
    .field textarea { resize:none; }

    /* Steps */
    .steps { display:flex; align-items:center; gap:.5rem; padding:1rem 0; }
    .step-dot { width:10px; height:10px; border-radius:50%; background:var(--border); transition:all .3s; }
    .step-dot.active { background:var(--brand); width:28px; border-radius:5px; }
    .step-dot.done { background:var(--brand); }
    .step-line { flex:1; height:2px; background:var(--border); transition:background .5s; }
    .step-line.done { background:var(--brand); }

    /* Section */
    .section { max-width:var(--max-w); margin:0 auto; padding:2rem 1.5rem; }
    .section__title { text-align:center; margin-bottom:.5rem; font-size:1.5rem; }
    .section__sub { text-align:center; color:var(--text-muted); margin-bottom:2rem; font-size:.95rem; }

    /* Service grid */
    .services { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
    @media(max-width:600px) { .services { grid-template-columns:1fr; } }
    .service { cursor:pointer; position:relative; }
    .service__price { font-family:'Montserrat',sans-serif; font-size:1.4rem; font-weight:800; color:var(--brand); margin-top:.75rem; }
    .service__tag { display:inline-block; padding:.15rem .5rem; background:var(--bg-light); border-radius:var(--radius-pill); font-size:.7rem; font-weight:600; color:var(--text-muted); margin-top:.5rem; }
    .service__check { position:absolute; top:1rem; right:1rem; width:24px; height:24px; border-radius:50%; background:var(--brand); display:flex; align-items:center; justify-content:center; }

    /* Summary */
    .summary { border-radius:var(--radius-lg); overflow:hidden; border:1px solid var(--border); }
    .summary__head { background:var(--brand); color:#fff; padding:1.25rem 1.5rem; display:flex; justify-content:space-between; align-items:center; }
    .summary__head h3 { color:#fff; font-size:1.1rem; }
    .summary__price { font-family:'Montserrat',sans-serif; font-size:1.8rem; font-weight:800; }
    .summary__body { padding:1.25rem 1.5rem; }
    .summary__row { display:flex; justify-content:space-between; padding:.4rem 0; font-size:.9rem; color:var(--text-sec); }
    .summary__row span:last-child { color:var(--text); font-weight:600; }

    /* Success */
    .success { text-align:center; padding:3rem 1rem; }
    .success__icon { width:64px; height:64px; border-radius:50%; background:var(--brand-light); display:flex; align-items:center; justify-content:center; margin:0 auto 1.5rem; }

    /* Animate */
    .fade-in { animation: fadeIn .4s ease-out; }
    @keyframes fadeIn { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
  </style>
</head>
<body x-data="bookingForm()" x-cloak>

<!-- Nav -->
<nav class="nav">
  <div class="nav__inner">
    <a href="https://fleckfrei.de"><img src="https://fleckfrei.de/img/logo/logo-nav.svg" alt="fleckfrei" class="logo__img"/></a>
    <div style="display:flex;align-items:center;gap:.75rem">
      <a href="https://wa.me/message/OVHQQCZT7WYAH1" target="_blank" class="btn btn--ghost" style="padding:.5rem .75rem;font-size:.8rem">WhatsApp</a>
      <a href="https://fleckfrei.de" class="btn btn--ghost" style="padding:.5rem .75rem;font-size:.8rem">Startseite</a>
    </div>
  </div>
</nav>

<!-- Steps -->
<div style="max-width:var(--max-w);margin:0 auto;padding:.75rem 1.5rem">
  <div class="steps">
    <template x-for="(s,i) in ['Service','Details','Buchen']" :key="i">
      <div style="display:flex;align-items:center;gap:.5rem;flex:1">
        <div class="step-dot" :class="{'active':step===i,'done':step>i}"></div>
        <span style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em" :style="step>=i?'color:var(--brand)':'color:var(--text-muted)'" x-text="s"></span>
        <template x-if="i<2"><div class="step-line" :class="{'done':step>i}"></div></template>
      </div>
    </template>
  </div>
</div>

<!-- Step 0: Service -->
<div class="section" x-show="step===0" x-transition>
  <h2 class="section__title">Welchen <em>Service</em> brauchst du?</h2>
  <p class="section__sub">Flexible Termine, verifizierte Partner, faire Preise.</p>
  <div class="services">
    <template x-for="s in services" :key="s.id">
      <div class="card service" :class="{'card--selected':form.service===s.name}" @click="selectService(s)">
        <div x-show="form.service===s.name" class="service__check"><svg width="14" height="14" fill="none" stroke="#fff" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg></div>
        <div style="font-weight:700;font-size:1.05rem" x-text="s.name"></div>
        <div style="color:var(--text-muted);font-size:.85rem;margin-top:.25rem" x-text="s.desc"></div>
        <div class="service__price" x-text="'ab ' + s.price + ' EUR/h'"></div>
        <div class="service__tag" x-text="s.tag"></div>
      </div>
    </template>
  </div>
</div>

<!-- Step 1: Details -->
<div class="section fade-in" x-show="step===1" x-transition>
  <h2 class="section__title">Wann und <em>wo</em>?</h2>
  <p class="section__sub">Sag uns wann und wo wir kommen sollen.</p>
  <!-- Selected service pill -->
  <div style="display:flex;align-items:center;justify-content:space-between;background:var(--brand-light);border-radius:var(--radius-pill);padding:.6rem 1rem;margin-bottom:1.5rem">
    <span style="font-weight:700;font-size:.9rem" x-text="form.service + ' — ' + form.price_per_hour + ' EUR/h'"></span>
    <button @click="step=0" style="font-size:.8rem;color:var(--brand);font-weight:700;background:none;border:none;cursor:pointer">Aendern</button>
  </div>
  <div class="card" style="display:flex;flex-direction:column;gap:1rem">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div class="field"><label>Name</label><input type="text" x-model="form.name" placeholder="Max Mustermann"/></div>
      <div class="field"><label>Telefon</label><input type="tel" x-model="form.phone" placeholder="+49 170 123 4567"/></div>
    </div>
    <div class="field"><label>Email</label><input type="email" x-model="form.email" placeholder="deine@email.de"/></div>
    <div class="field"><label>Adresse</label><input type="text" x-model="form.address" placeholder="Musterstr. 12, 10115 Berlin"/></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div class="field"><label>Wunschtermin</label><input type="date" x-model="form.date" :min="minDate"/></div>
      <div class="field"><label>Uhrzeit</label>
        <select x-model="form.time"><option value="08:00">08:00</option><option value="09:00">09:00</option><option value="10:00">10:00</option><option value="11:00">11:00</option><option value="12:00">12:00</option><option value="13:00">13:00</option><option value="14:00">14:00</option><option value="15:00">15:00</option><option value="16:00">16:00</option></select></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div class="field"><label>Dauer</label>
        <select x-model="form.hours"><option value="2">2 Stunden</option><option value="3">3 Stunden</option><option value="4">4 Stunden</option><option value="5">5 Stunden</option><option value="6">6 Stunden</option><option value="8">Ganzer Tag (8h)</option></select></div>
      <div class="field"><label>Wie oft?</label>
        <select x-model="form.frequency"><option value="once">Einmalig</option><option value="weekly">Jede Woche</option><option value="biweekly">Alle 2 Wochen</option><option value="monthly">Monatlich</option></select></div>
    </div>
    <div class="field"><label>Anmerkungen <span style="font-weight:400;text-transform:none">(optional)</span></label>
      <textarea rows="2" x-model="form.notes" placeholder="z.B. Haustiere, bestimmte Bereiche..."></textarea></div>
  </div>
</div>

<!-- Step 2: Summary -->
<div class="section fade-in" x-show="step===2" x-transition>
  <h2 class="section__title">Fast <em>geschafft</em>!</h2>
  <p class="section__sub">Pruefe deine Angaben und buche.</p>
  <div class="summary" style="margin-bottom:1.5rem">
    <div class="summary__head">
      <h3 x-text="form.service"></h3>
      <div><div class="summary__price" x-text="totalBrutto + ' EUR'"></div><div style="font-size:.7rem;opacity:.7">inkl. MwSt</div></div>
    </div>
    <div class="summary__body">
      <div class="summary__row"><span>Name</span><span x-text="form.name"></span></div>
      <div class="summary__row"><span>Kontakt</span><span x-text="form.phone + ' / ' + form.email"></span></div>
      <div class="summary__row"><span>Adresse</span><span x-text="form.address"></span></div>
      <div class="summary__row"><span>Termin</span><span x-text="formatDate(form.date) + ', ' + form.time + ' Uhr'"></span></div>
      <div class="summary__row"><span>Dauer</span><span x-text="form.hours + ' Stunden'"></span></div>
      <template x-if="form.frequency!=='once'"><div class="summary__row"><span>Wiederholung</span><span x-text="freqLabel"></span></div></template>
      <div class="summary__row" style="border-top:1px solid var(--border);padding-top:.75rem;margin-top:.5rem"><span style="font-weight:700;color:var(--text)">Netto</span><span x-text="totalPrice + ' EUR'"></span></div>
    </div>
  </div>
  <button @click="submitBooking('invoice')" :disabled="paying" class="btn btn--cta btn--full btn--xl">
    <template x-if="!paying"><span>Jetzt verbindlich buchen</span></template>
    <template x-if="paying"><span>Wird gebucht...</span></template>
  </button>
  <p style="text-align:center;font-size:.8rem;color:var(--text-muted);margin-top:.75rem">Zahlung nach Service — keine Vorauszahlung</p>
</div>

<!-- Step 3: Success -->
<div class="section fade-in" x-show="step===3" x-transition>
  <div class="success">
    <div class="success__icon"><svg width="28" height="28" fill="none" stroke="var(--brand)" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg></div>
    <h2 style="margin-bottom:.5rem">Buchung <em>bestaetigt</em>!</h2>
    <p style="color:var(--text-muted);margin-bottom:2rem">Wir melden uns innerhalb von 2 Stunden.</p>
    <div class="card" style="max-width:360px;margin:0 auto 2rem;text-align:left">
      <div class="summary__row"><span>Buchungs-Nr.</span><span style="font-family:monospace;color:var(--brand)" x-text="'#' + bookingId"></span></div>
      <div class="summary__row"><span>Service</span><span x-text="form.service"></span></div>
      <div class="summary__row"><span>Termin</span><span x-text="formatDate(form.date) + ', ' + form.time"></span></div>
      <div class="summary__row" style="border-top:1px solid var(--border);padding-top:.5rem;margin-top:.25rem"><span style="font-weight:700">Betrag</span><span style="font-weight:700;color:var(--brand)" x-text="totalBrutto + ' EUR'"></span></div>
    </div>
    <a href="/book.php" class="btn btn--cta">Weitere Buchung</a>
    <a href="https://wa.me/message/OVHQQCZT7WYAH1" target="_blank" class="btn btn--ghost" style="margin-left:.5rem">WhatsApp</a>
  </div>
</div>

<!-- Navigation -->
<div style="max-width:var(--max-w);margin:0 auto;padding:0 1.5rem 2rem;display:flex;justify-content:space-between" x-show="step>0 && step<3">
  <button @click="step--" class="btn btn--ghost">Zurueck</button>
  <button @click="nextStep()" x-show="step<2" :disabled="!canNext" class="btn btn--cta">Weiter</button>
</div>

<!-- Footer -->
<footer style="border-top:1px solid var(--border);padding:2rem 1.5rem;text-align:center;font-size:.8rem;color:var(--text-muted)">
  &copy; <?= date('Y') ?> <?= SITE ?> &mdash; <a href="https://<?= SITE_DOMAIN ?>"><?= SITE_DOMAIN ?></a> &mdash; <a href="mailto:<?= CONTACT_EMAIL ?>"><?= CONTACT_EMAIL ?></a>
</footer>

<script>
function bookingForm() {
  return {
    step:0, paying:false, bookingId:'',
    services:[
      {id:'std',name:'Standardreinigung',price:35,desc:'Wohnung, Haus oder Buero — regelmaessig oder einmalig.',tag:'Am beliebtesten'},
      {id:'grd',name:'Grundreinigung',price:45,desc:'Tiefenreinigung nach Umzug, Renovierung oder Fruehjahrputz.',tag:'Intensiv'},
      {id:'fen',name:'Fensterreinigung',price:40,desc:'Alle Fenster innen + aussen, Rahmen und Fensterbaenke.',tag:'Innen + Aussen'},
      {id:'bue',name:'Bueroreinigung',price:38,desc:'Professionelle Buero- und Gewerbereinigung.',tag:'Gewerbe'}
    ],
    form:{service:'',price_per_hour:0,name:'',phone:'',email:'',address:'',date:'',time:'09:00',hours:'3',frequency:'once',notes:''},
    get minDate(){var d=new Date();d.setDate(d.getDate()+1);return d.toISOString().slice(0,10)},
    get totalPrice(){return(this.form.price_per_hour*parseInt(this.form.hours)).toFixed(2)},
    get totalBrutto(){return(this.totalPrice*1.19).toFixed(2)},
    get freqLabel(){return{once:'Einmalig',weekly:'Jede Woche',biweekly:'Alle 2 Wochen',monthly:'Monatlich'}[this.form.frequency]},
    get canNext(){
      if(this.step===0)return!!this.form.service;
      if(this.step===1)return this.form.name&&this.form.phone&&this.form.email&&this.form.address&&this.form.date;
      return true;
    },
    formatDate(d){if(!d)return'';var p=d.split('-');return p[2]+'.'+p[1]+'.'+p[0]},
    selectService(s){this.form.service=s.name;this.form.price_per_hour=s.price;this.step=1},
    nextStep(){if(this.canNext)this.step++},
    async submitBooking(method){
      this.paying=true;
      try{
        var resp=await fetch('/api/index.php?action=webhook/booking',{
          method:'POST',headers:{'Content-Type':'application/json'},
          body:JSON.stringify({name:this.form.name,phone:this.form.phone,email:this.form.email,address:this.form.address,service:this.form.service,date:this.form.date,time:this.form.time,hours:parseInt(this.form.hours),frequency:this.form.frequency,notes:this.form.notes,platform:'website',customer_type:'private',payment_method:method,amount:parseFloat(this.totalBrutto)})
        });
        var d=await resp.json();
        this.paying=false;
        if(d.success){this.bookingId=d.data?.booking_id||d.data?.j_id||Math.floor(Math.random()*9000+1000);this.step=3}
        else alert(d.error||'Fehler');
      }catch(e){this.paying=false;alert('Netzwerkfehler')}
    }
  };
}
</script>
</body>
</html>
