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
  4. **Check** — run `/code-review` on the diff and read the diff yourself. STATIC check
     only. Do NOT run the test suite or install anything (see Local environment).
     "Check it" here means static review, not test execution.
  5. **Hand off** — `git add`, commit (with the `/code-review` summary in the body for
     source changes), and push to `main`. Include this item's status flip in the active
     roadmap file under `docs/roadmaps/` in the same commit.
  6. Move to the next item in scope.
- An item is done once its change is committed and pushed. Flip its status in the active
  roadmap file under `docs/roadmaps/` (e.g. `[ ]` → `[x]`) in the same commit as the change
  — don't leave a separate trailing commit just for the checkbox.

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
- Be conservative. Parallel agents multiply token / usage cost. Only fan out when it clearly
  saves wall-clock time, and only within the scope I named. Don't spawn more than 3 subagents
  at once without asking.

## Git workflow

- All work goes directly to `main`. No branches, no PRs.
- One commit per roadmap item. `git add` the changed files, commit, `git push` to `main`.
- Reference the item in the commit subject (e.g. "phase 2 item 1: fix Z-suffix UTC
  timestamp parsing").
- **Before committing any source-code change** (`src/**`, `tests/**`), run a `/code-review`
  pass on the staged diff and put the summary in the commit body — what was fixed vs. left
  open. Static review only: no install, no test run (see Local environment). This is a
  default; do it without being asked.
- Docs-only changes (`docs/**`, markdown) don't need the `/code-review` pass.
- Push after each item so progress is visible on GitHub. Don't batch the whole scope into
  one push unless I ask.
- If a push is rejected as non-fast-forward, `git pull --rebase` and retry.

## Local environment

- **Do NOT install dependencies (`composer install`/`require`) or run the test
  suite in the working environment.** The deliverable is the committed code;
  the human handles dependency installation and test runs. The `/code-review`
  pass is still required — it reads the diff statically and needs no install.
