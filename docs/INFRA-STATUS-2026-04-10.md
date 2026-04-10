# Fleckfrei Infrastructure Status — 2026-04-10

Live-Audit aller produktiven Systeme. Erzeugt am 2026-04-10 18:46 UTC durch direkte Checks (HTTP, SSH, MySQL, Docker).

## Public Domains — alle erreichbar

| Domain | HTTP | Content | Backend |
|---|---|---|---|
| `fleckfrei.de` | 200 | WordPress "Smart Home Care Berlin" | GoDaddy Apache (cPanel `nev4j6nx5t5k`) |
| `app.fleckfrei.de` | 302 → `/login.php` | `fleckfrei-admin` (Tailwind + PHP 8.3) | Hostinger LiteSpeed |
| `la-renting.de` | 200 | WordPress + legacy `addJob.php` | Hostinger |
| `app.la-renting.de` | 200 "Homepage" | la-renting Admin Panel (98 PHP files) | Hostinger |
| `n8n.la-renting.com` | 200 | n8n UI | VPS Docker |
| `worldmonitor.la-renting.de` | 200 | WorldMonitor App | VPS Docker (⚠️ unhealthy) |
| `maxcohost.host` | 301 | Weiterleitung | GoDaddy |

## VPS Hostinger KVM2 (89.116.22.185)

**System:** Ubuntu 24.04, 96 GB disk (56 G used / 40 G free), Uptime 15 days, Load 0.12, 8 GB RAM.

### Docker Containers (12 running)

| Container | Uptime | Port | Rolle |
|---|---|---|---|
| `n8n` | 10 days | 5678 | Main automation (MAX Co-Host v2.0, 167 nodes) |
| `postgres` | 2 weeks | 5432 | DBs: `fleckfrei_osint`, `n8n` |
| `mongodb` | 2 weeks | 27017 | Auth required |
| `evolution-api-r2w0-api-1` | 2 weeks | 41097 | WhatsApp Evolution API |
| `evolution-api-r2w0-redis-1` | 2 weeks | — | Evolution cache |
| `evolution-api-r2w0-postgres-1` | 2 weeks | — | Evolution DB |
| `searxng` | 20 h | 9090 | "Fleckfrei Search" (custom-branded) |
| `worldmonitor` | 33 h | 3030 | Main app — ⚠️ healthcheck fails |
| `worldmonitor-redis` | 33 h | 6379 | Cache |
| `worldmonitor-redis-rest` | 33 h | 8079 | Upstash REST proxy |
| `worldmonitor-ais-relay` | CrashLoop | — | 🔴 **AIS ship tracking — `AISSTREAM_API_KEY` fehlt** |
| `osint-api` | 4 days | 8899 | OSINT Scanner API |

### Native Services (non-Docker)

| Process | Port | Rolle |
|---|---|---|
| `nginx` (system) | 80/443 | Reverse Proxy — 4 sites: `worldmonitor`, `intel-worldmonitor`, `n8n`, `airbnb.la-renting.com` |
| `node /var/www/whatsapp-flow/server.js` | 3000 | WhatsApp Flow Webhook (PID 999, 15 days uptime) |
| `python3` (OSINT worker) | 8900 | OSI Deep Scan worker |
| `tor` | 9050 (local) | SOCKS proxy for OSINT rotation |

## Hostinger Shared Hosting (`u860899303@srv1047`)

**Domains hosted here:**
- `app.fleckfrei.de` → `fleckfrei-admin` (PWA with manifest.php + service worker, PHP 8.3)
- `app.la-renting.de` → la-renting admin
- `la-renting.de` → WordPress + legacy
- `fleckfrei.de`, `maxcohost.host` → DNS only (GoDaddy-hosted content)

**MySQL `u860899303_la_renting` — Top 15 tables by row count:**

| Table | Rows | Purpose |
|---|---|---|
| `logs` | 26.109 | Activity log |
| `jobs` | 12.132 | Cleaning jobs |
| `welmius_calendar` | 4.004 | Booking calendar |
| `invoices` | 1.082 | Invoices |
| `invoice_payments` | 992 | Payments |
| `ontology_objects` | 708 | OSI graph nodes |
| `customer_service` | 621 | Customer ↔ service map |
| `services` | 544 | Service catalog |
| `users` | 543 | All user accounts |
| `customer_address` | 499 | Addresses |
| `ontology_links` | 448 | OSI graph edges |
| `customer` | 434 | Customers |
| `osint_scans` | 204 | OSI scan history |
| `ontology_events` | 202 | OSI timeline |
| `max_faq` | 161 | MAX bot FAQ |

## GoDaddy (cPanel `nev4j6nx5t5k`)

| Domain | Content |
|---|---|
| `fleckfrei.de` | WordPress "Smart Home Care Berlin" (PRIMARY public site) |
| `maxcohost.host` | WordPress |
| `larentinggroup.com` | WordPress (DKIM panel) |

## GitHub Repos (account `fleckfrei`)

