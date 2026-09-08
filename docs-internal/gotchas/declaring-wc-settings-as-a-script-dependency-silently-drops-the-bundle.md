# Declaring `wc-settings` as a script dependency silently drops your whole bundle

> Namespace: `woocommerce/*` — added session 127 (2026-09-08). Measured against WooCommerce 11.1.0
> in the rig container while reviewing SP-10 increment 7.

## The trap

A `wc-admin` page built the Route-B way declares its `wc-*` handles by hand
(`woodev/shipping-method/admin/orders/class-orders-registry.php`). `wc-components`, `wc-navigation`,
`wc-admin-app`, `wc-date` and `wc-currency` are all real, unconditionally built packages — they sit
under `assets/client/admin/{components,navigation,app,date,currency}/`.

**`wc-settings` looks like one more of the same and is not.** Adding it to that array is a defect,
for three separate reasons, each measured in WooCommerce's own source:

1. **It is only CONDITIONALLY registered.** WooCommerce does not assume it exists —
   `src/Internal/Admin/WCAdminAssets.php:460` reads
   `if ( wp_script_is( 'wc-settings', 'registered' ) )`. There is no built
   `assets/client/admin/settings/` package at all.
2. **When an unregistered handle is named as a dependency, WordPress drops the dependent script
   without a word.** `WP_Dependencies::all_deps()` returns `false` and `do_item()` skips it. The
   bundle is enqueued and never printed: the page renders nothing, the browser console is empty, and
   it reads as a broken build rather than as a missing handle.
3. **A direct `wc-settings` dependency is a known ERROR condition in WooCommerce when the script
   prints in the header.** `src/Blocks/AssetsController.php:534-556` — *"The wc-settings script only
   works correctly when enqueued in the footer"* — detects header-printed scripts carrying that
   dependency and substitutes an error handle named `wc-settings-dep-in-header`.

## And you already have it anyway

The same `WCAdminAssets` method that guards the handle, `inject_wc_settings_dependencies()`, injects
`wc-settings` into `wc-admin-layout`, `wc-csv`, `wc-currency`, `wc-customer-effort-score`,
`wc-navigation`, `wc-notices`, `wc-number` and friends **whenever it is registered at all**. So a
page declaring `wc-components` / `wc-navigation` / `wc-currency` receives it transitively in exactly
the cases where it exists — and correctly does without it in the cases where it does not.

## ❌ Wrong

```php
$dependencies = array_merge(
    (array) $asset['dependencies'],
    [ 'wc-components', 'wc-navigation', 'wc-admin-app', 'wc-date', 'wc-currency', 'wc-settings' ]
);
```

## ✅ Correct

```php
// `wc-settings` is deliberately NOT declared: it is only conditionally registered
// (WCAdminAssets.php:460 guards it), an unregistered dependency makes WordPress drop
// this bundle silently, and WooCommerce injects it into wc-currency/wc-navigation
// itself whenever it does exist.
$dependencies = array_merge(
    (array) $asset['dependencies'],
    [ 'wc-components', 'wc-navigation', 'wc-admin-app', 'wc-date', 'wc-currency' ]
);
```

Read whatever that handle would have carried out of `window.wc.*` at runtime instead, and **degrade
the one control rather than the page** when it is absent — the same rule the `RoiPanel` already
follows.

## The transferable half

Route B's premise is "declare the handles by hand". The unstated precondition is that the handle is
unconditionally registered. Before adding one, check that `assets/client/admin/<name>/` exists as a
built package **and** that WooCommerce's own code does not guard it with `wp_script_is()`. A guard in
the vendor's source is the vendor telling you it may be absent.

## Related

- [wp-scripts-names-a-chunk-from-basename-minus-js](wp-scripts-names-a-chunk-from-basename-minus-js.md) — the other silent-enqueue-failure on this page
- [wp-scripts-css-enqueue-version-by-mtime](wp-scripts-css-enqueue-version-by-mtime.md)
- `docs-internal/specs/2026-09-07-sp10-orders-page-design.md` §D7 — why Route B was forced
