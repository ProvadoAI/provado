# SIM-PAY-001 — Payment Success, Order Not Created (Silent Order Loss)

## Status

Draft (documented from primary sources; not yet reproduced on lab).

## Purpose

Simulate a checkout that takes the shopper's money at the PSP but **never creates
a `sales_order` row**. The conversion starts (a reserved increment id is written
to the quote), payment authorizes/captures at Stripe, then something throws before
the order persists — so the money is gone and no order exists. `[verified]` This is
the cleanest APM blind spot: the missing thing is a **row, not a trace**.

## Environment

### Magento lab

- Magento 2.4.9
- CentOS Stream 9
- Apache + PHP 8.3
- MariaDB
- OpenSearch / Redis / RabbitMQ
- New Relic APM
- **Stripe test mode** (PSP under test)

## Test data

### Products

| SKU | Name | Purpose |
|---|---|---|
| `ERP-TSHIRT-001` | Magento Sync T-Shirt | Standard checkout item |
| `ERP-LOWSTOCK-001` | Low Stock Test Product | Stock-race variant (stock = 1) |

### Customer

| Field | Value |
|---|---|
| Name | Magento Test Customer |
| Email | `magento.customer.001@example.com` |

## Fault injection

The silent-loss window is between payment authorization (inside
`orderManagement->place($order)`) and the order persist. `[verified]` Inject a
throw in that window. Sub-variants from the source:

- **Throwing plugin/observer.** A custom module observes
  `sales_model_service_quote_submit_before` (or `..._success`) and throws *after*
  `place()` authorized payment but *before* the order is saved. `[verified]` This
  mirrors the `provado_erp_faults` override pattern already used in SIM-ERP-001.
