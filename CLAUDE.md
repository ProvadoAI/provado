# Provado — working agreements

## Roadmap execution

- `docs/ROADMAP.md` is an **index**, not the work list itself. It points to per-version
  roadmap files under `docs/roadmaps/` (e.g. `docs/roadmaps/v0.3.0.md`). The active work
  list is the roadmap file marked **In progress** in that index; resolve it there before
  starting.
- Each versioned roadmap is organized into numbered phases (numbering restarts at Phase 1
  per version). Within a phase, the deliverables are the items, numbered **per phase** —
  each phase restarts at item 1. So an item is only unambiguous when paired with its phase.
- **Every roadmap run has an explicit scope. Do not assume the whole roadmap.**
  Accepted ways I'll give scope:
  - "do the whole roadmap" — all phases of the active version, in order.
  - "do phase 1" / "do phases 1 and 2" — one or more whole phases, in order.
  - "do phase 2 items 1–3" — a contiguous item range within one phase.
  - "do phase 2 item 2" — a single item within one phase.
  - A mix, e.g. "phase 1 then phase 3 items 1–2".
- **Item numbers are per-phase and must always be given with a phase.** If I name an item
  or range without a phase (e.g. "do items 1–3", "do item 5"), STOP and ask which phase —
  do not guess.
- **If I start a roadmap run without naming any scope, STOP and ask which phases or items.**
  Never default to the whole list.
- Before doing any work, resolve the scope to a concrete ordered list and echo it back,
  qualified by phase: "Scope: phase 2 items 1, 2, 3. Starting with phase 2 item 1." This
  echo is confirmation, not a question — proceed unless an item hits its plan-approval gate.
- Work the resolved scope in order, top to bottom. Stop at the end of the scope. Do NOT
  continue into the next phase or item beyond what I asked for.
- For each item in scope, run this loop:
  1. **Analyze** — read the relevant code, state what the item touches (files, functions).
  2. **Plan** — short plan: which files change and how. For changes to core logic
     (correlation / BFS engine, source adapters, anything in the public API) post the plan
     and wait for an explicit OK before implementing. For everything else, implement
     directly.
  3. **Implement** — make the change.
  4. **Check** — two gates, both required before hand-off, both run automatically every
     item (I do not wait to be asked):
     - **Tests:** sync the working tree to the lab server and run the suite there
       (`php -d newrelic.enabled=0 vendor/bin/phpunit`). For new or changed behavior, add
       or update tests in the same item. Iterate fix→retest until green. If a test is red,
       fix the root cause — never weaken, skip, or delete a test to force green; if the
       test itself is wrong, STOP and flag it before changing it. If stuck (same failure
       after a couple of genuine attempts) or a failure smells like environment
       (DB / OpenSearch / network rather than my code), STOP and surface the output instead
       of hammering. See "Test environment (lab server)".
     - **Review:** run `/code-review` on the diff and read the diff yourself.
  5. **Hand off** — `git add`, commit (with the `/code-review` summary in the body for
     source changes), and push to `main`. Include this item's status flip in the active
     roadmap file under `docs/roadmaps/` in the same commit. **Coverage:** if the item changes
     what Provado diagnoses **on live data**, update the matching row(s) in `docs/COVERAGE.md`
     in the same commit — green means *diagnosed on live data*, never "the signal ships" or
     "the fixture passes". If the item moves no coverage cell (a verification, a hygiene fix, an
     internal refactor), say so and skip it — don't force an update. When an item completes a
     whole version, bump the map's `Measured against` / `Last updated` line.
  6. Move to the next item in scope.
- An item is done once its change is committed and pushed. Flip its status in the active
  roadmap file under `docs/roadmaps/` (e.g. `[ ]` → `[x]`) in the same commit as the change
  — don't leave a separate trailing commit just for the checkbox (same for any
  `docs/COVERAGE.md` cell the item moved).

## Parallelism

- Default is sequential — one item at a time. Do not parallelize by default.
- You MAY fan out to parallel subagents only when BOTH are true:
  - the items are independent (none needs another's output), AND
  - they touch non-overlapping files.
- Never parallelize items that share files or have a dependency. Those stay sequential.
- **Pushing is serialized even when editing is parallel.** Subagents may edit their disjoint
  file sets concurrently, but commits to `main` and pushes happen one at a time, coordinated
  by the orchestrator — never two agents pushing to `main` at once. Fan out the editing, then
  fan in and commit + push each item sequentially. On a non-fast-forward rejection,
  `git pull --rebase` and retry.
- **Testing is serialized too:** there is one shared lab-server test mirror, so the green-suite
  gate (loop step 4) runs one item at a time, coordinated by the orchestrator — fan out the
  editing, then fan in and test + commit + push each item sequentially.
- Be conservative. Parallel agents multiply token / usage cost. Only fan out when it clearly
  saves wall-clock time, and only within the scope I named. Don't spawn more than 3 subagents
  at once without asking.

## Git workflow

- All work goes directly to `main`. No branches, no PRs.
- One commit per roadmap item. `git add` the changed files, commit, `git push` to `main`.
- Reference the item in the commit subject (e.g. "phase 2 item 1: fix Z-suffix UTC
  timestamp parsing").
- **Before committing any source-code change** (`src/**`, `tests/**`), the suite must be
  green on the lab server (loop step 4) and a `/code-review` pass must have run on the staged
  diff. Put the `/code-review` summary in the commit body — what was fixed vs. left open —
  and note the tests are green. This is a default; do it without being asked.
- Docs-only changes (`docs/**`, markdown) don't need the `/code-review` pass.
- Push after each item so progress is visible on GitHub. Don't batch the whole scope into
  one push unless I ask.
- If a push is rejected as non-fast-forward, `git pull --rebase` and retry.

## Test environment (lab server)

- The local working copy has **no `vendor/`** — composer deps are not installed on the PC,
  so tests cannot run there. The **lab server is the test source of truth**; GitHub stays the
  canonical git source of truth, and the PC is just the editing workspace.
- **Run the suite automatically as part of every source item** (loop step 4) — I do not wait
  to be asked. Flow: edit locally → sync the working tree to the server → run PHPUnit there →
  iterate fix→retest until green → then `/code-review`, commit, push from local.
- SSH host alias `provado`; repo at `/var/www/html/provado`, treated as a **test mirror** —
  never edited there, safe to `git fetch && git reset --hard origin/main` before/after a run.
- Sync with tar-over-ssh (no `rsync` on the PC):
  `tar -czf - src tests config composer.json phpunit.xml | ssh provado 'tar -xzf - -C /var/www/html/provado'`.
- The test command MUST disable the New Relic ext or PHPUnit OOM-kills on the server:
  `php -d newrelic.enabled=0 vendor/bin/phpunit`. This affects only the test run — it does
  not touch Magento's web/APM monitoring (a live Provado data source).
- If `composer.json` changes (a phase adds a dependency), run `composer install` on the server
  before testing. Otherwise do not reinstall.
- Never weaken, skip, or delete a test to force green — fix the cause; if the test is wrong,
  stop and flag it first.