| Repo | Purpose | Synced to |
|---|---|---|
| `fleckfrei/Fleckfrei.de` | fleckfrei-admin source | `/opt/gitnexus-repos/fleckfrei-admin` (VPS), `/Users/fleckfrei.de/src/fleckfrei-admin` (Mac), `app.fleckfrei.de` (deployed) |
| `fleckfrei/worldmonitor` | Fork of `koala73/worldmonitor` | `/opt/worldmonitor` (VPS) |
| `fleckfrei/whatsapp-flow` | WhatsApp Flow webhook server | `/var/www/whatsapp-flow` (VPS) |
| `fleckfrei/max-cohost-bot` | n8n workflow backup | — |

## Issues Resolved This Session (2026-04-10 19:00 UTC)

### ✅ FIXED — `worldmonitor-ais-relay` crash loop
- Root cause: `AISSTREAM_API_KEY` env var was empty in `/opt/worldmonitor/.env`
- Fix: Key set in `.env` (not committed to git — lives only on VPS)
- Verification: container `Up (healthy)`, no more `AISSTREAM_API_KEY environment variable not set` in logs
- Impact: AIS ship tracking relay now operational

### ✅ FIXED — `worldmonitor` main app Upstash Redis integration
- Root cause: App was sending `wm-local-token` (default), but the `worldmonitor-redis-rest` proxy was started with `SRH_TOKEN=wm-fleckfrei-2026`, causing HTTP 401 on every Redis call. This silently disabled 8+ features (CorridorRisk, USNI, ShippingStress, SocialVelocity, ClimateNewsSeed, ChokepointFlows, PizzINT, DodoPrices)
- Fix: Added `REDIS_TOKEN=wm-fleckfrei-2026` to `/opt/worldmonitor/.env` (docker-compose reads it via `${REDIS_TOKEN:-wm-local-token}`), recreated worldmonitor container
- Verification: From inside worldmonitor container: `wget http://redis-rest:80/ping` with the correct token returns `{"result":"PONG"}`. The "Disabled (no Upstash Redis)" messages are completely gone from logs.
- Impact: all Redis-dependent features re-enabled

### 🔄 IN PROGRESS — Data seeding
- Root cause: 71 of 144 data sources in `/api/health` show status `EMPTY` — they were never successfully seeded because the Redis proxy was unreachable (see fix above)
- Action: `scripts/run-seeders.sh` started with correct Upstash credentials. Runs ~100 seed-*.mjs scripts sequentially.
- Will auto-skip any seeder requiring an external API key that isn't configured (see below)

## Still Open — External API Keys Needed for Full Functionality

The following data sources require external API keys in `/opt/worldmonitor/docker-compose.override.yml` (file doesn't exist yet — create it) to be fully populated:

| Source | Env Var | Where to get |
|---|---|---|
| Finnhub (market quotes) | `FINNHUB_API_KEY` | finnhub.io (free tier) |
| FRED (macro economics) | `FRED_API_KEY` | fred.stlouisfed.org |
| EIA (energy) | `EIA_API_KEY` | eia.gov/opendata |
| ACLED (conflict data) | `ACLED_ACCESS_TOKEN`, `ACLED_EMAIL`, `ACLED_PASSWORD` | acleddata.com |
| NASA FIRMS (wildfires) | `NASA_FIRMS` | firms.modaps.eosdis.nasa.gov |
| AviationStack (flight delays) | `AVIATIONSTACK` | aviationstack.com |
| OpenAQ / WAQI (air quality) | `OPENAQ_API_KEY`, `WAQI_API_KEY` | openaq.org, waqi.info |
| Groq (LLM summaries) | `GROQ_API_KEY` | console.groq.com (free) |

These are all free or have generous free tiers. Max can register for each and drop them into the override file.

## Separate Minor Issues Noted

- **Healthcheck IPv6 bug**: Docker healthcheck uses `wget -q http://localhost:8080/api/health` inside the Alpine container. BusyBox `wget` resolves `localhost` to IPv6 `::1`, but the Node server binds to IPv4 `0.0.0.0:8080` only, causing "connection refused". The app is actually healthy, but Docker marks it unhealthy. Fix: change `localhost` → `127.0.0.1` in the `docker-compose.yml` healthcheck line. Cosmetic only.
- **OREF alerts fetch failing**: `ais-relay` logs show `curl: (5) Unsupported proxy syntax in 'http://'` when fetching Israeli home front alerts. Empty proxy env var being passed to curl. Low priority.

## What's 100% Functional

- WordPress `fleckfrei.de` (Berlin public site)
- `fleckfrei-admin` login at `app.fleckfrei.de` (bcrypt + plaintext migration, 543 users)
- `la-renting` admin at `app.la-renting.de`
- n8n with 167-node MAX Co-Host v2.0 workflow (10 days uptime)
- WhatsApp Evolution API (2 weeks uptime)
- WhatsApp Flow Webhook on port 3000 (15 days uptime)
- Postgres with `fleckfrei_osint` + `n8n` DBs
- SearXNG (custom-branded "Fleckfrei Search")
- OSINT API + Python worker + Tor exit rotation
- Gitnexus MCP (3 repos indexed: fleckfrei-admin, worldmonitor, whatsapp-flow; 1895+115+4 files, 17k+ symbols)
- MySQL: 12.132 real jobs, 434 customers, 1.082 invoices

## Capacity Headroom

- VPS: 40 GB disk free, Load 0.12 (idle), 15 days stable uptime
- Hostinger shared: unknown (MCP query needed)
- No critical memory/CPU pressure visible
