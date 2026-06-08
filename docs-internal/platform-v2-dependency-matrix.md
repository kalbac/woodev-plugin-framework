# Platform v2 Dependency Matrix
> Date: 2026-05-28
> Scope: read-only audit for the v2 platform decoupling design. No PHP implementation decisions are made here.

## Inputs

- `PLANS.md` sections 2-3 and section 5 open questions.
- `docs-internal/FUTURE-BACKLOG.md` section "Framework Decoupling — Support Non-WooCommerce Plugins".
- Read-only source inspection of `woodev/`.

## Classification Rules

| Field | Meaning |
|-------|---------|
| WooCommerce dependency | Current source dependency: `yes`, `partial`, or `no`. |
| Target owner | Where the module should conceptually live after the platform split. |
| Priority | `P0` = spike before implementation spec, `P1` = v2.0.0 platform split, `P2` = post-v2. |

Notes:

- This matrix uses the class names from `PLANS.md`: `Woodev_Plugin`, `Woodev_Woocommerce_Plugin`, and future `Woodev_EDD_Plugin`.
- `FUTURE-BACKLOG.md` uses the descriptive names `Base_Plugin` and `WooCommerce_Plugin`; this document maps those to `Woodev_Plugin` and `Woodev_Woocommerce_Plugin`.
- Payment gateway internals, shipping UI, licensing webhooks, and the box-packer algorithm are intentionally not designed here. They are classified as post-v2 where applicable.

## Dependency Matrix

| Component | WC dependency | Target owner | Priority | Evidence / refactor note |
|-----------|---------------|--------------|----------|---------------------------|
| `bootstrap.php` | partial | `Woodev_Plugin` platform-neutral loader, with WC checks delegated to `Woodev_Woocommerce_Plugin` | P0 (spike) | Current loader checks `minimum_wc_version`, exposes `is_woocommerce_active()`, and conditionally loads payment/shipping modules via args. Its future role must be decided before a spec can be written. |
| `class-plugin.php` | yes | `Woodev_Plugin`; WC behavior extracted to `Woodev_Woocommerce_Plugin` | P0 (spike) | Current base class loads WC compatibility, order compatibility, WC Blocks handler, WC REST status hooks, WC settings wrappers, WC logger, and WC templates. This is the central split point. |
| root support classes (`class-helper.php`, `class-lifecycle.php`, admin notice/message handlers, dependencies, hook deprecator, exceptions) | partial | `Woodev_Plugin`, with WC helper sections moved behind `Woodev_Woocommerce_Plugin` or WC adapters | P1 (v2.0.0) | `class-helper.php` contains a dedicated WooCommerce helper section; lifecycle uses WC sanitization/deprecation helpers; admin handlers assume `manage_woocommerce` and `wc_enqueue_js()`. Pure exceptions/string helpers can remain base-level. |
| `admin/` | partial | `Woodev_Plugin`, with setup-wizard WC assets/capability behavior split or adapted | P1 (v2.0.0) | Main Woodev admin pages and licensing pages are WordPress admin concepts. The setup wizard is based on WooCommerce, defaults to `manage_woocommerce`, uses `wc_*` hook names, WooCommerce assets, and `woocommerce_form_field()`. |
| `api/` | partial | `Woodev_Plugin` | P1 (v2.0.0) | `PLANS.md` explicitly says this module moves under `Woodev_Plugin`. Current residual WC coupling is limited: request user-agent includes WC version when present and one deprecated method uses `wc_deprecated_function()`. |
| `assets/` | partial | Split by owning module: generic admin/license assets under `Woodev_Plugin`; checkout/shipping/WC-flavored assets under WC owners | P1 (v2.0.0) | Generic admin assets can be reused by pure WP plugins. Some CSS uses WooCommerce admin color variables; frontend Dadata suggestions are tied to WooCommerce checkout field names. |
| `box-packer/` | partial | remains WC-only module for v2; pure algorithm extraction/wrapper is post-v2 | P2 (post-v2) | `FUTURE-BACKLOG.md` classifies box-packer as WC-only for module isolation. `PLANS.md` notes the algorithm is theoretically universal but currently tied to WooCommerce and needs a wrapper. Algorithm redesign is explicitly out of scope here. |
| `compatibility/` | yes | `Woodev_Woocommerce_Plugin` | P1 (v2.0.0) | `class-order-compatibility.php` imports WooCommerce HPOS/admin classes and calls `wc_get_order()`. `class-plugin-compatibility.php` is mostly WC version/screen compatibility. |
| `handlers/` | partial | split: `script-handler.php` under `Woodev_Plugin`; `blocks-handler.php` under `Woodev_Woocommerce_Plugin` | P1 (v2.0.0) | Script handling is generic. Blocks handler imports WooCommerce Blocks classes and manages Cart/Checkout block compatibility, so it cannot load from a pure WP base class. |
| `languages/` | no runtime dependency | `Woodev_Plugin` | P2 (post-v2 cleanup) | Translation files include WooCommerce strings, but they do not create runtime coupling. Cleanup can follow the architecture split. |
| `licensing/` | partial | `Woodev_Plugin`; webhooks remain post-v2 feature work | P1 (v2.0.0) | License activation/update logic is platform-neutral in intent and uses the WooDev EDD store API. Current WC coupling is small: date helpers, `wc_strtolower()`, deprecated-argument helpers, and `woocommerce_screen_ids`. Server-to-client webhooks from `PLANS.md` are deferred. |
| `payment-gateway/` | yes | remains WC-only module | P2 (post-v2) | Payment gateway classes extend `WC_Payment_Gateway`, hook into WooCommerce checkout/account/order flows, and are explicitly excluded from design in this audit. For v2, only loader isolation is relevant. |
| `plugin-updater/` | no WC dependency | `Woodev_Plugin` | P1 (v2.0.0) | Uses WordPress plugin update filters and the WooDev EDD licensing API. It should be available to pure WP, WC, and future EDD plugins. |
| `rest-api/` | partial | `Woodev_Plugin`, with WC status integration delegated to `Woodev_Woocommerce_Plugin` | P1 (v2.0.0) | `PLANS.md` confirms API should stop depending on WooCommerce. Current REST handler hooks into `woocommerce_rest_prepare_system_status`, uses `wc/v3`, and settings controller uses `wc_rest_check_manager_permissions()`. Routes and permissions need a platform-neutral base. |
| `settings-api/` | partial | `Woodev_Plugin` | P1 (v2.0.0) | Typed settings are platform-neutral in purpose. Current coupling is limited to WC helper functions such as `wc_doing_it_wrong()`, `wc_bool_to_string()`, and `wc_string_to_bool()`. |
| `shipping-method/` | yes | remains WC-only module | P2 (post-v2) | Shipping classes extend `WC_Shipping_Method` and `WC_Integration`, read WooCommerce shipping settings, and hook into WooCommerce shipping methods/integrations. Shipping UI/scaffold work is explicitly deferred post-v2. For v2, only loader isolation is relevant. |
| `utilities/` | partial | `Woodev_Plugin`, with WC debug/session hooks guarded by WC adapter | P1 (v2.0.0) | Background jobs and async requests are generally WordPress-level utilities. Current coupling includes `woocommerce_debug_tools` and a WC session nonce workaround in background processing. |

