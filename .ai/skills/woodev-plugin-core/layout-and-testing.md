# Layout and testing a plugin

Paths below that start with `tests/`, `docs-internal/` or `.ai/` are in the framework REPOSITORY
(`kalbac/woodev-plugin-framework`), not in the bundled `woodev/` copy. Read the fixtures there as worked
examples; do not copy them blindly (they are rig fixtures, "NOT for production use").

## Recommended directory layout

```text
my-plugin/
├── my-plugin.php                    entry file: header, constants, loader require, Woodev_Loader::register()
├── woodev/                          the bundled framework copy (never edit; replace wholesale on update)
├── includes/
│   ├── class-my-plugin.php          main class: extends the right base, instance(), get_file(), get_plugin_name()
│   ├── class-my-plugin-lifecycle.php  extends Woodev_Lifecycle  (install / upgrade_to_X_Y_Z)
│   ├── class-my-plugin-settings.php   extends Woodev_Abstract_Settings (+ connection test interfaces)
│   ├── api/                         API client on Woodev_API_Base, request/response classes
│   └── …                            domain classes
├── languages/                       .pot/.po/.mo (and JSON for JS) for YOUR text domain
├── assets/                          your own built JS/CSS
└── tests/
    ├── unit/                        Brain Monkey + Mockery, no WordPress
    └── integration/                 real WordPress + WooCommerce (wp-env)
```

- **One class per file**, `class-{slug}.php` for classes and `abstract-class-{slug}.php` for abstract ones —
  the framework's own convention (the class-map generator enforces it inside `woodev/`). A shipping
  plugin's class set is in [../woodev-shipping-plugin/SKILL.md](../woodev-shipping-plugin/SKILL.md).
- **Runtime is include-based.** Do not rely on Composer autoload at runtime in the shipped plugin: require
  your own files from the loader `callback` (see `woodev_realistic_shipping_plugin_init()` in
  `tests/_fixtures/woodev-realistic-shipping-plugin/woodev-realistic-shipping-plugin.php`) in dependency
  order — parent classes and interfaces before the classes that extend them. Framework classes need no
  `require`; they resolve through the framework autoloader (but only AFTER the resolver ran on
  `plugins_loaded`, so a class that `extends` a framework base must be loaded from the callback or later,
  never at the top of the entry file).
- **Namespaces:** author new code in your own namespace. Never edit or add files inside `woodev/`.
- **A minimal worked plugin** is `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php` (plain
  WordPress: settings tab, every control type, wizard). The realistic ones:
  `woodev-realistic-shipping-plugin/`, `woodev-realistic-payment-plugin/`, and the carrier pilots
  `woodev-yandex-pilot-plugin/` and `woodev-edostavka-pilot-plugin/`. The entry-path example is
  `woodev-entry-path-fixture/` — the only fixture whose entry file uses `Woodev_Loader::register()` the
  way a real plugin does.
- Note: several fixtures register through `register_loader_definition()` called directly on the
  bootstrap singleton, and their definitions return an array from a function. Both are fixture
  conveniences; a real plugin goes through `Woodev_Loader::register()` with a literal array.

## Unit tests — Brain Monkey + Mockery

The framework's pattern (`tests/unit/TestCase.php`, `tests/bootstrap.php`, the guide
`.ai/skills/woodev-framework-dev-cycle/testing-guide.md`):

- Base class sets up and tears down Brain Monkey, uses `MockeryPHPUnitIntegration`, and stubs the
  translation and escape functions. Give your plugin repo its own equivalent `TestCase`.
- The unit bootstrap defines `ABSPATH`, WordPress time constants and a minimal `WP_Error`, and loads
  Patchwork BEFORE any source file (Brain Monkey loads it lazily, too late for `require_once`d sources).
  Copy that ordering. Any redefinable internal is declared in `patchwork.json`.
- **Singletons leak between tests.** The plugin class and the bootstrap are process-wide singletons;
  reset `Woodev_Plugin_Bootstrap::$instance` (reflection) in `setUp()` as `LoaderEntryPathTest` does, and
  do not assert on registrations a previous test may have made — a plugin that registers itself once per
  process only shows that registration right after the construction that causes it
  (`tests/unit/RealisticShippingFixtureTest.php`).
- Fake the WooCommerce runtime with the support traits when you need a real subclass of the framework
  bases: `tests/unit/Support/Pilot_Fixture_WP_Stubs.php` (WooCommerce class stubs + WordPress runtime
  functions) and `Pilot_Testable_Framework_Resolver.php` (a resolver with a controllable WC version).
  `tests/unit/RealisticShippingFixtureTest.php`, `YandexPilotFixtureTest.php`,
  `EdostavkaPilotFixtureTest.php`, `RealisticPaymentFixtureTest.php` show the whole load-and-assert
  pattern; `LoaderEntryPathTest.php` shows a test that `include`s a real entry file.
- Mocked tests are blind to declaration-time fatals. Add `npm run probe:signature` to your checks
  (`docs-internal/migration/signature-probe.md`).
- Run order: `phpunit.xml` of the framework uses `executionOrder="depends,defects"`, so delete
  `.phpunit.result.cache` before a measurement or two runs of the same tree disagree.

What to cover in a plugin, at minimum:

| Area | Unit | Integration |
|---|---|---|
| Entry path | literals-only definition (read the source, like `LoaderDefinitionLiteralsTest`) | the plugin boots through `Woodev_Loader::register()` and the base `instance()` exists |
| Settings | default values, validation callables, `show_if` rules | save/load round trip over REST, secrets masked |
| Lifecycle | each `upgrade_to_X_Y_Z` idempotent, with a fake installed version | install on a clean site, upgrade from the previous release's stored data |
| Data contracts | the stable ids/keys asserted as literals | an existing site's data is read unchanged |
| API client | auth, endpoint mapping, response parsing, error mapping (stub transport) | against a stub server — never live credentials in CI |
| HPOS | order meta through the `WC_Order` API | the same flow on CPT and on HPOS |

## Integration tests — real WordPress + WooCommerce

The framework runs them inside `wp-env` (`.wp-env.json` maps each fixture plugin and mounts the
framework's `woodev/` into it; base class `tests/integration/TestCase.php` extends `WP_UnitTestCase` and
fails fast if `Woodev_Plugin_Bootstrap` is not loaded). Commands: `composer test:integration`
(`./vendor/bin/phpunit --testsuite=Integration`, `TEST_SUITE=integration`). Patterns:
`tests/integration/PluginBootstrapTest.php` (the fixture is loaded), `SettingsPageRestTest.php` and
`SetupWizardRestTest.php` (REST save/validate), `ShippingMethodIntegrationTest.php`,
`Shipping/` (checkout, orders, export datastores), `OrderMetaCacheFlushTest.php` (HPOS vs CPT).

- The integration database is single-flight: do not run two integration processes at once.
- Anything that only breaks on a real boot — class-map wiring, a hook that fires once per plugin, an
  admin-only function used on `plugins_loaded` — needs an integration test; a Brain Monkey suite defines
  those functions for you and stays green (gotcha `admin-only-wp-functions-are-undefined-on-plugins-loaded`).
- Verify on the dev rig (a real WordPress with several Woodev plugins active) before calling it done:
  multi-version resolution and checkout/blocks behaviour are not reproducible in unit tests.

## Related

- [SKILL.md](SKILL.md), [loading.md](loading.md), [traps.md](traps.md)
