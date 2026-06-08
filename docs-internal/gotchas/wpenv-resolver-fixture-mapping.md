# Integration fixtures need the framework mapped at the bootstrap's load path, not just wp-content

> [testing/integration] — discovered 2026-06-08 fixing PR #20 Integration tests.

## The trap

The platform-v2 `Framework_Resolver::load_plugins()` loads the **selected plugin's bundled**
copy:

```php
require_once $this->get_plugin_path( $plugin['path'] ) . '/woodev/class-plugin.php';
```

`get_plugin_path()` is `untrailingslashit( plugin_dir_path( $file ) )` — i.e. the directory of
the registered plugin file. For the test fixtures, that file is
`tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php`, so the resolver requires
`tests/_fixtures/woodev-test-plugin/woodev/class-plugin.php`. That `woodev/` subdir is **empty**
(the fixtures don't commit a framework copy).

`.wp-env.json` *does* map the repo's `./woodev` into each fixture — but only at the
`wp-content/plugins/woodev-test-*/woodev` mount. The PHPUnit bootstrap `require_once`s the
fixtures from the **`woodev-framework: .`** mount (`/var/www/html/woodev-framework/tests/_fixtures/…`),
where there is **no** `woodev/` mapping. Result on CI:

```
Failed opening required '.../tests/_fixtures/woodev-test-plugin/woodev/class-plugin.php'
```

It passed on `main` only because the *old* resolver loaded `class-plugin.php` early (so
`class_exists('\Woodev_Plugin')` short-circuited the per-plugin require). The v2 lazy resolver
removed that, exposing the missing bundled copy.

## Fix

Add the `woodev/` mapping at the path the bootstrap actually loads from, in **both** mapping
blocks of `.wp-env.json` (top-level **and** `env.tests` — integration uses `env.tests`):

```json
"woodev-framework/tests/_fixtures/woodev-test-plugin/woodev":          "./woodev",
"woodev-framework/tests/_fixtures/woodev-test-payment-gateway/woodev": "./woodev",
"woodev-framework/tests/_fixtures/woodev-test-shipping-method/woodev": "./woodev"
```

wp-env supports nested mappings (a sub-path mapping overlaying a parent mount), as the existing
`wp-content/plugins/*/woodev` entries already demonstrate.

## What did NOT work

A `symlink()`/copy in `tests/bootstrap.php` at runtime — the wp-env volume is **not writable**
from the tests-cli container at test time, so `@symlink` silently failed. The `.wp-env.json`
mapping is the proper, existing mechanism.

## Why it matters

Unit tests don't hit this: they use a testable resolver that overrides `get_plugin_path()`.
Only the real integration load path requires each fixture to bundle `woodev/`.

## How to apply

- A new test fixture loaded via the real resolver needs `./woodev` mapped at
  `woodev-framework/tests/_fixtures/<fixture>/woodev` in `.wp-env.json` (both blocks).
- `composer check` (unit) will NOT catch a broken integration fixture mapping; only the
  wp-env integration job will.

## Related

- [[ci-failing-gate-skips-dependent-jobs]] — other PR #20 CI root causes
