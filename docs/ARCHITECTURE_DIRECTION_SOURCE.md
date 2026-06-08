# Provado — Architecture Direction & Source Roadmap

Audience: Engineering (Martin) and product team  
Status: Working conclusion for the Alpha build-up period. Intended as a starting point for architecture work, not a final spec.  
Date: June 2, 2026  
Revision: v2 — reframes AI-discovery as a symptom signal feeding the one correlation engine (not a separate "Thesis B"); Tier 4 re-justified on friction grounds only.

---

## Purpose

This document captures a specific, narrow conclusion: which architectural layers we can confidently start building now, given that some product and positioning decisions are still open.

The guiding principle is simple. Build the layers that do not change regardless of the open decisions (one dimension vs. three, exact pattern depth, output format, commercial model). Defer or stub the layers whose shape depends on those still-open decisions. This lets engineering make real progress during Alpha without building ahead into things that may move.

---

## Part 1 — Layers we can start working on now

We separate the stack into layers that are invariant (safe to build now) and layers that are decision-dependent (stub behind clean interfaces until resolved).

### Build now — invariant layers

These three layers are present in every current Provado document — the market opportunity material, Appendix A, the Q3 team goals, and the wedge/moat doc. There is no version of the product that omits them. They are therefore treated as mandatory and settled, and they are the right place to start.

### 1. Ingestion (connector layer)

Authenticated, rate-limited, retry-handling clients against the v1 sources: New Relic via NerdGraph and Adobe Commerce via its REST API. Includes Adobe Commerce Cloud tier detection (Pro vs. Starter), because the available signal envelope differs by tier. This is the foundation everything else sits on; no diagnostic capability exists without it. (Maps to Q3 Goal 2.)

### 2. Normalization (canonical signal model)

A single internal representation that every source is translated into. New Relic traces/metrics/deploy events and Adobe Commerce REST config/catalog/order data get normalized into one common schema, so all downstream logic reasons over Provado's model rather than over any vendor's response shapes. This is the layer that makes the deferred "vendor-agnostic adapter abstraction" real: adding a new source later becomes one new adapter to the canonical model, touching nothing downstream.

### 3. Correlation (cross-referencing substrate)

The engine that time-aligns signals from multiple sources and joins them by entity (SKU, region, deployment, user-agent, etc.) to surface co-occurrences. This is the software version of the manual cross-referencing that humans do today across multiple dashboards — slow, expensive, and dependent on rare full-stack expertise. Automating the join is invariant under every open product decision and is the buildable heart of the opportunity.

The substrate is signal-source-agnostic by design: it does not care whether a signal originated in New Relic, Adobe Commerce REST, a payment processor, or an AI-discovery failure. All signals land in the canonical model and are correlated the same way. This is the architectural reason there is no "Thesis A vs. Thesis B" split at the engine level.

Why these three are mandatory now: Ingestion, normalization, and correlation appear in all current documents without exception. Whatever else changes about scope, thesis lean, or output, the product always ingests signals, normalizes them, and cross-references them. These are not in question.

---

## Important boundary inside the correlation layer

The substrate (align on a timeline, join by time + entity, detect co-occurrence) is invariant and buildable now. The interpretation (what a co-occurrence means — e.g. "this deploy + latency + EU-Visa checkout-failure pattern is a 3DS regression") is the pattern library, which is still an open scope item (PRD §10.5 / tracker T-005).

Build the join now. Defer the meaning. When the pattern library is seeded (Q3 Goal 4, end of July), the correlation engine is already waiting to receive the patterns.

A discipline note: the correlation substrate should be general (source-agnostic, joins any signal on time + entity), but the signal set we actually run through it first should be narrow — limited to the v1 starting dimension (Technical Infrastructure) and the first one or two patterns. A general engine pointed at a deliberately small initial input keeps v1 output sharp instead of noisy. Correlating too many signals at once makes everything appear to co-occur with everything, drowning real findings.

---

## AI-discovery signals are a symptom feeding one engine — not a separate thesis

Earlier framing treated "find revenue leaks" (root-cause diagnosis) and "AI-discovery visibility" as two distinct theses to choose between. That framing is wrong at the product and architecture level, and we are not adopting it.

A failed or incorrect AI discovery — the merchant not surfacing in an LLM result, or being surfaced wrongly — is itself a signal that feeds the same root-cause engine as the backend signals. It is a sensor pointed at the same infrastructure, not a different product. The reason is that one defect commonly produces several symptoms: a schema gap can cause both AI invisibility and feed disapprovals and structured-data parsing failures. Detecting the discovery failure therefore makes the diagnosis richer, because an AI-discovery failure is often the first visible symptom of a backend or schema defect that APM-only signals would catch late or not at all.

This is the "three faces of one problem" model from the wedge/moat doc, applied correctly: AI Discovery is one face of the same revenue-leakage problem, and its signals flow into the one correlation engine alongside Technical Infrastructure and Conversion Performance signals.

The only legitimately separate concern is messaging, not architecture: the headline we sell should stay consistent (we diagnose revenue leaks) rather than swinging between "find your leaks" and "make you visible to AI agents." That is a marketing-clarity risk, not a product-logic or engine-design problem. At the engine level, more sensors is more coherent, not less.

---

## Stub for now — decision-dependent layers

Do not build the content of these yet; define clean interfaces so they slot in once the open decisions are made.

- Pattern library contents — depends on T-005 (which patterns, and proven to what depth).
- Dimension-specific diagnostic logic — depends on the still-open 1-vs-3 dimensions decision. Technical Infrastructure is the likely first dimension (Q3 Goal 3), but build it as the first instance of a general interface, not as hard-coded logic.
- Output / report format — still open.
- Revenue attribution & performance-fee logic — commercial model still open; attribution methodology not yet validated.

