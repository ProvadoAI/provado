# SIM-ERP-002 — Stale Inventory Oversell / Reservation Drift

## Status

Draft (documented from primary sources; not yet reproduced on lab).

## Purpose

Simulate the integration state where Magento sells stock it does not really have,
because its salable-quantity view diverges from physical/ERP reality. Two
mechanisms produce the same revenue-damaging symptom:

- **ERP sync lag** — Odoo (system of record) drops stock, but Magento's
  `inventory_source_item` is only as fresh as the last sync, so Magento keeps a
  higher salable quantity and accepts orders that cannot be fulfilled.
- **MSI reservation drift** — Magento's append-only `inventory_reservation`
  ledger fails to net to zero for an order (a lost or duplicated compensation),
  so salable qty is silently too high (oversell) or too low (false out-of-stock).

The dangerous property: drift raises **no error** and returns a numerically
valid (but wrong) salable number. `[verified]`

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
- Stripe test mode

### ERP lab

- Odoo Community 19.0
- PostgreSQL 16
- Apache reverse proxy, HTTPS via Let's Encrypt
- URL: `https://odoo.temiandu.com`
- Database: `provado_erp_lab`
- API user: `magento.api@provado.local`
- APIs tested: XML-RPC

### Odoo modules installed

- Sales
- Inventory
- Custom addon: `provado_erp_faults`

## Test data

### Products

| SKU | Name | Purpose |
|---|---|---|
| `ERP-LOWSTOCK-001` | Low Stock Test Product | Primary oversell target (`free_qty` 3) |
| `ERP-TSHIRT-001` | Magento Sync T-Shirt | Control item (healthy stock) |

### Customer

| Field | Value |
|---|---|
| Name | Magento Test Customer |
| Email | `magento.customer.001@example.com` |
| Odoo partner ID | `7` |

## Fault injection

Two injection designs; both end in salable-vs-real divergence.

### Variant A — ERP sync lag (integration-side)

1. In Odoo, reduce physical stock of `ERP-LOWSTOCK-001` (e.g. via
   `stock.quant` + `inventory_quantity` + `action_apply_inventory`) to a low or
   zero `qty_available`.
2. **Skip / delay** the Odoo → Magento stock sync (do not push the new
   `inventory_source_item` qty). This is the injected fault: Magento's salable
   view stays stale.
3. Magento continues to show the old salable quantity and accepts checkout.

### Variant B — MSI reservation drift (Magento-side)

The append-only ledger must fail to net to zero. `[verified]` Options:

- Stop the `inventory.reservations.updateSalabilityStatus` consumer (Adobe warns
  it must run continuously), so salability never refreshes after orders. `[verified]`
- Simulate a lost compensation: place + then cancel/ship an order while the
  compensating-reservation event is suppressed, leaving a non-zero sequence.
  `[inferred]`

Note: `inventory_cleanup_reservations` cron only deletes sequences that already
sum to zero — it never repairs a non-zero sequence, so injected drift is
permanent until manually corrected. `[verified]`

## Reproduction steps

1. Record baseline salable qty in Magento and `qty_available`/`free_qty` in Odoo
   for `ERP-LOWSTOCK-001`.
2. Apply Variant A (drop Odoo stock, skip sync) **or** Variant B (stop the
   salability consumer / suppress a compensation).
3. Place one or more Magento orders for the SKU exceeding true availability.
4. Capture: Magento order accepted; Odoo cannot fulfill / reservation sequence
   ≠ 0; `inventory:reservation:list-inconsistencies` output.

## Observed behavior

⏳ Expected (not yet reproduced on lab), per primary sources:

- Magento accepts an order for stock that physically does not exist (Variant A)
  or salable qty stays wrong because the ledger no longer nets to zero (Variant
  B). `[verified]` mechanism.
- `bin/magento inventory:reservation:list-inconsistencies -r` emits a non-zero
  line in the form `ORDER:SKU:QTY:STOCK` (e.g. `172:ERP-LOWSTOCK-001:+2.000000:1`).
  `[verified]`
- No exception and no slow transaction — the salable computation returns a valid
  (wrong) number. `[inferred]`

## Retry behavior

⏳ Expected — N/A as a retry loop. The relevant recovery is **reconciliation**,
not retry: Adobe's manual remedy is `inventory:reservation:list-inconsistencies -r`
piped to `inventory:reservation:create-compensations`. `[verified]` Provado's role
is detection only — it never writes compensations.

## Stock impact

⏳ Expected. The contradiction lives across these fields:

| Field (Magento MSI) | Meaning |
|---|---|
| `inventory_source_item.quantity` | Per-source on-hand (stale vs Odoo in Variant A) |
| `GetProductSalableQtyInterface` | Computed salable = Σ source qty − \|reservations\| − OOS threshold |
| `inventory_reservation.quantity` | Append-only ledger; non-zero sum for a final-state order = drift |

