# Gotcha: [bootstrap/singleton-instantiation] — Bootstrap is singleton, constructor is private
> Tags: bootstrap, singleton, instantiation | Session: s2

## What happens
Using `new Woodev_Plugin_Bootstrap()` causes a **PHP fatal error** — the constructor is `private`. Each plugin that bundles the framework would create a separate bootstrap instance, breaking the single-source-of-truth multi-version selection mechanism.

## Root cause
`Woodev_Plugin_Bootstrap` uses the singleton pattern. The `__construct()` is `private` and hooks into `plugins_loaded` and `admin_init`. Multiple plugins sharing the same framework MUST register with the same bootstrap instance so that version comparison works correctly. Only one `class-plugin.php` is loaded — the highest version.

## Fix

❌ **Wrong:**
```php
// PHP Fatal error: Call to private Woodev_Plugin_Bootstrap::__construct()
$bootstrap = new Woodev_Plugin_Bootstrap();
$bootstrap->register_plugin( '1.4.1', 'My Plugin', __FILE__, $callback );
```

✅ **Correct:**
```php
// Singleton accessor — safe to call from every plugin
$bootstrap = Woodev_Plugin_Bootstrap::instance();
$bootstrap->register_plugin( '1.4.1', 'My Plugin', __FILE__, $callback );
```

## Related
- [bootstrap.php](../../woodev/bootstrap.php) — Bootstrap source (constructor line 39, instance line 49)
- [AGENT-RULES.md](../AGENT-RULES.md) — Rule 3: Singleton Bootstrap
