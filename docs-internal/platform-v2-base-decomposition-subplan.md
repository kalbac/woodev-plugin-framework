# Platform v2 — `Woodev_Plugin` Base Decomposition Sub-Plan (D-3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.
>
> This is **Phase 4** of `docs-internal/platform-v2-cleanbreak-plan.md`. Execute it only after the Phase 2 pilot gate is green and Phase 3 (delete back-compat debt) has landed.

**Goal:** Make `Woodev_Plugin` no longer a god-object and strip its WooCommerce seams — pragmatically. Extract the last inline subsystems into handlers that own their own WordPress hooks, so the base stops being the central hook hub, and a pure-WordPress plugin can run with no WC-shaped code in the base.

**Architecture:** The base already delegates ~18 subsystems to handler classes; this sub-plan finishes the job for the **4 concerns still inline** and removes the WC seams. Each extracted handler is constructed eagerly in `Woodev_Plugin::__construct()` (all construction is hook-safe — confirmed by survey) and **registers its own WP hooks in its constructor** (`add_action`/`add_filter` only queue callbacks; they are safe at construct time). The base's monolithic `add_hooks()` shrinks to only what remains base-owned. New handlers are namespaced `Woodev\Framework\Handlers\*` (clean-break policy: new code is namespaced; loaded include-based via the base `includes()`, never Composer autoload at runtime).

**Pragmatic scope guard (D-3):** extract the 4 clear concerns + remove WC seams. Do **not** build a DI container, a generic handler registry, or rename the existing 18 handlers. "Done" = base is readable and each subsystem is independently testable, not textbook DI purity.

**Installed-site contracts touched (PRESERVE byte-for-byte):**
- Cron: schedule key `weekly`, event hook `woodev_weekly_scheduled_events`, AJAX action `wp_ajax_woodev_verify_license`, filter `cron_schedules`.
- Action links: filter `plugin_action_links_{plugin_basename}`.
- Translations: text domains `woodev-plugin-framework` (framework) + each plugin's own text domain.
- API logging: action `woodev_{plugin_id}_api_request_performed`.

---

## What stays inline (do NOT extract)

Per the survey, these are **not** god-object debt — leave them on the base:
- ~25 thin metadata/path getters (`get_id`, `get_version`, `get_plugin_path`, …) — too simple, too reused.
- Empty platform-neutral extension points (`init_plugin`, `init_admin`, `add_admin_notices`, `log`, `load_template`, `get_settings_handler`, …) — these are the override API, not inline logic.
- The 18 already-extracted handlers and their `init_*_handler()` factories.
- Singleton guards (`__clone`, `__wakeup`).

The decomposition targets exactly: **cron**, **plugin action links**, **translations**, **API logging**, and the **WC seams**.

---

## Task 1 — Extract translation loading → `Woodev\Framework\Handlers\Translation_Handler`

**Rationale:** simplest, fully self-contained; good first extraction to establish the pattern.

**Files:**
- Create: `woodev/handlers/class-translation-handler.php`
- Modify: `woodev/class-plugin.php` (remove `load_translations()` + textdomain helpers + their `add_hooks()` line + `includes()` require; add `init_translation_handler()`)
- Test: `tests/unit/handlers/TranslationHandlerTest.php`

- [ ] **Step 1.1 — Write the failing test.**
  ```php
  // tests/unit/handlers/TranslationHandlerTest.php
  public function test_registers_init_hook_and_loads_both_textdomains(): void {
      $plugin = $this->makePluginStub( [ 'get_textdomain' => 'woodev-edostavka' ] );
      Functions\expect( 'add_action' )->once()->with( 'init', Mockery::type( 'array' ) );
      $handler = new Translation_Handler( $plugin );        // registers on construct
      // framework domain is the fixed 'woodev-plugin-framework'; plugin domain comes from the plugin
      $this->assertSame( 'woodev-plugin-framework', $handler->get_framework_textdomain() );
  }
  ```
