# SIM-ERP-001 — ERP API Timeout Creates Draft Order Partial Failure

## Status

Reproduced successfully.

## Purpose

Simulate a real Magento → ERP operational failure where Magento receives a timeout/502 from the ERP API, but Odoo still partially processes the request.

This reproduces a dangerous integration state:

- Magento believes the order sync failed.
- Odoo created the sales order.
- Odoo did not finish the full lifecycle.
- Retry logic may incorrectly stop because the order already exists.
- The order can remain stuck in `draft`.
- Stock may not be reserved until recovery logic completes the flow.

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
- Stripe test mode working

### ERP lab

- Odoo Community 19.0
- PostgreSQL 16
- Apache reverse proxy
- HTTPS via Let's Encrypt
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
| `ERP-TSHIRT-001` | Magento Sync T-Shirt | Normal stock item |
| `ERP-HOODIE-001` | Magento Sync Hoodie | Normal stock item |
| `ERP-MUG-001` | Magento Sync Mug | Normal stock item |
| `ERP-LOWSTOCK-001` | Low Stock Test Product | Low-stock / stock-risk item |

### Customer

| Field | Value |
|---|---|
| Name | Magento Test Customer |
| Email | `magento.customer.001@example.com` |
| Odoo partner ID | `7` |

## Fault injection

A custom Odoo addon was created at:

```text
/opt/odoo/custom-addons/provado_erp_faults
```

The addon overrides `sale.order.create()`.

When `client_order_ref` starts with:

```text
MAG-TIMEOUT
```

Odoo sleeps for 5 seconds before creating the order.

Relevant code path:

```text
/opt/odoo/custom-addons/provado_erp_faults/models/sale_order.py
```

Expected log message:

```text
PROVADO FAULT SIMULATOR: delaying sale.order.create for MAG-TIMEOUT-001 by 5 seconds
```

Apache was temporarily configured with:

```apache
ProxyPass / http://127.0.0.1:8069/ retry=0 timeout=1
```

This forces Apache to cut the client connection before Odoo finishes processing.

## Reproduction steps

### 1. Create timeout order

Script:

```text
/tmp/odoo_create_sales_order_timeout_fault.py
```

Magento order reference used:

```text
MAG-TIMEOUT-001
```

Command:

```bash
time python3 /tmp/odoo_create_sales_order_timeout_fault.py
```

Observed client-side result:

```text
xmlrpc.client.ProtocolError: <ProtocolError for odoo.temiandu.com/xmlrpc/2/object: 502 Proxy Error>

real    0m1.799s
```

## Observed ERP behavior

Odoo log showed that the request continued after the client received the 502:

```text
PROVADO FAULT SIMULATOR: delaying sale.order.create for MAG-TIMEOUT-001 by 5 seconds
POST /xmlrpc/2/object#sale.order.create HTTP/1.1" 200 ... 5.061
```

The order was created in Odoo:

```text
S00004
client_order_ref: MAG-TIMEOUT-001
state: draft
amount_total: 147.0
```

This confirms the partial failure:

```text
Client received timeout/502.
ERP created the order.
ERP did not complete order confirmation.
```

## Bad retry behavior

When the same sync was retried, the current idempotency logic found the existing order and exited:

```text
Order already exists:
{'id': 4, 'name': 'S00004', 'client_order_ref': 'MAG-TIMEOUT-001', 'state': 'draft', 'amount_total': 147.0}
```

This is not enough.

The retry avoided a duplicate, but it left the ERP order stuck in `draft`.

## Correct retry behavior

A corrected retry script was created:

```text
/tmp/odoo_retry_confirm_existing_order.py
```

Expected behavior:

- Search by `client_order_ref`.
- If no order exists, create it.
- If order exists in `draft` or `sent`, continue lifecycle by calling `action_confirm`.
- If order exists in `sale`, treat as already completed.
- Do not create duplicate orders.

Observed result:

```text
Before: {'id': 4, 'name': 'S00004', 'client_order_ref': 'MAG-TIMEOUT-001', 'state': 'draft', 'amount_total': 147.0}

After: {'id': 4, 'name': 'S00004', 'client_order_ref': 'MAG-TIMEOUT-001', 'state': 'sale', 'amount_total': 147.0, 'picking_ids': [4]}
```

## Stock impact

After confirmation, Odoo reserved stock.

Observed values:

```text
ERP-LOWSTOCK-001
qty_available: 6.0
virtual_available: 3.0
free_qty: 3.0

ERP-TSHIRT-001
qty_available: 118.0
virtual_available: 112.0
free_qty: 112.0
```

Interpretation:

| Field | Meaning |
|---|---|
| `qty_available` | Physical stock |
| `free_qty` | Free stock not reserved |
| `virtual_available` | Forecast / projected availability |

