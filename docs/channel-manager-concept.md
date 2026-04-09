# MAX Co-Host Channel Manager — Konzept

## Problem
Smoobu kostet 30+ EUR/Monat, bietet aber nur iCal Sync + einfaches Dashboard. Wir haben 80% davon schon gebaut.

## Vision
Lightweight Channel Manager direkt in der Fleckfrei-Plattform. Kein Drittanbieter, keine monatlichen Kosten, volle Kontrolle.

---

## Architektur (6 Layer)

### Layer 1: iCal Sync (FERTIG)
- **Import:** `api/ical-import.php` — Cron-fähig, alle 30min
- **Export:** `api/ical-export.php` — Eigene Feeds für OTAs
- Airbnb, Booking.com, VRBO, Agoda unterstützen iCal
- 600 Buchungen bereits importiert (Sveas Properties)

### Layer 2: Booking Normalization
- Einheitliches Buchungsformat aus verschiedenen iCal-Quellen
- Mapping: Airbnb Reservation Codes, Booking.com IDs, VRBO Refs
- Status-Normalisierung: CHECK_IN, CHECK_OUT, BLOCKED, BOOKING, MAINTENANCE
- Gast-Extraktion aus SUMMARY/DESCRIPTION Feldern

### Layer 3: Availability Matrix
- Kalender-Grid über alle Properties + Channels
- Konflikterkennung (Doppelbuchungen)
- Manuelles Blocken (Wartung, Eigennutzung)
- Traffic-Light: Grün (frei), Gelb (Check-in/out), Rot (belegt)

### Layer 4: Rate Management (Phase 2)
- Basispreise pro Property + Saison
- Saisonale Preisregeln (Sommer +20%, Weihnachten +40%)
- Mindestaufenthalt pro Saison
- Wochenend-Aufschläge
- Dynamische Preise nach Auslastung (>80% = +15%)

### Layer 5: Direct Booking Widget (Phase 2)
- Einbettbares Widget für la-renting.de
- Keine OTA-Provision (spart 15-20%)
- Stripe + PayPal Payment (bereits integriert)
- Automatische Rechnungsstellung (bereits gebaut)
- iCal-Export für Rückspielung an OTAs

### Layer 6: OTA API Integration (Phase 3)
- Booking.com Connectivity Partner API
- Airbnb Professional Hosting Tools API
- Direkte Preissynchronisation
- Echtzeit-Verfügbarkeitsupdates

---

## Smoobu vs. MAX Co-Host CM

| Feature | Smoobu | MAX Co-Host |
|---------|--------|-------------|
| Monatliche Kosten | 30+ EUR | 0 EUR |
| iCal Sync | Ja | Ja (gebaut) |
| Buchungsimport | Ja | Ja (gebaut) |
| Kalenderansicht | Ja | Phase 2 |
| Preismanagement | Ja | Phase 2 |
| Direct Booking | Basic | Phase 2 |
| OTA API | Booking/Airbnb | Phase 3 |
| Rechnungen | Nein | Ja (gebaut) |
| Telegram Alerts | Nein | Ja (gebaut) |
| OSINT/Gästescreening | Nein | Ja (gebaut) |
| Eigene Automationen | Nein | Ja (n8n) |
| Datenkontrolle | Extern | 100% eigen |

---

## Roadmap

### Phase 1 — FERTIG
- [x] iCal Import + Export
- [x] Feed Management UI
- [x] Cron-fähiger Sync (alle 30min)
- [x] 600 Buchungen importiert
- [x] Smoobu API Anbindung (Fallback)

### Phase 2 — Next
- [ ] Availability Matrix (Kalender-Grid)
- [ ] Konflikterkennung + Alerts
- [ ] Basispreis-Management
- [ ] Direct Booking Widget
- [ ] Gastdaten-Extraktion aus iCal

### Phase 3 — Später
- [ ] Booking.com API
- [ ] Airbnb API
- [ ] Dynamische Preise
- [ ] Multi-User (Property Manager)
- [ ] Revenue Analytics

---

## Tech Stack
- **Backend:** PHP 8.x (bestehendes Fleckfrei Framework)
- **DB:** MySQL (Hostinger)
- **Frontend:** Vanilla JS + TailwindCSS (bestehend)
- **Cron:** Hostinger Cron / VPS Crontab
- **Notifications:** Telegram Bot (gebaut)
- **Payments:** Stripe + PayPal (gebaut)
- **Deployment:** SCP via deploy.sh
