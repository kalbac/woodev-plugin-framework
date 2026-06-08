# A failing early CI job silently SKIPS dependent jobs — they never run

> [build/ci] — discovered 2026-06-08 while fixing PR #20 CI.

## The trap

The `Lint` job died at its first step (`Security audit`). Because the `Unit Tests`,
`PHP Compat`, and `Publish` jobs `needs:` the `Lint`/`version` jobs, GitHub Actions marked
them **`skipping`**, not `failing`. `gh pr checks` showed them as skipped. A skipped check
is **not** a red ❌, so the suite *looked* mostly fine and `main` looked green.

The consequence: the **entire Unit Tests matrix had never actually run on CI** — for however
long the audit step had been broken (it is identical on `main`). When the audit was fixed,
the Unit job finally ran and immediately surfaced **three** pre-existing, never-CI-validated
failures (gitignored `plugins-reference/`, Brain Monkey function pollution, and reflection
without `setAccessible` on 7.4/8.0).

## Why it matters

"Green CI" can mean "the gate job failed so nothing downstream ran." Fixing the gate doesn't
*introduce* downstream failures — it *reveals* ones that were masked. Budget for a cascade.

## How to apply

- When triaging CI, read **skipped** jobs as suspicious, not safe. `gh pr checks <PR>` lists
  `skipping` rows — a cluster of them downstream of one failed job means that job is a gate.
- After fixing a gate job, expect the unblocked jobs to run for the first time; re-check the
  full matrix, don't assume the gate fix is the end.
- Prefer CI graphs where lint/audit failures don't gate test execution (or accept that an
  early-failing gate hides test health).

## Related

- [[composer-audit-no-prod-deps]] — the specific gate-step failure here
- [[reflection-setaccessible-version-guard]], [[brain-monkey-function-pollution]] — the masked Unit failures it revealed