- [ ] **Step 1.2 — Run, verify fail.** `./vendor/bin/phpunit tests/unit/handlers/TranslationHandlerTest.php -v` → FAIL (class missing).
- [ ] **Step 1.3 — Create the handler.** Move `load_translations()` and the framework/plugin `load_*_textdomain` logic verbatim (preserve domains). Skeleton:
  ```php
  namespace Woodev\Framework\Handlers;

  class Translation_Handler {
      private const FRAMEWORK_TEXTDOMAIN = 'woodev-plugin-framework';
      private \Woodev_Plugin $plugin;
      public function __construct( \Woodev_Plugin $plugin ) {
          $this->plugin = $plugin;
          add_action( 'init', [ $this, 'load_translations' ] );
      }
      public function get_framework_textdomain(): string { return self::FRAMEWORK_TEXTDOMAIN; }
      public function load_translations(): void { /* moved verbatim: framework + plugin domains */ }
  }
  ```
- [ ] **Step 1.4 — Wire into the base.** In `Woodev_Plugin`: add `require_once` in `includes()`; add `protected function init_translation_handler(): void { $this->translation_handler = new \Woodev\Framework\Handlers\Translation_Handler( $this ); }`; call it in `__construct`; **delete** the old `load_translations()` method and its `add_action('init', …)` line from `add_hooks()`.
- [ ] **Step 1.5 — Run + neutrality check.** `composer check` green; the no-WC neutrality test still passes (translations have no WC dependency).
- [ ] **Step 1.6 — Commit.** `refactor(base): extract Translation_Handler from Woodev_Plugin`.

---

## Task 2 — Extract plugin action links → `Woodev\Framework\Handlers\Plugin_Action_Links_Handler`

> **CANCELLED (decision 2026-06-04).** `Woodev_Payment_Gateway_Plugin::plugin_action_links()` overrides the base and calls `parent::`, customizing per gateway — it is a polymorphic template-method, not god-object glue. Extracting it would break the `parent::` chain and need overridable-handler scaffolding (gold-plating, contra D-3). Left on the base. The steps below are not executed.

**Files:**
- Create: `woodev/handlers/class-plugin-action-links-handler.php`
- Modify: `woodev/class-plugin.php` (remove `plugin_action_links()` + its filter wiring; add factory)
- Test: `tests/unit/handlers/PluginActionLinksHandlerTest.php`

- [ ] **Step 2.1 — Failing test:** assert the handler registers `plugin_action_links_{basename}` and that the produced links still include Settings/Docs/Support/License entries derived from the plugin's `get_settings_link()`/`get_documentation_url()`/`get_support_url()`/`get_license_instance()`.
  ```php
  Functions\expect( 'add_filter' )->once()->with(
      'plugin_action_links_' . $basename, Mockery::type( 'array' )
  );
  $handler = new Plugin_Action_Links_Handler( $plugin );
  $links = $handler->plugin_action_links( [] );
  $this->assertContains( $settings_link_html, $links );
  ```
- [ ] **Step 2.2 — Run, verify fail.**
- [ ] **Step 2.3 — Create handler:** move `plugin_action_links()` verbatim; register the filter on the exact `plugin_action_links_{plugin_basename}` name (installed-site contract) in the constructor.
- [ ] **Step 2.4 — Wire base, delete moved method + filter line.**
- [ ] **Step 2.5 — `composer check` green.**
- [ ] **Step 2.6 — Commit.** `refactor(base): extract Plugin_Action_Links_Handler`.

---

## Task 3 — Extract API request logging → `Woodev\Framework\Handlers\API_Logger`

> **CANCELLED (decision 2026-06-04).** `add_api_request_logging()` is polymorphic: `Woodev_Payment_Gateway_Plugin` no-ops it and gateways log per-gateway; unconditional handler construction would double-log on every live payment plugin (installed-site regression). `get_api_log_message()` is also called externally by `Woodev_Payment_Gateway`. Left on the base. The steps below are not executed.

**Files:**
- Create: `woodev/handlers/class-api-logger.php`
- Modify: `woodev/class-plugin.php` (remove `log_api_request()`, `get_api_log_message()`, `add_api_request_logging()`; add factory)
- Test: `tests/unit/handlers/ApiLoggerTest.php`

- [ ] **Step 3.1 — Failing test:** assert the handler registers on the exact action `woodev_{plugin_id}_api_request_performed` (contract) and that `get_api_log_message()` formats request+response into the same string shape.
  ```php
  $plugin = $this->makePluginStub( [ 'get_id' => 'edostavka' ] );
  Functions\expect( 'add_action' )->once()->with(
      'woodev_edostavka_api_request_performed', Mockery::type( 'array' ), 10, 2
  );
  $handler = new API_Logger( $plugin );
  ```
