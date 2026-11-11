# Gotcha: [testing/integration] — the framework link store COMMITs the WP test transaction; a plugin test must purge its rows itself
> Tags: marketplace, link-store, transaction, plugin-tests | Session: s227

## What happens

A plugin WP test that creates marketplace links through the framework link store leaves the rows behind after the test: the store
issues a `COMMIT`, which ends the test-case transaction that `WP_UnitTestCase` relies on for rollback. Later tests in the same run
see the rows and fail or pass for the wrong reason.

## Root cause

The test harness isolates tests by wrapping each in a transaction and rolling it back. Any code path that commits (the link store does)
makes the rollback a no-op for everything written before the commit.

## Fix

```php
// ❌ relies on the rollback — the link rows survive the test
class Export_Test extends WP_UnitTestCase { /* writes links */ }

// ✅ the plugin test purges what the store committed
class Export_Test extends WP_UnitTestCase {
	use Purges_Committed_Rows; // clears the committed link rows in tearDown
}
```

Found while writing the Ozon Seller O2 tests; `Purges_Committed_Rows` is the plugin-side trait for it.

## Related

- [two-concurrent-integration-runs-share-one-test-database](two-concurrent-integration-runs-share-one-test-database.md)
