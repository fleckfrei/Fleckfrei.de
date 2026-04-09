/**
 * Fleckfrei.de — Main Application
 * Booking, Stripe, n8n webhook, animations, accessibility
 */
'use strict';

/* ================================================================ CONFIG */
const FF = {
  webhook: 'https://n8n.la-renting.com/webhook/fleckfrei-booking',
  webhookConfirm: 'https://n8n.la-renting.com/webhook/fleckfrei-booking-confirm',
  stripeKey: '***REDACTED***',
  waNumber: '4915757010977',
  basePrice: 56.58,
  extras: {
    pflegemittel: { label: 'Pflegemittel-Flatrate', price: 12.99, unit: '/Monat' },
    waesche: { label: 'Waescheservice', price: 13.40, unit: '/kg' },
    schluesselbox: { label: 'Schluesselbox', price: 23.99, unit: ' einmalig' }
  }
};

/* ================================================================ PRELOADER */
window.addEventListener('load', () => {
  setTimeout(() => document.getElementById('preloader')?.classList.add('hidden'), 600);
});

/* ================================================================ NAV */
const nav = document.getElementById('nav');
const burger = document.getElementById('burger');
const navMenu = document.getElementById('navMenu');

window.addEventListener('scroll', () => {
  nav?.classList.toggle('nav--scrolled', window.scrollY > 30);
}, { passive: true });

burger?.addEventListener('click', () => {
  navMenu?.classList.toggle('open');
  const open = navMenu?.classList.contains('open');
  burger.setAttribute('aria-expanded', open);
});

navMenu?.querySelectorAll('a').forEach(a => {
  a.addEventListener('click', () => navMenu.classList.remove('open'));
});

/* ================================================================ SCROLL ANIMATIONS */
const animateObserver = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      const delay = entry.target.dataset.delay || 0;
      setTimeout(() => entry.target.classList.add('visible'), parseInt(delay));
      animateObserver.unobserve(entry.target);
    }
  });
}, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

document.querySelectorAll('[data-animate]').forEach(el => animateObserver.observe(el));

/* ================================================================ COUNTER ANIMATION */
document.querySelectorAll('[data-count]').forEach(el => {
  const observer = new IntersectionObserver(entries => {
    if (entries[0].isIntersecting) {
      const target = parseFloat(el.dataset.count);
      const isDecimal = target % 1 !== 0;
      const duration = 1500;
      const start = performance.now();
      const animate = (now) => {
        const progress = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        const current = eased * target;
        el.textContent = isDecimal ? current.toFixed(1) : Math.round(current);
        if (progress < 1) requestAnimationFrame(animate);
      };
      requestAnimationFrame(animate);
      observer.unobserve(el);
    }
  }, { threshold: 0.5 });
  observer.observe(el);
});

/* ================================================================ DYNAMIC PRICING INTEGRATION */
function initPricing() {
  const serviceRadios = document.querySelectorAll('input[name="service"]');
  const extraCheckboxes = document.querySelectorAll('input[name="extras"]');

  // Show/hide str fields
  serviceRadios.forEach(r => {
    r.addEventListener('change', () => {
      const strFields = document.getElementById('strFields');
      if (strFields) {
        strFields.style.display = r.value === 'ferienwohnung' ? 'block' : 'none';
      }
      updateLivePrice();
    });
  });

  // Update price on addon change
  extraCheckboxes.forEach(cb => cb.addEventListener('change', updateLivePrice));

  // Update price when address/sqm/date/time changes
  ['address', 'sqm', 'date', 'time'].forEach(name => {
    const el = document.querySelector(`[name="${name}"]`);
    if (el) el.addEventListener('change', updateLivePrice);
  });
  const sqmEl = document.querySelector('input[name="sqm"]');
  if (sqmEl) sqmEl.addEventListener('input', updateLivePrice);

  // Update market prices on page load (async)
  if (typeof PricingEngine !== 'undefined') {
    PricingEngine.updateMarketPrices();
  }

  // Update price on property count change
  const numProp = document.querySelector('input[name="numProperties"]');
  if (numProp) {
    numProp.addEventListener('input', () => {
      const count = parseInt(numProp.value) || 1;
      const hint = document.getElementById('multiPropertyHint');
      if (hint && count >= 3) {
        const disc = count >= 10 ? 18 : count >= 5 ? 12 : 8;
        hint.textContent = `${disc}% Rabatt bei ${count} Wohnungen`;
        hint.style.color = 'var(--brand)';
      } else if (hint) {
        hint.textContent = count >= 2 ? 'Ab 3 Wohnungen gibt es Rabatt' : '';
      }
      updateLivePrice();
    });
  }
}

