# SIM-<DOMAIN>-<NUMBER> — <Short Human-Readable Title>

<!--
Filename convention: SIM-<DOMAIN>-<NUMBER>-short-kebab-title.md
  DOMAIN  — ERP | PAY | QUEUE | SEARCH | ... (see README.md for the registry)
  NUMBER  — zero-padded, per-domain sequence (001, 002, ...)
Keep the H1 title above in sync with the filename.

This template mirrors SIM-ERP-001. Keep every section heading even when a
section does not apply — write `N/A` and a one-line reason rather than deleting
it, so simulations stay diff-comparable across the library.
-->

## Status

<!-- One of: Draft | Reproduced successfully | Partially reproduced | Could not reproduce | Retired -->

## Purpose

<!--
What real Magento <-> ERP (or other domain) operational failure this reproduces,
in 1-3 sentences, followed by a short bullet list of the dangerous state(s) it
creates. State the integration contradiction plainly (e.g. "Magento believes X,
ERP believes Y").
-->

## Environment

### Magento lab

- Magento <version>
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
- Apache reverse proxy
- HTTPS via Let's Encrypt
- URL: `https://odoo.temiandu.com`
- Database: `provado_erp_lab`
- API user: `magento.api@provado.local`
- APIs tested: XML-RPC

### Odoo modules installed

- Sales
- Inventory
- Custom addon: `provado_erp_faults` <!-- list any others this sim relies on -->

## Test data

### Products

| SKU | Name | Purpose |
|---|---|---|
| `ERP-...` | ... | ... |

### Customer

| Field | Value |
|---|---|
| Name | ... |
| Email | ... |
| Odoo partner ID | ... |

## Fault injection

<!--
Exactly how the fault is introduced. Be reproducible: addon path, config edit,
service state, env var, throttle, etc. Include the trigger condition and the
expected log line that proves the fault armed.
-->

## Reproduction steps

<!--
Numbered, copy-pasteable. Include the script path(s), the command(s) run, and the
observed client-side result for each step. Cover the full path including the
retry / recovery step, not just the initial failure.
-->

### 1. <step name>

```text
<script path>
```

```bash
<command>
```

Observed client-side result:

```text
<output>
```

## Observed behavior

<!--
What actually happened on the server side, with evidence (log excerpts, record
dumps). Make the partial-failure / contradiction explicit.
-->

## Retry behavior

<!--
Canonical for this library. If the simulation has no retry dimension, write `N/A`
and a one-line reason. Otherwise document both the broken and the fixed paths.
-->

### Bad retry behavior

<!-- The naive / current behavior and why it is insufficient. -->

### Correct retry behavior

<!-- The corrected idempotent behavior, the script that implements it, and the
observed before/after record state. -->

## Stock impact

<!--
Canonical for this library. Stock / state deltas after the flow settles.
If no inventory dimension, write `N/A`.
-->

| Field | Meaning |
|---|---|
| `qty_available` | Physical stock |
| `free_qty` | Free stock not reserved |
| `virtual_available` | Forecast / projected availability |

## Failure classification

Type:

```text
<e.g. Partial failure + retry/idempotency gap + eventual consistency issue>
```

Failure chain:

```text
<symptom -> ... -> root state, one arrow per hop>
```

## Business impact

<!-- Bullet list of operational / revenue consequences. -->

## Metrics/signals needed

### From Magento integration layer

<!-- request count by operation, latency, status/exception, timeout count,
retry count by increment_id, final sync status, idempotency key. -->

### From Odoo

<!-- order counts by client_order_ref, draft-stuck count + age, orders without
picking, delivery not assigned, stock reservation deltas, duplicates. -->

### From Apache / reverse proxy

<!-- 502 count for the endpoint, proxy timeout count, request duration, upstream
duration, client-aborted requests. -->

### From New Relic APM

<!-- transaction duration, error rate, external call latency, sync traces,
exceptions grouped by operation and order reference. -->

### Provado-specific derived metric

```text
<metric name, e.g. ERP Partial Sync Risk>
```

Suggested rule:

```text
<IF ... AND ... AND ... THEN flag ...>
```

Severity:

```text
<Low | Medium | High | Critical>
```

Reason:

```text
<why this severity>
```

## Detection query ideas

### Odoo PostgreSQL

```sql
-- find the contradiction state
```

### Odoo log grep

```bash
grep -E "..." /var/log/odoo/odoo19.log
```

### Apache log grep

```bash
grep -E "..." /var/log/httpd/odoo_access.log /var/log/httpd/odoo_error.log
```

## Mitigation pattern

<!--
Minimum required behavior to prevent or recover from the fault (idempotency,
reconciliation job, etc). Describe as steps, not code.
-->

## Lab cleanup

<!--
Commands to return the lab to a clean baseline (restore configs, cancel/delete
test orders, reset stock). Always include this even if it is just "none".
-->

```bash
<cleanup commands>
```

## Current result

<!--
Final verdict: is the simulation valid and library-ready? Open questions, known
gaps, and links to related simulations (e.g. SIM-ERP-002).
-->
