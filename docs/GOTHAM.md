# Gotham — Palantir-Lite Ontology

*Typed object graph over every scan, DB user, and past investigation.*

## Why

OSINT scans come and go. After a year you have thousands of them and no way to ask:

- "Has this email ever appeared before?"
- "Which customers share an address?"
- "Who else linked to this domain?"
- "Show me everything we know about Max Mustermann."

Gotham is an **indexed ontology layer** that accumulates every scan into a typed object graph. You search it like Google. Click a result to see its link graph, timeline, and all known relations. Click a graph node to navigate. Click **Cascade** to fire a fresh VULTURE scan from that node.

## Model

Three tables. Typed objects, typed links, dated events.

### `ontology_objects`

```sql
obj_id        BIGINT PRIMARY KEY
obj_type      VARCHAR(24)   -- person | company | email | phone | domain | handle | address
obj_key       VARCHAR(255)  -- canonical lookup key (lowercased, stable)
display_name  VARCHAR(500)  -- human label
properties    JSON          -- free-form attributes
confidence    FLOAT         -- 0.0–0.99, 1 − 0.67^n_sources
verified      TINYINT       -- 1 if seen in ≥3 sources
source_count  INT           -- how many sources attest
source_scans  JSON          -- array of scan_ids that contributed
first_seen    DATETIME
last_updated  DATETIME
```

**Upsert semantics:** `(obj_type, obj_key)` is unique. Every ingest merges: properties are merged, source_scans is extended, confidence is recalculated from new source count.

### `ontology_links`

```sql
link_id    BIGINT PRIMARY KEY
from_obj   BIGINT  -- foreign ontology_objects.obj_id
to_obj     BIGINT
relation   VARCHAR(64)   -- has_email | has_phone | lives_at | owns | associated_with | mentioned_email | uses_handle
source     VARCHAR(128)  -- which module detected this link
confidence FLOAT
```

**Upsert:** `(from_obj, to_obj, relation)` is unique. Higher confidence wins.

### `ontology_events`

```sql
event_id    BIGINT PRIMARY KEY
obj_id      BIGINT
event_type  VARCHAR(64)   -- scan | gotham_expand | breach | registry_filing | mention | osint_scan_imported
event_date  DATE
title       VARCHAR(500)
data        JSON
source      VARCHAR(128)
```

**Timeline-first** — events show per object in chronological order (newest first), rendered in the detail panel and briefing book.

## Ingestion paths

| Trigger | Handler | Writes |
|---------|---------|--------|
| Every VULTURE cascade completes | `ontology_ingest_scan()` auto-called | All graph nodes + identity block + scan event |
| Click on "past scan" in UI  | `/api/gotham-expand.php?action=ingest_scan` | person + email/phone/address + mentioned_emails |
| Click on ontology node + 🦅 Cascade | `/api/gotham-expand.php?action=cascade` | Fires new VULTURE cascade from that node |
| Mass-import from DB tables | `scripts/gotham_ingest_all.php` | Every customer/employee row as person with linked identifiers |

## APIs

### `POST /api/gotham-search.php`

```json
{
  "query": "rosa",
  "type_filter": "person",   // optional: person|company|email|phone|domain|handle|address|all
  "include_live": false      // if true: also query SearXNG live (+15s)
}
```

Returns:

```json
{
  "success": true,
  "data": {
    "query": "rosa",
    "ontology": [ { "obj_id": 31, "obj_type": "person", "display_name": "Rosa Gortner", "confidence": 0.7, "verified": 1, "source_count": 3, ... } ],
    "scans":    [ { "scan_id": 154, "scan_name": "Rosa Gortner", "scan_email": "rosa_gortner@gmx.de", ... } ],
    "live":     [ ... ],
    "counts":   { "ontology": 4, "scans": 15, "live": 10, "total": 29 }
  }
}
```

### `GET /api/gotham-expand.php?action=detail&obj_id=X`

Full object with events + incoming/outgoing links.

### `GET /api/gotham-expand.php?action=graph&obj_id=X&depth=2`

N-hop subgraph in Cytoscape JSON format:

```json
{
  "nodes": [ { "data": { "id": "n31", "label": "Rosa Gortner", "type": "person", "verified": 1, "obj_id": 31 } }, ... ],
  "edges": [ { "data": { "id": "e12", "source": "n31", "target": "n32", "label": "has_email" } }, ... ]
}
```

### `POST /api/gotham-expand.php` body `action=cascade, obj_id=X, depth=2, mode=fast`

