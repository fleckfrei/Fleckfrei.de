# Fleckfrei Platform — Complete System Overview

**Stand: 09.04.2026 | Sessions 1-10 | 37+ Commits**

---

## A. Admin Panel (`app.fleckfrei.de`)

White-Label Business Management Platform — 36 Seiten, PHP/Tailwind/AlpineJS.

| Bereich | Seiten | Funktionen |
|---------|--------|------------|
| Dashboard | 1 | KPIs, Charts, heutige Jobs, offene Rechnungen, Quick Actions |
| Jobs Kalender | 1 | FullCalendar, Drag & Drop, Status-Management, Partner-Zuweisung |
| Kunden | 3 | CRUD, Typen (Privat/Firma/Hausverwaltung), Rechte, Adressen, Notizen |
| Partner | 3 | CRUD, Provisionen, Arbeitszeiten, GPS-Tracking, Bewertungen |
| Services | 2 | Zuordnung Kunde-Partner, Preise, Wiederkehrend, Adress-basiert |
| Rechnungen | 2 | XRechnung, PDF-Export, Bezahlt-Status, offene Posten, Inline-Edit |
| Nachrichten | 1 | Internes Chat-System (Admin/Partner/Kunde), Echtzeit |
| Arbeitszeit | 1 | Auto-Berechnung aus GPS, Start/Stop, Excel-Export, Google Drive |
| Live-Karte | 1 | Alle Partner + Jobs auf Google Maps, Echtzeit-Position |
| Buchungen | 1 | Smoobu Channel Manager (Airbnb, Booking.com, VRBO, Agoda) |
| Verfügbarkeit | 1 | Kalender-Grid pro Property, Auslastung, Preis/Nacht, Popup-Details |
| OSI Scanner | 1 | OSINT Deep Scan — 11 Module, 40+ Quellen, PDF-Report |
| Protokoll | 1 | Vollstaendiges Audit-Log aller Aktionen |
| Einstellungen | 1 | White-Label Config, Feature-Toggles, API-Keys, Webhook-URLs |
| Kunden-Portal | 5 | Jobs, Rechnungen, Nachrichten, Profil, Online-Buchung |
| Partner-Portal | 4 | Meine Jobs, Verdienst, Nachrichten, Profil |

### Tech Stack
- **Backend**: PHP 8.2, MySQL (Hostinger)
- **Frontend**: Tailwind CSS (CDN), AlpineJS, FullCalendar
- **Hosting**: Hostinger Shared (62.72.37.195), SCP Deploy
- **Auth**: Session-based, bcrypt + auto-migration
- **PWA**: Service Worker, Offline-Cache, Install-Prompt, Pull-to-Refresh

---

## B. THE VULTURE — OSI Intelligence Scanner

### Was ist es?
Ein vollstaendiges OSINT-Intelligence-System das Personen und Firmen aus oeffentlichen Quellen durchleuchtet. Eingabe: Name, Email, Telefon, Adresse, Kennzeichen, Steuer-Nr. Ergebnis: Dossier mit Risiko-Score in 10-15 Sekunden.

### Warum?
- Neue Kunden pruefen (Insolvenz? Betrug? Fake-Email?)
- Neue Partner/Mitarbeiter verifizieren
- Mietgaeste bei La-Renting checken
- Geschaeftspartner Due Diligence
- Kennzeichen-Halter ermitteln (EU-weit)

### Architektur