function updateLivePrice() {
  if (typeof PricingEngine === 'undefined') return;

  const serviceEl = document.querySelector('input[name="service"]:checked');
  if (!serviceEl) return;

  const typeMap = { privathaushalt: 'private', ferienwohnung: 'str', buero: 'office' };
  const customerType = typeMap[serviceEl.value] || 'private';
  const selectedAddons = Array.from(document.querySelectorAll('input[name="extras"]:checked')).map(cb => cb.value);
  const numProperties = parseInt(document.querySelector('input[name="numProperties"]')?.value) || 1;
  const sqm = parseInt(document.querySelector('input[name="sqm"]')?.value) || 60;
  const address = document.querySelector('input[name="address"]')?.value || '';
  const plz = PricingEngine.extractPLZ(address);
  const date = document.querySelector('input[name="date"]')?.value || null;
  const time = document.querySelector('input[name="time"]')?.value || null;

  const result = PricingEngine.calculate({
    customerType,
    sqm,
    rooms: 2,
    frequency: document.querySelector('select[name="frequency"]')?.value || 'einmalig',
    selectedAddons,
    numProperties,
    plz,
    date,
    time
  });

  // Show minimum hours info
  if (result.minHours > 2) {
    const hint = document.getElementById('multiPropertyHint');
    if (hint) hint.textContent += ` | Min. ${result.minHours}h Buchung`;
  }

  const preview = document.getElementById('pricePreview');
  const livePrice = document.getElementById('livePrice');
  if (preview && livePrice) {
    preview.style.display = 'block';
    livePrice.textContent = PricingEngine.formatEUR(result.total);
  }
}

document.addEventListener('DOMContentLoaded', initPricing);

/* ================================================================ BOOKING FORM */
function goStep(step) {
  const form = document.getElementById('bookingForm');
  const current = form.querySelector('.book__step.active');
  const currentStep = parseInt(current.dataset.step);

  if (step > currentStep && !validateStep(currentStep)) return;
  if (step === 3) buildSummary();

  current.classList.remove('active');
  form.querySelector(`[data-step="${step}"]`).classList.add('active');
  document.getElementById('buchen').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function validateStep(step) {
  if (step === 1) {
    if (!document.querySelector('input[name="service"]:checked')) {
      showToast('Bitte waehlen Sie einen Service aus.');
      return false;
    }
    return true;
  }
  if (step === 2) {
    const fields = document.querySelectorAll('.book__step[data-step="2"] [required]');
    for (const el of fields) {
      if (!el.value.trim()) {
        el.focus();
        el.style.borderColor = '#dc2626';
        setTimeout(() => el.style.borderColor = '', 2000);
        showToast('Bitte fuellen Sie alle Pflichtfelder aus.');
        return false;
      }
    }
    const email = document.querySelector('input[name="email"]');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
      email.focus();
      showToast('Bitte geben Sie eine gueltige E-Mail ein.');
      return false;
    }
    return true;
  }
  return true;
}

