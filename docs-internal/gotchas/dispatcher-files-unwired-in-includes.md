# New framework class files must be wired into `includes()`, not just the Composer classmap

> Namespace: `framework/*` — added session 2 (2026-06-09)

## Context

> **Updated s210:** since s27 the framework has a runtime classmap autoloader
> (`Woodev_Framework_Autoloader`, `woodev/class-map.php`), so a class missing from the map — not a
> missing `require_once` — is what fatals in a real boot. `includes()` still exists and still matters for
> load order. The text below is the original s2 account.

The Composer `autoload.classmap` in `composer.json` lists whole framework directories
(`woodev/box-packer`, `woodev/shipping-method`, …). During **unit tests** every class in
those dirs autoloads on demand, so a new class "just works" in tests. In **production**,
WordPress has no Composer autoloader for the framework — a class loads only through
`woodev/class-map.php` or an explicit `require_once` in `Woodev_Plugin::includes()` (or a
plugin-variant `includes()`).

## The gotcha

Adding a new framework class file (a new feature, controller, DTO, interface) and proving
it with unit tests is **not enough**. If it is never `require_once`'d in the appropriate
`includes()`, it is unreachable in production — a real plugin calling it fatals with
"class not found", while the whole test suite stays green (the classmap masks the gap).

Concrete instances this session:
- `Woodev_Packer_Dispatcher` + `Woodev_WC_Packer_Dispatcher` + the packer contract classes
  existed (PR #21) but were never required by `Woodev_Plugin::includes()`.
- `Abstract_Warehouses_Controller` was never required by `Shipping_Plugin::includes()`.

Both passed 200+ tests yet would have fataled in production.

## Correct

- When you add a framework class, regenerate the map (`php bin/generate-class-map.php`);
  `ClassMapCompletenessTest` gates it — see
  [framework-classmap-autoload-vendored-boot](framework-classmap-autoload-vendored-boot.md).
- Add its `require_once $framework_path . '/…';` to the right `includes()` only where load
  order matters, in **dependency order** (interfaces before implementers).
- Gate WooCommerce-coupled files behind `Woodev_Helper::is_woocommerce_active()` (or load
  them via the resolver's WC-base block) so pure-WP plugins don't pull WC-named classes.
- Add a **source-assertion test** that reads the `includes()` source and asserts the
  `require_once` lines exist (mutation-verified). See `BoxPackerDispatcherWiringTest`.

## Incorrect

- Relying on the Composer classmap to "prove" a class loads — it only proves it in tests; the production gate is `woodev/class-map.php`.
- Same failure class as the s1-p6 "module left unwired" holistic-review headline.

## Related

- [wpenv-resolver-fixture-mapping](wpenv-resolver-fixture-mapping.md) — the integration-test analogue (fixture not mapped
  at the bootstrap's load path).
- [framework-classmap-autoload-vendored-boot](framework-classmap-autoload-vendored-boot.md) — the runtime classmap that is the real production gate.
- Sibling lesson: the P2 pilot-gate hardening ("no Composer-autoload include-order masking").
