# [testing/integration] wp-env on Windows: Git-Bash mangles container paths (MSYS conversion)

> Namespace: `testing/*` — added session 9 (2026-06-11)

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

## Related

- [[wpenv-resolver-fixture-mapping]] — the other wp-env setup trap.
