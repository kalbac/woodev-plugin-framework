# The plugin class — bases, overrides, lifecycle of a request

Source of truth: `woodev/class-plugin.php` (`Woodev_Plugin`), `woodev/class-woocommerce-plugin.php`
(`Woocommerce_Plugin`), `woodev/shipping-method/class-shipping-plugin.php`,
`woodev/payment-gateway/class-payment-gateway-plugin.php`. Rule table:
`docs-internal/AGENT-RULES.md` → Rule 2. Where each responsibility lives:
`docs-internal/wiki/architecture.md`. Worked examples:
`tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php` (plain WordPress),
`tests/_fixtures/woodev-realistic-shipping-plugin/includes/class-realistic-shipping-plugin.php`
(shipping), `tests/_fixtures/woodev-realistic-payment-plugin/includes/class-realistic-payment-plugin.php`.

## Required

A concrete plugin class:

- `extends` the right base ([SKILL.md](SKILL.md) table). Declare `protected static $instance` with a public
  static `instance()` (`return self::$instance ??= new self();`) — recommended, not required: the resolver
  calls `main_class::instance()` when it exists and falls back to `new $main_class()` otherwise, but
  `Woodev_Plugin_Bootstrap::get_active_plugin_instances()` (the installed-plugins badge) only sees plugins
  that have an accessor, and a second `new` would build every subsystem twice.
- calls `parent::__construct( string $id, string $version, array $args = [] )`. `$id` MUST equal the
  `plugin_id` of the loader definition. `$args`: `text_domain` (string) and `dependencies` (see below).
- implements `protected function get_file()` — the full path of the plugin ENTRY file (not the class
  file; it feeds `plugin_basename()`, the plugin-action link, the deactivation hook and
  `get_plugin_path()`). When the class lives in `includes/`, return the constant the entry file defines.
- implements `public function get_plugin_name()` — the localised display name.

Do NOT override `get_download_id()` — it reads the loader definition. Do not override `add_hooks()` (it
is private).

## What the base constructor builds, in order

`Woodev_Plugin::__construct()` runs: `includes()` → `init_dependencies()` → `init_admin_message_handler()`
→ `init_admin_notice_handler()` (+ contract check) → `init_license_handler()` (+ contract check) →
`init_hook_deprecator()` → `init_lifecycle_handler()` (+ contract check) → `init_translation_handler()` →
`init_cron_handler()` → `init_rest_api_handler()` → `init_setup_wizard_handler()` →
`init_competitor_handler()` → `init_settings_page()` → `load_admin_pages()` → `add_hooks()`.
`Woocommerce_Plugin::__construct()` then adds `init_blocks_handler()` and `register_woocommerce_hooks()`.

**An override of an `init_*` method must assign the property the base would have assigned** — it
REPLACES the default, it does not extend it. The three enforced ones (admin notice handler, license,
lifecycle) are dereferenced without a null check 17 / 13 / 2 times; if your override leaves them unset,
the framework reports `_doing_it_wrong()` under `WP_DEBUG` and builds a default. The base methods are
`protected`: keep that visibility in an override. Call `parent::` when you only want to add.

| To customise | Override |
|---|---|
| Install/upgrade routines | `init_lifecycle_handler()` → `$this->lifecycle_handler = new My_Lifecycle( $this );` — [lifecycle.md](lifecycle.md) |
| Settings tabs | `get_settings_providers()` (public) — [settings.md](settings.md) |
| An onboarding wizard | `build_setup_wizard_handler()` (return a `Setup_Wizard` subclass or `null`) — [settings.md](settings.md) |
| A custom REST handler | `init_rest_api_handler()` |
| Deprecated hooks of YOUR plugin | `get_deprecated_hooks()` — format in its docblock; the framework's own are internal |
| A plugin sold without a license | `is_need_license()` returning `false` (presentation hint only; enforcement is server-signed) |
| Admin plugin-settings detection | `is_plugin_settings()` |

Two hook callbacks the constructor does NOT call but WordPress does: `init_plugin()` on
`plugins_loaded` priority 15 and `init_admin()` on `admin_init` priority 0 (both public, empty in the
base). Put "after WordPress/WooCommerce is ready" wiring there, not in the constructor — WooCommerce
order and product classes are not guaranteed to exist when the bootstrap builds your plugin (the
realistic fixture registers its demo seeder on `admin_init` for exactly this reason).

