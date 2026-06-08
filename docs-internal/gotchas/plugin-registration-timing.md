# Gotcha: [bootstrap/plugin-registration-timing] — register_plugin() must run before plugins_loaded
> Tags: bootstrap, registration, timing, order | Session: s2

## What happens
If `register_plugin()` is called after `plugins_loaded` fires, the plugin is never loaded, never initialized, and never appears in the active plugins list. There is **no error or warning** — the plugin simply doesn't work.

## Root cause
`Woodev_Plugin_Bootstrap` hooks its `load_plugins()` method to the `plugins_loaded` action (priority 10 by default). Inside `load_plugins()`, it iterates over `$this->registered_plugins` — the array populated by `register_plugin()`. If registration happens after `plugins_loaded` has already fired, the array is empty when `load_plugins()` runs.

Registration typically happens during file inclusion (at plugin load time, before `plugins_loaded`), which is the correct pattern.

## Fix

❌ **Wrong:**
```php
// Plugin main file
add_action( 'plugins_loaded', function() {
    // TOO LATE — load_plugins() already ran
    Woodev_Plugin_Bootstrap::instance()->register_plugin(
        '1.4.1', 'My Plugin', __FILE__, $callback
    );
}, 5 ); // Even priority 5 won't help if the bootstrap registered at default 10
```

✅ **Correct:**
```php
// Plugin main file — at file level, before any hooks fire
require_once __DIR__ . '/woodev/bootstrap.php';

Woodev_Plugin_Bootstrap::instance()->register_plugin(
    '1.4.1', 'My Plugin', __FILE__, $callback
);
// Registration happens during file inclusion, long before plugins_loaded
```

## Related
- [bootstrap.php](../../woodev/bootstrap.php) — `load_plugins()` at line 78, hooked at line 40
- [singleton-instantiation.md](singleton-instantiation.md) — Bootstrap singleton pattern
