# Gotcha: [testing/isolated-run] — A stale Composer classmap breaks ONLY isolated test runs, never the full suite
> Tags: testing, tooling, autoload | Session: s100

## What happens

Running one unit-test file on its own fatals with a class-not-found for a framework class
that plainly exists on disk and is plainly committed:

```
Error: Class "Woodev\Framework\Shipping\Settings\Shipping_Tools_Registry" not found
  woodev/shipping-method/location/class-location-provider-registry.php:724
```

The same file passes as part of `--testsuite=Unit`. The full suite is unaffected — measured
**2978 / 7178 / 66 both before and after the fix**, byte for byte — so no baseline ever
recorded in this repo is compromised by it.

This is the diagnosed cause of the long-standing trap that handoffs recorded as
*«`LocationServiceDefaultTest` не гоняется в изоляции — падает 27/28 и без правок. Причина
не диагностирована.»* It is not specific to that file, and **not** specific to a worktree:
reproduced in the PRIMARY checkout.

## Root cause

Two facts have to be true at once, and both are:

1. **PSR-4 cannot resolve these classes at all.** `composer.json` maps
   `"Woodev\\Framework\\": "woodev/"`, so PSR-4 looks for
   `woodev/Shipping/Settings/Shipping_Tools_Registry.php`. The real file is
   `woodev/shipping-method/settings/class-shipping-tools-registry.php` — WordPress naming,
   which PSR-4 structurally cannot map. The directory `woodev/Shipping/` does not exist.
   So for Composer the class is reachable **only** through the generated classmap.
2. **The generated classmap goes stale silently.** `vendor/composer/autoload_classmap.php` is
   gitignored and regenerated only by `composer install` / `composer dump-autoload`. Add a
   class and commit it, and every checkout that does not re-dump keeps a map without it. On
   27.08.2026 the committed map held 1614 classes; a fresh dump produced **1628**.

In a **full** run some earlier test has already pulled the class into memory (or the
framework's own `woodev/class-map.php` autoloader got there first), so the gap is masked. In
**isolation** nothing has, and Composer is the only autoloader in play — fatal.

The shipped runtime is NOT affected: `woodev/class-map.php` is committed, contains the class,
and is what plugins actually boot through. This is a dev-autoload defect only.

## Fix

❌ Wrong — diagnosing it as a worktree defect, a bad `vendor` copy, or a defect in the PR
under test. It is none of those; the primary checkout has the same latent gap.

❌ Wrong — measuring one file to decide whether a change is green:

```bash
./vendor/bin/phpunit --testsuite=Unit tests/unit/Shipping/Location/LocationServiceDefaultTest.php
# Tests: 33, Assertions: 1, Errors: 32.
```

✅ Correct — re-dump once, then the isolated run works too:

```bash
composer dump-autoload
./vendor/bin/phpunit --testsuite=Unit tests/unit/Shipping/Location/LocationServiceDefaultTest.php
# OK (33 tests, 121 assertions)
```

✅ Correct, and the standing rule regardless — **measure with a full run**:

```bash
rm -f .phpunit.result.cache
./vendor/bin/phpunit --testsuite=Unit
```

After adding or renaming a framework class, run `php bin/generate-class-map.php` and commit
the map (Rule 3) — and run `composer dump-autoload` locally so the dev autoloader matches.
Only the first is a commit; the second is local hygiene.

**Also surfaced by the dump, not yet investigated:** two classes under `woodev/competitor/`
are reported as PSR-4 non-compliant and **skipped** by Composer entirely
(`class-wc-admin-notes-renderer.php`, `interface-competitor-notice-renderer.php`).

## Related

- [phpunit-takes-one-path-and-silently-ignores-the-rest](phpunit-takes-one-path-and-silently-ignores-the-rest.md) — the other way a narrowed phpunit invocation misleads you about coverage
- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the other "green run that measured less than you think"; compare SKIPPED, which is 66
- [sharing-vendor-breaks-composer-autoload-in-a-worktree](sharing-vendor-breaks-composer-autoload-in-a-worktree.md) — why `vendor` is COPIED into a worktree, never shared
- [framework-classmap-autoload-vendored-boot](framework-classmap-autoload-vendored-boot.md) — the committed `woodev/class-map.php` the shipped runtime boots through, which is why production is unaffected
