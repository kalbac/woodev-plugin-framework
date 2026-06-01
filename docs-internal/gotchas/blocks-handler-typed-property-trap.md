# Gotcha: [php/blocks-handler-typed-property-trap] — Non-nullable typed return can TypeError for pure-WordPress plugin subclasses
> Tags: php, typed-properties, type-system, woocommerce-conditional, base-class
> Discovered: 2026-06-01 (independent audit)

## What happens
A pure-WordPress plugin that extends `Woodev_Plugin` (the platform-neutral base) and calls `$this->get_blocks_handler()` triggers a PHP `TypeError: Typed property Woodev_Plugin::$blocks_handler must not be accessed before initialization`. The method is declared with a non-nullable return type that promises a fully-initialized handler, but the base constructor never sets the property — only `Woodev_Woocommerce_Plugin::init_blocks_handler()` does, and only after `parent::__construct()` returns.

### Where it is now
- `woodev/class-plugin.php:71` — `protected Woodev_Blocks_Handler $blocks_handler;` (typed, no default value)
- `woodev/class-plugin.php:1018-1020` — `public function get_blocks_handler(): Woodev_Blocks_Handler { ... return $this->blocks_handler; }` (non-nullable return)
- `woodev/class-woocommerce-plugin.php` — `init_blocks_handler()` is the only initializer; called from `__construct()` line 60+ after `parent::__construct()`

### Same trap elsewhere
Any typed property on `Woodev_Plugin` that is initialized only by `Woodev_Woocommerce_Plugin` subclasses (or other v2 platform subclasses) is at risk if a child class accesses it before its own `init_*` method runs. Search the base class for `protected ?Type $property;` (no default) where the only initializer is in a subclass — each one is a latent `TypeError` waiting to fire.

## Root cause
`Woodev_Plugin` is being positioned as a platform-neutral base for v2.0 (pure WordPress, WooCommerce, future EDD). The contract is "all public methods work in any platform context." But typed properties without `?` and without a default value, combined with a non-nullable return type on the getter, are a strong promise that the value is initialized. If only some subclasses initialize it, the base class has lied to the type system.

## Fix
Make the base class's property nullable with a default, and the getter's return type nullable to match:

```php
// ❌ Wrong — promises non-null but is not initialized in base
protected Woodev_Blocks_Handler $blocks_handler;

public function get_blocks_handler(): Woodev_Blocks_Handler {
    return $this->blocks_handler;
}
```

```php
// ✅ Correct — base class admits "may not be initialized yet"
protected ?Woodev_Blocks_Handler $blocks_handler = null;

public function get_blocks_handler(): ?Woodev_Blocks_Handler {
    return $this->blocks_handler;
}
```

Woocommerce_Plugin can either (a) keep the non-nullable version via an override that asserts non-null after `init_blocks_handler()`, or (b) keep the nullable contract — callers must check. **Option (a) is preferred** because it preserves the v1 contract for WC subclasses (and the realistic shipping fixture test already relies on this).

```php
// In Woocommerce_Plugin:
protected ?Woodev_Blocks_Handler $blocks_handler = null; // inherit, no override needed

public function get_blocks_handler(): ?Woodev_Blocks_Handler {
    return $this->blocks_handler; // null until init_blocks_handler() runs
}
```

Callers that need a non-null handler must guard:
```php
$handler = $this->get_blocks_handler();
if ( ! $handler ) {
    return; // or default
}
```

## Related
- [php/gateway-type-methods-required](gotchas/gateway-type-methods-required.md) — same family of base-class contract lies
- [php/namespace-migration-legacy-psr4](gotchas/namespace-migration-legacy-psr4.md) — same family of base-class contract lies
- [../platform-v2-implementation-spec.md](../platform-v2-implementation-spec.md) — section 9.1 (Woodev_Plugin owner responsibilities) and 9.2 (Woocommerce_Plugin owner responsibilities)
