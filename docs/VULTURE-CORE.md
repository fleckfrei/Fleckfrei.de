# VULTURE-CORE v3.0 — Recursive Cascade Orchestrator

*Palantir-style recursive OSINT engine on top of Fleckfrei's 40+ module scan layer.*

## Purpose

`osint-deep.php` is a single-shot scanner — it runs a fixed set of modules once and returns. VULTURE-CORE is a **meta-orchestrator** that wraps it and gives:

- **Recursive cascades** — every finding is a new seed
- **3-source triangulation** — a fact is only "verified" when ≥3 independent sources agree
- **99% confidence threshold** — scan continues until the threshold is reached or depth is exhausted
- **Behavioral fingerprint** — language, tone, activity profile for alias-unmasking
- **Predictive vectoring** — rule-based follow-up queries (Booking→ImmoScout, Airbnb→Northdata, …)
- **AI cross-check** — Perplexity + SearXNG as an independent 3rd source
- **Sanitized-profile anomaly detection** — flags "too clean" fabricated identities
- **Palantir-style synthetic narrative** output

## Architecture

```
                ┌──────────────────────────┐
   POST seed →  │  /api/vulture-core.php   │  ← auth: session OR X-API-Key
                └────────────┬─────────────┘
                             │
                             ▼
            ┌────────────────────────────────────┐
            │  Cascade Loop  (depth 1-5)         │
            │  ┌──────────────────────────────┐  │
            │  │  run_deep_scan()             │  │  → internal HTTPS POST
            │  │  → /api/osint-deep.php       │  │    to same host
            │  └──────────────┬───────────────┘  │
            │                 ▼                  │
            │  ┌──────────────────────────────┐  │
            │  │  extract_seeds()             │  │
            │  │  - regex email/phone/domain  │  │
            │  │  - structured fields         │  │
            │  │  - noise blacklist (40+)     │  │
            │  │  - dedup + cap 8/layer       │  │
            │  └──────────────┬───────────────┘  │
            │                 ▼                  │
            │  ┌──────────────────────────────┐  │
            │  │  Graph state update          │  │
            │  │  confidence = 1 - 0.67^n     │  │
            │  │  verified = n_sources ≥ 3    │  │
            │  └──────────────────────────────┘  │
            │  Early-exit when ≥5 verified @99%  │
            └────────────────────────────────────┘
                             │
                             ▼
            ┌────────────────────────────────────┐
            │  Post-processing                   │
            │  - detect_sanitized()              │
            │  - behavioral_fingerprint()        │
            │  - predictive_vectors()            │
            │  - ai_crosscheck() (deep mode)     │
            │  - synthesize() → narrative        │
            └────────────────┬───────────────────┘
                             │
                             ▼
            ┌────────────────────────────────────┐
            │  Persist                           │
            │  - osint_scans row (audit trail)   │
            │  - ontology_ingest_scan()          │
            │    → typed objects + links + events│
            └────────────────────────────────────┘
```

## Request

```json
POST /api/vulture-core.php
X-API-Key: flk_api_*

{
  "name":    "Max Mustermann",
  "email":   "max@example.com",
  "phone":   "+49...",
  "address": "Musterstr. 1, 10115 Berlin",
  "domain":  "example.com",
  "company": "Musterfirma GmbH",

  "depth":   3,            // 1-5, default 3
  "mode":    "fast",       // fast | stealth (Tor) | deep (Tor + AI)
  "context": "customer vetting before contract" // REQUIRED, audit trail
}
```

## Response

```json
{
  "success": true,
  "vulture_core": "3.0",
  "config": { "depth": 3, "mode": "fast", "context": "..." },
  "report": {
    "confidence_overall": 0.87,
    "threshold_reached": false,
    "narrative": "Target 'X' analyzed across 3 cascade layers (42 total nodes, 7 verified 3-source). ...",
    "identity": { "primary_name": "...", "aliases": [...], "emails_verified": [...], "phones_verified": [...], "handles": [...] },
    "network": { "companies": [...], "business_registries": [...] },
    "digital_footprint": { "active_platforms": [...], "breaches_found": 0, "historical_snapshots": 12 },
    "risk_assessment": { "score": 15, "level": "LOW", "anomalies": [...] },
    "behavioral_fingerprint": { "dominant_language": "de", "tone": "formal", "profile_type": "business", ... },
    "predictive_vectors": [ { "trigger": "airbnb.com", "target": "northdata.de", "reason": "...", "query_url": "..." } ],
    "ai_crosscheck": { "searxng_hits": 10, "triangulation_strength": "moderate", "risk_signal": null }
  },
  "graph": { "nodes": [...], "edge_count": 12, "layers_executed": 3 },
  "ontology": { "objects_created": 11, "links_created": 2, "events_created": 1 },
  "elapsed_seconds": 31.08
}
```

