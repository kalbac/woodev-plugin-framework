# `Woodev_Plugin_Compatibility::is_enhanced_admin_available()` returns `true` unconditionally

> Namespace: `[php/wc-compat]` · Discovered s12 (2026-06-13)
>
> Context note: the s12 WC-Admin-Notes breadcrumb that first surfaced this was
> reverted (operator decision — a single-v2-plugin site intentionally shows no
> deactivation banner). The underlying trap below is permanent and applies to ANY
> future WooCommerce-gated code (e.g. the existing payment-gateway note code).

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

The framework's own guard is `WC_Admin_Notes_Renderer::is_available()`
(`woodev/competitor/class-wc-admin-notes-renderer.php`, `class_exists( Note::class )`). It keeps the
heavily mocked unit suite from reaching the WC path. The platform-neutral option-clearing path (the
deactivate command's `clear_remote_deactivation_artifacts()`) is intentionally NOT behind this
guard (it must run regardless of WooCommerce). `Woodev_Notes_Helper::add_note()` and
`maybe_add_breadcrumb_note()`, which an earlier version of this note named, no longer exist (the breadcrumb
note was reverted).

## Related
- [SESSION-LOG](../SESSION-LOG.md) (s12) — remote-deactivation UX (Finding B, since reverted)
- [class-payment-gateway-plugin.php](../../woodev/payment-gateway/class-payment-gateway-plugin.php) — live WC-note code that relies on this being true only inside a real WC context
