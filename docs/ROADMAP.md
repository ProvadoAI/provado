# Provado Development Roadmap

This roadmap is designed to guide development in small, coherent phases. Each phase should be suitable for a Codex prompt: small enough to avoid overload, but large enough to produce meaningful progress.

## Status legend

- [ ] Not started
- [~] In progress
- [x] Done
- [!] Blocked

## Phase overview

| Phase | Status | Goal |
|---|---:|---|
| Phase 0 | [ ] | Project skeleton |
| Phase 1 | [ ] | Canonical signal model |
| Phase 2 | [ ] | Source adapter interface |
| Phase 3 | [ ] | New Relic adapter |
| Phase 4 | [ ] | Adobe Commerce adapter |
| Phase 5 | [ ] | Signal storage |
| Phase 6 | [ ] | Correlation substrate |
| Phase 7 | [ ] | First diagnostic pattern |
| Phase 8 | [ ] | Incident output |
| Phase 9 | [ ] | Alpha demo flow |

---

## Phase 0 — Project skeleton

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

Do not implement business logic yet.
Create placeholder classes or interfaces only where useful.
Update docs/ROADMAP.md marking Phase 0 as done.
```

---

## Phase 1 — Canonical signal model

**Goal:** Define Provado's internal signal model before implementing vendor-specific integrations.

**Deliverables:**

- `Signal`
- `SignalSource`
- `SignalType`
- `SignalSeverity`
- `EntityReference`
- `TimeWindow`
- `DeploymentReference`

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
- SignalSource
- SignalType
- SignalSeverity
- EntityReference
- TimeWindow
- DeploymentReference

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

## Phase 2 — Source adapter interface

**Goal:** Make external data sources swappable.

**Deliverables:**

- `SourceAdapterInterface`
- `SourceCredentials`
- `SourceFetchRequest`
- `SourceFetchResult`
- `SignalNormalizerInterface`

Adapters must fetch external data and return canonical `Signal` objects.

**Codex prompt:**

```txt
Create the source adapter abstraction.

Add:
- SourceAdapterInterface
- SourceCredentials
- SourceFetchRequest
- SourceFetchResult
- SignalNormalizerInterface

Adapters must fetch external data and return canonical Signal objects.

Do not implement New Relic or Adobe yet.
Add tests using a fake adapter.
Update docs/ROADMAP.md marking Phase 2 as done.
```

---

## Phase 3 — New Relic adapter

**Goal:** Ingest New Relic signals through NerdGraph.

**Deliverables:**

- `NewRelicAdapter`
- `NewRelicClientInterface`
- `NewRelicNerdGraphClient`
- `NewRelicSignalNormalizer`

Initial supported signals:

- application errors
- transaction latency
- deployment events
- key metric time series

**Codex prompt:**

```txt
Implement the New Relic adapter skeleton.

Add:
- NewRelicAdapter
- NewRelicClientInterface
- NewRelicNerdGraphClient
- NewRelicSignalNormalizer

Support fetching:
- application errors
- transaction latency
- deployment events
- key metric timeseries

Use configuration placeholders for account ID, API key, app/entity GUID.

Add tests using mocked NerdGraph responses.
Do not call the real API in tests.
Update docs/ROADMAP.md marking Phase 3 as done.
```

---

## Phase 4 — Adobe Commerce adapter

**Goal:** Ingest Adobe Commerce state signals.

**Deliverables:**

- `AdobeCommerceAdapter`
- `AdobeCommerceClientInterface`
- `AdobeCommerceRestClient`
- `AdobeCommerceSignalNormalizer`

Initial supported signals:

- store config
- payment method config
- orders in a time window
- products/SKUs
- inventory status, where available

**Codex prompt:**

```txt
Implement the Adobe Commerce REST adapter skeleton.

Add:
- AdobeCommerceAdapter
- AdobeCommerceClientInterface
- AdobeCommerceRestClient
- AdobeCommerceSignalNormalizer

Support fetching:
- store config
- payment method config
- orders in a time window
- products/SKUs
- inventory status if available

Add mocked tests.
Do not depend on a live Adobe instance.
Update docs/ROADMAP.md marking Phase 4 as done.
```

---

## Phase 5 — Signal storage

**Goal:** Persist normalized signals.

**Deliverables:**

- `SignalRepositoryInterface`
- `InMemorySignalRepository`
- database-backed repository placeholder, if the project already has database support

Repository must support:

- save signal
- query by time window
- query by source
- query by entity reference
- query by signal type

**Codex prompt:**

```txt
Implement signal persistence.