- [ ] **Step 3.2 — Run, verify fail.**
- [ ] **Step 3.3 — Create handler:** move the 3 methods verbatim; the action name is built from `$plugin->get_id()` — keep the `woodev_{id}_api_request_performed` format exactly. Logging delegates to `$plugin->log()` (the platform-neutral override point — base no-op, WC plugin logs via WC logger), so the handler stays platform-neutral.
- [ ] **Step 3.4 — Wire base, delete moved methods + hook line.**
- [ ] **Step 3.5 — `composer check` green.**
- [ ] **Step 3.6 — Commit.** `refactor(base): extract API_Logger`.

---

## Task 4 — Extract cron / scheduled events → `Woodev\Framework\Handlers\Cron_Handler`

**Rationale:** last because it touches licensing (`weekly_license_check`, `ajax_verify_license`). Handle the license callback via the plugin reference, keep all hook names exact.

**Files:**
- Create: `woodev/handlers/class-cron-handler.php`
- Modify: `woodev/class-plugin.php` (remove `add_schedules()`, `schedule_events()`, `weekly_license_check()`, `ajax_verify_license()` + their hook lines; add factory)
- Test: `tests/unit/handlers/CronHandlerTest.php`

- [ ] **Step 4.1 — Failing test:** assert the handler registers exactly: filter `cron_schedules` (adds a `weekly` interval), action `wp` → `schedule_events`, action `woodev_weekly_scheduled_events` → `weekly_license_check`, action `wp_ajax_woodev_verify_license` → `ajax_verify_license`. All four names are installed-site contracts.
  ```php
  Functions\expect( 'add_filter' )->once()->with( 'cron_schedules', Mockery::type( 'array' ) );
  Functions\expect( 'add_action' )->once()->with( 'wp', Mockery::type( 'array' ) );
  Functions\expect( 'add_action' )->once()->with( 'woodev_weekly_scheduled_events', Mockery::type( 'array' ) );
  Functions\expect( 'add_action' )->once()->with( 'wp_ajax_woodev_verify_license', Mockery::type( 'array' ) );
  $handler = new Cron_Handler( $plugin );
  $schedules = $handler->add_schedules( [] );
  $this->assertArrayHasKey( 'weekly', $schedules );
  ```
- [ ] **Step 4.2 — Run, verify fail.**
- [ ] **Step 4.3 — Create handler:** move the 4 methods verbatim; preserve the `weekly` schedule key, the `woodev_weekly_scheduled_events` event hook (scheduled via `wp_schedule_event` — must keep the exact hook name or live sites lose their scheduled job), and the `wp_ajax_woodev_verify_license` action. `weekly_license_check()`/`ajax_verify_license()` call `$plugin->get_license_instance()`.
- [ ] **Step 4.4 — Wire base, delete moved methods + hook lines.**
- [ ] **Step 4.5 — `composer check` green;** confirm no behavior change to the scheduled-event name (grep that `woodev_weekly_scheduled_events` appears only in the handler now).
- [ ] **Step 4.6 — Commit.** `refactor(base): extract Cron_Handler`.

---

## Task 5 — Remove the WooCommerce seams from the platform-neutral base

**Objective:** Delete WC-shaped code from `Woodev_Plugin` so the base is genuinely platform-neutral (audit §6.1.1). The WC behavior already lives on `Woodev\Framework\Woocommerce_Plugin`, which registers its own WC hooks.

**Files:**
- Modify: `woodev/class-plugin.php` (remove `add_woocommerce_hooks()` empty stub + any call to it; confirm no other WC-named method remains after Phase 3 removed the deprecated WC shims)
- Modify: `woodev/class-woocommerce-plugin.php` (ensure it registers its WC hooks directly in its own constructor, not via a base stub call)
- Update: `docs-internal/wiki/v2-extension-point-pattern.md` (the L-3 article documented `add_woocommerce_hooks()` as a positive pattern — replace with "WC hooks belong on `Woocommerce_Plugin`, not as a stub on the neutral base")
- Test: `tests/unit/PureWordpressPluginBlocksHandlerTest.php` / the no-WC neutrality test

