# SIM-QUEUE-001 — Order Sync Message Stuck / Consumer Death

## Status

Draft (documented from primary sources; not yet reproduced on lab).

## Purpose

Simulate the message-queue subsystem going **alive but doing nothing**: cron
spawns (or fails to spawn) consumers, the broker fills, and async work (order
sync, emails, inventory mass actions, async indexing) silently stops — with **no
crash, no exception, no error-rate spike**. The only evidence is *absence of
progress* and a *growing backlog*. `[verified]`

## Environment

### Magento lab

- Magento 2.4.9
- CentOS Stream 9
- Apache + PHP 8.3
- MariaDB
- **RabbitMQ** (AMQP backend under test)
- OpenSearch / Redis
- New Relic APM

## Test data

Async traffic to fill a queue: bulk product updates, async order operations, or
queued emails. Any topic drained by a cron-spawned consumer works; the example
target is order/async sync via `async.operations.all`.

## Fault injection

The unifying property of every sub-variant: the OS process table and APM see a
normally-exiting short-lived PHP process (or nothing), never a crash. `[verified]`
Sub-variants to inject:

1. **`cron_run => false`** in `app/etc/env.php` — the documented kill switch;
   `consumers_runner` no-ops, the broker fills, nothing errors. `[verified]`
2. **Stuck lock.** A consumer lock keyed on `md5($consumer->getName())` never
   releases (killed process / DB lock leak) → the runner believes a consumer is
   already running forever and never respawns. `[verified]` key.
3. **Poison / long-running message (MySQL DB queue variant).** A message that
   throws or never completes blocks progress; the row sits at `IN_PROGRESS (3)`
   until cleanup re-queues, repeated failure drives it to `ERROR (6)`. `[verified]` enum.
4. **Cron stalled** — whole cron wedged so `consumers_runner` never fires (see
   [SIM-CRON-001](README.md)). `[inferred]`

Reminder: with `only_spawn_when_message_available` (default true), "no consumer
running" is **normal** when the queue is empty — liveness must be correlated with
backlog before alarming. `[verified]`

## Reproduction steps

1. Generate async work so a queue has a known backlog.
2. Inject the fault (simplest: set `cron_consumers_runner/cron_run => false`, or
   hold the consumer lock).
3. Observe the backlog grow while ack/processing rate stays flat.
4. Capture broker + DB + cron + lock state (see signals below).

## Observed behavior

⏳ Expected (not yet reproduced on lab), per primary sources:

- RabbitMQ `messages_ready` grows; `consumers` may be 0 (disconnected) or > 0
  with `ack` rate 0 (alive-but-stalled). `[verified]`
- DB-queue variant: `queue_message_status` rows pile at `NEW (2)` not draining,
  or stuck at `IN_PROGRESS (3)` past `retry_inprogress_after`. `[verified]`
- No exception, no slow transaction, no error-rate spike — APM sees green. `[verified]`
- Downstream: async order/email/export not completing; bulk-action status stuck.
  `[inferred]`

## Retry behavior

⏳ Expected.

### Bad behavior

Nothing retries because nothing errored; the kill switch / stuck lock persists
across cron ticks until a human notices the backlog.

### Correct behavior

Detection correlates the three axes (liveness, backlog, progress), then a human
clears the kill switch / releases the lock / restarts the consumer. The hourly
`messagequeue_clean_outdated_locks` job exists to release stale locks. `[verified]`

## Stock impact

⏳ Expected — N/A directly, though a stalled inventory consumer is upstream of
[SIM-ERP-002](SIM-ERP-002-stale-inventory-oversell.md).

## Failure classification

Type:

```text
Silent liveness failure — alive but no progress (absence of an event)
```

Failure chain:

```text
consumers_runner kill switch / stuck lock / poison message / dead cron
→ consumer never spawns or stalls mid-message
→ broker backlog grows, ack rate flat
→ no crash, no exception, no APM error
→ async orders/emails/inventory silently stop
→ discovered only via downstream symptom, after the damage window
```

## Business impact

- Order confirmation emails unsent; async orders not finalized.
- Inventory mass actions / async indexing stall → stale storefront.
- Bulk operations appear stuck; customer-facing delays with no alert.

