# Gotcha: [php/options] — `add_option()` is not an atomic lock: two contenders both «acquire»

> Tags: php, concurrency, wordpress, locks | Session: s184

## What happens

A claim written as «`if ( add_option( $key, $owner ) ) { …I own it… }`» lets two concurrent requests both proceed. It was found
in a critic round on the CDEK courier-status scan (edostavka PR #113): the scan «claimed» its slot with `add_option()`, and
two cron/AJAX contenders each saw `true`.

## Root cause

`add_option()` first checks that the option does not exist (a cached read), then runs `INSERT … ON DUPLICATE KEY UPDATE`.
Contender B can pass the existence check before contender A's row is visible, and the upsert then overwrites A's row instead of
failing — so the return value says nothing about who is first.

## Fix

❌ `add_option( 'x_lock', $token )` as the claim.

✅ One of the two atomic primitives the framework already uses:

- `INSERT IGNORE INTO {$wpdb->options} …` with a per-contender owner token, check `$wpdb->rows_affected === 1`, and release or
  extend with a CONDITIONAL `UPDATE`/`DELETE … WHERE option_value = <token>` — the pattern in
  `woodev/error-reporting/class-dispatcher.php`.
- MySQL `GET_LOCK()` — `Order_Lock` (`woodev/shipping-method/order/class-order-lock.php`), when the claim is per order and short.

Never read the claim back with `get_option()` (object cache, `notoptions`) — read it with `$wpdb`.

## Related

- [get-option-turns-a-failed-select-into-the-default-and-caches-it](get-option-turns-a-failed-select-into-the-default-and-caches-it.md) — the same option-API blind spots on the read side
- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
