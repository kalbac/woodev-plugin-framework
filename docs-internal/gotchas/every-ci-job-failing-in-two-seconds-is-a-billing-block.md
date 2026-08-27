# Every CI job failing in two seconds is a billing block, not a red build

**Namespace:** `[build/*]`
**Found:** s98 (27.08.2026), at 02:49 UTC, mid-session.

## The trap

`gh pr checks` reports `fail` against every job, and `mergeStateStatus` goes `UNSTABLE`. It reads
exactly like a broken commit — and it is not code at all:

```
Assets build parity   fail  2s
JS Tests              fail  2s
Label PR              fail  2s
Read version          fail  2s
WP 6.4 / WC 8.5.1     fail  2s
```

The tell is in the run's ANNOTATIONS, which `gh pr checks` does not show:

```
$ gh run view <run-id>
X The job was not started because recent account payments have failed or your
  spending limit needs to be increased. Please check the 'Billing & plans' section
```

## How to recognise it in one glance

**Everything fails, instantly, including jobs that build nothing.** `Label PR` and `Read version`
take two seconds and touch no code — a commit cannot break them and every job at once. A real
regression is selective; this is not.

`gh run view <run-id>` (no flags) prints the annotation. `--log-failed` does **not** — there is no
log, because no job ever started.

## Why it arrives without warning — measured, not guessed

Two things compounded, and the second is the one an agent controls.

**The counter got switched on.** This repo went **private on 25.08.2026**. Actions minutes are free
for public repositories and metered for private ones, so going private silently starts spending a
monthly allowance that was previously irrelevant.

**Then one overnight session spent most of it.** s98 ran **187 workflow runs in a night**. Measured
per-job from `started_at`/`completed_at`, rounded up to the minute the way GitHub bills:

| | |
|---|---|
| one full PR cycle | **27 billable minutes** (CI 16 + Integration 10 + PR Triage 1) |
| the night's total | **≈1042 minutes** |
| the plan's monthly allowance | 2000, of which 1800 were used |

So a single autonomous session consumed **~58 % of the month**. At that rate the allowance covers
**two such sessions a month.** The block is not a fault to route around — it is the budget
behaving correctly.

What actually blocks is a **$0 spending budget**, GitHub's default overrun protection, and usage
**resets on the 1st**. So "wait for the reset" is a real option, not just "go pay".

### The two measured sinks, for whoever tunes this

`concurrency: cancel-in-progress` is already set on every workflow here — that saving is taken.

- **34 of 78** CI+Integration runs that night were `push` to `main`, i.e. re-running on `main`
  exactly what the PR had just proven on the same tree. ≈440 min/month at that rate.
- A commit touching only `docs-internal/**` ran the **full Integration matrix**: 27 minutes where
  `paths-ignore` would have made it 1.

Both touch the merge gate, so both are an operator decision, not an agent's.

## What to do

Nothing agent-side: it is an account setting. Do NOT merge on "the tests passed locally" — the
project rule is every job green with state CLEAN, and s98 found a defect on the same night that
only CI could see (the local PHP is 8.5, the CI floor is 7.4). Leave the PRs open, say so, and
hand it over. After the block is lifted, `close`/`reopen` re-runs the checks.

## Related

- [the-local-php-is-four-versions-above-the-ci-floor](the-local-php-is-four-versions-above-the-ci-floor.md) — why "green locally" is not a substitute
- [a-pull-request-workflow-can-simply-not-fire](a-pull-request-workflow-can-simply-not-fire.md) — the other "CI is lying to you" shape: count the jobs, do not read the colour