```
SCANNER (app.fleckfrei.de/admin/scanner.php)
    |
    +-- Quick Scan (PHP, direkt auf Hostinger)
    |   +-- Email-Analyse (MX, SPF, Pattern, Wegwerf-Check)
    |   +-- Telefon-Analyse (27 Laender, Carrier, WhatsApp)
    |   +-- Adresse -> Geocoding + Satellitenbild
    |   +-- Domain/Website (SSL, Tech-Stack, HTTP-Status)
    |   +-- DB Cross-Reference (Kunden, Partner, Jobs, Rechnungen)
    |
    +-- Deep Scan (api/osint-deep.php, 2250+ Zeilen)
    |   +-- Hunter.io (Email Verifikation, Score, Firmen-Emails)
    |   +-- VirusTotal (Domain Reputation, Malware-Check)
    |   +-- Shodan (offene Ports, Schwachstellen, ISP)
    |   +-- HIBP (Breach-Check, Passwort-Leaks)
    |   +-- Handelsregister (HRB, Geschaeftsfuehrer, Firmen)
    |   +-- Gravatar, Keybase (Profile, PGP-Keys)
    |   +-- Social Media Deep (Instagram, LinkedIn, Facebook, XING, TikTok)
    |   +-- Impressum-Validierung (Pflichtangaben, Inhaber)
    |   +-- Kennzeichen-Suche (27 Laender, EU-weit, Halter-Services)
    |   +-- Business Intel (NorthData, Bundesanzeiger, Creditreform)
    |   +-- Korrelation + Risiko-Score (LOW/MEDIUM/HIGH)
    |
    +-- VPS Tools (89.116.22.185:8900) -- THE VULTURE ENGINE
        +-- Holehe        -- Email auf 120+ Sites registriert?
        +-- Maigret       -- Username auf 2500+ Sites suchen
        +-- PhoneInfoga   -- Telefon Deep Analysis (Carrier, Typ)
        +-- SocialScan    -- Account-Existenz auf 20+ Plattformen
        +-- WHOIS Deep    -- Domain-Besitzer, Registrar, Ablauf
        +-- IntelX        -- Dark Web, Leaks, Paste-Sites
        +-- Perplexity AI -- KI-gestuetzte Web-Recherche
        +-- SearXNG       -- Echte Suchmaschine (Docker, 30+ Engines)
        +-- GHunt         -- Google Account Intelligence (Gmail)
        +-- Mullvad VPN   -- Anonymisierte Abfragen (WireGuard)
```

### Scanner-Features
- **4-Tab UI**: Ergebnis, Funde, Suche, Details
- **Echtzeit-Fortschritt**: Progress-Bar mit Stage-Indikatoren
- **PDF-Report**: Druckbarer OSINT-Bericht mit allen Findings
- **Scan speichern**: Ergebnisse in DB archivieren
- **Kunden-Scan**: 1-Click Scan aus Kundenliste
- **200+ Recherche-Links**: Collapsible, nach Kategorie sortiert
- **Kennzeichen-Modul**: EU-weit, Versicherung, Halter-Services, VIN

---

## C. WorldMonitor (`worldmonitor.la-renting.de`)

### Was ist es?
Open-Source Echtzeit-Dashboard fuer globale Daten — Wirtschaft, Geopolitik, Rohstoffe, Wetter, Schiffsverkehr. Vite SPA mit PWA-Support.

