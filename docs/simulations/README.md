# Provado — Simulation Library

Reproducible **integration-failure simulations** for the Provado Magento /
Adobe Commerce + Odoo lab. Each entry injects a real-world ecommerce integration
fault, documents the observable symptoms and the signals needed to detect it, and
distills a Provado detection rule.

The goal is **not** a production ERP. It is a growing catalog of *observable
failures* that feed a future RCA / observability platform focused on
revenue-at-risk.

## How to use this library

- Every simulation is one Markdown file following [`TEMPLATE.md`](TEMPLATE.md).
- Start a new simulation by copying `TEMPLATE.md` and keeping **all** section
  headings (write `N/A` + a one-line reason rather than deleting a section, so
  entries stay diff-comparable).
- Screenshots, log dumps, and other binaries go in [`assets/`](assets/), named
  with the simulation id as prefix (e.g. `SIM-ERP-001-draft-order.png`).
- Source of truth is this directory in the repo. The lab server is downstream —
  edit here, then sync.

## Naming convention

```text
SIM-<DOMAIN>-<NUMBER>-short-kebab-title.md
```

- `DOMAIN` — fixed code from the registry below.
- `NUMBER` — zero-padded, sequential **per domain** (`001`, `002`, ...).
- `short-kebab-title` — lower-case, hyphenated, human-readable summary.

Examples:

```text
SIM-ERP-001-api-timeout-creates-draft-order.md
SIM-ERP-002-stale-inventory-oversell.md
SIM-PAY-001-payment-success-order-not-created.md
SIM-QUEUE-001-order-sync-message-stuck.md
SIM-SEARCH-001-product-index-stale.md
```

## Domain registry

| Domain | Scope |
|---|---|
| `ERP` | Magento ↔ Odoo order/inventory/customer sync failures |
| `PAY` | Payment vs. order-creation inconsistencies (Stripe test mode) |
| `QUEUE` | RabbitMQ / async message-queue and consumer failures |
| `CRON` | Magento cron scheduler health (upstream of index/cache/email) |
| `INDEX` | Indexer / mview projection stagnation (stale price/stock/catalog) |
| `SEARCH` | OpenSearch / catalogsearch indexing and stale-index failures |
| `CACHE` | Full Page Cache cacheability and invalidation failures |
| `PROMO` | Cart / catalog price-rule failures |
| `CONFIG` | Deploy/config regression, per-scope config, schema drift, maintenance flag |
| `WAF` | Edge / WAF traffic blocking (gated — needs Fastly/CDN, not in lab yet) |
| `TAG` | Measurement / consent / tag breakage (gated — needs GA4/consent stack) |

<!-- Add a new domain here before using a new prefix. -->

## Status legend

| Status | Meaning |
|---|---|
| Draft | Written up, not yet reproduced end-to-end |
| Reproduced successfully | Fault injected and observed as documented |
| Partially reproduced | Some steps confirmed, gaps remain |
| Could not reproduce | Attempted, fault did not manifest |
| Retired | Superseded or no longer relevant |

## Simulations

| ID | Title | Domain | Status |
|---|---|---|---|
| [SIM-ERP-001](SIM-ERP-001-api-timeout-creates-draft-order.md) | ERP API timeout creates draft order partial failure | ERP | Reproduced successfully |
| [SIM-ERP-002](SIM-ERP-002-stale-inventory-oversell.md) | Stale inventory oversell / reservation drift | ERP | Draft |
| [SIM-ERP-003](SIM-ERP-003-cross-system-sync-stoppage.md) | Cross-system sync stoppage / partial sync | ERP | Draft |
| [SIM-PAY-001](SIM-PAY-001-payment-success-order-not-created.md) | Payment success, order not created | PAY | Draft |
| [SIM-PAY-002](SIM-PAY-002-order-created-payment-never-captured.md) | Order created, payment never captured | PAY | Draft |
| [SIM-QUEUE-001](SIM-QUEUE-001-order-sync-message-stuck.md) | Order sync message stuck / consumer death | QUEUE | Draft |

<!-- Add one row per simulation. Keep most recent / highest-priority near the top
     within each domain. -->

## Planned / backlog

Candidate simulations not yet written:

| Proposed ID | Title | Domain | Wave |
|---|---|---|---|
| SIM-CRON-001 | Cron stalled (upstream root cause) | CRON | 2 |
| SIM-INDEX-001 | Indexer / projection stagnation → stale price | INDEX | 2 |
| SIM-SEARCH-001 | Product index stale / empty search | SEARCH | 2 |
| SIM-CACHE-001 | Cacheability broken by deploy | CACHE | 2 |
| SIM-PROMO-001 | Price rule silently stops applying | PROMO | 3 |
| SIM-CONFIG-001 | Deploy / config regression ("what changed") | CONFIG | 3 |
| SIM-CONFIG-002 | Per-region tax / payment config break | CONFIG | 3 |
| SIM-CONFIG-003 | Schema / module version drift | CONFIG | 3 |
| SIM-CONFIG-004 | Maintenance flag left on after deploy | CONFIG | 3 |
| SIM-WAF-001 | Good bot blocked at edge / WAF | WAF | gated |
| SIM-TAG-001 | Measurement / consent / tag breakage | TAG | gated |

Gated items (`WAF`, `TAG`) need infrastructure not present in the current lab
(Fastly/CDN/WAF; GA4 + consent-mode stack) and are deferred until that exists.
