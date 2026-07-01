# Provado Roadmaps

Provado plans its work in per-version roadmaps. Each minor version gets its own file
under `docs/roadmaps/`, with phase numbering restarting at Phase 1.

Primary source document for direction: `docs/ARCHITECTURE_DIRECTION_SOURCE.md`.

## Roadmaps

| Version | Status | File |
|---|---|---|
| v0.13.0 | Planned | [`docs/roadmaps/v0.13.0.md`](roadmaps/v0.13.0.md) |
| v0.12.0 | Planned | [`docs/roadmaps/v0.12.0.md`](roadmaps/v0.12.0.md) |
| v0.11.0 | Planned | [`docs/roadmaps/v0.11.0.md`](roadmaps/v0.11.0.md) |
| v0.10.0 | Planned | [`docs/roadmaps/v0.10.0.md`](roadmaps/v0.10.0.md) |
| v0.9.0 | **In progress** | [`docs/roadmaps/v0.9.0.md`](roadmaps/v0.9.0.md) |
| v0.8.0 | Shipped (tag `0.8.0`) | [`docs/roadmaps/v0.8.0.md`](roadmaps/v0.8.0.md) |
| v0.7.0 | Shipped (tag `0.7.0`) | [`docs/roadmaps/v0.7.0.md`](roadmaps/v0.7.0.md) |
| v0.6.0 | Shipped (tag `0.6.0`) | [`docs/roadmaps/v0.6.0.md`](roadmaps/v0.6.0.md) |
| v0.5.0 | Shipped (tag `0.5.0`) | [`docs/roadmaps/v0.5.0.md`](roadmaps/v0.5.0.md) |
| v0.4.0 | Shipped (tag `0.4.0`) | [`docs/roadmaps/v0.4.0.md`](roadmaps/v0.4.0.md) |
| v0.3.0 | Shipped (tag `0.3.0`) | [`docs/roadmaps/v0.3.0.md`](roadmaps/v0.3.0.md) |
| v0.2.0 | Shipped (tag `0.2.0`) | [`docs/roadmaps/v0.2.0.md`](roadmaps/v0.2.0.md) |
| v0.1.0 | Shipped (tag `0.1.0`) | [`docs/roadmaps/v0.1.0.md`](roadmaps/v0.1.0.md) |

## Planned arc (from the 2026-07-01 alignment analysis)

The 2026-07-01 analysis produced an action inventory; `v0.8.0`–`v0.11.0` sequence it. The
overarching order is **make correlation real → make it smart → add breadth → reach revenue
integrity**.

| Action (2026-07-01) | Lands in |
|---|---|
| 1. Verify the lead pattern organically on the lab | v0.8.0 Phase 1 |
| 2.C1 — shared instance entity so cron collapses in production | v0.8.0 Phase 2 |
| 3 — fidelity hygiene (fixture ↔ shipper, catalog) + coverage re-audit | v0.8.0 Phase 1 & 3 |
| 2.C2 — cross-family correlation (symptom ↔ operational cause) | v0.9.0 Phase 2 |
| 4 — proximity-as-weight + correlation-criteria config surface | v0.9.0 Phase 1 & 3 |
| 5a — standalone diagnosis + queue/consumer progress axis | v0.10.0 Phase 1 |
| 5b — easy Wire-Up wins (`maintenance_flag`, `schema_drift`) | v0.10.0 Phase 2 |
| 5c — search-engine cluster half (#4/#6) | v0.10.0 Phase 3 |
| 5d — order/payment integrity (#6/#7) | v0.11.0 Phase 1 |
| 5e — New Relic deploy markers (change spine) | v0.11.0 Phase 2 |

## Full-catalog coverage extension (2026-07-01)

Goal decided 2026-07-01: **before merchant outreach, diagnose every failure mode in the signal
catalog** (the 12 traces in `docs/simulations/Provado Signals and diagnosis.md` + the 7 operational
signals in `… - Additional.md`) on the lab — independent of willingness-to-pay validation, which
stays the open assumption it always was. `v0.12.0`–`v0.13.0` extend the arc to the modes
`v0.8.0`–`v0.11.0` leave open:

| Remaining gap | Lands in |
|---|---|
| #2 inventory drift, #3 promotion/price-rule effect, #10 per-region config | v0.12.0 |
| #8 live ERP wiring (Odoo + `magento_operation`), #12 cacheability, PSP reconciliation completing #6/#7 (Stripe test), #11 measurement slice (real GA4 stack on the lab) | v0.13.0 |
| #4 *valid-while-failed* shape (shipped-but-ignored `working` flag) | v0.10.0 Phase 3 |
| #9 edge/WAF | **Excluded** from the goal (decision 2026-07-01) — no Fastly/ACC infra; revisit with a merchant |

**Coverage map:** the honest, living view of where diagnosis stands (per signal + per failure
mode, each with `✅ what exists` / `❌ what's missing`, plus a renderable mermaid overview and the
6 cross-cutting structural gaps) lives in [`docs/COVERAGE.md`](COVERAGE.md). Measured against
v0.8.0; update it alongside each roadmap-item checkbox. Green means *diagnosed on live data* — not
"the signal ships" or "the fixture passes".

## Working agreement

- **Docs roadmap changes** are committed and merged directly (see `CLAUDE.md`).
- **Source-code phases** are delivered as reviewed PRs, each preceded by a `/code-review` pass.
