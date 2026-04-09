/**
 * Fleckfrei Dynamic Pricing Engine
 *
 * Calculates price based on:
 * - Customer type (private, office, str)
 * - Property size (sqm)
 * - Number of rooms
 * - Booking frequency
 * - Urgency (same-day surcharge)
 * - Loyalty (number of past bookings)
 * - Add-ons
 * - Time of day / season
 * - Distance from Berlin center (Luftlinie)
 * - Minimum booking hours based on distance
 * - Market price benchmarking (Berlin 30km radius)
 */
'use strict';

const PricingEngine = {

  // ============================================================
  // BASE RATES (EUR netto per sqm)
  // ============================================================
  rates: {
    private: {
      perSqm: 1.15,          // EUR per m2
      minPrice: 56.58,        // minimum per visit
      maxPrice: 250.00        // cap per visit
    },
    office: {
      perSqm: 0.95,          // offices: slightly lower per m2
      minPrice: 56.58,
      maxPrice: 400.00,
      roomSurcharge: 3.50     // per additional room above 3
    },
    str: {
      // fixed tiers by apartment size
      tiers: [
        { maxSqm: 40,  price: 56.58 },   // Studio
        { maxSqm: 60,  price: 56.58 },   // 1-2 Zimmer
        { maxSqm: 80,  price: 72.00 },   // 2-3 Zimmer
        { maxSqm: 100, price: 89.00 },   // 3-4 Zimmer
        { maxSqm: 150, price: 119.00 },  // 4+ Zimmer
        { maxSqm: 9999, price: 149.00 }  // Villa/Loft
      ],
      volumeDiscount: [
        { minProperties: 1, discount: 0 },
        { minProperties: 3, discount: 0.08 },   // 8% ab 3 Wohnungen
        { minProperties: 5, discount: 0.12 },   // 12% ab 5
        { minProperties: 10, discount: 0.18 }   // 18% ab 10
      ]
    }
  },

  // ============================================================
  // FREQUENCY DISCOUNTS
  // ============================================================
  frequencyDiscounts: {
    einmalig: 0,
    woechentlich: 0.15,       // 15% weekly discount
    '2-woechentlich': 0.10,   // 10% bi-weekly
    monatlich: 0.05           // 5% monthly
  },

  // ============================================================
  // LOYALTY DISCOUNTS (based on past bookings)
  // ============================================================
  loyaltyTiers: [
    { minBookings: 0,  discount: 0,    label: 'Neukunde' },
    { minBookings: 3,  discount: 0.03, label: 'Stammkunde' },
    { minBookings: 5,  discount: 0.05, label: 'Stammkunde+' },
    { minBookings: 10, discount: 0.08, label: 'Premium' },
    { minBookings: 25, discount: 0.12, label: 'VIP' },
    { minBookings: 50, discount: 0.15, label: 'Partner' }
  ],

  // ============================================================
  // DISTANCE RULES (Luftlinie from Berlin Mitte)
  // Berlin center: 52.5200° N, 13.4050° E
  // ============================================================
  berlinCenter: { lat: 52.5200, lng: 13.4050 },
  maxRadius: 30, // km — service area limit

  distanceRules: [
    { maxKm: 5,  minHours: 2, surchargePerKm: 0,    label: 'Kerngebiet' },
    { maxKm: 10, minHours: 4, surchargePerKm: 1.50, label: 'Stadtgebiet' },
    { maxKm: 20, minHours: 6, surchargePerKm: 2.00, label: 'Erweitertes Gebiet' },
    { maxKm: 30, minHours: 6, surchargePerKm: 2.50, label: 'Randgebiet' }
  ],

  // PLZ → approximate distance from Berlin Mitte (km Luftlinie)
  // Major Berlin PLZ zones for quick lookup without geocoding
  plzDistanceMap: {
    '101': 1, '102': 2, '103': 3, '104': 3, '105': 4,  // Mitte, Prenzlberg
    '106': 4, '107': 5, '108': 4, '109': 5,              // Friedrichshain, Lichtenberg
    '120': 3, '121': 4, '122': 6, '123': 8, '124': 7,   // Steglitz, Tempelhof
    '125': 10, '126': 12,                                  // Reinickendorf
    '130': 5, '131': 8, '132': 10, '133': 12,            // Spandau, Wannsee
    '134': 14, '135': 15,                                  // Spandau Rand
    '136': 16, '137': 18, '138': 20, '139': 22,          // Aussengebiete
    '140': 8, '141': 10, '142': 12, '143': 14,           // Zehlendorf
    '144': 15, '145': 17, '146': 18, '147': 20,          // Potsdam Naehe
    '148': 22, '149': 25,                                  // Brandenburg
  },

  // ============================================================
  // MARKET PRICE BENCHMARKS (Berlin, updated periodically)
  // Source: scraped from berliner-reinigungsfirmen, check24, etc.
  // ============================================================
  marketBenchmarks: {
    lastUpdated: '2026-04-06',
    berlin: {
      avgPerSqm: 1.10,        // market avg EUR/m2 in Berlin
      avgPerHour: 28.50,       // market avg EUR/h
      minPerVisit: 45.00,      // market minimum
      maxPerVisit: 180.00,     // market upper range
      strAvgTurnover: 65.00 // market avg for Airbnb turnover
    },
    // Fleckfrei positioning: slightly below market for volume play
    positionFactor: 1.05  // 10% below market average
  },

  // ============================================================
  // URGENCY SURCHARGE
  // ============================================================
  urgencySurcharge: {
    sameDay: 0.25,      // +25% same day
    nextDay: 0.15,      // +15% next day
    within3Days: 0.05,  // +5% within 3 days
    normal: 0           // 4+ days: no surcharge
  },

  // ============================================================
  // TIME-BASED ADJUSTMENTS
  // ============================================================
  timeAdjustments: {
    earlyMorning: { hours: [6, 7], surcharge: 0.10 },    // 6-8: +10%
    evening: { hours: [18, 19, 20], surcharge: 0.10 },   // 18-21: +10%
    weekend: { surcharge: 0.05 }                           // Sat/Sun: +5%
  },

  // ============================================================
  // ADD-ONS (fixed prices)
  // ============================================================
  addons: {
    pflegemittel: {
      label: 'Pflegemittel-Flatrate',
      price: 12.99,
      type: 'monthly',
      description: 'Eigene Fleckfrei Produkte monatlich'
    },
    waesche: {
      label: 'Waesche & Handtuecher',
      price: 13.40,
      type: 'perKg',
      description: 'Waschen, Trocknen, Falten'
    },
    schluesselbox: {
      label: 'Metall-Schluesselbox',
      price: 23.99,
      type: 'onetime',
      description: 'Sichere Schluesseluebergabe'
    },
    fotodoku: {
      label: 'Foto-Dokumentation',
      price: 8.00,
      type: 'perVisit',
      description: 'Vorher/Nachher Fotos'
    },
    waeschetransport: {
      label: 'Waeschetransport',
      price: 30.00,
      type: 'perVisit',
      description: 'Abholung & Lieferung'
    },
    expressSlot: {
      label: 'Express-Zeitfenster',
      price: 15.00,
      type: 'perVisit',
      description: 'Garantiertes 2h-Fenster'
    }
  },

  // ============================================================
  // MAIN CALCULATION
  // ============================================================
  calculate(params) {
    const {
      customerType = 'private',  // private | office | str
      sqm = 60,
      rooms = 2,
      frequency = 'einmalig',
      date = null,               // booking date
      time = null,               // booking time (HH:MM)
      pastBookings = 0,          // loyalty
      numProperties = 1,         // for str hosts
      selectedAddons = [],       // array of addon keys
      plz = null,                // postal code for distance calc
      lat = null,                // latitude (if available)
      lng = null                 // longitude (if available)
    } = params;

    const result = {
      customerType,
      breakdown: [],
      basePrice: 0,
      discounts: [],
      surcharges: [],
      addonsTotal: 0,
      addonsList: [],
      subtotal: 0,
      total: 0,
      loyaltyTier: '',
      savings: 0,
      distance: null,            // km from Berlin center
      minHours: 2,               // minimum booking duration
      distanceZone: '',          // zone label
      outOfRange: false          // true if > 30km
    };

    // Step 1: Base price
    result.basePrice = this._calculateBase(customerType, sqm, rooms, numProperties);
    result.breakdown.push({ label: 'Basispreis', amount: result.basePrice });

    // Step 1b: Distance calculation & minimum hours
    const dist = this._calculateDistance(plz, lat, lng);
    result.distance = dist.km;
    result.distanceZone = dist.zone;
    result.minHours = dist.minHours;
    result.outOfRange = dist.km > this.maxRadius;

    if (dist.km > this.maxRadius) {
      result.breakdown.push({ label: `Ausserhalb Servicegebiet (${dist.km.toFixed(1)}km)`, amount: 0 });
    } else if (dist.surcharge > 0) {
      result.surcharges.push({
        label: `Anfahrt ${dist.zone} (${dist.km.toFixed(1)}km)`,
        amount: dist.surcharge
      });
    }
    if (dist.minHours > 2) {
      result.breakdown.push({ label: `Mindestbuchung: ${dist.minHours}h`, amount: 0 });
    }

    // Step 2: Frequency discount
    const freqDiscount = this.frequencyDiscounts[frequency] || 0;
    if (freqDiscount > 0) {
      const freqAmount = result.basePrice * freqDiscount;
      result.discounts.push({
        label: `${frequency} (-${(freqDiscount * 100).toFixed(0)}%)`,
        amount: -freqAmount
      });
    }

    // Step 3: Loyalty discount
    const loyalty = this._getLoyaltyTier(pastBookings);
    result.loyaltyTier = loyalty.label;
    if (loyalty.discount > 0) {
      const loyaltyAmount = result.basePrice * loyalty.discount;
      result.discounts.push({
        label: `${loyalty.label} (-${(loyalty.discount * 100).toFixed(0)}%)`,
        amount: -loyaltyAmount
      });
    }

    // Step 4: Urgency surcharge
    if (date) {
      const urgency = this._getUrgency(date);
      if (urgency.surcharge > 0) {
        const urgAmount = result.basePrice * urgency.surcharge;
        result.surcharges.push({
          label: `${urgency.label} (+${(urgency.surcharge * 100).toFixed(0)}%)`,
          amount: urgAmount
        });
      }
    }

    // Step 5: Time surcharge
    if (time) {
      const timeSurcharge = this._getTimeSurcharge(time, date);
      if (timeSurcharge.surcharge > 0) {
        const timeAmount = result.basePrice * timeSurcharge.surcharge;
        result.surcharges.push({
          label: `${timeSurcharge.label} (+${(timeSurcharge.surcharge * 100).toFixed(0)}%)`,
          amount: timeAmount
        });
      }
    }

    // Step 6: Calculate subtotal
    const totalDiscounts = result.discounts.reduce((sum, d) => sum + d.amount, 0);
    const totalSurcharges = result.surcharges.reduce((sum, s) => sum + s.amount, 0);
    result.subtotal = result.basePrice + totalDiscounts + totalSurcharges;
    result.savings = Math.abs(totalDiscounts);

    // Step 7: Add-ons
    selectedAddons.forEach(key => {
      const addon = this.addons[key];
      if (addon) {
        result.addonsList.push({ label: addon.label, amount: addon.price, type: addon.type });
        if (addon.type === 'perVisit' || addon.type === 'onetime') {
          result.addonsTotal += addon.price;
        }
      }
    });

    // Step 8: Total
    result.total = Math.max(result.subtotal + result.addonsTotal, 0);
    result.total = Math.round(result.total * 100) / 100;

    return result;
  },

  // ============================================================
  // PRIVATE HELPERS
  // ============================================================
  _calculateBase(type, sqm, rooms, numProperties) {
    switch (type) {
      case 'private': {
        const rate = this.rates.private;
        return Math.max(Math.min(sqm * rate.perSqm, rate.maxPrice), rate.minPrice);
      }
      case 'office': {
        const rate = this.rates.office;
        let price = sqm * rate.perSqm;
        if (rooms > 3) price += (rooms - 3) * rate.roomSurcharge;
        return Math.max(Math.min(price, rate.maxPrice), rate.minPrice);
      }
      case 'str': {
        const tier = this.rates.str.tiers.find(t => sqm <= t.maxSqm);
        let price = tier ? tier.price : 135.00;
        // Multi-property discount
        const mpd = [...this.rates.str.volumeDiscount]
          .reverse()
          .find(d => numProperties >= d.minProperties);
        if (mpd && mpd.discount > 0) {
          price *= (1 - mpd.discount);
        }
        return price;
      }
      default:
        return 56.58;
    }
  },

  _getLoyaltyTier(pastBookings) {
    return [...this.loyaltyTiers].reverse().find(t => pastBookings >= t.minBookings)
      || this.loyaltyTiers[0];
  },

  _getUrgency(dateStr) {
    const bookDate = new Date(dateStr);
    const now = new Date();
    const diffDays = Math.ceil((bookDate - now) / (1000 * 60 * 60 * 24));

    if (diffDays <= 0) return { surcharge: this.urgencySurcharge.sameDay, label: 'Heute' };
    if (diffDays === 1) return { surcharge: this.urgencySurcharge.nextDay, label: 'Morgen' };
    if (diffDays <= 3) return { surcharge: this.urgencySurcharge.within3Days, label: 'Kurzfristig' };
    return { surcharge: 0, label: '' };
  },

  _getTimeSurcharge(timeStr, dateStr) {
    const hour = parseInt(timeStr.split(':')[0]);
    let surcharge = 0;
    let label = '';

    // Early morning
    if (this.timeAdjustments.earlyMorning.hours.includes(hour)) {
      surcharge += this.timeAdjustments.earlyMorning.surcharge;
      label = 'Frueh-Termin';
    }
    // Evening
    if (this.timeAdjustments.evening.hours.includes(hour)) {
      surcharge += this.timeAdjustments.evening.surcharge;
      label = 'Abend-Termin';
    }
    // Weekend
    if (dateStr) {
      const day = new Date(dateStr).getDay();
      if (day === 0 || day === 6) {
        surcharge += this.timeAdjustments.weekend.surcharge;
        label = label ? label + ' + Wochenende' : 'Wochenende';
      }
    }

    return { surcharge, label };
  },

  // ============================================================
  // DISTANCE HELPERS
  // ============================================================
  _calculateDistance(plz, lat, lng) {
    let km = 0;

    if (lat && lng) {
      // Haversine formula for exact distance
      km = this._haversine(this.berlinCenter.lat, this.berlinCenter.lng, lat, lng);
    } else if (plz) {
      // PLZ lookup (first 3 digits)
      const prefix = plz.toString().substring(0, 3);
      km = this.plzDistanceMap[prefix] || 15; // default 15km if unknown
    } else {
      // No location data — assume central
      return { km: 3, minHours: 2, surcharge: 0, zone: 'Kerngebiet' };
    }

    // Find matching distance rule
    const rule = this.distanceRules.find(r => km <= r.maxKm)
      || this.distanceRules[this.distanceRules.length - 1];

    const surcharge = km > 5 ? (km - 5) * rule.surchargePerKm : 0;

    return {
      km,
      minHours: rule.minHours,
      surcharge: Math.round(surcharge * 100) / 100,
      zone: rule.label
    };
  },

  _haversine(lat1, lon1, lat2, lon2) {
    const R = 6371; // Earth radius in km
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
  },

  // Extract PLZ from German address string
  extractPLZ(address) {
    const match = address.match(/\b(\d{5})\b/);
    return match ? match[1] : null;
  },

  // ============================================================
  // MARKET PRICE SCRAPING (fetch from n8n webhook)
  // ============================================================
  async updateMarketPrices() {
    try {
      const res = await fetch('https://n8n.la-renting.com/webhook/fleckfrei-market-prices');
      if (res.ok) {
        const data = await res.json();
        if (data.berlin) {
          this.marketBenchmarks.berlin = data.berlin;
          this.marketBenchmarks.lastUpdated = new Date().toISOString().split('T')[0];
          // Adjust base rates based on market
          this._adjustToMarket();
        }
      }
    } catch (e) {
      // Silently fail — use cached benchmarks
      console.warn('Market price update failed, using cached data');
    }
  },

  _adjustToMarket() {
    const market = this.marketBenchmarks.berlin;
    const factor = this.marketBenchmarks.positionFactor;
    // Keep prices 10% below market average
    this.rates.private.perSqm = Math.round(market.avgPerSqm * factor * 100) / 100;
    this.rates.office.perSqm = Math.round(market.avgPerSqm * factor * 0.80 * 100) / 100;
  },

  // ============================================================
  // FORMAT HELPERS
  // ============================================================
  formatEUR(amount) {
    return amount.toFixed(2).replace('.', ',') + ' EUR';
  },

  // Build HTML price breakdown for display
  renderBreakdown(result) {
    let html = '<div class="price-breakdown">';

    // Base
    html += `<div class="price-row"><span>${result.breakdown[0].label}</span><span>${this.formatEUR(result.basePrice)}</span></div>`;

    // Discounts
    result.discounts.forEach(d => {
      html += `<div class="price-row price-row--discount"><span>${d.label}</span><span>${this.formatEUR(d.amount)}</span></div>`;
    });

    // Surcharges
    result.surcharges.forEach(s => {
      html += `<div class="price-row price-row--surcharge"><span>${s.label}</span><span>+${this.formatEUR(s.amount)}</span></div>`;
    });

    // Subtotal
    html += `<div class="price-row price-row--sub"><span>Zwischensumme</span><span>${this.formatEUR(result.subtotal)}</span></div>`;

    // Addons
    result.addonsList.forEach(a => {
      const suffix = a.type === 'monthly' ? '/Monat' : a.type === 'perKg' ? '/kg' : '';
      html += `<div class="price-row"><span>${a.label}</span><span>${this.formatEUR(a.amount)}${suffix}</span></div>`;
    });

    // Total
    html += `<div class="price-row price-row--total"><span>Gesamt (netto)</span><span>${this.formatEUR(result.total)}</span></div>`;

    // Savings
    if (result.savings > 0) {
      html += `<div class="price-savings">Sie sparen ${this.formatEUR(result.savings)} dank ${result.loyaltyTier}-Status</div>`;
    }

    html += '</div>';
    return html;
  }
};

// Make globally available
window.PricingEngine = PricingEngine;
