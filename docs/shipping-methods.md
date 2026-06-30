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

### Variant — "Instrument" (bootstrapped) shipper

Most signals are **Wire-Up**: a DB row or a single CLI count, read read-only — the raw-SQL
`provado-ship.php` above. A few are **Instrument**: their state lives behind Magento's internal
APIs, not a table. `cache_validity` needs `Cache\TypeListInterface::getInvalidated()`; consumer
liveness needs the message-queue consumer config plus `LockManagerInterface::isLocked()` (the probe
Magento's own `ConsumersRunner` uses). These cannot be a `SELECT`, so they ship from
[`../shippers/php-agent/provado-ship-instrument.php`](../shippers/php-agent/provado-ship-instrument.php),
which **boots the Magento application** (`Bootstrap::create(...)->getObjectManager()`) before
reading. It is heavier than the raw-SQL path, so it runs on its own — usually less frequent — cron
entry alongside the Wire-Up shipper. Both emit the identical `ProvadoSignal` shape. Run it with
`--self-check` to confirm the bootstrap reaches the internal APIs (ships nothing).

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
needed). It is scheduled in root's OS-cron, firing every 5 minutes:

```cron
*/5 * * * * MAGENTO_ROOT=/var/www/html/magento /usr/bin/php /var/www/html/provado/shippers/php-agent/provado-ship.php >> /var/log/provado-ship.log 2>&1
```

so `ProvadoSignal` events arrive continuously and Provado's read window holds a real time series —
the precondition for dwell (v0.6.0). The shipper runs with the NR PHP extension **enabled** (that
is the shipping path); only the PHPUnit test run disables it (`-d newrelic.enabled=0`). The cron
points at the mirror checkout (`/var/www/html/provado`), whose shipper file is tracked and so
survives the `git reset --hard` the test mirror gets.

The heavier **Instrument** shipper (`provado-ship-instrument.php`, v0.7.0) runs on its own,
less-frequent entry — every 15 minutes — since it boots the Magento application:

```cron
*/15 * * * * MAGENTO_ROOT=/var/www/html/magento /usr/bin/php /var/www/html/provado/shippers/php-agent/provado-ship-instrument.php >> /var/log/provado-ship-instrument.log 2>&1
```

so `cache_validity` and `consumer_liveness` also arrive continuously, feeding the cron→cache and
cron→email edges. Same NR-extension-enabled shipping path; its own log keeps the heavier run's
output separate.