function buildSummary() {
  const service = document.querySelector('input[name="service"]:checked').value;
  const names = { privathaushalt: 'Privathaushalt', ferienwohnung: 'Airbnb / Ferienwohnung', buero: 'Buero / Gewerbe' };
  const typeMap = { privathaushalt: 'private', ferienwohnung: 'str', buero: 'office' };
  const f = (n) => document.querySelector(`[name="${n}"]`)?.value || '';
  const selectedAddons = Array.from(document.querySelectorAll('input[name="extras"]:checked')).map(cb => cb.value);

  // Use pricing engine for dynamic calculation
  const result = PricingEngine.calculate({
    customerType: typeMap[service],
    sqm: parseInt(f('sqm')) || 60,
    rooms: parseInt(f('rooms')) || 2,
    frequency: f('frequency'),
    date: f('date'),
    time: f('time'),
    numProperties: parseInt(f('numProperties')) || 1,
    selectedAddons,
    pastBookings: 0  // will come from API later
  });

  // Build summary with personal details + pricing breakdown
  let html = '';
  html += row('Service', names[service]);
  html += row('Name', esc(f('name')));
  html += row('E-Mail', esc(f('email')));
  html += row('Telefon', esc(f('phone')));
  html += row('Adresse', esc(f('address')));
  html += row('Flaeche', esc(f('sqm')) + ' m2');
  html += row('Termin', f('date') + ' um ' + f('time'));
  html += row('Haeufigkeit', f('frequency'));
  html += '<div style="border-top:2px solid var(--border);margin:1rem 0"></div>';
  html += PricingEngine.renderBreakdown(result);

  document.getElementById('bookingSummary').innerHTML = html;

  // Store total for payment
  window._bookingTotal = result.total;
  window._pricingResult = result;
}

function row(label, value) {
  return `<div class="summary__row"><span>${label}</span><span>${value}</span></div>`;
}

function esc(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}

/* ================================================================ STRIPE */
let stripe, cardElement;

function initStripe() {
  if (FF.stripeKey.includes('REPLACE')) return;
  stripe = Stripe(FF.stripeKey);
  const elements = stripe.elements({ locale: 'de' });
  cardElement = elements.create('card', {
    style: { base: { fontSize: '16px', color: '#0f172a', fontFamily: 'Inter, system-ui', '::placeholder': { color: '#94a3b8' } } }
  });
  cardElement.mount('#cardElement');
  cardElement.on('change', e => {
    document.getElementById('cardErrors').textContent = e.error ? e.error.message : '';
  });
}
document.addEventListener('DOMContentLoaded', initStripe);

/* ================================================================ PAYPAL (Server-Side) */
function initPayPal() {
  if (typeof paypal === 'undefined') return;
  paypal.Buttons({
    style: { layout: 'vertical', color: 'black', shape: 'pill', label: 'pay', height: 45 },
    createOrder: async () => {
      const total = window._bookingTotal || 56.58;
      const bookingData = collectData();
      // Create order server-side
      const res = await fetch('https://app.fleckfrei.de/api/paypal-create.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ amount: total, bookingId: 'FF-' + Date.now(), description: 'Fleckfrei ' + bookingData.service })
      });
      const order = await res.json();
      if (!order.success) throw new Error('PayPal order failed');
      window._currentBookingId = order.bookingId;
      return order.orderID;
    },
    onApprove: async (data) => {
      // Capture server-side
      const captureRes = await fetch('https://app.fleckfrei.de/api/paypal-capture.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ orderID: data.orderID })
      });
      const capture = await captureRes.json();
      if (capture.success) {
        // Also send booking data to n8n
        const bookingData = collectData();
        bookingData.payment.paypalOrderId = data.orderID;
        bookingData.payment.status = 'paid';
        await fetch(FF.webhook, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(bookingData)
        });
        showConfirmation(window._currentBookingId || 'FF-' + Date.now());
      } else {
        document.getElementById('cardErrors').textContent = 'Zahlung fehlgeschlagen. Bitte erneut versuchen.';
      }
    },
    onError: (err) => {
      document.getElementById('cardErrors').textContent = 'PayPal Fehler. Bitte waehlen Sie eine andere Zahlungsmethode.';
    }
  }).render('#paypalElement');
}
document.addEventListener('DOMContentLoaded', initPayPal);

// Toggle between card/paypal display
document.querySelectorAll('input[name="payment"]').forEach(radio => {
  radio.addEventListener('change', () => {
    const isPaypal = radio.value === 'paypal';
    const cardEl = document.getElementById('cardElement');
    const paypalEl = document.getElementById('paypalElement');
    const isRechnung = radio.value === 'rechnung';
    if (cardEl) cardEl.style.display = (isPaypal || isRechnung) ? 'none' : 'block';
    if (paypalEl) paypalEl.style.display = isPaypal ? 'block' : 'none';
  });
});

