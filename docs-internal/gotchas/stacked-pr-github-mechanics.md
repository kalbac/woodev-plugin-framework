# gotcha: stacked PRs — GitHub closes (never retargets) on base-branch delete, and `ci.yml` never runs on them

**Namespace:** `[tooling/git-merge]`
**Discovered:** s80 (2026-08-19), while merging the #362 PR stack (#363 → #365 → #366)

## Symptom 1 — a downstream PR closes itself when the upstream one merges

`gh pr merge 363 --squash --delete-branch` (PR #363, base `main`, head `feat/shipping-settings-tab`)
merged clean. PR #365 (base `feat/shipping-settings-tab`, head `feat/shipping-checkout-field-policy`)
immediately flipped to `state: CLOSED, mergedAt: null` — not merged, not retargeted to `main`.
`gh pr reopen 365` and `gh pr edit 365 --base main` both fail:

```
API call failed: GraphQL: Could not open the pull request. (reopenPullRequest)
GraphQL: Cannot change the base branch of a closed pull request. (updatePullRequest)
```

## Root cause

GitHub does **not** retarget a PR whose base branch was deleted — it closes it outright, and a
closed PR's base is immutable. This is GitHub product behaviour, not a bug or something this repo's
config controls.

## Fix

The PR's **head** branch survives (only the base branch was deleted) — its commits are intact.
Open a **new** PR from the same head branch, targeting `main` directly, instead of trying to salvage
the closed one:

```bash
gh pr create --repo <owner>/<repo> --base main --head <same-head-branch> --title "..." --body-file ...
gh pr comment <old-PR-number> --body "Closed automatically when the base branch was deleted after #<upstream-PR> merged. Recreated as #<new-PR-number>, same branch, targeting main directly."
```

## Symptom 2 — the recreated PR's diff looks inflated (files from the ALREADY-MERGED PR reappear)

`git diff main...origin/<branch> --stat` (three-dot, merge-base-relative) after the recreation shows
files the upstream PR (#363) already merged — e.g. a whole 170-line file the squash commit already
put in `main` — as if newly added again.

## Root cause (same root cause as [[git-squash-onto-stale-origin-main-diverge]])

A squash merge creates a **new** commit object; the branch's original, pre-squash commits are never
an ancestor of it. `git diff A...B` computes from `merge-base(A,B)`, which — because the squash commit
breaks that ancestry chain — resolves to a commit from BEFORE the upstream PR merged. Everything the
upstream PR changed then shows up a second time in the three-dot diff, even though the tree content is
byte-identical to what is already in `main`.

**This is purely a local diffing artefact, not a real problem** — GitHub's own PR diff view and the
actual merge operation compare trees, not commit ancestry, so the created PR merges cleanly with only
its true incremental content. Verify locally with the **two-dot** form instead, which is a direct
tree comparison and is not fooled by the broken ancestry:

```bash
git diff main...origin/<branch> --stat   # MISLEADING after an upstream squash-merge — ignore this
git diff main..origin/<branch> --stat    # the TRUE remaining diff — trust this one
```

A quick sanity check for one specific file, if in doubt:

```bash
git diff main:<path> origin/<branch>:<path>   # empty output = byte-identical, already merged
```

## Symptom 3 — the recreated (and every stacked) PR shows almost no CI checks

Only `Label PR` and `Markdown Lint` ever appear on `gh pr checks` for a PR whose base is a feature
branch, not `main` — the full matrix (`unit-tests`, `test-js`, `assets`, `secrets`, PHP/WC compat)
never starts, no matter how long you wait.

## Root cause

`.github/workflows/ci.yml`'s trigger is:

```yaml
on:
  push:
    branches: [main]
  pull_request:
    branches: [main]
```

`pull_request.branches` filters by the PR's **base**, not its head — a PR based on anything other
than `main` never satisfies the trigger, full stop. This is not a flaky run or a queued job; it will
never fire while the base stays a feature branch.

## Consequence for a stacked-PR plan

A plan that opens PR #1 → #2 → #3 as a stack (each based on the previous, per this project's own
`docs-internal/plans/*` convention for large features) gets full CI on PR #1 only. PR #2/#3 must be
verified locally (`composer test:unit`, `npm run test:js`, `composer phpcs`, `composer phpstan`, the
integration suite via the docker container) as a substitute — and re-created against `main` (Symptom
1) once the PR ahead of them actually merges, at which point their OWN full CI matrix finally runs.

## Related

- [[git-squash-onto-stale-origin-main-diverge]] — the same squash-merge-breaks-ancestry root cause,
  a different symptom (local `main` diverging from `origin/main`, not a diff-stat artefact).
- Merge protocol: verify each CI job green + state CLEAN before `--squash --delete-branch`, never
  `--auto` (AGENT-RULES / global feedback patterns) — for a stacked PR, "each CI job" means the LOCAL
  gate run, since GitHub's own CI does not exist yet for it.
