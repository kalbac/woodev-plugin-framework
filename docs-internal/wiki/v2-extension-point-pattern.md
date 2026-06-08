# v2 Extension-Point Pattern: WooCommerce hook registration

> **Updated 2026-06-04 (P4 Task 5).** Earlier revisions of this article documented an
> empty `add_woocommerce_hooks()` stub on the platform-neutral base `Woodev_Plugin`
> as a positive extension point. That seam has been **removed**. The base now declares
> **no WooCommerce-named method at all** (audit §6.1.1 / P6 neutrality gate). This
> article describes the current shape: constructor-safe WooCommerce hooks are
> registered by `Woodev\Framework\Woocommerce_Plugin`; early feature
> declarations are registered by the bootstrap from loader metadata.

## Overview

`Woodev_Plugin` is the platform-neutral base. It must not reference WooCommerce
actions, filters, class names, or even method names. Constructor-safe
WooCommerce-specific hook registration is owned by the WooCommerce plugin class
`Woodev\Framework\Woocommerce_Plugin`, which wires those hooks at the end of its
**own** constructor — not via a callback on the base. The early
`before_woocommerce_init` compatibility declaration is different: it is wired by
`Woodev_Plugin_Bootstrap::register_loader_definition()` as soon as WooCommerce
loader metadata is registered, because the resolver may construct plugin
instances only on `plugins_loaded`.

## Details

The base `Woodev_Plugin::add_hooks()` wires only genuinely base-owned hooks
(`plugins_loaded → init_plugin`, `admin_init → init_admin`, enqueue hooks, the
plugin action-links filter, API request logging). It does **not** call into any
WooCommerce extension point.

`Woocommerce_Plugin::__construct()` calls a private `register_woocommerce_hooks()`
after `parent::__construct()` (and after `init_blocks_handler()`):

```php
// Woodev\Framework\Woocommerce_Plugin::__construct()
parent::__construct( $id, $version, $args );

$this->init_blocks_handler();

// Register WooCommerce runtime hooks owned by this WooCommerce plugin class.
$this->register_woocommerce_hooks();
```

```php
// Woodev\Framework\Woocommerce_Plugin::register_woocommerce_hooks()
private function register_woocommerce_hooks(): void {
    foreach ( [ 'shipping', 'checkout', 'integration' ] as $tab ) {
        add_action( 'woocommerce_before_settings_' . $tab, [ $this, 'add_class_form_wrap_start' ] );
        add_action( 'woocommerce_after_settings_' . $tab,  [ $this, 'add_class_form_wrap_end' ] );
    }

    add_filter( 'woocommerce_system_status_environment_rows', [ $this, 'add_system_status_php_information' ] );
}
```

These settings and system-status registrations are queue-only
(`add_action`/`add_filter`), so running them at the end of
`Woocommerce_Plugin` construction instead of via a base callback is timing-safe —
nothing fires until the corresponding admin/status hook runs later in the request.
The early `before_woocommerce_init` hook is intentionally **not** registered here.
Its HPOS/Blocks declarations use the loader definition's `plugin_file` and
`supported_features` metadata so WooCommerce can receive them before plugin
instances exist.

Subclasses `Woodev_Payment_Gateway_Plugin` and `Shipping_Plugin` extend
`Woocommerce_Plugin` and chain `parent::__construct()`, so they inherit this
registration automatically.

### Why this is the right shape for v2

- **Platform neutrality** — the base class declares no WooCommerce-named method and
  references no WooCommerce actions, filters, or class names. Enforced by
  `tests/unit/PlatformNeutralBaseHasNoWcMethodTest.php`.
- **Hook ownership follows the class** — the class that needs WooCommerce hooks is
  the one that registers late-safe runtime hooks, from its own construction. Early
  feature declarations are loader metadata, not instance runtime hooks. There is
  no base seam to override and no callback graph for the resolver to track.
- **No flag in the constructor** — there is no `$is_wc_plugin` boolean. A plugin
  is a WooCommerce plugin iff it extends `Woocommerce_Plugin`.
- **Forward-compatible** — a future platform (e.g. EDD in v3) gets its own
  `Woodev_Edd_Plugin` base that registers its hooks from its own constructor. The
  platform-neutral `Woodev_Plugin` is never edited for new platform extensions.

### Anti-patterns to avoid

- **Re-introducing a WC-named stub on the base** (e.g. `add_woocommerce_hooks()`),
  even an empty one. The method name alone leaks platform knowledge into the
  neutral base; the guard test fails if any base method name contains
  "woocommerce".
- Adding a `$is_payment_gateway` / `$is_shipping_method` boolean to the base
  constructor and branching inside `add_hooks()`. This couples the base to plugin
  flavor flags.
- Overriding `add_hooks()` directly in a WC subclass to add WC-specific hooks. This
  forces the subclass to replicate the base's hook list and breaks when the base
  adds new hooks. Register WC hooks from the WC subclass constructor instead.
- Registering `before_woocommerce_init` from `Woocommerce_Plugin::__construct()`.
  The resolver may construct plugin instances on `plugins_loaded`, which can be
  too late for WooCommerce feature declarations.

## Related

- [Woodev\Framework\Woocommerce_Plugin::register_woocommerce_hooks()](../../../woodev/class-woocommerce-plugin.php) (source)
- [PlatformNeutralBaseHasNoWcMethodTest](../../../tests/unit/PlatformNeutralBaseHasNoWcMethodTest.php) — guard enforcing zero WC-named methods on the base
- [audit-2026-06-01.md §L-3](../audit-2026-06-01.md#l-3) — original observation that flagged the empty stub (now resolved by removing the seam)
