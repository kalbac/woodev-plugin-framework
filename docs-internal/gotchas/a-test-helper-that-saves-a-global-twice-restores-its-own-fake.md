# A test helper that saves the original global on every call restores its own fake after a second call

**Namespace:** `[testing/state]` · **Discovered:** s228 (2026-11-12), card #1698

## What happens

`LicenseInForceTest::arm_wpdb()` did `$this->previous_wpdb = $GLOBALS['wpdb'] ?? null; $GLOBALS['wpdb'] = $fake;`, and `tearDown()`
restored `previous_wpdb`. One test armed it twice, so the second call saved the FIRST FAKE as «the original», and `tearDown()` put a
fake back into `$GLOBALS['wpdb']` for the rest of the process. Its `query()` json-decodes a prepared string and `unserialize()`s an
argument, so later tests that reach `$wpdb` through `trait-rest-rate-limit.php` (`ScriptHandlerRateLimitTest`,
`ScriptHandlerLogFieldTest`, `RestRateLimitTraitTest`) died with `unserialize(): Error at offset 0 of 10 bytes`, pointing at
`LicenseInForceTest.php:1178`. It surfaced only on CI PHP 7.4 and only when the file order put those tests after it — first as a
one-off flake (#1697), then deterministically after a PR added test files (#1732, twice).

## ❌ Wrong

```php
private function arm_wpdb(): object {
	$this->previous_wpdb = $GLOBALS['wpdb'] ?? null; // overwritten on the 2nd call with our own fake
	$GLOBALS['wpdb']     = $wpdb;
}
```

## ✅ Correct

```php
private function arm_wpdb(): object {
	if ( ! $this->wpdb_armed ) {             // capture the pre-test value once per test
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->wpdb_armed    = true;
	}
	$GLOBALS['wpdb'] = $wpdb;
}
// tearDown(): restore previous_wpdb, reset wpdb_armed.
```

A stack trace that names ANOTHER test file's line is the tell: the failing test is running someone else's double.

## Related

- [the-framework-link-store-commits-the-wp-test-transaction-so-plugin-tests-must-purge-its-rows](the-framework-link-store-commits-the-wp-test-transaction-so-plugin-tests-must-purge-its-rows.md) — the other s228 order-dependence: DDL commits the test transaction, so an option set by one integration test survives into the next.