## P0 Spike Items

1. Define whether `bootstrap.php` remains the multi-version resolver, becomes a minimal compatibility shim, or is replaced by a new loader.
2. Define the v2 base-class boundary: what stays in `Woodev_Plugin`, what moves to `Woodev_Woocommerce_Plugin`, and what is module-only.
3. Define the plugin type declaration mechanism before touching loaders, because it changes when payment/shipping classes are available.
4. Define the compatibility strategy for existing entry files that call `register_plugin()` with `is_payment_gateway` or `load_shipping_method`.

## P1 v2.0.0 Work Candidates

1. Split `Woodev_Plugin` into a pure WordPress base plus WooCommerce subclass behavior.
2. Keep `api/`, `settings-api/`, `licensing/`, `plugin-updater/`, and generic utilities available from `Woodev_Plugin`.
3. Move HPOS/order compatibility, WooCommerce Blocks support, WooCommerce status integration, WooCommerce logger/template helpers, and WC settings wrappers behind `Woodev_Woocommerce_Plugin`.
4. Make loader behavior platform-aware without requiring pure WP plugins to have WooCommerce active.
5. Preserve old public methods as wrappers where feasible during the v2 migration window, even if the final internal owner changes.

## P2 Post-v2 Work Candidates

1. Payment gateway internals and trait extraction.
2. Full shipping module boilerplate and shipping admin UI.
3. Licensing webhooks and server-to-client push actions.
4. Box-packer algorithm redesign or generic wrapper extraction.
5. Translation and asset cleanup after the class/module boundaries stabilize.

## Top 10 Risks for Production Plugins on the Old API

