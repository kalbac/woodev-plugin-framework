# A PR that conflicts with base runs no `pull_request` CI — only `pull_request_target`

> Namespace: `build/ci` — added session 2 (2026-06-09)

## Context

GitHub Actions `pull_request`-triggered workflows run against the **computed merge commit**
of the PR (head merged into base). If the PR **conflicts** with base, GitHub can't compute
that merge commit, so it **skips** those workflows entirely — they never appear, never run,
and there is no "failed" check to alert you. `pull_request_target` workflows (which run
against the head, not the merge) still run, so a check like "PR Triage / Label PR" passes
and the PR can look deceptively healthy.

## The gotcha

This session, PR #21 was **squash-merged** to `main`. The branch `autodev/loop-s2` still
carried the **unsquashed** history, so re-opening a PR from it diverged and conflicted with
`main` (`gh pr view --json mergeStateStatus` = `DIRTY`, `mergeable` = `CONFLICTING`). Result:
**CI and Integration Tests never ran** for the new commits — only PR Triage did. It looked
like CI "passed" because the one check present was green.

## Correct

- Before trusting CI on a PR, check `gh pr view <n> --json mergeable,mergeStateStatus`.
  `MERGEABLE` / `CLEAN` (or `UNSTABLE` while running) = good; `CONFLICTING` / `DIRTY` =
  CI is **not running**, fix the conflict first.
- Confirm the real jobs actually ran for the head SHA:
  `gh run list --branch <branch>` and check the `headSha` matches.
- When a base branch was squash/rebase-merged, **rebase** your feature branch onto the new
  base (`git rebase --onto origin/main <old-base> <branch>`) rather than merging — it drops
  the now-duplicated history and clears the conflict cleanly.

## Incorrect

- Assuming "all checks green" on a PR means the test suite ran. Count the checks — if the
  matrix jobs (Unit/Integration) are absent, they were skipped, not passed.

## Related

- [[ci-failing-gate-skips-dependent-jobs]] — sibling "skipped ≠ failed, looks green" trap,
  but caused by a `needs:` gate rather than a merge conflict.
