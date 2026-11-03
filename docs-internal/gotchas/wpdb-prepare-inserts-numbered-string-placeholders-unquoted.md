# Gotcha: [php/wpdb] — `wpdb::prepare()` leaves numbered `%N$s` string placeholders unquoted, and a reused placeholder raises `_doing_it_wrong`

> Tags: wpdb, prepare, sql, unit-tests | Session: s212 (03.11.2026)
> **Measured on:** the local rig (WordPress in Docker on the MacBook), through the real `$wpdb->prepare()`.

## What happens

The #711 analytics query builder bound string values through numbered placeholders (`%1$s`). On the rig every uncached
request failed with an SQL syntax error: the unquoted date was parsed as arithmetic. The query runner then suppressed the
database error and cached the zero result, so the tab showed zeros as if they were a valid empty report. The unit tests
stayed green, because they used a fake `wpdb` that never executes SQL.

A second trap in the same statement: one numbered placeholder used twice (`%1$s … %1$s`) makes `prepare()` emit a
`_doing_it_wrong` notice, and that HTML lands in the REST response body.

## Root cause

WordPress quotes only the bare `%s` placeholder. The numbered form `%N$s` goes through the "Unquoted strings for backward
compatibility" branch of `wp-includes/class-wpdb.php`, so the value is inserted with no quotes. Reusing one numbered
placeholder is refused the same way: each occurrence needs its own argument.

## Fix

❌ Wrong — the value lands unquoted; the statement is broken:

```php
$wpdb->prepare( 'SELECT COUNT(*) FROM t WHERE created >= %1$s AND created < %2$s', $from, $to );
```

❌ Wrong — one numbered placeholder reused (`_doing_it_wrong` notice):

```php
$wpdb->prepare( 'SELECT COUNT(*) FROM t WHERE a = %1$s OR b = %1$s', $value );
```

✅ Correct — quote the string placeholder yourself, one argument per occurrence; integers stay bare:

```php
$wpdb->prepare( "SELECT COUNT(*) FROM t WHERE created >= '%1\$s' AND created < '%2\$s'", $from, $to );
$wpdb->prepare( 'SELECT COUNT(*) FROM t WHERE a = %1$s OR b = %2$s AND id = %3$d', $value, $value, $id );
```

**How to check it.** Run the finished statement through the real `$wpdb->prepare()` and execute it against a database
(on the rig: `wp eval`), then check that it returns rows and no error. A fake `wpdb` in a unit test proves neither
point: the template test passed while the tab showed zeros.

## Related

- [a-probe-that-finds-nothing-passes](a-probe-that-finds-nothing-passes.md) — another green check that proves nothing
- [a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all](a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all.md) — a query that succeeds and returns the wrong rows
