# Gotcha: [shipping/poller] — a carrier status poller's `run()` must accept the framework's manual-refresh arguments `(0, true)`
> Tags: status-poller, cron_hook, do_action, manual-refresh | Session: s222

Since framework 2.0.26 (#1617) the «Обновить статусы сейчас» tool fires the provider's `cron_hook` as `do_action( $hook, 0, true )`
(offset, manual). Before that it fired `do_action( $hook )`, and WordPress passed `''` as the first argument. A plugin poller written
against the old call breaks in one of two ways: a typed `run( int $offset = 0, bool $manual = false )` threw a `TypeError` on `''`
(Yandex, `woocommerce-yandex-delivery#159`, fixed by the new call), and a poller whose second parameter means something else treats
`true` as its own argument — Ozon's `run( $offset = 0, $token = '' )` read `true` as a continuation token and polled nothing (critic C5,
fixed in Ozon 2.0.5 before release). A green unit suite does not see it: the plugin's tests call `run()` directly, never through the hook.

❌ **Wrong:**

```php
add_action( self::HOOK, array( $this, 'run' ), 10, 2 );
public function run( $offset = 0, $token = '' ) { /* `true` arrives as $token */ }
```

✅ **Correct:**

```php
add_action( self::HOOK, array( $this, 'run' ), 10, 2 );
public function run( $offset = 0, $manual = false ) { /* the framework's contract: (int offset, bool manual) */ }
// plus a test that fires do_action( self::HOOK, 0, true ) and asserts a poll happened
```

## Related

- [../gotcha-index/shipping.md](../gotcha-index/shipping.md) — shipping index
