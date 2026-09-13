# Gotcha index — [bootstrap/*] Multi-version loading

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [bootstrap/loader] **A `Woodev_Loader::register()` definition may hold NO framework constant — the array is built before the bootstrap registers the autoloader, so `PLATFORM_WOOCOMMERCE` fatals. Pass `'woocommerce'`.** → [a-loader-definition-cannot-use-a-framework-class-constant](../gotchas/a-loader-definition-cannot-use-a-framework-class-constant.md) (s115)
- [bootstrap/singleton-instantiation] **Bootstrap is singleton, constructor is private.** → [singleton-instantiation](../gotchas/singleton-instantiation.md) (s2)
- [bootstrap/plugin-registration-timing] **register_plugin() must run before plugins_loaded.** → [plugin-registration-timing](../gotchas/plugin-registration-timing.md) (s2)
- [bootstrap/payment-gateway-conditional-load] **Payment gateway base class loads conditionally.** → [payment-gateway-conditional-load](../gotchas/payment-gateway-conditional-load.md) (s2)
- [bootstrap/multiversion-early-class-guards] **Guard and source early support classes.** → [multiversion-early-class-guards](../gotchas/multiversion-early-class-guards.md) (s4)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