## Metrics/signals needed

Three orthogonal axes a human reconciles by hand — the core of the detection:

### Backlog (is work piling up?)

- RabbitMQ `GET /api/queues/{vhost}/{qname}` → `messages_ready`,
  `messages_unacknowledged`. `[verified]`
- MySQL DB queue: `queue_message_status` counts by status (esp. `NEW = 2` growing).
  `[verified]`

### Liveness (alive but not working?)

- RabbitMQ same payload → `consumers`, `consumer_capacity`,
  `message_stats.deliver_get_details.rate`, `ack_details.rate`. `consumers > 0` +
  ack 0 + ready > 0 = alive-but-stalled; `consumers == 0` = disconnected. `[verified]`
- `bin/magento queue:consumers:list` (what should run) vs `ps` (what runs). `[verified]`
- Lock state: MySQL lock for the `md5(consumerName)` keys — held with no live
  process = stuck-lock death. `[inferred]` (keys verified).

### Progress (is it advancing?)

- RabbitMQ: deltas of `message_stats.ack` / `deliver_get` between polls. Flat +
  nonzero backlog = no progress. `[verified]`
- DB queue: `queue_message_status.updated_at` not advancing; rows stuck at 3 past
  `retry_inprogress_after`. `[verified]`

### Upstream cron + config

- `cron_schedule` rows for `consumers_runner` (status/scheduled_at/executed_at). `[verified]`
- `app/etc/env.php`: `cron_consumers_runner/cron_run`, `/max_messages`,
  `queue/only_spawn_when_message_available`. `[verified]`

### Provado-specific derived metric

```text
Queue Stall (backlog up, progress zero)
```

Suggested rule:

```text
Backlog above learned baseline
AND ack/progress = 0 over N polls
QUALIFIED BY whether consumers_runner is succeeding in cron_schedule
   (distinguishes "cron dead" from "consumer dead")
AND whether a lock is held with no live process
   (distinguishes "stuck lock" from "legitimately idle empty queue")
THEN flag Queue Stall
```

Severity:

```text
High
```

Reason:

```text
Async orders / emails / inventory silently stop; no error fires.
```

## Detection query ideas

### RabbitMQ Management API (read-only HTTP GET)

```bash
curl -s -u guest:guest http://127.0.0.1:15672/api/queues/%2f/async.operations.all \
  | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d.get("messages_ready"),d.get("consumers"),d.get("message_stats",{}).get("ack_details",{}).get("rate"))'
```

### Magento MySQL (DB-queue backend)

```sql
SELECT status, COUNT(*) FROM queue_message_status GROUP BY status;
-- rows stuck IN_PROGRESS (3) not advancing
SELECT * FROM queue_message_status WHERE status = 3 ORDER BY updated_at LIMIT 20;
```

### CLI + process + cron

```bash
bin/magento queue:consumers:list
ps aux | grep "queue:consumers:start"
# cron_schedule for the runner
mysql -e "SELECT status,COUNT(*) FROM cron_schedule WHERE job_code='consumers_runner' GROUP BY status;"
```

## Mitigation pattern

- Alarm on the contradiction (backlog up + progress zero), never on liveness
  alone — empty-queue idle is normal under `only_spawn_when_message_available`.
- Distinguish cron-dead vs consumer-dead vs stuck-lock before paging.
- Keep `messagequeue_clean_outdated_locks` healthy to clear stale locks.

## Lab cleanup

```bash
# restore the kill switch if it was flipped
# (set cron_consumers_runner/cron_run => true in app/etc/env.php)
bin/magento queue:consumers:start async.operations.all --max-messages=200
# release any stale lock by letting messagequeue_clean_outdated_locks run
```

## Current result

Draft from Drive failure mode #1 (canonical re-verified trace). The
`cron_run => false` injection is the most deterministic first lab run; the
stuck-lock and DB-queue poison-message variants exercise the lock-state and
`queue_message_status` signals respectively. Related:
[SIM-CRON-001](README.md), [SIM-ERP-003](SIM-ERP-003-cross-system-sync-stoppage.md).
