# Provado Development Roadmap

This roadmap is designed to guide development in small, coherent phases. Each phase should be suitable for a Codex prompt: small enough to avoid overload, but large enough to produce meaningful progress.

Primary source document: `docs/ARCHITECTURE_DIRECTION_SOURCE.md`.

## Status legend

- [ ] Not started
- [~] In progress
- [x] Done
- [!] Blocked

## Alpha success definition

The Alpha is successful when the system can:

1. Load configured source credentials safely.
2. Fetch or simulate Tier 0 source data from New Relic-like and Adobe Commerce-like sources.
3. Normalize all source data into canonical signals.
4. Persist and query those signals.
5. Correlate signals by time and entity.
6. Run at least one diagnostic pattern.
7. Produce a readable incident report with evidence and recommended next checks.
8. Run the full flow locally with fixtures and no real credentials.
9. Track project progress in this file.

## Current implementation rule

Do not build future integrations before the core model, adapter seam, and correlation substrate exist.

The first working Alpha must be fully demonstrable with Tier 0-style fixture data only: New Relic-like observability signals plus Adobe Commerce-like commerce-state signals.

## Phase overview

| Phase | Status | Goal |
|---|---:|---|
| Phase 0 | [x] | Project skeleton |
| Phase 1 | [x] | Canonical signal model |
| Phase 2 | [x] | Configuration and secrets boundary |
| Phase 3 | [x] | Source adapter interface |
| Phase 4 | [x] | New Relic adapter |
| Phase 5 | [x] | Adobe Commerce adapter |
| Phase 6 | [ ] | Signal storage |
| Phase 7 | [ ] | Correlation substrate |
| Phase 8 | [ ] | Diagnostic pattern interface |
| Phase 9 | [ ] | First diagnostic pattern |
| Phase 10 | [ ] | Incident output |
| Phase 11 | [ ] | Pipeline orchestration |
| Phase 12 | [ ] | Error handling and observability |
| Phase 13 | [ ] | Alpha demo flow |
| Phase 14 | [ ] | Source integration backlog |

---

## Phase 0 — Project skeleton

**Status:** [x]

**Goal:** Create the initial repository structure.

**Deliverables:**

- `src/`
- `tests/`
- `docs/`
- `src/Core/`
- `src/Sources/`
- `src/Correlation/`
- `src/Patterns/`
- `src/Incidents/`
- `src/Pipeline/`
- `src/Config/`
- placeholder architecture files

**Codex prompt:**

```txt
Create the initial Provado project skeleton.

Add:
- src/
- tests/
- docs/
- docs/ROADMAP.md
- docs/ARCHITECTURE.md
- src/Core/
- src/Sources/
- src/Correlation/
- src/Patterns/
- src/Incidents/
- src/Pipeline/
- src/Config/

Do not implement business logic yet.
Create placeholder classes or interfaces only where useful.
Update docs/ROADMAP.md marking Phase 0 as done.
```

---

## Phase 1 — Canonical signal model
**Status:** [x]

**Goal:** Define Provado's internal signal model before implementing vendor-specific integrations.

**Deliverables:**

- `Signal`
- `SignalId`
- `SignalSource`
- `SignalType`
- `SignalSeverity`
- `EntityReference`
- `TimeWindow`
- `DeploymentReference`
- `RawPayloadReference`

A signal must support:

- source
- type
- timestamp
- severity
- entity references
- metadata attributes
- raw payload reference

Core logic must not depend on vendor-specific fields.

**Codex prompt:**

```txt
Implement the canonical signal model.

Create value objects/entities for:
- Signal
- SignalId
- SignalSource
- SignalType
- SignalSeverity
- EntityReference
- TimeWindow
- DeploymentReference
- RawPayloadReference

A Signal must support:
- source
- type
- timestamp
- severity
- entity references
- attributes metadata
- raw payload reference, but not vendor-specific fields in core logic

Add unit tests for constructing and validating signals.
Update docs/ROADMAP.md marking Phase 1 as done.
```

---

## Phase 2 — Configuration and secrets boundary

**Status:** [x]

**Goal:** Define immutable configuration value objects and prevent accidental secret exposure.

**Deliverables:**

- `ProvadoConfig`
- `SourceConfig`
- `SourceCredentials`
- named source support for `new_relic` and `adobe_commerce`
- required-value validation for enabled sources
- PHPUnit coverage for valid config, disabled sources, missing required credentials, and secret redaction

**Verification note:** Phase 2 was marked done after local PHPUnit verification.

---

## Phase 3 — Source adapter interface

**Status:** [x]

**Goal:** Create the source adapter boundary for future vendor integrations without implementing real provider calls.

**Deliverables:**

- `SourceAdapter` interface
- `SourceFetchResult` immutable value object
- `SourceFetchError` immutable value object with sanitized context
- `SourceAdapterRegistry` for registered and enabled adapters
- PHPUnit coverage for source fetch results, registry validation/resolution, enabled adapter filtering, and error context redaction

**Verification note:** Phase 3 was marked done after local PHPUnit verification.

---

## Phase 4 — New Relic adapter

**Status:** [x]

**Goal:** Normalize New Relic-like observability fixture payloads into canonical signals without real provider calls.

**Deliverables:**

- `NewRelicAdapter` implementing the source adapter boundary
- `NewRelicFixtureClient` for local New Relic-like fixture payloads
- `NewRelicPayloadMapper` for latency, error-rate, and transaction-slowdown signals
- Fixture payloads under `tests/Fixtures/new_relic/`
- PHPUnit coverage for adapter support, fetch result shape, payload mapping, and invalid fixture handling

**Verification note:** Phase 4 was marked done after local PHPUnit verification.

---

## Phase 5 — Adobe Commerce adapter

**Status:** [x]

**Goal:** Normalize Adobe Commerce-like commerce and operations fixture payloads into canonical signals without real provider calls.

**Deliverables:**

- `AdobeCommerceAdapter` implementing the source adapter boundary
- `AdobeCommerceFixtureClient` for local Adobe Commerce-like fixture payloads
- `AdobeCommercePayloadMapper` for checkout failure-rate, order sync backlog, inventory sync drift, and stuck indexer signals
- Fixture payloads under `tests/Fixtures/adobe_commerce/`
- PHPUnit coverage for adapter support, fetch result shape, payload mapping, invalid fixture handling, and time-window filtering

**Verification note:** Phase 5 was marked done after local PHPUnit verification.

---

## Deferred on purpose

These are intentionally not part of the first Alpha build:

- Full pattern library
- Revenue attribution
- Performance-fee logic
- Real payment processor integrations
- GA4 / Google Ads integrations
- AI-discovery visibility integrations
- Edge/CDN log ingestion
- Multi-tenant SaaS UI
- Automated remediation

They should remain behind clean interfaces until the first working Alpha proves the ingestion-normalization-correlation-incident loop.
