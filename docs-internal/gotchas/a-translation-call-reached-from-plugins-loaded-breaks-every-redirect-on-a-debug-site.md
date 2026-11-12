# Gotcha: [i18n/jit] A translation call reached from `plugins_loaded` prints a just-in-time notice that breaks every redirect

**Discovered:** s229 (12.11.2026), rig sweep of Ozon Seller after the O3 port.

## What happens

On WordPress ≥ 6.7 with `WP_DEBUG` display on, a `__()` that runs before `init` prints
`_load_textdomain_just_in_time was called incorrectly` into the response. The notice is output before headers,
so every redirect and cookie on that request dies: login returned 200 with a notice instead of a 302 (161 notices
in the log), and a v1 settings-URL redirect broke. Unit tests never see it; only a rig sweep did.

## Root cause

`Plugin_Obligations::register()` (hooked on `plugins_loaded`) built the v1 webhook route object eagerly. Its intake
translates strings (`Ozon_Push_Protocol::webhook_types()`), so constructing it called `__()` too early.

## Fix

Build any object whose constructor or first use translates **lazily**, on the hook that actually needs it.

```php
// ❌ plugins_loaded: the route is built, webhook_types() translates, too early
add_action( 'plugins_loaded', fn() => $this->route = new Legacy_Webhook_Route() );

// ✅ built when the request arrives
add_action( 'woocommerce_api_wc-ozon-seller-hooks', fn() => ( new Legacy_Webhook_Route() )->handle() );
```

After touching bootstrap code, load one admin page and log in on a debug rig and read `debug.log` for the notice.

## Related

- [wc-email-default-texts-built-in-the-constructor-use-the-admins-locale](wc-email-default-texts-built-in-the-constructor-use-the-admins-locale.md) — the same "translated too early" family
- [wp-locale-switcher-caches-available-languages-at-init](wp-locale-switcher-caches-available-languages-at-init.md) — another init-order locale trap
