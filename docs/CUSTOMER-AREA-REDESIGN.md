# Customer Area Redesign — Analyse & Plan

**Ziel:** Das Kundenkonto in `app.fleckfrei.de` **neu bauen** basierend auf
dem bestehenden `app.la-renting.de` customer-Bereich, im **Helpling-Layout-Style**
mit **Fleckfrei-eigenen Brand-Farben** (nicht Helpling Teal kopieren).

**Harte Regel:** NICHTS aus dem bestehenden Code von `app.la-renting.de` oder
`app.fleckfrei.de` löschen. Nur analysieren, dann **neue Files parallel** zum
bestehenden Code bauen.

**Stand:** 2026-04-10

---

## 1. Was ist auf `app.la-renting.de/customer/` vorhanden

**Pfad auf Hostinger shared hosting:**
`/home/u860899303/domains/la-renting.de/public_html/app/customer/`

**Framework-Stack:**
- Custom PHP (`include 'authenticate.php'`, `include '../sidebar.php'`, `include '../menu.php'`)
- **Materio Bootstrap Admin Template** (Bootstrap 5)
- Font: **Public Sans** (Google Fonts)
- Icons: **Boxicons** + Font Awesome Pro 5.10
- Libs: DataTables, perfect-scrollbar, Chart.js, FullCalendar
- Brand color in inline-CSS: **`#309278`** (forest green, fast identisch zu Helplings `#306C60`)

**Customer Navigation (rollenbasiert, via `$user_row['customer_type']`):**

| Role | Menu Items |
|---|---|
| Regular | Dashboard · Work-Hour · Profile · Invoices |
| Airbnb | Dashboard · **Services** · Work-Hour · Profile · Invoices |
| Host | Dashboard · **Services** · **Work-Hour-Host** · Profile · Invoices |

**PHP Files im `customer/`-Ordner (40+ files):**

| File | Größe | Zweck |
|---|---|---|
| `index.php` | 18 K | Jobs-Dashboard (Startseite ist die Jobs-Liste) |
| `header.php` | 4 K | Layout-Header mit Invoice-Lock (auto-redirect → checkout bei offener Rechnung) |
| `modals.php` | 99 K | Alle Bootstrap modals (gigantisch — vermutlich gesamter Booking/Edit-Flow) |
| `addJob.php` | 28 K | Einzel-Buchung |
| `addJobRecurence.php` | 13 K | **Wiederkehrende Buchung** — weekly/biweekly/monthly |
| `cancel-job.php` | 3 K | Stornierung mit Cancel-Fee-Logik (< 24h = 2× Service-Price) |
| `invoices.php` | 4 K | Rechnungsliste (inline CSS mit Brand-Color `#309278`) |
| `checkout.php` | 6 K | Pflicht-Checkout bei offener Rechnung |
| `pay-now.php` | — | Einzelne Rechnungsbezahlung |
| `add-invoice-payment.php` | 4 K | Zahlungsdatum/-methode erfassen |
| `payment-success.php`, `payment-cancel.php` | — | Stripe/PayPal callbacks |
| `profile.php` | — | Profil anzeigen/bearbeiten (mit tabs) |
| `updateCustomer.php` | — | Profil-Update POST handler |
| `service.php` | — | Services-Katalog (nur für Airbnb/Host) |
| `add-service.php`, `addService.php`, `edit-service.php` | 18–20 K | Service CRUD |
| `view-service-address.php` | — | Service-Adress-Detail |
| `addServiceImage.php` | 2 K | Service-Bild Upload |
| `ical-feeds.php` | 6 K | **iCal-Integration** — Airbnb/Booking.com Import-Export |
| `addContactPerson.php`, `updateContactPerson.php`, `updateCP.php` | — | Ansprechpartner-Mgmt |
| `addDocument.php`, `updateDocument.php` | — | Dokument-Uploads |
| `addVideoLink.php`, `updateVideoLink.php` | — | Video-Links (Tutorials für Cleaner?) |
| `work-hour.php`, `work-hour-host.php` | — | Stunden-Erfassung (mit Host-spezifischer Variante) |
| `update-calendar.php`, `update-job-msg.php` | — | Kalender + Job-Notizen-Update |
| `fetch.php` | 3 K | AJAX-Endpoint (vermutlich für DataTables) |
| `logout.php` | 268 B | Session destroy |
| `authenticate.php` | 483 B | Auth guard (required auf jeder page) |
| `validation.php` | — | Form-Validation helpers |
| `delete.php` | 7 K | Generischer Delete-Handler |
| `footer.php` | 2 K | Layout-Footer |

**Features die das alte la-renting customer HAT, die bestehende fleckfrei-admin customer NICHT hat:**

