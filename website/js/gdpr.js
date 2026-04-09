/**
 * Fleckfrei — GDPR/DSGVO Cookie Consent + Accessibility
 */
'use strict';

/* ================================================================ COOKIES */
const COOKIE_KEY = 'ff_cookie_consent';

function getCookieConsent() {
  try { return JSON.parse(localStorage.getItem(COOKIE_KEY)); } catch { return null; }
}

function showCookieBanner() {
  const consent = getCookieConsent();
  const banner = document.getElementById('cookieBanner');
  if (!consent && banner) {
    banner.classList.add('visible');
  }
}

function acceptCookies(type) {
  const consent = {
    essential: true,
    analytics: type === 'all',
    marketing: type === 'all',
    timestamp: new Date().toISOString()
  };
  localStorage.setItem(COOKIE_KEY, JSON.stringify(consent));
  document.getElementById('cookieBanner')?.classList.remove('visible');
  applyConsent(consent);
}

function openCookieSettings() {
  const settings = document.getElementById('cookieSettings');
  settings.style.display = settings.style.display === 'none' ? 'block' : 'none';
}

function saveCookieSettings() {
  const consent = {
    essential: true,
    analytics: document.getElementById('cookieAnalytics')?.checked || false,
    marketing: document.getElementById('cookieMarketing')?.checked || false,
    timestamp: new Date().toISOString()
  };
  localStorage.setItem(COOKIE_KEY, JSON.stringify(consent));
  document.getElementById('cookieBanner')?.classList.remove('visible');
  applyConsent(consent);
}

function applyConsent(consent) {
  if (consent.analytics) {
    // Load analytics scripts here if needed
  }
  if (consent.marketing) {
    // Load marketing scripts here if needed
  }
}

// Payment scripts are ESSENTIAL (not marketing) — load immediately
// GDPR Art. 6(1)(b): necessary for contract performance
function loadPaymentScripts() {
  if (!document.getElementById('stripeScript')) {
    var s = document.createElement('script');
    s.id = 'stripeScript';
    s.src = 'https://js.stripe.com/v3/';
    s.defer = true;
    s.onload = function() {
      if (typeof initStripe === 'function') initStripe();
    };
    document.head.appendChild(s);
  }
  if (!document.getElementById('paypalScript')) {
    var p = document.createElement('script');
    p.id = 'paypalScript';
    p.src = 'https://www.paypal.com/sdk/js?client-id=***REDACTED***&currency=EUR&locale=de_DE';
    p.defer = true;
    p.onload = function() {
      if (typeof initPayPal === 'function') initPayPal();
    };
    document.head.appendChild(p);
  }
}
// Load payment when user reaches booking step (lazy but available)
loadPaymentScripts();

// Show banner on load
document.addEventListener('DOMContentLoaded', () => {
  showCookieBanner();
  const existing = getCookieConsent();
  if (existing) applyConsent(existing);
});

// Global functions
window.acceptCookies = acceptCookies;
window.openCookieSettings = openCookieSettings;
window.saveCookieSettings = saveCookieSettings;

/* ================================================================ ACCESSIBILITY */
const a11yToggle = document.getElementById('a11yToggle');
const a11yPanel = document.getElementById('a11yPanel');

a11yToggle?.addEventListener('click', () => {
  a11yPanel?.classList.toggle('open');
});

let currentFontSize = 100;

function a11yAction(action, value) {
  const body = document.body;
  switch (action) {
    case 'fontSize':
      if (value === 'increase' && currentFontSize < 150) currentFontSize += 10;
      if (value === 'decrease' && currentFontSize > 70) currentFontSize -= 10;
      if (value === 'reset') currentFontSize = 100;
      body.style.fontSize = currentFontSize + '%';
      break;
    case 'contrast':
      body.classList.toggle('a11y-high-contrast');
      break;
    case 'links':
      body.classList.toggle('a11y-highlight-links');
      break;
    case 'readable':
      body.classList.toggle('a11y-readable-font');
      break;
    case 'animations':
      body.classList.toggle('a11y-no-animations');
      break;
    case 'reset':
      currentFontSize = 100;
      body.style.fontSize = '';
      body.classList.remove('a11y-high-contrast', 'a11y-highlight-links', 'a11y-readable-font', 'a11y-no-animations');
      break;
  }
}

window.a11yAction = a11yAction;