## Modes

| Mode | Description | Latency |
|------|-------------|---------|
| `fast`    | Direct HTTPS to osint-deep, no proxy | 15–30s/layer |
| `stealth` | Routed through Tor SOCKS5 on VPS (`89.116.22.185:9050`) | 25–45s/layer |
| `deep`    | Stealth + Perplexity + SearXNG AI cross-check | 35–60s/layer |

## Noise Blacklist

`VULTURE_NOISE_DOMAINS` — 40+ hosts that are never "findings," only URL hosts of search results. Filtered from seed extraction so domain nodes reflect real leads:

```
google.*, bing.*, duckduckgo.*, yandex.*, yahoo.*, 
facebook.*, instagram.*, twitter.*, x.com, tiktok.*, youtube.*, linkedin.*, xing.*, 
pinterest.*, reddit.*, snapchat.*, github.*, gitlab.*, stackoverflow.*, 
wikipedia.*, wikimedia.*, ebay.*, kleinanzeigen.*, markt.*, quoka.*, 
web.archive.org, archive.org, airbnb.*, booking.*, tripadvisor.*, vrbo.*, 
immobilienscout24.*, immowelt.*, insolvenzbekanntmachungen.*, bundesanzeiger.*, 
northdata.*, handelsregister.*, telegram.*, whatsapp.*
```

## Confidence Formula

```
confidence(n_sources) = 1 - 0.67^n_sources   (capped at 0.99)
verified = (n_sources ≥ 3)                    (3-source rule)
```

| n | confidence |
|---|------------|
| 1 | 0.33 |
| 2 | 0.55 |
| 3 | **0.70 (verified)** |
| 4 | 0.80 |
| 5 | 0.87 |
| ≥7 | 0.93+ |

## Predictive Vectoring Rules

| Trigger (found in scan) | → Predicted follow-up | Rationale |
|---|---|---|
| `booking.com`    | ImmoScout24            | Host on Booking → check property ownership |
| `airbnb.com`     | Northdata              | Airbnb host → check registered business |
| `handelsregister`| LinkedIn (owner)       | Company registered → owner LinkedIn |
| `northdata`      | Bundesanzeiger         | In Northdata → filings |
| `impressum`      | Google Maps            | Impressum → verify physical address |
| `tripadvisor`    | Google Reviews         | TripAdvisor hit → sweep reviews |

## AI Cross-Check (deep mode only)

Calls the VPS OSINT API v4 at `89.116.22.185:8900`:

- `POST /perplexity` — LLM profile + risk query (needs valid `PERPLEXITY_KEY` in systemd unit)
- `POST /searxng` — 250-engine local aggregator (profile query + negative-keyword sweep)

Triangulation: capitalized words >5 chars in AI answers are checked against the scan result blob. `matches ≥5 = strong`, `≥2 = moderate`, `<2 = weak`. Strong triangulation adds +15% confidence bonus.

## Database schema impact

Every successful cascade writes:
1. One row to `osint_scans` (remote DB, audit trail with full JSON report)
2. N objects + M links + 1 event to `ontology_*` tables (via `ontology_ingest_scan`)

See [GOTHAM.md](./GOTHAM.md) for the ontology layer details.

## Known limitations

- **Perplexity key expiry** — if `PERPLEXITY_KEY` is invalid, AI cross-check falls back to SearXNG only. Update via `systemctl edit osint-api` on VPS.
- **Hostinger MySQL timeout** — long cascades (>30s) cause PDO persistent connections to die. `vulture-core.php` explicitly reconnects `$db` + `$dbLocal` after the cascade loop before persisting.
- **Context required** — `context` field is required (audit trail). Empty → 400.
- **Depth capped at 5** — hardcoded to prevent exponential cascade blowup.
- **8 new seeds/layer max** — anti-explosion cap in `extract_seeds()`.

## Files

```
api/vulture-core.php       ← main orchestrator (~600 lines)
api/osint-deep.php         ← underlying single-shot scanner (~2340 lines)
includes/ontology.php      ← normalizer that writes graph to DB
admin/scanner.php          ← integrated UI (search + graph + detail)
```
