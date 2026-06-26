# gotcha: integration tests — don't fire global admin hooks; `$menu`/`$submenu` accumulate across tests

**Namespace:** `[testing/integration]`
**Discovered:** s34 (2026-06-26, SP-1 settings page)

## Two traps, both green locally and on some CI matrix cells, red on others

### 1. Firing `do_action( 'admin_menu' )` in an integration test pulls in WooCommerce's callbacks → PHP deprecation **printed** → PHPUnit "unexpected output" → red

A settings-page menu test did `do_action( 'admin_menu' )` to exercise the full menu chain. The **assertions passed** (`Tests: 52, Assertions: 97`), but the job still failed:

```
Test code or tested code printed unexpected output:
Deprecated: Automatic conversion of false to array is deprecated in
  .../woocommerce/src/Admin/DataSourcePoller.php on line 138
```

Firing the global `admin_menu` action runs **every** registered callback, including WooCommerce's. On **WC 8.5.1 (WP 6.4)** and **WC latest-stable** that emits a PHP deprecation notice; on **WC 9.3.0 (WP 6.6)** it does not — so the same test was **green on 6.6, red on 6.4 + latest**. PHPUnit treats any printed output during a test as a failure (`printed unexpected output` → exit 1), so a passing assertion still reds the job.

**Fix:** never fire a broad global admin action to set up state. Call the specific method directly. For a submenu test, call `$registry->register_page()` (which only calls `add_submenu_page`) instead of `do_action('admin_menu')`. WP's own parent-menu promotion is core behaviour and does not need re-testing.

### 2. The admin `$menu` / `$submenu` globals are NOT reset between `WP_UnitTestCase` tests → stale entries leak

After fixing #1, the test read the *wrong* `$submenu['woodev']` entry: an earlier test (`test_menu_registers_when_provider_present`, admin + a `manage_options`-only provider) had already pushed a `woodev-settings` entry under `manage_options`. `$submenu` persists across test methods, so the later shop-manager test (page cap `manage_woocommerce`) found the **stale first** entry and asserted `manage_options` ≠ `manage_woocommerce`.

```php
// WP submenu row shape: [ $menu_title, $capability, $menu_slug, $page_title, ... ]
global $submenu;
unset( $submenu['woodev'] );   // isolate from any prior test's registration
$registry->register_page();
// now $submenu['woodev'] holds only THIS test's entry
```

**Fix:** clear the relevant `$menu`/`$submenu` key before registering, or assert tolerantly (collect ALL caps for the slug and assert the expected one is among them) — never assume the first match is yours.

## Why local + unit were clean

`composer test:unit` (Brain Monkey, no WP) never loads WC and stubs `add_action`/`add_submenu_page` to no-ops, so neither trap fires. These only surface in the real WP integration matrix — and only on the WC versions that emit the deprecation. Integration runs on CI (no local `WP_TESTS_DIR`), so the loop is: push → read the failed matrix cell's log → fix → re-push.

## Related

- [[settings-api-control-save-path-pitfalls]] — the SP-1 settings handler save path this page reuses.
- [[phpstan-windows-parallel-worker-segfault]] — the other "CI is the real gate, not local" case.