1. **Recurring bookings** (`addJobRecurence.php`) — weekly/biweekly/monthly Serien
2. **iCal-Feeds** (`ical-feeds.php`) — Airbnb/Booking.com integration
3. **Cancel-Fee-Logik** — storniert < 24h vor Termin kostet 2× den Service-Preis
4. **Invoice-Lock** — bei offener Rechnung erzwingt header.php automatischen Redirect zu `checkout.php` bis bezahlt
5. **Service-Katalog für Hosts** (Airbnb/Host können eigene Services verwalten)
6. **Contact Persons** — Ansprechpartner pro Service/Adresse
7. **Video Links** — Tutorial-Videos (Handlungsanweisungen für Cleaner?)
8. **Work-Hour-Host** — Separate Stundenerfassung für Hosts
9. **Pay-now flow** mit Stripe/PayPal callbacks

---

## 2. Was `app.fleckfrei.de/customer/` heute hat

**Pfad:** `/Users/fleckfrei.de/src/fleckfrei-admin/customer/` (live: Hostinger `/domains/app.fleckfrei.de/public_html/customer/`)

**Framework:** PHP 8.3 + **Tailwind CSS** + eigene `layout.php`

**PHP Files:**

| File | Lines | Zweck |
|---|---|---|
| `index.php` | 118 | Dashboard (Stats, upcoming mini-list, recent-done mini-list, booking CTA, contact) |
| `jobs.php` | 79 | Jobs-Liste mit 3 Tabs (Kommend/Erledigt/Storniert) |
| `booking.php` | 255 | **Booking flow** (wahrscheinlich größer/komplexer) |
| `invoices.php` | 148 | Rechnungen |
| `profile.php` | 103 | Profil |
| `documents.php` | 43 | Dokumente |
| `messages.php` | 93 | Nachrichten / Chat |
| `workhours.php` | 98 | Arbeitsstunden |
| `rate.php` | 82 | Bewertung abgeben |

**Features die fleckfrei-admin HAT, die das alte la-renting customer NICHT hat:**

1. **Rate / Review** — Kunde kann Partner bewerten
2. **Messages / Chat** — Nachrichten-System
3. **Documents** (als eigene Seite)
4. **Tailwind statt Bootstrap** — moderner, leichter
5. **Granulare Rechte** via `customerCan()` helper
6. **Stats-Dashboard** (Helpling hat das nicht in customer/orders, aber als home)

---

## 3. Ziel-Feature-Matrix (neu)

Das neue fleckfrei Customer Account soll eine **Union aller Features** sein — das
Beste aus beiden Welten, im Helpling-Layout-Stil, mit Fleckfrei-Brand.

| Feature | alt la-renting | neu fleckfrei | Helpling-Ref | Status |
|---|---|---|---|---|
| Dashboard (stats + uebersicht) | ✘ | ✔ | Home | ✅ BEHALTEN |
| Jobs-Liste (Vergangenheit/Zukunft tabs) | ✔ index.php | ✔ jobs.php | Alle Termine | 🔄 REDESIGN (Helpling layout) |
| **Recurring Buchungen** | ✔ | ✘ | ~ | 🆕 NEU |
| **iCal-Feeds** (Airbnb import/export) | ✔ | ✘ | ✘ | 🆕 NEU |
| **Cancel-Fee-Logik** (<24h = 2×) | ✔ | ✘ | ~ | 🆕 NEU |
| **Invoice-Lock / Pflicht-Checkout** | ✔ | ✘ | ~ | 🆕 NEU |
| Rechnungen | ✔ invoices.php | ✔ invoices.php | Rechnungen | 🔄 REDESIGN (Dienstleistung/Andere tabs) |
| Bezahlen (Stripe/PayPal/SEPA) | ✔ pay-now.php | ~ (in booking.php) | Zahlung in Step 3 | 🔄 AUSBAUEN |
| Profil | ✔ profile.php | ✔ profile.php | Kontoeinstellungen | 🔄 REDESIGN |
| Services (für Hosts) | ✔ | ✘ | ✘ | 🆕 NEU für Airbnb/Host |
| **Contact Persons** | ✔ | ✘ | ✘ | 🆕 NEU für Host |
| **Video-Links** (cleaning tutorials) | ✔ | ✘ | ✘ | 🆕 NEU (nice-to-have) |
| Work-Hour (Stundenerfassung) | ✔ | ✔ | ✘ | 🔄 REDESIGN |
| Dokumente | ~ | ✔ | ✘ | 🔄 REDESIGN |
| Rate / Review | ✘ | ✔ | ~ (Ratings sichtbar) | ✅ BEHALTEN |
| Chat / Messages | ✘ | ✔ | Chat | ✅ BEHALTEN + Helpling-style empty state |
| **Referral (Code teilen)** | ✘ | ✘ | Sauber sparen -50% | 🆕 NEU |
| **Ein Problem melden** | ✘ | ✘ | Meldungen + Hilfe-Center | 🆕 NEU |
| **Hilfe-Center** | ✘ | ✘ | Hilfe-Center | 🆕 NEU |

---

## 4. Brand Colors — Entscheidung

- **Helpling**: `#306C60` (primary teal)
- **La-Renting alt**: `#309278` (forest green, in inline-CSS)
- **Fleckfrei-admin**: nutzt Tailwind `text-brand` / `bg-brand` — in der Tailwind-Config definiert (NOT CHECKED YET — TODO: `tailwind.config.js` lesen)

