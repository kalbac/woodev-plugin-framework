# A MySQL named lock alone does not fence a background tick — `wpdb` reconnects silently on error 2006

**Topic:** `[framework/*]` · **Discovered:** s232 (2026-11-16, #1795 spec r3, proven by the E2 integration test)

## Symptom

A bulk run guarded only by `GET_LOCK( name, 0 )` could still be worked by two ticks at once: tick A's connection died (a `wait_timeout`,
a server restart, a `KILL`), tick B took the freed lock and went on from the saved cursor — and tick A, whose PHP carried on, kept
writing products and saving over B's progress.

## Root cause

`wpdb::query()` catches MySQL error 2006 («server has gone away»), reconnects through `check_connection()` and **re-runs the query on a new
connection**. MySQL frees a named lock the moment its connection closes, so after the reconnect the tick still believes it holds a lock
that it no longer owns, and every later query runs on a connection that holds nothing.

## ❌ Wrong

```php
if ( $lock->acquire( $account_id ) ) {
	$this->work_parts();   // saves and product writes carry no proof of ownership
	$lock->release( $account_id );
}
```

## ✅ Correct

The lock decides who *starts*; two more things decide who may *write* (`Bulk_Run_Runner`, `Bulk_Run_Store`):

1. write a random **tick token** right after the lock is granted and add `AND tick = <mine>` to every save and to the terminal write —
   0 rows affected means «I lost the run»: stop, queue no next tick;
2. ask `SELECT IS_USED_LOCK( name ) = CONNECTION_ID()` **before every product write** — after a reconnect `CONNECTION_ID()` is the new
   connection, so the answer is false. Throw a dedicated exception that does not extend `\RuntimeException`: the code around the write
   catches `\RuntimeException` for its own reasons and would swallow it.

What remains is the one write whose own query triggered the retry; keep every per-product write idempotent.

Test it on **two real connections** (`tests/integration/Marketplace/BulkRunTwoConnectionsTest.php`): SQLite has no `GET_LOCK` and
a mocked `wpdb` cannot reconnect.

## Related

- [Marketplace bulk operations spec](../specs/2026-11-16-marketplace-bulk-operations.md) §2.3
- [Marketplace module wiki — bulk runs](../wiki/marketplace-admin.md)
