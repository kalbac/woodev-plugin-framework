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

## Why it can arrive without warning

This repo went **private on 25.08.2026**. Actions minutes are free for public repositories and
metered for private ones, so going private silently starts spending a monthly allowance that was
previously irrelevant. Two days later it ran out.

## What to do

Nothing agent-side: it is an account setting. Do NOT merge on "the tests passed locally" — the
project rule is every job green with state CLEAN, and s98 found a defect on the same night that
only CI could see (the local PHP is 8.5, the CI floor is 7.4). Leave the PRs open, say so, and
hand it over. After the block is lifted, `close`/`reopen` re-runs the checks.

## Related

- [the-local-php-is-four-versions-above-the-ci-floor](the-local-php-is-four-versions-above-the-ci-floor.md) — why "green locally" is not a substitute
- [a-pull-request-workflow-can-simply-not-fire](a-pull-request-workflow-can-simply-not-fire.md) — the other "CI is lying to you" shape: count the jobs, do not read the colour
