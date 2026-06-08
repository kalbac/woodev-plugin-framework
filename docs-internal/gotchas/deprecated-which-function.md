# Gotcha: [deprecation/deprecated-which-function] — wc_deprecated_function vs _deprecated_function
> Tags: deprecation, backward-compatibility, woocommerce, wordpress | Session: s2

## What happens
Using the wrong deprecation function causes the deprecation notice to either not fire (if WooCommerce's shim isn't loaded), or fires with unexpected behavior. More critically, as the framework moves toward **decoupling from WooCommerce** (v2.0.0 plan), `wc_deprecated_function()` calls will break because they require WooCommerce to be active.

## Root cause
The codebase currently uses `wc_deprecated_function()` (a WooCommerce function). This works because the framework currently requires WooCommerce. However, for framework-internal deprecations (hook deprecator, data compatibility, plugin method delegation), `_deprecated_function()` (WordPress core) is more appropriate and doesn't create a WC dependency for non-WC functionality.

AGENT-RULES.md correctly shows the `_deprecated_function()` pattern, but the actual code uses `wc_deprecated_function()` in `class-plugin.php`.

## Fix

❌ **Avoid for framework-internal (non-WC) deprecations:**
```php
/** @deprecated 1.1.8 */
public function do_install() {
    wc_deprecated_function( __METHOD__, '1.1.8', ... );  // Requires WC
    $this->get_lifecycle_handler()->init();
}
```

✅ **Use for WC-coupled code:**
```php
// In payment-gateway/ — keep wc_deprecated_function (WC-dependent module)
wc_deprecated_function( __METHOD__, '1.1.8', 'new_method()' );
```

✅ **Use for framework-internal deprecations:**
```php
// In class-plugin.php core methods, class-helper.php — use WP's version
/** @deprecated 2.0.0 Use new_method() instead. */
public function old_method(): void {
    _deprecated_function( __METHOD__, '2.0.0', __CLASS__ . '::new_method()' );
    $this->new_method();
}
```

### Decision rule
| Module | Use |
|--------|-----|
| `class-plugin.php` core methods | `_deprecated_function()` |
| `payment-gateway/` | `wc_deprecated_function()` |
| `compatibility/` (WC-specific) | `wc_deprecated_function()` |
| `class-helper.php`, `bootstrap.php` | `_deprecated_function()` |

## Related
- [class-plugin.php](../../woodev/class-plugin.php) — Lines 1486–1630: all deprecations use wc_deprecated_function
- [AGENT-RULES.md](../AGENT-RULES.md) — Rule 0: Backward Compatibility (shows _deprecated_function pattern)
- [FUTURE-BACKLOG.md](../FUTURE-BACKLOG.md) — Framework Decoupling (v2.0.0): remove WC dependency