| Field (Odoo) | Meaning |
|---|---|
| `qty_available` | Physical stock |
| `free_qty` / `virtual_available` | Sellable / projected (more relevant for Magento salable) |

## Failure classification

Type:

```text
Eventual-consistency / silent data divergence (no error raised)
```

Failure chain:

```text
Odoo stock drops (or a compensation is lost)
→ Magento source_item stale OR reservation sequence ≠ 0
→ GetProductSalableQty returns a wrong-but-valid number
→ Magento accepts an unfulfillable order (oversell) or hides stock (false OOS)
→ no exception, no slow transaction
→ cancellation / backorder / lost revenue surfaces days later
```

## Business impact

- Overselling → cancellations, refunds, support load, reputational damage.
- False out-of-stock → silent lost sales on real inventory.
- Industry framing: global inventory distortion estimated at **$1.73T/yr**
  (IHL Group, Sep 2025). `[verified as an industry estimate]`

## Metrics/signals needed

### From Magento integration layer

- Salable qty per SKU/stock over time (movement of `GetProductSalableQtyInterface`).
- `inventory:reservation:list-inconsistencies` non-zero count (scheduled).
- `inventory.reservations.updateSalabilityStatus` consumer liveness +
  `inventory_cleanup_reservations` cron last run. `[verified]`
- Legacy vs MSI disagreement: `cataloginventory_stock_item.is_in_stock` /
  `cataloginventory_stock_status` vs MSI salable service. `[verified]`

### From Odoo

- `qty_available`, `free_qty`, `virtual_available` per product.
- Delta between Odoo sellable qty and Magento `inventory_source_item.quantity`
  (the sync-lag width).

### From New Relic APM

- (Low signal — drift is invisible to APM.) Track order-cancellation rate and
  checkout-failure rate as lagging proxies.

### Provado-specific derived metric

```text
Inventory Salability Drift
```

Suggested rule:

```text
A final-state order's inventory_reservation sequence sums to ≠ 0
OR Magento source_item qty diverges from Odoo sellable qty beyond in-flight
   reservation volume for more than N minutes
(optionally) AND updateSalabilityStatus consumer is not draining
THEN flag Inventory Salability Drift
```

Severity:

```text
High
```

Reason:

```text
Direct oversell (cancellations / refunds) or silent lost sales on real stock.
```

## Detection query ideas

### Magento MySQL

```sql
-- reservation sequences that do not net to zero (drift fingerprint)
SELECT object_id, sku, stock_id, SUM(quantity) AS net
FROM inventory_reservation
GROUP BY object_id, sku, stock_id
HAVING net <> 0;
```

```sql
-- legacy vs MSI source disagreement for a SKU
SELECT * FROM cataloginventory_stock_item WHERE product_id = :id;
SELECT * FROM inventory_source_item WHERE sku = 'ERP-LOWSTOCK-001';
```

### Magento CLI

```bash
bin/magento inventory:reservation:list-inconsistencies -r
bin/magento queue:consumers:list | grep updateSalabilityStatus
```

### Odoo PostgreSQL

```sql
-- sellable vs physical for the target SKU (compare against Magento source qty)
SELECT default_code, qty_available, free_qty, virtual_available
FROM product_product pp JOIN product_template pt ON pp.product_tmpl_id = pt.id
WHERE default_code = 'ERP-LOWSTOCK-001';
```

## Mitigation pattern

- Run `inventory:reservation:list-inconsistencies` on a schedule, not on demand.
- Keep `updateSalabilityStatus` consumer monitored as a leading indicator.
- Treat Odoo→Magento stock sync as a tracked operation with a freshness SLA;
  alarm when source_item age exceeds the sync window.
- Repair drift with `create-compensations` (human action), never silently.

## Lab cleanup

```bash
# Variant A: re-run the Odoo -> Magento stock sync to refresh source_item
# Variant B: restart the salability consumer and reconcile
bin/magento queue:consumers:start inventory.reservations.updateSalabilityStatus --max-messages=100
bin/magento inventory:reservation:list-inconsistencies -r | \
  bin/magento inventory:reservation:create-compensations
# Restore Odoo stock to baseline via stock.quant + action_apply_inventory
```

## Current result

Draft from Drive failure mode #2. Reproduction pending: decide Variant A vs B
(or both) for the first lab run; A exercises the ERP-sync-lag signals, B exercises
the MSI reservation-ledger signals. Related: [SIM-ERP-001](SIM-ERP-001-api-timeout-creates-draft-order.md),
[SIM-ERP-003](SIM-ERP-003-cross-system-sync-stoppage.md).
