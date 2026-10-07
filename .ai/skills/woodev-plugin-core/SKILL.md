---
name: woodev-plugin-core
description: Load before writing or changing ANY plugin that runs on Woodev Framework v2 (shipping, payment or plain WordPress/WooCommerce) — the entry file and loader definition, the plugin class, lifecycle and upgrades, settings, licensing, i18n, testing and the traps that already cost us time. For plugin AUTHORS who consume the framework, not for developing the framework itself (that is `woodev-framework-backend-dev`).
---

# Woodev Plugin Core — building a plugin on Framework v2

This skill is for the author of a plugin that BUNDLES the framework (`woodev/` inside the plugin).
It covers what every plugin needs. A shipping plugin loads
[../woodev-shipping-plugin/SKILL.md](../woodev-shipping-plugin/SKILL.md) on top of this one.

**Where paths point.** `woodev/…` is the framework copy bundled inside your plugin (the same files in
the framework repository). `docs-internal/…`, `tests/…`, `.ai/…` and `AGENTS.md` are in the framework
REPOSITORY (`kalbac/woodev-plugin-framework`) — read them there; they are not shipped in your plugin.

## Ground rules

1. **Read the code, not `docs/`.** The public `docs/` are stale and will be rewritten after the first
   real plugin. The source of truth is `woodev/` (symbol names are cited below; line numbers rot).
2. **Never invent an API.** Every class, method, filter and option named in this skill exists in
   `woodev/` today. If you need something not named here, `rg` the framework first; if it is not
   there, it does not exist — do not write the call, ask the operator.
3. **Never `require` framework internals by path.** The framework resolves its own classes
   (`Woodev_Framework_Autoloader` + `woodev/class-map.php`); a plugin declares its type by what its
   class `extends`.
4. **Internal APIs may change; installed-site data never does** (ADR-005,
   `docs-internal/AGENT-RULES.md` → Rule 0). Option keys, hook names, shipping/gateway method ids,
   instance setting keys, cron hooks, REST namespaces, admin slugs, meta keys, license option names —
   preserve byte-for-byte. See [lifecycle.md](lifecycle.md).
5. **Style:** WordPress Coding Standards, `snake_case` methods/hooks, short array syntax `[]` only,
   type declarations on every parameter and return, docblocks (`@since`, `@param`, `@return`) on
   every public/protected method, pure methods `static`. New classes are namespaced; do not copy
   the legacy global `Woodev_*` shape.

## The map

| You need to… | Where | Detail |
|---|---|---|
| Register the plugin, bundle the framework, survive multi-version | entry file, `Woodev_Loader::register()` | [loading.md](loading.md) |
| Write the main plugin class, override subsystems, declare HPOS/blocks | `Woodev_Plugin` → `Woocommerce_Plugin` | [plugin-class.md](plugin-class.md) |
| Install/upgrade routines, migrate a v1 plugin's data | `Woodev_Lifecycle` | [lifecycle.md](lifecycle.md) |
| Add settings (typed API, sections, connection test, wizard) | `Settings_Provider`, `Woodev_Abstract_Settings` | [settings.md](settings.md) |
| Licensing, error reporting, REST, logging, notices, i18n | subsystems on the base class | [services.md](services.md) |
| Lay out the plugin, write unit/integration tests | `tests/_fixtures/*` as worked examples | [layout-and-testing.md](layout-and-testing.md) |
| Avoid the known traps | `docs-internal/gotchas/*` | [traps.md](traps.md) |

## Which base class

The type is the `extends` — never a flag, never a `capabilities` array.

| Plugin | Extend | File |
|---|---|---|
| Pure WordPress | `Woodev_Plugin` | `woodev/class-plugin.php` |
| WooCommerce (no shipping/payment) | `\Woodev\Framework\Woocommerce_Plugin` | `woodev/class-woocommerce-plugin.php` |
| Payment gateway | `Woodev_Payment_Gateway_Plugin` (already a `Woocommerce_Plugin`) | `woodev/payment-gateway/class-payment-gateway-plugin.php` |
| Shipping | `\Woodev\Framework\Shipping\Shipping_Plugin` (already a `Woocommerce_Plugin`) | `woodev/shipping-method/class-shipping-plugin.php` |

`Woodev_Plugin` is platform-neutral by design (no WooCommerce or HPOS methods). Anything that touches
WooCommerce — logging through the WC logger, HPOS declaration, Blocks handler — comes from
`Woocommerce_Plugin`.

## The five-minute path to a loading plugin

1. Bundle the framework: copy the framework's `woodev/` directory into the plugin root as `woodev/`
   (the framework's release ZIP `woodev-plugin-framework-<version>.zip` is the artifact; see
   [loading.md](loading.md)).
