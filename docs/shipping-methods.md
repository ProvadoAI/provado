# Shipping metrics into New Relic — the three methods

Provado runs **remotely**: it never connects to a merchant's MySQL, SSH, or host. The Magento
operational signals (cron health, indexer state, queue backlog, config churn, …) are pushed into
New Relic by a small **merchant-side shipper**, and Provado reads them back via NerdGraph (the
client built in v0.4.0) and runs the diagnosis on top.

There are **three interchangeable methods** to get those signals into New Relic. All three do the
same job — emit a `ProvadoSignal` custom event into NRDB — so the merchant picks whichever fits
their environment, and Provado's reader doesn't care which produced it. The contract and the
ready-to-use templates live in [`signal-shipping.md`](signal-shipping.md) and [`../shippers/`](../shippers/);
this document explains the **methods** themselves and how to choose.

```
Magento-reachable state (cron_schedule, indexer_state, getInvalidated(), RabbitMQ, core_config_data, …)
        │
        │   one of three shippers (merchant-side):
        │     1) New Relic Flex          — config, points at SQL/CLI
        │     2) Magento cron + PHP agent — newrelic_record_custom_event()
        │     3) Event / Metric API       — HTTP POST from any script
        ▼
   [ New Relic ]  — all emit eventType: ProvadoSignal
        │
        │   NerdGraph / NRQL  (Provado, remote)
        ▼
   [ Provado ]  → canonical Signal → correlation → diagnosis
```

Why through New Relic at all: NR is already present on nearly every Adobe Commerce merchant (it is
bundled into Adobe Commerce Cloud), so shipping into it gives zero-install reach and keeps Provado
fully remote. New Relic is the transport and the symptom/marker surface; it is **not** where the
diagnosis happens — "ingestion is not diagnosis" (see [`ARCHITECTURE_DIRECTION_SOURCE.md`](ARCHITECTURE_DIRECTION_SOURCE.md)).

---

## Method 1 — New Relic Flex (`nri-flex`)

**What it is.** An integration of the New Relic **Infrastructure agent**. You give it a YAML that
points at a shell command or a SQL query on an interval, and it ships the output as a custom event —
no code written.

**How it runs.** Install the infra agent → drop `provado.yml` under the agent's `integrations.d/` →
the agent runs the query on its configured interval and ships each row as a `ProvadoSignal`.

**Needs.** The New Relic Infrastructure agent installed on the host; a read-only MySQL user for DB
queries.

**Strengths.** Zero code; New Relic maintains the agent; ideal for signals that are "one SQL query →
a row of counts" (e.g. `cron_health`: `SELECT SUM(status='pending') AS pending, … FROM cron_schedule`).

**Limits.** Not suited to signals that need real logic. `indexer_status` joins each `mview_state`
row to its dynamically-named `<view>_cl` table, and `queue_backlog` reads the RabbitMQ management
API per queue — neither is a single Flex query. Those belong on Method 2 or 3.

**Template:** [`../shippers/newrelic-flex/provado.yml`](../shippers/newrelic-flex/provado.yml)

---

## Method 2 — Magento cron + PHP agent

**What it is.** A PHP script that reads the operational state and calls
`newrelic_record_custom_event('ProvadoSignal', [...])`. The **New Relic PHP extension** (already
present on Adobe Commerce Cloud) ships the event through its daemon — no Insert key, no HTTP.

**How it runs.** Schedule it from the OS cron or Magento's own cron, every 1–5 minutes.

**Needs.** The New Relic PHP extension enabled; database access (it runs on the Magento host).

**Strengths.** Full PHP, so it handles any logic — the per-view indexer backlog, the RabbitMQ
management-API calls for queues, etc. It is the most capable path, which is why Provado's reference
shipper is here and emits all of `cron_health` / `indexer_status` / `queue_backlog` / `config_change`.

**Limits.** It is code someone owns, and it runs on the Magento host. The agent auto-decorates
custom events with internal attributes (`appId`, `realAgentId`, `host`); Provado's reader filters
the internal ones and treats `host` as an entity.

**Template:** [`../shippers/php-agent/provado-ship.php`](../shippers/php-agent/provado-ship.php)

---

## Method 3 — Event / Metric API

**What it is.** A plain HTTP `POST` to the New Relic Event API:
`https://insights-collector.newrelic.com/v1/accounts/<account_id>/events` (EU:
`insights-collector.eu01.nr-data.net`), with the `ProvadoSignal` JSON in the body. Any language,
any host.

**How it runs.** Any cron/scheduler invoking the script (e.g. `curl`).

**Needs.** A New Relic **Insert/License key** (distinct from the User API key Provado uses to
*read*). No PHP extension, no infra agent.

**Strengths.** The most portable — runs from anywhere, touches nothing in the Magento stack.

**Limits.** You own the scheduling and the secret handling; it is the least Magento-aware path.

**Template:** [`../shippers/event-api/provado-ship.sh`](../shippers/event-api/provado-ship.sh)

---

## Choosing

| | Flex | PHP agent | Event API |
|---|---|---|---|
| Code to write | none (YAML) | PHP | a script |
| Needs | Infra agent + RO DB user | NR PHP ext + DB | Insert key |
| Key type | — | — (daemon) | Insert key |
| Logic flexibility | low (SQL only) | high | high |
| Runs on | Magento host | Magento host | anywhere |
| Best for | simple SQL-count signals | the full operational set | hosts with no agent/ext |

- **Default to Flex** for the simple SQL-count signals when the infra agent is already there.
- **Use the PHP agent** for the full operational set (it covers the signals Flex can't).
- **Use the Event API** when there is no agent/extension, or to ship from a separate box.

They are not mutually exclusive — a merchant can use Flex for some signals and the PHP agent for
others; Provado reads them all the same.

## Scheduling (continuous shipping)

A shipper must run on an interval (1–5 min) or the data in New Relic goes stale and the diagnosis
reflects an old snapshot. For the OS-cron + PHP-agent path:

```cron
*/5 * * * * MAGENTO_ROOT=/var/www/html/magento php /path/to/shippers/php-agent/provado-ship.php >/dev/null 2>&1
```

Provado reads the latest state over a window and (in a future dwell/baseline phase) computes how
long a contradiction has persisted from the series — so the shipper only ever ships **raw current
state**, never verdicts.

## Lab note

On the Provado lab the **PHP-agent** method is used (the NR PHP extension is present, no Insert key
needed). It has been run on demand during development; making it continuous is a one-line OS-cron
entry as above.
