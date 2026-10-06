# Loading — entry file, loader definition, multi-version

Source of truth: `woodev/loader.php` (`Woodev_Loader`), `woodev/bootstrap.php`
(`Woodev_Plugin_Bootstrap`), `woodev/class-framework-plugin-loader-definition.php`
(`Framework_Plugin_Loader_Definition`), `woodev/class-framework-resolver.php`
(`Framework_Resolver::load_plugins()`), `woodev/class-framework-autoloader.php`.
Decisions: `docs-internal/adr/001`, `003`, `004`. Rule text: `docs-internal/AGENT-RULES.md` → Rule 3.

## How the framework is bundled

Every production plugin ships its OWN copy of the framework as a `woodev/` directory in the plugin
root. The framework repo publishes it as a release ZIP, `woodev-plugin-framework-<version>.zip`, built by
the `release` job of `.github/workflows/ci.yml` (it excludes `tests`, `src`, `.ai`, `node_modules`, the
dev configs). Take `woodev/` from that ZIP — built assets under `woodev/assets/build/` are part of the
runtime, so a copy taken from a raw checkout without a build is incomplete.

There is no Composer in a shipped plugin. The runtime class resolver is `Woodev_Framework_Autoloader`
reading the generated `woodev/class-map.php`; a plugin never lists framework files itself.

Many plugins run on one site, each with its own copy. The loader arbitrates between them — see
"Multi-version" below.

## The entry file

The shape (compare the in-repo example `tests/_fixtures/woodev-entry-path-fixture/woodev-entry-path-fixture.php`):

```php
defined( 'ABSPATH' ) || exit;

defined( 'MY_PLUGIN_FILE' ) || define( 'MY_PLUGIN_FILE', __FILE__ );
defined( 'MY_PLUGIN_VERSION' ) || define( 'MY_PLUGIN_VERSION', '1.0.0' );

require_once plugin_dir_path( __FILE__ ) . 'woodev/loader.php';

Woodev_Loader::register(
    MY_PLUGIN_FILE,
    [
        'plugin_id'            => 'my-plugin',
        'download_id'          => 1234,
        'plugin_name'          => 'My Plugin',
        'plugin_version'       => MY_PLUGIN_VERSION,
        'framework_version'    => '2.0.2',
        'backwards_compatible' => '2.0.0',
        'platform'             => 'woocommerce',
        'requirements'         => [
            'php'         => '7.4',
            'wordpress'   => '6.3',
            'woocommerce' => '7.0',
        ],
        'main_class'           => 'My_Plugin',
    ]
);
```

- **Do this at file level, during inclusion.** `Woodev_Plugin_Bootstrap` loads plugins on `plugins_loaded`
  (priority 10); a registration made from a hook that runs later is silently ignored — no error, the
  plugin simply never starts. Gotcha `docs-internal/gotchas/plugin-registration-timing.md`.
- **`Woodev_Loader::register()` does not take `plugin_file`** — it adds it from the first argument.
  It returns `false` when the bootstrap is unreadable, or when a legacy (v1) copy of the framework won
  the class rendezvous and has no `register_loader_definition()`; in that case the plugin stays dormant and
  `Woodev_Loader` hooks a fleet-wide admin notice that names the blocking plugin.
- **Never `new Woodev_Plugin_Bootstrap()`** — its constructor is private; use `instance()` (and you almost
  never need to, `Woodev_Loader` does it). Gotcha `docs-internal/gotchas/singleton-instantiation.md`.
- **`WOODEV_FRAMEWORK_DIR`** — when defined, `Woodev_Loader::register()` loads the bootstrap from that
  directory instead of next to the plugin. It is for the dev rig and tests; a production plugin does
  not define it.
- **The definition contains literals only.** PHP evaluates the array BEFORE `register()` requires the
  bootstrap that registers the autoloader, so any framework class constant — even
  `Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE` — is `Class … not found` on the first
  line. Write `'woocommerce'`. Gotcha
  `docs-internal/gotchas/a-loader-definition-cannot-use-a-framework-class-constant.md`.
  Your own constants and functions are fine.

## Definition fields

Validated by `Framework_Plugin_Loader_Definition::from_array()` (a failure is reported as an admin
notice and logged; the plugin does not start).

