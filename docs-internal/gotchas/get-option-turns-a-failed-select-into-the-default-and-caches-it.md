# Gotcha: [php/options] — `get_option()` turns a failed SELECT into the default AND caches it as absent
> Tags: php/options, lifecycle/migration | Session: s158

## What happens

A one-time migration reads an option, the database query fails for a moment, and the migration
proceeds as if the option did not exist: it derives a default (a pickup method became a courier),
skips a copy, or rewrites its own journal from `[]`. The version then advances and the run never
repeats. A later healthy read in the same request still says "absent".

## Root cause

`get_option()` does not distinguish an SQL error from a missing row. On a failed `SELECT` it
returns the `$default` and adds the name to the `notoptions` cache, so the rest of the request
keeps seeing the false absence. `$wpdb->last_error` is overwritten by the next query, so checking
it later is too late. Measured by the s158 critic against the upstream `wp-includes/option.php`.

## Fix

❌ Derive behaviour from a plain read in a run that executes once:

```php
$settings = get_option( "woocommerce_edostavka_{$id}_settings", [] ); // error == "no settings"
```

✅ Read prerequisites through a verified reader: a direct `$wpdb` row read, `last_error` checked
immediately, the false `notoptions` entry removed, and a retryable exception thrown BEFORE any
default is derived or anything is written:

```php
$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
if ( '' !== $wpdb->last_error ) {
	wp_cache_delete( 'notoptions', 'options' ); // or drop just this name
	throw new Migration_Exception( 'option read failed' );
}
```

Autoloaded options (e.g. WooCommerce unit settings) come from `alloptions` loaded at bootstrap; a
failure there means the site is down, so they do not need this guard.

## Related

- CDEK plugin `includes/lifecycle/class-cdek-wpdb-option-reader.php` — the worked implementation.
- [../gotcha-index/php.md](../gotcha-index/php.md)
