# SIM-PAY-002 — Order Created, Payment Never Captured

## Status

Draft (documented from primary sources; not yet reproduced on lab).

## Purpose

Simulate an order that is placed and authorized but **never captured**: an
authorization transaction exists, no invoice is created, the gateway hold lapses,
goods ship against an order Magento treats as live, and the funds are never
collected. `[inferred]` composite. The root mechanism is the split between
**authorize** and **capture** — capture in Magento is bound to invoice creation.
`[verified]`

## Environment

### Magento lab

- Magento 2.4.9
- CentOS Stream 9
- Apache + PHP 8.3
- MariaDB / OpenSearch / Redis / RabbitMQ
- New Relic APM
- **Stripe test mode** (Payment Action under test)

## Test data

### Products

| SKU | Name | Purpose |
|---|---|---|
| `ERP-TSHIRT-001` | Magento Sync T-Shirt | Standard authorize-only order |

### Customer

| Field | Value |
|---|---|
| Name | Magento Test Customer |
| Email | `magento.customer.001@example.com` |

## Fault injection

Set the method's **Payment Action = Authorize** (not Authorize and Capture), then
ensure no invoice is ever created. `[verified]` Sub-variants:

- **Authorize-only, no invoice ever created.** Order placed, authorization
  recorded, but no operator/cron creates the invoice → no capture. `[inferred]`
- **Fraud / payment-review hold.** Order enters Payment Review / Suspected Fraud;
  the auto-invoice step that would capture is suspended. `[verified]` status.
- **Authorization expires before capture.** Stripe: "If the authorization expires
  before you capture the funds, the funds are released and the payment status
  changes to canceled." Card hold ≈ 7 days. `[verified]`

For the lab, the deterministic injection is Authorize-only + never invoice, then
age the authorization past Stripe's hold window.

## Reproduction steps

1. Configure the Stripe method Payment Action = **Authorize**.
2. Place an order with a Stripe **test** card; confirm an authorization txn.
3. Do **not** create an invoice. Let the order sit in Processing/Pending.
4. Capture state: `sales_payment_transaction` has an `authorization` row with no
   `capture` child; `sales_order_payment.amount_authorized > 0`, `amount_paid` 0;
   no `sales_invoice` row. `[verified]` / `[inferred]` columns.
5. (Optional) advance time past the Stripe hold window; confirm the PaymentIntent
   moves to canceled / capture no longer collectible. `[verified]`

## Observed behavior

⏳ Expected (not yet reproduced on lab), per primary sources:

- `sales_payment_transaction.txn_type = 'authorization'` exists with no child
  `capture` (linked by `parent_txn_id`). `[verified]`
- No `sales_invoice` row for the order. `[verified]`
- Order stays in a shippable state (Processing/Pending/Payment Review) while
  `amount_paid` is 0. `[verified]` statuses.
- After the hold window, Stripe releases the funds; late capture may succeed on
  one card network and silently fail on another. `[verified]` behavior.

## Retry behavior

⏳ Expected — N/A as a retry loop. The recovery is operational: create the invoice
to trigger capture **before** the hold expires, or void cleanly if abandoning.

## Stock impact

⏳ Expected — N/A. Stock is reserved/shipped normally; the leak is on the money
side, not inventory.

## Failure classification

Type:

```text
Revenue leak — authorized, never captured (uncollected funds)
```

Failure chain:

```text
Payment Action = Authorize → order placed, authorization recorded
→ no invoice created (manual step missed / fraud hold / broken auto-invoice cron)
→ no capture; amount_authorized > 0, amount_paid = 0
→ gateway hold lapses (Stripe ~7 days), funds released
→ goods ship against a live-looking order; money never collected
```

## Business impact

- Direct uncollected revenue (the clearest money-out-the-door of the set).
- Magento `amount_paid` and gateway settlement diverge.
- Scales silently with any flipped Payment Action or broken auto-invoice cron.

## Metrics/signals needed

### From Magento integration layer

- `sales_payment_transaction.txn_type` — `authorization` with no `capture` child
  (constants on `TransactionInterface`: TYPE_AUTH/TYPE_CAPTURE/TYPE_VOID/…). `[verified]`
- `sales_order_payment.amount_authorized > 0` with `amount_paid` NULL/0. `[inferred]` columns.
- `sales_invoice` absence for the `order_id`. `[verified]`
- `sales_order.state/status` in a shippable state without a matching invoice. `[verified]`
- Authorization aging: `created_at` of the auth txn vs the network validity window. `[verified]`

### From the PSP (Stripe test)

- PaymentIntent `status = requires_capture`; `capture_before` timestamp. `[verified]`
- Settlement / payout export reconciled against Magento revenue; delta =
  authorized − captured = leaked revenue. `[verified]` reports exist.

### From New Relic APM

- (Blind — an authorized-not-captured order is a healthy, fast request.) Track
  uncaptured-aging count as a derived signal. `[verified]`

### Provado-specific derived metric

```text
Uncaptured Authorization Aging
```

Suggested rule:

```text
An authorization txn exists with no capture child
AND no sales_invoice row for the order
AND the order is still in a shippable state
AND the authorization age has crossed the network window (Stripe ~7d card)
THEN flag Uncaptured Authorization Aging
```

Severity:

```text
High
```

Reason:

```text
Goods ship; funds expire uncollected. Direct, quantifiable revenue leak.
```

## Detection query ideas

### Magento MySQL

```sql
-- authorizations with no capture child, no invoice, order still open
SELECT o.increment_id, p.amount_authorized, p.amount_paid, o.state, t.created_at
FROM sales_order o
JOIN sales_order_payment p ON p.parent_id = o.entity_id
JOIN sales_payment_transaction t ON t.order_id = o.entity_id AND t.txn_type = 'authorization'
LEFT JOIN sales_payment_transaction c ON c.parent_id = t.transaction_id AND c.txn_type = 'capture'
LEFT JOIN sales_invoice i ON i.order_id = o.entity_id
WHERE c.transaction_id IS NULL
  AND i.entity_id IS NULL
  AND o.state IN ('processing','new','payment_review')
  AND t.created_at < (NOW() - INTERVAL 7 DAY);
```

### PSP reconciliation

```text
Pull Stripe (test) PaymentIntents with status=requires_capture and compare
capture_before against now; join to Magento increment id.
```

## Mitigation pattern

- Prefer Authorize-and-Capture unless deferred capture is a deliberate workflow.
- Monitor authorization aging vs the network window; alert before expiry.
- Reconcile gateway settlement vs Magento `amount_paid` continuously.
- Watch the auto-invoice cron and Payment Action config for silent flips
  (ties to [SIM-CONFIG-001](README.md)).

## Lab cleanup

```bash
# void/refund the test authorization in the Stripe test dashboard
# restore the method Payment Action to its baseline (Authorize and Capture)
# cancel or invoice the test order to clear the open state
```

## Current result

Draft from Drive failure mode #7. Honest scope: this is the most-covered mode
(gateway dashboards + paid extensions partly cover the capture side) — the durable
edge is the continuous auth-aging + reconciliation join, not raw detection.
Reproduction pending. Related:
[SIM-PAY-001](SIM-PAY-001-payment-success-order-not-created.md).
