# [testing/integration] wp-env on Windows: Git-Bash mangles container paths (MSYS conversion)

> Namespace: `testing/*` — added session 9 (2026-06-11), extended s123 (`docker exec` + the
> host-side `composer test:integration` dead end)

## The trap

Running wp-env commands from Git Bash (or the Bash tool on Windows) converts
absolute container paths into host paths before Docker sees them:

```bash
npx wp-env run tests-cli --env-cwd=/var/www/html/woodev-framework -- env WP_TESTS_DIR=/wordpress-phpunit ...
# → "chdir to cwd ('/var/www/html/C:/Program Files/Git/var/www/html/woodev-framework') failed"
# → WP_TESTS_DIR becomes 'C:/Program Files/Git/wordpress-phpunit'
```

MSYS rewrites every argument that looks like an absolute POSIX path. The failure
modes are confusing (`exit 127`, chdir errors inside the container).

## Correct

Either run from PowerShell (no MSYS conversion):

```powershell
npx wp-env run tests-cli --env-cwd=/var/www/html/woodev-framework -- env TEST_SUITE=integration WP_TESTS_DIR=/wordpress-phpunit ./vendor/bin/phpunit --testsuite=Integration
```

or keep the paths inside a single-quoted `bash -c` payload so MSYS never sees them
as standalone args:

```bash
npx wp-env run tests-cli bash -c "cd /var/www/html/woodev-framework && TEST_SUITE=integration WP_TESTS_DIR=/wordpress-phpunit php vendor/bin/phpunit --testsuite=Integration"
```

Also remember: the integration bootstrap branches on `TEST_SUITE=integration`
(`tests/bootstrap.php`) — without it the unit branch runs and `WP_UnitTestCase`
is "not found" even inside the container.

## s123: the same trap on `docker exec`, and two failures that both read as "the code is broken"

The rig is already up between sessions, so `docker exec` straight into its container is faster than
`npx wp-env run`. It hits the identical MSYS rewrite, and the message names PHP rather than paths:

```bash
docker exec -e TEST_SUITE=integration <container> \
  php /var/www/html/woodev-framework/vendor/bin/phpunit --testsuite=Integration
# → Could not open input file: C:/Program Files/Git/var/www/html/woodev-framework/vendor/bin/phpunit
```

That reads like a broken vendor install. It is the same conversion; prefix the command:

```bash
MSYS_NO_PATHCONV=1 docker exec -e TEST_SUITE=integration \
  de59f74e6d3d19d18a7f7b6608fda7e7-tests-cli-1 \
  php /var/www/html/woodev-framework/vendor/bin/phpunit \
    --configuration /var/www/html/woodev-framework/phpunit.xml \
    --testsuite=Integration --no-coverage
```

That is the whole coordinator recipe: **143 tests / 530 assertions, ~12 s** (s123). The container
name is `<wp-env hash>-tests-cli-1`; find it with `docker ps --format '{{.Names}}'`.

**And the other half of the same confusion:** `composer test:integration` run on the HOST cannot
work at all — there is no `WP_TESTS_DIR` there, so PHPUnit dies with

```text
PHPUnit\TextUI\RuntimeException: Class "WP_UnitTestCase" not found
```

after a wall of stack frames. It is not a regression, not a bootstrap bug and not something to
debug: integration only ever runs inside the container. `CURRENT-STATE.md` says integration is the
coordinator's job and must not run from a worktree; this is the command it means.

## Related

- [[wpenv-resolver-fixture-mapping]] — the other wp-env setup trap.
