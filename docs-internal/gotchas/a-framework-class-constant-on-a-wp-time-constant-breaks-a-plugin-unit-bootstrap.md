# gotcha: a framework class constant built on `MINUTE_IN_SECONDS` breaks a carrier plugin's unit suite

**Namespace:** `[testing/unit]`
**Discovered:** s177 (2026-10-14), bumping the Yandex plugin's framework pin to `8b56ae0a`

## What happens

The licence gate (#1270) added class constants such as `CLAIM_REFRESH_THROTTLE = 15 * MINUTE_IN_SECONDS` to
`Woodev_Plugins_License`. The framework's own suite and the CDEK plugin's suite stayed green; the Yandex plugin's suite
went from 489 green to **21 errors** `Undefined constant "MINUTE_IN_SECONDS"` — in API, documents, location and checkout
tests that never touch licensing. A class constant expression is evaluated when the class's constants are first used, and
the Yandex unit bootstrap (Brain Monkey, no WordPress) never defines WordPress's time constants.

In a real site this cannot happen: WordPress defines them in `wp_initial_constants()` before any plugin loads. So the red
suite reads like a framework regression and is not one.

## ✅ Correct

Define the WordPress time constants in the plugin's unit bootstrap, guarded, one array value per line (WPCS
`ArrayDeclarationSpacing` fails a one-line associative array — that cost a second local-ci run):

```php
$wp_time_constants = [
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
];
foreach ( $wp_time_constants as $wp_time_constant => $wp_time_seconds ) {
	if ( ! defined( $wp_time_constant ) ) {
		define( $wp_time_constant, $wp_time_seconds );
	}
}
```

After every pin bump run the plugin's suite on the SYNCED `woodev/` before opening the PR.

## Related

- [a-stale-primary-checkout-degrades-every-worktree-made-from-it](a-stale-primary-checkout-degrades-every-worktree-made-from-it.md) — the other pin-bump trap
- Session: [../sessions/s177.md](../sessions/s177.md)
