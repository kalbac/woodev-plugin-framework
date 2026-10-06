# `get_plugin_name()` runs before `init`, so a translated plugin name triggers WordPress's "too early" notice

**Namespace:** `[i18n/plugin-name]`
**Discovered:** s157 (06.10.2026), first activation of the new CDEK plugin (#786) on the rig

## What happens

Activating a plugin whose `get_plugin_name()` returns `__( '…', 'its-domain' )` logs, on every request:

```text
PHP Notice: Function _load_textdomain_just_in_time was called incorrectly. Translation loading for the
<code>woocommerce-edostavka</code> domain was triggered too early. … Translations should be loaded at the
init action or later.
```

`Woodev_Plugin::__construct()` builds the hook deprecator with `new Woodev_Hook_Deprecator( $this->get_plugin_name(), … )`
(`woodev/class-plugin.php`), and the constructor runs on `plugins_loaded` — before `init`. Since WP 6.7 any
translation call for a plugin domain before `init` raises the notice.

Harm is low (a debug-log notice, no behaviour change), but it repeats on every request and reads like a
plugin bug in a shop's log.

## ✅ Correct

Every in-repo fixture already does this — return a plain, untranslated brand name:

```php
public function get_plugin_name(): string {
	return 'CDEK WooCommerce Shipping Method';
}
```

❌ Wrong:

```php
public function get_plugin_name(): string {
	return __( 'СДЭК: доставка для WooCommerce', 'woocommerce-edostavka' );
}
```

Translate the name only where it is rendered after `init` (admin pages), not in `get_plugin_name()`.

## Related

- `woodev/class-plugin.php` — `Woodev_Plugin::__construct()`, the hook-deprecator line
- `.ai/skills/woodev-plugin-core/traps.md` — plugin-author trap list
