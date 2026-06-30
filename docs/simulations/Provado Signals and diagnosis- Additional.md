# Provado — Signal Readout, New Relic Shipping & Diagnosis Path

**Author:** Prepared with Claude for Martin  **Date:** 2026-06-24 **Companion to:** `Provado_Failure_to_Cause_Traces` (Martin folder).

That doc traces 12 failure modes from symptom to root cause. This doc covers a narrower, practical question that came up alongside it: a set of Magento operational signals worth reading, how a merchant could ship them into New Relic without Provado, and why getting them into New Relic still does not produce a diagnosis. The last part is the competitive seam.

## How to read this

Evidence tags follow the Traces doc convention:

- **\[verified\]** — confirmed in a primary source this session (magento/magento2 GitHub issues showing real behavior, Adobe Developer / Experience League, the New Relic Reporting module reference).  
- **\[inferred\]** — reasoned from documented behavior or established platform mechanics, not pulled verbatim this session.  
- **\[hypothesis\]** — plausible and operationally common, but unconfirmed. A thing to check, not a fact to assert.

Carried over from the Traces doc: this establishes feasibility (signals are readable and shippable) and the competitive gap (no in-band tool does the diagnosis layer). It says nothing about willingness to pay, which is still the unvalidated leg and resolves only with merchants.

---

# Part A — The signals and how to read them

Shared shape: each is a status command backed by a DB flag or file. An engineer eyeballs it when something feels wrong. Generic APM reads none of them, because they are application-domain state, not runtime transaction telemetry. Cache management is the anchor; the other six are the same pattern.

## 0\. Cache validity (the anchor)

Two states get conflated: enabled/disabled (the toggle, what `bin/magento cache:status` reports) versus valid/invalidated (stale data flagged for regeneration — the orange "Invalidated" in System \> Cache Management). No native CLI for the second; `cache:status` does not show it. **\[verified\]**

- Readout: `Magento\Framework\App\Cache\TypeListInterface::getInvalidated()` returns invalidated cache types, each a DataObject with id, label, status. Runs inside Magento (a small custom CLI command is the clean wrapper). **\[verified\]**  
- Event stream: `var/log/debug.log` carries `cache_invalidate` entries with the tag of what was flushed. Catches the trigger, not the snapshot. Caveat: debug logging must be on (default in developer mode, often off in production via `dev/debug/debug_logging`). **\[verified\]**  
- Storage: the invalidated list lives in the cache backend (same store as the config cache), not the DB. The exact key/serialization is not a public contract and has shifted across versions — verify against the `TypeList` constructor for the target version before reading it out-of-band. **\[hypothesis\]**  
- Load-bearing signal: dwell time (how long a type has sat invalidated), not the flag. That is what correlates with response-time degradation.  
- Why APM misses it: `Magento_NewRelicReporting` reports entity counts, module status, deploy markers, order events — not cache state. The agent has no domain knowledge of `getInvalidated()`. New Relic sees the consequence (slow transactions) with no attribution. **\[verified\]**  
- Tier: **Wire-Up** (signal exists and is queryable; work is collection \+ correlation).

## 1\. Indexer status (target D)