**Vorschlag:**
- **PRIMARY** bleibt was Fleckfrei-admin heute hat (respektiert Memory: "NO Helpling-style")
- Das Layout-Pattern (Cards, Tabs, Stepper, Sidebar-Summary, Empty States) von Helpling übernehmen
- Farben **eigen** — kein Teal-Kopieren

---

## 5. Navigation-Struktur (neu, basierend auf la-renting Roles)

```
Customer (Regular)
├── Dashboard    ← stats + nächster job + offene Rechnung
├── Meine Termine    ← Vergangenheit/Zukunft tabs (wie Helpling "Alle Termine")
├── Rechnungen   ← Dienstleistung/Andere tabs
├── Messages / Chat
├── Profil
└── Hilfe  ← Problem melden · FAQ · Support

Customer (Airbnb/Host) — extra:
├── Services     ← eigene Service-Katalog
├── Kalender     ← iCal-Feeds + externe Calendars
└── Kontaktpersonen   ← je Adresse
```

---

## 6. Build-Plan (strictly additive — nichts löschen)

**Prinzip:** Alle neuen Files bekommen ein Suffix oder eigenen Ordner, damit
bestehende pages unangetastet bleiben. Option:
- `customer-v2/` neuer Ordner parallel zu `customer/`
- ODER: neue Files mit `.v2.php` Suffix (`jobs.v2.php`)
- ODER: neue Files direkt in `customer/` aber mit neuen Namen (`arrivals.php` ist auch neu und liegt in `admin/`)

**Empfehlung:** Neuer Ordner **`customer/v2/`** — klar trennbar, einfach zu routen über nginx/.htaccess oder einen simplen Redirect-Switch.

### Iteration 1 — Customer Pages (2-3 neue Files, keine Breaking Changes)
1. **`customer/v2/jobs.php`** — Helpling-Layout für Jobs:
   - Header: "Termindetails"
   - "Abgesagte Termine anzeigen" toggle
   - Tabs Vergangenheit / Zukunft
   - Card-Layout pro Job: Datum big, Service, Partner avatar+name, Preis, Status-Timeline, Actions
   - Empty state illustrations
2. **`customer/v2/invoices.php`** — Helpling "Rechnungen" Style:
   - Tabs Dienstleistung / Andere
   - Card-Layout
   - Pay-now Button wenn offen
3. **`customer/v2/referral.php`** — NEU:
   - Code teilen (WhatsApp / FB / Email Share Buttons)
   - "So funktioniert's" Stepper

### Iteration 2 — Host/Airbnb Features (wenn Iteration 1 abgenommen)
4. **`customer/v2/services.php`** — Service-Katalog für Hosts
5. **`customer/v2/calendar.php`** — iCal-Feeds management
6. **`customer/v2/contacts.php`** — Ansprechpartner pro Adresse

### Iteration 3 — Invoice-Lock + Pay-Flow
7. **`customer/v2/checkout.php`** — Pflicht-Checkout bei offener Rechnung
8. **`customer/v2/pay.php`** — Stripe/SEPA/PayPal flow
9. **header.php Extension**: invoice-lock helper (optional auto-redirect)

### Iteration 4 — Recurring Bookings + Cancel-Fee
10. **`customer/v2/booking.php`** — Mit Häufigkeits-Wahl (Einmalig / Wöchentlich / Zweiwöchentlich)
11. **Cancel-Fee-Logik** portiert aus la-renting `cancel-job.php`

### Iteration 5 — Hilfe-Bereich
12. **`customer/v2/support.php`** — "Ein Problem melden" + Ticket list
13. **`customer/v2/help.php`** — Hilfe-Center / FAQ

### Iteration 6 — Migration (wenn v2 stabil & getestet)
14. Routing switch: `customer/index.php` → redirect zu `customer/v2/index.php` (oder umgekehrt). Alte Files bleiben als Backup verfügbar unter z.B. `customer/_legacy/`.

---

## 7. Offene Fragen für dich

1. **Brand color**: Welche ist deine echte Fleckfrei-Primary? Ich checke `tailwind.config.js` — oder sag mir den Hex.
2. **Navigation**: Bleibt Sidebar (wie aktuell fleckfrei-admin) oder Top-Nav (wie Helpling)? Top-Nav ist ein viel größerer Change (touches jede Page).
3. **Neuer Ordner `customer/v2/` ok?** Oder möchtest du eine andere Struktur (z.B. `new/`, `redesign/`)?
4. **Iteration 1 Punkt 1 starten?** `customer/v2/jobs.php` als erster sichtbarer Win.
5. **Sollen bestehende `customer/*.php` einen Link zur v2-Version bekommen** (z.B. Banner "Neue Version ausprobieren →")?
6. **Was ist mit `app.la-renting.de` selbst?** Bleibt es unverändert (nur Referenz) oder soll es langfristig auf die neue Codebase migriert werden?