- **Stock race.** Product stock = 1; a slow gateway lets stock fall to 0; PSP
  captures and the callback returns, but Magento fails to create the order because
  stock is now 0. `[verified]` (issue #25862)
- **Grid-insert deadlock.** Under load, `INSERT INTO sales_order_grid` throws
  `SQLSTATE[40001] 1213 Deadlock`; mitigation is `dev/grid/async_indexing = 1`.
  `[verified]` Reproduce by forcing synchronous grid insert under contention.

## Reproduction steps

1. Enable the chosen fault (plugin throw is the most deterministic for the lab).
2. Place a checkout with a Stripe **test** card that authorizes successfully.
3. Observe the client error ("A server error stopped your order from being
   placed…") while Stripe shows a successful charge/authorization. `[verified]`
4. Confirm in DB: a `quote` row with `reserved_order_id` set, `is_active = 1`, and
   **no** matching `sales_order.increment_id`. `[inferred]` fingerprint.

## Observed behavior

⏳ Expected (not yet reproduced on lab), per primary sources:

- Stripe (test) records a captured/authorized transaction tied to the Magento
  increment id. `[verified]` outcome.
- `var/log/exception.log` shows the placement `LocalizedException` and/or
  "An exception occurred on 'sales_model_service_quote_submit_failure' event."
  `[verified]` strings.
- The orphaned-quote fingerprint exists; the admin order grid has no order.
  `[inferred]` / `[verified]`.

## Retry behavior

⏳ Expected.

### Bad behavior

The shopper retries / support recreates the order manually, risking a **double
charge** or a duplicate order; the orphaned quote is never reconciled against the
PSP capture.

### Correct behavior

Reconcile the PSP capture list against `sales_payment_transaction` + `sales_order`
by increment id; a captured txn with no order is flagged within minutes. Provado
detects only — it never recreates orders (unlike paid "Missing Orders"
extensions, which mutate the DB). `[verified]`

## Stock impact

⏳ Expected — N/A as a reservation-ledger case. In the stock-race variant the order
simply never reserves; track via the orphaned-quote fingerprint, not stock fields.

## Failure classification

Type:

```text
Partial failure — money captured, order row never written (silent loss)
```

Failure chain:

```text
Checkout calls placeOrder → reserveOrderId writes reserved_order_id to quote
→ orderManagement->place() authorizes/captures at Stripe
→ a plugin/observer/DB exception throws before the order persists
→ rollbackAddresses runs; quote stays is_active=1; no sales_order row
→ PSP holds the money; admin grid shows no order
→ surfaces via chargeback / "charged but no order" ticket, days later
```

## Business impact

- Customer charged with no order → chargebacks, refunds, trust damage.
- Revenue recognized at PSP but unfulfillable / invisible in Magento.
- A peak-hour incident lost **400+ paid orders** (issue #25862, MDVA-31519). `[verified]`
- A market of paid "Missing Orders" recovery extensions exists — direct evidence
  the platform leaves this gap open. `[verified]`

## Metrics/signals needed

### From Magento integration layer

- `quote` rows where `reserved_order_id IS NOT NULL AND is_active = 1` with no
  matching `sales_order.increment_id` (leading internal indicator). `[verified]` ordering.
- `sales_order` vs `sales_order_grid` divergence. `[verified]`
- `exception.log` / `system.log` for placement / submit_failure strings. `[verified]`
- MySQL `SHOW ENGINE INNODB STATUS` for `1213` / `1205` on `sales_rule`,
  `sales_order_grid`, `sales_invoice_grid`, `inventory_source_item`. `[verified]`
- `dev/grid/async_indexing` setting. `[verified]`

### From the PSP (Stripe test)

- Captured/authorized transaction list reconciled against
  `sales_payment_transaction` + `sales_order` by increment id / txn id. `[verified]`

### From New Relic APM

- (Cleanest blind spot — no error transaction for an order that never got
  created.) Use orphaned-quote rate and PSP-to-order match rate as derived
  signals. `[inferred]`

### Provado-specific derived metric

```text
Paid-But-No-Order (Silent Order Loss)
```

Suggested rule:

```text
A Stripe transaction is captured/authorized
AND its Magento increment id has no sales_order row
   (equivalently: a quote with that reserved_order_id is still is_active = 1)
THEN flag Paid-But-No-Order
```

Severity:

```text
Critical
```

Reason:

```text
Customer paid; no order exists. Direct revenue + chargeback + trust exposure.
```

## Detection query ideas

### Magento MySQL

```sql
-- orphaned-quote fingerprint: conversion started, order never created
SELECT q.entity_id, q.reserved_order_id, q.is_active, q.grand_total, q.updated_at
FROM quote q
LEFT JOIN sales_order o ON o.increment_id = q.reserved_order_id
WHERE q.reserved_order_id IS NOT NULL
  AND q.is_active = 1
  AND o.entity_id IS NULL;
```

```sql
-- order rows missing their grid row (grid-insert deadlock)
SELECT o.increment_id
FROM sales_order o
LEFT JOIN sales_order_grid g ON g.entity_id = o.entity_id
WHERE g.entity_id IS NULL;
```

### Log grep

```bash
grep -E "stopped your order|sales_model_service_quote_submit_failure|Rolled back transaction" \
  /var/log/exception.log /var/log/system.log
```

## Mitigation pattern

- Continuously reconcile PSP captures against `sales_order` by increment id.
- Detect the orphaned-quote fingerprint *before* the customer complains.
- Set `dev/grid/async_indexing = 1` to remove synchronous grid-insert deadlocks.
- Never auto-recreate orders blindly — reconcile to avoid double charges.

## Lab cleanup

```bash
# disable the injected throwing plugin / restore stock for the race variant
# refund or void the test charge in the Stripe test dashboard
# optionally deactivate the orphaned quote rows used in the test
```

## Current result

Draft from Drive failure mode #6. Reproduction pending; the throwing-plugin
variant is the most deterministic first lab run and reuses the
`provado_erp_faults` injection pattern. Related:
[SIM-PAY-002](SIM-PAY-002-order-created-payment-never-captured.md),
[SIM-ERP-001](SIM-ERP-001-api-timeout-creates-draft-order.md).