1. **Multi-plugin framework loading regression.** About a dozen plugins can load different vendored framework versions; changing `bootstrap.php` can break highest-version selection or activation order.
2. **Entry-file registration drift.** Existing plugins likely pass `minimum_wc_version`, `is_payment_gateway`, or `load_shipping_method` into `register_plugin()`. Removing or reinterpreting these args can produce missing base classes before plugin initialization.
3. **Constructor and hook order changes.** Existing plugins may rely on `Woodev_Plugin::__construct()` side effects: includes, license initialization, REST setup, blocks handler setup, admin pages, and hooks.
4. **Public method relocation.** Moving methods from `Woodev_Plugin` to `Woodev_Woocommerce_Plugin` can fatal old child classes or integrations that call helpers on the old base instance.
5. **Payment gateway base loading.** If `is_payment_gateway` is replaced by inheritance without a compatibility bridge, gateway plugins can fail before `Woodev_Payment_Gateway_Plugin` is loaded.
6. **Shipping plugin base loading.** Shipping plugins using `load_shipping_method` or namespaced `Shipping_Plugin` can fail if loader isolation changes before their entry files are migrated.
7. **License and updater behavior changes.** Moving licensing/updater code can break license activation, renewal notices, update checks, and beta update flags across production plugins.
8. **WooCommerce compatibility declarations disappearing.** HPOS and Cart/Checkout Blocks compatibility currently live in the base class. If they are moved incorrectly, production plugins may lose compatibility declarations.
9. **REST API namespace and permissions changes.** Current settings routes use WooCommerce REST namespace and permissions. Changing this can break admin screens, integrations, or tests that call those endpoints.
10. **Hook/filter name compatibility.** Existing customizations may depend on `wc_{plugin_id}_...`, WooCommerce settings hooks, system-status hooks, or deprecated hook wrappers. Renaming without wrappers creates silent behavior regressions.

## Open Question 1: Fate of `bootstrap.php`

### Option A: Keep `bootstrap.php` as a platform-aware compatibility loader

| Pros | Cons |
|------|------|
| Preserves the current multi-version vendored-framework model. | Keeps a global singleton layer in the architecture. |
| Gives existing plugins a migration bridge for `register_plugin()` args. | Platform-specific conditionals can keep accumulating in one file. |
| Lowest short-term risk for production plugins that load the framework from entry files. | Harder to test cleanly than a smaller loader plus explicit platform classes. |
| Can continue to select the highest compatible framework version across active plugins. | May obscure the boundary between version resolution, module loading, and platform detection. |

### Option B: Replace `bootstrap.php` with a smaller v2 loader / kernel entry point

| Pros | Cons |
|------|------|
| Cleaner architecture: loader resolves the framework, plugin classes own platform behavior. | Higher migration risk for all existing entry files. |
| Easier to align with DI, explicit module sets, and future EDD support. | Needs a new solution for multi-version conflict resolution across vendored copies. |
| Reduces special-case flags in the loader. | Requires careful compatibility shims or a lockstep migration of production plugins. |
| Makes pure WP plugins feel first-class instead of WooCommerce plugins with disabled checks. | A failed migration can break plugin activation before runtime fallbacks are available. |

## Open Question 2: `is_payment_gateway` Flag vs Typed Inheritance

### Option A: Keep a metadata flag, generalized as plugin type metadata

Example shape: `register_plugin(..., [ 'platform' => 'woocommerce', 'type' => 'payment_gateway' ])`.

| Pros | Cons |
|------|------|
| Easiest compatibility bridge for existing `is_payment_gateway` entry files. | Still duplicates type information outside the class hierarchy. |
| Loader can require module classes before invoking plugin callbacks. | String flags can drift from the actual class being instantiated. |
| Allows gradual migration across production plugins. | Keeps the current unstable pattern that `PLANS.md` calls out as inconvenient. |
| Can support warnings/deprecations before removing old flags. | Makes static analysis and type-based behavior less reliable. |

### Option B: Use inheritance as the source of truth

Example shape: payment plugins extend `Woodev_Payment_Gateway_Plugin`, which extends `Woodev_Woocommerce_Plugin`.

| Pros | Cons |
|------|------|
| Type system becomes the source of truth; no duplicate `is_payment_gateway` flag. | Loader must make the correct base classes available before the child plugin class is declared. |
| Cleaner checks via `instanceof` for REST/status/admin behavior. | Requires all production plugin entry files to be migrated carefully. |
| Aligns with the target class hierarchy in `PLANS.md`. | Harder to support old plugins unless a compatibility registration path remains. |
| Easier to extend later with `Woodev_EDD_Plugin` and other platform-specific bases. | If the inheritance tree is wrong, failures happen early as fatal class errors. |

## Related

- [FUTURE-BACKLOG.md](FUTURE-BACKLOG.md) — deferred v2 work and framework decoupling notes.
- [CURRENT-STATE.md](CURRENT-STATE.md) — current project status and v2.0.0 execution order.
- [GOTCHAS.md](GOTCHAS.md) — bootstrap and compatibility gotchas relevant to migration planning.
