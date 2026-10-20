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
- **Define EVERY WordPress time constant in your unit bootstrap** (`MINUTE_IN_SECONDS`, `HOUR_IN_SECONDS`,
  `DAY_IN_SECONDS`, `WEEK_IN_SECONDS`, …), not just the ones your own code uses. A framework class constant built on
  one (`CLAIM_REFRESH_THROTTLE = 15 * MINUTE_IN_SECONDS` in `Woodev_Plugins_License`) is evaluated the first time the
  class's constants are touched: the framework's and CDEK's suites stayed green while Yandex's went from 489 green to
  21 `Undefined constant` errors in tests that never touch licensing (gotcha
  `a-framework-class-constant-on-a-wp-time-constant-breaks-a-plugin-unit-bootstrap`; Yandex PR #64 fixed its bootstrap).
- **PHPUnit 9 ignores `@runInSeparateProcess` on a CLASS docblock** — the test leaks into the shared process and fails
  only under CI's reverse-order gate (`--order-by=reverse`). Put the annotation (and `@preserveGlobalState disabled`) on
  the METHOD; run `--order-by=reverse` locally for anything that defines a function or class at runtime (Brain Monkey's
  `Functions\when( 'has_block' )` defines it process-wide). Gotcha `phpunit-9-ignores-run-in-separate-process-on-a-class`.
- A test that `include`s your ENTRY FILE in a subprocess (the literals-only loader definition test) stubs every WordPress
  function the entry file calls at file level — adding the error-reporting DSN `add_filter()` broke CDEK's
  `LoaderDefinitionTest` until it got a recording stub ([services.md](services.md#error-reporting)).
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

## CI of a plugin repo

Both carrier plugins share one shape (`.github/workflows/ci.yml` in `kalbac/woocommerce-yandex-delivery` is the
cleanest copy; CDEK `v2` has the same). Reuse it rather than inventing one:

- **The framework is pinned by SHA** (`WOODEV_FRAMEWORK_REF` in the workflow; one line to move to a reviewed framework
  commit) and fetched as a codeload tarball; `scripts/sync-framework.sh` copies its `woodev/` into the plugin. A plugin
  that is not synced to the pinned commit tests a different framework than CI does. The script's default source is
  `../woodev-plugin-framework`, which is WRONG inside an Orca worktree — run it as
  `WOODEV_FRAMEWORK_DIR=<framework checkout> bash scripts/sync-framework.sh`.
- **A private repo's GitHub Actions minutes are one shared, metered pool**: run GitHub CI on `pull_request` only (no
  `push` trigger) and gate agents' work with `local-ci` (the real GitHub runner in Docker). It needs `.github/local-ci.Dockerfile`,
  `runner=self-hosted` for `shivammathur/setup-php` under `LOCAL_CI_LOCAL`, no second `actions/checkout`, and the mock
  Composer GitHub token unset — all four are already in the Yandex workflow (PRs #39, #40).
- The matrix is PHP 7.4 and 8.3; the Mac sees PHP 8.5 deprecations the matrix never does (guard `setAccessible()` etc.).

## Releasing a plugin

Copy `release.yml` + `scripts/build-release-zip.sh` from either carrier plugin (s188, PRs CDEK #120 / Yandex #122):

- **One script builds the ZIP, locally and in Actions**: `git archive HEAD` → an allowlist (main file, `includes/`,
  `assets/`, `languages/`, `woodev/`) → one top folder named after the slug. No README (it is the developer README),
  no tests/docs/agent files. The script FAILS unless the bundled `woodev/class-plugin.php` `VERSION` equals the
  loader's `framework_version` and `woodev/assets/build` exists. Timestamps are pinned to the HEAD commit time, files
  added in sorted order with `zip -X`, so two builds are byte-identical.
- **Publish when the tag `v<version>` is missing, not when the version changed.** A first release whose version is
  already on the base branch never "changes", so a version-diff trigger merges the release PR and publishes nothing.
  Trigger: `push` to the base branch filtered to the main file + `workflow_dispatch`; skip when the tag or release exists.
- The release body is the README `### <version>` changelog section (Russian, merchant-facing) — not git-cliff, which
  prints the internal commit log with PR numbers.
- **Changelog format (operator, 20.10.2026): Keep a Changelog, in Russian.** Each README `### <version> — DD.MM.YYYY`
  section groups its bullets under `#### Новое`, `#### Улучшено`, `#### Исправлено`, `#### Удалено` (only the groups
  that have bullets). Every bullet ends with the PR it came from, prefixed by its repo, as plain text — the repos are
  private, so no links: `(edostavka#127)`, `(yandex#129)`, `(framework#1384)`. A PR number, not a commit hash (a
  squash hash says nothing). A bullet that sums up many PRs, such as a rewrite, carries none.
- **The EDD changelog on woodev.ru is the same text with the `(repo#N)` references stripped**, rendered as HTML
  (`<h4>` per group, `<ul><li>`). Buyers read it in the WordPress «View details» window, where a PR number is noise;
  the README keeps the references for us. One source text — never edit the two by hand separately.
- **Publishing to EDD (woodev.ru)** happens only on the operator's word, after he checks the GitHub-release ZIP on his demo sites. It
  goes through the woodev-mcp-adapter release abilities (see `docs/wiki/edd-release-mcp.md` in the woodev-ru repo). Facts measured on
  EDD Software Licensing 3.8.14 (s190):
  - the update API keeps only `p li ul ol strong a em span br` in the changelog, so headings vanish; write a version line as
    `<p><strong>2.3.0.4 — DD.MM.YYYY</strong></p>`;
  - the «View details» description tab shows `post_excerpt` when it is set;
  - staged rollouts pick sites by `crc32(licence + site URL) % 100` (stable, never growing on their own). Bucket 0 is never eligible,
    so release to everyone by switching rollouts OFF, not by setting 100. Only the update API is gated; account downloads always
    serve the newest file. Start a full rewrite at 20 %.
- The workflow fetches `woodev/` from the framework tag `v<framework_version>`, so the framework release goes first.
- Under `set -o pipefail`, `printf … | grep -q` fails at random with SIGPIPE (grep exits early) — grep a file instead.

## Related

- [SKILL.md](SKILL.md), [loading.md](loading.md), [traps.md](traps.md)
