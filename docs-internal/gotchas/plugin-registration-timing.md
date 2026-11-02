# Gotcha: [bootstrap/plugin-registration-timing] — plugin registration must run before plugins_loaded
> Tags: bootstrap, registration, timing, order | Session: s2

> **Updated s210 (v2):** the timing rule still holds, but the call is no longer `register_plugin()` — that is a
> tombstone in v2 (it only quarantines a legacy v1 plugin). A v2 plugin entry file calls
> `Woodev_Loader::register( __FILE__, $definition )` at file level, which reaches
> `Woodev_Plugin_Bootstrap::register_loader_definition()`. The snippets below are rewritten accordingly.

## What happens
If registration is attempted after `plugins_loaded` fires, the plugin is never loaded, never initialized, and never appears in the active plugins list. There is **no error or warning** — the plugin simply doesn't work.

## Root cause
`Woodev_Plugin_Bootstrap` hooks its `load_plugins()` method to the `plugins_loaded` action (priority 10 by default). Inside `load_plugins()`, it iterates the loader definitions the resolver collected from `register_loader_definition()`. If registration happens after `plugins_loaded` has already fired, the set is empty (or missing this plugin) when `load_plugins()` runs.

Registration typically happens during file inclusion (at plugin load time, before `plugins_loaded`), which is the correct pattern.

## Fix

❌ **Wrong:**
```php
// Plugin main file
add_action( 'plugins_loaded', function() {
    // TOO LATE — load_plugins() already ran
    Woodev_Loader::register( __FILE__, $definition );
}, 5 ); // Even priority 5 won't help if the bootstrap registered at default 10
```

✅ **Correct:**
```php
// Plugin main file — at file level, before any hooks fire
require_once __DIR__ . '/woodev/loader.php';

Woodev_Loader::register( __FILE__, $definition );
// Registration happens during file inclusion, long before plugins_loaded
```

## Related
- [bootstrap.php](../../woodev/bootstrap.php) — `load_plugins()`, hooked on `plugins_loaded` in the constructor; `register_loader_definition()`
- [loader.php](../../woodev/loader.php) — `Woodev_Loader::register()`, the plugin-side entry
- [singleton-instantiation.md](singleton-instantiation.md) — Bootstrap singleton pattern
