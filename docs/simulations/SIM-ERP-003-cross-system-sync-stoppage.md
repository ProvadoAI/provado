# SIM-ERP-003 — Cross-System Sync Stoppage / Partial Sync

## Status

Draft (documented from primary sources; not yet reproduced on lab).

## Purpose

Simulate data pushed between Magento and the ERP (Odoo) stopping or arriving
partial, while **the store keeps serving old data with no surfaced error**. The
defining trap: an asynchronous push returns `accepted` (HTTP 202) and the caller
marks it done — but **acceptance is not success**. `[verified]` The real outcome
lands later in the operation/bulk records, and if the consumer dies or operations
fail, nothing errors loudly.

## Environment

### Magento lab

- Magento 2.4.9
- CentOS Stream 9
- Apache + PHP 8.3
- MariaDB
- OpenSearch
- Redis
- RabbitMQ
- New Relic APM

### ERP lab

- Odoo Community 19.0 / PostgreSQL 16
- URL: `https://odoo.temiandu.com`
- Database: `provado_erp_lab`
- API user: `magento.api@provado.local`
- APIs tested: XML-RPC

### Odoo modules installed

- Sales, Inventory
- Custom addon: `provado_erp_faults`

## Test data

### Products

| SKU | Name | Purpose |
|---|---|---|
| `ERP-TSHIRT-001` | Magento Sync T-Shirt | Bulk stock/price push target |
| `ERP-MUG-001` | Magento Sync Mug | Second item to confirm partial-batch behavior |

### Customer

| Field | Value |
|---|---|
| Name | Magento Test Customer |
| Odoo partner ID | `7` |

## Fault injection

Magento async/bulk inbound writes are the cleanest lab surface. `[verified]`

1. Drive an inbound write through the async REST endpoint (returns `bulk_uuid` +
   status `accepted`). `[verified]`
2. Inject the stoppage by **killing or pausing the `async.operations.all`
   consumer** (or stopping `consumers_runner` cron) so the queued message is
   never processed. `[verified]` consumer name.
3. Optionally force a partial batch: make one operation in the batch fail
   (status `2` retriable / `3` not-retriable) while others complete, to exercise
   the partial-sync path. `[verified]` enum.

Outbound eventing variant (if exercised later): leave events at `event_data.status
= 0` (Waiting) by disabling the publishing cron — "if the status of an event is
still 0 … after a long period, then the crons are not configured correctly."
`[verified]`

## Reproduction steps

1. POST an async bulk update; record the returned `bulk_uuid` and the immediate
   `accepted` status.
2. Stop the consumer / cron (the fault).
3. Poll `GET /V1/bulk/:bulkUuid/status` and the DB; observe the batch never
   reaches a terminal success.
4. Confirm Odoo / Magento still serve the pre-push data.

## Observed behavior

⏳ Expected (not yet reproduced on lab), per primary sources:

- The push returns `202 accepted` immediately; the caller (ERP side) treats it as
  done and walks away. `[verified]`
- With the consumer stopped, `magento_operation` rows sit at status `4` (Open) /
  the bulk at `IN_PROGRESS (1)`; nothing transitions to `Complete (1)` /
  `FINISHED_SUCCESSFULLY (2)`. `[verified]` enum.
- Store keeps serving stale data; no exception, no slow transaction. `[verified]`
- Known Adobe defect to be aware of: bulk status can mis-report
  `FINISHED_WITH_ERRORS` while still `IN_PROGRESS`. `[verified]`

## Retry behavior

⏳ Expected.

### Bad behavior

Caller keys on the `202 accepted` receipt and never re-checks the terminal
operation status → believes the sync succeeded while data is stale.

### Correct behavior

Caller keys on the **terminal** state (`magento_operation.status` /
`bulk` FINISHED_SUCCESSFULLY), not the receipt; re-drives or alerts on operations
stuck at Open/Failed past their normal window.

## Stock impact

⏳ Expected — N/A directly. The downstream effect (stale stock/price) is the
subject of [SIM-ERP-002](SIM-ERP-002-stale-inventory-oversell.md); here the focus
is the sync-pipeline terminal state, not the inventory ledger.

