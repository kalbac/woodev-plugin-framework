# A stubbed WooCommerce class hides a boot fatal

**Namespace:** `[testing/phpunit]` · **Discovered:** s193 (2026-10-22), Russian Post v2 #49 (found on the rig by P19b)

## The trap

A unit-test bootstrap that defines stand-ins for WooCommerce classes (`WC_Email`, `WC_Shipping_Method`, …) makes every class
that `extends` them loadable at any moment. On a real site those parents exist only after WooCommerce loads them — `WC_Email`
is required when `WC_Emails` is instantiated, well after `plugins_loaded`. So a plugin that autoloads its `extends \WC_Email`
subclass during `plugins_loaded` fatals on EVERY request (front, admin, WP-CLI — `wp-env start` cannot finish), while phpunit,
phpcs and phpstan are all green.

Seen in s193: `Plugin::init_plugin()` called the static `Russian_Post_Tracking_Email::register()`. A static call autoloads the
file, the file declares `final class … extends \WC_Email`, and `Class "WC_Email" not found` killed the site. The
`class_exists( '\WC_Email' )` guard sat inside the `woocommerce_email_classes` filter — too late, because the static call had
already loaded the file.

## ❌ Wrong

```php
// plugins_loaded
Russian_Post_Tracking_Email::register(); // autoloads a class that extends WC_Email
```

## ✅ Correct

```php
// plugins_loaded — a registrar with NO WooCommerce parent owns the ids, the filter and the listeners
Russian_Post_Tracking_Email_Registrar::register();

// inside woocommerce_email_classes, the first place the subclass is touched
$emails[ Russian_Post_Tracking_Email_Registrar::ID ] = new Russian_Post_Tracking_Email();
```

## How to catch it

A boot test that requires the plugin's main file and runs `init_plugin()` with the WooCommerce stand-ins NOT defined (the plugin
now has `tests/unit/PluginBootTest.php`), and a rig activation (`wp plugin activate` + one front request) before any acceptance.
Grep for `extends \WC_` / `extends WC_` and check that nothing on the `plugins_loaded` path reaches those classes — static calls
and constants count, not only `new`.

## Related

- [a-mockery-alias-mock-lives-for-the-whole-phpunit-process](a-mockery-alias-mock-lives-for-the-whole-phpunit-process.md)
- [mockery-mock-new-method-full-suite](mockery-mock-new-method-full-suite.md)
