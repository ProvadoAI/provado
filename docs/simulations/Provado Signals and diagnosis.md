Table of Contents

# **Provado — Failure-to-Cause Traces (Adobe Commerce operational layer)**

**Author:** Prepared with Claude for Martin **Date:** 2026-06-22 **Scope:** The 12 failure modes in *Provado — Needs — Engineer Pain Points V2*. Item \#1 (silent consumer / queue death) was already traced; this document re-verifies it against primary sources and then builds the same failure-to-cause trace for the remaining 11\.

---

## **How to read this**

Each failure mode gets the same five-part trace, matching the consumer-death write-up:

1. **Mechanics** — how the failure actually happens, and its sub-variants.

2. **The signals, and where each comes from** — grouped by the question each answers, with the exact table/column, API endpoint, CLI command, header, log path, or config file. Every signal is **read-only**.

3. **Where the manual correlation sits today** — the ordered path a human walks across N disconnected surfaces, starting from the downstream symptom.

4. **The automated collapse** — how Provado reads those signals continuously, learns normal, and fires on the exact contradiction a human resolves by hand, stamped against the deploy/config timeline.

5. **Why it’s a real gap** — which existing tools are blind and why (New Relic APM, Noibu, Adobe native, and the warehouse data-observability tools Monte Carlo / Acceldata / Bigeye).

**Evidence tags** are applied to every load-bearing technical claim:

* **\[verified\]** — confirmed directly in a primary source (Adobe Experience League, Adobe Developer, the magento/magento2 GitHub repo, RabbitMQ/Fastly/Stripe/Adyen/Google docs). The URL is given inline.

* **\[inferred\]** — reasoned from documented behavior, but not stated verbatim in a primary source.

* **\[hypothesis\]** — plausible and operationally common, but unvalidated against a primary source. Treat as a thing to confirm, not a fact to assert.

A note on what this document is and isn’t: it establishes **feasibility** (Provado can read or construct the signals) and the **competitive gap** (no in-band tool occupies this space). It does **not** establish **willingness to pay** — that is still the load-bearing unvalidated assumption across every one of these, and it resolves only by talking to merchants, not by more desk research.

---

## **Item \#1 verification — Silent consumer / queue death**

**Verdict: the existing trace is accurate.** Every load-bearing claim re-checked against the magento/magento2 source, Adobe Experience League, and RabbitMQ docs held up:

