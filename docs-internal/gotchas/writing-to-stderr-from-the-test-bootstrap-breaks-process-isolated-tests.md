# Gotcha: [testing/phpunit] — Anything written from `tests/bootstrap.php` turns process-isolated tests into errors
> Tags: testing, phpunit, bootstrap, process-isolation | Session: s102

## What happens

#609 asked for the local-PHP-vs-CI-matrix divergence to be made LOUD. The obvious place was
`tests/bootstrap.php` — it runs once per suite, before anything else. Five lines were added there to
`fwrite( STDERR, $notice )`.

The suite went from green to **36 errors**, in tests that have nothing to do with PHP versions:

```
1) Woodev\Tests\Unit\PlatformNeutralHelperTest::test_format_percentage_falls_back_without_woocommerce_helper
PHPUnit\Framework\Exception: PHP 8.5 is NOT in the CI matrix (7.4, 8.0, 8.1, 8.2, 8.3).
  A green run here is evidence about 8.5 only. Trust CI, not this.
  Issue #609.
```

The notice text itself is reported as the test's failure. A gate meant to announce ONE problem
invented three dozen fake ones.

## Root cause

PHPUnit runs some tests in a separate PHP process (`@runInSeparateProcess`, `processIsolation`,
`@preserveGlobalState disabled`). The child serialises its result and the parent **parses the
child's output stream to recover it**.

`tests/bootstrap.php` runs in the CHILD too — it is the bootstrap for that process as well. Anything
it writes lands in the stream ahead of the serialised result, the parent cannot parse what it gets,
and it reports the stray text as a `PHPUnit\Framework\Exception` against whichever test was running.

So the blast radius is not "tests that check output". It is **every process-isolated test in the
suite**, and which ones those are is not obvious from the test you are editing.

## Fix

❌ Wrong — writes from the bootstrap, which is also the child's bootstrap:

```php
// tests/bootstrap.php
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( '' !== $notice ) {
	fwrite( STDERR, $notice );   // 36 unrelated tests now error
}
```

✅ Correct — the pure functions live where tests can reach them, and only a composer-script entry
point writes, outside the PHPUnit process entirely:

```php
// bin/php-version-matrix.php — pure, no side effects on include
function woodev_php_version_notice( string $running, array $matrix, string $platform = '' ): string { … }

// bin/check-php-version.php — the only thing that writes
require_once __DIR__ . '/php-version-matrix.php';
fwrite( STDOUT, $notice );
```

```jsonc
// composer.json
"test:unit":   [ "@php-version", "./vendor/bin/phpunit --testsuite=Unit" ],
"php-version": "@php bin/check-php-version.php"
```

The notice still appears every time a human runs `composer check` or `composer test:unit`, and the
PHPUnit process stays pristine.

**The general rule:** `tests/bootstrap.php` may DEFINE things; it must not EMIT anything. If a
message has to reach a human, put it in the composer script that invokes PHPUnit, not in the
bootstrap PHPUnit invokes.

## Related

- [the-local-php-is-four-versions-above-the-ci-floor](the-local-php-is-four-versions-above-the-ci-floor.md) — the divergence #609's gate announces
- [patchwork-early-load-bootstrap](patchwork-early-load-bootstrap.md) — the other bootstrap-ordering trap in this same file
- [the-skipped-count-is-dominated-by-whether-sodium-is-enabled](the-skipped-count-is-dominated-by-whether-sodium-is-enabled.md) — measured in the same session, same theme: the local runtime is not CI's