## Failure classification

Type:

```text
Async boundary failure — acceptance mistaken for success (silent partial sync)
```

Failure chain:

```text
ERP pushes async write → Magento returns 202 accepted
→ consumer dies / cron stops / an operation fails
→ magento_operation stuck at Open(4) or Failed(2/3); bulk never FINISHED_SUCCESSFULLY
→ store keeps serving old data
→ no error surfaced; divergence discovered manually, late
```

## Business impact

- Stale prices/stock/catalog served to shoppers (mispricing, oversell, wrong PDP).
- ERP and storefront silently diverge; reconciliation is manual.
- Order-eventing variant: orders never reach the ERP → fulfillment never starts.

## Metrics/signals needed

### From Magento integration layer

- `magento_operation.status` (1 Complete / 2 Failed-retriable / 3 Failed-not /
  4 Open / 5 Rejected) + `error_code` / `result_message`. `[verified]`
- `magento_bulk` overall status (0 NOT_STARTED / 1 IN_PROGRESS / 2 FINISHED_SUCCESSFULLY
  / 3 FINISHED_WITH_FAILURE). `[verified]`
- `async.operations.all` consumer liveness + queue backlog. `[verified]`
- REST: `GET /V1/bulk/:bulkUuid/status`, `/operation-status/:status`,
  `/detailed-status`. `[verified]`
- Outbound (if used): `event_data.status` (0/1/2/3) + `info`; `events:list`. `[verified]`

### From Odoo

- `sale.order` by `client_order_ref` present vs missing for pushed orders.
- Orders with `client_order_ref` but no picking (incomplete downstream lifecycle).

### From Apache / reverse proxy

- Request duration and status for the sync endpoints; client-aborted requests.

### From New Relic APM

- Transaction duration / error rate for the sync transactions.
- (APM sees a 202 as a fast success — track *absence* of terminal completions as
  a derived signal, not an APM-native alert.)

### Provado-specific derived metric

```text
Sync Terminal-State Gap
```

Suggested rule:

```text
A push was accepted (202 / bulk created)
AND no matching magento_operation reached Complete within the learned window
   (OR operations landed at status 2/3, OR event_data rows pile at status 0/2)
THEN flag Sync Terminal-State Gap
```

Severity:

```text
High
```

Reason:

```text
ERP and storefront silently diverge; fulfillment or pricing breaks downstream.
```

## Detection query ideas

### Magento MySQL

```sql
-- operations not completing
SELECT status, COUNT(*) FROM magento_operation GROUP BY status;

-- bulks stuck below FINISHED_SUCCESSFULLY
SELECT uuid, description, start_time
FROM magento_bulk
WHERE uuid NOT IN (SELECT bulk_uuid FROM magento_operation WHERE status = 1);
```

### Magento CLI

```bash
bin/magento queue:consumers:list | grep async.operations.all
```

### Odoo PostgreSQL

```sql
-- orders pushed from Magento that never landed / never got a picking
SELECT name, client_order_ref, state
FROM sale_order
WHERE client_order_ref LIKE 'MAG-%'
ORDER BY create_date DESC;
```

## Mitigation pattern

- Caller must poll the **terminal** operation/bulk status, never trust `202`.
- Monitor `async.operations.all` consumer and the operation-status distribution;
  alarm on Open/Failed dwell beyond the learned window.
- For outbound events, monitor `event_data` for rows stuck at status 0 (cron) or
  2 (publish failure).

## Lab cleanup

```bash
# restart the stopped consumer and drain the backlog
bin/magento queue:consumers:start async.operations.all --max-messages=200
# re-enable consumers_runner cron if it was disabled (app/etc/env.php cron_run)
# reconcile any Odoo orders left without a picking
```

## Current result

Draft from Drive failure mode #8 (ERP/integration-conditional). Reproduction
pending. The inbound async-bulk path is the recommended first lab run; the
outbound Adobe I/O Events path is documented but lower priority for this lab.
Related: [SIM-ERP-001](SIM-ERP-001-api-timeout-creates-draft-order.md),
[SIM-QUEUE-001](SIM-QUEUE-001-order-sync-message-stuck.md).
