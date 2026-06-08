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
| Phase 0 | [ ] | Project skeleton |
| Phase 1 | [ ] | Canonical signal model |
| Phase 2 | [ ] | Configuration and secrets boundary |
| Phase 3 | [ ] | Source adapter interface |
| Phase 4 | [ ] | New Relic adapter |
| Phase 5 | [ ] | Adobe Commerce adapter |
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

**Goal:** Add a safe configuration layer before implementing real adapters.

**Deliverables:**

- `ConfigRepositoryInterface`
- environment-based config loader
- source credential value objects
- validation for missing credentials
- no secrets committed to the repo
- `.env.example` or equivalent sample config

**Codex prompt:**

```txt
Implement the configuration and secrets boundary.

Add:
- ConfigRepositoryInterface
- environment-based config loader
- source credential value objects
- validation for missing credentials
- .env.example or equivalent sample config

Do not commit real credentials.
Do not connect to real external APIs yet.
Add tests for missing and valid configuration.
Update docs/ROADMAP.md marking Phase 2 as done.
```

---

## Phase 3 — Source adapter interface

**Goal:** Make external data sources swappable.

**Deliverables:**

- `SourceAdapterInterface`
- `SourceCredentials`
- `SourceFetchRequest`
- `SourceFetchResult`
- `SignalNormalizerInterface`
- `SourceHealthCheckResult`

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
- SourceHealthCheckResult

Adapters must fetch external data and return canonical Signal objects.
Adapters must expose a health-check method.

Do not implement New Relic or Adobe yet.
Add tests using a fake adapter.
Update docs/ROADMAP.md marking Phase 3 as done.
```

---

## Phase 4 — New Relic adapter

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
Update docs/ROADMAP.md marking Phase 4 as done.
```

---

## Phase 5 — Adobe Commerce adapter

**Goal:** Ingest Adobe Commerce state signals.

**Deliverables:**

- `AdobeCommerceAdapter`
- `AdobeCommerceClientInterface`
- `AdobeCommerceRestClient`
- `AdobeCommerceSignalNormalizer`
- Adobe Commerce Cloud tier detector placeholder

Initial supported signals:

- store config
- payment method config
- orders in a time window
- products/SKUs
- inventory status, where available
- cloud tier, if detectable

**Codex prompt:**

```txt
Implement the Adobe Commerce REST adapter skeleton.

Add:
- AdobeCommerceAdapter
- AdobeCommerceClientInterface
- AdobeCommerceRestClient
- AdobeCommerceSignalNormalizer
- AdobeCommerceCloudTierDetector placeholder

Support fetching:
- store config
- payment method config
- orders in a time window
- products/SKUs
- inventory status if available
- cloud tier if detectable

Add mocked tests.
Do not depend on a live Adobe instance.
Update docs/ROADMAP.md marking Phase 5 as done.
```

---

## Phase 6 — Signal storage

**Goal:** Persist normalized signals.

**Deliverables:**

- `SignalRepositoryInterface`
- `InMemorySignalRepository`
- database-backed repository placeholder, if the project already has database support

Repository must support:

- save signal
- save many signals
- query by time window
- query by source
- query by entity reference
- query by signal type
- query by severity

**Codex prompt:**

```txt
Implement signal persistence.

Add:
- SignalRepositoryInterface
- InMemorySignalRepository
- database-backed repository placeholder if the project already has DB support

Repository must support:
- save signal
- save many signals
- query by time window
- query by source
- query by entity reference
- query by signal type
- query by severity

Add tests.
Update docs/ROADMAP.md marking Phase 6 as done.
```

---

## Phase 7 — Correlation substrate

**Goal:** Build the signal-joining engine without assigning root-cause meaning yet.

**Deliverables:**

- `CorrelationEngine`
- `CorrelationQuery`
- `CorrelationResult`
- `CorrelatedSignalGroup`
- configurable time-proximity threshold

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
- configurable time-proximity threshold

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

