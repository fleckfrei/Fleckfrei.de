# Fleckfrei.de -- Technical Book (2026)

**Version:** 2.0  
**Last Updated:** 2026-04-09  
**Classification:** Internal -- Technical Reference  

---

## Table of Contents

1. [Platform Overview](#1-platform-overview)
2. [Architecture](#2-architecture)
3. [File Structure](#3-file-structure)
4. [Key Features](#4-key-features)
5. [Database Schema](#5-database-schema)
6. [API Endpoints](#6-api-endpoints)
7. [Deployment](#7-deployment)
8. [External Integrations](#8-external-integrations)
9. [Session History](#9-session-history)
10. [Git History](#10-git-history)

---

## 1. Platform Overview

**Fleckfrei.de** is a multi-tenant cleaning and property management platform based in Berlin. It serves three distinct business verticals under a single codebase:

| Brand | Domain | Purpose |
|-------|--------|---------|
| **Fleckfrei.de** | app.fleckfrei.de | Cleaning management (Privat, Firma, Ferienwohnung) |
| **La-Renting** | app.la-renting.de | Rental / property management |
| **MAX Co-Host** | maxcohost.host | Short-term rental co-hosting |

**Owner:** MAX (Adrian)  
**Tagline:** "Smart. Sauber. Zuverlassig."  
**Brand Color:** #2E7D6B (teal)  
**Currency:** EUR, positioned after amount (e.g., "100,00 EUR")  
**Locale:** de (German), Timezone: Europe/Berlin  
**Tax Rate:** 19% MwSt  
**Minimum Billing Hours:** 2h per job  

### Technology Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.x (vanilla, no framework) |
| Frontend | Vanilla JavaScript + TailwindCSS |
| Database | MySQL (InnoDB, utf8mb4) |
| Hosting | Hostinger Shared Hosting |
| VPS | Hostinger KVM2 (Ubuntu 24.04) |
| Architecture | Custom MVC-light, white-label ready |

The platform is **custom-built with no framework** -- a deliberate design choice enabling full control over routing, authentication, and white-label configuration. All branding (colors, names, contact info) is configurable via constants in `config.php`.

---

## 2. Architecture

### Infrastructure Diagram

```
                    [Internet]
                        |
        +---------------+---------------+
        |                               |
  [Hostinger Shared]            [Hostinger VPS]
  app.fleckfrei.de              89.116.22.185
  SSH port 65002                Ubuntu 24.04
  User: u860899303              
        |                               |
  +-----+-----+              +---------+---------+
  |           |              |         |         |
 [PHP]    [MySQL]       [n8n]   [WorldMon]  [Evolution]
  8.x     localhost     Webhooks  OSINT     WhatsApp API
          u860899303_   |         Docker
          la_renting    |         Redis
                        |
                   [Telegram Bot]
                   @AdriAssist_bot
```

### Hosting Details

**Shared Hosting (Production App):**
- Host: Hostinger
- SSH: Port 65002, user `u860899303`, key `hostinger_jwt`
- Database: MySQL localhost, database `u860899303_la_renting`
- PHP: 8.x with PDO MySQL, curl, OpenSSL
- SSL: Auto-renewed via Hostinger

**VPS (89.116.22.185):**
- OS: Ubuntu 24.04 LTS
- Services running:
  - **WorldMonitor** -- OSINT intelligence dashboard (Docker, 104 seed scripts, Redis data store)
  - **n8n** -- Workflow automation at `n8n.la-renting.com`
  - **Evolution WhatsApp API** -- WhatsApp Business integration
  - **OSINT API** -- Deep scan backend services
- Domain: intel.maxcohost.host (pending SSL)

### Database Architecture

Dual-database design with automatic fallback:

| Database | Location | Purpose | Tables |
|----------|----------|---------|--------|
| **Master** (`u860899303_la_renting`) | Hostinger localhost | Core business data | customer, employee, jobs, services, invoices, ical_feeds, osint_scans |
| **Local** (`i10205616_zlzy1`) | GoDaddy localhost | Supplementary data | messages, audit_log, settings, gps_tracking, channel_bookings, customer_address, invoice_payments |

Connection management in `config.php`:
- `$db` / `$dbRemote` -- Master database (Hostinger)
- `$dbLocal` -- Local database (GoDaddy), falls back to `$db` on connection failure
- `qBoth()` -- Dual-write function for critical operations
- Helper functions: `q()`, `all()`, `one()`, `val()` for master; `qLocal()`, `allLocal()`, `oneLocal()`, `valLocal()` for local

### CORS Policy

Allowed origins (strict whitelist):
- `https://app.fleckfrei.de`
- `https://fleckfrei.de`
- `https://app.la-renting.de`
- CLI and API key access gets wildcard `*`

### Authentication Model

Two authentication paths:
1. **Session-based** -- Admin/customer/employee login via PHP sessions (`$_SESSION['uid']`)
2. **API key** -- Header `X-API-Key` or query param `?key=` validated against `API_KEY` constant

---

## 3. File Structure

```
/src/fleckfrei-admin/
|
+-- api/                          API endpoints (JSON REST)
|   +-- index.php                 Main API router (~1918 lines, 50+ endpoints)
|   +-- osint-deep.php            OSI Deep Scanner (2187 lines, 40+ data sources)
|   +-- ical-import.php           iCal feed import endpoint
|   +-- ical-export.php           iCal calendar export
|   +-- paypal-create.php         PayPal order creation
|   +-- paypal-capture.php        PayPal payment capture
|   +-- health.php                Health check endpoint
|
+-- includes/                     Shared PHP includes
|   +-- config.php                Database, API keys, features, white-label config
|   +-- schema.php                Auto-migrate DB schema (creates tables if missing)
|   +-- auth.php                  Session + API key authentication
|   +-- layout.php                HTML layout template + navigation
|   +-- lang.php                  Internationalization (i18n)
|   +-- email.php                 Email template engine + SMTP sending
|   +-- openbanking.php           Open Banking API wrapper (N26 integration)
|   +-- stripe-keys.php           Stripe API keys (not in git)
|   +-- paypal-keys.php           PayPal API keys (not in git)
|   +-- openbanking.pem           Open Banking certificate
|   +-- openbanking_account.txt   Linked bank account IDs
|
+-- admin/                        Admin panel pages (36 pages)
|   +-- index.php                 Dashboard (stats overview)
|   +-- customers.php             Customer list (Aktiv/Archiv tabs)
|   +-- view-customer.php         Customer detail + jobs + invoices
|   +-- employees.php             Partner (employee) list
|   +-- view-employee.php         Partner detail view
|   +-- services.php              Service/property management
|   +-- jobs.php                  Job list with filters
|   +-- calendar.php              Calendar view (month/week/day)
|   +-- invoices.php              Invoice list + generation
|   +-- invoice-pdf.php           PDF invoice generation
|   +-- scanner.php               OSI Intelligence Scanner UI
|   +-- bookings.php              Smoobu channel bookings
|   +-- messages.php              Message center
|   +-- settings.php              System settings, iCal feeds, white-label
|   +-- audit.php                 Audit log viewer
|   +-- workhours.php             Work hours report + CSV export
|   +-- live-map.php              Live GPS tracking map
|   +-- bank.php                  Open Banking dashboard
|   +-- notifications.php         Notification center
|   +-- reports.php               Business reports
|   ... (36 total pages)
|
+-- customer/                     Customer portal
|   +-- index.php                 Customer dashboard
|   +-- invoices.php              View + pay invoices (Stripe/PayPal buttons)
|   +-- jobs.php                  View scheduled jobs
|   +-- profile.php               Edit profile + addresses
|
+-- employee/                     Employee/Partner portal
|   +-- index.php                 Partner dashboard
|   +-- jobs.php                  Today's jobs + start/complete actions
|   +-- gps.php                   GPS tracking (sends position every 30s)
|
+-- scripts/
|   +-- deploy.sh                 Safe deploy (PHP syntax check + SCP + Telegram)
|   +-- monitor.sh                Health monitoring script
|   +-- ical-cron.sh              iCal auto-sync (cron every 30min)
|
+-- backups/                      Deployment backups
+-- icons/                        PWA icons
+-- uploads/                      User uploads
+-- login.php                     Login page
+-- logout.php                    Logout handler
+-- manifest.php                  PWA manifest
+-- sw.js                         Service Worker (offline support)
+-- offline.html                  Offline fallback page
```

---

## 4. Key Features

### 4.1 Customer Management

**Files:** `admin/customers.php`, `admin/view-customer.php`, `customer/`

| Feature | Description |
|---------|-------------|
| CRUD | Full create/read/update/delete with soft-delete (status=0/1) |
| Customer Types | `Privat`, `Firma`, `Airbnb`, `Ferienwohnung` |
| Active/Archive Tabs | Toggle between active (status=1) and archived (status=0) customers |
| Address Management | Separate `customer_address` table with street, number, PLZ, city, country |
| Inline Editing | API endpoint `customer/update` allows field-by-field updates |
| Customer Portal | Self-service login, view jobs, pay invoices, edit profile |
| Email Permissions | Per-customer notification preferences |
| Auto-creation | Webhook bookings auto-create customers if not found by email/phone |

### 4.2 Partner (Employee) Management

**Files:** `admin/employees.php`, `admin/view-employee.php`, `employee/`

| Feature | Description |
|---------|-------------|
| Partner Profiles | Name, email, phone, tariff rate, location, nationality |
| Status Toggle | Active/Inactive with audit logging |
| Inline Editing | API endpoint `employee/update` for field-by-field updates |
| Job Assignment | Assign partners to jobs; auto-sets status to CONFIRMED |
| Partner Portal | View today's jobs, start/complete with GPS, photo upload |
| GPS Tracking | Live position every 30 seconds during RUNNING jobs |

### 4.3 Job/Booking System

**Files:** `admin/jobs.php`, `admin/calendar.php`, `api/index.php`

**Status Lifecycle:**
```
PENDING --> CONFIRMED --> STARTED/RUNNING --> COMPLETED
    |           |              |
    +-----------+--------------+--> CANCELLED
```

| Feature | Description |
|---------|-------------|
| Job Creation | Manual (admin) or automated (webhook/iCal/Smoobu) |
| Recurring Jobs | Daily, weekly, bi-weekly, tri-weekly, monthly with configurable end date |
| Recurring Group | `recurring_group` ID links related recurring jobs |
| Calendar View | Month/week/day with FullCalendar integration |
| iCal Import | Sync from Airbnb, Booking.com, VRBO feeds |
| iCal Export | Export calendar for external tools |
| Platform Tracking | Source: `manual`, `ical`, `airbnb`, `booking.com`, `website`, `admin` |
| Status Change Hooks | n8n webhook on status change, email notifications, Telegram alerts |
| Guest Info | Guest name, phone, email, checkout date/time (for Airbnb/rental jobs) |
| Door Codes | `code_door` field for property access |
| Bulk Operations | Cancel/delete single or all future recurring jobs |
| Minimum Hours | 2h minimum billing regardless of actual time worked |

### 4.4 Invoice System

**Files:** `admin/invoices.php`, `admin/invoice-pdf.php`, `api/index.php`

| Feature | Description |
|---------|-------------|
| Auto-generation | `invoice/generate` creates invoice from all completed jobs in a month |
| Manual Creation | `invoice/create` for custom invoices with sequential numbering (FF-0001) |
| Invoice Numbering | Auto: `FF-YYYYMM-CustomerID`, Manual: `FF-NNNN` (sequential) |
| PDF Generation | Server-side PDF via `invoice-pdf.php` |
| XRechnung XML | Full UBL 2.1 / XRechnung 3.0 compliant XML export |
| Custom Lines | `invoice/save-lines` allows manual line-item editing |
| Payment Tracking | Per-invoice payment records in `invoice_payments` table |
| Payment Methods | Stripe (card), PayPal (button), SEPA (bank transfer), Cash |
| Open Balance | `remaining_price` tracks partial payments |
| CSV Export | `export/invoices` downloads invoice list as CSV |
| Work Hours CSV | `export/workhours` downloads completed job hours as CSV |
| Tax Calculation | Automatic 19% MwSt with netto/brutto/tax breakdown |
| Email Notifications | Auto-send invoice email on creation |

**XRechnung Compliance:**
- Customization ID: `urn:cen.eu:en16931:2017#compliant#urn:xoev-de:kosit:standard:xrechnung_3.0`
- Profile ID: `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0`
- Invoice Type Code: 380 (commercial invoice)
- Payment Means Code: 58 (SEPA credit transfer)
- Tax Category: S (standard rate, 19%)
- Due Date: Issue date + 14 days

### 4.5 Payment Integration

#### Stripe

**Files:** `includes/stripe-keys.php` (not in git), `api/index.php` (stripe/* endpoints)

| Endpoint | Purpose |
|----------|---------|
| `stripe/checkout` (POST) | Create Checkout Session for invoice payment |
| `stripe/webhook` (POST) | Handle `checkout.session.completed` event |

Flow: Customer clicks "Pay" on invoice --> Checkout Session created with invoice metadata --> redirect to Stripe --> webhook confirms payment --> invoice marked paid + Telegram notification.

Stripe signature verification is implemented using HMAC-SHA256 with `STRIPE_WEBHOOK_SECRET`.

#### PayPal

**Files:** `includes/paypal-keys.php` (not in git), `api/paypal-create.php`, `api/paypal-capture.php`

- Mode: **Live** (production)
- API: PayPal v2 REST API with OAuth2
- Base URL: `https://api-m.paypal.com`
- Flow: Smart Buttons on customer portal --> create order --> capture payment

### 4.6 Channel Manager / iCal

**Files:** `admin/settings.php`, `admin/bookings.php`, `api/index.php`

#### iCal Import/Export

| Feature | Endpoint | Description |
|---------|----------|-------------|
| Feed Management | `ical/feeds` (GET/POST) | List and add iCal feed URLs |
| Feed Delete | `ical/feeds/delete` (POST) | Remove a feed |
| Sync | `ical/sync` (POST) | Sync one or all active feeds |
| Auto-Sync | `scripts/ical-cron.sh` | Cron job every 30 minutes |
| Export | `api/ical-export.php` | Export jobs as .ics file |

Supported platforms: Airbnb, Booking.com, VRBO, generic iCal

The iCal parser handles:
- VCALENDAR/VEVENT blocks
- Folded lines (RFC 5545 compliant)
- Date formats: `20260415T140000Z` (UTC), `20260415T140000` (local), `20260415` (date-only)
- UTC to Europe/Berlin timezone conversion
- Deduplication via `ical_uid` column on jobs table

#### Smoobu Integration

| Endpoint | Method | Description |
|----------|--------|-------------|
| `smoobu/webhook` | POST | Incoming webhook (create/update/cancel bookings) |
| `smoobu/bookings` | GET | List bookings with date range |
| `smoobu/apartments` | GET | List properties |
| `smoobu/rates` | GET | Get pricing for apartment + date range |
| `smoobu/availability` | GET | Check availability |
| `smoobu/sync` | POST | Full sync (last 7 days to +90 days, paginated) |
| `channel/bookings` | GET | Read local booking cache |

Smoobu API base: `https://login.smoobu.com/api`  
Local cache table: `channel_bookings` (with `smoobu_id` unique index)

### 4.7 OSI Intelligence Scanner

**File:** `api/osint-deep.php` (2187 lines)

The most complex component of the platform. A comprehensive OSINT (Open Source Intelligence) scanner with 40+ data sources, verification engine, correlation analysis, and self-learning algorithm.

#### Input Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `email` | string | Email address |
| `name` | string | Full name |
| `phone` | string | Phone number |
| `address` | string | Physical address |
| `dob` | string | Date of birth (DD.MM.YYYY or YYYY-MM-DD) |
| `id_number` | string | Personalausweis-Nr |
| `passport` | string | Reisepass-Nr |
| `serial` | string | Serien-Nr (Gewerbe/Handelsregister) |
| `tax_id` | string | Steuernummer / USt-IdNr |
| `plate` | string | License plate (e.g., B-AB 1234) |

#### Modules (40+ Data Sources)

**0. Cache Check**
- MD5 hash of all inputs
- Returns cached results if scan < 24 hours old
- Reports cache hit, age in minutes

**1. DB Cross-Reference**
- Searches local database for matching customer by email, name, or phone
- Returns: customer profile, job stats (total/completed/cancelled), cancel rate, revenue, open balance, services, invoices, addresses, recent jobs
- Risk flags: high cancellation rate (>20%), open invoices (>100 EUR), missing contact info

**2. Email Intelligence**
- SPF record lookup and analysis
- DMARC record check
- DKIM verification
- **SPF Chain Resolution**: Recursive `include:` resolution up to 5 levels deep
  - Identifies mail services: Google Workspace, Microsoft 365, Amazon SES, SendGrid, Mailgun, Mailchimp, Zendesk, Freshdesk, HubSpot, Zoho
  - Extracts IP4/IP6 ranges

**3. Domain Reconnaissance** (parallel curl_multi)
- crt.sh SSL certificate transparency (subdomain enumeration)
- WHOIS lookup (registrar, creation date, organization, country)
- Reverse IP lookup via HackerTarget
- Wayback Machine archive check
- SSL certificate analysis (issuer, expiry, days remaining)
- HTTP response code
- Technology detection: WordPress, Shopify, Wix, Bootstrap, Tailwind, React, Next.js, Vue, Angular, jQuery, Elementor, Google Analytics, Tag Manager, Cloudflare, LiteSpeed

**4. Social Profiles (12 platforms)**
- Instagram, TikTok, Facebook, X/Twitter, LinkedIn, GitHub, XING, Reddit, YouTube, Pinterest, Telegram, Kleinanzeigen
- Google dork search URLs for each platform
- Combined name + email search queries

**5. Username Search**
- Name permutation engine (first.last, first_last, flast, f.last, etc.)
- GitHub API verification (reliable, returns JSON)
- Sherlock-style multi-platform check via curl_multi (21+ platforms)
- Username generator: 30+ patterns from first/last name

**6. Breach Check (HIBP k-Anonymity)**
- Have I Been Pwned API using k-Anonymity model
- SHA-1 hash prefix lookup (first 5 chars)
- No full hash ever leaves the system
- Returns breach count

**7. Phone OSINT**
- German carrier detection by prefix
- Phone format normalization
- WhatsApp existence check (wa.me redirect)
- Database phone cross-reference

**8. Address Geocoding (Nominatim)**
- OpenStreetMap Nominatim geocoding
- Returns latitude, longitude, formatted address

**9. Email Exposure (HackerTarget)**
- Domain-based email search
- Returns exposed email addresses on same domain

**10. Shodan (Port/Service Scan)**
- API Key: Configured in config.php
- Host lookup by IP
- Returns: ports, services, OS, ISP, location

**11. VirusTotal (Domain Reputation)**
- API Key: Configured in config.php
- Domain report
- Returns: reputation score, detected engines, categories

**12. Hunter.io (Email Verification + Domain Search)**
- API Key: Configured in config.php
- Email verification (deliverable/risky/undeliverable)
- Domain search (find all emails at domain)

**13. GLEIF LEI (Legal Entity Identifier)**
- Fuzzy name search against GLEIF database
- Returns: LEI, legal name, jurisdiction, registration date, status

**14. OpenCorporates**
- Company search by name
- Returns: company number, jurisdiction, status, registered address

**15. DNS Service Discovery**
- 14 SRV record prefixes checked:
  - `_sip._tcp`, `_xmpp-server._tcp`, `_autodiscover._tcp`, `_caldav._tcp`, `_carddav._tcp`, `_imap._tcp`, `_submission._tcp`, `_pop3._tcp`, `_http._tcp`, `_https._tcp`, `_ftp._tcp`, `_ssh._tcp`, `_minecraft._tcp`, `_ts3._udp`

**16. BGP/ASN Lookup**
- Team Cymru IP-to-ASN mapping
- RDAP queries for detailed network info

**17. License Plate Search**
- Pan-European coverage: 12 countries
- GDV Zentralruf (German insurance lookup)
- Plate format validation per country
- AutoDNA integration

**18. Registration Number Search**
- Steuernummer / HRB / Aktenzeichen
- German format validation

**19. German Handelsregister**
- offeneregister.de API
- Company name + registry number search

**20. Airbnb Profile Scraper**
- Profile URL construction and verification
- Review count, listing count extraction

**21. Impressum Validator**
- Fetches website, extracts /impressum or /imprint page
- Cross-references: company name, address, phone, email, Handelsregister
- Validates legal compliance

**22. Website Deep Scan**
- Crawls subpages (up to 10)
- Extracts: emails, phone numbers, social media links
- Technology fingerprinting

**23. NorthData + Business Registries**
- NorthData company search
- 9 additional business registries queried

**24. Name Permutation Engine**
- Every word combination from provided name
- First-last, last-first, initials+last, etc.

**25. Username Generator**
- 30+ patterns: first.last, flast, first_last, firstl, first123, etc.

**26. Gravatar, Keybase, Telegram OSINT**
- Gravatar hash lookup
- Keybase username search
- Telegram t.me/ profile check

**27. Paste-Leak Search, Document Leaks, Forum Mentions**
- Paste site search (Pastebin-style)
- Document leak databases
- Forum mention aggregation

**28. Social Media Deep Scan**
- Instagram, LinkedIn, Facebook, TikTok, XING
- Profile existence verification via HTTP HEAD
- Redirect detection (login pages = no profile)

**29. Insolvency Check (Bundesanzeiger)**
- German insolvency publication search

**30. Web Intelligence (DuckDuckGo)**
- Parallel DuckDuckGo searches
- Name + email + phone combinations
- Result aggregation

#### Correlation Engine (Identity Graph)

Cross-references all findings to build an identity graph:
- Links email to social profiles
- Links phone to WhatsApp + DB records
- Links address to geocoding + property records
- Confidence scoring: EXACT, FUZZY, HEURISTIC per module

#### Verification Engine

Three confidence levels per finding:
- **EXACT** -- Direct API match (email verification, GLEIF LEI)
- **FUZZY** -- Name/pattern match (social profiles, username search)
- **HEURISTIC** -- Behavioral inference (breach patterns, web mentions)

Hard identifiers used for verification:
- Email, Phone, DOB, ID Number, Passport, Serial Number, Tax ID, License Plate

#### Self-Learning Algorithm

Analyzes past scans (last 50) to improve accuracy:
- Tracks hit rate per module (which sources find data most often)
- Generates recommendations ("'{module}' yields results in X% of scans")
- Detects person type: business, self_employed, customer, private
- Provides type-specific advice for best data sources

#### Performance

- Max execution time: 90 seconds
- 24-hour cache with MD5 key
- Parallel HTTP requests via `curl_multi`
- Error suppression with graceful degradation
- Output buffering for clean JSON response

### 4.8 Open Banking (N26 Auto-Import)

**Files:** `includes/openbanking.php`, `api/index.php` (bank/* endpoints)

| Feature | Endpoint | Description |
|---------|----------|-------------|
| Bank Connection | `bank/connect` (POST) | Start OAuth2 bank linking flow |
| Bank List | `bank/list` (GET) | List available banks (Germany) |
| Auto-Sync | `bank/auto-sync` (POST) | Fetch last 7 days, match to invoices |
| CSV Export | `bank/export` (GET) | Download payment history as CSV |

- Provider: Enable Banking API
- App ID: Configured in config.php
- PEM certificate authentication
- Auto-matching: incoming transactions matched against open invoices
- Telegram notification on successful matches

### 4.9 Distance Calculator

**Endpoint:** `distance` (GET)

Calculates travel distance and cost across 5 transport modes:

| Mode | Source | Cost Model |
|------|--------|------------|
| Car | OSRM API | 0.30 EUR/km (fuel) / 0.52 EUR/km (ADAC full cost) |
| Bicycle | OSRM API | Free |
| Walking | OSRM API | Free |
| BVG Transit | Estimate | 2.40 EUR (Kurzstrecke) / 3.50 EUR (Einzelfahrt) |
| Bolt/Taxi | Estimate | 1.65 EUR base + 1.28 EUR/km + 0.30 EUR/min |

Features:
- Address geocoding via Nominatim
- Best option recommendation (cheapest under 30 minutes)
- Google Maps URL generation
- Fraud warning flag (distance > 2km from expected)

### 4.10 GPS Live Tracking

**Files:** `admin/live-map.php`, `employee/gps.php`, `api/index.php`

| Endpoint | Method | Description |
|----------|--------|-------------|
| `gps/update` | POST | Partner sends position (every 30s during RUNNING job) |
| `gps/live` | GET | Get latest positions of all active partners |

- Auto-creates `gps_tracking` table if missing
- Stores: emp_id, j_id (job), lat, lng, accuracy, timestamp
- Live map shows positions from last 1 hour
- Employee names resolved from master DB

### 4.11 Notification System

**Channels:**

| Channel | Implementation | Trigger |
|---------|---------------|---------|
| Telegram | `telegramNotify()` via n8n webhook | Job created, status change, payment, invoice, Smoobu, iCal sync, bank import |
| n8n Webhooks | 4 dedicated endpoints | booking, status, notify, message |
| Email | `sendEmail()` via SMTP | 8 template types |

**n8n Webhook URLs:**

| Webhook | URL |
|---------|-----|
| Booking | `https://n8n.la-renting.com/webhook/fleckfrei-v2-booking` |
| Status | `https://n8n.la-renting.com/webhook/fleckfrei-v2-job-status` |
| Notify | `https://n8n.la-renting.com/webhook/fleckfrei-v2-notify` |
| Message | `https://n8n.la-renting.com/webhook/fleckfrei-v2-message` |

**Email Templates:**

| Type | Function | Trigger |
|------|----------|---------|
| `welcome` | `notifyWelcome()` | New customer created |
| `booking` | `notifyBookingConfirmation()` | Job created via webhook |
| `started` | `notifyJobStarted()` | Job status -> RUNNING |
| `completed` | `notifyJobCompleted()` | Job status -> COMPLETED |
| `reminder` | `notifyJobReminder()` | Tomorrow's jobs (n8n cron) |
| `invoice` | `notifyInvoiceCreated()` | Invoice generated |
| `payment_reminder` | `notifyPaymentReminder()` | Overdue invoice |
| `review` | `notifyReviewRequest()` | Post-completion review request |

### 4.12 Message System

**Files:** `admin/messages.php`, `api/index.php`

| Endpoint | Method | Description |
|----------|--------|-------------|
| `messages/send` | POST | Create message (from n8n, external) |
| `messages` | GET | List messages by sender/recipient |
| `messages/translate` | POST | Update with AI-translated text |

Message types: admin, customer, employee, system, AI  
Channels: portal, whatsapp, email, system

### 4.13 Webhook Booking Handler

**Endpoint:** `webhook/booking` (POST)

Handles incoming bookings from the fleckfrei.de website and external sources. Supports both flat and nested JSON formats.

**Flow:**
1. Parse booking data (handles nested `customer.name` and flat `name` formats)
2. Map service type to customer_type (privathaushalt -> Private Person, ferienwohnung -> Airbnb, buero -> Company)
3. Map frequency to recurring interval (woechentlich -> weekly, 2wochen -> weekly2, monatlich -> weekly4)
4. Parse address into street/PLZ/city components
5. Find or create customer (by email, then phone)
6. Match service by address similarity or service type
7. Create job with all fields (guest info, door codes, checkout dates)
8. Send booking confirmation email + Telegram notification

### 4.14 Security Features

| Feature | Implementation |
|---------|---------------|
| CSRF Protection | Token validation on all forms |
| SQL Injection Prevention | Prepared statements via PDO everywhere |
| XSS Prevention | `e()` function wrapping `htmlspecialchars()` with ENT_QUOTES, UTF-8 |
| API Authentication | API key via X-API-Key header or query param |
| Session Authentication | PHP sessions with uid/uname/utype |
| CORS | Strict origin whitelist |
| Audit Log | All CRUD operations logged with user, IP, timestamp |
| Password Hashing | bcrypt with cost 12; bulk migration endpoint `security/hash-passwords` |
| Stripe Signature | HMAC-SHA256 webhook verification |

### 4.15 WorldMonitor

- OSINT intelligence dashboard running on VPS (Docker)
- 104 seed scripts for data collection
- Redis data store for cached intelligence
- Domain: intel.maxcohost.host (pending SSL setup)

### 4.16 Feature Flags

Configurable in `config.php`:

| Flag | Default | Description |
|------|---------|-------------|
| `FEATURE_OSINT` | true | OSI Scanner page |
| `FEATURE_RECURRING` | true | Recurring jobs |
| `FEATURE_AUDIT` | true | Audit logging |
| `FEATURE_WHATSAPP` | true | WhatsApp integration |
| `FEATURE_TELEGRAM` | false | Telegram direct integration |
| `FEATURE_INVOICE_AUTO` | true | Auto-generate invoices |
| `FEATURE_STRIPE` | dynamic | Stripe payments (auto-detected from key) |
| `FEATURE_PAYPAL` | dynamic | PayPal payments (auto-detected from keys) |
| `FEATURE_SMOOBU` | dynamic | Smoobu channel manager (auto-detected from key) |
| `FEATURE_AUTO_BANK` | true | Open Banking auto-import |

---

## 5. Database Schema

### Master Database (`u860899303_la_renting`)

#### `customer`
| Column | Type | Description |
|--------|------|-------------|
| customer_id | INT PK | Auto-increment |
| name | VARCHAR | Full name |
| surname | VARCHAR | Last name |
| email | VARCHAR | Email address |
| phone | VARCHAR | Phone number |
| customer_type | VARCHAR | Privat/Firma/Airbnb/Ferienwohnung |
| password | VARCHAR | bcrypt hash (migrated from plaintext) |
| status | TINYINT | 1=active, 0=archived |
| email_permissions | VARCHAR | Notification preferences |
| email_notifications | TINYINT | Email opt-in |
| notes | TEXT | Admin notes |
| created_at | TIMESTAMP | Creation date |

#### `employee`
| Column | Type | Description |
|--------|------|-------------|
| emp_id | INT PK | Auto-increment |
| name | VARCHAR | First name |
| surname | VARCHAR | Last name |
| email | VARCHAR | Email |
| phone | VARCHAR | Phone |
| password | VARCHAR | bcrypt hash |
| tariff | DECIMAL | Hourly rate |
| location | VARCHAR | Base location |
| nationality | VARCHAR | Nationality |
| status | TINYINT | 1=active, 0=inactive |
| notes | TEXT | Notes |

#### `jobs`
| Column | Type | Description |
|--------|------|-------------|
| j_id | INT PK | Auto-increment |
| customer_id_fk | INT FK | Customer reference |
| s_id_fk | INT FK | Service reference |
| emp_id_fk | INT FK | Assigned partner |
| j_date | DATE | Job date |
| j_time | TIME | Start time |
| stop_times | TIME | Calculated end time |
| j_hours | DECIMAL | Scheduled hours |
| total_hours | DECIMAL | Actual hours worked |
| job_status | ENUM | PENDING/CONFIRMED/RUNNING/STARTED/COMPLETED/CANCELLED |
| job_for | VARCHAR | Recurrence: daily/weekly/weekly2/weekly3/weekly4 |
| recurring_group | VARCHAR | Links recurring jobs |
| address | VARCHAR | Job location |
| code_door | VARCHAR | Door access code |
| platform | VARCHAR | Source: manual/ical/airbnb/booking.com/website/admin |
| ical_uid | VARCHAR | iCal event UID for dedup |
| start_time | TIME | Actual start |
| end_time | TIME | Actual end |
| start_location | VARCHAR | GPS at start |
| end_location | VARCHAR | GPS at end |
| cancel_date | DATETIME | Cancellation timestamp |
| invoice_id | INT FK | Linked invoice |
| guest_name | VARCHAR | Guest name (Airbnb) |
| guest_phone | VARCHAR | Guest phone |
| guest_email | VARCHAR | Guest email |
| guest_checkout_date | DATE | Guest checkout date |
| guest_checkout_time | TIME | Guest checkout time |
| check_in_date | DATE | Check-in date |
| check_in_time | TIME | Check-in time |
| job_note | TEXT | Internal notes |
| emp_message | TEXT | Message for partner |
| optional_products | TEXT | Extra services |
| no_people | INT | Number of people |
| job_photos | TEXT | Photo URLs (JSON) |
| j_c_val | VARCHAR | Payment method |
| status | TINYINT | 1=active, 0=soft-deleted |

#### `services`
| Column | Type | Description |
|--------|------|-------------|
| s_id | INT PK | Auto-increment |
| title | VARCHAR | Service name |
| price | DECIMAL | Hourly rate (netto) |
| total_price | DECIMAL | Total price |
| tax | DECIMAL | Tax amount |
| tax_percentage | DECIMAL | Tax percentage (19) |
| coin | VARCHAR | Currency symbol |
| street, number, postal_code, city, country | VARCHAR | Service location |
| qm | INT | Square meters |
| room | INT | Number of rooms |
| box_code | VARCHAR | Key box code |
| client_code | VARCHAR | Client reference code |
| deposit_code | VARCHAR | Deposit box code |
| wifi_name, wifi_password | VARCHAR | WiFi credentials |
| customer_id_fk | INT FK | Assigned customer |
| status | TINYINT | 1=active |

#### `invoices`
| Column | Type | Description |
|--------|------|-------------|
| inv_id | INT PK | Auto-increment |
| customer_id_fk | INT FK | Customer reference |
| invoice_number | VARCHAR | e.g., FF-202604-5 or FF-0001 |
| issue_date | DATE | Invoice date |
| price | DECIMAL | Netto amount |
| tax | DECIMAL | Tax (19% MwSt) |
| total_price | DECIMAL | Brutto total |
| remaining_price | DECIMAL | Open balance |
| invoice_paid | ENUM | yes/no |
| start_date | DATE | Service period start |
| end_date | DATE | Service period end |
| custom_lines | TEXT | JSON line items (manual edit) |
| custom_note | TEXT | Custom note |

#### `ical_feeds`
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| customer_id_fk | INT FK | Linked customer |
| label | VARCHAR | Feed name |
| url | TEXT | iCal URL |
| platform | VARCHAR | airbnb/booking.com/vrbo/ical |
| active | TINYINT | 1=active |
| last_sync | DATETIME | Last sync timestamp |
| jobs_created | INT | Total jobs created from feed |

#### `osint_scans`
| Column | Type | Description |
|--------|------|-------------|
| scan_id | INT PK | Auto-increment |
| customer_id_fk | INT FK | Linked customer (nullable) |
| scan_name | VARCHAR | Scanned name |
| scan_email | VARCHAR | Scanned email |
| scan_phone | VARCHAR | Scanned phone |
| scan_address | VARCHAR | Scanned address |
| scan_data | TEXT | Input parameters JSON |
| deep_scan_data | TEXT | Full scan results JSON |
| scanned_by | INT | User who ran scan |
| created_at | TIMESTAMP | Scan timestamp |

#### `users`
| Column | Type | Description |
|--------|------|-------------|
| email | VARCHAR | Login email |
| type | ENUM | admin/customer/employee |

### Local Database (supplementary tables)

#### `invoice_payments`
| Column | Type | Description |
|--------|------|-------------|
| ip_id | INT PK | Auto-increment |
| invoice_id_fk | INT FK | Invoice reference |
| amount | DECIMAL(10,2) | Payment amount |
| payment_date | DATE | Payment date |
| payment_method | VARCHAR(50) | Stripe/PayPal/SEPA/Cash |
| note | TEXT | Payment reference |

#### `customer_address`
| Column | Type | Description |
|--------|------|-------------|
| ca_id | INT PK | Auto-increment |
| customer_id_fk | INT FK | Customer reference |
| street | VARCHAR(255) | Street name |
| number | VARCHAR(20) | House number |
| postal_code | VARCHAR(10) | PLZ |
| city | VARCHAR(100) | City |
| country | VARCHAR(100) | Default: Deutschland |
| address_for | VARCHAR(50) | Type: location/billing |

#### `settings`
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Single row |
| first_name, last_name | VARCHAR | Owner name |
| company | VARCHAR | Company name |
| phone, email, website | VARCHAR | Contact info |
| invoice_prefix | VARCHAR | Default: INV- |
| invoice_number | INT | Next invoice number |
| street, number, postal_code, city, country | VARCHAR | Business address |
| bank, iban, bic | VARCHAR | Banking info |
| USt_IdNr | VARCHAR | VAT ID |
| business_number, fiscal_number | VARCHAR | Registration numbers |
| invoice_text | TEXT | Default invoice text |
| note_for_email | TEXT | Email footer note |
| email_booking...email_reminder | TINYINT | Email notification toggles |

#### `channel_bookings`
| Column | Type | Description |
|--------|------|-------------|
| cb_id | INT PK | Auto-increment |
| smoobu_id | INT UNIQUE | Smoobu booking ID |
| guest_name | VARCHAR | Guest name |
| guest_email, guest_phone | VARCHAR | Guest contact |
| property_name | VARCHAR | Apartment name |
| property_id | INT | Smoobu property ID |
| channel | VARCHAR | airbnb/booking.com/vrbo/direct |
| check_in, check_out | DATE | Stay dates |
| adults, children | INT | Guest count |
| price | DECIMAL(10,2) | Booking price |
| currency | VARCHAR | Default: EUR |
| status | VARCHAR | confirmed/cancelled |
| notes | TEXT | Booking notes |
| job_id | INT | Linked cleaning job |
| synced_at | TIMESTAMP | Last sync |

#### `messages`
| Column | Type | Description |
|--------|------|-------------|
| msg_id | INT PK | Auto-increment |
| job_id | INT | Related job (nullable) |
| sender_type | ENUM | admin/customer/employee/system/ai |
| sender_id | INT | Sender reference |
| sender_name | VARCHAR | Display name |
| recipient_type | ENUM | admin/customer/employee |
| recipient_id | INT | Recipient reference |
| message | TEXT | Message content |
| translated_message | TEXT | AI translation |
| channel | ENUM | portal/whatsapp/email/system |
| read_at | DATETIME | Read timestamp |
| created_at | DATETIME | Sent timestamp |

#### `audit_log`
| Column | Type | Description |
|--------|------|-------------|
| user_name | VARCHAR | Acting user |
| action | VARCHAR | create/update/delete/status_change/... |
| entity | VARCHAR | customer/job/invoice/employee/... |
| entity_id | INT | Entity reference |
| details | TEXT | Change description |
| ip | VARCHAR | Client IP |

#### `gps_tracking`
| Column | Type | Description |
|--------|------|-------------|
| id | INT PK | Auto-increment |
| emp_id | INT | Partner ID |
| j_id | INT | Active job (nullable) |
| lat | DECIMAL(10,7) | Latitude |
| lng | DECIMAL(10,7) | Longitude |
| accuracy | FLOAT | GPS accuracy in meters |
| created_at | DATETIME | Timestamp |

---

## 6. API Endpoints

All endpoints served from `api/index.php` unless noted otherwise.  
Base URL: `https://app.fleckfrei.de/api/index.php?action=`  
Auth: Session cookie or `X-API-Key` header.  
Response format: `{"success": true|false, "data": ..., "error": "..."}`.

### No-Auth Endpoints (before auth check)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `smoobu/webhook` | POST | Smoobu booking webhook |
| `stripe/webhook` | POST | Stripe payment webhook |

### Stats & Dashboard

| Endpoint | Method | Description |
|----------|--------|-------------|
| `stats` | GET | Dashboard stats (customers, employees, services, jobs today/pending/total) |

### Customer Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `customers` | GET | List all active customers |
| `customer/details` | GET | Single customer with latest address |
| `customer/services` | GET | Services assigned to or used by customer |
| `customer/update` | POST | Update single field (name/email/phone/type/notes) |
| `customer/status` | POST | Toggle active/archived |

### Job Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `jobs` | GET | List jobs with date range + filters |
| `jobs` | POST | Create job (with recurring support) |
| `jobs/update` | POST | Update single field |
| `jobs/delete` | POST | Soft-delete single job |
| `jobs/delete-recurring` | POST | Delete recurring series (with date range) |
| `jobs/cancel-recurring` | POST | Cancel single or all future recurring |
| `jobs/assign` | POST | Assign/un-assign partner |
| `jobs/status` | POST | Update job status (triggers n8n webhook + email) |

### Invoice Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `invoices` | GET | List all invoices (limit 200) |
| `invoice/generate` | POST | Auto-generate from completed jobs |
| `invoice/create` | POST | Manual invoice creation |
| `invoice/update` | POST | Update single field |
| `invoice/save-lines` | POST | Save custom line items |
| `invoice/jobs` | GET | Unbilled completed jobs for date range |
| `invoice/xrechnung` | GET | Download XRechnung XML |

### Employee Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `employees` | GET | List all employees |
| `employee/update` | POST | Update single field |
| `employee/status` | POST | Toggle active/inactive |

### Service Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `services` | GET | List all active services with customer name |
| `services/create` | POST | Create service (admin only) |
| `services/update` | POST | Update service fields |

### Payment Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `stripe/checkout` | POST | Create Stripe Checkout Session |
| `stripe/webhook` | POST | Handle Stripe payment events |

### Channel Manager Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `smoobu/bookings` | GET | List Smoobu bookings |
| `smoobu/apartments` | GET | List properties |
| `smoobu/rates` | GET | Get pricing |
| `smoobu/availability` | GET | Check availability |
| `smoobu/sync` | POST | Full Smoobu sync |
| `channel/bookings` | GET | Local booking cache |

### iCal Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `ical/feeds` | GET | List all feeds |
| `ical/feeds` | POST | Add new feed |
| `ical/feeds/delete` | POST | Remove feed |
| `ical/sync` | POST | Sync one or all feeds |

### OSINT Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `osint/whatsapp` | GET | WhatsApp number check |
| `osint/save` | POST | Save scan results |
| `osint/history` | GET | Past scan history |
| `osint/deep` | POST | Inline deep scan (lighter version) |

**External:** `api/osint-deep.php` (POST) -- Full OSI Deep Scanner (40+ modules)

### Banking Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `bank/connect` | POST | Start bank connection (OAuth2) |
| `bank/list` | GET | Available banks |
| `bank/auto-sync` | POST | Fetch + match transactions |
| `bank/export` | GET | CSV payment export |

### Communication Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `messages/send` | POST | Create message |
| `messages` | GET | List messages |
| `messages/translate` | POST | AI translation update |
| `email/send` | POST | Send email template or generic email |
| `email/reminders` | POST | Send tomorrow's job reminders |

### GPS Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `gps/update` | POST | Partner position update |
| `gps/live` | GET | All active partner positions |

### Utility Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `distance` | GET | Multi-modal distance + cost calculation |
| `webhook/booking` | POST | External booking handler |
| `sync/jobs` | POST | Bulk job sync from La-Renting |
| `settings/update` | POST | Update system settings |
| `security/hash-passwords` | POST | Bulk bcrypt migration |
| `migrate/messages` | POST | Create messages table + schema updates |
| `export/workhours` | GET | Work hours CSV export |
| `export/invoices` | GET | Invoice CSV export |

---

## 7. Deployment

### Deploy Script (`scripts/deploy.sh`)

**Workflow:**
1. **PHP Syntax Check** -- Runs `php -l` on all `.php` files; aborts on errors
2. **Backup** -- Creates timestamped backup of current production files
3. **SCP Upload** -- Transfers files via SCP to Hostinger (port 65002)
4. **Post-Verification** -- Hits `health.php` to verify deployment success
5. **Telegram Notification** -- Sends deployment report via n8n webhook

### SSH Configuration

```
Host: Hostinger Shared
Port: 65002
User: u860899303
Key: hostinger_jwt
```

### Git Workflow

- Repository: `github.com/fleckfrei/Fleckfrei.de` (private)
- Auth: SSH key
- Tags: Major milestones tagged for rollback reference
- No CI/CD pipeline -- manual deploy via `deploy.sh`

### Health Check

`api/health.php` -- Returns server status, PHP version, database connectivity, feature flags.

### Monitoring

`scripts/monitor.sh` -- Periodic health checks with alerting via Telegram.

### iCal Cron

`scripts/ical-cron.sh` -- Runs every 30 minutes, calls `ical/sync` endpoint to import new bookings from all active iCal feeds.

---

## 8. External Integrations

| Service | Purpose | API Type | Auth |
|---------|---------|----------|------|
| **Stripe** | Card + SEPA payments | REST API v1 | Secret Key (Bearer) |
| **PayPal** | Checkout buttons | REST API v2 | OAuth2 (Client ID + Secret) |
| **Smoobu** | Channel manager (Airbnb, Booking.com, VRBO, Agoda) | REST API | API Key (header) |
| **Telegram** | Admin notifications | via n8n webhook | Bot token (in n8n) |
| **n8n** | Workflow automation | Webhooks (4 endpoints) | Internal |
| **Enable Banking** | Open Banking (N26) | REST API | App ID + PEM certificate |
| **Nominatim** | Address geocoding | REST (free) | User-Agent header |
| **OSRM** | Route distance/duration (car/bike/walk) | REST (free) | None |
| **Shodan** | Port/service scanning | REST API | API Key |
| **VirusTotal** | Domain reputation | REST API v2 | API Key |
| **Hunter.io** | Email verification + discovery | REST API | API Key |
| **GLEIF** | Legal entity identifier lookup | REST API (free) | None |
| **OpenCorporates** | Company registry search | REST API (free) | None |
| **Have I Been Pwned** | Breach check (k-Anonymity) | REST API v3 | None (k-Anonymity) |
| **HackerTarget** | WHOIS, reverse IP, email search | REST API (free) | None |
| **DuckDuckGo** | Web intelligence search | Scraping | None |
| **crt.sh** | SSL certificate transparency | REST API (free) | None |
| **Wayback Machine** | Domain age verification | REST API (free) | None |
| **offeneregister.de** | German Handelsregister search | REST API (free) | None |
| **GDV Zentralruf** | German vehicle insurance lookup | Web | None |
| **AutoDNA** | Vehicle history check | Web | None |
| **Team Cymru** | IP-to-ASN mapping | DNS/RDAP | None |

---

## 9. Session History

### Session 5 -- Admin Panel Rebuild
- Complete admin panel rebuild from scratch
- 30+ features implemented
- Calendar sync, Stripe webhooks, iCal import/export
- Message system, Live Map foundation
- Customer/Employee/Service CRUD
- Dashboard with stats

### Session 6 -- Full Audit (36/36 Pages)
- All 36 admin pages audited and verified working
- OSINT v2 implementation (first generation scanner)
- Booking end-to-end flow testing
- Work hours calculation and reporting
- Invoice system with PDF generation
- Service and notification improvements

### Session 7 -- Monitor, Deploy, OSI Intelligence
- `monitor.sh` health monitoring script
- `deploy.sh` safe deployment with PHP syntax check + rollback
- Price fix and invoice editing improvements
- XRechnung XML export implementation
- Live Map editing capabilities
- Database encoding fix (utf8mb4)
- OSI Intelligence Scanner rebuild (11 modules)

### Session 8 -- Smoobu, PayPal, iCal, WorldMonitor
- Smoobu channel manager integration (6 endpoints + webhook)
- PayPal Checkout (v2 REST API, live keys)
- iCal Import engine (full VCALENDAR parser)
- OSI cache system + SPF Chain resolution
- CORS fix for multi-origin support
- WorldMonitor initial setup on VPS
- 12+ files changed, 14 files deployed

### Session 9 -- Deep OSINT (40+ Sources)
- OSI Deep Scanner rewrite: 2187 lines
- 40+ data sources integrated
- Pan-European license plate search (12 countries)
- Correlation Engine (Identity Graph)
- Verification Engine (EXACT/FUZZY/HEURISTIC)
- Self-Learning Algorithm (analyzes past scans for accuracy improvement)
- Airbnb profile scraper
- Impressum validator (fetch + extract + cross-check)
- Website Deep Scan (subpages, emails, phones, social links)
- NorthData + 9 business registries
- Name Permutation Engine
- Username Generator (30+ patterns)
- Gravatar, Keybase, Telegram OSINT
- Insolvency check (Bundesanzeiger)
- BGP/ASN lookup
- DNS Service Discovery (14 SRV prefixes)
- Open Banking (N26) auto-import
- Distance calculator (5 transport modes)
- GPS live tracking
- Bank transaction CSV export
- Security: bulk password hashing endpoint

---

## 10. Git History

Key milestones in the repository:

| Date | Description | Scope |
|------|-------------|-------|
| Session 5 | Admin Panel Rebuild | Initial platform, 30+ features |
| Session 6 | Full Audit Pass | 36/36 pages verified, OSINT v2 |
| Session 7 | Deploy + OSI v1 | deploy.sh, monitor.sh, XRechnung, 11 OSINT modules |
| Session 8 | Channel Manager | Smoobu, PayPal, iCal, WorldMonitor, CORS |
| Session 9 | Deep Intelligence | 40+ OSINT sources, Correlation, Self-Learning, Banking |

**Repository:** `github.com/fleckfrei/Fleckfrei.de` (private)  
**Branch Strategy:** Single branch (main), deploy tags for milestones  
**Commit Style:** Descriptive, session-based grouping  

---

## Appendix A: Configuration Constants

All constants defined in `includes/config.php`:

### White-Label
- `SITE` -- Brand name (Fleckfrei)
- `SITE_DOMAIN` -- Domain (fleckfrei.de)
- `SITE_TAGLINE` -- Tagline
- `BRAND` / `BRAND_DARK` / `BRAND_LIGHT` / `BRAND_RGB` -- Color scheme
- `LOGO_LETTER` -- Letter for icon (F)
- `CONTACT_EMAIL` -- info@fleckfrei.de
- `CURRENCY` / `CURRENCY_POS` -- EUR, after

### Business Rules
- `MIN_HOURS` -- 2 (minimum billable hours)
- `TAX_RATE` -- 0.19 (19% MwSt)
- `LOCALE` -- de
- `TIMEZONE` -- Europe/Berlin

### Databases
- `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` -- Master DB
- `DB_LOCAL_HOST` / `DB_LOCAL_NAME` / `DB_LOCAL_USER` / `DB_LOCAL_PASS` -- Local DB
- `API_KEY` -- REST API authentication key

### Webhooks
- `N8N_WEBHOOK_BOOKING` / `N8N_WEBHOOK_STATUS` / `N8N_WEBHOOK_NOTIFY` / `N8N_WEBHOOK_MESSAGE`

### API Keys
- `SHODAN_API_KEY`, `VT_API_KEY`, `HUNTER_API_KEY` -- OSINT services
- `OPENBANKING_APP_ID` -- Enable Banking
- `STRIPE_PK` / `STRIPE_SK` -- Loaded from stripe-keys.php
- `PAYPAL_CLIENT_ID` / `PAYPAL_SECRET` -- Loaded from paypal-keys.php
- `SMOOBU_API_KEY` -- Channel manager

---

## Appendix B: Helper Functions

Defined in `config.php`, available globally:

| Function | Signature | Description |
|----------|-----------|-------------|
| `q()` | `q($sql, $p=[])` | Prepared query on master DB |
| `all()` | `all($sql, $p=[])` | Fetch all rows from master DB |
| `one()` | `one($sql, $p=[])` | Fetch single row from master DB |
| `val()` | `val($sql, $p=[])` | Fetch single value from master DB |
| `qLocal()` | `qLocal($sql, $p=[])` | Prepared query on local DB |
| `allLocal()` | `allLocal($sql, $p=[])` | Fetch all rows from local DB |
| `oneLocal()` | `oneLocal($sql, $p=[])` | Fetch single row from local DB |
| `valLocal()` | `valLocal($sql, $p=[])` | Fetch single value from local DB |
| `qBoth()` | `qBoth($sql, $p=[])` | Dual-write (local + remote) |
| `qRemote()` | `qRemote($sql, $p=[])` | Write to remote (Hostinger master) |
| `e()` | `e($s)` | HTML escape (XSS prevention) |
| `money()` | `money($n)` | Format as "123,45 EUR" |
| `audit()` | `audit($action, $entity, $id, $details)` | Write audit log entry |
| `badge()` | `badge($status)` | Generate status badge HTML |
| `telegramNotify()` | `telegramNotify($msg)` | Send Telegram via n8n |
| `webhookNotify()` | `webhookNotify($event, $data)` | Fire n8n webhook |

---

*End of Technical Book*
