# class_exists() "init-once" guards break under the runtime class-map autoloader

**Namespace:** `[framework/autoload]`
**Discovered:** s35 (2026-06-26), by operator rig-testing the SP-1 settings page (top-level «Woodev» menu was missing).

## Symptom

The top-level **«Woodev»** admin menu (parent of *Licenses*, *Плагины*, *Настройки*)
did not render at all. The pages were reachable only by direct URL
(`admin.php?page=woodev-settings`). Affects **every real v2 plugin** booted through
the s27 runtime class-map autoloader — i.e. all shipped plugins — so it was
release-blocking, not fixture-only.

## Root cause

`Woodev_Plugin::load_admin_pages()` used `class_exists()` as a "has the shared
admin-pages object been initialized once across the fleet?" signal:

```php
// BROKEN
if ( is_admin() && ! class_exists( 'Woodev_Admin_Pages' ) ) {
    $admin_pages = $this->load_class( '/woodev/admin/class-admin-pages.php', 'Woodev_Admin_Pages' );
    $admin_pages->instance( $this ); // adds the admin_menu hook that builds the parent menu
}
```

`Woodev_Admin_Pages` is in `woodev/class-map.php`, and `class_exists($name)` defaults
to **autoload on**. The s27 runtime `spl_autoload` resolves the class on demand, so
`class_exists( 'Woodev_Admin_Pages' )` **always returns true** → `! true` → the block
never runs → `instance()` is never called → no `admin_menu` hook → no parent menu.

The guard conflated two different questions:
- *"Is this class defined/loadable?"* → what `class_exists()` answers (and, with a
  class-map autoloader, the answer is unconditionally yes).
- *"Have we already run this one-time fleet-wide init?"* → what the code actually meant.

## Why tests/CI missed it

- The unit suite (Brain Monkey) and any Composer-preloaded context make
  `class_exists()` true regardless, so the guard behaves identically — no test
  exercised the `instance()` call, and none asserted the menu actually registers.
- No v2 plugin's **admin menu** had been opened in a browser since the s27 runtime
  autoloader landed (the license page was last rig-checked in s14/s21, pre-s27).
- The integration env is a *real* WP boot: there `class_exists( 'X', false )` is
  **false** until autoloaded, but `class_exists( 'X' )` (autoload on) is **true** —
  which is exactly the production-faithful condition. Assert with autoload **on** to
  reproduce the guard's evaluation.

## Fix

Track the one-time init with an explicit static flag, never with `class_exists()`:

```php
private static $admin_pages_initialized = false;

private function load_admin_pages() {
    if ( is_admin() && ! self::$admin_pages_initialized ) {
        $admin_pages = $this->load_class( '/woodev/admin/class-admin-pages.php', 'Woodev_Admin_Pages' );
        $admin_pages->instance( $this );
        self::$admin_pages_initialized = true;
    }
}
```

The flag lives on the (highest-version) `Woodev_Plugin` class shared by the whole
fleet, so the init still happens exactly once across all active plugins.

## Rule

- **Never use `class_exists()` (or `function_exists()`) as a "did I already do this
  once?" flag** when the symbol is autoloadable. With the runtime class-map autoloader
  every framework class is autoloadable, so such a guard is always true. Use a static
  boolean / singleton flag for once-only initialization.
- File-level *class-definition* guards (`if ( ! class_exists( 'X' ) ) : class X … endif;`)
  are a different, correct pattern — they guard redefinition, not init-once. Leave them.

## Related

- [[framework-classmap-autoload-vendored-boot]] — class-map completeness on a real vendored boot (s27).
- [[integration-test-global-admin-hooks-output-and-submenu-accumulation]] — testing admin menu registration without firing broad global hooks (s34).
- Regression test: `tests/integration/AdminMenuTest.php`.