Add:
- SignalRepositoryInterface
- InMemorySignalRepository
- database-backed repository placeholder if the project already has DB support

Repository must support:
- save signal
- query by time window
- query by source
- query by entity reference
- query by signal type

Add tests.
Update docs/ROADMAP.md marking Phase 5 as done.
```

---

## Phase 6 — Correlation substrate

**Goal:** Build the signal-joining engine without assigning root-cause meaning yet.

**Deliverables:**

- `CorrelationEngine`
- `CorrelationQuery`
- `CorrelationResult`
- `CorrelatedSignalGroup`

The engine should:

- accept a time window
- load signals from repository
- group signals by time proximity
- group signals by shared entity references
- return correlated groups without assigning root-cause meaning

**Codex prompt:**

```txt
Implement the correlation substrate.

Add:
- CorrelationEngine
- CorrelationQuery
- CorrelationResult
- CorrelatedSignalGroup

The engine should:
- accept a time window
- load signals from repository
- group signals by time proximity
- group signals by shared entity references
- return correlated groups without assigning root-cause meaning

Add tests with fake signals:
- deploy + latency spike should correlate
- payment errors + checkout failures should correlate
- unrelated signals should not correlate

Update docs/ROADMAP.md marking Phase 6 as done.
```

---

## Phase 7 — First diagnostic pattern

**Goal:** Implement one narrow Alpha diagnostic pattern.

**Pattern:** Deploy-correlated checkout/payment regression.

The pattern should detect when:

- a deployment signal occurs
- checkout/payment-related errors increase shortly after
- latency or error severity is elevated in the same time window

**Deliverables:**

- `DiagnosticPatternInterface`
- `PatternMatch`
- `DeployCorrelatedCheckoutPaymentRegressionPattern`

**Codex prompt:**

```txt
Implement the first diagnostic pattern: DeployCorrelatedCheckoutPaymentRegressionPattern.

The pattern should inspect CorrelationResult groups and detect when:
- a deployment signal occurs
- checkout/payment-related errors increase shortly after
- latency or error severity is elevated in the same time window

Return a PatternMatch with:
- title
- confidence score
- evidence signals
- suspected cause
- recommended next checks

Keep this pattern independent from New Relic or Adobe raw payloads.
Use only canonical Signal objects.

Add unit tests.
Update docs/ROADMAP.md marking Phase 7 as done.
```

---

## Phase 8 — Incident output

**Goal:** Convert pattern matches into useful incident reports.

**Deliverables:**

- `Incident`
- `IncidentSeverity`
- `IncidentEvidence`
- `IncidentRecommendation`
- `IncidentBuilder`

Incident output must include:

- summary
- suspected root cause
- affected entities
- evidence
- confidence
- recommended actions
- time window

**Codex prompt:**

```txt
Implement incident generation.

Add:
- Incident
- IncidentSeverity
- IncidentEvidence
- IncidentRecommendation
- IncidentBuilder

Convert PatternMatch results into Incident objects.

Incident output must include:
- summary
- suspected root cause
- affected entities
- evidence
- confidence
- recommended actions
- time window

Add tests.
Update docs/ROADMAP.md marking Phase 8 as done.
```

---

## Phase 9 — Alpha demo flow

**Goal:** Prove the full local flow using fixtures.

The demo should:

1. Load fixture New Relic-like signals.
2. Load fixture Adobe Commerce-like signals.
3. Normalize them.
4. Save them.
5. Run correlation.
6. Run the first diagnostic pattern.
7. Output an incident report.

No real credentials should be required.

**Codex prompt:**

```txt
Create an Alpha demo command or script.

The demo should:
- load fixture New Relic-like signals
- load fixture Adobe Commerce-like signals
- normalize them
- save them
- run correlation
- run the first diagnostic pattern
- output an Incident report

Use fixtures only.
No real credentials required.

Add README instructions for running the demo.
Update docs/ROADMAP.md marking Phase 9 complete.
```

---

## Current implementation rule

Do not build future integrations before the core model, adapter seam, and correlation substrate exist.

The first working Alpha must be fully demonstrable with Tier 0-style fixture data only: New Relic-like observability signals plus Adobe Commerce-like commerce-state signals.
