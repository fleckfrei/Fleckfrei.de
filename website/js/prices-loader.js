/**
 * Pricing-Loader für fleckfrei.de Website.
 * Lädt Live-Preise aus app.fleckfrei.de DB und überschreibt hardcoded Werte.
 */
(function () {
  const API = 'https://app.fleckfrei.de/api/prices-public.php';
  const CACHE_KEY = 'flk_prices_v1';
  const CACHE_TTL = 5 * 60 * 1000; // 5 Min

  function applyPrices(d) {
    if (!d || !d.success) return;

    // 1) Update Service-Card "ab X EUR" subtitles
    if (d.min_prices) {
      const map = { private: 'private', str: 'str', office: 'office' };
      for (const [type, sel] of Object.entries(map)) {
        const el = document.querySelector(`.book__dynamic-price[data-type="${sel}"]`);
        if (el && d.min_prices[type]) {
          el.textContent = 'ab ' + d.min_prices[type].toFixed(2).replace('.', ',') + ' EUR';
        }
      }
    }

    // 2) Update Add-on labels (Pflegemittel, Wäsche, etc.)
    if (d.addons && d.addons.length) {
      document.querySelectorAll('.book__check').forEach((label) => {
        const cb = label.querySelector('input[type="checkbox"][name="extras"]');
        if (!cb) return;
        const value = cb.value;
        // Match by name-keywords — best-effort
        const found = d.addons.find(a => {
          const n = (a.name || '').toLowerCase();
          if (value === 'pflegemittel' && n.includes('reinigungsmittel')) return true;
          if (value === 'waesche' && n.includes('wäsch')) return true;
          if (value === 'expressSlot' && n.includes('express')) return true;
          if (value === 'priority' && n.includes('priorit')) return true;
          return n.includes(value.toLowerCase());
        });
        if (!found) return;
        const span = label.querySelector('span');
        if (!span) return;
        const unit = found.pricing_type === 'per_hour' ? '/h' :
                     found.pricing_type === 'percentage' ? '%' :
                     ' einmalig';
        const priceFmt = (+found.price).toFixed(2).replace('.', ',');
        span.textContent = `${found.icon ? found.icon + ' ' : ''}${found.name} (+${priceFmt} EUR${unit})`;
        label.dataset.priceLoaded = '1';
      });
    }

    // 3) Expose globally for pricing-engine.js to use
    window.__flkPrices = d;
    document.dispatchEvent(new CustomEvent('flk:prices-loaded', {detail: d}));
  }

  async function load() {
    // Try cache first
    try {
      const cached = JSON.parse(localStorage.getItem(CACHE_KEY) || '{}');
      if (cached.ts && Date.now() - cached.ts < CACHE_TTL && cached.data) {
        applyPrices(cached.data);
      }
    } catch (e) {}

    // Fetch fresh
    try {
      const r = await fetch(API);
      const d = await r.json();
      if (d.success) {
        applyPrices(d);
        try { localStorage.setItem(CACHE_KEY, JSON.stringify({ts: Date.now(), data: d})); } catch (e) {}
      }
    } catch (e) {
      console.warn('[flk-prices]', e);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', load);
  } else {
    load();
  }
})();
