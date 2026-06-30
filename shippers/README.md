# Provado signal shippers

Merchant-side collectors that push Magento operational signals into New Relic as
`ProvadoSignal` custom events, which Provado then reads remotely via NerdGraph. See
`docs/signal-shipping.md` for the contract and `docs/shipping-methods.md` for a deeper
explanation of the three methods and how to choose. **Pick one** — they are interchangeable
and emit the same event shape; Provado's reader does not care which produced it.

| Shipper | Path | When to use |
|---|---|---|
| [`newrelic-flex/provado.yml`](newrelic-flex/provado.yml) | New Relic Flex (`nri-flex`) | Infra agent present; config-only, no code. The usual first choice. |
| [`php-agent/provado-ship.php`](php-agent/provado-ship.php) | Magento cron + New Relic PHP agent (`newrelic_record_custom_event`) | PHP agent present (Adobe Commerce Cloud); want it inside Magento's stack. |
| [`event-api/provado-ship.sh`](event-api/provado-ship.sh) | New Relic Event API (HTTP POST) | No agent/Flex; any host/language. Needs an Insert key. |

## Contract recap

Each signal is one `ProvadoSignal` event: `signal` (the type), `source`, optional
named entity attributes (`store`, `indexer`, `queue`, `cron_job`, `host`…), and
numeric metrics. **Ship raw state only** — dwell, baselines, correlation, and
deploy-stamping are Provado's job, not the shipper's.

> The New Relic PHP agent auto-adds internal attributes (`appId`, `realAgentId`, …)
> and `host` to custom events; Provado's reader filters the internal ones and treats
> `host` as an entity.

## Signals

Add a new signal by adding its source query and `ProvadoSignal` shape to each shipper
you use. Ordered by leverage:

| `signal` | Source query (Magento) | Metrics |
|---|---|---|
| `cron_health` | `cron_schedule` status counts | pending, running, success, missed, error |
| `indexer_status` | per view: `MAX(<view>_cl.version_id)` − `mview_state.version_id`, `indexer_state` | one event per `indexer`: backlog, working, invalid |
| `queue_backlog` | RabbitMQ mgmt API `/api/queues` per queue (+ DB `queue_message_status` fallback) | one event per `queue`: ready, unacked, consumers |
| `config_change` | `core_config_data` churn (the marker-less change surface) | changed_1h, changed_24h, latest_change_age_seconds |

> `indexer_status` and `queue_backlog` emit one `ProvadoSignal` per entity (view / queue), so they
> use the php-agent or Event API path, not a single Flex query. `queue_backlog` reads the RabbitMQ
> management API — configure `PROVADO_RABBITMQ_URL` / `PROVADO_RABBITMQ_USER` / `PROVADO_RABBITMQ_PASS`
> (defaults `http://localhost:15672`, `guest`/`guest`).

## Scheduling

Run every 1–5 minutes (cron / agent interval). Provado reads the latest state over a
window and computes dwell from the series. On the Provado lab this shipper is scheduled in
root's crontab every 5 minutes — see `docs/shipping-methods.md` → "Lab note" for the exact entry.
