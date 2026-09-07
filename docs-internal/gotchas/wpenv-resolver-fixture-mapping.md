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

## s124 (#814): the mapping is only HALF of "mount a fixture", and the other half comes first

Mounting `woodev-realistic-shipping-plugin` into the integration environment needed **two**
changes, and the card that asked for it named neither correctly:

1. **A `require_once` in `tests/bootstrap.php`.** Nothing loads a fixture implicitly. The suite
   requires each one by path out of the `woodev-framework: .` mount, so the
   `wp-content/plugins/<fixture>` mappings — which is what the card proposed — are what the DEV
   RIG needs and have no bearing on integration at all.
2. **Then** the `woodev/` mirror this gotcha is about.

⚠ **Do them in that order and the second failure is spectacular out of proportion to its cause.**
The resolver's require is guarded by `class_exists( '\Woodev_Plugin', false )`, so it fires for
whichever registered plugin it reaches FIRST. Add one unmapped fixture and it can be that one —
at which point the bootstrap dies before a single test runs, and every test in the suite is red
with a message naming `class-plugin.php`, which reads as a broken `vendor` or a broken framework.
Three correctly mapped fixtures next to it do not save you.

**A mapping change needs `npx wp-env start`** — mappings are bind mounts, so editing the JSON
alone changes nothing and the same fatal persists, which is easy to misread as the fix not
working. Measured s124: the restart left rig state byte-identical (options, 11 popular-settlement
rows, five zone-method instances, active plugins, the `zz-rig-yandex-key` mu-plugin) and the
container-name prefix unchanged, so the documented `docker exec … -tests-cli-1` command still
worked afterwards. Snapshot before and diff after anyway — that is what makes "it still works" a
comparison instead of an impression.

Measured cost of actually mounting it: the full suite went 156 → 163 tests with **no existing
test disturbed**. The card's stated worry — that tests counting registered shipping methods would
break — did not materialise, but it was the right thing to check first.

## Related

- [[ci-failing-gate-skips-dependent-jobs]] — other PR #20 CI root causes
