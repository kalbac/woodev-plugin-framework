# Gotcha: [tooling/local-ci] — the tail of a terminal shows the PREVIOUS local-ci run; wait on the run's log directory instead

> Tags: local-ci, orca, terminal, merge-gate | Session: s204 (30.10.2026, Mac, local-ci 0.18.x)

## What happens

A coordinator that decides «the run finished» by reading `orca terminal read` of the local-ci terminal can match a result
line (`✓ … passed`, `2 of 2`) left by the **previous** run in the same terminal and report green evidence for a run that has
not finished — or has failed.

## Root cause

A terminal is one scrollback. Re-running local-ci in it appends; the old summary stays above the new output, and a regex on
the last N lines sees both.

## Fix

❌ Wrong:

```bash
orca terminal read --terminal "$T" | tail -40 | grep -E 'passed|failed'
```

✅ Correct — poll the run's own record. Every run writes
`~/Library/Application Support/local-ci/logs/<run>/metadata.json` (carries `workflowPath`) and `timeline.json` (per-job
result). Pick the run by `workflowPath` AND an `mtime` newer than the launch moment, wait until its timeline shows every
job finished, then read the result from there. The coordinator's `lc-wait.sh` of s204 did exactly this. (The log directory
is shared by every project on the machine — never pick «the newest».)

## Same trap through the exit file (s207, 31.10.2026)

A watcher that waits for a `*.exit` marker file written by a previous local-ci run can read the OLD value before the new run
truncates it, and report green for a run that has not started. The coordinator's per-PR exit files (`ci.yml 0`,
`markdown-lint.yml 0`) were reused across runs of one PR.

❌ Trust a marker file that exists. ✅ Truncate or delete it BEFORE launching, and confirm the result on the commit status of the
head SHA the run was started for (`--commit-status` posts a `local-ci` check on the PR).

## Related

- [local-ci-on-orbstack-needs-local-ci-docker-host-not-docker-host](local-ci-on-orbstack-needs-local-ci-docker-host-not-docker-host.md) — launching the run
- [local-ci-pause-on-failure-blocks-an-unattended-worker](local-ci-pause-on-failure-blocks-an-unattended-worker.md) — the other way a local-ci wait goes quiet