## Dependencies

`$args['dependencies']` takes `php_extensions`, `php_functions` and `php_settings` arrays; the
`Woodev_Plugin_Dependencies` handler (`woodev/class-woodev-plugin-dependencies.php`) adds admin notices
for what is missing. Read the gotcha `docs-internal/gotchas/dependency-function-check-bug.md` before
relying on `php_functions`. Do not declare `ext-mbstring` and then rely on it silently — see
[traps.md](traps.md).

## HPOS and Blocks compatibility

Declared in the LOADER DEFINITION's `supported_features` ([loading.md](loading.md)):

```php
'supported_features' => [
    'hpos'   => true,
    'blocks' => [ 'cart' => true, 'checkout' => true ],
],
```

- `Woodev_Plugin_Bootstrap::register_loader_definition()` hooks the WooCommerce compatibility
  declaration early from this array. Declaring it from the plugin class is too late for
  `before_woocommerce_init`.
- `Woocommerce_Plugin` reads the SAME array back from the definition: `is_hpos_compatible()` is true
  only when `hpos` is `true` AND WooCommerce is ≥ 7.6; `get_supported_features()` returns it. A
  constructor-supplied `supported_features` that disagrees with the definition triggers
  `_doing_it_wrong()` and the definition wins.
- Defaults: `hpos` false; `blocks.cart`/`blocks.checkout` false, except `true` when the definition has
  `'type' => 'shipping'`.
- Never declare `hpos => true` and then read or write orders through `get_post_meta()`; use the `WC_Order`
  API or `Woodev_Order_Compatibility` (`woodev/compatibility/class-order-compatibility.php`). Gotchas
  `hpos-order-meta-safety`, `a-row-rebuilt-after-an-action-is-stale-only-on-the-legacy-cpt-store`.
- `Woodev_Blocks_Handler` (`woodev/handlers/blocks-handler.php`) reads `get_supported_features()` and
  shows the admin notices about an incompatible Cart/Checkout block. `Woodev_Plugin::get_blocks_handler()` returns `?Woodev_Blocks_Handler`: it is `null` for a pure-WordPress
  plugin (only `Woocommerce_Plugin` builds one), so null-check it (the typed-property `TypeError` of
  gotcha `blocks-handler-typed-property-trap` is resolved, the lesson stands: a base-class property
  initialised only by a subclass must be nullable).

## Hooks the plugin class should and should not register

- Register request-scoped wiring in `init_plugin()` / `init_admin()` or in your own handler's constructor.
- The base already registers: `plugins_loaded` (init_plugin), `admin_init` (init_admin), `init`
  (updater), `wp_enqueue_scripts` / `admin_enqueue_scripts`, `admin_notices`, the "Configure"
  plugin-action link, and API-request logging. Do not re-register them.
- A hook registered as `[ $this, 'method' ]` from an object each plugin builds fires ONCE PER PLUGIN
  (WordPress keys callbacks by `spl_object_hash()`); a framework-wide or site-wide hook should be a
  static callback. Gotcha `a-hook-registered-from-a-per-plugin-object-fires-once-per-plugin`.
- If you replace a framework handler, keep the override chain intact: a handler that self-registers its
  hook can silently disable a subclass override (gotcha `handler-extraction-must-preserve-override-chain`).
- Name hook callback methods `handle_{hook_name}` and mark them `@internal`
  (`.ai/skills/woodev-framework-backend-dev/hooks.md`).

## Subclassing traps that fatal at declaration time

- A base constructor that ASSIGNS a property the subclass set BEFORE calling `parent::__construct()`
  discards the subclass's value silently, with no error — the live case is a shipping method's
  `supports` list. Do not set base-owned state ahead of the parent call; use the documented route for
  that property (the shipping skill names it). Gotcha
  `a-base-constructor-that-assigns-what-the-subclass-just-set`.
- Overriding a `final` base method, narrowing visibility, a `static` mismatch, or an omitted return type
  where the base declares one all fatal at CLASS DECLARATION — before WordPress boots, invisible to a
  mocked suite. Run `npm run probe:signature` against your class and its base before shipping
  (`docs-internal/migration/signature-probe.md`, gotcha `a-stricter-base-class-fatals-on-signatures`).

## Related

- [SKILL.md](SKILL.md), [lifecycle.md](lifecycle.md), [services.md](services.md)
