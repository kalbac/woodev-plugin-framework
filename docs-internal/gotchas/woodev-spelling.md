# Gotcha: [naming/woodev-spelling] — woodev (single 'd'), NEVER wooddev
> Tags: naming, spelling, convention | Session: s2

## What happens
Using `wooddev` (double 'd') instead of `woodev` causes silent failures: `class_exists('Woodev_Plugin')` returns false, autoloaders miss files, hooks don't fire, and plugin registration fails with no visible error.

## Root cause
The project name is "Woodev" — derived from "Woo" + "dev". It's a single 'd'. Every identifier in the codebase — class names, hook prefixes, file names, text domains, option keys — uses `woodev` with one 'd'. There is no `wooddev` anywhere.

## Fix

❌ **Wrong:**
```php
// Fatal or silent failure — class_exists returns false
if ( ! class_exists( 'Wooddev_Plugin' ) ) { ... }

// Hook will never fire
add_action( 'wooddev_plugins_loaded', ... );

// Text domain won't load
__( 'Hello', 'wooddev-plugin-framework' );
```

✅ **Correct:**
```php
// Always single 'd'
if ( ! class_exists( 'Woodev_Plugin' ) ) { ... }

add_action( 'woodev_plugins_loaded', ... );

__( 'Hello', 'woodev-plugin-framework' );
```

## Related
- [AGENT-RULES.md](../AGENT-RULES.md) — Architecture rules, naming conventions
- [GOTCHAS.md](../GOTCHAS.md) — Gotcha index
