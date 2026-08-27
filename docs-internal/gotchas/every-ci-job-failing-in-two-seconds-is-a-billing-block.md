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

### Local CI is a real option for a private repo — with one hard constraint

`run-local-ci` (renamed from `@redwoodjs/agent-ci`; the CLI is `local-ci`) runs the SAME workflow
YAML on GitHub's own official runner binary in Docker, so the version matrix genuinely expands —
that is what separates it from `act` and from hand-rolled scripts.

**It does not run on native Windows.** It dies on `tar (child): Cannot connect to C:` before
reaching Docker, because its cache layer hands a `C:\...` path to a POSIX `tar`. Measured on
**0.18.1 (27.08.2026)**, four releases after the 0.16.2 that first hit this — the bug is not
fixed. Under **WSL** it works: `npx run-local-ci run --workflow .github/workflows/markdown-lint.yml`
passed in 36 s with every step really executing. On 15 GB it prints
`degraded mode: job resource hints exceed the available host capacity` and runs anyway.

**Not measured, and the part that would decide it:** the heavy workflows — the PHP 7.4–8.3 matrix
and three wp-env Docker stacks — on a box where RAM is already the binding constraint.

### The two measured sinks, for whoever tunes this

`concurrency: cancel-in-progress` is already set on every workflow here — that saving is taken.

- **34 of 78** CI+Integration runs that night were `push` to `main`, i.e. re-running on `main`
  exactly what the PR had just proven on the same tree. ≈440 min/month at that rate.
- A commit touching only `docs-internal/**` ran the **full Integration matrix**: 27 minutes where
  `paths-ignore` would have made it 1.

Both touch the merge gate, so both are an operator decision, not an agent's.

## What to do

Do NOT merge on "the tests passed locally" — the project rule is every job green with state
CLEAN, and s98 found a defect on the same night that only CI could see (the local PHP is 8.5,
the CI floor is 7.4). Leave the PRs open, say so, and hand it over. After the block is lifted,
`close`/`reopen` re-runs the checks.

**HOW IT WAS RESOLVED HERE (27.08.2026, operator decision): the repo went PUBLIC again.** Public
repositories on standard runners are free and consume no quota, so the block lifted immediately —
measured, not assumed: the same `Markdown Lint` run that had failed in 2 s passed in 14 s right
after the switch, and a full CI re-run went green (`JS Tests` 53 s, `Assets build parity` 42 s).

The trade he accepted: `docs-internal/` is publicly readable again. Weighed against the fact that
it had ALREADY been public from the repo's creation until 25.08 — the licensing/anti-piracy specs
date from June — and that the repo has 0 forks, 0 watchers, 1 star. Going private on 25.08 closed
a door on a room that had been photographed for months; the only genuinely new exposure was the
three-day delta, which is checkout and location-layer work.

**The option NOT taken, recorded so nobody re-derives it:** split `docs-internal/` into its own
private repository and keep the code public. That is the only shape giving free CI *and* closed
internals. Its cost is that the whole session protocol commits docs and code in ONE commit, so it
would mean two repos, two commits, and rewriting every path in `CLAUDE.md`, `AGENTS.md`,
`scripts/lint-docs.mjs` and the docs' own cross-links.

## Related

- [the-local-php-is-four-versions-above-the-ci-floor](the-local-php-is-four-versions-above-the-ci-floor.md) — why "green locally" is not a substitute
- [a-pull-request-workflow-can-simply-not-fire](a-pull-request-workflow-can-simply-not-fire.md) — the other "CI is lying to you" shape: count the jobs, do not read the colour
