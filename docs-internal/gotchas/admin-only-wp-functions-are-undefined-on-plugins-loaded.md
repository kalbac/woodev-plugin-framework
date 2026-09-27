# gotcha: `deactivate_plugins()` & co. are undefined on `plugins_loaded` — and Brain Monkey hides it

**Namespace:** `[php/wp-load-order]`
**Discovered:** s141 (2026-09-27), #916 critic round 1 (Claude Opus 5)

## What happens

`deactivate_plugins()`, `is_plugin_active_for_network()` and the rest of `wp-admin/includes/plugin.php`
are NOT loaded when `plugins_loaded` fires: `wp-settings.php` fires `plugins_loaded`, and only later
does `wp-admin/admin.php` require `includes/admin.php`. Code that calls them from a `plugins_loaded`
path fatals — here, on EVERY admin page once two active plugins shared a download id, so the admin
could not even reach plugins.php to recover (FTP only).

Every unit test was green, because Brain Monkey DEFINES the stubbed function: in the test process
`deactivate_plugins` exists. The rig probe was green too, because it exercised the activation path,
where WP has already loaded plugin.php.

## ❌ Wrong

```php
// runs inside Framework_Resolver::load_plugins(), hooked to plugins_loaded
if ( is_admin() && current_user_can( 'activate_plugins' ) ) {
	deactivate_plugins( $plugin_file ); // Call to undefined function on a normal admin page load
}
```

## ✅ Correct

Defer to `admin_init` (plugin.php is loaded by then), or `require_once ABSPATH . 'wp-admin/includes/plugin.php'`
first — the pattern `account/class-account-installer.php` and `license-command-deactivate-plugin.php`
already use, and WooCommerce's `Packages::deactivate_merged_packages()` too. And test the RUNTIME
path, not the stub: a rig probe must hit the code path itself (here: both duplicates already active,
then a plain admin page load), not a neighbouring path that happens to load the same file.

## Related

- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — the same family: the stub proves the stub
- [brain-monkey-function-pollution](brain-monkey-function-pollution.md) — why a stubbed function exists for the whole test process
