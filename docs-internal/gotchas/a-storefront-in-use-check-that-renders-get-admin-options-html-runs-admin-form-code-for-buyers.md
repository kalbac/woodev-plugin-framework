# Gotcha: [woocommerce/shipping-method] — a storefront check that renders `get_admin_options_html()` runs admin-form code (and its remote calls) for buyers

> Tags: woocommerce, shipping-method, storefront, admin-form, ozon | Session: s219 (06.11.2026)
> Found by a rig trace of the city-mode checkout; filed as `woodev-ozon-delivery#92`.

## What happens

On a storefront request (cart, checkout, a rates recalculation) an extra remote call or a slow admin-only lookup shows up that
no checkout code asked for. In the Ozon plugin the cause is `Ozon_Method_Point::in_use`: to decide whether the method is in use
it renders **every** shipping method's `get_admin_options_html()` and inspects the result. That function is admin-form code:
it builds settings fields, and the fields of our methods can reach the network (pickup-point lists, account lookups).

## Root cause

`WC_Shipping_Method::get_admin_options_html()` is meant to run only inside the shipping-zone admin screen. Using its output as a
data source ("is option X set?") moves it onto the storefront, where it runs for every buyer on every request the check runs.

## Fix

❌ Wrong — ask a method whether it is in use by rendering its settings form:

```php
$html = $method->get_admin_options_html();
$used = false !== strpos( $html, '<marker>' );
```

✅ Correct — read the stored setting (`$method->get_option( 'key' )`) instead of the rendered form; a stored value touches no form
code.

When tracing an unexpected storefront remote call, grep the plugin for `get_admin_options_html`, `generate_*_html` and
`init_form_fields` calls that are not behind `is_admin()`.

## Related

- [package-hash-ignores-the-phone-so-phone-rates-never-re-run](package-hash-ignores-the-phone-so-phone-rates-never-re-run.md) — another storefront-request trap in shipping-method code
- [Session s219](../sessions/s219.md) — where it was found