* Consumers run via the consumers\_runner cron job (class Magento\\MessageQueue\\Model\\Cron\\ConsumersRunner::run), scheduled \`\* \* \* \* \*— every minute — in theconsumerscron group. \*\*\[verified\]\*\* (app/code/Magento/MessageQueue/etc/crontab.xml\`)

* max\_messages default is **10000** — the figure in the original trace is correct. **\[verified\]** (ConsumersRunner.php: $this-\>deploymentConfig-\>get('cron\_consumers\_runner/max\_messages', 10000\))

* cron\_run \=\> false in env.php is a real kill switch; ConsumersRunner::run() returns immediately and spawns nothing. **\[verified\]** (ConsumersRunner.php)

* DB-queue status codes confirmed exactly from Magento\\MysqlMq\\Model\\QueueManagement: NEW \= 2, IN\_PROGRESS \= 3, COMPLETE \= 4, RETRY\_REQUIRED \= 5, ERROR \= 6, TO\_BE\_DELETED \= 7. The “status 6 \= Error” claim is correct, and the enum starts at 2 (there is no 0/1). **\[verified\]** (app/code/Magento/MysqlMq/Model/QueueManagement.php)

* Consumer locking uses a MySQL lock keyed on md5($consumer-\>getName()) (plus a process index for multi-process). The “md5 of the queue/consumer name” claim is correct — it is the consumer name. **\[verified\]** (ConsumersRunner.php)

* RabbitMQ Management HTTP API /api/queues/{vhost}/{qname} exposes messages\_ready, messages\_unacknowledged, consumers, and message\_stats rates (deliver\_get / ack). **\[verified\]** (rabbitmq.com/docs/monitoring)

* cron\_schedule columns (status pending/running/success/missed/error, scheduled\_at, executed\_at, finished\_at) and bin/magento queue:consumers:list all confirmed. **\[verified\]** (app/code/Magento/Cron/etc/db\_schema.xml)

**One material addition the original trace omitted:** before spawning, canBeRun() consults queue/only\_spawn\_when\_message\_available (global default **true**) and the per-consumer onlySpawnWhenMessageAvailable flag. When enabled, the runner **skips spawning a consumer whose queue currently looks empty**. **\[verified\]** (ConsumersRunner.php). The consequence matters for the detector: “no consumer running” is *normal* when the queue is empty and is **not** by itself a fault — liveness must always be correlated with backlog before alarming. This sharpens, rather than changes, the original conclusion.

The full re-verified trace for item \#1 is reproduced below in the same format as the others, since it is now the canonical version.

---

## **1\. Silent consumer / queue death (process alive, doing nothing)**

### **Mechanics**

Magento’s message-queue subsystem moves asynchronous work (async/bulk REST operations, inventory mass actions, email sending, async indexing, export feeds) through topics and queues drained by *consumers*. In production the consumers are normally not long-lived daemons but are spawned by cron.

* The cron job consumers\_runner (job class Magento\\MessageQueue\\Model\\Cron\\ConsumersRunner::run) is scheduled \`\* \* \* \* \*— every minute — in theconsumerscron group. \*\*\[verified\]\*\* (https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/MessageQueue/etc/crontab.xml). The same module schedulesmessagequeue\_clean\_outdated\_locks\` hourly. **\[verified\]**

* On each tick ConsumersRunner::run() reads cron\_consumers\_runner/cron\_run (default true). If false, it returns immediately and spawns nothing — the documented kill switch in app/etc/env.php. **\[verified\]** (https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/MessageQueue/Model/Cron/ConsumersRunner.php)

* It then reads cron\_consumers\_runner/max\_messages, **default 10000**, and for each registered consumer spawns bin/magento queue:consumers:start \<name\> \--single-thread \--max-messages=\<n\>; each spawned process consumes up to max\_messages then **terminates**, to be respawned by a later cron tick. **\[verified\]** (same file; https://experienceleague.adobe.com/en/docs/commerce-operations/configuration-guide/message-queues/manage-message-queues)

* **Important nuance:** before spawning, canBeRun() consults queue/only\_spawn\_when\_message\_available (global default true) and the per-consumer onlySpawnWhenMessageAvailable flag. When enabled, the runner **skips spawning a consumer whose queue currently looks empty**. **\[verified\]** (ConsumersRunner.php). Consequence: a “no consumer running” observation is *normal* when the queue is empty and is not, by itself, a fault — liveness must always be correlated with backlog. **\[inferred\]**

* Backend is either **RabbitMQ (AMQP, recommended)** or the **MySQL DB queue** (used when no AMQP broker is configured; default out-of-box). **\[verified\]** (manage-message-queues doc)

Sub-variants of “alive but doing nothing”:

1. **Poison / long-running message (MySQL DB queue).** A message that throws or never completes blocks progress. DB-queue lifecycle constants (verified exactly from Magento\\MysqlMq\\Model\\QueueManagement, https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/MysqlMq/Model/QueueManagement.php): NEW \= 2, IN\_PROGRESS \= 3, COMPLETE \= 4, RETRY\_REQUIRED \= 5, ERROR \= 6, TO\_BE\_DELETED \= 7. A message read for processing flips to IN\_PROGRESS (3); if a consumer dies mid-flight the row sits at 3 until cleanup re-queues it after retry\_inprogress\_after minutes; repeated failure drives it to ERROR (6), with the trial counter in the retries column. **\[verified\]** enum; **\[inferred\]** trial-limit path.

2. **Consumer never spawns because the lock is stuck.** ConsumersRunner guards double-spawning with LockManagerInterface, locking md5($consumer-\>getName()) (plus process index for multi-process). The default LockManager is the MySQL GET\_LOCK\-based backend; a lock never released (crash, killed process, DB lock leak) makes the runner believe a consumer is already running forever, so nothing respawns. The hourly messagequeue\_clean\_outdated\_locks job exists precisely to release stale locks. **\[verified\]** lock key; **\[inferred\]** failure path.

3. **cron\_run \=\> false left in env.php.** A deploy or debugging session ships the kill switch to production; cron runs, the job no-ops, the broker fills, nothing errors. **\[verified\]** mechanism; **\[hypothesis\]** as a field cause.

4. **Cron itself stalled.** If the whole cron process is wedged or cron\_schedule rows go to missed/error, consumers\_runner never fires even though the broker is healthy. **\[inferred\]**

The unifying property: in every variant the OS process table and APM see either a normally-exiting short-lived PHP process or nothing at all — no crash, no exception trace, no error-rate spike. The only evidence is *absence of progress* on the broker/DB and a *growing backlog*.

### **The signals, and where each comes from**

**Is work piling up? (backlog)** \- RabbitMQ: GET /api/queues/{vhost}/{qname} \-\> messages\_ready, messages\_unacknowledged. **\[verified\]** (https://www.rabbitmq.com/docs/monitoring). Read-only HTTP GET. \- MySQL DB queue: queue\_message\_status table — count rows by status, especially status \= 2 (NEW) growing and not draining; join to queue\_message. **\[verified\]**

**Is it alive but not working? (liveness vs throughput)** \- RabbitMQ: same /api/queues payload \-\> consumers count, consumer\_capacity, message\_stats.deliver\_get\_details.rate, message\_stats.ack\_details.rate. consumers \> 0 \+ ack rate \== 0 \+ messages\_ready \> 0 \= alive-but-stalled; consumers \== 0 \= up but disconnected. **\[verified\]** \- OS: bin/magento queue:consumers:list enumerates registered consumers (what *should* run). ps/process list shows whether any queue:consumers:start processes actually exist. **\[verified\]** / **\[inferred\]** \- Lock state: query MySQL lock for the md5(consumerName) keys. A held lock with no live process \= stuck-lock death. **\[inferred\]** (keys verified)

**Is it advancing? (progress over time)** \- RabbitMQ: deltas of message\_stats.ack / deliver\_get counters between polls. Flat counters \+ nonzero backlog \= no progress. **\[verified\]** \- MySQL DB queue: queue\_message\_status.updated\_at not advancing; rows stuck at status \= 3 past retry\_inprogress\_after. **\[verified\]**

**Where stalled work dies (terminal states)** \- MySQL DB queue: queue\_message\_status.status \= 6 (ERROR) and retries; status \= 7 (TO\_BE\_DELETED) is the tombstone. **\[verified\]** \- RabbitMQ: dead-letter exchange / .dlq counts if configured; otherwise unacked messages requeue on channel close. **\[inferred\]**

**Is the runner even firing? (upstream cron)** \- cron\_schedule: job\_code \= 'consumers\_runner', status (pending/running/success/missed/error), scheduled\_at, executed\_at, finished\_at. **\[verified\]** (https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/Cron/etc/db\_schema.xml)

**What changed (config)** \- app/etc/env.php: cron\_consumers\_runner/cron\_run, /max\_messages, /consumers, /multiple\_processes, queue/only\_spawn\_when\_message\_available. **\[verified\]**

**What the human sees first (downstream symptom)** \- Async order/email/export operations not completing; admin bulk-action status stuck; confirmation emails unsent; inventory not syncing. **\[inferred\]**

### **Where the manual correlation sits today**

From the downstream symptom (“order confirmation emails stopped” / “bulk operation stuck”):

1. **APM / New Relic** — look for errors or a throughput dip; find nothing (the consumer exits cleanly). Surface 1\.

2. **Application logs** (var/log/, exception.log, system.log) — grep the topic/consumer; usually silent (nothing threw). Surface 2\.

3. **RabbitMQ Management UI / /api/queues** (or SELECT status, COUNT(\*) FROM queue\_message\_status GROUP BY status) — discover a large backlog and ack rate \= 0. Surface 3\.

4. **Process list on the worker host** (ps aux | grep queue:consumers:start) — no live consumer, or one wedged on a message. Surface 4\.

5. **cron\_schedule table** — is consumers\_runner succeeding? Surface 5\.

6. **Lock state** — stuck lock blocking respawn? Surface 6\.

7. **app/etc/env.php** — is cron\_run false or only\_spawn\_when\_message\_available hiding it? Surface 7\.

Up to **7 disconnected surfaces** (APM, app logs, broker API, host process table, cron DB, lock manager, deploy config). None individually says “the queue is dead”; the join — “broker has backlog AND ack rate is zero AND no live process AND cron is green AND a lock is held AND config didn’t change” — happens entirely in the engineer’s head, under incident pressure, after the damage window already ran.

### **The automated collapse**

Provado reads, continuously and read-only, the three orthogonal axes a human reconciles by hand: **liveness** (/api/queues consumers/capacity, or live process presence \+ lock-held state), **backlog** (messages\_ready \+ messages\_unacknowledged, or queue\_message\_status counts), and **progress** (ack/deliver-counter deltas, or updated\_at advancement). It learns each queue’s normal backlog band and ack-rate baseline and fires on the contradiction — *backlog above baseline AND progress \= 0 over N polls* — qualified by whether consumers\_runner is succeeding in cron\_schedule (distinguishes “cron dead” from “consumer dead”) and whether a lock is held with no live process (distinguishes “stuck lock” from “legitimately idle empty queue under only\_spawn\_when\_message\_available”). Every reading is stamped against the deploy/config timeline. All signals are reads (HTTP GET, SELECT, process enumeration, config read) — never a purge, requeue, or restart.

### **Why it’s a real gap**

* **New Relic APM** is transaction- and error-oriented. A cron-spawned consumer that exits normally (or is never spawned) produces no slow transaction and no error; APM is structurally blind to the *absence* of an event. A flat ack rate is a non-event it does not alarm on.

* **Noibu** observes the browser only. Queue death manifests server-side with no front-end JS error.

* **Adobe native:** SWAT is a periodic snapshot, not a continuous liveness watch; queue:consumers:list and the RabbitMQ UI expose state but are point-in-time commands nobody watches continuously or correlates against backlog, progress, cron, and lock state simultaneously. The data exists; the continuous correlation does not.

* **Monte Carlo / Acceldata / Bigeye** reason about warehouse table freshness/volume/schema, not a live AMQP broker’s messages\_unacknowledged, a host process table, a MySQL GET\_LOCK, or cron\_schedule rows. The operational layer where this lives is outside their data model.

---

## **2\. Inventory drift / overselling / reconciliation mismatch**

### **Mechanics**

Adobe Commerce Multi-Source Inventory (MSI) does not store a single mutable “available stock” number. Salable quantity is *computed* from two inputs:

* **StockItem quantity** — the aggregated on-hand quantity of all physical sources mapped to a stock for the sales channel (e.g. Baltimore 20 \+ Austin 25 \+ Reno 10 \= 55). **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/basics/selection-reservations)

* **Outstanding reservations** — the sum of all initial reservations not yet compensated, always negative (customer A −10 \+ B −5 \= −15). **\[verified\]** (same)

A merchant can fulfill an order as long as it is less than StockItem qty \+ outstanding reservations (55 \+ (−15) \= 40 salable), and the out-of-stock threshold is subtracted on top. So the working formula is **salable qty ≈ Σ(source qty) − |outstanding reservations| − out-of-stock threshold.** **\[verified\]** (assembled from the two cited statements)

Reservations live in the inventory\_reservation table: reservation\_id, stock\_id, sku, quantity (float), and metadata JSON carrying event\_type (order\_placed, order\_canceled, shipment\_created, creditmemo\_created, invoice\_created), object\_type (always order), object\_id. **\[verified\]** (same). The table is **append-only** — reservations are never updated; the system only appends compensating rows. A full order lifecycle nets to zero: order\_placed −25, order\_canceled \+5, shipment\_created \+20 → 0\. **\[verified\]**. The append services are PlaceReservationsForSalesEventInterface / AppendReservationsInterface; reservations are deliberately immutable with no Web API setters. **\[verified\]** (https://developer.adobe.com/commerce/php/development/framework/inventory-management/reservations)

The initial negative reservation is written when the order is submitted/Open and **stays in place** through On Hold, Pending, Processing, Pending Payment, Payment Review, Suspected Fraud. A compensating positive reservation is appended on cancellation (before shipment), on shipment\_created (physical), invoice\_created (virtual/downloadable), and creditmemo\_created with return-to-stock. On shipment the on-hand source qty is also directly decremented. **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/basics/order-status)

**The drift mechanism (sub-variants).** Drift happens when the negative initial reservation and its positive compensations fail to net to zero for an order in a final state, OR when an initial reservation exists with no matching demand. Adobe explicitly enumerates two shapes: (1) “loses the initial reservation and enters too many reservation compensations (overcompensating)”; (2) “correctly places the initial reservation, but loses compensatory reservations.” **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/cli). Documented causes: upgrade to 2.3.x with orders not in a final state; starting with Manage Stock \= No then enabling it; reassigning a stock to a website while orders are in flight; removing all sources from a product with pending orders (“stuck reservations”). **\[verified\]**. Add the operationally common ones: a plugin/observer throwing after the order saves but before the compensating event fires; a failed message-queue consumer (inventory.reservations.updateSalabilityStatus, which Adobe warns “requires … to run continuously”); manual DB edits to source qty that bypass reservations. **\[verified\]** consumer note; **\[inferred\]** the failure paths.

**The append-only design means nothing throws an error when drift occurs.** A lost compensation is simply a row never appended; the salable-qty computation keeps subtracting a reservation that should have been released → silent **false out-of-stock** (salable too low) or, with overcompensation, **overselling** (salable too high → cancellations). The inventory\_cleanup\_reservations cron only deletes sequences that *already* sum to zero; it never repairs a non-zero sequence, so drift is permanent until manually corrected. **\[verified\]** (selection-reservations)

**Legacy vs MSI divergence.** The deprecated CatalogInventory layer still maintains cataloginventory\_stock\_item / cataloginventory\_stock\_status; MSI keeps inventory\_source\_item and aggregated indexes, and salable qty is a service call (GetProductSalableQtyInterface). **\[verified\]**. The two layers can disagree (e.g. parent stock\_status \= 0 while is\_in\_stock \= 1 filters a configurable off the storefront). **\[hypothesis\]** (surfaced via magento/inventory issue \#3454; not confirmed against an Adobe primary doc)

**ERP/WMS divergence.** When an external ERP/WMS is the system-of-record, inventory\_source\_item qty is only as fresh as the last sync, and Magento salable additionally nets reservations the ERP knows nothing about — so the two legitimately differ at any instant by the in-flight reservation volume, and a partial/lagged sync widens it. **\[inferred\]**

**Industry scale.** IHL Group (Sep 2025\) puts global inventory distortion (out-of-stocks \+ overstocks) at **$1.73 trillion annually**, \~6.5% of global retail sales, North America $415B. **\[verified as an industry estimate\]** (https://www.ihlservices.com/news/analyst-corner/2025/09/retail-inventory-crisis-persists-despite-172-billion-in-improvements/). This frames the cost of the symptom class, not this mechanism alone.

### **The signals, and where each comes from**

**Is the reservation ledger internally inconsistent for any order?** \- bin/magento inventory:reservation:list-inconsistencies (\-c complete, \-i incomplete, \-r raw). Raw output ORDER:SKU:QTY:STOCK, e.g. 172:bike-123:+2.000000:1. **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/cli). Read-only.

**What is the raw reservation state right now?** \- Table inventory\_reservation (reservation\_id, stock\_id, sku, quantity, metadata). Sum quantity grouped by (sku, stock\_id, object\_id), join metadata.object\_id to the order; a non-zero sum for an order in a final state is drift. **\[verified\]** (selection-reservations). Read-only SELECT.

**What does Magento believe is salable, and is the freshness consumer alive?** \- Service GetProductSalableQtyInterface per stock. **\[verified\]** (reservations dev doc). bin/magento queue:consumers:list to confirm inventory.reservations.updateSalabilityStatus is present/running. **\[verified\]**. Read-only.

**True physical/source picture vs aggregated?** \- inventory\_source\_item (per-source qty), aggregated stock index. **\[verified\]**. Read-only.

**Does legacy stock agree with MSI?** \- cataloginventory\_stock\_item.is\_in\_stock / cataloginventory\_stock\_status.stock\_status vs the MSI salable service result. **\[verified\]** tables. Read-only.

**Is the order progressing through statuses that should fire compensations?** \- sales\_order.status / sales\_shipment / sales\_creditmemo existence vs reservation event\_type rows for that order. **\[verified\]** mapping (order-status). Read-only.

**Is ERP/WMS the same as Magento source qty?** \- ERP/WMS API or sync export vs inventory\_source\_item. **\[inferred\]** (merchant-dependent). Read-only.

### **Where the manual correlation sits today**

1. **Storefront / Noibu / support tickets** — shoppers report out-of-stock on items believed in stock, or cancellations for “no inventory.”

2. **Admin order grid** — pull the SKU’s orders, eyeball statuses, see nothing wrong (no error was raised).

3. **Admin product page “Salable Quantity”** — a number that looks too low/high with no reason.

4. **SSH \+ inventory:reservation:list-inconsistencies** — first place the drift becomes visible.

5. **MySQL inventory\_reservation** — SELECT raw rows to see which event\_type append is missing or duplicated.

6. **MySQL inventory\_source\_item \+ legacy cataloginventory\_stock\_\*** — cross-check source qty and legacy/MSI disagreement.

7. **queue:consumers:list \+ cron/queue logs** — was updateSalabilityStatus or inventory\_cleanup\_reservations running in the window?

8. **ERP/WMS export** — compare system-of-record on-hand against Magento source qty.

That is **8 surfaces**. No single screen joins them; the reservation ledger, order-status timeline, consumer health, and ERP feed are reconciled only in the engineer’s head, after a customer complains. Adobe’s own remediation is a manual CLI run: list-inconsistencies \-r | create-compensations. **\[verified\]**

### **The automated collapse**

Provado, continuously and read-only: (a) sums inventory\_reservation.quantity per (sku, stock\_id, object\_id) and flags any final-state order whose sequence ≠ 0 — running the list-inconsistencies logic on a schedule instead of waiting for a human; (b) reads GetProductSalableQtyInterface and watches it move; (c) joins reservation event\_type rows against order status / shipment / creditmemo events to detect a transition that *should* have appended a compensation but didn’t; (d) checks queue:consumers:list so a stalled updateSalabilityStatus consumer is a leading indicator; (e) diffs inventory\_source\_item against legacy cataloginventory\_stock\_\* and the ERP export to localize whether drift is reservation-, sync-, or legacy-side. It learns normal per SKU/stock and fires on the contradiction (“order 172 is Complete but holds −2 unreleased reservation on stock 1”), stamped against the deploy/config timeline (a plugin deploy, a Manage-Stock toggle, a stock-to-website reassignment). All reads; Provado never writes compensations — it tells the engineer the exact order:sku:qty:stock line to run.

### **Why it’s a real gap**

* **New Relic / APM:** drift fires no exception and no slow transaction — a missing append is the absence of an event, and the salable computation returns a valid (wrong) number. **\[inferred\]**

* **Noibu:** sees the shopper hit “out of stock” but not the stranded inventory\_reservation row. Front-end only.

* **Adobe native:** the Admin shows the resulting salable number and the CLI can *detect* inconsistencies, but only when a human SSHes in and runs it — no continuous monitor, no alert, no automatic repair. **\[verified\]**

* **Monte Carlo / Acceldata / Bigeye:** observe warehouse rows (freshness/volume/schema). Drift lives in the live inventory\_reservation / inventory\_source\_item tables and in the gap between an order status and a missing append; the distortion frequently *precedes* any warehouse row (it blocks/oversells a checkout in real time, before the nightly ELT), and even after loading a warehouse-side tool sees a numerically valid quantity with healthy freshness — nothing to flag. **\[inferred\]**

---

## **3\. Promotion / cart price-rule silently stops applying**

### **Mechanics**

A price rule can show is\_active \= 1 in the Admin while no longer affecting any order. Two engines fail differently.

**Cart price rules (salesrule)** are evaluated live at quote/checkout time. Tables: salesrule; coupons salesrule\_coupon; per-customer usage salesrule\_customer and salesrule\_coupon\_usage; scope salesrule\_website and salesrule\_customer\_group. **\[verified\]** (https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/SalesRule/etc/db\_schema.xml)

**Catalog price rules (catalogrule)** are *not* evaluated live. They are pre-computed by an indexer/cron into catalogrule\_product\_price; the storefront reads the pre-built price. Tables: catalogrule, catalogrule\_product, catalogrule\_product\_price. **\[verified\]** (https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/CatalogRule/etc/db\_schema.xml). This split is the root of the gap: the Admin surfaces is\_active, not whether catalogrule\_product\_price actually contains a current row.

Sub-variants:

1. **Catalog-rule materialization stalls.** catalogrule\_product\_price is rebuilt daily by cron catalogrule\_apply\_all (Magento\\CatalogRule\\Cron\\DailyCatalogUpdate::execute), scheduled 0 1 \* \* \*, and by the Catalog Rule Product indexer. **\[verified\]** (https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/CatalogRule/etc/crontab.xml). If cron stops or the indexer is stuck invalid/working, the rule stays is\_active=1 but no current rule\_date row exists and the discount silently disappears. Adobe quality patch MDVA-43726 documents exactly this (“catalog price rule … fails to apply after partial reindex … doesn’t apply without running a full reindex”). **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-operations/tools/quality-patches-tool/patches-available-in-qpt/v1-1-12/mdva-43726-catalog-price-rule-fails-to-apply-after-partial-reindex)

2. **Timezone / DST skew on the materialized window.** catalogrule\_product stores the active window as from\_time/to\_time (int); catalogrule\_product\_price carries rule\_date/latest\_start\_date/earliest\_end\_date. **\[verified\]** schema. Magento issue \#29549 confirms a real defect where the rule’s start/end was offset by the configured timezone (not converted to UTC). **\[verified\]** (https://github.com/magento/magento2/issues/29549). A timezone config change after the last successful index leaves the window wrong while is\_active is unchanged.

3. **from\_date/to\_date expiry.** Both salesrule and catalogrule carry these columns. A rule past to\_date stops applying but is\_active stays 1. **\[verified\]**

4. **Coupon usage caps hit (“used up”).** salesrule.uses\_per\_coupon, uses\_per\_customer; times\_used; salesrule\_coupon.times\_used/usage\_limit/usage\_per\_customer; per-customer salesrule\_coupon\_usage.times\_used. When a counter reaches the cap the coupon silently stops discounting while is\_active=1. **\[verified\]**

5. **Priority / stop-further-rules.** Both tables have sort\_order and stop\_rules\_processing (default 1). A newly added higher-priority rule (lower sort\_order) with stop\_rules\_processing=1 halts evaluation before a lower-priority rule runs. **\[verified\]**

6. **Scope mismatch.** Website scope (salesrule\_website / catalogrule\_website) and group scope (\*\_customer\_group, plus catalogrule\_group\_website). A new website/store-view/group added after the rule was created is silently out of scope. **\[verified\]**

7. **Deploy / config knock-out.** A setup:upgrade/deploy that runs a full reindex can wipe DB triggers off catalogrule\_product\_price, after which on-schedule updates stop materializing — Adobe documents this as MDVA-43601. **\[verified\]** (https://experienceleague.adobe.com/docs/commerce-knowledge-base/kb/support-tools/patches/mdva-43601-triggers-are-removed-from-catalogrule-product-price-table.html)

### **The signals, and where each comes from**

**Is the rule supposed to be live right now?** \- salesrule / catalogrule: is\_active, from\_date, to\_date, sort\_order, stop\_rules\_processing. **\[verified\]**. Read-only SELECT.

**Is a catalog rule actually materialized for today?** \- catalogrule\_product\_price rows for the current rule\_date (rule\_price, website\_id, customer\_group\_id, latest\_start\_date, earliest\_end\_date). Absence of a current-date row for a product the rule should cover \= active but not applying. catalogrule\_product.from\_time/to\_time offset by timezone is the \#29549 signature. **\[verified\]**. Read-only SELECT.

**Is the materialization pipeline healthy?** \- Catalog Rule Product indexer status (indexer:status; indexer\_state/mview\_state; changelog catalogrule\_product\_cl). Note Adobe documents that these status tables may not reflect a silently failed indexer (ACSD-51431). **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-operations/configuration-guide/cli/manage-indexers). catalogrule\_apply\_all success/last-run in cron\_schedule. **\[verified\]**. Read-only.

**Has a coupon been used up?** \- salesrule.times\_used vs uses\_per\_coupon; salesrule\_coupon.times\_used vs usage\_limit; salesrule\_coupon\_usage.times\_used vs usage\_per\_customer. **\[verified\]**. Read-only.

**Is the rule in scope for the traffic that needs it?** \- salesrule\_website/salesrule\_customer\_group; catalogrule\_website/catalogrule\_customer\_group/catalogrule\_group\_website. **\[verified\]**. Read-only.

**Is the discount actually landing on real orders (rule EFFECT)?** \- salesrule\_coupon\_aggregated (and \_order): period, coupon\_code, rule\_name, coupon\_uses, discount\_amount. A rule’s discount\_amount/coupon\_uses dropping to zero against a non-zero baseline is the direct effect signal. **\[verified\]**. Quote/order applied-discount fields (applied\_rule\_ids, discount columns). **\[inferred\]** exact columns. Read-only SELECT.

### **Where the manual correlation sits today**

1. **Admin \> Marketing \> Cart/Catalog Price Rules** — confirm is\_active, dates, scope visually.

2. **DB salesrule/catalogrule** — confirm dates, sort\_order, stop\_rules\_processing.

3. **DB catalogrule\_product\_price** — is there a current rule\_date row for the product/website/group?

4. **CLI indexer:status \+ cron\_schedule/indexer\_state** — did the indexer and catalogrule\_apply\_all actually run and succeed?

5. **DB salesrule\_coupon/salesrule\_coupon\_usage/salesrule\_customer** — usage caps.

6. **DB scope tables** — is the affected website/group bound?

7. **DB salesrule\_coupon\_aggregated / quote-order discount fields** — is the discount actually landing?

8. **Deploy/config history** — a recent setup:upgrade (triggers dropped, MDVA-43601), timezone change (\#29549), or new website/group?

Up to **8 disconnected surfaces** (Admin, six raw SQL tables, one CLI, deploy history). The join — “active AND in window AND in scope AND not capped AND materialized AND the pipeline ran AND a deploy/timezone change preceded it” — exists only in the engineer’s head.

### **The automated collapse**

Provado polls rule status/dates/scope, materialization (catalogrule\_product\_price current rule\_date row?), pipeline health (indexer\_state/cron\_schedule), usage counters, and — critically — measures rule **EFFECT** from salesrule\_coupon\_aggregated and order/quote discount fields, learning each rule’s normal discount throughput. It fires on the *contradiction*, not on any single field: “is\_active=1, within dates, in scope, not capped — yet no current-date catalogrule\_product\_price row / coupon\_aggregated.discount\_amount collapsed to zero / applied-rule discount disappeared from new orders.” That is the difference between status and effect. It stamps onset against the deploy/config timeline (a setup:upgrade that dropped triggers, a timezone change, a new website/group, a stalled catalogrule\_apply\_all). All reads.

### **Why it’s a real gap**

* **Adobe native Admin:** shows is\_active, dates, scope — but has **no surface for rule effect**; nothing tells the merchant whether catalogrule\_product\_price is materialized or whether the discount lands on orders. Status ≠ effect. **\[verified by absence\]**

* **New Relic APM:** a rule that materializes no price throws no error and no slow transaction — the storefront serves the non-discounted (correct, fast) price. Blind to a business-logic no-op.

* **Noibu:** a missing discount renders as a valid page; no JS error.

* **Monte Carlo / Acceldata / Bigeye:** sit on the warehouse, not the live OLTP Commerce DB — they can flag yesterday’s promo-revenue table drifting *after* it lands, with no view into catalogrule\_product\_price, the indexer state, or cron, and no ability to attribute it to a deploy/timezone change inside Commerce.

Honest scope: detecting *effect collapse* needs a healthy baseline; a brand-new rule that never worked has no “normal” to contradict, so the strongest signal is for established rules that stop.

---

## **4\. Indexer / projection stagnation → stale price \+ stale/empty search**

### **Mechanics**

Magento serves the storefront from *indexes* (denormalized projection tables and, for search, an Elasticsearch/OpenSearch/Live Search index) rather than from normalized dictionary tables, because computing price/stock/search per request is too slow. **\[verified\]** (https://developer.adobe.com/commerce/php/development/components/indexing/). When the dictionary changes, the index must be rebuilt or the storefront serves stale data.

Two indexer modes: **Update on Save** (index updated immediately) and **Update by Schedule** (changes recorded, index updated by cron on a schedule — the default for new indexers and recommended for production). Mode read with indexer:show-mode, set with indexer:set-mode. **\[verified\]** (same; https://experienceleague.adobe.com/en/docs/commerce-operations/configuration-guide/cli/manage-indexers)

The **mview / changelog mechanism** underlies Update by Schedule: \- mview.xml declares, per indexer (view id), which dictionary tables to watch. **\[verified\]** \- For each watched table, Magento auto-creates MySQL AFTER INSERT/UPDATE/DELETE triggers that INSERT IGNORE the changed entity\_id into a changelog table \<indexer\_table\>\_cl (e.g. catalog\_product\_price\_cl, catalogsearch\_fulltext\_cl), with an auto-increment version\_id. **\[verified\]** (indexing doc) \- The mview\_state table tracks per view the last processed version\_id, plus status and mode. Magento\\Framework\\Mview\\View\\StateInterface: MODE\_ENABLED/MODE\_DISABLED, STATUS\_IDLE/STATUS\_WORKING/STATUS\_SUSPENDED. **\[verified\]** (https://github.com/magento/magento2/blob/2.4-develop/lib/internal/Magento/Framework/Mview/View/StateInterface.php) \- Cron drains the changelogs. Magento\_Indexer schedules indexer\_reindex\_all\_invalid (\* \* \* \* \*), indexer\_update\_all\_views (\* \* \* \* \*), indexer\_clean\_all\_changelogs (\*/5 \* \* \* \*) in the index group. indexer\_update\_all\_views selects changelog rows with version\_id greater than mview\_state.version\_id, runs the indexer’s execute(ids), and advances version\_id. **\[verified\]** (https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/Indexer/etc/crontab.xml). This is the *same per-minute cron loop* that runs consumers — when cron is wedged, indexers and consumers stall together. **\[inferred\]**

Indexer status (distinct from mview status): Magento\\Framework\\Indexer\\StateInterface defines STATUS\_VALID, STATUS\_INVALID, STATUS\_WORKING, STATUS\_SUSPENDED, persisted in indexer\_state, surfaced by indexer:status and Admin \> System \> Index Management. **\[verified\]** (https://github.com/magento/magento2/blob/2.4-develop/lib/internal/Magento/Framework/Indexer/StateInterface.php)

**The “valid-while-failed” problem (the crux, and confirmed by Adobe):** \- Adobe states verbatim: *“the indexer\_state or mview\_state database tables may not be the same as what is observed, because they sometimes do not get updated when an indexer fails.”* **\[verified\]** (indexing doc) \- Patch **ACSD-51431** documents a concrete stuck state: an on-schedule indexer shows working (0 in the backlog) instead of returning to idle — mview\_state.status wedged at working with nothing to process, which permanently blocks future draining because the view believes a run is in progress. **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-operations/tools/quality-patches-tool/patches-available-in-qpt/v1-1-33/acsd-51431-indexer-status-is-working). So a stuck indexer can present as valid and/or working while doing no work — it never shows “invalid”, so it never triggers the reindex-invalid path. \- indexer.use\_application\_lock \= true (env.php, since 2.4.3) makes on-failure status more accurate so cron re-picks a failed indexer instead of requiring a manual indexer:reset. Without it, a failed indexer can stay stale silently. **\[verified\]**

How stale price / empty search arise: if mview\_state.status is stuck at working, or cron stops draining, the \*\_cl changelog grows unbounded — version\_id keeps incrementing from live triggers while mview\_state.version\_id stays frozen. The gap between changelog MAX(version\_id) and mview\_state.version\_id is the backlog of un-applied changes. The storefront keeps serving the last successfully built projection: stale catalog\_product\_price, missing new products, stale/empty catalogsearch\_fulltext. **\[verified\]** mechanism; **\[inferred\]** the serve-stale consequence. Search half is conditional on the engine (Elasticsearch/OpenSearch self-managed or Adobe Live Search): if the engine is unreachable/empty/misconfigured while the DB indexer reports valid, search returns zero results even though products exist. **\[inferred\]**. GTIN/feed disapproval and elevated zero-result rate are downstream symptoms, not the root signal. **\[hypothesis\]** (feed-disapproval linkage)

### **The signals, and where each comes from**

**Is work piling up? (changelog backlog)** \- \<indexer\>\_cl tables (catalog\_product\_price\_cl, catalog\_category\_product\_cl, catalogsearch\_fulltext\_cl): SELECT MAX(version\_id). **\[verified\]** \- mview\_state.version\_id per view\_id. Backlog \= MAX(cl.version\_id) − mview\_state.version\_id. **\[verified\]**. Read-only SELECT.

**Can it look healthy while stalled? (the valid-while-failed contradiction)** \- indexer\_state.status/updated; mview\_state.status/mode. A view stuck at working with zero remaining backlog is the ACSD-51431 signature. Surfaced read-only by indexer:status/indexer:show-mode. Adobe’s own caveat: status may not update on failure — so status alone is unreliable and must be cross-checked against backlog/version progress. **\[verified\]**

**Is it advancing? (progress over time)** \- mview\_state.version\_id advancing toward MAX(\<cl\>.version\_id); mview\_state.updated/indexer\_state.updated timestamps advancing. Frozen version\_id \+ frozen timestamp \+ nonzero backlog \= stalled. **\[verified\]** columns; **\[inferred\]** the frozen-trio interpretation.

**Is the drain cron running? (upstream)** \- cron\_schedule rows for indexer\_update\_all\_views, indexer\_reindex\_all\_invalid, indexer\_clean\_all\_changelogs — status/scheduled\_at/executed\_at/finished\_at. **\[verified\]**

**Search-engine half (conditional)** \- Elasticsearch/OpenSearch GET /\_cat/indices and GET /\<index\>/\_count (index exists, doc count consistent with catalog size); Adobe Live Search status. catalogsearch\_fulltext indexer status \+ \_cl backlog ties DB-side to engine-side. **\[verified\]** core; **\[inferred\]** Live Search.

**What changed (config/deploy)** \- app/etc/env.php indexer/use\_application\_lock; mode flips (indexer:set-mode); a deploy running setup:upgrade (can invalidate indexers) or dropping/recreating \*\_cl triggers. **\[verified\]** use\_application\_lock; **\[inferred\]** deploy linkage.

### **Where the manual correlation sits today**

1. **Admin \> Index Management** (or indexer:status) — frequently shows Ready/valid/working, so it looks fine — the false-clear.

2. **mview\_state** — per-view status and version\_id; may find a view stuck at working.

3. **\*\_cl changelog tables** — MAX(version\_id) vs mview\_state.version\_id to measure un-applied backlog.

4. **cron\_schedule** — is indexer\_update\_all\_views actually succeeding?

5. **app/etc/env.php** — use\_application\_lock, mode changes.

6. **Elasticsearch/OpenSearch (or Live Search)** — \_cat/indices / \_count to separate DB-index stall from engine failure.

7. **Feed/merchandising platform** — confirm disapprovals trace to stale/missing indexed rows.

**6–7 disconnected surfaces** (Admin grid/CLI, mview\_state, changelog tables, cron\_schedule, env.php, the search engine, the feed platform). The native status command actively *misleads* (Adobe’s own warning), so the engineer must reconstruct “status says valid, but changelog max version is 47,000 and mview is frozen at 38,000, and cron last succeeded 40 minutes ago” — a join done in the engineer’s head.

### **The automated collapse**

Provado reads the three orthogonal axes continuously and read-only: **liveness/health** (indexer\_state.status, mview\_state.status/mode — treated as unreliable per Adobe’s own warning, never trusted alone); **backlog** (MAX(\<cl\>.version\_id) − mview\_state.version\_id per view); **progress** (mview\_state.version\_id/.updated advancement; cron\_schedule cadence for indexer\_update\_all\_views). It learns each view’s normal backlog band and drain cadence and fires on the exact contradiction the engineer resolves by hand: *status reads valid/working/idle (looks healthy) WHILE the changelog backlog is above baseline AND version\_id has not advanced over N cron windows* — the generalized ACSD-51431 signature. Cron status disambiguates “drain cron dead” from “this one view wedged.” The search half is added conditionally (when catalogsearch\_fulltext backlog grows or the engine \_count diverges from catalog size). Every reading is stamped against the deploy/config timeline (a setup:upgrade, a mode flip, a use\_application\_lock change). All reads — no reindex, no reset.

### **Why it’s a real gap**

* **New Relic APM:** a stalled scheduled indexer throws nothing on the request path (the storefront serves the stale projection *fast*), and a cron drain that fails to advance is a non-event. Blind to “the projection silently stopped advancing” — no error, no latency signal, only stale-but-fast reads.

* **Noibu:** stale prices and empty search render as valid pages with no JS error; Noibu cannot tell a correct $3.99 from a stale $4.99, nor an empty result from a legitimately empty query.

* **Adobe native:** Index Management / indexer:status expose the data, but Adobe explicitly warns status “may not be updated when an indexer fails” — the native surface can *actively report healthy while stale*. SWAT is a periodic snapshot, not a continuous version\_id-progress watch. Nobody watches it continuously or joins status against changelog backlog and cron cadence.

* **Monte Carlo / Acceldata / Bigeye:** could alarm on a downstream *warehouse* table going stale, but have no model of mview\_state.version\_id vs \*\_cl.version\_id, the working\-stuck state, the per-minute indexer\_update\_all\_views cron, or the live MySQL changelog triggers. The mechanism that produces the stale data sits below the warehouse, where these tools do not look.

---

## **5\. Deploy / config-regression correlation (“what changed?”)**

### **Mechanics**

A deploy or config change silently degrades revenue or performance; the hard part is correlating the symptom to the exact change, because the change surfaces are fragmented and only one of them emits a marker. This is the “spine” — once changes are on a timeline, the other failure modes become attributable.

**Change surface A — code/infra deploys (Adobe Commerce Cloud).** Cloud deploys are git-based and run three phases: **build**, **deploy**, **post-deploy**; the deploy phase runs ece-tools including bin/magento setup:upgrade. **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-on-cloud/user-guide/develop/deploy/process). .magento.env.yaml controls per-phase actions. **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-on-cloud/user-guide/configure/env/configure-env-yaml)

**Change surface B — admin config edits (core\_config\_data).** A config change in the Admin writes the value to core\_config\_data (with scope, scope\_id, path, value, updated\_at). These edits **emit no deploy marker** — they happen at runtime in the DB, outside the git/deploy pipeline. bin/magento config:show reads saved values. **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-operations/configuration-guide/cli/configuration-management/set-configuration-values; column list confirmed via the Config db\_schema.xml, see §10). The updated\_at column and the precise column set are confirmed in app/code/Magento/Config/etc/db\_schema.xml.

**Change surface C — file-baked config (app/etc/env.php).** Values locked into deployed code (config:set \--lock-env/config.php) ride along with the deploy; diffable in git but a separate surface from both runtime DB edits (B) and the New Relic marker (D). **\[inferred\]**

**Change surface D — the observability marker (New Relic).** New Relic change tracking records deployments/changes as NRDB events. Via NerdGraph: the legacy changeTrackingCreateDeployment mutation stores a **Deployment** event (auto-set timestamp and deploymentId, plus revision/commit); the newer changeTrackingCreateEvent is richer (commit SHAs, changelogs, custom attributes). Markers render as overlays on APM/browser charts. **\[verified\]** (https://docs.newrelic.com/docs/change-tracking/overview/; https://docs.newrelic.com/docs/change-tracking/change-tracking-graphql/)

**How a deploy silently breaks revenue/performance (sub-variants):** 1\. **setup:upgrade invalidates indexers** — leaves indexers invalid, so prices/stock/catalog serve stale until reindex (links to \#4). **\[verified\]** (https://support.magento.com/hc/en-us/articles/12942691382925) 2\. **A config flag flipped** in core\_config\_data (a payment method disabled, a tax/shipping toggle, a Payment Action changed — links to \#7) — runtime DB change, no deploy marker. **\[inferred\]** 3\. **A cacheable="false" block** introduced in layout XML disables FPC for affected page types, collapsing hit ratio and raising latency (links to \#12). **\[hypothesis\]** (known Magento behavior; verified in \#12) 4\. **A plugin (interceptor) sortOrder change** in di.xml reorders interceptors around a core method, changing behavior silently. **\[hypothesis\]** 5\. **Cache flush / indexer invalidation post-deploy** causes a cold-cache latency spike that *looks* like a regression but is transient — must be distinguished from a true regression. **\[inferred\]**

### **The signals, and where each comes from**

**When did code/infra change, and to what revision?** \- New Relic NRDB **Deployment**/change event: timestamp, deploymentId, revision/commit, entity.guid. Query via NerdGraph or NRQL FROM Deployment. **\[verified\]**. Read-only. \- Adobe Cloud deploy logs — var/log/cloud.log and build/deploy/post-deploy phase output. **\[verified\]** phases. Read-only. \- Git: the deployed commit SHA. Read-only.

**When did an admin config value change, and which one? (the marker-less surface)** \- core\_config\_data: path, value, scope, scope\_id, updated\_at. updated\_at is the only timestamp of the edit and there is no corresponding deploy marker. **\[verified\]** (Config db\_schema.xml; read via config:show). Read-only. \- app/etc/env.php — file-baked config, diff across deploys. Read-only.

**Did the deploy leave the system degraded?** \- indexer\_state / indexer:status — indexers invalid/reindex required after setup:upgrade. **\[verified\]**. Read-only. \- Cache hit ratio drop (a cacheable=false block or post-deploy flush) via Redis/Varnish/Fastly metrics. **\[inferred\]**. Read-only. \- setup\_module schema/data versions — what setup:upgrade migrated. **\[inferred\]**. Read-only.

**What’s the downstream symptom and its onset time?** \- New Relic APM: latency / error-rate / throughput change with a precise onset timestamp. **\[verified\]**. Read-only. \- Magento sales\_order volume/conversion drop after a timestamp. **\[inferred\]**. Read-only.

### **Where the manual correlation sits today**

1. **New Relic APM chart** — pinpoint onset time T.

2. **New Relic Deployment markers** — is there a deploy marker near T? The marker carries only timestamp \+ revision; it does not say *what in the deploy* mattered.

3. **Adobe Cloud deploy logs / git** — open the deploy log and commit diff for the three phases.

4. **core\_config\_data.updated\_at** — *separately* check whether an admin config edit happened near T. This surface has **no marker in New Relic**, so step 2 will not show it; the engineer must know to look.

5. **indexer:status / cache state** — did setup:upgrade invalidate indexers or a flush cause a cold-start spike?

**5 disconnected surfaces** (New Relic APM, New Relic markers, Cloud logs/git, Magento core\_config\_data, Magento indexer/cache state). New Relic joins surfaces 1 and 2 for code deploys — but it has **no marker for an admin config edit** (surface 4\) and **no visibility into Magento backend operational state** (surface 5). The full join is assembled in the engineer’s head.

### **The automated collapse**

Provado maintains a single unified change timeline by reading, read-only and continuously: New Relic Deployment/change events (NerdGraph), Adobe Cloud deploy phase logs, core\_config\_data (polling updated\_at for path/scope/value deltas), app/etc/env.php diffs, indexer\_state, and cache hit metrics. It learns normal for each operational signal (FPC hit ratio, indexer validity, latency, conversion). When a symptom anomaly fires, it does not just look for a New Relic marker — it overlays the onset against **all** change surfaces, including the marker-less core\_config\_data.updated\_at edits and the post-deploy indexer/cache state, and surfaces the single nearest contradicting change (e.g. “conversion −12% at T; no New Relic deploy marker, but core\_config\_data path payment/... flipped at T−2min, scope website 1”). This stamp is reused as the spine for the other modes. All reads.

### **Why it’s a real gap**

* **New Relic APM \+ change tracking (the partial-coverage case):** New Relic genuinely HAS deployment markers and change events with timestamp/revision/deploymentId, overlaid on APM charts. **\[verified\]**. But (a) it only knows about changes something explicitly *pushed* to it — an **admin edit to core\_config\_data emits no event and never appears as a marker**; and (b) it has **no model of Magento backend operational state** — indexer invalidation from setup:upgrade, a cacheable=false block collapsing FPC, a plugin sortOrder change — so even when a marker exists, it cannot tell you *which part of the deploy* caused the regression. It correlates time, not Magento internals.

* **Noibu:** can show the symptom started at T, but has zero visibility into deploys, core\_config\_data, indexers, or cache.

* **Adobe native:** deploy logs and config:show/core\_config\_data exist, but they are separate manual surfaces with no correlation engine and no learned baseline.

* **Monte Carlo / Acceldata / Bigeye:** would, at best, flag a derived metric anomaly hours later in the warehouse. No access to core\_config\_data, deploy phases, indexer state, or New Relic markers.

---

## **6\. Silent order loss (shopper finished, no order created)**

### **Mechanics**

Checkout converts a quote (cart) into a sales\_order via Magento\\Quote\\Model\\QuoteManagement. The public entry is placeOrder($cartId, $paymentMethod), which wraps the work in a per-cart mutex (cartMutex-\>execute(..., 'placeOrderRun', ...)); placeOrderRun loads the active quote, imports payment, dispatches checkout\_submit\_before, then calls $order \= $this-\>submit($quote). If submit returns null it throws LocalizedException("A server error stopped your order from being placed…"). **\[verified\]** (https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/Quote/Model/QuoteManagement.php)

submit() → submitQuote() does the conversion and order save. The critical sequence: 1\. $quote-\>reserveOrderId(); — assigns a reserved increment id to the quote **before** the order is saved. **\[verified\]** 2\. Builds $order, copies the reserved id. **\[verified\]** 3\. Dispatches sales\_model\_service\_quote\_submit\_before. **\[verified\]** 4\. try { $order \= $this-\>orderManagement-\>place($order); $quote-\>setIsActive(false); … $this-\>quoteRepository-\>save($quote); } catch (\\Exception $e) { $this-\>rollbackAddresses($quote, $order, $e); throw $e; }. **\[verified\]**

**The silent-loss window is steps 3–4.** Payment authorization happens inside orderManagement-\>place($order). If place() authorizes/captures at the gateway and then **anything throws before or during the order persist** — a plugin/observer on sales\_model\_service\_quote\_submit\_before/…\_success, an order-save DB exception, a deadlock on insert, an FK failure — the exception propagates, rollbackAddresses runs, setIsActive(false)/quoteRepository-\>save may never complete, and **no sales\_order row exists**, yet the money was already taken at the PSP. **\[verified\]** mechanics. A secondary path: rollbackAddresses re-dispatches sales\_model\_service\_quote\_submit\_failure; if a listener there throws, Magento surfaces “An exception occurred on ‘sales\_model\_service\_quote\_submit\_failure’ event.” **\[verified\]**

Sub-variants: \- **A — transaction-wrapped guest checkout deadlock.** A merchant lost **400+ paid orders during a peak hour** with charged PSP transactions but no Magento order. Root cause: a PR wrapped the entire guest placeOrder (GuestPaymentInformationManagement) in beginTransaction()/commit() across the payment call and multi-entity save; under concurrency all orders contend on the same sales\_rule row (a site-wide coupon counter), producing lock-wait timeouts; the deadlocked transaction rolls back the order even though the PSP captured. Adobe shipped patch **MDVA-31519**; the thread reports recurrences as recently as 2.4.7-p2 and on registered checkouts. **\[verified\]** (https://github.com/magento/magento2/issues/25862) \- **B — stock race.** Reproduced in the same thread: product stock \= 1; shopper goes to a slow gateway; stock drops to 0; PSP captures and sends the callback; Magento fails to create the order because stock is now 0\. Money taken, no order. **\[verified\]** (issue \#25862) \- **C — grid-insert deadlock.** Under load, INSERT INTO sales\_order\_grid (and sales\_invoice\_grid for auto-invoiced orders) can throw SQLSTATE\[40001\] 1213 Deadlock. The documented mitigation is dev/grid/async\_indexing \= 1 so grid rows are populated by cron rather than synchronously in the placement request. **\[verified\]** (magento/magento2 issues \#36334, \#9756); **\[inferred\]** that the synchronous grid deadlock rolls back the placement.

**Why the quote table is the tell.** reserveOrderId() writes reserved\_order\_id *before* the order saves, and the quote is only marked is\_active \= 0 *after* orderManagement-\>place() succeeds. So a quote with a populated reserved\_order\_id, still is\_active \= 1, and no matching sales\_order.increment\_id is a near-certain fingerprint of a silent loss. **\[inferred\]** (combines the verified reserveOrderId/setIsActive(false) ordering)

**Evidence the problem is real and recurring:** a market of paid “Missing Orders / Recover Orders” extensions exists specifically to scan for paid-but-not-created orders and rebuild the sales\_order row — FME “Missing Orders” is listed on the Adobe Commerce Marketplace, alongside Meetanshi and Mageefy equivalents. **\[verified\]** (https://commercemarketplace.adobe.com/fme-missing-orders.html). A recurring, monetizable third-party fix is direct evidence the platform leaves the gap open.

### **The signals, and where each comes from**

**Did a checkout begin converting but never produce an order?** \- quote: rows where reserved\_order\_id IS NOT NULL AND is\_active \= 1 AND no sales\_order with that increment\_id. **\[verified\]** ordering. Read-only SELECT.

**Did the order half-materialize?** \- sales\_order vs sales\_order\_grid divergence (grid row missing after a grid-insert deadlock). **\[verified\]**. Read-only.

**Did something throw after payment?** \- var/log/exception.log / system.log for the placeOrderRun LocalizedException, the payment-information manager’s critical($e), and “Rolled back transaction has not been completed correctly” / “An exception occurred on ‘sales\_model\_service\_quote\_submit\_failure’ event.” **\[verified\]** strings. Read-only.

**Was it a deadlock / lock-wait under load?** \- MySQL SHOW ENGINE INNODB STATUS / error logs for SQLSTATE\[40001\] 1213 and 1205 Lock wait timeout, especially on sales\_rule, sales\_order\_grid, sales\_invoice\_grid, inventory\_source\_item. **\[verified\]**. Read-only.

**Did the gateway capture money with no Magento order behind it?** \- PSP transaction list (Stripe / Adyen / PayPal / Authorize.Net API) reconciled against sales\_payment\_transaction and sales\_order by increment id / txn id. A captured PSP txn whose increment id has no sales\_order row is a confirmed silent loss. **\[verified\]** outcome. Read-only.

**Is the grid-deadlock mitigation on?** \- dev/grid/async\_indexing in core\_config\_data / env.php. **\[verified\]**. Read-only.

### **Where the manual correlation sits today**

1. **Customer email / chargeback / support ticket** — “I was charged but have no order.” Often the only trigger, days later.

2. **PSP dashboard** — confirm a captured/authorized transaction exists, with the Magento increment id.

3. **Admin order grid** — search that increment id; no order.

4. **MySQL sales\_order** — confirm no order row (rules out grid-only desync).

5. **MySQL quote** — find the quote with the matching reserved\_order\_id, still is\_active \= 1 — proof the conversion started and died.

6. **var/log/exception.log/system.log** — search the timestamp for the throwing plugin / LocalizedException / submit\_failure wrap.

7. **MySQL InnoDB status / error log** — check for 1213/1205 deadlock on sales\_rule or the grid tables.

8. **core\_config\_data/env.php** — async\_indexing and the relevant patch in place?

**8 surfaces** (PSP, admin grid, sales\_order, quote, app logs, DB engine status, config, deploy history). The join — “PSP txn id → increment id → orphaned quote → exception/deadlock in the same window → which deploy introduced the throwing plugin” — exists only in the engineer’s head, and only after a customer complains or a chargeback lands.

### **The automated collapse**

Provado, continuously and read-only: (a) SELECTs the quote fingerprint (reserved\_order\_id IS NOT NULL AND is\_active \= 1 AND no matching sales\_order) — the leading internal indicator that fires *before* the customer emails; (b) diffs sales\_order vs sales\_order\_grid; (c) tails the logs for the placement strings and submit\_failure wrap; (d) reads InnoDB status / error logs for 1213/1205 on sales\_rule, the grids, inventory\_source\_item; (e) reconciles the PSP capture list against sales\_payment\_transaction \+ sales\_order so a captured txn with no order is flagged within minutes, not days. It learns the normal rate of orphaned quotes / deadlocks / PSP-to-order match rate and fires on the contradiction (“PSP captured txn 00000172 / quote 5531 has reserved\_order\_id 00000172, is\_active=1, and no sales\_order”) — unambiguous: the shopper paid, the order was never created. Every fire is stamped against the deploy/config timeline (the plugin deploy that started throwing on sales\_model\_service\_quote\_submit\_\*, the coupon serializing on sales\_rule, the moment async\_indexing was toggled off). All reads — Provado never recreates orders (unlike the paid “Missing Orders” extensions, which mutate the DB).

### **Why it’s a real gap**

* **New Relic APM (the cleanest blind spot):** there is no error transaction for an order that simply never got created after the payment call returned. The throw is caught-and-rolled-back (so the request may return a handled error), or the PSP callback succeeds and Magento “correctly” declines to create the order. APM sees a completed payment call and either a handled exception or nothing anomalous — no missing-transaction signal, because the missing thing is a *row*, not a *trace*. **\[inferred\]**

* **Noibu:** the browser often shows success (PSP redirect back, or a hung spinner); front-end RUM cannot see that no sales\_order row was written server-side.

* **Adobe native:** the order grid only shows orders that *exist*; a silent loss is by definition not in the grid. Adobe’s only acknowledgment is reactive patches (MDVA-31519) and async\_indexing config — no detection, no alert. The paid Marketplace “Missing Orders” extensions confirm Adobe leaves detection to third parties. **\[verified\]**

* **Monte Carlo / Acceldata / Bigeye:** a silently lost order never becomes a sales\_order row, so it never lands in the warehouse — no row whose freshness/volume/distribution could deviate. At best they notice an order-*volume* dip (lagging, noisy) after the nightly load; they cannot point at the orphaned quote or the captured PSP txn. The loss precedes any warehouse row by design. **\[inferred\]**

---

## **7\. Order created, payment never captured**

### **Mechanics**

The split between **authorization** and **capture** is the root mechanism. The *Payment Action* is configured per method (Stores \> Configuration \> Sales \> Payment Methods) and “determines when the funds are captured and when invoices are created.” **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-admin/config/sales/payment-methods/payment-methods). Verbatim: **Authorize** “Authorizes the buyer’s account for the order total but does not capture the payment. Capture payment by creating an invoice.” **Authorize and Capture** “Authorizes … and captures … An invoice is automatically created.” **Order** (PayPal) allows capture “within a defined time period (up to 29 days).” **\[verified\]**

Capture in Magento is bound to **invoice creation**: “with Authorize only, you must manually create an invoice to trigger the capture process”; the Admin Invoice button “does not appear when the payment action … is set to Authorize and Capture.” **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-admin/stores-sales/order-management/invoices)

Sub-variants of authorized-but-not-captured: 1\. **Authorize-only with no invoice ever created.** Order placed, an authorization transaction recorded, but no operator/cron ever creates the invoice. No invoice \= no capture; the order sits in Processing/Pending with amount\_authorized set and amount\_paid 0\. **\[inferred\]** from the two verified mechanics. 2\. **Fraud / payment-review hold blocks auto-capture.** Orders enter Payment Review/Suspected Fraud; the auto-invoice step that would capture is suspended pending manual approval. If never resolved, capture never fires. **\[verified\]** status exists; **\[inferred\]** the missed-capture link. 3\. **Partial / delayed capture.** Partial invoices are supported; the uncaptured remainder is authorized-not-captured until separately invoiced. **\[verified\]** partial invoices; **\[inferred\]** remainder. 4\. **The authorization expires before capture.** Gateway auth holds have finite lifetimes; once the hold lapses the funds are released and capture is no longer collectible: \- **Stripe:** “If the authorization expires before you capture the funds, the funds are released and the payment status changes to canceled.” Card windows: Visa/MC/Amex/Discover \~7 days (CIT); Visa MIT \~5 days; default online card hold “typically … 7 days.” Klarna 28, PayPal 10(+10), Affirm 30, Afterpay 13\. **\[verified\]** (https://docs.stripe.com/payments/place-a-hold-on-a-payment-method) \- **Adyen:** for global card schemes Adyen “expires (pre-)authorization requests automatically after 28 days”; capturing after expiry can fail for Visa and incur processing-integrity fees. **\[verified\]** (https://docs.adyen.com/online-payments/adjust-authorisation, https://docs.adyen.com/online-payments/capture/failure-reasons/). Mastercard capture “can be successful even when the authorization has expired” — so late capture may succeed on one network and silently fail on another, and Magento’s amount\_paid and the gateway settlement diverge either way. **\[verified\]** behavior; **\[inferred\]** divergence.

Net failure: an order exists, amount\_authorized is set, no sales\_invoice row (or capture failed at the gateway), the auth hold lapses, goods ship against an order Magento treats as live, and the funds are never collected. **\[inferred\]** composite.

### **The signals, and where each comes from**

**Was this order only authorized, never captured? (per-order ground truth)** \- sales\_payment\_transaction.txn\_type — constants on Magento\\Sales\\Api\\Data\\TransactionInterface: TYPE\_AUTH='authorization', TYPE\_CAPTURE='capture', TYPE\_VOID='void', TYPE\_REFUND='refund', TYPE\_ORDER='order', TYPE\_PAYMENT='payment'. **\[verified\]** (https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/Sales/Api/Data/TransactionInterface.php). An order with an authorization row and no child capture (linked by parent\_txn\_id/parent\_id) is authorized-not-captured. **\[verified\]** (Transaction.php). Read-only SELECT. \- sales\_order\_payment: amount\_authorized \> 0 with amount\_paid NULL/0. **\[inferred\]** exact column names (standard sales schema; getters confirmed in the payment model). Read-only. \- sales\_invoice: absence of any row for the order\_id confirms no capture path triggered. Read-only.

**Is the auth still alive, or has the gateway released it?** \- Gateway-side: Stripe charge.payment\_method\_details.card.capture\_before; PaymentIntent status \= requires\_capture; Adyen PSP reference state \+ 28-day window. **\[verified\]**. Derived in Magento by aging the authorization transaction’s created\_at (on the Transaction model, **\[verified\]**) against the network’s validity window (lookup table from gateway docs, **\[verified\]**). Read-only gateway GET / report export.

**Is the order stuck in a state that suppresses capture?** \- sales\_order.state/status \= Pending/Payment Review/Suspected Fraud/Processing without a matching invoice. **\[verified\]** statuses exist. Read-only.

**Reconciliation — does collected revenue match settled money?** \- Magento: sum sales\_invoice.grand\_total / sales\_order\_payment.base\_amount\_paid over a period. **\[inferred\]** column usage. \- Gateway: settlement/payout export — Stripe Payouts, Adyen Settlement detail, or Adobe **Payment Services Payouts report** and **Order Payment Status report**. **\[verified\]** reports exist (https://experienceleague.adobe.com/docs/commerce-merchant-services/payment-services/reporting/payouts.html; https://experienceleague.adobe.com/en/docs/commerce/payment-services/financial-reports/order-payment-status). The delta (Magento-authorized minus gateway-captured) is the leaked revenue. Read-only.

### **Where the manual correlation sits today**

1. **Admin order grid / order view** — find orders in Processing/Payment Review with no invoice.

2. **sales\_payment\_transaction \+ sales\_order\_payment (DB)** — confirm an authorization row with no capture child and amount\_paid 0\.

3. **sales\_invoice (DB)** — confirm no invoice row exists.

4. **Payment gateway dashboard / settlement report** — is the PaymentIntent still requires\_capture, already canceled/expired, or captured-but-not-reflected?

5. **Gateway payout export vs Magento revenue export** — manual spreadsheet join on order/txn reference to size the gap.

**5 disconnected surfaces** (Admin UI, two Magento tables queried separately, gateway API/dashboard, settlement export). No system joins “Magento says authorized” → “gateway says hold expired” → “no invoice exists.” The join — order ref ↔ txn\_id ↔ PSP reference ↔ payout line — lives in the engineer’s head and a spreadsheet.

### **The automated collapse**

Provado reads sales\_payment\_transaction, sales\_order\_payment, sales\_invoice, and sales\_order.state/status continuously and read-only. It learns the normal lag between an authorization row’s created\_at and its capture child appearing (per method, per store) and fires on the contradiction: an authorization whose age has crossed the network-specific validity window (Stripe \~7d card / Adyen \~28d card) with no capture child, no sales\_invoice row, and an order still in a shippable state — i.e. an auth that will or did expire uncaptured. It cross-checks the gateway settlement/payout export to confirm the money never landed and quantifies the leak as amount\_authorized − base\_amount\_paid. Every alert is stamped against the deploy/config timeline (\#5), so a rise in uncaptured-aging is attributed to a flipped Payment Action or a broken auto-invoice cron. All reads — no captures.

### **Why it’s a real gap**

* **New Relic APM:** an authorized-not-captured order is a perfectly healthy, fast, error-free request; APM has no concept of txn\_type, invoice existence, or auth-window aging. Blind.

* **Noibu:** the order succeeded in the browser; nothing to capture client-side. Blind.

* **Adobe native (partial coverage):** the Order Payment Status and Payouts reports exist and *can* surface mismatches **\[verified\]**, but they are static reports a human must pull; there is no continuous “this auth is aging toward expiry, no invoice, still shippable” alert, and they don’t join Magento transaction state to the gateway hold lifecycle automatically. This is the most-covered of the modes — be honest that gateway dashboards \+ paid extensions partially cover the capture side — so the durable edge is the continuous auth-aging \+ reconciliation join, not raw detection.

* **Monte Carlo / Acceldata / Bigeye:** sit on warehouse copies, not the live sales\_payment\_transaction/sales\_invoice tables, and have no model of the authorize→invoice→capture mechanic or gateway auth expiry. Blind to root cause.

---

## **8\. Cross-system sync stoppage / partial sync (ERP / feed)**

**Scope flag:** this mode is **ERP/integration-conditional** — a merchant with no ERP/PIM/feed has no inbound async-bulk sync and likely no outbound order eventing to fail. The discipline is to stay inside Commerce-owned surfaces and not drift into monitoring the ERP’s internals or generic message-broker/IT-ops health, which is scope creep into a different product and a different buyer.

### **Mechanics**

Data pushed between an external ERP/PIM/feed and Adobe Commerce stops or arrives partial, and the store keeps serving old data with no surfaced error. Three integration paths fail silently in different places.

**1\. Async / Bulk REST API.** Inbound writes commonly use the async endpoints. An async request returns immediately with a bulk\_uuid and status: accepted (“Reserved for future use. Currently, the value is always accepted”) — acceptance is **not** success. **\[verified\]** (https://developer.adobe.com/commerce/webapi/rest/use-rest/asynchronous-web-endpoints). The message is queued and processed later by the consumer async.operations.all. **\[verified\]**. The real outcome lands in magento\_operation (operations) and magento\_bulk (the bulk record). **\[verified\]** (https://developer.adobe.com/commerce/php/development/components/message-queues/bulk-operations; tables referenced in https://github.com/magento/magento2/issues/29718). Per-operation status enum: 1\=Complete, 2\=Failed retriable, 3\=Failed not retriable, 4\=Open, 5\=Rejected. **\[verified\]** (https://developer.adobe.com/commerce/webapi/rest/use-rest/operation-status-endpoints/). Overall bulk status: NOT\_STARTED(0)/IN\_PROGRESS(1)/FINISHED\_SUCCESSFULLY(2)/FINISHED\_WITH\_FAILURE(3). **\[verified\]**. **Failure mode:** the ERP gets HTTP 202 \+ accepted, marks the push done, and walks away; if the consumer dies, the queue backs up, or operations land at 3/2, the store silently keeps old data. Adobe even documents a defect where bulk status mis-reports FINISHED\_WITH\_ERRORS while still IN\_PROGRESS. **\[verified\]** (https://github.com/magento/magento2/issues/36911)

**2\. Adobe I/O Events / App Builder (outbound, e.g. order → ERP).** Commerce eventing stages events in event\_data, then a cron publishes them in batches. event\_data.status: 0\=Waiting, 1\=Successfully sent, 2\=Failed to send, 3\=Transmission in progress. **\[verified\]** (https://developer.adobe.com/commerce/extensibility/events/troubleshooting). **Failure A (Commerce side):** “Events are sent by crons. If the status of an event is still 0 … after a long period, then the crons are not configured correctly” — events sit Waiting forever, silently; status 2 failures surface only in the info column and system.log. **\[verified\]**. **Failure B (delivery side):** once handed to Adobe I/O Events, delivery is “at least once,” retried only for 429 / 5xx (except 505\) / 6xx; other codes are **not** retried; retries continue for 24 hours; “if all attempts fail, the event is dropped” while “the event registration remains Active.” There is **no traditional dead-letter queue** — dropped events are recoverable only from the **Journaling API for the past 7 days**. A registration whose webhook is down is marked Unstable/Disabled and stops receiving new deliveries. **\[verified\]** (https://developer.adobe.com/events/docs/support/faq). So an order event can be silently dropped at the edge while Commerce shows it “Successfully sent.”

**3\. Scheduled imports (legacy feed).** CSV/feed imports run on cron and can fail or import partial (partial rows, malformed file, store keeps old data). **\[inferred\]** (general import/cron model; exact import-history table not re-verified)

### **The signals, and where each comes from**

**Did an inbound async/bulk push actually succeed (not just get accepted)?** \- magento\_operation.status (1/2/3/4/5) \+ error\_code/result\_message; magento\_bulk for the bulk record. **\[verified\]**. Read-only SELECT. \- Read-only REST: GET /V1/bulk/:bulkUuid/status, /operation-status/:status, /detailed-status. **\[verified\]** \- Admin: **System \> Action Logs \> Bulk Actions** (“Your Bulk Operations” grid). **\[verified\]**

**Is the queue/consumer alive?** \- Consumer async.operations.all running state; queue backlog. **\[verified\]** consumer name. Read-only.

**Did an outbound Commerce event publish?** \- event\_data.status (0/1/2/3) \+ info. **\[verified\]**. Read-only SELECT. \- Admin: **System \> Events \> Events Status** grid. **\[verified\]**. var/log/system.log filtered to batch publishing. **\[verified\]**. bin/magento events:list for subscription health. **\[verified\]**. Read-only.

**Was the event actually delivered to the ERP (edge)?** \- Adobe I/O Events registration state (Active/Unstable/Disabled); missed events retrievable only via **Journaling API, 7-day window**; x-adobe-retry-count header indicates retry depth. **\[verified\]**. Read-only.

### **Where the manual correlation sits today**

1. **ERP-side logs** — confirm the ERP believes it sent (got HTTP 202 accepted).

2. **Admin \> Action Logs \> Bulk Actions** — find the bulk and its operation outcomes.

3. **DB magento\_operation/magento\_bulk** — read status (2/3 \= failed) and result\_message/error\_code.

4. **Queue/consumer check** — is async.operations.all running, is the backlog draining?

5. **Admin \> Events \> Events Status** and **DB event\_data** — for outbound, read status 0/1/2/3 and info.

6. **var/log/system.log** — find the actual publish error text.

7. **Adobe Developer Console / I/O Events \+ Journaling API** — was the registration Unstable/Disabled, were events dropped after 24h, what’s in the 7-day journal?

8. **Deploy/config history** — a deploy that killed a consumer, cron mis-config, expired credentials (403), or changed Event Provider Instance ID.

Up to **8 disconnected surfaces** spanning the ERP, two Admin grids, two raw SQL tables, a queue/consumer, a log file, and the Developer Console. The join — “ERP sent → Commerce accepted → operation actually completed → (for orders) event published → event actually delivered” — lives only in the engineer’s head, and the asynchronous boundaries (202 accepted, status 0/Waiting, edge drop after 24h) are exactly where the chain breaks silently.

### **The automated collapse**

Provado reads each link continuously and read-only and learns the normal cadence of each integration (typical daily volume of Complete operations, typical event\_data 0→1 transition time, normal lag between an ERP push and a Complete). It fires on the contradiction across the boundary: ERP reports a push (or order volume implies events should exist) but magento\_operation shows status 2/3 or no matching Complete; or event\_data rows pile up at status 0 (cron not running) or flip to 2 (publish failed); or the I/O Events registration goes Unstable/events age out of the 7-day journal undelivered. Because acceptance (accepted, HTTP 202\) is explicitly *not* success, Provado keys on the *terminal* state, not the receipt. It stamps onset against the deploy/config timeline (a deploy that killed the consumer, a cron mis-config, expired Developer Console credentials, a changed Provider Instance ID), so the alert reads “inbound price sync stopped completing after the 14:00 deploy; 240 operations stuck at status 4/open.” All reads.

### **Why it’s a real gap**

* **Adobe native:** the signals exist (Bulk Actions grid, Events Status grid, magento\_operation, event\_data) but they are passive, siloed grids with no baseline, no cross-boundary join, and no alerting — and the most dangerous states are the quiet ones (status 4/Open queued forever, 0/Waiting cron down, an edge drop after 24h that leaves Commerce showing “Successfully sent”). Nobody watches the grid at 2am.

* **New Relic APM:** an async 202 accepted is a fast, successful HTTP transaction; a stalled consumer is *absence* of work, not an error; an event dropped at the Adobe I/O edge happens outside the monitored PHP process entirely. Sees green.

* **Noibu:** browser-side only; a backend sync failure produces no client-side error — the storefront serves stale-but-valid pages.

* **Monte Carlo / Acceldata / Bigeye:** can eventually flag a stale inventory/orders table *in the warehouse*, but only after the load, with no visibility into magento\_operation/event\_data/the consumer/the I/O Events registration, and no ability to attribute the stall to a Commerce deploy or a dropped event. They detect the shadow on the wall, not the broken pipe.

---

## **9\. Good bot blocked at edge / WAF after a rule change**

### **Mechanics**

Adobe Commerce on cloud infrastructure (ACC) sits behind Fastly as its CDN and WAF; “Powered by Fastly, the web application firewall (WAF) service for Adobe Commerce on cloud infrastructure detects, logs, and blocks malicious request traffic.” **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-on-cloud/user-guide/cdn/fastly-waf-service). Origin cloaking forces the path “Fastly \> Load Balancer \> Adobe Commerce” so all traffic is inspected at the edge — there is no bypass. **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-on-cloud/user-guide/cdn/fastly)

The merchant does NOT operate the Fastly service: “Adobe Commerce on cloud infrastructure projects are not given a dedicated Fastly account. The Fastly service is managed in a centralized account registered to Adobe, and the management dashboard is only accessible to the Cloud Support team.” **\[verified\]** (same). The merchant influences edge behavior only indirectly — via the Fastly CDN module, custom VCL snippets, ACLs/allowlists, and the per-environment Fastly API token/service ID in the Admin. The base WAF ruleset is “configured and maintained by Fastly” and “Fastly can add rules … after the WAF service is enabled” automatically. **\[verified\]**. So a rule change that begins blocking good traffic can originate from (a) an automated Fastly ruleset update the merchant did not author, or (b) a merchant-authored VCL/ACL/rate-limit change.

When a request is blocked, “the requestor sees a default 403 Forbidden error page that includes a reference ID.” **\[verified\]**. The symptom signature is a 403 at the edge, not an origin error.

Sub-variants: \- **AI shopping crawlers turned away.** A WAF/bot rule or VCL ACL that matches automated user-agents (or treats high-volume single-UA traffic as abusive) returns 403 to GPTBot, ClaudeBot, PerplexityBot, etc. The catalog is then never ingested by the LLM, so the storefront silently disappears from AI-mediated discovery. The base ACC WAF explicitly “does not support … bot mitigation” out of the box and points merchants to ACLs or a third-party service **\[verified\]** — so bot-blocking here is typically merchant-added VCL/ACL or a layered bot manager, exactly the surface most prone to misconfiguration. \- **Legit human shoppers false-positived.** A ModSecurity/OWASP rule (or an auto-deployed rule) matches a benign request (a search query, a long URL, an apostrophe reading as SQLi) and returns 403\. Adobe frames this as expected: “If you find that the WAF is blocking legitimate requests, these are often false positives.” **\[verified\]**. Sessions lost with no client-side JS error and no origin log line. \- **Rate-limit threshold crossed.** Rate limiting is not part of the base WAF and is implemented via the module/VCL; a lowered threshold returns 429/403 to bursty-but-legitimate traffic. **\[verified\]** (Limitations section; https://github.com/fastly/fastly-magento2/blob/master/Documentation/Guides/RATE-LIMITING.md)

**robots.txt vs an edge block are different layers.** robots.txt is a voluntary directive a well-behaved crawler reads and obeys; an edge 403 is an involuntary block the request never gets past. A merchant can have a permissive robots.txt and still block the same bot at the WAF — and the two are managed by different people in different surfaces, so they routinely disagree. **\[verified\]** (the operators’ own crawler docs below)

Published official user-agent strings (load-bearing for any block/allow rule): \- **GPTBot (OpenAI):** token GPTBot; UA …compatible; GPTBot/1.1; \+https://openai.com/gptbot; IPs at openai.com/gptbot.json. Also OAI-SearchBot, ChatGPT-User. **\[verified\]** (https://platform.openai.com/docs/bots) \- **ClaudeBot (Anthropic):** token ClaudeBot; UA …compatible; ClaudeBot/1.0; \+claudebot@anthropic.com; IPs at https://claude.com/crawling/bots.json. Also Claude-User, Claude-SearchBot. **\[verified\]** (https://support.claude.com/en/articles/8896518) \- **PerplexityBot:** token PerplexityBot; UA …compatible; PerplexityBot/1.0; \+https://perplexity.ai/perplexitybot; IPs at perplexity.com/perplexitybot.json. Also Perplexity-User. **\[verified\]** (https://docs.perplexity.ai/docs/resources/perplexity-crawlers) \- **Google-Extended:** NOT a distinct HTTP user-agent — it is a robots.txt control token only; crawling uses existing Googlebot UAs. You cannot block (or detect a block of) Google-Extended by inspecting UA strings; it only ever appears as a robots.txt directive. **\[verified\]** (https://developers.google.com/crawling/docs/crawlers-fetchers/google-common-crawlers)

**The emerging, unowned layer:** per-bot access rates and per-segment block rates at the edge are not watched by any in-band tool. There is no native ACC dashboard that says “GPTBot’s 403 rate went from 0% to 100% at 14:02”; the WAF dashboard is Adobe-internal, and the merchant sees only an aggregate 403 page and a reference ID handed to a support ticket. **\[verified\]**

### **The signals, and where each comes from**

**Is the edge returning 403/429, and at what rate?** \- Fastly Real-Time Analytics API (1-second granularity): status\_403, status\_4xx, status\_429. Endpoint GET /v1/channel/\<service\_id\>/ts/\<timestamp\> and the historical Stats API GET /stats. **\[verified\]** (https://www.fastly.com/documentation/reference/api/metrics-stats/realtime/; https://docs.fastly.com/api/stats.html). Read-only. \- ACC-side proxy via New Relic: Agent\_response \= WAF response code (“200 means good and 406 means blocked”); sigsci tags identify which WAF tag matched. This is the only natively documented WAF-decision telemetry exposed to the merchant, and it lives in New Relic, not the Commerce Admin. **\[verified\]**

**Which requests/user-agents are being blocked?** \- Fastly Real-Time Log Streaming: per-request log lines (UA, URL, status, matched WAF action). On ACC Pro, “Fastly CDN and WAF logs” are reviewable via New Relic Logs. Caveat: ACC limits customer log-endpoint configuration as an unsupported standard feature, so raw per-request edge logs are not freely self-served by every tier. **\[verified\]** (https://www.fastly.com/documentation/guides/integrations/streaming-logs/…; fastly overview/limitations) \- The 403 error-page reference ID, pasted into an Adobe Support ticket to identify the blocking rule. **\[verified\]**

**Was the request a legitimate bot or a spoof?** \- Reverse-verify the source IP against the operator-published JSON ranges (openai.com/gptbot.json, claude.com/crawling/bots.json, perplexity.com/perplexitybot.json). UA alone is spoofable; the operators direct verification by IP. **\[verified\]**

**What changed at the edge, and when?** \- Merchant-controllable: custom VCL snippets, ACL/allowlist entries, Fastly module version (set from Admin / Fastly API). Plus auto-deployed Fastly ruleset updates the merchant did not author and has no in-band changelog for. **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-on-cloud/user-guide/cdn/custom-vcl-snippets/fastly-vcl-custom-snippets; fastly-waf-service)

### **Where the manual correlation sits today**

1. **Analytics / referral data** — someone notices AI-crawler hits or AI-referral sessions fell. No cause shown.

2. **robots.txt** — engineer confirms the bot is allowed there, which wrongly clears the edge as a suspect.

3. **New Relic** — looks at throughput; sees Agent\_response\=406 or sigsci tags only if they know to query them; otherwise nothing, because a blocked request never hit origin.

4. **Fastly Real-Time / Stats API** — pulls status\_403/status\_4xx (if they have the token and the endpoint).

5. **Fastly real-time logs (via New Relic Logs on Pro)** — greps for the blocked UA to confirm GPTBot/ClaudeBot is getting 403\.

6. **Operator IP JSON** — verifies the blocked source is the real bot, not a spoof.

7. **VCL/ACL config \+ Adobe Support ticket** — pastes the 403 reference ID into a ticket because the base WAF rule is Adobe/Fastly-owned and not self-editable.

**7 surfaces**, three of which the merchant cannot directly own (Fastly dashboard, the WAF ruleset, the support-ticket reference-ID lookup). The join — “AI traffic dropped” ⇄ “edge 403 rate for that UA spiked” ⇄ “this VCL/rule change at this timestamp” — exists only in the engineer’s head, and only if an engineer thought to look at the edge at all.

### **The automated collapse**

Provado reads, continuously and read-only: Fastly Real-Time/Stats API (status\_403/4xx/429, hits/misses), Fastly real-time logs where available, the Agent\_response/sigsci fields in New Relic, robots.txt, the live VCL/ACL config and module version, and the operator bot-IP JSON lists. It learns per-segment normal — the baseline 403 rate for verified GPTBot/ClaudeBot/PerplexityBot IPs, and for human-shopper segments separately. It fires on the contradiction — “robots.txt allows ClaudeBot AND verified ClaudeBot IPs are now seeing a 100% 403 rate,” or “the human-shopper 403 rate stepped from baseline to elevated at 14:02” — and stamps the onset against the deploy/config timeline (a VCL snippet change, an ACL edit, a module update, or the timestamp of an auto-deployed Fastly ruleset shift). All telemetry read-only; nothing is written back to the edge.

### **Why it’s a real gap**

* **New Relic APM:** a request blocked at the edge never reaches origin, so the core APM view shows an *absence* of traffic, not an error — nothing to alert on. The Agent\_response/sigsci fields exist but only if explicitly queried. **\[verified\]**

* **Noibu / browser tools:** a blocked bot has no browser session to instrument; a human who gets a hard 403 often never loads the JS tag, so the lost session is invisible to client-side monitoring. **\[inferred\]**

* **Adobe native:** surfaces only the aggregate 403 page \+ a reference ID, and routes false-positive resolution through a support ticket; the WAF dashboard is Adobe-internal. No native per-bot / per-segment edge-block dashboard. **\[verified\]**

* **Monte Carlo / Acceldata / Bigeye:** an edge 403 is upstream of any pipeline; it never produces a warehouse row to be anomalous about. Structurally blind. **\[inferred\]**

* The edge-block layer is largely **unwatched in-band**: nobody natively trends per-bot or per-segment block rates for ACC merchants, and robots.txt and the WAF can silently disagree. **\[verified\]** that the WAF dashboard is Adobe-only and base WAF excludes bot mitigation; **\[hypothesis\]** that no incumbent product trends per-bot edge-block rates for ACC merchants.

---

## **10\. Per-region tax / payment config break (one market only)**

### **Mechanics**

Adobe Commerce runs many storefronts off one database using a four-level scope hierarchy: **default (global) → website → store → store view**; settings inherit downward and can be overridden lower. **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-admin/config/scope-change). A cross-border merchant typically maps one website per country/region (own base currency, allowed countries, payment config) and one store view per language. **\[verified\]**

Almost every config value is stored per scope in core\_config\_data: columns config\_id, scope (varchar(8), default default), scope\_id (int, default 0), path, value, updated\_at (auto-stamped on update), with a **unique key on (scope, scope\_id, path)**. **\[verified\]** (https://github.com/magento/magento2/blob/2.4/app/code/Magento/Config/etc/db\_schema.xml). So one config path can hold a different value for scope='default' vs scope='websites' scope\_id=2; a change at one scope\_id affects exactly one market and leaves every other website untouched. updated\_at is the load-bearing read-only timestamp for this whole trace. **\[verified\]**

Sub-variants: 1\. **Payment method disabled / mis-scoped for one website.** Payment methods are configured at the **website** level; every core method’s Enabled, New Order Status, Payment from Applicable/Specific Countries, and Merchant Country fields are Website scope. **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-admin/config/sales/payment-methods/payment-methods). An admin (or a deploy) flips payment/\<method\>/active to 0, or narrows “Payment from Specific Countries,” for one website’s scope\_id. At runtime the gate is canUseForCountry(), reading allowspecific/specificcountry against the quote country. **\[verified\]** (https://github.com/magento/magento2/blob/2.4/app/code/Magento/Payment/Model/Method/Adapter.php; behavior in https://github.com/magento/magento2/issues/10234) 2\. **Per-region tax break.** Tax data lives in tax\_calculation\_rate (tax\_country\_id varchar(2), tax\_region\_id, tax\_postcode, rate), tax\_calculation\_rule (priority, position, calculate\_subtotal), and the join tax\_calculation. **\[verified\]** (https://github.com/magento/magento2/blob/2.4/app/code/Magento/Tax/etc/db\_schema.xml). A rate row scoped to one tax\_country\_id is edited/deleted, a rule’s priority/class mapping changes, or the config-layer **Default Country / State / Post Code** or **Tax Calculation Based On** is set wrong for one website’s scope. **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-admin/stores-sales/site-store/taxes/tax-settings-general). Tax computes to zero, errors, or a wrong amount for that one region. The tax tables have no updated\_at, so the config-layer change is the timestamped artifact, not the rate-table edit. **\[verified\]** 3\. **Cross-border-trade / price-consistency flag** toggled for one website with catalog prices not set to include-tax shifts displayed/charged totals for that scope only. **\[verified\]**

**The deploy vector:** on ACC Cloud, scope-specific config is dumped to app/etc/config.php (bin/magento app:config:dump; only website/store/store-view scopes) and re-applied on every deploy via magento app:config:import. **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-operations/configuration-guide/deployment/technical-details). So a one-line diff for one website’s scope\_id, merged and deployed, silently breaks one market — reproducibly on every subsequent deploy. **\[inferred\]**

### **The signals, and where each comes from**

**Did a scoped config value change, and for which market?** \- core\_config\_data: path, scope, scope\_id, value, updated\_at — the unique key means each market’s value is one row; updated\_at dates the change. For payments: payment/\<method\>/active, payment/\<method\>/allowspecific, payment/\<method\>/specificcountry. **\[verified\]** table; **\[inferred\]** exact path strings. Read-only SELECT. \- app/etc/config.php diff in git history (Cloud) — the deploy-time artifact showing which scope\_id block changed. **\[verified\]** mechanism. \- Scope identity: store\_website and store tables map scope\_id → website/store-view code. **\[verified\]**

**Did the tax rule/rate change?** \- tax\_calculation\_rate.rate/.tax\_country\_id, tax\_calculation\_rule.priority, tax\_calculation joins. No native change timestamp on these tables. **\[verified\]**. Read-only SELECT.

**Did checkout actually fail there, and only there?** \- Order/quote rows segmented by store\_id and shipping-address country — the success side. **\[inferred\]** (standard sales schema; store\_id is the cross-scope key). Read-only. \- tax\_order\_aggregated\_created/\_updated (store\_id, percent, orders\_count, tax\_base\_amount\_sum) — a native per-store tax aggregation that can show a region’s tax base collapsing to a different percent bucket or to zero. **\[verified\]**. Read-only.

### **Where the manual correlation sits today**

1. **Aggregate analytics (GA4 / exec dashboard)** — total conversion looks normal; nobody localizes.

2. **Customer-complaint channel** — “I can’t pay” / “checkout errors” from one country; first human signal, days late.

3. **Browser-error tool (Noibu)** — may show a JS/checkout error but can’t attribute it to a website-scoped config row or tax table.

4. **APM (New Relic)** — backend “healthy”; no exception, since a disabled method or zero tax is correct behavior.

5. **Admin / DB** — open Stores \> Configuration, switch the Store View chooser to the suspected scope, hunt for a cleared “Use system value” checkbox, and/or SELECT against core\_config\_data and the tax tables, mapping scope\_id → market via store\_website/store.

**\~5 surfaces.** That one region is a small share of total GMV is precisely why steps 1–2 dominate (the dip hides in the average), and the scope-resolution work in step 5 is entirely manual and tribal.

### **The automated collapse**

Provado reads the four read-only signal families continuously and **segments checkout success by store\_id/website/store-view and shipping-country** rather than in aggregate — so a localized dip surfaces immediately instead of being averaged away. It learns each market’s normal payment-method mix and tax-base profile (per store\_id), then fires on the contradiction: market X’s begin-checkout-to-order ratio (or tax\_order\_aggregated percent bucket) breaks its own baseline while every other website holds. It stamps that break against the config/deploy timeline — the core\_config\_data.updated\_at for the matching path/scope\_id, and the app/etc/config.php diff — turning “conversion is down somewhere” into “payment//active went 1→0 for website\_id=2 (DE) at 14:03 in deploy abc123; orders shipping to DE dropped to zero at 14:05.” All reads.

### **Why it’s a real gap**

* **New Relic APM:** a website-scoped disabled method or a zero/mis-scoped tax is correct code executing correct config — no exception, no slow transaction. Blind to “configured wrong for one scope.” **\[inferred\]**

* **Noibu:** can catch a front-end checkout error but has no view of core\_config\_data, scope\_id, or the tax tables. **\[inferred\]**

* **Adobe native:** the admin *exposes* scope (Store View chooser, “Use system value”) but does nothing to *detect* an anomalous scoped override or correlate it to a regional dip; tax\_order\_aggregated is reporting, not alerting. **\[verified\]** tables exist.

* **Monte Carlo / Acceldata / Bigeye:** watch rows *after* they land; a correctly-recorded order from a region with falling volume, or an absent order, is not a schema/freshness/volume anomaly they can attribute to a Commerce scope change — the cause is upstream of the warehouse. **\[inferred\]**

---

## **11\. Measurement / consent / tag breakage corrupting decision data**

**Honest framing up front:** this class is largely *solved above the Commerce layer* by tag-governance / synthetic-monitoring tools (ObservePoint-class) that crawl pages, fire events, and audit tag/consent state directly. Provado has **no edge** on general tag governance. Provado’s only durable, defensible slice is the **operational-cause slice**: tying a measurement break to a specific Commerce deploy/config change on Commerce-owned pages, using the order ledger as the truth anchor those tools lack. Scope it narrowly or it becomes a product Provado shouldn’t build.

### **Mechanics**

The store works; the **data about the store** is wrong, and the merchant decides on corrupt numbers. GA4 ecommerce is event-driven: the page pushes purchase (and begin\_checkout, add\_to\_cart, …) to the dataLayer/gtag, with purchase carrying transaction\_id (required), value, currency, items. **\[verified\]** (https://developers.google.com/analytics/devguides/collection/ga4/set-up-ecommerce; https://developers.google.com/tag-platform/tag-manager/datalayer)

Sub-variants: 1\. **Dropped purchase event → under-counting.** A Commerce deploy changes the confirmation template/route, renames the SPA confirmation component, or reorders scripts so the purchase push never fires (or fires before items is populated). GA4 revenue/conversion silently falls while real orders are unchanged. **\[inferred\]** mechanism; event-on-confirmation model **\[verified\]**. 2\. **Duplicated tag / double-fire → over-counting.** GA4 dedupes **only** when transaction\_id is identical; a different (or missing) transaction\_id is counted as a separate purchase. So a deploy that regenerates or drops transaction\_id inflates revenue. **\[verified\]** (set-up-ecommerce; https://support.google.com/analytics/answer/12313109) 3\. **Malformed event → revenue mis-stated.** value/currency/items missing or wrong type after a template change; the event records with wrong revenue. **\[inferred\]**; required-parameter model **\[verified\]**. 4\. **Consent-default / CMP change → silent suppression.** Under **Consent Mode v2**, tags carry consent checks keyed to analytics\_storage and ad\_storage (granted/denied). In advanced mode, defaults may be denied unless configured; while denied, Google tags send measurements *without cookies* (pings) and GA4 *models* the gap. **\[verified\]** (https://developers.google.com/tag-platform/security/concepts/consent-mode). A CMP/deploy change that flips the **default** consent state to denied, or breaks the consent-state ping, suppresses or models collection with no visible front-end fault. Modeling has hard thresholds (behavioral modeling needs ≥1,000 events/day with analytics\_storage='denied' for ≥7 days *and* ≥1,000 daily granted users for 7 of 28 days). Below threshold the gap is simply lost — so the distortion is non-linear and market-dependent. **\[verified\]** thresholds (https://support.google.com/analytics/answer/11161109); **\[inferred\]** consequence.

### **The signals, and where each comes from**

**Is the data corrupt — under/over/mis-counted?** (covered by ObservePoint-class tools; Provado consumes, doesn’t own) \- GA4 reporting/Data API metrics — *Ecommerce purchases*, *Total revenue*, key-event counts diverging from the source of truth. **\[verified\]** metrics. \- dataLayer / network purchase payload on the live confirmation page — presence, transaction\_id, value, currency, items. **\[verified\]** model. \- Consent-mode HTTP params on outgoing pings: gcs (encodes ad\_storage/analytics\_storage), gcd, dma\_cps. **\[verified\]**

**Is GA4 the truth, or is the truth the order ledger?** (Provado’s anchor — Commerce-side) \- sales\_order rows by store\_id/created\_at — the **ground-truth count and revenue** the merchant booked, independent of any tag. **\[inferred\]** (standard sales schema). The contradiction Provado fires on is *GA4 purchase count/revenue vs real orders diverging.* Read-only SELECT.

**Which Commerce change broke the tag/dataLayer?** (Provado’s durable slice) \- Deploy artifacts on ACC Cloud: git diff of theme templates (.phtml), layout XML, requirejs/script include, confirmation route, app/etc/config.php (re-applied each deploy via app:config:import). **\[verified\]** deploy mechanism. \- core\_config\_data \+ updated\_at for any consent/CSP/header-affecting config path that could block or gate the tag. **\[verified\]** table/timestamp.

### **Where the manual correlation sits today**

1. **GA4 / exec dashboard** — revenue or conversion steps up or down; looks like a real business change.

2. **Marketing / finance reconciliation** — someone notices GA4 revenue ≠ the order ledger / processor settlement. First evidence it’s measurement, not sales. Often weeks late.

3. **Tag-governance / Tag Assistant / GTM debug (ObservePoint-class)** — confirms the purchase tag is missing, doubled, malformed, or that gcs shows consent denied. Establishes *what* is broken.

4. **CMP / consent console** — did a default-consent or banner change flip analytics\_storage to denied?

5. **Commerce deploy history / repo** — bisect deploys/template diffs to find the change that dropped the dataLayer.push, reordered scripts, or toggled the consent/CSP block.

**\~5 surfaces.** Steps 3–4 are well-tooled and not Provado’s; the join from step 3/4 back to step 5 — *which Commerce deploy/config change caused it* — is manual bisection in the engineer’s head.

### **The automated collapse**

Provado continuously reads the order ledger (sales\_order by store\_id) as ground truth and learns the normal ratio of **real orders to reported GA4 purchases** (and the normal consent-gcs distribution). It fires on the contradiction — GA4 purchase count/revenue diverging from booked orders, or a step-change in denied-consent pings — and stamps it against the deploy/config timeline (template/layout/script git diff; core\_config\_data.updated\_at for a consent/CSP/header path; the app/etc/config.php import). Output: “GA4 purchases fell 40% vs orders starting at the 14:00 deploy that edited checkout/success.phtml; orders unchanged — measurement broke, not sales.” Read-only throughout. Provado does not replace ObservePoint-class tag governance and should not claim to detect arbitrary tag misconfiguration; its defensible signal is narrow — tying a measurement break to a Commerce deploy/config change using the order ledger as the truth anchor those tools lack.

### **Why it’s a real gap**

* **New Relic APM:** the page renders and responds 200; a missing or doubled dataLayer.push is not an error, latency, or throughput anomaly. Blind. **\[inferred\]**

* **Noibu:** a tag that doesn’t fire, fires twice with a fresh transaction\_id, or a consent default flip throws no JS error — nothing to catch. **\[inferred\]**; dedup/consent behavior **\[verified\]**.

* **Adobe native:** Commerce has no awareness of GA4 event integrity or consent-mode state; it serves the page and records the order. **\[inferred\]**

* **Monte Carlo / Acceldata / Bigeye:** the loss happens *before* the warehouse row exists — a purchase that never fired produces no row; a doubled one produces a plausible extra row. Warehouse observability cannot see an event never collected. **\[inferred\]**

* **ObservePoint-class tag governance (honest caveat):** these tools *do* cover the general case (crawling/synthetics confirming the tag fires, is not duplicated, and consent state is correct). Provado’s only edge is the causal tie-back to a specific Commerce deploy/config change using the order ledger as truth — which tag-governance tools, having no view of the Commerce repo or the booked-order ledger, cannot do. **\[inferred\]**

---

## **12\. Cache cacheability silently broken by a deploy**

**Honest framing:** unlike the silent-failure modes, the raw cache hit ratio **is** visible (Varnish varnishstat, Fastly Stats API, New Relic). The gap here is two specific things: (1) the **deploy → cacheability-flip → ratio-drop correlation** that no tool makes natively, and (2) the **“uncacheable is not an error” blindness** — the failure produces only HTTP 200s, so error/threshold alerting never trips. This is why the sortable map files it as fold-into-deploy-regression, not a standalone moat.

### **Mechanics**

ACC’s CDN is “a Varnish-based service that caches your site pages” (Fastly), fronting Magento’s Full Page Cache. **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-on-cloud/user-guide/cdn/fastly). Magento builds each page by merging layout XML from all applicable handles; the default handle applies to every page. **\[verified\]** (https://github.com/magento/magento2/issues/9041).

The load-bearing mechanism: a single block marked cacheable="false" makes the **whole page** uncacheable. Adobe’s developer doc: “The application disables page caching if at least one non-cacheable block is present in the layout,” and “Using cacheable="false" inside the default.xml file disables caching for all pages on the site.” **\[verified\]** (https://developer.adobe.com/commerce/php/development/cache/page/public-content). The mechanism is layout-level, not block-level hole-punching: Magento\\Framework\\View\\Layout::isCacheable searches the merged layout-update structure for any cacheable="false" and, if found, marks the page non-cacheable. **\[verified\]** (issue \#9041).

The blast-radius trap: if the non-cacheable block is added to the default handle — even referencing a container that does not exist on most pages, so the block never renders — isCacheable still sees the declaration in the merged layout and marks **every page** non-cacheable. “If it is added to the default handler it will make every page non-cacheable, even though this block is never executed … Full Page Cache is using the declaration of the layouts rather than actually executed blocks structure.” Even remove="true" does not fix it; only overriding the layout file does. **\[verified\]** (issue \#9041)

Sub-variants: \- **cacheable="false" in default.xml** (or a broad handle) by a third-party module or careless install → FPC collapses storefront-wide. **\[verified\]** \- **cacheable="false" scoped to one page-type handle** → that whole page type goes uncacheable; a broader-than-intended handle drags more page types in. **\[verified\]** \- **no-cache/no-store response headers from a controller.** A deploy adds $page-\>setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true) — Adobe’s documented method to control varnish/fastly/built-in caches — on the wrong route, making those responses uncacheable at Fastly with no XML change at all. **\[verified\]** (public-content doc)

The defining property: an uncacheable page is **not an error** — it returns HTTP 200, renders correctly, and is just slower. The only edge-visible difference is a cache MISS instead of HIT and a growing share of requests reaching origin.

**On the specific “95% → 10%” figure:** the *mechanism* by which one false block collapses a page-type’s (or the whole site’s) FPC hit ratio is verified (issue \#9041; public-content doc). The specific numbers 95% and 10% are illustrative — no Adobe/Fastly/Magento primary source states them. **\[hypothesis\]** — treat the magnitude as unverified; the directional collapse is verified.

### **The signals, and where each comes from**

**Is a given page being cached (HIT/MISS)?** \- X-Magento-Cache-Debug response header (developer mode): HIT/MISS, via curl \-I. **\[verified\]** (https://experienceleague.adobe.com/en/docs/commerce-on-cloud/user-guide/cdn/fastly-troubleshooting; public-content doc; Varnish verification https://experienceleague.adobe.com/en/docs/commerce-operations/configuration-guide/cache/config-varnish-final) \- Fastly X-Cache header (“HIT, HIT” / “MISS, MISS”); curl with Fastly-Debug:1 returns Fastly-Magento-VCL-Uploaded. **\[verified\]**

**What is the aggregate hit ratio, and is it dropping?** (VISIBLE — the gap is elsewhere) \- Varnish: varnishstat (cache\_hit / cache\_miss). **\[verified\]** \- Fastly: Stats API — hits, miss, plus origin\_offload (“ratio of bytes served … cached by Fastly … between 0 and 1”). **\[verified\]** (https://www.fastly.com/documentation/reference/api/metrics-stats/realtime/) \- New Relic: origin throughput and transaction latency rising as more requests fall through to origin. **\[verified\]** that New Relic is the ACC monitoring surface; **\[inferred\]** the throughput-up behavior.

**Which page/block/deploy made it uncacheable?** (the missing signal) \- Layout XML in the deployed codebase: search for cacheable="false" and which handle (default.xml vs a scoped handle). **\[verified\]** mechanism. \- Controller code returning Cache-Control: no-store/no-cache. **\[verified\]** \- The deploy/release timeline (which commit introduced the above). No primary tool joins this to the ratio drop — this join is the gap.

### **Where the manual correlation sits today**

1. **New Relic APM** — origin throughput and transaction time up, but **no error** (every response is 200). Looks like “the site is busy/slow,” not “the cache broke.”

2. **Fastly Stats API / Varnish varnishstat** — pull hit ratio, see it fell. This surface DOES show the drop.

3. **curl with X-Magento-Cache-Debug / X-Cache** — probe page types to find which now MISS.

4. **Layout XML grep** — hunt for the offending cacheable="false" and its handle (remembering \#9041: a non-rendered block in default can still be the culprit).

5. **Controller code review** — if no XML cause, look for a no-cache header set in a controller.

6. **Deploy/release log** — manually line up “ratio dropped at T” with “release X shipped at T.”

**6 surfaces.** The raw hit-ratio drop is visible at step 2 — so this is *partial* coverage. What no tool does is the join across 2 → 4/5 → 6: ratio-drop ⇄ specific block/header ⇄ specific deploy. That correlation lives in the engineer’s head, and only if someone first noticed slowness and guessed “cache” rather than “traffic spike” or “slow query.”

### **The automated collapse**

Provado reads continuously and read-only: Fastly hit ratio / origin\_offload / hits / miss, Varnish counters, New Relic origin throughput/latency, the deployed layout XML (presence and handle-scope of cacheable="false"), and controllers emitting no-cache/no-store. It learns normal — per-page-type baseline HIT ratio and origin offload — and fires on the contradiction incumbents miss: hit ratio for a page type (or sitewide) steps down while responses stay 200 (uncacheable, not erroring), stamped against the deploy/config timeline to name the release and the specific block/handle or header that flipped cacheability — including the \#9041 case where the offending block is declared in default and never even renders. Read-only throughout.

### **Why it’s a real gap**

* **The raw hit ratio is NOT the gap — it is visible** in Varnish, Fastly Stats API, and New Relic. **\[verified\]**. The gap is (1) the **deploy-to-block correlation** — no tool natively says “this release, via this cacheable="false" in this handle, dropped the ratio at this timestamp” — and (2) the **“uncacheable is not an error” blindness** — only 200s, so error/threshold alerting never trips.

* **New Relic APM:** shows higher origin throughput and latency with no error event; cannot tell “busy because cache broke” from “busy because of a traffic spike or a slow query,” and does not read layout XML or the deploy diff. **\[verified\]** monitoring surface; **\[inferred\]** behavior.

* **Noibu:** sees a slower page but reports no error (200 \+ correct render); zero visibility into FPC HIT/MISS or layout XML. **\[inferred\]**

* **Adobe native:** exposes the HIT/MISS headers and module config but no continuous “your cacheability changed on this deploy” monitor; the verification headers are manual curl probes. **\[verified\]**

* **Monte Carlo / Acceldata / Bigeye:** a collapsed FPC hit ratio never lands as a warehouse row — an edge/origin performance regression entirely outside their scope. **\[inferred\]**

---

## **Closing notes**

**Pattern across all twelve.** Every one of these is the same shape: a backend/edge state that is *correct-looking but wrong* (or an *absence* of an event), producing only a downstream revenue/ops symptom and no error. APM is blind because there is no error or slow transaction; Noibu is blind because the page renders fine; warehouse data-observability is blind because the distortion precedes the warehouse row or looks like a valid value. The work that is worth removing is the **manual cross-surface join** — 5 to 8 disconnected surfaces per failure, reconciled in an engineer’s head under incident pressure, after the damage window has run. Provado’s automated version is, in each case, a direct collapse of that join: read the liveness/backlog/progress (or state/effect/expiry) signals continuously, learn normal, fire on the exact contradiction, and stamp it against the deploy/config timeline (\#5 is the spine that makes this attribution possible for every other mode).

**Honesty about coverage tiers.** Not all twelve are equally clean gaps:

* **Cleanest (no incumbent reads the signal):** silent consumer death (\#1), inventory drift (\#2), indexer stagnation (\#4), silent order loss (\#6), promo-rule effect collapse (\#3), cross-system sync (\#8, ERP-conditional).

* **Partial incumbent coverage — the edge is the correlation/continuity, not raw detection:** payment-not-captured (\#7, gateway dashboards \+ paid extensions partly cover capture), cache cacheability (\#12, hit ratio is visible; the deploy-correlation is not), deploy-regression (\#5, New Relic has markers but no config/operational join).

* **Narrow or conditional slices:** edge/WAF bot block (\#9, emerging/unowned but partly support-ticket-mediated), per-region config break (\#10, conditional on cross-border selling), measurement/tag breakage (\#11, general case owned by ObservePoint-class tools — only the deploy-cause slice is Provado’s).

**The one thing none of this proves: willingness to pay.** Every trace above establishes that the signal is readable and that no in-band tool occupies the space. None of it establishes that a $50–250M Adobe Commerce merchant experiences the backend-causation gap as *pay-to-fix*. That is the load-bearing unvalidated assumption, and it resolves only by putting these specific failure stories in front of merchants who have felt the pain — not by more desk research.

**Primary sources** are cited inline at each claim. The principal ones: Adobe Experience League and Adobe Developer (developer.adobe.com/commerce), the magento/magento2 GitHub repo (source files and issues \#25862, \#36334, \#9041, \#29549, \#36911, \#29718), Adobe quality-patch notes (ACSD-51431, MDVA-43726, MDVA-43601, MDVA-31519), RabbitMQ, Fastly, Stripe, Adyen, Google (GA4 / Tag Platform / Consent Mode), and IHL Group for the inventory-distortion figure.