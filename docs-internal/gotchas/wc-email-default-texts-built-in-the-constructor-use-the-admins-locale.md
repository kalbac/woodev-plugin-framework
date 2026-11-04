# Gotcha: [woocommerce/emails] — a WC email's default subject/heading/body built with `__()` in the constructor use the ADMIN's locale
> Tags: woocommerce, emails, i18n, setup_locale | Session: s215 (04.11.2026)

## What happens

A Russian store whose administrator works in an English profile adds a tracking number; WooCommerce switches to the
buyer's `ru_RU` to send the mail, yet the subject and body stay English. Reproduced against real `WC_Email` with the
plugin's real `.mo` (tracking plugin PR #4): before the constructor `get_locale()`/`determine_locale()` was `ru_RU`/`en_US`,
at the intercepted send both were `ru_RU`, a fresh translation returned Russian — and the captured text was still English.
The `.po` catalogue tests stay green because they never switch locale.

## Root cause

`WC_Email::__construct()` runs once per request, in whatever locale is current (the admin's, or the request's), and the
strings assigned there (`$this->subject = __( … )`) are fixed from then on. `WC_Email::send()` calls `setup_locale()` AFTER
construction, so the switch cannot reach texts that were already translated. Framework card #1587 tracks the same shape
in `Delivery_Status_Emails::register_emails()` (rig defect D4, 2.0.25 rig: English shipment mails with WC's block email
editor on — investigated in #1586, pre-existing since 2.0.24, not changed).

## Fix

❌ **Wrong:**

```php
public function __construct() {
    $this->subject = __( 'Your order has been handed over', 'my-domain' ); // admin's locale, frozen
}
```

✅ **Correct** — resolve the default lazily, after `setup_locale()`, and keep the merchant's saved text untouched:

```php
public function get_default_subject() {
    return __( 'Your order has been handed over', 'my-domain' );
}

public function get_default_heading() {
    return __( 'Your order is on its way', 'my-domain' );
}

public function get_message( $order ) { // or wherever the body is read at send time
    return $this->get_option( 'message' ) ?: __( 'Default body…', 'my-domain' );
}
```

The regression test sets a different locale for the "admin" (before construction) and the "store" (at send) and asserts
the captured mail is in the store's locale.

## Related

- [get_plugin_name runs before init so a translated name warns](get-plugin-name-runs-before-init-so-a-translated-name-warns.md) — the other direction of the same timing problem.
- [wp locale switcher caches available languages at init](wp-locale-switcher-caches-available-languages-at-init.md) — what `switch_to_locale()` can and cannot see.
