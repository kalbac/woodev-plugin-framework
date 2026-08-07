# An empty `statusCheckRollup` + `CLEAN` can be a GitHub Actions OUTAGE, not your config

> Namespace: `build/ci` — added session 54 (2026-08-07)

## Context

PR #177 (`feat/pickup-selection` → `main`) was opened 2026-08-06T20:51Z. Ten minutes later,
and still ~20 hours later, `gh pr view 177 --json statusCheckRollup` returned `[]` and
`gh run list --branch feat/pickup-selection` listed **not a single run** — while
`mergeStateStatus` reported **`CLEAN`**.

`CLEAN` here means only "no merge conflicts". `main` carries no required-status-check gate,
so nothing stops a merge on zero evidence. That combination already burned this project once
(s29, merged on a red PHPStan).

## The gotcha

The instinct is to blame the repository: wrong `on:` triggers, a path filter, a disabled
workflow, a modified workflow file on the branch, exhausted Actions minutes. All five were
checked and all five were fine:

- `ci.yml` and `integration-tests.yml` both declare `pull_request: branches: [main]`, no path filters
- `git diff origin/main...HEAD -- .github/` — **empty**, the branch never touched a workflow
- `gh workflow list --all` — every workflow `active`
- `gh api repos/OWNER/REPO/actions/permissions` — `{"enabled": true, "allowed_actions": "all"}`
- the repo is **public**, so there is no minutes quota to exhaust at all

The actual cause was a **GitHub-side incident**. `https://www.githubstatus.com/api/v2/summary.json`
reported Actions in **Major Outage** since 2026-08-06T15:22Z — the PR was created inside that
window — and GitHub's own update named this exact symptom:

> "Webhook triggers are currently throttled to help with recovery and we are processing
> approximately 15% of webhooks, so many events such as pushes and pull requests are not
> triggering workflow runs."

Note the second-order trap: because the events are *dropped*, **re-triggering also silently
fails**. Closing + reopening the PR (a `reopened` event) produced nothing; pushing an empty
commit (a `synchronize` event) produced nothing either. Two failed re-triggers read as
"something is deeply broken in this repo" when they are simply two more dropped webhooks.

## Correct

- When zero runs exist for a PR whose triggers you have verified, **check
  `https://www.githubstatus.com/api/v2/summary.json` (or `…/incidents/unresolved.json`)
  before diagnosing the repo any further.** It is one fetch and it either exonerates or
  convicts the platform outright.
- Order the cheap local checks first anyway (triggers → workflow diff vs base → `gh workflow
  list` → `actions/permissions`), so that reaching the status page means the repo is genuinely
  clean.
- During an outage: stop re-triggering — every attempt is another throttled webhook. Wait for
  the incident to resolve, then push one fresh empty commit and verify each job reports pass
  individually.

## Incorrect

- Merging on `mergeStateStatus: CLEAN` with an empty rollup. `CLEAN` is about conflicts, never
  about tests, and this repo's `main` has no required checks to fall back on.
- Reading "two re-triggers did nothing" as evidence of a repository misconfiguration.
- Treating a locally-green suite as a substitute for CI evidence. Local runs were fully green
  here (1425 unit / 702 jest / 96 integration / phpcs 193 / PHPStan clean) and that is still
  not the same proof — different OS, different PHP matrix, and the jest suite is not in CI at
  all (issue #146).

## Related

- [[pr-conflict-skips-pull-request-ci]] — the other way a PR ends up with no `pull_request` CI;
  there the tell is `DIRTY`/`CONFLICTING`, here it is a `CLEAN` PR with nothing running.
- [[ci-failing-gate-skips-dependent-jobs]] — third member of the family: skipped ≠ failed, and
  the absence of a job is never evidence of its success.
