# [testing/integration] Two concurrent integration runs share ONE test database, and the loser's failures read as code defects

> Namespace: `testing/*` — added session 129 (2026-09-09)

## The trap

The rig's `tests-cli` container has exactly one test database (`tests-wordpress`), and
`WP_UnitTestCase` **installs and drops its tables on every run**. Two integration runs started at
the same time therefore demolish each other's schema mid-flight.

The second run does not report a lock or a conflict. It reports **your code failing**:

```text
WordPress database error: [Table 'tests-wordpress.wp_users' doesn't exist]
  DELETE FROM wp_users WHERE ID != 1
...
Tests: 192, Assertions: 696, Failures: 3, Risky: 22.
```

The 22 risky are all `Test code or tested code did not (only) close its own output buffers` — the
DB-error HTML above is printed straight into whatever buffer was open when the other run pulled the
table out.

Measured in s129: the same tree, same commit, three runs in a row gave **3 failures / 22 risky**,
then **0 failures / 15 risky**, then **OK (192 tests, 703 assertions), 0 risky** — the only variable
was whether a second agent happened to be running the suite at that moment.

## Why it happens even when the second runner is "isolated"

The runner does not have to be in the same checkout. In s129 a critic agent copied its worktree into
the container (`docker cp` → `/tmp/wt`) and ran `--testsuite Integration` from there, believing that
a private copy of the CODE made the run private. It does not: the code path is private, the
**database is not**. `WP_TESTS_DIR` and the working directory are per-run; `tests-wordpress` is
per-container.

## ❌ Wrong

```bash
# Coordinator, main checkout:
docker exec -e TEST_SUITE=integration $C php .../phpunit --testsuite=Integration
# Critic, at the same moment, from its own copy — believing this is isolated:
docker exec -e TEST_SUITE=integration -w /tmp/wt $C php vendor/bin/phpunit --testsuite Integration
```

## ✅ Correct

**One integration run at a time, and it belongs to the coordinator.** Every brief that mentions
integration tests must say so explicitly — a worker or critic asked to *write* or *review*
integration coverage will otherwise reach for the obvious way to check its work:

> ⛔ Do not run the integration suite. The coordinator runs it and returns the output to you.
> The unit suite is free to run in your worktree — it never touches MySQL.

Before believing an integration failure, ask what else was running. Re-run serialized, with
`rm -f .phpunit.result.cache` (`phpunit.xml` sets `executionOrder="depends,defects"`), and only
then treat a failure as a finding.

## Related

- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the other half of why integration is the coordinator's job
- [killing-phpunit-leaves-its-mysql-query-running-and-holding-locks](killing-phpunit-leaves-its-mysql-query-running-and-holding-locks.md) — the neighbouring way one run poisons the next
- [phpunit-result-cache-makes-a-run-unreproducible](phpunit-result-cache-makes-a-run-unreproducible.md) — the OTHER reason two runs of one tree disagree; rule out both, in this order
- [wpenv-windows-gitbash-path-mangling](wpenv-windows-gitbash-path-mangling.md) — the `MSYS_NO_PATHCONV=1 docker exec` recipe itself
- [three-agents-is-the-concurrency-cap-on-this-machine](three-agents-is-the-concurrency-cap-on-this-machine.md) — concurrency limits that are about RAM rather than shared state