| Field | Rule |
|---|---|
| `plugin_id` | Required. Stable id; derives option names and hook names (`woodev_{id}_…`). Never change it after release. |
| `download_id` | Required, positive integer: the EDD product id on woodev.ru, the license identity. Two plugins with one id are an author error (second one quarantined; deactivated in admin). |
| `plugin_name` | Required. |
| `plugin_version` | Required. |
| `framework_version` | Required. The framework version this plugin bundles. The highest one on the site wins. |
| `backwards_compatible` | Not validated but REQUIRED in practice: oldest framework version the plugin works with. Without it a too-old plugin is never quarantined. |
| `platform` | Required: `'wordpress'` or `'woocommerce'`. `'edd'` is reserved and rejected. |
| `requirements` | Required: `php` and `wordpress`; `woocommerce` is also required when `platform` is `woocommerce`. A plugin failing a requirement is skipped with an admin notice. |
| `main_class` or `callback` | At least one. `callback` runs first when callable; otherwise `main_class::instance()` (if the class has a static `instance()`) or `new main_class()`. A `main_class` that does not exist is an invalid definition. |
| `supported_features` | Optional: `hpos` (bool) and `blocks.cart` / `blocks.checkout` (bool). See [plugin-class.md](plugin-class.md). |
| `type` | Optional and only `'shipping'` is accepted. It changes ONE default: `blocks.cart` and `blocks.checkout` default to `true` for it. The plugin type itself is still decided by `extends`. |

Use a `callback` when the main class file must be included first (the fixtures do this:
`tests/_fixtures/woodev-realistic-shipping-plugin/woodev-realistic-shipping-plugin.php` →
`woodev_realistic_shipping_plugin_init()` requires its `includes/` files, then builds the plugin).
Use `main_class` alone when your classes are autoloadable. Keep `main_class` set even with a callback:
the resolver records which definition built which class, and `Woodev_Plugin::get_download_id()` relies
on it.

## Multi-version resolution

On `plugins_loaded` the resolver (`Framework_Resolver::load_plugins()`) sorts registered plugins by
`framework_version`, HIGHEST FIRST (`framework_compare()`), and:

1. registers `Woodev_Framework_Autoloader` against the WINNING copy's `woodev/` — so framework classes
   (`Woodev_Plugin`, `Woocommerce_Plugin`, `Shipping_Plugin`, …) come from the highest copy, whichever
   plugin's bundle you happen to ship;
2. installs the error reporter (before any plugin code can fail);
3. skips plugins whose `framework_version` is below the winner's `backwards_compatible`, or whose PHP /
   WordPress / WooCommerce requirement fails — each gets an admin notice;
4. for the rest, quarantines a repeated `download_id` and invokes the plugin;
5. fires `woodev_plugins_loaded`.

Consequences for a plugin author:

- **Your code runs against the highest framework on the site, not the one you bundled.** Test against
  the framework you bundle AND assume a newer one. The registration contract is additive-only from
  2.0.0 (new optional fields may appear; required ones will not be removed or renamed).
- **The `Woodev_Plugin_Bootstrap` copy that answers can be a different, older copy than the framework
  classes in use** (the class rendezvous is first-loaded, the classes are highest-version). Guard any
  call to a bootstrap method that postdates 2.0.0 with `method_exists()`, as `Woodev_Plugin::get_download_id()`
  does. Gotcha `docs-internal/gotchas/multiversion-early-class-guards.md`.
- **Do not declare globally named helpers or classes without a `class_exists( …, false )` /
  `function_exists()` guard** if they may exist in another plugin's copy.

## Verifying the entry path

A test that calls `register_loader_definition()` on an already-loaded framework does NOT exercise the
real entry path — that is how the entry-path fatal of #763 shipped. Keep one test that goes through
`Woodev_Loader::register()` from an entry file: `tests/unit/LoaderEntryPathTest.php`,
`tests/unit/LoaderFacadeTest.php`, `tests/unit/LoaderDefinitionLiteralsTest.php` (the last one asserts
the literals-only rule) are the patterns to copy.

## Related

- [SKILL.md](SKILL.md), [plugin-class.md](plugin-class.md), [traps.md](traps.md)
