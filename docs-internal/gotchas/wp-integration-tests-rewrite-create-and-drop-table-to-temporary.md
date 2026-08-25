# gotcha: in the WP integration suite `CREATE TABLE` and `DROP TABLE` become `TEMPORARY`, and `SHOW TABLES` then answers about a different table

**Namespace:** `[testing/integration]`
**Discovered:** s91 (2026-08-25), writing the #498 integration test for the popular-settlements table

## What happened

The first run of `PopularSettlementTableTest` produced a result that reads as impossible:

```text
DIAG option='2' existsAfterDrop='wp_woodev_popular_settlements'
```

A `DROP TABLE IF EXISTS` had just run and reported success. `SHOW TABLES LIKE` still returned the
table. The same `DROP` + `SHOW` pair, run through `wp eval-file` against the dev rig, behaved
correctly (`drop result = true`, `exists after drop = NULL`) — so the statement was fine and the
environment was not.

## Root cause

`WP_UnitTestCase_Base::start_transaction()` installs two `query` filters
(`wordpress-phpunit/includes/abstract-testcase.php:478-479`):

```php
add_filter( 'query', array( $this, '_create_temporary_tables' ) );
add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
```

They rewrite a **leading** `CREATE TABLE` to `CREATE TEMPORARY TABLE` (line 498) and a **leading**
`DROP TABLE` to `DROP TEMPORARY TABLE` (line 511). That is how the suite keeps each test isolated
without real DDL.

Two consequences collide:

1. `dbDelta()`'s `CREATE TABLE` produces a **temporary** table. Every read and write in the test
   hits it, so behavioural assertions (upsert semantics, unique-key enforcement) are still real
   MySQL behaviour and still meaningful.
2. **`SHOW TABLES` does not list temporary tables.** So any assertion phrased as "does the table
   exist" answers about a *real* table of the same name — a leftover from some earlier run — and
   the `DROP TABLE` that was supposed to clear it only dropped the temporary shadow.

The test was therefore measuring two different tables at once, and passing for the wrong reason.

## ❌ Wrong

```php
protected function setUp(): void {
    parent::setUp();
    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" ); // becomes DROP TEMPORARY TABLE
    $this->store->install();                                 // becomes CREATE TEMPORARY TABLE
}

// ... and then:
$this->assertSame( $this->table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) ) );
// passes off a leftover REAL table, not the one the test just created
```

## ✅ Correct

Remove both filters for the test class that genuinely needs real DDL, and own the table's lifecycle
at both ends:

```php
protected function setUp(): void {
    parent::setUp();
    global $wpdb;

    remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
    remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

    $wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" );
    $this->store->install();
}

protected function tearDown(): void {
    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" );
    parent::tearDown();
}
```

With the filters gone the table is real, so **the DDL commits the surrounding transaction
implicitly** and nothing the test writes afterwards is rolled back. That is why `tearDown()` must
drop the table AND undo any option the test wrote — the transaction will not do it.

## The second half: drop AND REINSTALL, never merely drop

A `tearDown()` that only drops is still wrong, and the first attempt here was. The suite's own
bootstrap fires `init` **outside any test's transaction and before the filters exist**, so
`Location_Provider_Registry::maybe_install_popular_settlements_table()` leaves a REAL, empty table
that every later test in the run inherits. Dropping it and stopping made two unrelated tests in
`ProviderSelectionScopeAgreementTest` go RISKY, printing
«Table 'wp_woodev_popular_settlements' doesn't exist» in the middle of an assertion — a failure
that names neither the file that caused it nor the reason.

Restore what you found: present, empty, current schema.

## How to notice it

The tell is a `DROP` that succeeds while `SHOW TABLES` still sees the table, or a schema assertion
that passes on a run where the schema is obviously wrong. When in doubt, mutate the schema
deliberately (remove a key, drop a column) and confirm the test actually fails — the #498 test was
checked that way and produced 4 failures with the unique key removed.

## Related

- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the other "a green suite is not the suite you think" trap
- [phpunit-result-cache-makes-a-run-unreproducible](phpunit-result-cache-makes-a-run-unreproducible.md) — always `rm -f .phpunit.result.cache` before a measurement
- Issue #498 — the card this was found while closing
