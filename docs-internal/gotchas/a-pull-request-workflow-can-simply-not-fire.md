# Gotcha: [build/ci] — A `pull_request` workflow can simply not fire, with nothing wrong with the PR
> Namespace: `build/ci` — added s97 (2026-08-27)

## What happens

A PR is opened (or pushed to) and only **`PR Triage` / `Label PR`** appears in its checks. `CI`
and `Integration Tests` never start. The PR reports `MERGEABLE` / `CLEAN`, `ci.yml` has no `paths`
filter, and `gh run list --branch <branch>` confirms the runs were never created — not queued,
not skipped, absent.

Observed twice in s97 on the same repo within twenty minutes: on **#555 at `opened`**, and on
**#556 at `synchronize`** (a push of a new commit). In both cases the immediately preceding and
following PRs on the same repo got their full run set.

## Root cause

Not established. What IS established is what it is **not**: not a merge conflict (the sibling
gotcha below), not a `paths` filter, not the `concurrency` group (`pull_request` refs are
per-PR: `refs/pull/<n>/merge`), and not a credentials or permissions problem — `PR Triage` runs
on `pull_request_target` and fired every time.

The asymmetry is the tell: `pull_request_target` delivered, `pull_request` did not. Treat it as a
GitHub-side event-delivery miss until something better is measured.

## Fix

Close and reopen the PR. That re-emits the `pull_request` event and the full run set appears.

```bash
gh pr close  <n> --repo <owner>/<repo>
gh pr reopen <n> --repo <owner>/<repo>
```

Worked both times in s97. It costs nothing and does not touch the branch or its commits.

❌ Do NOT wait it out. There is no queue to drain — the run does not exist. An agent polling
`gh pr checks` will sit there indefinitely reading "1 check, passing" as healthy.

❌ Do NOT merge on it. **Count the jobs**, do not read the colour:

- a PR touching `**.md` → **20** jobs (Markdown Lint included)
- a code-only PR → **19**

One green check is not a green PR.

## Related

- [[pr-conflict-skips-pull-request-ci]] — same symptom, a cause you CAN diagnose: a conflicting
  PR has no computable merge commit, so `pull_request` workflows are skipped. Check
  `mergeable`/`mergeStateStatus` first; if it is `CLEAN`, you are in THIS gotcha instead.
- [[ci-failing-gate-skips-dependent-jobs]] — third member of the "skipped ≠ failed, looks green"
  family, caused by a `needs:` gate.
- [[empty-status-rollup-can-be-a-github-actions-outage]] — when the rollup is empty rather than short.
