# [testing/integration] A wrong `dirname(__DIR__, N)` depth aborts the ENTIRE Integration suite, not one file

> Namespace: `testing/*` — added session 44; promoted from an index-only note to a file at the s75 docs cleanup.

## The trap

PHPUnit resolves `require` statements while it **builds** the suite, before it runs a single
test. So one integration test file that `require`s framework sources at the wrong directory
depth does not fail alone — it takes the whole Integration suite down with it.

The reading is misleading in exactly the wrong direction: "the integration tests are red" looks
like a regression in the code under test, when it is one bad path in one new file.

## Correct

Count the depth from the file's own directory:

```php
// from tests/integration/<Group>/SomeTest.php
require dirname( __DIR__, 3 ) . '/woodev/class-plugin.php';   // repo root is 3 up

// from tests/unit/<A>/<B>/SomeTest.php
require dirname( __DIR__, 4 ) . '/woodev/class-plugin.php';   // repo root is 4 up
```

## The rule this buys

**An integration test written "for CI" must still be run locally once.** The suite-build failure
is invisible to every other gate — phpcs, phpstan and the unit suite all stay green, because none
of them loads the file.

```bash
MSYS_NO_PATHCONV=1 npx wp-env run tests-cli …
```

## Related

- [[wpenv-windows-gitbash-path-mangling]] — why that local run needs `MSYS_NO_PATHCONV=1`.
- [[wpenv-resolver-fixture-mapping]] — the other wp-env setup trap.
- [[phpunit-defects-cache-hides-cross-test-session-leaks]] — the other way a local integration run disagrees with CI.
