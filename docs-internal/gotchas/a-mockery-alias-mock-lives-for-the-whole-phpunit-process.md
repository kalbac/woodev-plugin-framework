# A Mockery `alias:` mock lives for the whole PHPUnit process

**Namespace:** `[testing/phpunit]` · **Discovered:** s192 (2026-10-22), Russian Post v2 P11 + P16 merge

## The trap

`Mockery::mock( 'alias:' . SomeClass::class )` does not stub a class — it **declares** a class with
that name in the running process. PHP cannot undeclare a class, so `Mockery::close()` in `tearDown()`
removes the expectations but the alias class stays. Every later test in the same process that reaches
`SomeClass::method()` hits the leftover alias, whose expectations are gone.

Seen in s192: P16's `RussianPostMigrationStorageTest` aliased WooCommerce's `OrderUtil`. P11's
`RussianPostOrderExportTest::test_a_point_stored_at_checkout_reaches_the_export_body` then reached the
framework's `Woodev_Plugin_Compatibility::is_hpos_enabled()` → `OrderUtil::custom_orders_table_usage_is_enabled()`
and died with `Call to a member function __call() on null`. Both branches were green alone; only the
merged suite failed, and the failing test was in neither card's diff.

## ❌ Wrong

```php
public function test_storage_reads_hpos(): void {
	Mockery::mock( 'alias:' . OrderUtil::class )
		->shouldReceive( 'custom_orders_table_usage_is_enabled' )->andReturn( true );
	// ...
}
```

## ✅ Correct

```php
/**
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
public function test_storage_reads_hpos(): void {
	Mockery::mock( 'alias:' . OrderUtil::class )
		->shouldReceive( 'custom_orders_table_usage_is_enabled' )->andReturn( true );
	// ...
}
```

The annotations go on the METHOD — PHPUnit 9 ignores them on the class
([phpunit-9-ignores-run-in-separate-process-on-a-class](phpunit-9-ignores-run-in-separate-process-on-a-class.md)).
Better still, inject the dependency so no alias is needed.

## How to catch it

Run `vendor/bin/phpunit --order-by=random` (and once after every merge of two parallel branches). The
same random run in s192 exposed two more leaks of the same family: a test that relied on `WC()` being
undefined, and one that relied on another test having loaded a contract class.

## Related

- [phpunit-9-ignores-run-in-separate-process-on-a-class](phpunit-9-ignores-run-in-separate-process-on-a-class.md)
- [phpunit-defects-cache-hides-cross-test-session-leaks](phpunit-defects-cache-hides-cross-test-session-leaks.md)
- [mockery-mock-new-method-full-suite](mockery-mock-new-method-full-suite.md)
