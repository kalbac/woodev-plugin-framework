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

## Cleaning up afterwards: `git cherry` also lies, for the same reason

After the whole stack has merged, the local feature branches are still there and `git branch -d`
refuses them ("not fully merged"). The instinct is to reach for `git cherry main <branch>` to check
whether anything is unmerged — it reports every commit as unmerged (`+`), because squash rewrote the
SHAs. `git diff --stat main..<branch>` is just as misleading: it shows the branch's now-stale copies
of files that later PRs changed as "insertions".

**The authority is the PR's own state, not git:**

```bash
gh pr list --head <branch> --state all --json number,state,mergedAt
```

`MERGED` with a `mergedAt` means the content is in `main`; `git branch -D` is then safe and correct.
Do not hand this to the operator as a judgement call — it is a mechanical check with a definitive
answer (s81, and he had said so once before).

## Symptom 4 (s87) — a rig aggregate branch turns the REST of the stack `DIRTY` on the first squash-merge

s86 staged five PRs for a rig pass by building `rig/s86-checkout-fixes` as a chain of MERGE commits
(`rig: PR #461`, `rig: PR #462`, …), and then branched the later PRs off that rig branch. Every PR's
base was `main`, so all five got the full CI matrix and all five reported `CLEAN`. That is the trap:
they were clean **against the un-squashed `main`**.

Merging in order, the first two went through untouched. The moment `#462` landed as a squash commit,
`#464` flipped to `mergeStateStatus: DIRTY` / `mergeable: CONFLICTING` — its branch still carried the
rig MERGE commits for `#461` and `#462`, and the three-way merge against a `main` that now held the
same content under new SHAs could not be computed. And per Symptom 3's sibling gotcha
[[pr-conflict-skips-pull-request-ci]], a `CONFLICTING` PR runs no `pull_request` CI at all.

**The fix is a rebase that drops the rig commits, not a merge:**

```bash
git fetch origin --prune
git checkout -B <pr-branch> origin/<pr-branch>
git rebase --onto origin/main <the rig merge commit this PR sits on>   # leaves ONLY its own commit
git push --force-with-lease origin <pr-branch>
```

Then wait for the re-triggered CI (the force-push starts a fresh matrix; the old green is about a
commit that no longer exists) and merge. Repeat per PR — each one goes `DIRTY` in turn, because each
one carries the rig merge for the PR ahead of it.

**Cheaper next time:** build the rig branch by CHERRY-PICKING onto a throwaway branch, or merge the
stack into the rig branch but branch each PR off `main` directly. The rig branch exists to give the
operator one tree to look at; nothing requires the PR branches to descend from it.

## Symptom 5 (s94, 26.08.2026) — and there is no way back

Confirmed again, and with the recovery path measured this time. Squash-merging #535 with
`--delete-branch` removed the branch that was #537's BASE. GitHub closed #537 immediately, and
**neither half of the obvious recovery works**:

```
$ gh pr reopen 537
GraphQL: Could not open the pull request. (reopenPullRequest)

$ gh pr edit 537 --base main
GraphQL: Cannot change the base branch of a closed pull request. (updatePullRequest)
```

Retargeting requires the PR to be open; reopening requires the base branch to exist. A closed PR
whose base branch is gone is **permanently closed** — the work survives, the PR does not.

Recovery: rebase the branch onto `main`, dropping the commits the squash already absorbed, and open
a NEW PR. `git rebase --onto origin/main <last-commit-of-the-merged-branch>` does it in one step,
and the resulting diff-stat against `main` is the check that it dropped exactly the right commits.
Carry the old PR's body across and leave a comment on the closed one pointing at the replacement,
or the review history is orphaned.

**The cheap prevention:** merge the base PR with `--squash` but WITHOUT `--delete-branch`, retarget
the downstream PR to `main` while it is still open, and only then delete the branch.


## Related

- [[git-squash-onto-stale-origin-main-diverge]] — the same squash-merge-breaks-ancestry root cause,
  a different symptom (local `main` diverging from `origin/main`, not a diff-stat artefact).
- Merge protocol: verify each CI job green + state CLEAN before `--squash --delete-branch`, never
  `--auto` (AGENT-RULES / global feedback patterns) — for a stacked PR, "each CI job" means the LOCAL
  gate run, since GitHub's own CI does not exist yet for it.