/* ================================================================ FORM SUBMIT */
document.getElementById('bookingForm')?.addEventListener('submit', async (e) => {
  // Rate limit
  if (window._lastBookingSubmit && Date.now() - window._lastBookingSubmit < 5000) { showToast('Bitte warten Sie einen Moment.'); e.preventDefault(); return; }
  window._lastBookingSubmit = Date.now();
  e.preventDefault();
  const btn = document.getElementById('submitBtn');
  const btnText = document.getElementById('btnText');
  const btnSpinner = document.getElementById('btnSpinner');

  btn.disabled = true;
  btnText.textContent = 'Wird verarbeitet...';
  btnSpinner.style.display = 'inline-block';

  try {
    const data = collectData();

    const res = await fetch(FF.webhook, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });

    if (!res.ok) throw new Error('Server error');
    const result = await res.json();

    if (result.clientSecret && stripe && cardElement) {
      const { error, paymentIntent } = await stripe.confirmCardPayment(result.clientSecret, {
        payment_method: { card: cardElement }
      });
      if (error) throw new Error(error.message);

      await fetch(FF.webhookConfirm, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ bookingId: result.bookingId, paymentId: paymentIntent.id, status: paymentIntent.status })
      });
    }

    showConfirmation(result.bookingId || 'FF-' + Date.now());
  } catch (err) {
    document.getElementById('cardErrors').textContent = 'Fehler: ' + err.message;
    btn.disabled = false;
    btnText.textContent = 'Jetzt verbindlich buchen';
    btnSpinner.style.display = 'none';
  }
});

function collectData() {
  const f = (n) => document.querySelector(`[name="${n}"]`)?.value?.trim() || '';
  const service = document.querySelector('input[name="service"]:checked')?.value;
  const extras = Array.from(document.querySelectorAll('input[name="extras"]:checked')).map(cb => cb.value);
  const payment = document.querySelector('input[name="payment"]:checked')?.value;

  return {
    source: 'fleckfrei.de',
    timestamp: new Date().toISOString(),
    service,
    customer: { name: f('name'), email: f('email'), phone: f('phone'), address: f('address') },
    property: { sqm: parseInt(f('sqm')) || 0, rooms: parseInt(f('rooms')) || null },
    schedule: { date: f('date'), time: f('time'), frequency: f('frequency') },
    extras,
    notes: f('notes'),
    payment: {
      method: payment,
      basePrice: FF.basePrice,
      extrasTotal: extras.reduce((sum, k) => sum + (FF.extras[k]?.price || 0), 0)
    }
  };
}

function showConfirmation(bookingId) {
  const f = (n) => document.querySelector(`[name="${n}"]`)?.value || '';
  document.getElementById('confirmationDetails').innerHTML = `
    <div class="book__summary" style="text-align:left;margin:1.5rem 0">
      ${row('Buchungsnummer', '<strong>' + esc(bookingId) + '</strong>')}
      ${row('Name', esc(f('name')))}
      ${row('Termin', f('date') + ' um ' + f('time'))}
    </div>`;
  goStep(4);
}

/* ================================================================ TOAST */
function showToast(msg) {
  let toast = document.getElementById('toast');
  if (!toast) {
    toast = document.createElement('div');
    toast.id = 'toast';
    toast.setAttribute('role', 'alert');
    toast.style.cssText = 'position:fixed;bottom:2rem;left:50%;transform:translateX(-50%) translateY(100px);background:#0f172a;color:#fff;padding:1rem 2rem;border-radius:999px;font-size:.9rem;z-index:9999;transition:transform .4s cubic-bezier(.34,1.56,.64,1);box-shadow:0 8px 32px rgba(0,0,0,.2)';
    document.body.appendChild(toast);
  }
  toast.textContent = msg;
  toast.style.transform = 'translateX(-50%) translateY(0)';
  setTimeout(() => { toast.style.transform = 'translateX(-50%) translateY(100px)'; }, 3000);
}

/* ================================================================ MIN DATE */
document.addEventListener('DOMContentLoaded', () => {
  const d = document.querySelector('input[name="date"]');
  if (d) d.setAttribute('min', new Date().toISOString().split('T')[0]);
});

/* ================================================================ SMOOTH SCROLL (for anchors) */
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', (e) => {
    const target = document.querySelector(a.getAttribute('href'));
    if (target) {
      e.preventDefault();
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  });
});

window.goStep = goStep;
