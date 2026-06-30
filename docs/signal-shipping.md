# Provado — Signal shipping (`ProvadoSignal` contract)

How Magento operational signals reach a **remote** Provado. Provado never connects to the
merchant's MySQL or host directly. Instead a small, merchant-side **shipper** pushes each signal
into New Relic as a custom event, and Provado reads it remotely via NerdGraph — the same client
built in v0.4.0.

The design goal: **one contract, three interchangeable shippers, one reader.** The merchant picks
whichever shipper fits their environment; Provado reads the same event shape regardless of how it
got there.

```
Magento-reachable signal (cron_schedule, indexer_state, getInvalidated(), …)
   │   shipper (merchant-side, pick one):
   │     A) New Relic Flex          — config, points at SQL/CLI
   │     B) Magento cron + PHP agent — newrelic_record_custom_event()
   │     C) Event API                — POST from any script
   ▼   all emit the SAME custom event:
[ New Relic ]  ── eventType: ProvadoSignal ──►  NerdGraph/NRQL  ──►  [ Provado (remote) ]
                                                                       → canonical Signal
                                                                       → correlation → diagnosis
```

## The contract: `ProvadoSignal`

A single New Relic **custom event type**, `ProvadoSignal`, discriminated by a `signal` attribute.
Custom events are flat key/value (string or numeric, no nesting), which maps cleanly onto the
canonical `Signal` model.

| Attribute | Type | Maps to | Notes |
|---|---|---|---|
| `signal` | string (required) | `SignalType` | e.g. `cron_health`, `indexer_status`, `queue_backlog` |
| `source` | string (required) | `SignalSource` | e.g. `magento`, or a per-instance id for multi-store |
| *entity attributes* | string | `EntityReference` | named attributes the reader maps to entity types — e.g. `store`, `indexer`, `queue`, `cron_job` (configurable, reuses the v0.4.0 `facet_entities` idea) |
| *metric attributes* | numeric | `Signal.attributes` | the readings — e.g. `missed`, `pending`, `backlog`, `dwell_seconds` |
| `timestamp` | numeric | `Signal.timestamp` | set by the shipper, or auto-stamped by New Relic on ingest |

**Golden rule — ship raw state, not verdicts.** The shipper emits the current snapshot each
interval and forgets it. Dwell time, learned-normal baselines, cross-signal correlation, and
deploy-stamping are **Provado's** job, not the shipper's ("ingestion is not diagnosis"). This keeps
shippers trivial and swappable, and keeps the intelligence in one place.

### Worked example — `cron_health`

Source query (Magento): `SELECT status, COUNT(*) FROM cron_schedule GROUP BY status`.

Emitted event(s):

```json
{ "eventType": "ProvadoSignal", "signal": "cron_health", "source": "magento",
  "missed": 3, "running": 1, "pending": 12, "success": 240, "error": 0 }
```

## Shipper A — New Relic Flex

Config-driven (`nri-flex`), no code. Each query row becomes a `ProvadoSignal` event.

```yaml
integrations:
  - name: nri-flex
    config:
      name: provado-magento
      apis:
        - name: ProvadoCronHealth
          event_type: ProvadoSignal
          database: mysql
          db_conn: "ro_user:pass@tcp(127.0.0.1:3306)/magento"
          custom_attributes: { signal: cron_health, source: magento }
          queries:
            - run: "SELECT status, COUNT(*) AS value FROM cron_schedule GROUP BY status"
```

> Verify the exact `database`/`db_conn`/`queries` keys against the installed `nri-flex` version.

## Shipper B — Magento cron + PHP agent

Runs on the Magento host (uses the New Relic PHP extension already present). A console command,
scheduled by cron:

```php
// provado:ship  — read state, ship one ProvadoSignal per logical entity
$counts = $connection->fetchPairs('SELECT status, COUNT(*) FROM cron_schedule GROUP BY status');

if (extension_loaded('newrelic')) {
    newrelic_record_custom_event('ProvadoSignal', [
        'signal'  => 'cron_health',
        'source'  => 'magento',
        'missed'  => (int) ($counts['missed'] ?? 0),
        'running' => (int) ($counts['running'] ?? 0),
        'pending' => (int) ($counts['pending'] ?? 0),
        'error'   => (int) ($counts['error'] ?? 0),
    ]);
}
```

## Shipper C — Event API

Any language, any host, no New Relic extension needed. POST to the Event API:

```bash
curl -s -X POST "https://insights-collector.newrelic.com/v1/accounts/$NR_ACCOUNT_ID/events" \
  -H "Api-Key: $NR_INSERT_KEY" \
  -H "Content-Type: application/json" \
  -d '[{"eventType":"ProvadoSignal","signal":"cron_health","source":"magento","missed":3,"pending":12,"error":0}]'
```

> EU accounts use `https://insights-collector.eu01.nr-data.net/...`. `Api-Key` is a New Relic
> Insert/License key. Verify the endpoint/key type against current New Relic docs before external use.

## The reader (Provado, remote)

Provado extends the v0.4.0 NerdGraph client with a generic custom-event path:

```sql
SELECT * FROM ProvadoSignal WHERE signal = 'cron_health' SINCE 30 minutes ago LIMIT MAX
```

Each event maps to a canonical `Signal`: `type` = `signal`, `source` = `source`, entity references
from the configured entity-attribute names, attributes = the numeric fields, timestamp = the
event's timestamp. **One reader handles every signal** — `signal='indexer_status'`,
`'queue_backlog'`, etc. — regardless of which shipper produced the event.

## Signal catalog (what to ship)

Per the signal docs (`Provado Signals and diagnosis.md` + `…- Additional.md`), each signal below
gets a source query/CLI and a `ProvadoSignal` shape. Ordered by leverage; build incrementally.

| `signal` | Source (Magento) | Key metrics |
|---|---|---|
| `cron_health` | `cron_schedule` GROUP BY status | missed, running, pending, error |
| `indexer_status` | `mview_state` vs `MAX(*_cl.version_id)`, `indexer_state` | backlog, stuck, invalid |
| `queue_backlog` | `queue_message_status` (+ RabbitMQ `/api/queues`) | ready, unacked, ack_rate, consumers |
| `cache_validity` | `getInvalidated()` (Instrument shipper) | one event per cache type: `cache` (entity), `invalidated` (0/1) |
| `consumer_liveness` | consumer config + `isLocked()` (Instrument shipper) | one event per consumer: `consumer` + `queue` (entities), `has_messages` (0/1), `running` (0/1) |
| `schema_drift` | `setup_module` schema vs data version | mismatched_modules |
| `maintenance_flag` | `var/.maintenance.flag` stat | present (0/1) |
| `order_integrity` | `quote` fingerprint, `sales_payment_transaction` aging | orphaned_quotes, uncaptured_aging |
| `config_change` | `core_config_data.updated_at` deltas | changed_paths (+ NR deploy markers) |

> Most are plain `SELECT`s the shipper runs locally (`provado-ship.php`) — the **"Wire-Up"**
> signals. A few (`cache_validity` via `Cache\TypeListInterface::getInvalidated()`, consumer
> liveness via the consumer config + `LockManagerInterface::isLocked()`) live behind Magento's
> internal APIs, so they ship from `provado-ship-instrument.php`, which boots the application —
> the **"Instrument"** signals. See `docs/shipping-methods.md` → "Instrument shipper" and
> `shippers/README.md` → "Wire-Up vs Instrument".
