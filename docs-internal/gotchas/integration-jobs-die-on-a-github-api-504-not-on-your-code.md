# All three integration jobs red on a docs-only PR — it is a GitHub API 504, not your change

**Namespace:** `[build/ci]` · **Discovered:** s87 (2026-08-23), hit three times in one night

## Symptom

Every job of the `Integration Tests` matrix fails at once — `WP 6.4 / WC 8.5.1 / PHP 8.1`,
`WP 6.6 / WC 9.3.0 / PHP 8.2` and `WP latest / WC latest-stable / PHP 8.2` — while every other job
on the same PR is green. It happened on a **docs-only** PR, which is what makes it obvious in
hindsight and invisible in the moment: three red checks read as "I broke something".

`gh run view --job <id> --log-failed` is useless here — it prints only the post-job cleanup steps.
The cause is in the full log:

```
Failed to download sebastian/type from dist: The "https://api.github.com/repos/…/zipball/…"
file could not be downloaded (HTTP/2 504 )
#38 ERROR: process "/bin/sh -c composer global require --dev phpunit/phpunit:\"^5.7.21 || … \""
    did not complete successfully: exit code: 100
✖ Error while running docker compose command.
```

## Root cause

`integration-tests.yml` builds the wp-env docker image, and that image's build step runs
`composer global require --dev phpunit/phpunit`. Composer pulls those dists from
`api.github.com`. When GitHub's API is degraded it answers `504`, composer aborts with exit 100, the
image build fails, and the whole job dies **before a single test runs**.

The matrix uses `fail-fast: false`, so all three combinations hit the same degraded API in parallel
and all three go red together. That simultaneity is the tell: a real regression rarely takes out
every WP/WC/PHP combination at exactly the same second, and never on a PR that touched no PHP.

## How to tell it apart, in one command

```bash
gh run view --repo <owner>/<repo> --job <job-id> --log | grep -iE "504|could not be downloaded|did not complete successfully"
```

Anything mentioning `api.github.com` and `504` is infrastructure. Compare with the honest failure
mode: a real test failure names a test class and prints an assertion diff.

## Fix

```bash
gh run rerun <run-id> --repo <owner>/<repo> --failed
```

Nothing else. Do not "fix" the code, do not touch the workflow, and do not merge past it — the jobs
must actually be green before the merge, because they never ran at all.

It recurred three times in the s87 night, so a single rerun is not always enough; if GitHub's API is
having a bad hour, wait it out rather than re-running in a loop.

## Related

- [[pr-conflict-skips-pull-request-ci]] — the other way a job can be absent rather than failing:
  a `CONFLICTING` PR never triggers `pull_request` workflows at all.
- [[ci-failing-gate-skips-dependent-jobs]] — "skipped ≠ failed, looks green", the same family of
  trap read from the opposite end.