### URL
`https://worldmonitor.la-renting.de` (SSL via Let's Encrypt)

### Nutzen fuer Fleckfrei/La-Renting
- Tourismus-Trends und Events fuer Mietpreis-Optimierung
- Wirtschaftsdaten fuer Business-Entscheidungen
- Geolocation-Kontext fuer OSINT-Recherchen
- Sanktionslisten-Abgleich (geplant)

### Tech
- Vite + TypeScript SPA
- nginx Static Hosting auf VPS
- Groq AI fuer Zusammenfassungen
- Redis Cache

---

## D. Infrastruktur

### Server

| System | Host | IP | Zweck |
|--------|------|----|-------|
| Hostinger Shared | srv1047 | 62.72.37.195 | Admin Panel, APIs, MySQL |
| Hostinger VPS KVM2 | srv864084 | 89.116.22.185 | OSINT Tools, n8n, Docker |

### Live Services

| Service | URL/Port | Status |
|---------|----------|--------|
| Admin Panel | app.fleckfrei.de | Live, 36 Seiten |
| OSINT API | VPS :8900 | 8 Tools, systemd |
| SearXNG | VPS :9090 (Docker) | 30+ Search Engines |
| WorldMonitor | worldmonitor.la-renting.de | Live, SSL |
| n8n | n8n.la-renting.com | 9 aktive Workflows |
| Telegram Bot | @fleckfrei_bot | 5 Commands |
| PWA | app.fleckfrei.de | Installierbar, Offline |
| Auto-Cron | /api/cron.php | iCal 15min, Smoobu 30min |
| GHunt | VPS /opt/ghunt-env | v2.3.4 |
| WireGuard | VPS Split-Tunnel | Konfiguriert |

### Docker Container (VPS)

| Container | Funktion |
|-----------|----------|
| searxng | Meta-Suchmaschine |
| n8n | Workflow Automation |
| postgres | Datenbank (n8n + OSINT) |
| mongodb | Evolution API |
| redis | Cache |
| evolution-api | WhatsApp Business API |

### Datenbank

**MySQL (Hostinger)**: `u860899303_la_renting`
- 11.800 Jobs, 431 Kunden, 148 Partner, 1.087 Rechnungen
- Services, iCal Feeds, Audit Log, Messages, Settings

**SQLite (lokal)**: Channel Bookings, Messages, Settings

**PostgreSQL (VPS)**: OSINT Scans, n8n Daten

---

## E. n8n Workflows

| Workflow | Status | Funktion |
|----------|--------|----------|
| Fleckfrei Telegram Bot | Bereit | /stats /jobs /kunde /offene /help |
| Fleckfrei Dashboard API | Aktiv | API Bridge fuer Dashboard |
| Fleckfrei WhatsApp | Aktiv | WhatsApp Business Messages |
| Job Assignment | Aktiv | Partner-Zuweisung + Telegram |
| Cancellation Fine | Aktiv | Storno-Gebuehren Check |
| Employee Profile | Aktiv | Profilbild-Management |
| Work Hours Export | Cron | Excel-Export nach Google Drive |
| Invoice Upload | Cron | Rechnungen nach Google Drive |
| Welmius.ro | Aktiv | Trailer Rental (Nebenprojekt) |

---

## F. Sicherheit

- bcrypt Passwort-Hashing (auto-migration von Plaintext)
- CSRF-Token auf allen Formularen
- API-Key Authentication (`X-API-Key` Header)
- Input Validation an System-Grenzen
- SQL Prepared Statements (kein Raw SQL)
- Security Headers (X-Frame-Options, CSP, XSS-Protection)
- Audit-Log fuer alle Aenderungen
- VPS SSH via Ed25519 Keys (Passwort als Backup)
- Mullvad VPN fuer anonyme OSINT-Abfragen
- SearXNG statt direkte Google-Anfragen (IP-Schutz)

---

## G. PWA (Mobile App)

- Service Worker mit Network-First + Cache-Fallback
- Offline-Seite mit Auto-Reconnect
- Install-Banner (Android) nach 30s
- iOS Standalone-Modus mit Safe Area Support
- Pull-to-Refresh Geste
- 6 Icon-Groessen (48-512px, maskable)
- 3 App-Shortcuts (Dashboard, Jobs, Nachrichten)
- Role-based Start-URL (Admin/Partner/Kunde)

---

## H. Development

### Git
- **Repo**: github.com/fleckfrei/Fleckfrei.de
- **Tag**: `Fleckfrei.de_2026_dont_delete`
- **Branch**: main
- **Sessions 1-10**: 37+ Commits

### Deploy
```bash
# Single file
bash scripts/deploy.sh admin/jobs.php

# Directory
bash scripts/deploy.sh admin/

# Everything
bash scripts/deploy.sh .
```

Deploy Script: PHP Syntax-Check (lokal + remote), Backup, SCP, Post-Deploy Verify, Auto-Rollback, Telegram Notification.

### Lokale Entwicklung
```
/Users/fleckfrei.de/src/fleckfrei-admin/
├── admin/          # Admin-Panel (36 PHP files)
├── api/            # REST API + OSINT Deep Scanner
├── customer/       # Kunden-Portal
├── employee/       # Partner-Portal
├── includes/       # Config, Auth, Layout, DB
├── scripts/        # Deploy, Cron, Utils
├── docs/           # Dokumentation
└── icons/          # Dynamic PNG Icon Generator
```