2. Entry file `my-plugin.php`: define constants, `require_once __DIR__ . '/woodev/loader.php'`, call
   `Woodev_Loader::register( __FILE__, [ …literals only… ] )`. Exact shape and every definition
   field: [loading.md](loading.md).
3. A main class `extends` the right base (table above) with a static `instance()` accessor (recommended: the resolver falls back to `new` without one); its constructor
   calls `parent::__construct( $id, $version, [ 'text_domain' => … ] )`; it implements the abstract
   `get_file()` and `get_plugin_name()`. See [plugin-class.md](plugin-class.md).
4. A `Woodev_Lifecycle` subclass for install/upgrade, wired by overriding `init_lifecycle_handler()`.
   See [lifecycle.md](lifecycle.md).
5. Settings through `get_settings_providers()` — on `Woodev → Настройки`, never on WooCommerce →
   Integrations by default. See [settings.md](settings.md).
6. Tests: unit with Brain Monkey/Mockery, one integration test that boots the REAL entry path.
   See [layout-and-testing.md](layout-and-testing.md).

## Settings controls

Textarea height is an optional `register_control()` argument: use
`$this->register_control( 'address', \Woodev_Control::TYPE_TEXTAREA, [ 'rows' => 3 ] );`
for a three-line address field. `rows` is a positive integer, applies only to textarea controls,
and travels through `Field_Schema` to the React settings page and wizard. Omit it to keep the
existing renderer default; nonpositive values also use that default. The separate classic helper
accepts the row count as its third argument: `textarea( $id, $classes, 3 )`, with 10 rows when omitted.
For the rest of the settings API, read [settings.md](settings.md).

## Hard rules (each one cost a session)

- **A loader definition holds literals only.** No framework class constant (not even
  `Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE`) — PHP builds the array before
  `register()` loads the autoloader, so it is a fatal on the plugin's first line. Write `'woocommerce'`.
  Gotcha `docs-internal/gotchas/a-loader-definition-cannot-use-a-framework-class-constant.md`.
- **Always set `framework_version` AND `backwards_compatible`.** Without the second, a too-old plugin
  is never quarantined. `framework_version` is the version you bundle; `backwards_compatible` is the
  oldest framework version your plugin works with.
- **`download_id` is the license identity.** It is a positive integer (the EDD product id on
  woodev.ru) and two plugins sharing one is an author error: the second is quarantined and, in admin,
  deactivated. Do not override `Woodev_Plugin::get_download_id()` — it reads the loader definition.
- **HPOS and blocks compatibility are declared in the LOADER DEFINITION** (`supported_features`), not in
  the constructor. The loader definition is authoritative; a conflicting constructor value only raises
  `_doing_it_wrong()`. Never touch order data with `get_post_meta()` — use
  `Woodev_Order_Compatibility` or the `WC_Order` object.
- **Settings live on `woodev-settings`** (`Woodev → Настройки`), reached through
  `Woodev_Plugin::get_settings_providers()`. The WooCommerce → Integrations tab is an exception with its
  own seam (`Shipping_Plugin::get_integration_handler()`), used only where an installed site's data
  contract requires it. Never build your own top-level admin settings page.
- **Three subsystems are enforced:** the admin-notice handler, the license and the lifecycle handler. A
  subclass that overrides their `init_*` method without building the object gets `_doing_it_wrong()`
  under `WP_DEBUG` and a default instead — do not return `null`.
- **A hook registered as `[ $this, … ]` from an object every plugin builds fires once per plugin.**
  Shared (per-site) hooks belong in a static callback. Gotcha
  `docs-internal/gotchas/a-hook-registered-from-a-per-plugin-object-fires-once-per-plugin.md`.
- **Merchant-facing copy:** label = short essence (2–3 words); help goes to `tooltip` (and `description`
  only when it must be unmissable or copyable); never the words «чекаут» or «фреймворк».
  `docs-internal/AGENT-RULES.md` → Rules 10a–10c.
- **i18n msgid language is decided by who reads the string.** Storefront → English msgid, Russian from
  the catalogue; admin → Russian msgid is fine; logs/exceptions → plain strings, no `__()`.
  `AGENTS.md` → Conventions → «Translatable strings». Details in [services.md](services.md).

## Before you hand work back

- `npm run probe:signature` (a PHP class repointed at a stricter framework base fatals at DECLARATION,
  and a mocked unit suite cannot see it — `docs-internal/migration/signature-probe.md`).
- A real-entry-path test (a plugin entry that goes through `Woodev_Loader::register()`), not only
  `register_loader_definition()` called by a test — see
  `tests/_fixtures/woodev-entry-path-fixture/` and `tests/unit/LoaderEntryPathTest.php`.
- The data-preservation checklist for a migrated plugin is verified (see [lifecycle.md](lifecycle.md)).
- Read [traps.md](traps.md) once per plugin — it is one line per trap and each links its gotcha.
