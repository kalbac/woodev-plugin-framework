# Gotcha: [testing/php-versions] — a frozen packing digest differs on PHP 7.4, because `usort()` is not stable there
> Tags: testing, box-packer, php-7.4, determinism | Session: s169

## What happens

A unit test that freezes the packer's output as a hash (`sha1( json_encode( $packages ) )` over seeded carts) is green
on PHP 8.x locally and red on CI's PHP 7.4 job only:

```text
Failed asserting that two strings are identical.
-'f86c73850f5f970268f0a55b56fafc271af71b7e'
+'01045aac59320855684a4db5e5dc6ba025c38377'
```

It reads like "the optimisation changed the packing on old PHP". It did not: the SAME digest `01045aac…` comes out of
the code BEFORE the optimisation when run on 7.4 (measured s169, #1214, `php:7.4-cli` container).

## Root cause

Since PHP 8.0 `sort()`/`usort()` are stable; on 7.4 they are not. Items that compare equal (same sides, same volume)
keep their input order on PHP 8 and come out shuffled — deterministically, but differently — on 7.4, so they land in
other packages. Both results are valid packings; they are simply not the same bytes.

## ✅ Correct

Freeze one digest per behaviour family, both taken from the reference code on each version, and say why:

```php
// PHP 7.4's usort() is not stable, so equal items land in other (equally valid) packages than on PHP 8.
$expected = PHP_VERSION_ID < 80000 ? '01045aac…' : 'f86c73850…';
```

Or compare the two code paths inside one process instead of against a frozen constant. To reproduce CI's 7.4 locally:
`docker run --rm -v "$PWD":/app -v <primary>/vendor:/app/vendor:ro -w /app php:7.4-cli php vendor/bin/phpunit --testsuite=Unit --filter <Test>`
(`--testsuite=Unit` is required — without it the integration suite loads and dies on `WP_UnitTestCase`).

## ❌ Wrong

Freezing a single digest measured on the local PHP 8.5 and trusting it for the whole CI matrix.

## Related

- [below-php-8-1-reflection-needs-setaccessible-and-anonymous-classes-are-class-at-anonymous](below-php-8-1-reflection-needs-setaccessible-and-anonymous-classes-are-class-at-anonymous.md) — another "green on 8.5, red on 7.4" trap
- [woodev-packer-boxes-checks-volume-not-placement](woodev-packer-boxes-checks-volume-not-placement.md)