Update docs/ROADMAP.md marking Phase 7 as done.
```

---

## Phase 8 — Diagnostic pattern interface

**Goal:** Stub the interpretation layer cleanly before adding real pattern content.

**Deliverables:**

- `DiagnosticPatternInterface`
- `PatternMatch`
- `PatternEvidence`
- `PatternConfidence`
- `PatternRegistry`

**Codex prompt:**

```txt
Implement the diagnostic pattern interface.

Add:
- DiagnosticPatternInterface
- PatternMatch
- PatternEvidence
- PatternConfidence
- PatternRegistry

Patterns must inspect CorrelationResult objects and return zero or more PatternMatch objects.
Do not implement real diagnostic logic in this phase.
Add tests using a fake pattern.
Update docs/ROADMAP.md marking Phase 8 as done.
```

---

## Phase 9 — First diagnostic pattern

**Goal:** Implement one narrow Alpha diagnostic pattern.

**Pattern:** Deploy-correlated checkout/payment regression.

The pattern should detect when:

- a deployment signal occurs
- checkout/payment-related errors increase shortly after
- latency or error severity is elevated in the same time window

**Deliverables:**

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
Update docs/ROADMAP.md marking Phase 9 as done.
```

---

## Phase 10 — Incident output

**Goal:** Convert pattern matches into useful incident reports.

**Deliverables:**

- `Incident`
- `IncidentSeverity`
- `IncidentEvidence`
- `IncidentRecommendation`
- `IncidentBuilder`
- Markdown or JSON incident renderer

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
- Markdown or JSON incident renderer

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
Update docs/ROADMAP.md marking Phase 10 as done.
```

---

## Phase 11 — Pipeline orchestration

**Goal:** Connect adapters, storage, correlation, patterns, and incident generation into one executable flow.

**Deliverables:**

- `ProvadoPipeline`
- `PipelineRunRequest`
- `PipelineRunResult`
- adapter selection
- time-window selection
- pattern registry execution

**Codex prompt:**

```txt
Implement the pipeline orchestration layer.

Add:
- ProvadoPipeline
- PipelineRunRequest
- PipelineRunResult

The pipeline should:
- select enabled source adapters
- fetch signals for a time window
- persist normalized signals
- run correlation
- execute registered diagnostic patterns
- build incidents from matches
- return a PipelineRunResult

Use fake adapters in tests.
Do not require real external APIs.
Update docs/ROADMAP.md marking Phase 11 as done.
```

---

## Phase 12 — Error handling and observability

**Goal:** Make failures visible and controlled.

**Deliverables:**

- common exception types
- adapter failure result handling
- retry/backoff placeholder for source clients
- structured logging interface or placeholder
- pipeline run summary with warnings/errors

**Codex prompt:**

```txt
Implement basic error handling and internal observability.

Add:
- common exception types
- adapter failure result handling
- retry/backoff placeholder for source clients
- structured logging interface or placeholder
- pipeline run summary with warnings/errors

The pipeline must continue when one source fails if enough data exists to continue safely.
Add tests for adapter failure and partial pipeline success.
Update docs/ROADMAP.md marking Phase 12 as done.
```

---

## Phase 13 — Alpha demo flow

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
Update docs/ROADMAP.md marking Phase 13 complete.
```

---

## Phase 14 — Source integration backlog

**Goal:** Document future source tiers without implementing them yet.

**Deliverables:**

- `docs/SOURCE_INTEGRATION_BACKLOG.md`
- Tier 0: New Relic + Adobe Commerce REST
- Tier 1: CrUX, then Sentry/deployed RUM
- Tier 2: payment processor
- Tier 3: GA4 / Google Ads
- Tier 4: AI-surface visibility + edge/CDN logs

**Codex prompt:**

```txt
Create docs/SOURCE_INTEGRATION_BACKLOG.md.

Document future source tiers without implementing them:
- Tier 0: New Relic + Adobe Commerce REST
- Tier 1: CrUX, then Sentry/deployed RUM
- Tier 2: payment processor such as Adyen, Stripe, or Braintree
- Tier 3: GA4 / Google Ads
- Tier 4: AI-surface visibility + edge/CDN logs

Make clear that only Tier 0 is part of the first Alpha build.
Update docs/ROADMAP.md marking Phase 14 as done.
```

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