---

## Architectural imperative: keep New Relic swappable

New Relic is the first source, not the only source. It is chosen for reach, not depth — it is bundled free into Adobe Commerce Cloud and is therefore already present on nearly every target merchant, giving zero-install time-to-first-value. It is not the richest possible signal source.

The canonical signal model (layer 2) is what guarantees New Relic is a starting point rather than a cage. If New Relic's response shapes leak upward into diagnostic logic, we trap ourselves. If everything above the adapter reasons over the canonical model, New Relic becomes additive and swappable by design. Do not let any vendor's data shapes contaminate the layers above the adapter seam.

---

# Part 2 — Source integration roadmap

Sources are ordered by friction-to-access × failure-modes-unlocked — front-loading low-friction sources that are already present on the merchant, then climbing toward richer but higher-friction sources as the merchant relationship earns the right to ask for them. This ordering also tends to produce ascending merchant interest, for the right underlying reason (outcomes unlocked, not API for its own sake).

This is a research-and-readiness order and an adapter-design order — not a build-everything mandate. Build Tier 0 now; design the adapter seam so the rest slot in; build later tiers only when a real merchant's failure modes (or a design partner request) pull them forward.

---

## Tier 0 — New Relic (NerdGraph) + Adobe Commerce REST (v1, decided)

Friction: Zero. Already present on the merchant (New Relic bundled into Adobe Commerce Cloud; Adobe Commerce REST is the commerce system of record).

Unlocks: Backend and commerce-logic failure modes — payment-module config regressions, catalog/feed sync failures, deploy-correlated slowdowns, admin-API timeouts.

Note: The v1 lead pattern must be one that is fully diagnosable from these two sources alone (e.g. the 3DS/payment-config regression). Patterns that require client-side signal wait for Tier 1.

---

## Tier 1 — RUM: CrUX first, then Sentry / deployed RUM

Friction: Low. CrUX (Chrome User Experience Report) is free and public — no merchant install. Sentry / a deployed RUM product is richer but requires the merchant to have it.

Unlocks: Client-side failure modes that Tier 0 is blind to — JavaScript errors, Core Web Vitals, device- and browser-specific conversion breaks (e.g. "checkout works everywhere except mobile Safari").

Why this tier is #1 after Tier 0: Biggest coverage gain for the least friction. Directly fills the gap that New Relic Browser RUM is excluded from the Adobe Commerce Cloud bundle.

---

## Tier 2 — Payment processor: Adyen / Stripe / Braintree

Friction: Medium. Merchant must grant access to processor dashboard/API. (Confirm which processor your merchants actually use — do not assume.)

Unlocks: Declines, 3DS challenge outcomes, and authorization failures at the source, rather than inferred from APM latency/error patterns. Turns "we inferred a payment problem" into "we see the payment failures directly."

---

## Tier 3 — Analytics + ad spend: GA4 / Google Ads

Friction: Medium. OAuth grants merchants are accustomed to giving.

Unlocks: Demand-side context — localizing a funnel drop to a traffic source, and the wasted-spend-into-broken-experience narrative ("paying to drive traffic to a page that doesn't convert for this segment"). Strong for CFO-level revenue framing and prioritization; more context-around-the-failure than root cause.

---

## Tier 4 — AI-surface visibility + edge/CDN logs

Friction: Higher / platform-dependent (e.g. Cloudflare, Fastly; AI-visibility data sources).

Unlocks: AI-discovery failure signals (not surfacing in an LLM result, or surfacing wrongly) and edge-level bot-blocks (e.g. GPTBot blocked at the CDN). These are first-class symptom signals feeding the one correlation engine — frequently the first visible symptom of a backend or schema defect.

Why this tier placement: Placed here purely on friction-to-access grounds — these sources are higher-friction and more platform-dependent to integrate than Tiers 0–3. The placement is not a judgment that AI-discovery is a separate or lower-value thesis; it is the same friction-and-coverage logic applied to every source. A secondary consideration is that the standalone visibility-tracking market is being commoditized by platforms (Google, Shopify shipping native AI-visibility features) — but that affects whether visibility-tracking is a sellable standalone deliverable, not whether discovery-failure is a useful signal to our engine. As a signal, it is useful and wanted. If a design partner's leak centers on AI-discovery, pull this tier forward like any other.

---

## Two caveats on the roadmap

This is an order to dig into and design adapters for — not to build all of at once. Each tier is a new adapter to the canonical signal model. Build Tier 0 now; design the seam so the rest slot in without rework; build later tiers when pulled forward.

Let the first design partner reorder Tiers 1–4. The friction ordering is the default for when we don't yet know the merchant's actual leak. The moment a real Adobe Commerce merchant is engaged, their diagnosed pain is the override: if it's a mobile checkout break, Tier 1 leads; if payment declines, Tier 2; if wasted ad spend, Tier 3; if AI-discovery, Tier 4. Don't let the roadmap calcify before the first merchant has spoken.

---

## One-line summary

Ingestion, normalization, and correlation are mandatory and present in every current document — start there now. The correlation engine is signal-source-agnostic: backend, commerce-state, and AI-discovery-failure signals all feed the one engine (AI-discovery is a symptom signal, not a separate thesis). Stub pattern contents, dimension logic, output, and attribution behind clean interfaces. Integrate sources in friction order: New Relic + Adobe REST → CrUX then RUM/Sentry → payment processor → analytics/ad spend → AI-visibility/edge logs, ordering purely by friction-to-access and failure-modes-unlocked, treating New Relic as source #1 of N and keeping it swappable behind the canonical signal model.
