# gotcha: the `geoip` default-locality policy resolves NOTHING on a local rig, and looks like a broken feature

**Namespace:** `[rig/fixtures]`
**Discovered:** s99 (2026-08-27), while preparing the rig for the #518 pass

## What happens

Set `woodev_location_default_locality_policy = geoip` on the local rig, load the checkout, and
nothing happens: no default locality, no seeded settlement record, and — because there is no
implicit record — no address lock either. Every mechanism that depends on the policy reads as
broken, and the settings page cheerfully shows the policy as active.

## Root cause: the customer's IP is `127.0.0.1`

`Location_Service::resolve_geoip_default()` asks `WC_Geolocation::get_ip_address()` and hands the
answer to the provider's `locate()`. On a local rig that answer is the loopback address, and no
geolocation API can say anything about it — DaData returns nothing, `locate()` returns `null`, and
the policy resolves to `null` exactly as it would for an unresolvable IP anywhere else.

Nothing is wrong with the code. The rig simply cannot exercise this policy as-is, and the failure
is indistinguishable from the policy being broken.

## ✅ Pin a real IP with a rig-only mu-plugin

`WC_Geolocation::get_ip_address()` checks three `$_SERVER` keys in order
(`class-wc-geolocation.php:82-99`): `HTTP_X_REAL_IP`, then `HTTP_X_FORWARDED_FOR`, then
`REMOTE_ADDR`. Setting the FIRST one leaves the other two alone, so nothing else on the rig sees a
rewritten client address.

```php
<?php
/**
 * Plugin Name: Woodev Rig — a real client IP for the geoip default locality
 * RIG ONLY. Delete this file to restore normal behaviour.
 */
defined( 'ABSPATH' ) || exit;

$_SERVER['HTTP_X_REAL_IP'] = '77.88.8.8'; // Yandex public DNS — geolocates to Moscow.
```

`mu-plugins` is not bind-mounted (see `.wp-env.json`), so write it into the container's volume
directly rather than into the repo.

## Two things to know before trusting the result

**The provider answers in ITS OWN language.** Measured on this rig, DaData's `locate()` returns
`settlement: "Moscow"`, not `«Москва»` — an account-level response-language setting. The pickup
fixture matches localities by NAME and carries an alias list for exactly this reason
(`class-test-bulk-point-source.php`), so points are found; a fixture without such a list would
silently return none and look like a second, unrelated bug.

**Restoring the option is not enough to restore the STATE.** A customer who already has a stored
location keeps it, so the policy never re-resolves. Clear `woodev_customer_location` from user meta
AND from the `wp_woocommerce_sessions` rows — editing each row in place, because deleting it drops
the cart.

## Related

- [rig-serves-the-working-tree-branch-switch-reverts-fixes](rig-serves-the-working-tree-branch-switch-reverts-fixes.md) — the other half of preparing a rig pass
- [the-cdek-fixture-credentials-are-not-the-option-they-look-like](the-cdek-fixture-credentials-are-not-the-option-they-look-like.md) — the same shape of trap: a rig setting that looks live and is not
- [../CURRENT-STATE.md](../CURRENT-STATE.md) — «Local rig», including what the rig is currently switched to and how to put it back
