# `Woodev_Plugin_Compatibility::is_enhanced_admin_available()` returns `true` unconditionally

> Namespace: `[php/wc-compat]` · Discovered s12 (2026-06-13)

## The trap

`woodev/compatibility/class-plugin-compatibility.php`:

```php
public static function is_enhanced_admin_available(): bool {
    return true;
}
```

It is a **hard-coded `true`** (legacy stub — every supported WC version has the
enhanced admin). So you **cannot** use it to gate WooCommerce-only code out of the
unit suite (which has no WooCommerce): the gated branch runs in unit tests too and
fatals on the missing WC class (a `new Automattic\WooCommerce\Admin\Notes\Note()`
throws `Error`, which `catch (Exception)` does **not** catch).

The existing payment-gateway note code "gets away with it" only because it is never
unit-tested — it runs solely in integration where WC exists.

## Correct guard

Gate WC-Admin-Notes (or any WC-class) code on the **class existence**, checked
**first**, before any accessor that a mocked object might not provide:

```php
if ( ! class_exists( '\Automattic\WooCommerce\Admin\Notes\Note' ) ) {
    return; // unit suite + pure-WP plugins stop here — no WC calls, no fatal
}
```

`Woodev_Notes_Helper::add_note()` and the deactivate command's
`maybe_add_breadcrumb_note()` / `clear_remote_deactivation_artifacts()` all use this
`Note`-class guard so the heavily mocked `LicenseCommandDeactivateTest` never reaches
the WC path. The platform-neutral option-clearing path is intentionally NOT behind
this guard (it must run regardless of WooCommerce).

## Related
- [[wc-note-breadcrumb-survives-deactivation]] — the feature that needed this guard
- SESSION-LOG s12 — remote-deactivation UX (Finding B)