Fires VULTURE cascade from the clicked object, ingests results, logs expansion event, returns cascade stats.

### `POST /api/gotham-expand.php?action=ingest_scan&scan_id=X`

One-click import of a past `osint_scans` row into the ontology. Returns `{obj_id, stats}`.

## UI

Integrated into `/admin/scanner.php` — look for the **"Ontology Graph"** section below "Deep Scan Ergebnisse." It uses the standard admin light theme (brand green on white, no dark Palantir aesthetic).

Layout:

```
┌─ Ontology Graph ─────────────────────────────────────────────┐
│ [ search input .......................... ] [LIVE] [Suchen] │
│ [ALLE] [Person] [Firma] [Email] [Tel] [Domain] [Handle] …   │
│ ┌───── Results (40%) ─────┐  ┌──── Link Graph (60%) ─────┐  │
│ │ ONTOLOGY                 │  │                           │  │
│ │  • Rosa Gortner    ✓ 70% │  │         ●──────●          │  │
│ │                          │  │         │      │          │  │
│ │ PAST SCANS               │  │         ●      ●          │  │
│ │  • Rosa Gortner #154     │  │                           │  │
│ │  • Rosa Gortner #146     │  │ [🦅 Cascade] [📄 Briefing]│  │
│ │                          │  └───────────────────────────┘  │
│ │ LIVE (SearXNG)           │  ┌─── Detail panel ──────────┐  │
│ │  • YellowMap listing     │  │ Rosa Gortner              │  │
│ │  • Infobel entry         │  │ Timeline + Links          │  │
│ └──────────────────────────┘  └───────────────────────────┘  │
└──────────────────────────────────────────────────────────────┘
```

### Click behavior

- Click **ontology result** → load graph + detail for that object
- Click **past scan result** → auto-ingest into ontology, then load the resulting object
- Click **live result** → open in new tab (no ingestion)
- Click **graph node** → navigate to that object (becomes new center)
- Click **🦅 Cascade** → fire VULTURE cascade from current object (15–30s)
- Click **📄 Briefing** → open `/admin/gotham-briefing.php?obj_id=X` (A4 print-ready)

## Briefing Book

`/admin/gotham-briefing.php?obj_id=X` — A4 print-ready HTML, styled for `@media print`. Contains:

- Subject header with type + verified badge
- 4 stat cards (confidence, sources, linked objects, events)
- Synthetic Truth Report narrative (last VULTURE scan)
- Properties table
- Known Associations table (links out)
- Incoming References table (links in)
- Event Timeline
- Audit footer (generator, obj_id, first_seen)

Click the "🖨 Print / Save PDF" button to export.

## Helper functions (`includes/ontology.php`)

```php
ontology_canonicalize($type, $value)       // stable lookup key per type
ontology_upsert_object($type, $name, $props, $scanId, $confidence)  // returns obj_id
ontology_upsert_link($fromId, $toId, $relation, $source, $confidence)
ontology_add_event($objId, $type, $date, $title, $data, $source)
ontology_ingest_scan($vcPayload, $scanId)  // main entry, reads VULTURE response
ontology_search($query, $typeFilter, $limit)
ontology_get_graph($rootId, $depth)        // N-hop BFS subgraph
ontology_get_object($objId)                // full detail + events + links
```

## Known limitations

- **No NLP extraction yet** — only regex (emails, phones, domains) and structured fields. Free-text bios aren't parsed for names.
- **No cluster detection** — duplicate people with different names aren't merged automatically.
- **No fulltext index** — search uses `LIKE %q%` on display_name/obj_key. Fine up to ~10k objects.
- **No ACLs** — any admin sees all ontology objects. No row-level permissions.
- **No watchlist/alerts** — no push notification when a new scan hits a saved target.

See `scripts/gotham_ingest_all.php` for bulk import of existing customer/employee data into the ontology.

## Files

```
includes/schema.php           ← 3 table definitions (auto-created on load)
includes/ontology.php         ← normalizer + search + graph functions
api/gotham-search.php         ← unified search endpoint
api/gotham-expand.php         ← detail | graph | cascade | ingest_scan
admin/scanner.php             ← UI (integrated into OSI Scanner)
admin/gotham-briefing.php     ← A4 PDF-ready briefing book
admin/gotham.php              ← 3-line redirect (legacy bookmark)
scripts/gotham_ingest_all.php ← mass import from customer/employee/addresses
```
