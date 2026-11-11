# Gotcha: [lifecycle/fresh-install] — a menu or settings builder that reads plugin tables on `rest_api_init` fatals on a fresh install (ozon-seller #68)
> Tags: fresh-install, rest_api_init, lifecycle, tables, marketplace-menu | Session: s227

## What happens

A fresh install of `woocommerce-ozon-seller` on the framework 2.1.1 core fatals: wp-admin and WP-CLI (with WC active) both die. The
chain: `rest_api_init` → `Mappings_Module::controller()` → `Marketplace_Plugin::get_marketplace_admin_menu()` → `build_menu()` →
`Plugin::get_settings_providers()` → `Settings_Handler::get_settings_provider()` → `Notifications_Section::sections()` →
`Notification_Account_Reader::all()` throws `Account_Read_Exception` (table `wp_woodev_ozon_accounts` missing).

## Root cause

The lifecycle creates the tables only on `wp_loaded` in admin. With no table yet, nothing ever reaches that point, because the code
that reads the table already ran earlier, on `rest_api_init`, and threw. Unit and WP tests never see it: their tables always pre-exist.
A framework-side half may exist (`build_menu()` reading data before install) — check it when #68 is fixed.

## Fix

Build menus and settings sections without reading plugin tables on early hooks (`rest_api_init`, `init`, `admin_menu` priority before
the install step): defer the data read to the request that renders the section, or let a missing table mean «empty». Add an
install-from-an-empty-database test that boots the plugin with no tables.

```php
// ❌ early hook, table may not exist yet
add_action( 'rest_api_init', fn() => $this->menu()->build_menu() ); // reads accounts

// ✅ the builder works with no tables; data is read when the section renders
```

## Related

- [lifecycle-install-upgrade-detection](lifecycle-install-upgrade-detection.md)
- [a-stubbed-wc-class-hides-a-boot-fatal](a-stubbed-wc-class-hides-a-boot-fatal.md)