For Magento salable stock, `free_qty` or `virtual_available` is more relevant than raw `qty_available`.

## Failure classification

Type:

```text
Partial failure + retry/idempotency gap + eventual consistency issue
```

Failure chain:

```text
Magento sends order to ERP
→ ERP API call times out at reverse proxy
→ Magento sees 502
→ Odoo continues processing
→ Odoo creates sale.order in draft
→ Magento retry sees order exists
→ retry exits too early
→ ERP order remains draft
→ stock is not reserved
→ downstream order/inventory state is inconsistent
```

## Business impact

Potential effects:

- Magento shows order as placed.
- ERP has incomplete order.
- Fulfillment does not start.
- Stock is not reserved.
- Later retries may not fix the order.
- Customer support sees inconsistent order state.
- Overselling risk increases if Magento continues selling based on stale ERP state.
- Revenue may be at risk even though payment succeeded.

## Metrics/signals needed

### From Magento integration layer

- ERP API request count by operation:
  - `create_customer`
  - `create_order`
  - `confirm_order`
  - `update_stock`
- ERP API latency by operation.
- ERP API HTTP status / exception type.
- Timeout count.
- Retry count by Magento `increment_id`.
- Final sync status by Magento order:
  - `pending`
  - `sent`
  - `confirmed`
  - `failed`
  - `needs_reconciliation`
- Idempotency key used:
  - Magento `increment_id`
  - Odoo `client_order_ref`

### From Odoo

- Count of `sale.order` by `client_order_ref`.
- Count of Magento-origin orders stuck in `draft`.
- Age of Magento-origin draft orders.
- Orders with `client_order_ref` but no picking.
- Orders in `sale` but delivery not assigned.
- Stock reservation delta:
  - `qty_available`
  - `free_qty`
  - `virtual_available`

### From Apache / reverse proxy

- 502 count for `/xmlrpc/2/object`.
- Proxy timeout count.
- Request duration.
- Upstream service duration.
- Client-aborted requests.

### From New Relic APM

- Transaction duration for Odoo XML-RPC endpoints.
- Error rate for Odoo API transactions.
- External call latency from Magento to Odoo.
- Magento order sync transaction traces.
- Exceptions grouped by operation and order reference.

### Provado-specific derived metric

```text
ERP Partial Sync Risk
```

Suggested rule:

```text
Magento order has successful payment
AND ERP API call failed or timed out
AND Odoo sale.order exists with matching client_order_ref
AND Odoo state is draft for more than N minutes
```

Severity:

```text
High
```

Reason:

```text
Customer paid or placed order, but ERP fulfillment may not proceed.
```

## Detection query ideas

### Odoo PostgreSQL

Find Magento-origin draft orders:

```sql
SELECT id, name, client_order_ref, state, amount_total, create_date
FROM sale_order
WHERE client_order_ref LIKE 'MAG-%'
  AND state IN ('draft', 'sent')
ORDER BY create_date DESC;
```

Find duplicate ERP orders by Magento reference:

```sql
SELECT client_order_ref, COUNT(*)
FROM sale_order
WHERE client_order_ref LIKE 'MAG-%'
GROUP BY client_order_ref
HAVING COUNT(*) > 1;
```

### Odoo log grep

```bash
grep -E "PROVADO FAULT|MAG-TIMEOUT|sale.order.create|502|timeout" /var/log/odoo/odoo19.log
```

### Apache log grep

```bash
grep -E "POST /xmlrpc/2/object| 502 " /var/log/httpd/odoo_access.log /var/log/httpd/odoo_error.log
```

## Mitigation pattern

Minimum required retry behavior:

```text
Use Magento increment_id as idempotency key.
Before creating an ERP order, search by client_order_ref.
If no order exists, create it.
If order exists in draft/sent, continue confirmation.
If order exists in sale, treat as successful.
If order exists in cancel/done/unexpected state, mark needs_reconciliation.
```

Recommended recovery job:

```text
Every 5 minutes:
Find Magento-origin Odoo orders stuck in draft for more than N minutes.
Compare against Magento paid/placed orders.
Confirm or flag for reconciliation.
```

## Lab cleanup

After testing, restore Apache timeout:

```bash
sed -i 's/timeout=1/timeout=120/g' /etc/httpd/conf.d/odoo.temiandu.com.conf
sed -i 's/timeout=1/timeout=120/g' /etc/httpd/conf.d/odoo.temiandu.com-le-ssl.conf

httpd -t
systemctl reload httpd
```

## Current result

This simulation is valid and should be added to the Provado simulation library.

Suggested filename:

```text
SIM-ERP-001-api-timeout-creates-draft-order.md
```
