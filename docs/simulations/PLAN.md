# Simulation Library — Build Plan

Working plan for completing the Provado simulation library. This is the ordered
work list; [`README.md`](README.md) is the public index and [`TEMPLATE.md`](TEMPLATE.md)
is the contract each simulation follows.

## Goal

One simulation `.md` per integration/operational failure mode, so that the
library collectively exercises **every metric/signal** described in the two Drive
source docs:

- *Provado — Failure-to-Cause Traces* (the 12 failure modes)
- *Provado — Signal Readout, New Relic Shipping & Diagnosis Path* (the 7 operational signals)

## Locked decisions (2026-06-29)

- **Method: documentation only.** Author each `.md` from the Drive primary
  sources. Do **not** inject faults or touch the lab server in this pass. Every
  new simulation lands at **Status: Draft**.
- **Scope: the full reproducible catalog** — 14 simulations (Waves 1–3 below).
- **Infra-gated modes excluded for now:** `WAF` (#9, needs Fastly/CDN) and `TAG`
  (#11, needs GTM/GA4/consent stack). Neither exists in the current lab. They
  stay in the README backlog only, to be written once the infra exists.

## Draft-status section convention (because we are not reproducing yet)

Each `.md` keeps all `TEMPLATE.md` headings. In docs-only Draft status:

- **Fault injection** — the concrete injection *design* (what we *will* do on the
  lab), derived from the source mechanics.
- **Reproduction steps** — the procedure to run later, written so it can be
  executed verbatim when we move to reproduction.
- **Observed behavior / Retry behavior / Stock impact** — filled with the
  **expected** behavior per primary sources, each prefixed `⏳ Expected (not yet
  reproduced on lab)`. These flip to real captured evidence when the simulation
  is reproduced and Status changes to *Reproduced successfully*.
- **Metrics/signals needed, Detection query ideas, Mitigation, Provado derived
  rule** — fully authored now; these are the deliverable and come straight from
  the source docs.
- Carry the source's **evidence tags** (`[verified]` / `[inferred]` /
  `[hypothesis]`) on load-bearing technical claims, as the Drive docs do.

## Domain registry additions

`README.md` currently registers ERP, PAY, QUEUE, SEARCH. This plan adds:
**CRON, INDEX, CACHE, PROMO, CONFIG** (and reserves WAF, TAG for the gated modes).
The registry expansion is the first edit when Wave 1 starts.

## The catalog (source mapping)

| SIM | Title | Domain | Drive source | Key signals exercised |
|---|---|---|---|---|
| SIM-ERP-001 ✅ | API timeout creates draft order | ERP | #8 / #5 | ERP API timeout, draft-stuck, retry idempotency, stock reservation |
| SIM-ERP-002 | Stale inventory oversell | ERP | #2 | `inventory_reservation` net≠0, `inventory_source_item` vs ERP, salable qty, `updateSalabilityStatus` consumer |
| SIM-ERP-003 | Cross-system sync stoppage / partial sync | ERP | #8 | `magento_operation`/`magento_bulk` status enum, `async.operations.all`, 202≠success |
| SIM-PAY-001 | Payment success, order not created | PAY | #6 | orphaned `quote` (reserved_order_id + is_active=1), PSP capture vs `sales_order`, 1213/1205 deadlock |
| SIM-PAY-002 | Order created, payment never captured | PAY | #7 | `sales_payment_transaction` auth w/o capture child, auth-window aging, payout reconciliation |
| SIM-QUEUE-001 | Order sync message stuck / consumer death | QUEUE | #1 | RabbitMQ ack rate vs backlog, `queue_message_status` enum, `consumers_runner` cron, stuck lock |
| SIM-CRON-001 | Cron stalled (upstream root cause) | CRON | Add. #2 | `cron_schedule` missed/running/pending, runtime delta, cascade to index/cache/email |
| SIM-INDEX-001 | Indexer / projection stagnation → stale price | INDEX | #4 | `mview_state.version_id` vs `*_cl` MAX, valid-while-failed (ACSD-51431), drain cron |
| SIM-SEARCH-001 | Product index stale / empty search | SEARCH | #4 search + Add. #6 | `catalogsearch_fulltext` backlog, OpenSearch `_count` vs catalog, cluster status |
| SIM-CACHE-001 | Cacheability broken by deploy | CACHE | #12 / Add. #0 | FPC HIT/MISS + hit ratio, `cacheable="false"` blast radius, `getInvalidated()` dwell |
| SIM-PROMO-001 | Price rule silently stops applying | PROMO | #3 | `catalogrule_product_price` materialization, usage caps, `salesrule_coupon_aggregated` effect |
| SIM-CONFIG-001 | Deploy / config regression ("what changed") | CONFIG | #5 | `core_config_data.updated_at` (marker-less), New Relic deploy marker, post-deploy indexer/cache |
| SIM-CONFIG-002 | Per-region tax / payment config break | CONFIG | #10 | scoped `core_config_data`, `tax_calculation_*`, per-`store_id` checkout success |
| SIM-CONFIG-003 | Schema / module version drift | CONFIG | Add. #3 | `setup_module` schema vs data version, `DbStatusValidator` hard-block, MySQL 8.0.x false positives |
| SIM-CONFIG-004 | Maintenance flag left on after deploy | CONFIG | Add. #5 | `var/.maintenance.flag`, traffic collapse vs real outage |
| _SIM-WAF-001_ | _Good bot blocked at edge (gated)_ | WAF | #9 | _excluded — no Fastly/WAF in lab_ |
| _SIM-TAG-001_ | _Tag / consent breakage (gated)_ | TAG | #11 | _excluded — no GA4/consent stack in lab_ |

## Wave order

- **Wave 1 — Integration (lab core; matches the Magento↔Odoo↔Stripe metrics list):**
  ERP-002, ERP-003, PAY-001, PAY-002, QUEUE-001.
- **Wave 2 — Platform async (CRON first; it is upstream of index/cache/email):**
  CRON-001, INDEX-001, SEARCH-001, CACHE-001.
- **Wave 3 — Commerce logic + config spine:**
  PROMO-001, CONFIG-001, CONFIG-002, CONFIG-003, CONFIG-004.

## Per-simulation build loop (docs-only pass)

1. Copy `TEMPLATE.md` → `SIM-<...>.md`.
2. Fill from the mapped Drive source per the section convention above.
3. Self-review: every section present, evidence tags carried, derived Provado
   rule stated, cleanup written.
4. Add a row to the README simulations table; remove from backlog.
5. Commit to the repo (source of truth). Server sync happens later, in batch.

## Definition of done (this pass)

- 14 new `SIM-*.md` files exist at Status: Draft, each fully populated except the
  evidence sections (which carry expected behavior, ready to be replaced on
  reproduction).
- `README.md` registry + simulations table + backlog reflect the full catalog.
- A later, separate pass reproduces each on the lab and flips Status to
  *Reproduced successfully*.
