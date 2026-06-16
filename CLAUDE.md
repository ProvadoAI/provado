# Provado — working agreements

## Git / PR workflow

- **Docs changes (e.g. `docs/ROADMAP.md`, other `docs/**`, markdown):** commit, push,
  open a PR, and merge it without waiting for review.
- **Source code changes (e.g. `src/**`, `tests/**`):** open a PR and stop there for
  human review. Do NOT merge until explicitly approved.
- **Before opening any source-code PR:** run a `/code-review` pass on the diff and
  summarize the findings in the PR description (note anything fixed vs. left open).
  This is a default — do it without being asked.

## Local environment

- **Do NOT install dependencies (`composer install`/`require`) or run the test
  suite in the working environment.** The deliverable is the code and the PR;
  the human handles dependency installation and test runs. The `/code-review`
  pass is still required — it reads the diff statically and needs no install.