- [ ] **Step 5.1 — Failing/guard test.** Add a reflection assertion that `Woodev_Plugin` declares **no** method whose name contains `woocommerce` (after Phase 3 + this task). Run → FAIL while `add_woocommerce_hooks()` still exists.
  ```php
  $methods = get_class_methods( \Woodev_Plugin::class );
  foreach ( $methods as $m ) {
      $this->assertStringNotContainsStringIgnoringCase( 'woocommerce', $m,
          'Woodev_Plugin (platform-neutral base) must declare no WooCommerce-named method' );
  }
  ```
- [ ] **Step 5.2 — Remove the stub.** Delete `add_woocommerce_hooks()` from the base and any base-side call. In `Woocommerce_Plugin`, register its WC hooks directly in its constructor (it overrode `add_woocommerce_hooks()` before — move that body to its own `__construct`/init).
- [ ] **Step 5.3 — Run.** The new guard test passes; the no-WC neutrality test (base loads with WC absent) passes; `composer check` green.
- [ ] **Step 5.4 — Update the wiki + commit.**
  ```bash
  composer check
  git add woodev/class-plugin.php woodev/class-woocommerce-plugin.php docs-internal/wiki/ tests/unit/
  git commit -m "refactor(base): remove WooCommerce seams from the platform-neutral base"
  ```

---

## Task 6 — Construction-readability pass (light)

**Objective:** With cron/links/translations/api-logging now handlers, confirm `__construct()` reads as a clean ordered list of `init_*_handler()` calls and the residual `add_hooks()` only wires what is genuinely base-owned (the empty extension-point hooks like `plugins_loaded → init_plugin`, `admin_init → init_admin`, enqueue hooks). Do **not** introduce a generic registry.

**Files:** `woodev/class-plugin.php`; `tests/unit/WoodevPluginConstructionTest.php` (if useful)

- [ ] **Step 6.1 — Review `__construct()` + `add_hooks()`.** Each extracted concern's hooks now live in its handler; ensure no orphaned `add_action`/`add_filter` for moved callbacks remains in `add_hooks()`.
- [ ] **Step 6.2 — Record metrics.** Note the new `class-plugin.php` line/method count vs the 1,435 / 87 baseline in `docs-internal/CURRENT-STATE.md` (for the Phase 6 "not a god-object" gate evidence).
- [ ] **Step 6.3 — `composer check` green + commit.** `refactor(base): tidy construction after handler extraction`.

---

## Sub-plan exit gate (= cleanbreak-plan Phase 4 gate)

- [ ] 4 inline concerns are now handlers under `woodev/handlers/`, each owning its own WP hooks.
- [ ] Every preserved hook/schedule/action name is byte-identical (cron `woodev_weekly_scheduled_events` + `weekly`; `wp_ajax_woodev_verify_license`; `plugin_action_links_{basename}`; `woodev_{id}_api_request_performed`; text domains).
- [ ] `Woodev_Plugin` declares no WooCommerce-named method; the no-WC neutrality test proves it loads with WooCommerce absent.
- [ ] `__construct()` is a readable list of handler constructions; no orphaned hook wiring.
- [ ] `composer check` green throughout (each task committed green).

## Self-review (scope vs D-3 / audit §6.1.3)

- "Extract clearest subsystems into injected handlers" → Tasks 1–4 (cron, links, translations, api-logging). "DI as far as WP allows / no container" → handlers constructed eagerly + injected the plugin reference; no container, no registry (pragmatic guard honored).
- "Base is platform-neutral / no WC seams" → Task 5. The corrected, smaller scope (base already 80% delegated) is reflected: this sub-plan does **not** re-extract the 18 existing handlers or rename the base — that would be churn without value.
- Open follow-up (out of scope, post-split): `class-payment-gateway.php` trait extraction (~2,378 lines) remains tracked in CURRENT-STATE as separate debt, not part of D-3.

## Related
- [platform-v2-cleanbreak-plan.md](platform-v2-cleanbreak-plan.md) — parent plan (this is Phase 4)
- [platform-v2-direction-audit-2026-06-03.md](platform-v2-direction-audit-2026-06-03.md) — D-3 decision + §6.1.3 scope
- [wiki/v2-extension-point-pattern.md](wiki/v2-extension-point-pattern.md) — updated by Task 5 (WC hooks belong on Woocommerce_Plugin)