- Readout: `bin/magento indexer:status`, backed by `indexer_state` (valid / invalid / working) and `mview_state` for scheduled mode. **\[verified\]**  
- Failure: an invalid indexer serves stale prices/stock/catalog. Worse — a scheduled indexer can stick in `working` permanently if the cron process running it is killed mid-update, reporting "working" while processing nothing. **\[verified\]** (magento/magento2 \#36724)  
- Dwell signal: `indexer:status` shows "x in backlog" for scheduled mode. **\[verified\]**  
- Tier: **Wire-Up**.

## 2\. Cron health (highest leverage — upstream of most of the rest)

- Readout: the `cron_schedule` table. Status values: pending, running, success, missed, error. Plus scheduled\_at, executed\_at, finished\_at. **\[verified\]**  
- Three failure shapes: many `missed` \= scheduler not firing on time; stuck `running` past executed\_at \= dead worker; growing `pending` \= backlog. **\[verified\]** mechanics  
- Why it matters disproportionately: much of Magento runs through cron — reindexing, email queues, cache cleanup, stock/status updates. A dead cron is a single root cause that makes cache, indexer, and order-email go bad at once. (Commonly cited default \~26 modules / \~53 jobs — vendor figure.) **\[inferred\]**  
- Duration signal: executed\_at to finished\_at delta gives per-job runtime; catch a job slowing before it starts missing.  
- Tier: **Wire-Up**.

## 3\. Schema / module version drift (target E)

- Readout: `bin/magento setup:db:status`, backed by `setup_module` (schema\_version, data\_version per module vs. codebase). **\[verified\]**  
- Failure: deploy new code but the build skips `setup:upgrade`; schema lags code. Output: "Declarative Schema is not up to date. Run setup:upgrade…". **\[verified\]**  
- Sharper than it looks: `DbStatusValidator` runs as a front-controller plugin, so a mismatch can throw and hard-block the storefront, not just degrade it. To APM that is a wall of 500s with no attribution. **\[verified\]** (\#9981 stack trace)  
- Caveat: known false positives on some MySQL 8.0.28+ versions (utf8/utf8mb3 charset comparison) where it always reports "not up to date." Do not trust the binary output — diff actual versions or special-case the noisy MySQL versions. **\[verified\]** (\#35671, \#37543)  
- Tier: **Wire-Up**.

## 4\. Message queue / async bulk operations (target A territory)

- Readout: consumers via `bin/magento queue:consumers:list`; failed/piled-up ops in `magento_operation` (status per op) and bulk groupings in `magento_bulk`. **\[verified\]** (cross-ref: Traces doc item \#1)  
- Split difficulty: failed-operation counts are easy (DB-queryable). Consumer liveness — "is the process alive" — has no clean flag and reduces to a brittle process/heartbeat check. **\[inferred\]**  
- Confirm the exact `magento_operation` status enum against the target version — not re-verified this session. **\[hypothesis\]**  
- Tier: straddles **Wire-Up** (op counts) and **Instrument** (liveness).

## 5\. Maintenance mode flag (trivial to read, catastrophic when wrong)

- Readout: a single filesystem flag (`var/.maintenance.flag`) — one stat. **\[inferred\]** (path from memory; confirm on target version)  
- Failure: a deploy fails midway and leaves the flag on; the whole store is offline. APM sees traffic collapse but cannot distinguish a leftover flag from a real outage.  
- Tier: **Wire-Up**. Cheap signal, high consequence — a good early easy win.

## 6\. Search index / engine sync

- Readout: the `catalogsearch_fulltext` indexer state, plus the health of the OpenSearch/Elasticsearch cluster Magento 2.4 requires (cluster status API). **\[verified\]**  
- Failure: search out of sync or the cluster yellow/red; on-site search returns nothing or wrong results — direct revenue loss, silent. Two collectors joined.  
- Tier: straddles **Wire-Up** and **Instrument**.

---

# Part B — Shipping these into New Relic

None of this is blocked by the platform. A merchant can push all of it in with no change from New Relic. Three paths, no New Relic cooperation required. (New Relic mechanics below are from established platform knowledge, not re-pulled from New Relic docs this session — confirm before quoting in a deck.) **\[inferred\]**

- **Custom Magento cron \+ PHP agent.** A cron reads each signal through the same APIs (`getInvalidated()`, `indexer_state`, a `cron_schedule` query) and ships it with the agent's custom-event call (`newrelic_record_custom_event`) or a custom metric. Queryable in NRQL, alertable.  
- **New Relic Flex (infra agent).** Config-driven: point it at a shell command or SQL query on an interval, it ships the output. Can run `bin/magento indexer:status`, or `SELECT status, COUNT(*) FROM cron_schedule GROUP BY status`, or stat the maintenance flag — no PHP written. The path a sharp ops person actually reaches for.  
- **Event/Metric API.** POST from any script. Most general, least Magento-aware.

### Per-signal ingestion difficulty

| Signal | Ingestion difficulty | Note |
| :---- | :---- | :---- |
| Maintenance flag | Trivial | One stat; Flex one-liner. |
| cron\_schedule | Easy | One SQL GROUP BY; Flex-friendly. |
| Indexer status | Easy | CLI or `indexer_state` query. |
| Cache invalidated | Easy–Medium | Needs a small in-Magento wrapper for `getInvalidated()`; dwell-time needs state across polls. |
| Schema drift | Easy, but noisy | CLI output unreliable on some MySQL 8.0.x; better to diff `setup_module`. |
| Search | Medium | Two sources joined: Magento indexer state \+ ES/OpenSearch cluster API. |
| Queue consumer liveness | Hard | No clean flag; brittle process check. Op-failure counts are easy. |

---

# Part C — Why ingestion is not diagnosis (the seam)

Getting six or seven numbers into NRDB yields dashboard tiles and alert conditions to tune. It does not yield a diagnostic. Four layers sit on top of ingestion, and New Relic ships none. This is the honest answer to "why can't I just do this in New Relic" — say it plainly to technical evaluators, because on ingestion alone the skeptic is right.

1. **Semantics.** Knowing what to collect and what a value means: an indexer in `working` can be frozen not busy; `missed` and stuck `running` are different cron failures; `setup:db:status` lies on some MySQL versions; cache dwell time, not the flag, is the signal; "no consumer running" is normal when the queue is empty (Traces item \#1) and must be correlated with backlog before alarming. This is the Magento-internals expertise being productized — not the pushing of numbers.  
2. **Correlation.** Independent signals mean that when cron dies, cache \+ index \+ email all go stale and four alerts fire at once with no indication they are one root cause. New Relic does not know Magento's dependency graph (cron upstream of indexing, cache cleanup, email). "These four are one incident, cause is cron" is logic you encode yourself. That manual dot-connecting is the engineer-in-the-loop step Provado sells against — rebuilding it in NRQL is its own project.  
3. **Maintenance.** Custom instrumentation rots. Magento upgrades change table structures; the bulk-op enum can shift; Flex configs break on a PHP path change. Someone owns that forever. For a lean mid-market team or an agency, recurring cost, not one-time setup.  
4. **Cost.** New Relic bills on data ingest and users; high-frequency polling across many signals adds ingest to the merchant's own bill. Directionally non-trivial at frequency — verify current pricing before quoting. **\[hypothesis\]**

Framing for evaluators: the New Relic customization path is "build Provado yourself, inside New Relic." Everything is reachable through Flex and custom events. What is not reachable is the part that is not ingestion — domain semantics, cross-signal correlation, and upkeep — encoded once by people who know the stack and kept current so the merchant never touches it.

---

# Part D — Diagnosis: what Provado does on top

Mirrors the "automated collapse" section of each Traces write-up. For these signals:

- **Learn normal.** Baseline per-signal (typical cache dwell, indexer backlog, cron cadence and runtime) instead of static thresholds, so a slow drift is caught before it trips a hard limit.  
- **Dwell, not state.** Alarm on how long a contradiction persists (cache invalidated under traffic for N minutes; indexer in backlog past its normal window) — the thing that maps to revenue impact.  
- **Collapse by dependency graph.** Encode that cron is upstream of cache cleanup, indexing, email; when upstream cause and downstream symptoms co-occur, emit one verdict ("cron stopped at HH:MM; cache/index/email staleness downstream") instead of four alerts.  
- **Stamp against the deploy/config timeline.** Tie each onset to the nearest deploy or config change (target E surface), so the verdict carries a likely trigger, not just a state.  
- **Resolve the symptom New Relic already shows.** The response-time spike APM records gets its attribution from the backend flag APM never read — closing the manual hop the engineer does today.

---

# Part E — Competitive note

Add "DIY on New Relic Flex" as a named alternative in the landscape, next to Cogent2. It cuts the same way against the buyer as Noibu — arguably sharper, because it costs the buyer nothing in new licensing (they likely already run New Relic). The rebuttal is Part C: reachable, but a real engineering build plus indefinite maintenance, presuming Magento-internals depth the merchant's team usually does not have to spare. If that rebuttal can't be stated crisply, the Flex objection lands.

---

# Open items / unverified

- Cache invalidation storage key/serialization — confirm against `TypeList` constructor per target version. **\[hypothesis\]**  
- `magento_operation` status enum — not re-verified this session. **\[hypothesis\]**  
- `var/.maintenance.flag` exact path — from memory; confirm on target version. **\[inferred\]**  
- New Relic ingestion mechanics (`newrelic_record_custom_event`, Flex) and current pricing — confirm against current New Relic docs before external use. **\[inferred\]**  
- Cron "\~26 modules / \~53 jobs" default — vendor figure, not primary Adobe source. **\[inferred\]**  
- Unchanged from Traces doc: willingness to pay remains the load-bearing unvalidated assumption. Resolves only with merchants.

