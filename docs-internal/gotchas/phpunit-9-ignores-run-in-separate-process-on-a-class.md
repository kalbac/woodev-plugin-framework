# Gotcha: [testing/phpunit] — PHPUnit 9 ignores `@runInSeparateProcess` on a CLASS docblock, so the test leaks into the unit process

> Tags: testing, phpunit, isolation, ci | Session: s175

## What happens

A new test class declared WooCommerce Blocks' `IntegrationInterface` in `setUp()` (via `eval`) and carried
`@runInSeparateProcess` + `@preserveGlobalState disabled` on the CLASS docblock. It was green in normal order and in every
local run, and failed CI's reverse-order gate (`--order-by=reverse`): `PickupBlocksTest::test_the_integration_is_not_registered_when_woocommerce_blocks_is_absent`
and a sibling saw the interface and asserted `true is false` (PR #1247, card #1244).

## Root cause

In PHPUnit 9 `@runInSeparateProcess` is a METHOD annotation; on a class it is silently ignored. The class-level form is
`@runTestsInSeparateProcesses`. Nothing warns — the test just runs in the shared process and its global class declaration
survives into whatever runs after it, which only reverse order exposes.

## Fix

❌ `@runInSeparateProcess` in the class docblock.

✅ Put `@runInSeparateProcess` + `@preserveGlobalState disabled` on EACH test method (or `@runTestsInSeparateProcesses` on the
class), and reproduce the CI reverse-order step locally before pushing (`.github/workflows/ci.yml` has the exact command).

## Related

- [brain-monkey-function-pollution](brain-monkey-function-pollution.md)
- [a-random-order-seed-is-not-a-portable-repro](a-random-order-seed-is-not-a-portable-repro.md)
