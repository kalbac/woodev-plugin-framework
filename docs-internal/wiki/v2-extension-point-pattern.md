# v2 Extension-Point Pattern: `add_woocommerce_hooks()`

## Overview

`Woodev_Plugin::__construct()` ends `add_hooks()` with a call to
`add_woocommerce_hooks()`, which is an **empty no-op on the base class**
and is the only overridden implementation in `Woodev_Woocommerce_Plugin`.
The pattern is the cleanest possible v2 extension point: every
WooCommerce-specific hook lives in the WC subclass, and the base class
remains platform-neutral.

## Details

The base-class body is one line:

```php
protected function add_woocommerce_hooks(): void {}
```

The method exists solely so the base `add_hooks()` can call it
unconditionally. Without it, every plugin that needed WC hooks would
have to override `add_hooks()` itself and replicate the rest of the
base-class hook list — error-prone and brittle.

The WC subclass overrides the method to install only the hooks that
require a WC runtime:

```php
// Woodev_Woocommerce_Plugin::add_woocommerce_hooks()
protected function add_woocommerce_hooks(): void {
    add_action( 'before_woocommerce_init', [ $this, 'handle_features_compatibility' ] );

    foreach ( [ 'shipping', 'checkout', 'integration' ] as $tab ) {
        add_action( 'woocommerce_before_settings_' . $tab, [ $this, 'add_class_form_wrap_start' ] );
        add_action( 'woocommerce_after_settings_' . $tab,  [ $this, 'add_class_form_wrap_end' ] );
    }

    add_filter( 'woocommerce_system_status_environment_rows', [ $this, 'add_system_status_php_information' ] );
}
```

### Why this is the right shape for v2

- **Platform neutrality** — the base class never references WooCommerce actions, filters, or class names.
- **No flag in the constructor** — there is no `add_woocommerce_hooks( $is_wc_plugin = true )` boolean. The override either exists (WC subclass) or doesn't (pure-WP subclass).
- **No callback graph to maintain** — the resolver/loader never has to know which hooks are WC-specific. The inheritance tree carries that information.
- **Forward-compatible** — future platforms (e.g. EDD in v3) would add a sibling empty `add_edd_hooks()` method on the base, overridden by a future `Woodev_Edd_Plugin` subclass. The base never has to be edited for new platform extensions.

### Anti-patterns to avoid

- Adding a `$is_payment_gateway` / `$is_shipping_method` boolean to the base constructor and branching inside `add_hooks()`. This couples the base to plugin flavor flags.
- Overriding `add_hooks()` directly in a WC subclass to add WC-specific hooks. This forces the subclass to replicate the base's hook list and breaks when the base adds new hooks.
- Moving the empty stub to a trait. Traits don't solve any problem here — the inheritance tree is already the right abstraction. A trait would add ceremony for no gain.

## Related

- [Woodev_Plugin::add_woocommerce_hooks()](../phpdoc/classes/Woodev-Plugin.html) (PHPDoc, when published)
- [Woodev_Woocommerce_Plugin::add_woocommerce_hooks()](../../../woodev/class-woocommerce-plugin.php) (source)
- [audit-2026-06-01.md §L-3](../audit-2026-06-01.md#l-3) — original observation calling out the empty stub as a positive pattern
