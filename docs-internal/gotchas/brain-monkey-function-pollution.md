# Brain Monkey function definitions leak across tests — PHP can't un-define a function

> [testing/unit] — discovered 2026-06-08 fixing PR #20 Unit tests.

## The trap

`HelperTest` mocks a WC function:

```php
Functions\expect( 'wc_format_decimal' )->...   // Brain Monkey DEFINES wc_format_decimal
```

PHP **cannot un-define** a function once it exists. Brain Monkey's `tearDown()` clears the
expectation, but the function symbol stays defined for the rest of the process. So a later
test in the same run sees `function_exists('wc_format_decimal') === true`.

`PlatformNeutralHelperTest::test_format_percentage_falls_back_without_woocommerce_helper` tests
the **WP-only fallback** (it expects `dp=false → 0 decimals`, unlike real WC's `→ 2`). Its code
path is `if ( function_exists('wc_format_decimal') ) { … } else { fallback }`. Once polluted,
`function_exists` is true, the code calls `wc_format_decimal`, and Brain Monkey throws
`"wc_format_decimal" is not defined nor mocked in this test`.

It depends on **test order**: CI runs `HelperTest` before `PlatformNeutralHelperTest`
(alphabetical) → deterministic failure on CI; locally the order differed → passed. Classic
"passes locally, fails on CI."

## Fix

Run the fallback test in a clean process so the function is genuinely absent:

```php
/**
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
public function test_format_percentage_falls_back_without_woocommerce_helper(): void { … }
```

(This is function-table isolation, distinct from the Windows class-table caveat — the autoloader
is inherited but `wc_format_decimal` is runtime-defined, not autoloaded, so a fresh process has
it absent.)

## Why it matters

A test that asserts behaviour **when a function is absent** is fragile: any other test that
`expect`/`when`s that function poisons it for the rest of the process. Don't "fix" it by
mocking the function (that tests the wrong branch and reimplements the fallback in the mock).

## How to apply

- To test an `if ( function_exists(...) ) {…} else {…}` *else* branch, isolate the test in a
  separate process; you cannot make a once-defined function un-exist.
- `passes locally / fails on CI` for a `function_exists` test → suspect cross-test function
  pollution + test ordering.

## Related

- [[ci-failing-gate-skips-dependent-jobs]] — this was one of the masked Unit failures
- [[reflection-setaccessible-version-guard]] — another masked Unit failure (PHP-version-specific)
