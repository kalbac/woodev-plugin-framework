
## P3 clean-break audit fixes — resolver compatibility window (2026-06-04)

### Implementation
- Processed `docs-internal/reviews/p3-cleanbreak-audit-packet.md` as an implementation/audit checklist for the landed P3 clean-break diff.
- Fixed the P3 blocker: explicit `Framework_Plugin_Loader_Definition` now carries optional `backwards_compatible` and maps it into resolver args, so the selected framework compatibility floor still works after legacy positional registration was deleted.
- Made `Framework_Resolver::load_plugins()` select the highest-version framework record deterministically even when `Woodev_Plugin` is already loaded, preventing compatibility-window checks from depending on class-table state.
- Changed missing `main_class` loader invocation from silent return to an `invalid_loader_definitions` entry and only marks plugins active after successful callback/main-class invocation.
- Added resolver regression coverage for the explicit backwards-compatible window, missing `main_class` invalidation, and `CAPABILITY_WOOCOMMERCE_PLUGIN` preloading only the WooCommerce base/helper classes.

### Verification
- Focused test: `vendor\bin\phpunit tests\unit\FrameworkResolverTest.php` passes — 21 tests / 77 assertions.
- Full gate: `composer check` passes — PHPCS 114/114, PHPStan 0 errors, PHPUnit 182 tests / 412 assertions.
- No gotcha file added: the reusable lesson is an application of the existing clean-break and explicit-loader rules, not a new independent gotcha.

### Next
- P3 clean-break gate is passed after audit fixes. Next action is P4: decompose `Woodev_Plugin` per `docs-internal/platform-v2-base-decomposition-subplan.md`.
- Existing `.serena/project.yml` and `.serena/memories/memory_maintenance.md` working-tree changes were left untouched.

## P2 pilot gate hardening — Edostavka-shaped fixture (2026-06-03)

### Implementation
- Processed `docs-internal/reviews/p2-pilot-audit-packet.md` as an audit checklist for the existing P2 pilot artifacts rather than as returned external findings.
- Hardened `tests/_fixtures/woodev-edostavka-pilot-plugin/woodev-edostavka-pilot-plugin.php`: the fixture now includes the concrete shipping method only after `woodev_edostavka_pilot_plugin()` constructs the real `Shipping_Plugin`, so the framework shipping base classes come from `Shipping_Plugin::__construct()` instead of Composer test autoload masking the include order.
- Strengthened `tests/unit/EdostavkaPilotFixtureTest.php`: pre-load assertions prove the plugin/method classes are absent before resolver callback execution; `add_filter( 'woocommerce_shipping_methods', ... )` is expected; `apply_filters()` is aliased; and the test now calls `register_shipping_methods( [] )` directly to assert the real registration result preserves `edostavka`.
- Expanded `docs-internal/migration/edostavka-data-preservation-checklist.md` with WooCommerce shipping-zone persistence: `woocommerce_shipping_zone_methods.method_id = edostavka` and potential `woocommerce_edostavka_{instance_id}_settings` options are now explicit release-blocking production rewrite checks.

### Verification
- Focused test: `vendor\\bin\\phpunit tests\\unit\\EdostavkaPilotFixtureTest.php` passes — 1 test / 11 assertions.
- Full gate: `composer check` passes — PHPCS 117/117, PHPStan 0 errors, PHPUnit 198 tests / 450 assertions.
- No new gotcha file: this was a direct application of existing test-integrity and data-preservation rules, not a new reusable framework gotcha.

### Next
- P2 gate is stronger for framework load-path readiness, but still intentionally does not prove live-site data preservation. Production plugin rewrite must use the checklist against real installed-site data.
- No Phase 3 deletion work was started in this session.

## Licensing v2 split - Woodev_Woocommerce_License_Settings (2026-06-03)

### Implementation
- First work in the admin/licensing v2 phase (per 2026-06-02 polish session handoff + next-session-prompt). Mapped the licensing subsystem with Serena MCP: 7 files in `woodev/licensing/` (4 classes + 3 API files). Only one hard WC coupling remained - `Woodev_License_Settings::set_wc_screen_ids()` registered a `woocommerce_screen_ids` filter unconditionally in `is_admin()`. The other 4 licensing files either have no WC coupling (`Woodev_Plugins_License`, `Woodev_License`) or are already behind `function_exists()` + filter contracts from Phase 5 cleanup #9 (`Woodev_License_Messages` for `wc_date_format()`/`wc_format_datetime()`, `Woodev_Licensing_API_Request` for `wc_print_r()`).
- New class `Woodev_Woocommerce_License_Settings` in `woodev/licensing/class-woocommerce-license-settings.php` - real implementation, 3 methods (`set_wc_screen_ids`, `register_license_settings`, `do_license_fields`) + constructor, verbatim copy of the original. Picked up by the existing `woodev/licensing/` classmap entry, no composer.json change needed.
- `Woodev_License_Settings` truncated to a deprecated shim: stores `$plugin` in a private property (silences PHPStan `unusedParameter`), emits `_deprecated_function()` + `_doing_it_wrong()` from the constructor. Class still resolves for any external `class_exists()` / `instanceof` check.
- `Woodev_Plugin::load_license_settings_fields()` now early-returns on `! Woodev_Helper::is_woocommerce_active()`, requires the new class file, instantiates `Woodev_Woocommerce_License_Settings`. Pure-WP plugins no longer add a callback to the `woocommerce_screen_ids` filter in `is_admin()`.
- New test `tests/unit/WoocommerceLicenseSettingsLocationTest.php` (3 tests, 14 assertions): (1) reflection proves the new class declares all 3 methods with the right visibility, (2) source regex on `class-plugin.php` proves the loader references the new FQCN and the `is_woocommerce_active()` gate, (3) source regex on the shim file proves the constructor calls `_doing_it_wrong()`. Pattern matches the B-2 location test (`WoocommerceUploadsPathLocationTest`).

### Verification
- Red-first confirmed: ran new test file before implementation, fatal `require_once` on the non-existent new file.
- Green after implementation: `composer check` passes - PHPCS 117/117 (was 116, +1 for the new file), **PHPStan 0 errors**, **PHPUnit 197 tests / 440 assertions** (was 194/426 at session start; +3 tests, +14 assertions).
- PHPStan flagged the shim's unused `$plugin` param on first run - fixed by assigning to a private property (mirrors the original constructor's `$this->plugin` assignment, satisfies PHPStan without a baseline ignore or `@phpstan-ignore-next-line` annotation). No baseline growth.
- No new gotcha files: the shim pattern is a clean application of the existing B-2 + M-1 + M-4 v2 split pattern (per `class-alias-phpstan-resolution` gotcha: real subclass in classmap, no `class_alias` for PHPStan visibility).
- Next-session-prompt file `C:\Users\maksi\AppData\Local\Temp\kilo\woodev-framework-next-session-prompt-2026-06-02.md` deleted (the task that triggered this session).

### Next
- Admin/licensing phase is **half done** (licensing subsystem). Admin pages (`woodev/admin/`) is the remaining scope for this phase.
- Alternative admin-phase targets the user might prefer: push notifications/webhooks (FUTURE #3), React admin UI (FUTURE #5), or broader helper-class cleanup.
- Deferred items from audit 2026-06-01 (B-3 cosmetic commit subject, L-2 5th test, render_select2_ajax shim edge case) remain untouched.
- v2 split boundary: pure-WP plugins using the framework now boot with zero WC coupling in `is_admin()` for both helper (M-1/L-4, 2026-06-02) and license settings (this commit). The licensing subsystem has no further clean v2 split surface.

## Polish session — B-2 FQCN fix + Woodev_Woocommerce_Helper split (M-1/L-4) (2026-06-02)

### Implementation
- **B-2 polish** (`d703f8c`, fix+test) — `Woodev_Plugin::get_woocommerce_uploads_path()` shim now references the FQCN `\Woodev\Framework\Woocommerce_Plugin` in both the `class_exists(...,false)` check and the delegate call. Previously the bare short name `Woocommerce_Plugin::class` resolved to the global-namespace `\Woocommerce_Plugin` (which does not exist — the class lives under `Woodev\Framework\`), so the shim silently fell through to the inline `wp_upload_dir()` fallback. Behavior was correct (same return value) but the shim did not actually exercise the new WC class location. Added `test_base_shim_uses_fqcn_for_woocommerce_plugin()` in `WoocommerceUploadsPathLocationTest.php` — a source-string regex assertion that the shim references the FQCN. The return-value test cannot distinguish the two paths because the WC class's method and the inline fallback compute the same string from `wp_upload_dir()`.
- **M-1 + L-4 helper-class split** — Created `woodev/class-woocommerce-helper.php` (`namespace Woodev\Framework; class Woocommerce_Helper`) holding the 4 WC-coupled methods moved from `Woodev_Helper`: `get_order_line_items()`, `is_order_virtual()`, `shop_has_virtual_products()`, `render_select2_ajax()`. Created `woodev/class-woocommerce-helper-alias.php` providing the global-namespace `Woodev_Woocommerce_Helper` alias (mirrors `class-woocommerce-plugin-alias.php`). Replaced the 4 methods in `woodev/class-helper.php` with deprecated shims that emit `_deprecated_function()` and delegate to the new class. The shim for `shop_has_virtual_products()` and `render_select2_ajax()` includes a `class_exists( '\Woodev\Framework\Woocommerce_Helper', false )` guard so they are safe no-ops in a no-WC context (pure-WP plugins). The two WC_Order-typed shims delegate unconditionally (the type-hint already requires WC). Updated `Woodev_Plugin_Bootstrap::is_woocommerce_active()`'s sibling in `class-framework-resolver.php` to load the new helper+alias files alongside `class-woocommerce-plugin.php` (only when `$requires_woocommerce_base` is true). Updated the internal caller `Woodev_Payment_Gateway::perform_credit_card_charge()` in `class-payment-gateway.php:2706` to use the FQCN `\Woodev\Framework\Woocommerce_Helper::is_order_virtual()` directly (no deprecation noise from our own code). Updated `tests/unit/PlatformNeutralHelperTest.php` to require the new files and to call `\Woodev_Woocommerce_Helper::shop_has_virtual_products()` (preserves the no-WC contract on the new class location). Added 2 new test files: `WoocommerceHelperLocationTest.php` (3 tests: declarations on the namespaced class, alias resolves via `is_a`, shim delegates and returns false in no-WC) and `WoocommerceHelperShimTest.php` (2 tests: shim returns false, shim source uses FQCN). Added the 2 new files to `composer.json` classmap (PHPStan needs them in the classmap; they do not comply with PSR-4 because the class lives in `class-woocommerce-helper.php` not `Woocommerce_Helper.php`, matching the existing `class-woocommerce-plugin.php` convention). The shim's internal code uses the FQCN `\Woodev\Framework\Woocommerce_Helper` (not the alias) so PHPStan resolves the static calls; the deprecation message and user-facing documentation still reference `Woodev_Woocommerce_Helper` as the migration target.

### Verification
- `composer check` green: PHPCS **116/116** (was 114/114; +2 for the new files), **PHPStan 0 errors**, **PHPUnit 194 tests / 426 assertions** (was 188/406 at session start; net +6 tests, +20 assertions).
- Two test files added: `WoocommerceHelperLocationTest` (3 tests, 13 assertions) and `WoocommerceHelperShimTest` (2 tests, 5 assertions). `PlatformNeutralHelperTest::test_shop_has_virtual_products_returns_false_without_woocommerce` migrated to the new class location.
- B-2 shim test extended: `WoocommerceUploadsPathLocationTest` now has 4 tests (was 3) with 7 assertions (was 5).
- All 4 shim methods verified to:
  1. Emit `_deprecated_function()` with the right (function, version, replacement) tuple.
  2. Reference the FQCN `\Woodev\Framework\Woocommerce_Helper` (regex test in `WoocommerceHelperShimTest`) — same FQCN trap as the B-2 polish.
  3. Be a safe no-op when the WC helper class is not loaded (only `shop_has_virtual_products()` and `render_select2_ajax()` shims; the other two require WC_Order).
- Gotcha: PHPStan does NOT follow `class_alias()` for classes declared in conditional `if ( ! class_exists(...) )` blocks. The shim's internal code uses the FQCN `\Woodev\Framework\Woocommerce_Helper` to work around this; the alias `Woodev_Woocommerce_Helper` is for user code and the deprecation message only. The existing `class-woocommerce-plugin-alias.php` has the same limitation but the B-2 shim already uses the FQCN internally, so it was not visible.

### Next
- User's next phase (per session-start) is **admin/licensing**. Deferred items from audit 2026-06-01 (L-2 5th test, cosmetic B-3 commit subject) remain untouched.
- Remaining audit lower-priority items: none — M-1/L-4 is now resolved.

## Audit follow-up — 12 of 13 deferred items from 2026-06-01 audit (2026-06-02)

### Implementation
- Continued from the 2026-06-01 audit session + 2026-06-02 B-1a/b/c + B-2/B-3/H1 follow-up. Tackled the lower-priority items in audit-2026-06-01.md #10 one item / one commit at a time, red-first.
- **H2 / H3 / H4** (`0d333eb`, test+refactor) — `Framework_Resolver::__construct()` now takes optional `?callable $update_notice_renderer` and `?callable $deactivation_notice_renderer` (defaults to no-op closures). `Woodev_Plugin_Bootstrap::__construct()` injects `[$this, 'render_update_notices']` and `[$this, 'render_deactivation_notice']`. Resolver no longer references `Woodev_Plugin_Bootstrap::instance()`. `load_plugins()` is now guarded by a `$loaded` flag (H3 — one-shot per instance for long-running WP-Cron/AS processes). `register_loader_definition()` and `register_legacy_plugin()` dedupe by `plugin_id` via an internal `plugin_ids` map; second registration with the same id throws `RuntimeException` (H4). 5 new tests in `FrameworkResolverTest.php`.
- **M-2** (`89bd1ee`, refactor) — `Woodev_Plugin_Bootstrap::is_woocommerce_active()` now delegates to `Woodev_Helper::is_woocommerce_active()` (single source of truth). No tests required (delegation).
- **M-3 + M-5** (`67a1ab6`, docs+style) — Added `@since 2.0.0 Must be overridden by plugin subclasses; returns null/empty in base.` to `get_documentation_url()`, `get_support_url()`, `get_sales_page_url()`. Fixed mixed tabs/spaces indentation at lines 486, 615, 618, 619 in `class-framework-resolver.php` (phpcbf did not auto-detect the mixed style; manual fix).
- **M-4** (`e1c079a`, refactor+test) — Moved `add_class_form_wrap_start()` and `add_class_form_wrap_end()` to `Woodev_Woocommerce_Plugin`. Base class retains deprecated shims using `_deprecated_function()` + `instanceof \Woodev\Framework\Woocommerce_Plugin` check (class-plugin.php has no `use` statement, so the FQ class name is required; mirrors the B-2 shim pattern). `tests/unit/AddClassFormWrapLocationTest.php` with 3 reflection-based tests.
- **L-1 / L-5 / L-6** (`303f128`, docs) — `@version` docblock synced to 1.4.1 in `class-plugin.php`; `Woodev_Lifecycle::install_default_settings()` comment rewritten to be platform-neutral (no longer describes `WC_Admin_Settings` as the target — Lifecycle no longer depends on it); `get_framework_file()` docblock extended with multi-version arbitration note.
- **L-2 (partial) + L-3** (`c758ca0`, test+docs) — 4 of 5 recommended test coverage gaps added to `FrameworkResolverTest.php` (multi-version arbitration, `minimum_wp_version` legacy, resolver boundary negative, bootstrap delegation). Created `docs-internal/wiki/v2-extension-point-pattern.md` documenting `add_woocommerce_hooks()` empty stub as positive pattern (L-3); updated `docs-internal/wiki/README.md` index. The 5th test (backwards_compatible window) was abandoned mid-session — see "Deferred" below.
- **L-2 (5th test, abandoned)** — the proposed `Backwards_Compat_Testable_Resolver::$loaded_framework` reflection approach failed with `ReflectionException: Property ... $loaded_framework does not exist` because `$loaded_framework` is a local variable in `Framework_Resolver::load_plugins()`, not a class property. PHPUnit's `@runInSeparateProcess` does not give a true fresh composer-classmap autoloader — `composer dump-autoload` already ran, so `\Woodev_Plugin` is autoloadable in any subprocess. Test file `FrameworkResolverBackwardsCompatibleTest.php` DELETED. Stale comment block referring to the dedicated test file removed from `FrameworkResolverTest.php`. Documented as deferred in CURRENT-STATE.md #11 (workflow rule: "fix turns out larger than estimated → stop and document partial PR").

### Verification
- `composer check` green: PHPCS clean, **PHPStan 0 errors**, **PHPUnit 188 tests / 406 assertions** (up from 177/369 at session start; net +11 tests, +37 assertions).
- H2 test: original test passed for the wrong reason (composer classmap includes `woodev/bootstrap.php`, so `\Woodev_Plugin_Bootstrap` was always autoloadable). Strengthened by adding a reflection check that the resolver source code does NOT contain the string `Woodev_Plugin_Bootstrap::instance()` (the actual concern of the audit item).
- H3 test: stronger version verifies the second `load_plugins()` call short-circuits, not just that the callback runs once.
- H4 test: exercises both `register_loader_definition()` and `register_legacy_plugin()` paths; verifies the second registration throws.
- M-4 test: pattern from `WoocommerceUploadsPathLocationTest.php` (B-2) — `getDeclaringClass()->getName() === \Woodev\Framework\Woocommerce_Plugin::class`.
- L-2 partial: 4 tests in `FrameworkResolverTest.php` cover (1) `register_loader_definition` accepts modern format and forwards to resolver, (2) legacy format with `minimum_wp_version` is rejected with `_doing_it_wrong`, (3) `Framework_Resolver` rejects unknown platforms, (4) `Woodev_Plugin_Bootstrap::__construct` injects the notice callbacks (verified via reflection on the resolver instance).
- Latent B-2 shim bug noticed: `Woocommerce_Plugin::class` resolves to `\Woocommerce_Plugin` (not `\Woodev\Framework\Woocommerce_Plugin`) because `class-plugin.php` has no namespace and no `use` statement. `class_exists( Woocommerce_Plugin::class )` returns false, so the B-2 shim falls through to the inline implementation. Behavior is correct (the inline implementation is the safe fallback), but the shim does not actually exercise the new WC class location. To fully exercise the B-2 shim path, change `Woocommerce_Plugin::class` to `\Woodev\Framework\Woocommerce_Plugin::class` in `class-plugin.php`. Logged as a follow-up; not release-blocking since the inline fallback is correct.
- Gotcha: `phpcbf` does not auto-detect mixed tabs/spaces — M-5 indentation was not flagged by `composer phpcbf`; manual fix required. Consider adding a `phpcs:tabwidth` check to `phpcs.xml` for the resolver file. (Not in scope for this session; logged.)
- Untracked files left in working tree per session-start protocol: `.claude/settings.local.json`, `.claude/worktrees/`, `.kiro/`, `.phpunit.result.cache`, `.serena/memories/memory_maintenance.md`, `plugins-reference/`. Pre-existing untracked ADRs (003/004) and `platform-v2-implementation-spec.md` from prior sessions also untouched.

### Next
- Future session should plan the user's stated next phase: **admin/licensing** work.
- Two smaller pre-2.0 polish items:
  - Fix the B-2 shim FQ class name (`Woocommerce_Plugin::class` → `\Woodev\Framework\Woocommerce_Plugin::class` in `class-plugin.php`).
  - Restore the cosmetic commit subject for B-3 (`$blocks_handler` was expanded to empty by PowerShell) — not strictly necessary.
- L-2 5th test (backwards_compatible window) is deferred per CURRENT-STATE.md #11. It needs either (a) extracting `$loaded_framework` from a local to a protected property, or (b) injecting an autoloader override into `load_plugins()`. Both are larger than the audit's "fix" budget; not release-blocking because the H3 `$loaded` guard and the existing 4 L-2 tests cover the underlying behaviors.
- The `Woodev_Helper` residual WC coupling (14 missed Phase 5 sites per the 2026-06-01 audit) remains open and the user should pick a path: well-designed helper-class split vs. continued `function_exists()` slices. The 2026-06-01 audit recommended (a).
- Do not continue Phase 6A paperwork, do not start Phase 6B, do not edit `plugins-reference/`, do not expand resolver/bootstrap scope further.

## Audit fixes — all 6 release-blocker items from 2026-06-01 audit (2026-06-02)

### Implementation
- Continued from the 2026-06-01 audit session (`audit-2026-06-01-next-session-prompt.md`). B-1a/b/c were already committed in the previous session; this session completed B-2, B-3, and H1.
- **B-2** (`2817143`) — moved `Woodev_Plugin::get_woocommerce_uploads_path()` to `Woodev_Woocommerce_Plugin::get_woocommerce_uploads_path()` with a deprecated shim on the base. Shim calls `_deprecated_function()` and delegates to the WC class when available, with a fallback to inline implementation for pure-WP contexts (defensive). Updated `docs/core-framework.md:649` example to call the WC class directly.
- **B-3** (`2bd041b`) — changed `Woodev_Plugin::$blocks_handler` to `?Woodev_Blocks_Handler = null` (nullable with default). Made `get_blocks_handler(): ?Woodev_Blocks_Handler` nullable. Pure-WP plugins now get `null` from the getter instead of a `TypeError`. `Woodev_Woocommerce_Plugin` unaffected (still initializes the property in `init_blocks_handler()`).
- **H1** (`ef3d067`) — added explicit `?Type` nullable annotations to 13 sites across 4 files: `class-payment-gateway.php` (2), `class-payment-gateway-my-payment-methods.php` (1), `handlers/abstract-hosted-payment-handler.php` (4), `handlers/abstract-payment-handler.php` (6). Removed the `error_reporting( error_reporting() & ~E_DEPRECATED )` mask in `RealisticPaymentFixtureTest.php:88-94`. Enabled `reportUnmatchedIgnoredErrors: true` in `phpstan.neon:78` — this immediately surfaced a dead `get_check_number` ignore pattern (eCheck API removed in s3, ignore was never removed). Removed the dead ignore in the same commit.

### Verification
- `composer check` green: PHPCS clean, **PHPStan 0 errors with `reportUnmatchedIgnoredErrors: true`** (authoritative proof that no ignore pattern is now dead), **PHPUnit 177 tests / 369 assertions** (up from 172/364 after the V-5 B-3 test started passing).
- New tests: `tests/unit/WoocommerceUploadsPathLocationTest.php` (B-2 — 3 tests) + `tests/unit/PaymentGatewayImplicitNullableTest.php` (H1 — 5 reflection-based tests). The B-3 test (`PureWordpressPluginBlocksHandlerTest.php`) was written earlier in the previous session.
- Audit prompt `audit-2026-06-01-next-session-prompt.md` is now obsolete — deleted. Scratch PHPStan configs (`phpstan-strict-v1/2/3.neon`) cleaned up.
- Gotcha files updated with resolution notes: `blocks-handler-typed-property-trap.md` (B-3), `php84-implicit-nullable-payment-handlers.md` (H1).
- Audit estimated 46 implicit-nullable sites; actual count was 13 (most untyped `$arg = null` params don't trigger the PHP 8.4 deprecation — only TYPED ones do). The fix scale was smaller than feared, but the principle (H1 audit item) still stands: implicit-nullable must be replaced with explicit `?Type`.

### Next
- Lower-priority audit findings remain in `audit-2026-06-01.md` and CURRENT-STATE.md Next Action #10: resolver edge cases (idempotency, plugin_id dedup, bootstrap-resolver coupling), `Woodev_Helper` residual WC coupling (14 missed sites), test coverage gaps (no `backwards_compatible` window test, no multi-version arbitration test, no end-to-end gateway integration test).
- Do not continue Phase 6A paperwork, do not start Phase 6B, do not edit `plugins-reference/`, do not expand resolver/bootstrap scope.
- Admin/licensing work (the user's stated next phase per his mental model) has not been started. Future session should plan it.
- One residual: the commit subject for B-3 (`2bd041b`) has a `$` escape bug — PowerShell expanded `$blocks_handler` to empty in the subject. The body of the commit is correct; the subject reads `make Woodev_Plugin::\ nullable`. Cosmetic only, but visible in `git log`.

## Independent audit — release-blocker findings + refactor process observations (2026-06-01)

### Implementation
- Read-only second-model audit initiated after the user noted the 2026-05-31 a7da0ea regression and the impression that "что-то всё как будто не туда пошло". Scope: `phpstan.neon` blanket ignores, `Woodev_Plugin` v2 split integrity, `Woodev_Payment_Gateway` restore, resolver/loader/bootstrap architecture, `Woodev_Helper` residual coupling, realistic fixtures.
- Ran PHPStan with the 4 suspect blanket ignores removed in a temp config (`phpstan-strict.neon`, then deleted): revealed **30 masked errors** across 5 patterns, all of the same shape as the a7da0ea bug.
- **3 release-blocker PHPStan-ignore masks** of the same class as a7da0ea: (1) `Woodev_Payment_Gateway_API_Payment_Notification_Response::#` class-wide hides 6 unguarded calls in `class-payment-gateway-hosted.php:440-452` (checkout fatal risk); (2) `Woodev_Box_Packer_Item::get_product()` masks interface-contract violation in `class-packer-separatly.php:38` (`pack()` fatal risk); (3) `Woodev\Framework\Shipping\Shipping_API` interface references 6 non-existent types — broken contract, 20 errors.
- **2 base-class contract leaks** that contradict the v2 split goal: `Woodev_Plugin::get_woocommerce_uploads_path()` (line 1258, WC-specific); `Woodev_Plugin::get_blocks_handler()` typed-property trap (line 71 + 1018, TypeError for pure-WP subclasses).
- **1 dead ignore** to remove: `Woodev_Payment_Gateway_Payment_Token::get_check_number()` (eCheck removed in s3).
- **1 PHP 8.4+ deprecation mask**: `RealisticPaymentFixtureTest.php:88-94` `error_reporting` workaround for implicit-nullable `$arg = null` parameters in legacy payment handler files. Pre-existing framework bug, not a test issue.
- **6 lower-priority observations**: resolver has invisible runtime dep on `Woodev_Plugin_Bootstrap::instance()` (3 sites, masked by happy-path tests); `load_plugins()` not idempotent; resolver does not dedupe by `plugin_id`; `Woodev_Helper` retains hard WC coupling in `get_order_line_items()`/`is_order_virtual()`/`render_select2_ajax()` (14 Phase 5 slices missed these); `Woodev_Plugin_Bootstrap::is_woocommerce_active()` duplicates `Woodev_Helper::is_woocommerce_active()`; 166/338 assertions is thin for 10+ dependent plugins.
- Refactor process observation (per user's "что-то пошло не так" note): Phase 5 went paperwork-heavy (3+ reference drafts) instead of advancing to admin/licensing (the user's stated next phase after split); 14 minimal-atomic cleanup slices created a `function_exists()`-fallback surface instead of a clean helper-class split; the deprecation mask in the payment fixture is the same pattern as a7da0ea (workaround that hides a real bug).

### Verification
- No code changes (audit + docs only). `composer check` still passes (no PHP/runtime files changed): PHPCS 113/113, PHPStan 0 errors, PHPUnit 166 tests / 338 assertions (per CURRENT-STATE).
- All findings recorded as gotchas and prioritized in `CURRENT-STATE.md` Next Actions #7–10.
- Detailed audit: `docs-internal/audit-2026-06-01.md`. Three new gotcha files: `shipping-api-broken-contract.md`, `blocks-handler-typed-property-trap.md`, `php84-implicit-nullable-payment-handlers.md`. Expanded: `gateway-type-methods-required.md` (added the 3 remaining blanket-ignore masks + cross-cutting enforcement rule).
- Gotcha count: 12 → 15 across 6 → 8 namespaces.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- The next session must fix the 3 release-blocker PHPStan-ignore masks (B-1a/b/c) and the 2 base-class contract leaks (B-2, B-3) BEFORE any v2.0 release candidate is tagged. Then fix the PHP 8.4+ deprecation (H1) and enable `reportUnmatchedIgnoredErrors: true` in `phpstan.neon:78` to catch future dead ignores.
- Do not continue Phase 6A paperwork, do not start Phase 6B, do not edit `plugins-reference/`, do not expand resolver/bootstrap scope.
- The user should decide between two paths for the residual Woodev_Helper WC coupling: (a) one well-designed helper-class split, or (b) continue with minimal-atomic `function_exists()` slices. The current trajectory is (b) and has created the same kind of workaround-pattern that masked a7da0ea.
- Admin/licensing work (the user's stated next phase per his mental model) has not been started. Future session should plan it.

## Payment gateway base-method regression fix (2026-05-31)

### Implementation
- A local branch review (after the payment fixture slice) surfaced a CRITICAL regression: commits `728c6f9` ("remove 47 deprecated methods") + `d85a1f9` removed **57 methods** from `Woodev_Payment_Gateway` (1045 lines); **28 were still called** by surviving framework code, including hot-path `is_available()` → `$this->get_plugin()` / `$this->currency_is_accepted()`, and capture/refund order-meta calls. On any installed gateway plugin this is a guaranteed `Call to undefined method` fatal at checkout.
- Root cause of non-detection: `phpstan.neon` had a blanket ignore for `Call to an undefined method Woodev_Payment_Gateway(_Direct|_Hosted)::#` (comment: "methods exist at runtime" — no longer true after deletion). Unit tests never instantiated a concrete gateway.
- Phase 0: diffed merge-base (`2d607b75`) vs HEAD; enumerated the 57 removed methods, cross-referenced surviving call sites, and confirmed surviving properties/constants/plugin-side deps (`log`, `get_api_log_message`, `get_documentation_url`). Confirmed capture.php's `get_order_capture_maximum`/`get_order_authorization_amount` calls are on the capture handler itself (not the gateway), so those deprecated wrappers needed no restore.
- Phase 1: restored the still-called infrastructure block on `Woodev_Payment_Gateway` from the pre-cleanup version: `get_id`, `get_id_dasherized`, `get_plugin`, `is_enabled`, `currency_is_accepted`, `get_accepted_currencies`, `get_payment_currency`, `get_available_countries`, order-meta CRUD + `get_order_meta_prefix`, environment family (`get_environments`/`get_environment`/`get_environment_name`/`is_environment`/`is_production_environment`/`is_test_environment`), `csc_*`, `share_settings`/`inherit_settings`, `add_support`/`remove_support`/`set_supports`, debug family (`debug_off`/`debug_log`/`debug_checkout`/`add_debug_message`), `is_direct_gateway`/`is_hosted_gateway`, `is_detailed_customer_decline_messages_enabled`, `get_api` stub, checkout order-id getters, `get_not_configured_error_message`, `add_api_request_logging`/`log_api_request`.
- Phase 2 (reconciliation): deliberately did NOT restore WC-inherited `get_method_title()`, eCheck-only `supports_check_field()`, or the deprecated capture wrappers; preserved the intentional eCheck/ACH/US-payment removal. Fixed a latent `'off' == debug_off()` loose comparison to a clean `debug_off()` (behavior-preserving).
- Phase 3: removed the blanket PHPStan ignore lines for the gateway hierarchy.
- Phase 4: extended `RealisticPaymentFixtureTest` with a reflection-based behavioral check that executes the restored pure getters (`currency_is_accepted`, environment checks, `csc_*`, `inherit_settings`, decline-messages, `get_plugin`, `is_direct_gateway`) on a `newInstanceWithoutConstructor()` gateway — no payment runtime executed.

### Verification
- `composer check` green: PHPCS 113/113, **PHPStan 0 errors with the blanket gateway ignore removed** (authoritative proof every still-called gateway method now resolves; future regressions of this kind will be caught), PHPUnit 166 tests / 355 assertions.
- Removing the fixture's redundant `protected get_plugin()` override surfaced and confirmed the restored base `public get_plugin()` is genuinely exercised.
- Gotcha updated: extended `docs-internal/gotchas/gateway-type-methods-required.md` with this larger recurrence and the PHPStan-ignore-masking lesson (dedup: same root cause as the s3 gotcha).

### Next
- This fixes the release-blocker. Consider an integration test that constructs a concrete gateway through the full WC runtime and exercises `is_available()`/refund/capture end-to-end. Audit other broad `Call to an undefined method <Class>::` PHPStan ignores for similar masking risk.

## Platform v2 sandbox payment runtime validation (2026-05-31)

### Implementation
- Re-anchored on `PLANS.md`, the accepted Platform v2 implementation spec, ADR-003/004, the 2026-05-31 roadmap reconciliation, and the already-completed shipping fixture slice: framework-first, sandbox validation second, no Phase 6B, no edits to `plugins-reference/`, no resolver/bootstrap scope expansion.
- Confirmed the payment gap: Platform v2 payment coverage was only synthetic (`FrameworkResolverTest` declares `eval()`-based abstract subclasses of `Woodev_Payment_Gateway_Plugin`), with no realistic file-based payment fixture — the exact analog of the gap the shipping fixture closed.
- Inspected `plugins-reference/woodev-vkredit` read-only for realism cues only: entry constants, `register_plugin()` with `is_payment_gateway`, singleton plugin class `extends Woodev_Payment_Gateway_Plugin`, `gateways` arg keyed by class name, concrete gateway `extends Woodev_Payment_Gateway_Hosted` loaded include-based via `init_plugin()`.
- Verified feasibility before coding: among the payment base `includes()` chain only `Woodev_Payment_Gateway extends WC_Payment_Gateway` is a parse-time WC dependency; `Woodev_Script_Handler` (needed by payment-form/my-payment-methods) is loaded during base construction before `includes()` runs; `init_plugin()` is hooked on `plugins_loaded:15`, so it does not auto-run in the unit context.
- Added red-first `tests/unit/RealisticPaymentFixtureTest.php`; it initially failed because the fixture did not exist.
- Added the fixture under `tests/_fixtures/woodev-realistic-payment-plugin`: explicit loader definition (platform `woocommerce`, payment capability), include-based callback, singleton `Woodev_Realistic_Payment_Plugin extends Woodev_Payment_Gateway_Plugin` with `gateways` arg, abstract gateway base, and concrete `Woodev_Realistic_Gateway extends Woodev_Payment_Gateway_Hosted`.
- The test proves explicit loader definition, payment capability + WooCommerce gating, selected-framework early payment base availability, include-based callback graph, real `Woodev_Payment_Gateway_Plugin` construction (full `includes()` chain), `Woodev_Woocommerce_Plugin` inheritance, and concrete `Woodev_Payment_Gateway` gateway-class registration via `get_gateway_class_names()`. No gateway is instantiated, so no payment business logic runs.

### Verification
- Red-first targeted test failed on missing fixture file, as expected.
- `vendor\bin\phpunit tests\unit\RealisticPaymentFixtureTest.php` passed after fixture implementation: 1 test / 8 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 166 tests / 338 assertions.
- The strict unit-output context (`failOnRisky`/`beStrictAboutOutputDuringTests`) initially flagged the test risky because the payment `includes()` chain loads legacy handler files that still use implicit-nullable parameters (a pre-existing PHP 8.4+ deprecation). Scoped `E_DEPRECATED` masking around base construction in the test resolves this without touching production payment files.
- No edits were made to `plugins-reference/`; no production plugin repo touched; no Phase 6B started; resolver/bootstrap responsibilities were not expanded.
- Gotcha compilation: candidate noted (constructing the real payment base in a strict unit context surfaces PHP 8.4+ implicit-nullable deprecations from legacy payment handler files); kept in SESSION-LOG/CURRENT-STATE rather than a separate gotcha file because it is test-environment-specific and already documented inline in the test.

### Next
- A realistic payment sandbox validation slice is implemented and verified, alongside the shipping slice. Further work should only add another narrow fixture/test if it exposes a framework-readiness gap not covered by the shipping and payment slices; do not resume Phase 6A paperwork or start Phase 6B from this repo.

## Platform v2 sandbox shipping runtime validation (2026-05-31)

### Implementation
- Re-anchored on `PLANS.md`, the accepted Platform v2 implementation spec, ADR-003/004, and the 2026-05-31 roadmap reconciliation: framework-first, sandbox validation second, no Phase 6B, no edits to `plugins-reference/`, no resolver/bootstrap scope expansion.
- Inspected existing Platform v2 coverage: current tests already prove pure-WP loading, WC gating, invalid loader handling, legacy adapter capability mapping, selected-path early classes, and synthetic callback-time payment/shipping subclass declaration.
- Inspected `plugins-reference/woocommerce-edostavka` and `plugins-reference/woocommerce-yandex-delivery` read-only for realism cues only: entry constants, include-based bootstrap/callback, singleton plugin class, method ID, shipping method registration, abstract shipping method base, courier/pickup method variants, checkout/session/rate/AJAX/cron cues.
- Chose the narrowest useful framework-readiness artifact: a generic file-based fixture under `tests/_fixtures/woodev-realistic-shipping-plugin` plus one focused unit test, instead of modifying sandbox copies or continuing migration-contract paperwork.
- Added red-first `tests/unit/RealisticShippingFixtureTest.php`; it initially failed because the fixture did not exist.
- Added the fixture entry + include graph: explicit loader definition, include-based callback, concrete `Woodev_Realistic_Shipping_Plugin extends Shipping_Plugin`, abstract shipping method base, courier and pickup method classes.
- The test proves explicit loader definition, WooCommerce requirement gate, selected-framework early shipping base availability, include-based callback loading, real `Shipping_Plugin` construction, and `Woodev_Woocommerce_Plugin` inheritance against a realistic shipping-plugin shape.

### Verification
- Red-first targeted test failed on missing fixture file, as expected.
- `vendor\bin\phpunit tests\unit\RealisticShippingFixtureTest.php` passed after fixture implementation: 1 test / 8 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 165 tests / 330 assertions.
- No edits were made to `plugins-reference/`; no production plugin repo touched; no Phase 6B started; resolver/bootstrap responsibilities were not expanded.
- Gotcha compilation: no new non-obvious framework-behavior gotcha discovered.

### Next
- A realistic sandbox validation slice is implemented and verified. Further work should only add another narrow fixture/test if it exposes a framework-readiness gap not covered here; do not resume Phase 6A paperwork or start Phase 6B from this repo.

## Platform v2 roadmap reconciliation (2026-05-31)

### Implementation
- No code changes. Roadmap/strategy reconciliation session, re-anchored on `PLANS.md`.
- Reconstructed the true roadmap: `PLANS.md` strategic intent (platform-neutral base hierarchy, framework-first, plugins rewritten later) narrowed into the accepted `platform-v2-implementation-spec.md` phasing P1 resolver → P2 loader → P3 platform split → P4 early classes → P5 module cleanup → P6 migration contracts → P6B real rewrites; broad feature vision (shipping universality, licensing webhooks/UI, box-packer, DI, React, EDD) deferred post-v2.0.
- Verified the actual source, not just doc claims: `woodev/class-framework-resolver.php`, `woodev/class-framework-plugin-loader-definition.php`, `woodev/class-woocommerce-plugin.php` (`Woocommerce_Plugin extends Woodev_Plugin`) + alias, payment/shipping bases `extends \Woodev\Framework\Woocommerce_Plugin`. P1–P5 are genuinely implemented.
- Verified test matrix coverage: `FrameworkResolverTest` (pure-WP-without-WC load, WC gating, invalid loader, EDD rejection, PHP-requirement skip, legacy-adapter mapping, callback timing, selected-path early classes) and `BootstrapRegistrationTest::test_version_sorting_highest_first` (multi-version arbitration). Confirmed base-owned modules guard WC helpers via `function_exists()` fallbacks (e.g. `licensing/class-license-messages.php`).
- Inspected sandbox copies `plugins-reference/woocommerce-edostavka` and `.../woocommerce-yandex-delivery`: both are WooCommerce shipping plugins still using legacy positional `register_plugin()` + flags and `extends Woodev_Plugin` directly — i.e. they still consume the OLD framework.
- Created `docs-internal/platform-v2-roadmap-reconciliation.md`; updated `CURRENT-STATE.md` (header, Next Actions, Platform v2 table row 31, Active Queue) and the Serena Phase 6 memory.

### Verification
- Docs/analysis-only session; `composer check` not run because no PHP/runtime files changed.
- Drift finding: no boundary-violating sequencing drift. Mild soft drift — Phase 6A produced only paper contracts (template + 2 reference drafts + gap analysis) and never validated the new framework runtime against a realistic plugin shape; the new resolver/loader/`Woocommerce_Plugin` path has only synthetic inline-fixture coverage.
- Gotcha compilation: no new non-obvious framework-behavior gotcha discovered.
- Commit: not created per session instruction.

### Next
- Single next safe category: sandbox-based framework readiness validation (framework-first, sandbox-only) — prove the new explicit-loader + `Woocommerce_Plugin` path hosts a realistic shipping-plugin shape via a realistic fixture and/or read-only conformance mapping from a sandbox copy.
- Do NOT start Phase 6B, do NOT edit `plugins-reference/`, do NOT expand resolver/bootstrap scope. Pause further migration-contract rehearsal.

## Platform v2 Phase 6A second reference draft (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md` and existing Phase 6A boundary docs.
- Purpose: create a second reference-based draft migration contract for `plugins-reference/woocommerce-yandex-delivery` to validate that the Phase 6A workflow is not overly tailored to the Edostavka plugin shape.
- Inspected `plugins-reference/woocommerce-yandex-delivery` read-only and gathered comprehensive structured evidence across all contract sections.
- Created `docs-internal/platform-v2-phase6a-yandex-reference-contract-draft.md`, explicitly labeled reference-based, non-production, not release-blocking, and not a real Phase 6B migration contract.
- Filled all standard contract sections with values justified from copied-source evidence; marked missing installed-site data as requiring real production repo / installed-site validation.
- Included a comparison table with the Edostavka draft showing complementary coverage: Yandex exercises custom DB tables, REST routes, Action Scheduler scheduling payloads, WC session keys, checkout POST fields, shipping rate meta, localized script objects, a custom WC_Email class, and competitor detection — sections Edostavka stressed less.
- Compared both drafts and confirmed no new framework-side template gap appeared; the template works for two different plugin shapes without structural changes.
- Phase 6A is now complete — validated against both reference plugins.

### Verification
- Docs-safe verification: confirmed all contract sections are filled with evidence-backed values; comparison table documents complementary coverage.
- Runtime checks not run because this session changed docs/memory artifacts only.
- Gotcha compilation: no new non-obvious framework behavior gotcha discovered; no `docs-internal/gotchas/` update required.
- Updated `CURRENT-STATE.md`, `DOCS-INDEX.md`, and `.serena/memories/platform-v2/phase-6-migration-contracts.md`.
- Did not start Phase 6B, did not rewrite production plugins, did not modify `plugins-reference/`, and did not expand resolver/bootstrap scope.

### Next
- Phase 6A is complete. Both reference drafts confirm the template is fillable for different plugin shapes.
- Production Phase 6B must start in a real selected plugin repository with source, release history, package identity, and installed-site DB evidence before any rewrite.

## Platform v2 Phase 6A first reference draft contract (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md` and the existing Phase 6A boundary docs.
- Re-stated the Phase 6A purpose: validate the migration-contract workflow from read-only copied plugin evidence only, not create a production contract.
- Inspected both reference plugins as read-only evidence sources and did not edit `plugins-reference/`.
- Selected `woocommerce-edostavka` as the first draft target because it covers more migration-contract continuity risks in one reference copy: legacy maps, deprecated wrappers, WP-Cron, WC API callbacks, webhook IDs, data stores, shipping method state, and order meta.
- Created `docs-internal/platform-v2-phase6a-edostavka-reference-contract-draft.md` and clearly labeled it reference-based, non-production, not release-blocking, and not a real Phase 6B migration contract.
- Filled the standard contract sections where copied-source evidence justified values, and marked incomplete values as requiring real production repo / installed-site validation.
- Confirmed the draft revealed no new template gap; remaining unknowns are expected Phase 6B evidence gaps, not framework/template gaps.
- Updated `CURRENT-STATE.md`, `DOCS-INDEX.md`, and `.serena/memories/platform-v2/phase-6-migration-contracts.md`.
- Did not start Phase 6B, did not rewrite production plugins, did not modify runtime/framework PHP, and did not expand resolver/bootstrap scope.

### Verification
- Docs-safe verification: reviewed the created draft against the template section list and confirmed all standard required sections are represented.
- Git verification: checked working tree noise before edits and staged only the Phase 6A draft/session artifacts for commit.
- Runtime checks not run because this session changed docs/memory artifacts only.
- Gotcha compilation: no new non-obvious framework behavior gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final hash reported in chat.

### Next
- Phase 6A has a fillable first reference draft artifact, but production migration remains blocked until a real plugin repo is selected.
- The next safe step is still Phase 6B in the selected production plugin repository with source, release history, package identity, and installed-site DB evidence before any rewrite.

## Platform v2 Phase 6A reference contract validation (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md` and Phase 6 entry state.
- Re-stated the boundary: Phase 6A in this repo is framework-side migration-contract methodology only; Phase 6B starts only in a real selected production plugin repo.
- Inspected `plugins-reference/woocommerce-edostavka` and `plugins-reference/woocommerce-yandex-delivery` as read-only reference inputs only.
- Confirmed both plugins are WooCommerce shipping plugins using include-based framework loading and legacy `register_plugin()` entry shape.
- Used Edostavka as the stronger legacy-migration/WP-Cron/WC API webhook/data-store stress test.
- Used Yandex as the stronger multi-method/custom-table/REST/checkout-session/Action-Scheduler stress test.
- Created `docs-internal/platform-v2-phase6a-reference-gap-analysis.md` to record evidence and template fit.
- Refined `docs-internal/platform-v2-migration-contract-template.md` for WC API callbacks, Action Scheduler hooks/mode/args/groups, WC data-store keys, checkout/session state, shipping package/rate meta, email template paths/placeholders, and legacy migration maps.
- Updated `DOCS-INDEX.md`, `CURRENT-STATE.md`, and `.serena/memories/platform-v2/phase-6-migration-contracts.md`.
- Did not edit `plugins-reference/`, did not modify framework runtime PHP, did not start Phase 6B, and did not expand resolver/bootstrap scope.

### Verification
- Evidence check: the original template covered the required spec list, but reference plugins exposed ambiguous fields that needed sharper rows rather than runtime changes.
- Docs-only change; `composer check` not run because no PHP/runtime files changed.
- Gotcha compilation: no new non-obvious gotcha discovered; this was methodology refinement, not a framework behavior bug.
- Commit: not created per session instruction.

### Next
- Phase 6A workflow is solid enough to stop in this repo.
- The next useful step is Phase 6B in a real selected plugin repository, where a plugin-specific contract must be filled from source, release history, and installed-site evidence before any rewrite.

## Platform v2 Phase 6 migration contract entry (2026-05-30)

### Implementation
- Entered Phase 6 strictly from `docs-internal/platform-v2-implementation-spec.md` after confirming Phase 5 is review-cleared.
- Read required Phase 6 sources, including ADR-003, ADR-004, latest session log entry, and `.serena/memories/platform-v2/phase-5-cleanup.md`.
- Summarized Phase 6 entry constraints: contract before rewrite, no resolver/bootstrap scope expansion, include-based production loading, installed-site contracts are release-blocking.
- Searched for existing migration contract docs, templates, checklists, and first-target evidence; none existed before this session.
- Determined that `woocommerce-edostavka` appears only as an illustrative loader example, not a selected Phase 6 target.
- Created `docs-internal/platform-v2-migration-contract-template.md` as the narrowest safe Phase 6 artifact.
- Updated `DOCS-INDEX.md` to expose the new Phase 6 template.
- Did not touch production plugin repositories, did not rewrite production plugin PHP, and did not expand resolver/bootstrap scope.

### Verification
- Evidence check: no clear first production plugin target exists in this framework repo.
- Real plugin-specific contract cannot be completed here because required option, license, hook, method-ID, cron, REST/AJAX/admin, log, job, email, and schema facts live in production plugin repos or installed-site history.
- Docs-only change; `composer check` not run because no PHP/runtime files changed.
- Gotcha compilation: no new gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: not created per session instruction.

### Next
- Select the first production plugin target explicitly.
- Continue in that production plugin repository to copy/fill the contract template from source, release history, and installed-site evidence before any rewrite begins.

## Platform v2 Phase 5 post-review follow-up (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha; did not start Phase 6.
- Treated the external review as findings to verify, not as scope expansion.
- Added red-first coverage for `Woodev_License_Messages::get_date_i18n()` preserving the `woocommerce_date_format` filter without requiring WooCommerce helpers.
- Added red-first coverage for ISO offset date strings preserving WordPress site timezone output in no-WooCommerce contexts.
- Updated licensing date formatting to use the original WooCommerce helper path when available, and a WordPress timezone-aware fallback using the same WooCommerce date-format filter otherwise.
- Re-evaluated Low findings after the Medium fix: `wc_enqueue_js()` wrapper/filter equivalence is not a clean atomic follow-up because exact preservation would alter the shared `Woodev_Helper::enqueue_js()` output contract.
- Added red-first coverage for the licensing API debug stringifier preserving the `woocommerce_print_r_alternatives` fallback-filter contract.
- Updated the private licensing request stringifier to delegate to `wc_print_r()` when available and otherwise mirror WooCommerce fallback alternatives without a hard WooCommerce dependency.

### Verification
- `vendor\bin\phpunit tests\unit\PlatformNeutralLicensingTest.php` failed first on the missing date-format filter, then passed after the narrow date-format fix.
- `vendor\bin\phpunit tests\unit\PlatformNeutralLicensingTest.php` failed first on the ISO offset timezone regression, then passed after the WordPress timezone fallback.
- `vendor\bin\phpunit tests\unit\PlatformNeutralLicensingTest.php` failed first on the missing `woocommerce_print_r_alternatives` contract, then passed after the private stringifier fix: 7 tests / 15 assertions.
- Code simplifier review touched only a behavior-neutral test docblock alignment; production code remained unchanged after review.
- ReadLints reported no issues for the three touched files.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 164 tests / 322 assertions.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Phase 5 is review-cleared for Phase 6 planning in a future session.
- Do not start Phase 6 from this follow-up session; the next session should begin with migration-contract planning, not production plugin rewrites.

## Platform v2 Phase 5 helper fallback cleanup (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Re-scanned the remaining base-owned WooCommerce helper paths and found one additional clean helper-only boundary after slice 12: `Woodev_Helper::format_percentage()` still hard-depended on `wc_format_decimal()`.
- Added `tests/unit/PlatformNeutralHelperTest.php` coverage first, proving the current failure mode when `wc_format_decimal()` is unavailable in a platform-neutral unit context and locking the percentage-formatting trim/precision contract.
- Replaced the hard dependency in `Woodev_Helper::format_percentage()` with a guarded path that preserves `wc_format_decimal()` when WooCommerce is available and falls back to local decimal formatting otherwise.
- Re-scanned again and identified one final helper-only seam still clean enough for the same session: `Woodev_Helper::shop_has_virtual_products()` fataled on direct `wc_get_products()` usage in a no-WooCommerce unit context.
- Extended `tests/unit/PlatformNeutralHelperTest.php` first with a focused failing test for the missing `wc_get_products()` path.
- Guarded `Woodev_Helper::shop_has_virtual_products()` so it now returns `false` when WooCommerce product helpers are unavailable, while preserving the published-virtual-product query path when WooCommerce is loaded.
- Preserved include-based runtime loading, public static helper API shape, WooCommerce execution paths where available, and resolver/bootstrap boundaries; did not expand resolver scope or start Phase 6 work.

### Verification
- `vendor\bin\phpunit tests\unit\PlatformNeutralHelperTest.php` failed first on undefined `wc_format_decimal()`, then passed after the first helper fallback change: 3 tests / 7 assertions.
- `vendor\bin\phpunit tests\unit\PlatformNeutralHelperTest.php` failed first on undefined `wc_get_products()`, then passed after the second helper fallback change: 4 tests / 8 assertions.
- `vendor\bin\phpunit tests\unit\HelperTest.php` passed after both changes: 81 tests / 89 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 161 tests / 319 assertions.
- Re-scan after the second slice leaves only the boundary-sensitive `wc_rest_check_manager_permissions()` path in the REST settings controller plus intentional WooCommerce wrappers/diagnostics in `woodev/class-helper.php`.
- No third clean atomic Phase 5 slice is currently defined from that remaining boundary, so the session stopped rather than forcing a resolver/runtime ownership change.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Stop after these two helper fallback slices rather than forcing the REST permissions seam or intentional WooCommerce wrappers in `Woodev_Helper`.
- External review by another model is required before any Phase 6 migration-contract or production-loader work begins.
- If Phase 5 resumes later, re-scan the residual REST/settings boundary and continue only if a new truly atomic slice definition appears.

## Platform v2 Phase 5 helper doing_it_wrong cleanup (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Re-scanned the remaining base-owned WooCommerce helper paths and identified a smaller isolated helper seam than the boundary-sensitive REST permissions path: `Woodev_Helper::maybe_doing_it_early()` in `woodev/class-helper.php` still called `wc_doing_it_wrong()` directly.
- Added `tests/unit/PlatformNeutralHelperTest.php` first, proving the current failure mode when `wc_doing_it_wrong()` is unavailable in a platform-neutral unit context and locking the early-hook diagnostic contract.
- Replaced the hard `wc_doing_it_wrong()` dependency in `Woodev_Helper::maybe_doing_it_early()` with a guarded path that keeps `wc_doing_it_wrong()` when WooCommerce is available and falls back to WordPress `_doing_it_wrong()` otherwise.
- Preserved the WooCommerce-specific diagnostic path where available, plus include-based runtime loading, public static API shape, and resolver boundaries; did not move helper/runtime behavior into the resolver or expand toward Phase 6.

### Verification
- `vendor\bin\phpunit tests\unit\PlatformNeutralHelperTest.php` failed first with the expected undefined `wc_doing_it_wrong()` error, then passed after the implementation: 2 tests / 4 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 159 tests / 315 assertions.
- Re-scan after the slice still leaves two residual areas only: `wc_rest_check_manager_permissions()` in the REST settings controller and broader WooCommerce-oriented helper/wrapper seams in `woodev/class-helper.php`.
- Those remaining areas are not cleanly atomic from the current ownership boundary and should not be forced without a narrower slice definition or external review.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Stop after this atomic Phase 5 slice rather than forcing a boundary-sensitive REST/settings change or a broad helper refactor.
- External review by another model remains required before any Phase 6 migration-contract or production-loader work begins.
- If Phase 5 resumes later, re-scan the remaining residual helper seams and continue only with another clearly atomic slice.

## Platform v2 Phase 5 setup wizard doing_it_wrong cleanup (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Re-scanned the remaining base-owned WooCommerce helper paths and confirmed the next smallest safe Phase 5 slice was setup wizard step-registration error reporting in `woodev/admin/abstract-plugin-admin-setup-wizard.php`.
- Added `tests/unit/PlatformNeutralSetupWizardTest.php` first, proving the current failure mode when `wc_doing_it_wrong()` is unavailable in a platform-neutral unit context and locking the invalid-step diagnostic contract.
- Replaced direct `wc_doing_it_wrong()` usage in `Woodev_Plugin_Setup_Wizard::register_step()` with WordPress `_doing_it_wrong()`.
- Preserved installed-site step-registration behavior, include-based runtime loading, and resolver boundaries; did not move setup wizard runtime behavior into the resolver or expand Phase 6 scope.

### Verification
- `vendor\bin\phpunit tests\unit\PlatformNeutralSetupWizardTest.php` failed first with the expected undefined `wc_doing_it_wrong()` error, then passed after the implementation: 1 test / 2 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 157 tests / 311 assertions.
- Re-scan after the slice left two residual helper seams: `wc_rest_check_manager_permissions()` in the REST settings controller and WooCommerce-oriented helper/wrapper paths in `woodev/class-helper.php`.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Stop after three atomic Phase 5 slices in this session, per session protocol.
- External review by another model is now required before any Phase 6 migration-contract or production-loader work begins.
- If Phase 5 resumes later, re-scan the remaining base-owned helper seams and continue only with another clearly atomic slice.

## Platform v2 Phase 5 job batch handler enqueue cleanup (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Re-scanned the remaining base-owned WooCommerce helper paths and confirmed the next smallest safe Phase 5 slice was the isolated `wc_enqueue_js()` path in `woodev/utilities/class-woodev-job-batch-handler.php`.
- Added `tests/unit/PlatformNeutralJobBatchHandlerTest.php` first, proving the current failure mode when `wc_enqueue_js()` is unavailable in a platform-neutral unit context and locking the inline JavaScript queue contract.
- Replaced direct `wc_enqueue_js()` usage in `Woodev_Job_Batch_Handler::render_js()` with `Woodev_Helper::enqueue_js()`.
- Preserved installed-site batch-handler payload output, footer print-hook registration, include-based runtime loading, and resolver boundaries; did not move background-job runtime behavior into the resolver.

### Verification
- `vendor\bin\phpunit tests\unit\PlatformNeutralJobBatchHandlerTest.php` failed first with the expected undefined `wc_enqueue_js()` error, then passed after the implementation: 1 test / 3 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 156 tests / 309 assertions.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Continue Phase 5 platform-neutral module cleanup from `docs-internal/platform-v2-implementation-spec.md`.
- Re-scan the remaining base-owned WooCommerce helper paths and pick the next smallest tested slice, most likely the setup wizard `wc_doing_it_wrong()` path or another equally narrow base-owned helper seam.
- Do not expand resolver runtime behavior and do not rewrite production plugin loaders before migration contract docs exist.

## Platform v2 Phase 5 licensing date formatting cleanup (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Re-scanned the remaining base-owned WooCommerce helper paths and confirmed the next smallest safe Phase 5 slice was licensing date formatting in `woodev/licensing/class-license-messages.php`.
- Extended `tests/unit/PlatformNeutralLicensingTest.php` first, locking the no-WooCommerce date-formatting contract for numeric and string expiration dates.
- Replaced `wc_date_format()`, `wc_string_to_datetime()`, and `wc_format_datetime()` in `Woodev_License_Messages::get_date_i18n()` with WordPress date formatting based on the site `date_format` option.
- Preserved installed-site expiration-message output shape, include-based runtime loading, and resolver boundaries; did not expand resolver scope or move licensing runtime behavior into the resolver.

### Verification
- `vendor\bin\phpunit tests\unit\PlatformNeutralLicensingTest.php` failed first with the expected undefined `wc_date_format()` error, then passed after the implementation: 4 tests / 12 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 155 tests / 306 assertions.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Continue Phase 5 platform-neutral module cleanup from `docs-internal/platform-v2-implementation-spec.md`.
- Re-scan the remaining base-owned WooCommerce helper paths and pick the next smallest tested slice, most likely the job batch handler `wc_enqueue_js()` path.
- Do not expand resolver runtime behavior and do not rewrite production plugin loaders before migration contract docs exist.

## Platform v2 Phase 5 settings API doing_it_wrong cleanup (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Re-scanned the remaining base-owned WooCommerce helper paths and confirmed the next smallest safe Phase 5 slice was the isolated settings API error-path usage of `wc_doing_it_wrong()` in `woodev/settings-api/abstract-class-settings.php`.
- Extended `tests/unit/PlatformNeutralSettingsApiTest.php` first, locking the register-setting and register-control failure-message contract in a no-WooCommerce unit context.
- Replaced `wc_doing_it_wrong()` with WordPress `_doing_it_wrong()` in `Woodev_Abstract_Settings::register_setting()` and `Woodev_Abstract_Settings::register_control()`.
- Preserved installed-site failure messages, public settings API behavior, include-based runtime loading, and resolver boundaries; did not expand resolver scope or pull WooCommerce runtime assumptions back into the base.

### Verification
- `composer test -- --filter PlatformNeutralSettingsApiTest` failed first with the expected undefined `wc_doing_it_wrong()` error, then passed after the implementation: 5 tests / 17 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 154 tests / 304 assertions.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Continue Phase 5 platform-neutral module cleanup from `docs-internal/platform-v2-implementation-spec.md`.
- Re-scan the remaining base-owned WooCommerce helper paths and prefer the next smallest tested slice, most likely licensing date formatting helpers in `woodev/licensing/class-license-messages.php` or the job batch handler `wc_enqueue_js()` path.
- Do not expand resolver runtime behavior and do not rewrite production plugin loaders before migration contract docs exist.

## Platform v2 Phase 5 admin notice JavaScript cleanup (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Re-checked the remaining WooCommerce helper dependencies in base-owned modules and confirmed the smallest safe next Phase 5 slice was the isolated admin notice dismiss JavaScript path in `Woodev_Admin_Notice_Handler`.
- Added `tests/unit/PlatformNeutralAdminNoticeTest.php` first, proving the current failure mode when `wc_enqueue_js()` is unavailable in a platform-neutral unit context and locking the dismiss-notice JavaScript queue contract.
- Replaced direct `wc_enqueue_js()` usage in `Woodev_Admin_Notice_Handler::render_admin_notice_js()` with `Woodev_Helper::enqueue_js()`.
- Completed the existing platform-neutral JavaScript queue helper by registering `Woodev_Helper::print_js()` on admin and frontend footer script hooks when queued JavaScript is first added.
- Preserved installed-site dismiss AJAX behavior, notice placeholder selectors, include-based runtime loading, public wrappers, and resolver boundaries; did not move admin notice runtime behavior into the resolver or reintroduce WooCommerce runtime assumptions into the base.

### Verification
- `composer test -- --filter PlatformNeutralAdminNoticeTest` failed first with the expected undefined `wc_enqueue_js()` error, then passed after the implementation: 2 tests / 8 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 152 tests / 300 assertions.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: `e82eefd`.

### Next
- Continue Phase 5 platform-neutral module cleanup from `docs-internal/platform-v2-implementation-spec.md`.
- Re-scan the remaining base-owned WooCommerce helper paths and pick the next smallest tested slice, likely `wc_doing_it_wrong()` in settings API, licensing date formatting helpers, or the job batch handler `wc_enqueue_js()` path.
- Do not expand resolver runtime behavior and do not rewrite production plugin loaders before migration contract docs exist.

## Platform v2 Phase 5 dependency size-parser cleanup (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Re-checked the remaining WooCommerce helper dependencies in base-owned modules and confirmed the smallest safe next Phase 5 slice was the PHP setting size parser path in `woodev/class-woodev-plugin-dependencies.php`.
- Added `tests/unit/PlatformNeutralDependenciesTest.php` first, proving the current failure mode when `wc_let_to_num()` is unavailable in a platform-neutral unit context and locking the incompatible PHP setting byte-conversion contract for size-based ini values.
- Replaced direct `wc_let_to_num()` usage in `Woodev_Plugin_Dependencies::get_incompatible_php_settings()` with a local platform-neutral byte conversion helper that preserves threshold comparisons plus formatted `expected`/`actual` notice payload values.
- Preserved installed-site behavior, admin notice payload shape, include-based runtime loading, resolver boundaries, and public wrappers; did not move dependency handling into the resolver or reintroduce WooCommerce runtime assumptions into the base.

### Verification
- `composer test -- --filter PlatformNeutralDependenciesTest` failed first with the expected undefined `wc_let_to_num()` error, then passed after the implementation: 2 tests / 6 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 150 tests / 292 assertions.
- IDE lints for the changed production and test files were clean.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Continue Phase 5 platform-neutral module cleanup from `docs-internal/platform-v2-implementation-spec.md`.
- Re-scan the remaining base-owned WooCommerce helper paths and pick the next smallest tested slice, likely a narrow `wc_enqueue_js()` dependency in a base-owned admin or utility module if it can be isolated cleanly.
- Do not expand resolver runtime behavior and do not rewrite production plugin loaders before migration contract docs exist.

## Platform v2 Phase 5 beta opt-in helper cleanup (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Re-checked the remaining WooCommerce helper dependencies in base-owned modules and confirmed the smallest safe next Phase 5 slice was the plugin-updater-adjacent beta opt-in helper path in `Woodev_Plugin`.
- Added `tests/unit/PlatformNeutralPluginUpdaterTest.php` first, proving the current failure mode when `wc_string_to_bool()` is unavailable in a platform-neutral unit context and locking the installed-site `beta_version` option contract.
- Replaced direct `wc_string_to_bool()` usage in `Woodev_Plugin::is_beta_allowed()` with a local platform-neutral boolean helper that preserves the existing WooCommerce-compatible truthy semantics for updater beta opt-in decisions.
- Preserved installed-site behavior, the `beta_version` option key, plugin updater integration, include-based runtime loading, public wrappers, and resolver boundaries; did not move updater behavior into the resolver or reintroduce WooCommerce runtime assumptions into the base.

### Verification
- `composer test -- --filter PlatformNeutralPluginUpdaterTest` failed first with the expected undefined `wc_string_to_bool()` error, then passed after the implementation: 1 test / 3 assertions.
- Independent review checkpoint completed immediately after the slice via a separate-model audit; no bugs or resolver/base-boundary regressions were found, with only an optional note that broader legacy truthy variants could be asserted in a future test if needed.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 148 tests / 286 assertions.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Continue Phase 5 platform-neutral module cleanup from `docs-internal/platform-v2-implementation-spec.md`.
- Best next candidate: a small tested cleanup in `Woodev_Plugin_Dependencies`, most likely the PHP setting size parser path that still uses `wc_let_to_num()`, only if it can be isolated without pulling WooCommerce runtime assumptions back into the base.
- Do not expand resolver runtime behavior and do not rewrite production plugin loaders before migration contract docs exist.

## Platform v2 Phase 5 lifecycle event sanitization cleanup (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Re-checked the remaining WooCommerce helper dependencies in base-owned modules and selected the next smallest safe Phase 5 slice: lifecycle event-history sanitization in `woodev/class-lifecycle.php`.
- Added `tests/unit/PlatformNeutralLifecycleTest.php` first, proving the current failure mode when `wc_clean()` is unavailable in a platform-neutral unit context and locking the stored event-history cleaning contract.
- Replaced direct `wc_clean()` calls in `Woodev_Lifecycle::store_event()` with a local recursive sanitization helper that preserves scalar and nested-array cleaning behavior for event names, plugin versions, and event payload data.
- Preserved installed-site behavior, public lifecycle APIs, event option names, include-based runtime loading, and resolver boundaries; did not move lifecycle ownership, change migration behavior, or expand WooCommerce runtime assumptions in `Woodev_Plugin`.

### Verification
- `composer test -- --filter PlatformNeutralLifecycleTest` failed first with the expected undefined `wc_clean()` error, then passed after the implementation: 2 tests / 13 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 147 tests / 283 assertions.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Independent review checkpoint tightened: run a separate-model audit after the next small Phase 5 cleanup slice and before Phase 6 migration contracts / production plugin rewrites.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Continue Phase 5 platform-neutral module cleanup from `docs-internal/platform-v2-implementation-spec.md`.
- Best next candidate: a small plugin-updater-adjacent cleanup in `Woodev_Plugin`, most likely the beta opt-in helper path, only if it can be isolated without reintroducing WooCommerce runtime assumptions into the base.
- Do not expand resolver runtime behavior and do not rewrite production plugin loaders before migration contract docs exist.

## Platform v2 Phase 5 licensing helper cleanup (2026-05-30)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Inspected the remaining small WooCommerce helper dependencies in base-owned modules and confirmed the next smallest safe Phase 5 slice was licensing utility helper cleanup.
- Added `tests/unit/PlatformNeutralLicensingTest.php` first, proving the current failure mode when `wc_strtolower()`, `wc_print_r()`, and `wc_is_valid_url()` are unavailable in a platform-neutral unit context.
- Replaced direct WooCommerce helper usage in `woodev/licensing/class-plugin-license.php` with a local lowercase helper that preserves case-insensitive action validation for licensing API dispatch.
- Replaced direct WooCommerce helper usage in `woodev/licensing/api/class-licensing-api-request.php` with a local `print_r` wrapper that preserves the existing request stringification contract used by request logging.
- Replaced direct WooCommerce URL validation in `woodev/licensing/api/class-licensing-api.php` with a local validator that preserves the previous `http`/`https` plus `FILTER_VALIDATE_URL` contract.
- Preserved installed-site behavior, public wrappers, include-based runtime loading, and resolver boundaries; did not move payment, shipping, licensing runtime behavior, or production plugin loaders.

### Verification
- `composer test -- --filter PlatformNeutralLicensingTest` failed first with the expected undefined WooCommerce helper errors, then passed after the implementation: 3 tests / 10 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 145 tests / 270 assertions.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Independent review checkpoint scheduled: run a separate-model audit after the next 1-2 small Phase 5 cleanup slices and before Phase 6 migration contracts / production plugin rewrites.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Continue Phase 5 platform-neutral module cleanup from `docs-internal/platform-v2-implementation-spec.md`.
- Best next candidate: another small tested cleanup slice in remaining base-owned modules, likely utilities or plugin-updater-adjacent helpers only if they can be isolated without pulling WooCommerce runtime assumptions back into the base.
- Do not expand resolver runtime behavior and do not rewrite production plugin loaders before migration contract docs exist.

## Platform v2 Phase 5 settings helper cleanup (2026-05-29)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Inspected the remaining small WooCommerce helper dependencies in platform-neutral modules and chose the smallest safe Phase 5 slice: settings API boolean and URL helper cleanup.
- Added `tests/unit/PlatformNeutralSettingsApiTest.php` first, proving the current failure mode when `wc_bool_to_string()`, `wc_string_to_bool()`, and `wc_is_valid_url()` are unavailable in a pure platform-neutral unit context.
- Replaced direct WooCommerce helper usage in `woodev/settings-api/abstract-class-settings.php` with local helper methods that preserve WooCommerce-compatible boolean semantics and the installed-site `yes`/`no` storage contract.
- Replaced direct WooCommerce URL validation in `woodev/settings-api/class-setting.php` with a local validator that preserves the previous `http`/`https` plus `FILTER_VALIDATE_URL` contract.
- Preserved installed-site behavior, public API shape, include-based runtime loading, and resolver boundaries; did not move payment, shipping, licensing runtime behavior, or production plugin loaders.

### Verification
- `composer test -- --filter PlatformNeutralSettingsApiTest` failed first with the expected undefined WooCommerce helper errors, then passed after the implementation: 3 tests / 13 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 142 tests / 260 assertions.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Continue Phase 5 platform-neutral module cleanup from `docs-internal/platform-v2-implementation-spec.md`.
- Best next small slice: licensing utility helper replacement (`wc_strtolower()`, `wc_print_r()`, licensing API URL validation) with tests first.
- Defer broader utility/background-job/session cleanup until targeted regression coverage exists because it touches WooCommerce-specific runtime hooks.
- Do not expand resolver runtime behavior and do not rewrite production plugin loaders before migration contract docs exist.

## Platform v2 Phase 5 deprecation-helper cleanup (2026-05-29)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Inspected residual WooCommerce helper usage in base-owned modules: lifecycle, API, settings, licensing, plugin updater, and utilities.
- Chose the smallest safe Phase 5 cleanup slice: remove WooCommerce-only deprecation wrappers from base-owned API, lifecycle, and licensing compatibility methods.
- Replaced `wc_deprecated_function()` with `_deprecated_function()` in `Woodev_API_Base::require_tls_1_2()` and `Woodev_Lifecycle::do_update()`.
- Replaced `wc_deprecated_argument()` with `_deprecated_argument()` in deprecated `Woodev_Plugins_License` arguments.
- Preserved installed-site contracts: public methods, deprecation versions, replacement text, return/delegation behavior, and production include-based loading were not changed.
- Did not expand resolver scope and did not move payment, shipping, licensing runtime behavior, or production plugin loaders.
- Added `tests/unit/PlatformNeutralDeprecationTest.php` covering absence of WooCommerce deprecation wrappers in the touched base-owned files and behavior of the API/lifecycle deprecated wrappers.

### Verification
- `composer test -- --filter PlatformNeutralDeprecationTest` passed: 3 tests / 13 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 139 tests / 247 assertions.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Continue Phase 5 platform-neutral module cleanup from `platform-v2-implementation-spec.md`.
- Good next candidates: settings boolean/URL helper removal (`wc_bool_to_string()`, `wc_string_to_bool()`, `wc_is_valid_url()`) or licensing utility helper replacement (`wc_strtolower()`, `wc_print_r()`, licensing API URL validation), with tests first.
- Defer background job/session/debug-tool cleanup until there is focused regression coverage because it touches WooCommerce admin/debug/session behavior.
- Do not expand resolver runtime behavior and do not rewrite production plugin loaders before migration contract docs exist.

## Platform v2 Phase 3 stop and callback timing coverage (2026-05-29)

### Implementation
- Continued strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Inspected remaining WooCommerce-adjacent helpers/state in `Woodev_Plugin` after commits `4001ae5` and `edc3f25`.
- Stopped Phase 3: remaining base items are compatibility wrappers (`handle_features_compatibility()`, `get_supported_features()`, `is_hpos_compatible()`, `load_template()`, `log()`), public callbacks kept for installed-site continuity, or broader Phase 5 module cleanup (`includes()` loading compatibility modules used by lifecycle/helper/utilities).
- Did not move another runtime ownership slice because no small safe slice remains without changing installed-site contracts or starting Phase 5 cleanup.
- Proceeded to the next Platform v2 step by adding Phase 4 callback timing coverage for specialized bases.
- Added a resolver test proving payment and shipping child classes can be declared inside the plugin callback after early capability loading.
- Kept resolver scope unchanged: no payment/shipping/licensing/runtime behavior moved into resolver, and production plugin loading remains include-based.

### Verification
- `composer test -- --filter FrameworkResolverTest` passed: 13 tests / 42 assertions.
- `composer test -- --filter PluginCompatibilityTest` passed: 19 tests / 34 assertions after avoiding global `WC_VERSION` test pollution.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 136 tests / 234 assertions.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Start Phase 5 platform-neutral module cleanup from `docs-internal/platform-v2-implementation-spec.md`.
- First inspect residual WooCommerce helper usage in base-owned modules, especially lifecycle, API, settings, licensing, plugin updater, and utilities.
- Do not expand resolver runtime behavior and do not rewrite production plugin loaders before migration contract docs exist.

## Platform v2 WooCommerce feature compatibility ownership (2026-05-29)

### Implementation
- Continued Phase 3 with the remaining WooCommerce feature compatibility ownership slice.
- Moved HPOS/Cart/Checkout Blocks feature declarations from pure `Woodev_Plugin` into `Woodev\Framework\Woocommerce_Plugin`.
- Kept installed-site public wrappers on `Woodev_Plugin`: `handle_features_compatibility()` is runtime-neutral, `get_supported_features()` returns an empty array, and `is_hpos_compatible()` returns false.
- Updated `Woodev_Payment_Gateway_Plugin` and `Woodev\Framework\Shipping\Shipping_Plugin` to inherit from `Woodev\Framework\Woocommerce_Plugin`, preserving feature declarations for specialized WooCommerce plugin paths.
- Updated resolver early capability loading so payment/shipping capabilities load the WooCommerce base first and source early classes from the selected framework copy, not the current plugin registration path.
- Fixed `Shipping_Plugin::get_shipping_method()` nullable parameter declaration exposed by loading the shipping base in isolated unit tests.
- Preserved production include-based loading and did not expand resolver scope into payment, shipping, licensing, or runtime behavior beyond early class availability.

### Verification
- `composer test -- --filter WoocommercePluginTest` passed: 9 tests / 30 assertions.
- `composer test -- --filter FrameworkResolverTest` passed: 12 tests / 38 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 135 tests / 230 assertions.
- Independent review found and fixes addressed: specialized bases missing WooCommerce inheritance, payment/shipping early capabilities missing WooCommerce base dependency, selected framework path not used for early class loading, and autoload-enabled `class_exists()` checks in resolver.
- Gotcha compilation: updated existing `docs-internal/gotchas/multiversion-early-class-guards.md`; no new gotcha file required.
- Commit: `4001ae5`.

### Next
- Inspect remaining WooCommerce-adjacent helpers in `Woodev_Plugin` and decide whether one more true runtime ownership slice remains.
- If no safe slice remains, stop Phase 3 and proceed to the next Platform v2 step from `platform-v2-implementation-spec.md`.
- Do not rewrite production plugin loaders until migration contract docs exist.

## Platform v2 WooCommerce template loader ownership (2026-05-29)

### Implementation
- Continued Phase 3 with the next small WooCommerce-adjacent runtime ownership slice.
- Moved WooCommerce `load_template()` behavior from `Woodev_Plugin` into `Woodev\Framework\Woocommerce_Plugin`.
- Kept the public installed-site `load_template()` wrapper on `Woodev_Plugin` as a runtime-neutral no-op, while WooCommerce plugins retain the previous `wc_get_template()` behavior through the WooCommerce base override.
- Kept generic `get_template_path()` ownership in `Woodev_Plugin` because it only derives the plugin's own `/templates` directory and is not WooCommerce runtime state.
- Added pure WordPress coverage proving `Woodev_Plugin::load_template()` does not request `wc_get_template()`.
- Added WooCommerce contract coverage proving `Woodev_Woocommerce_Plugin::load_template()` still calls `wc_get_template()` with the default plugin template path.
- Preserved production include-based loading and did not expand resolver scope into payment, shipping, licensing, or runtime behavior.

### Verification
- `composer test -- --filter WoocommercePluginTest` passed: 6 tests / 23 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 130 tests / 217 assertions.
- Independent verification: PASS; verifier ran `composer test -- --filter WoocommercePluginTest`, `composer check`, inspected base/WooCommerce `load_template()` behavior, confirmed pure WordPress no-`wc_get_template` coverage and WooCommerce positive path coverage.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Continue Phase 3 with another small tested WooCommerce runtime ownership slice from `Woodev_Plugin` to `Woodev_Woocommerce_Plugin`, or pause to review whether the remaining `Woodev_Plugin` WooCommerce-adjacent helpers are true runtime ownership.
- Preserve public wrappers where installed-site compatibility requires them.
- Do not rewrite production plugin loaders until migration contract docs exist.

## Platform v2 WooCommerce logger ownership (2026-05-29)

### Implementation
- Continued Phase 3 with the next small WooCommerce-adjacent runtime ownership slice.
- Moved WooCommerce logger storage and `logger()` ownership from `Woodev_Plugin` into `Woodev\Framework\Woocommerce_Plugin`.
- Kept the public installed-site `log()` wrapper contract intact by overriding `log()` in the WooCommerce base with the previous WooCommerce logger behavior.
- Updated `Woodev_Plugin::assert()` to call the public `log()` wrapper instead of directly reaching into WooCommerce logger internals.
- Added pure WordPress coverage proving `Woodev_Plugin` construction does not request `wc_get_logger()`.
- Added WooCommerce contract coverage proving `Woodev_Woocommerce_Plugin::log()` still writes through `wc_get_logger()->add()`.
- Preserved production include-based loading and did not expand resolver scope into payment, shipping, licensing, or runtime behavior.

### Verification
- `composer test -- --filter WoocommercePluginTest` passed: 4 tests / 21 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 128 tests / 215 assertions.
- Independent verification: PASS; verifier ran `composer test -- --filter WoocommercePluginTest`, `composer check`, inspected public `log()`/`assert()` compatibility, and completed a hostile pure-WordPress `wc_get_logger()` probe.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Continue Phase 3 with another small tested WooCommerce runtime ownership slice from `Woodev_Plugin` to `Woodev_Woocommerce_Plugin`.
- Good next candidate: WooCommerce template helpers; preserve public wrappers where installed-site compatibility requires them.
- Do not rewrite production plugin loaders until migration contract docs exist.

## Platform v2 WooCommerce system-status ownership (2026-05-29)

### Implementation
- Continued Phase 3 strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Moved WooCommerce system-status PHP incompatibility row generation from `Woodev_Plugin` into `Woodev\Framework\Woocommerce_Plugin`.
- Kept the installed-site WooCommerce hook contract intact: `Woodev\Framework\Woocommerce_Plugin::add_woocommerce_hooks()` still registers `woocommerce_system_status_environment_rows` against the same public method name.
- Removed the WooCommerce system-status method from pure `Woodev_Plugin` so WordPress-only plugin construction no longer carries this WooCommerce runtime surface.
- Added constructor isolation coverage proving pure WordPress `Woodev_Plugin` loading does not initialize Blocks state and does not call WooCommerce system-status row generation.
- Preserved production include-based loading and did not expand resolver scope into payment, shipping, licensing, or runtime behavior.

### Verification
- `composer test -- --filter WoocommercePluginTest` passed: 2 tests / 18 assertions.
- `composer check` passed twice after final cleanup: PHPCS 113/113, PHPStan 0 errors, PHPUnit 126 tests / 212 assertions.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Continue Phase 3 with another small tested WooCommerce runtime ownership slice from `Woodev_Plugin` to `Woodev_Woocommerce_Plugin`.
- Good next candidates: WooCommerce logger helpers or WooCommerce template helpers; preserve public wrappers where installed-site compatibility requires them.
- Do not rewrite production plugin loaders until migration contract docs exist.

## Platform v2 WooCommerce runtime state ownership (2026-05-29)

### Implementation
- Continued Phase 3 strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, ADR-004, and the multi-version early class guard gotcha.
- Added pure WordPress constructor coverage proving `Woodev_Plugin` does not register WooCommerce hooks and does not initialize the WooCommerce Blocks handler path.
- Moved the initial WooCommerce runtime feature state slice into `Woodev\Framework\Woocommerce_Plugin`: `supported_features` parsing/storage and Blocks handler construction now happen in the WooCommerce platform base.
- Kept production plugin loading include-based and did not expand resolver scope into payment, shipping, licensing, or runtime behavior.
- Preserved the guarded installed-site global alias contract for `Woodev_Woocommerce_Plugin`.

### Verification
- `vendor\bin\phpunit tests\unit\WoocommercePluginTest.php` passed: 2 tests / 17 assertions.
- `composer test:unit` passed: 126 tests / 211 assertions.
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 126 tests / 211 assertions.
- Gotcha compilation: no new non-obvious gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

### Next
- Continue Phase 3 with another small tested WooCommerce runtime ownership slice from `Woodev_Plugin` to `Woodev_Woocommerce_Plugin`.
- Good next candidates: WooCommerce logger helpers, WooCommerce template helpers, or WooCommerce system-status behavior; keep public wrappers only when installed-site compatibility requires them.
- Do not rewrite production plugin loaders until migration contract docs exist.

## Platform v2 namespace + WooCommerce hook ownership (2026-05-29)

### Implementation
- Refactored the initial Platform v2 resolver slice into `Woodev\Framework\*`: `Framework_Resolver`, `Framework_Plugin_Loader_Definition`, and `Woocommerce_Plugin` now start namespaced.
- Kept production loading include-based: `bootstrap.php` explicitly requires resolver files, and the selected framework copy requires WooCommerce support files through resolver capability loading.
- Preserved installed-site compatibility for `Woodev_Woocommerce_Plugin` via guarded `class_alias()` in `woodev/class-woocommerce-plugin-alias.php`; no Composer/autoload runtime contract was introduced.
- Moved the first WooCommerce runtime ownership slice out of `Woodev_Plugin`: WooCommerce hook registration now lives in `Woodev\Framework\Woocommerce_Plugin::add_woocommerce_hooks()`.
- Left `Woodev_Plugin::add_woocommerce_hooks()` as an empty protected extension point so pure WordPress plugins do not register WooCommerce runtime hooks.
- Added `tests/unit/WoocommercePluginTest.php` for WooCommerce hook ownership without requiring WooCommerce.
- Updated resolver tests to require namespaced framework files explicitly and assert namespaced classes.
- Updated Composer classmap only for dev/test tooling discovery of the guarded alias file; production plugins still load through framework includes.

### Verification
- `composer check` passed: PHPCS 113/113, PHPStan 0 errors, PHPUnit 125 tests / 202 assertions.
- Independent verification returned PARTIAL only because Bash was denied in the verifier worktree; source inspection passed namespace/include loading and WooCommerce hook ownership checks, with no FAIL findings.

### Next
- Continue Phase 3 by moving additional WooCommerce-adjacent runtime state from `Woodev_Plugin` to `Woodev_Woocommerce_Plugin` in small tested slices.
- Keep resolver limited to selection, validation, requirements, notices, and early include loading; do not move payment/shipping/licensing runtime behavior into resolver.

## Platform v2 resolver facade implementation (2026-05-29)

### Follow-up decision
- New Platform v2 implementation classes should use the `Woodev\Framework\*` namespace from the start; the next session must refactor the initial resolver slice before adding more platform behavior.
- Legacy global classes remain acceptable only for installed compatibility entry points, existing public API continuity, or explicit aliases/shims required by migration contracts.
- Namespaced Platform v2 classes must still be loaded explicitly through framework include/require paths in production plugins; Composer/autoload is not a plugin runtime loading mechanism.

### Implementation
- Started strictly from `docs-internal/platform-v2-implementation-spec.md`, ADR-003, and ADR-004; applied section 14 keep/discard before reusing spike assumptions.
- Added `Woodev_Framework_Plugin_Loader_Definition` with explicit `plugin_id`, `plugin_name`, versions, `plugin_file`, closed platform values, requirements, `main_class`/`callback`, and early capabilities.
- Added `Woodev_Framework_Resolver` as the minimal resolver behind the compatibility facade: registration normalization, version sorting, PHP/WP/WC requirement gates, early capability class loading, invalid-definition tracking, notices, and callback/main-class invocation.
- Refactored `Woodev_Plugin_Bootstrap` into a thin compatibility facade over the resolver while keeping `instance()`, legacy `register_plugin()`, reflected state, notices, and helper wrappers available.
- Added thin `Woodev_Woocommerce_Plugin` class as the future WooCommerce runtime owner; no WooCommerce runtime behavior was moved in this slice.
- Kept legacy `is_payment_gateway` and `load_shipping_method` only as early capability adapter inputs, not as runtime type truth.
- Guarded new globally named early-loaded classes with `class_exists(..., false)` to preserve multi-version vendored include safety.

### Verification
- Pre-commit review found four resolver risks; fixed multi-version redeclare guards, `main_class`-only invocation, legacy WC capability notice data, and PHP requirement enforcement.
- Added `tests/unit/FrameworkResolverTest.php` covering explicit definitions, invalid definitions, reserved EDD, capability validation, no-WooCommerce WordPress loading, WooCommerce skip, `main_class` bootstrap, PHP skip, and legacy capability mapping.
- `composer check` ✅: PHPCS, PHPStan, and 124 unit tests / 194 assertions green.
- Gotcha compilation: added `docs-internal/gotchas/multiversion-early-class-guards.md` and indexed it in `GOTCHAS.md`.
- Commit: pending at time of entry creation; final commit hash reported in chat.

## Platform v2 implementation spec (2026-05-29)

### Planning output
- Read `PLANS.md`, strategy alignment, deep analysis, ADR-003, ADR-004, Epic 1 spec, dependency matrix, DOCS-SCHEMA, CURRENT-STATE, DOCS-INDEX, SESSION-LOG, and GOTCHAS index.
- Created `docs-internal/platform-v2-implementation-spec.md` as the active Platform v2 implementation source.
- Decision: stale bridge-first parts of `platform-v2-epic1-spec.md` are superseded by a resolver-first implementation plan.
- Decision: `woodev/bootstrap.php` remains the installed compatibility entry path, but real early-loading logic belongs behind it in a minimal resolver.
- Decision: explicit loader definitions replace loose plugin type flags as the preferred v2 API; inheritance/contracts remain the runtime source of truth.
- Decision: production plugin rewrites require migration contract gates before PHP changes begin in those plugins.
- Added fixture/test matrix, early class availability rules, platform class boundaries, and keep/discard guidance for `feat/platform-v2-epic1-spike`.
- Updated `docs-internal/DOCS-INDEX.md` and `docs-internal/CURRENT-STATE.md` so future agents start implementation from the new spec.

### Verification
- Docs-only session; no PHP implementation was changed.
- Tests/build: not run because only internal planning docs were changed.
- Gotcha compilation: no new non-obvious technical gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

## Platform v2 resolver deep analysis (2026-05-29)

### Planning analysis
- Read `PLANS.md`, Platform v2 strategy alignment, dependency matrix, ADR-001/002, Epic 1 spec, CURRENT-STATE, FUTURE-BACKLOG, top 2026-05-29/2026-05-28 session log entries, current `bootstrap.php`, current `Woodev_Lifecycle`, and SkyVerge loader/namespace references.
- Created `docs-internal/platform-v2-next-analysis.md` with resolver recommendation, plugin loader API proposal, plugin type model, migration contract model, ADR/spec revision plan, risks, and next artifact recommendation.
- Created proposed ADR-003: `docs-internal/adr/003-platform-v2-minimal-framework-resolver.md`.
- Created proposed ADR-004: `docs-internal/adr/004-platform-v2-plugin-loader-api.md`.
- Decision: keep `woodev/bootstrap.php` as compatibility entry point, but move real logic behind it into a minimal resolver.
- Decision: explicit plugin loaders should replace loose legacy args; runtime behavior should be validated through inheritance/contracts, not brittle strings.
- Decision: rewrite-first plugin internals require per-plugin installed-site contract audits before implementation.
- Updated `docs-internal/DOCS-INDEX.md`, `docs-internal/adr/README.md`, and `docs-internal/CURRENT-STATE.md` to point the next session toward `platform-v2-implementation-spec.md`.

### Verification
- Docs-only analysis session; no PHP implementation was changed.
- Tests/build: not run because only planning docs were changed.
- Gotcha compilation: no new non-obvious technical gotcha discovered; no `docs-internal/gotchas/` update required.
- Commit: pending at time of entry creation; final commit hash reported in chat.

## Platform v2 strategy alignment (2026-05-29)

### Planning reset
- Reviewed `PLANS.md` against the previously created dependency matrix, ADR-001, ADR-002, Epic 1 spec, CURRENT-STATE, FUTURE-BACKLOG, and the spike branch.
- Reframed the prior orchestration-first track as useful but provisional until aligned with `PLANS.md`.
- Confirmed platform-first remains the v2.0 priority; shipping is critical but must live inside the platform, not define it.

### Strategic decisions
- Chosen direction: hybrid roadmap — v2.0 keeps a minimal framework resolver, while SkyVerge-style versioned namespaces remain a future v2.x/v3 track.
- Migration policy: rewrite-first for plugin internals, but installed-site contracts are sacred.
- Required preservation scope: option keys, persisted settings, license state, updater continuity, method IDs, public hooks/actions/filters, scheduled events, and idempotent data migrations.
- `Woodev_Lifecycle` remains the preferred foundation for install/upgrade/activation/deactivation migrations.

### Artifacts
- Added `docs-internal/platform-v2-strategy-alignment.md` to capture the hybrid roadmap, resolver boundaries, rewrite-first policy, lifecycle migration rules, and open decisions.
- Updated `docs-internal/DOCS-INDEX.md` and `docs-internal/CURRENT-STATE.md` so future agents do not auto-continue the old Epic 1 implementation path.

### Verification
- Docs-only session; no PHP implementation, tests, or build were run.
- Gotcha compilation: no new code gotcha discovered; no `docs-internal/gotchas/` update required.

## Platform v2 Phase 0 cleanup gate (2026-05-28)

### v2.0.0 cleanup #1 — minimum versions
- Raised documented/default minimums to WordPress 6.3+ and WooCommerce 7.0+ across public docs, test fixtures, PHPCS config, and agent docs.
- Updated bootstrap registration tests and integration minimum-version assertions to match the new gate.
- No platform split or `Woodev_Woocommerce_Plugin` code was introduced.

### v2.0.0 cleanup #2 — US-specific payment types
- Removed the remaining active ACH/eCheck API contract method `check_debit()` and direct-gateway `do_check_transaction()` path.
- Removed ACH/check-specific response messages, driver-license JS localization, and stale sample-check/eCheck comments.
- Left only deprecated false-return compatibility wrappers: `is_echeck_gateway()` and `is_echeck()`.
- Apple Pay and Google Pay remained absent from active code/assets; backlog now records them as completed cleanup.

### Verification
- `composer check` ✅: PHPCS, PHPStan, and 114 unit tests / 162 assertions green.
- PHPCS now treats warnings as non-blocking while keeping errors blocking; PHPStan memory limit raised to 2G to avoid worker OOM.
- Ready for Epic 1 platform spike.

## s3 (2026-05-10): PHPStan baseline cleanup + eCheck/ACH removal (4 commits)

### eCheck/ACH removal — BREAKING, v2.0.0 prep
- Removed eCheck payment type from 17 files across payment-gateway/
- Deleted eCheck response interface: `interface-payment-gateway-api-payment-notification-echeck-response.php`
- Deleted 3 eCheck assets: `card-echeck.svg`, `card-echeck.png`, `sample-check.png`
- `is_echeck_gateway()` → returns `false`, marked `@deprecated`
- `is_echeck()` on token → returns `false`
- Added missing gateway type methods (`get_payment_type`, `is_credit_card_gateway`, `is_echeck_gateway`) that were accidentally lost in prior cleanup
- Removed from class-payment-gateway.php: PAYMENT_TYPE_ECHECK constant, $supported_check_fields property, get_echeck_transaction_approved_message(), validate_check_fields() branch, eCheck JS error messages, eCheck icon block, eCheck transaction data, eCheck complete_payment note
- Removed from class-payment-gateway-direct.php: validate_check_fields() (~80 lines), eCheck branches in validate_fields/get_order/do_transaction/add_payment_method
- Removed from class-payment-gateway-payment-form.php: get_echeck_fields(), get_sample_check_html(), render_sample_check(), eCheck form rendering
- Removed from class-payment-gateway-hosted.php: PAYMENT_TYPE_ECHECK case, eCheck token branches
- Cleaned token model: removed get_account_type/set_account_type, simplified get_type_full/is_echeck
- Cleaned token handler: removed eCheck branches in create_token/get_tokens/get_order_note/get_merge_attributes
- Cleaned my-payment-methods: removed $echeck_tokens property, simplified load_tokens
- Cleaned handlers: removed eCheck instanceof and PAYMENT_TYPE_ECHECK branches
- Cleaned admin: removed echeck case from token editor, simplified user edit handler type
- Cleaned helper: removed checking/savings from payment_type_to_name
- class-payment-gateway.php: ~2860 lines (was 3927 → 2984 → ~2860, total -1067)
- PHPStan: ✅ 0 errors, Tests: ✅ 114/114 passed

### PHPStan baseline cleanup — 410 errors → 0
- Bugfix: Woodev_Helper::get_post() → get_posted_value() (6 calls, non-existent method)
- Bugfix: declare $voided_order_message as private property (was dynamic, PHP 8.2+ risk)
- Bugfix: PHPDoc @param mismatch in type_from_account_number() (card_type → account_number)
- Bugfix: @var WC_Payment_Gateway → Woodev_Payment_Gateway in partial-capture view
- Improve: is_available() return type : bool
- Baseline: rewrite ignoreErrors section with English docs, add payment-gateway hierarchy patterns

### JS/CSS eCheck cleanup (commit 119e5b6)
- Removed validate_account_data() and handle_sample_check_hint() from JS frontend
- Removed eCheck event binding in constructor
- Removed eCheck CSS selectors from both frontend.css + payment-form.css
- Deleted dist JS artifact (Parcel build, stale since eCheck removal)

### New gotcha discovered
- `is_credit_card_gateway()`/`is_echeck_gateway()`/`get_payment_type()` — these 3 methods were missing from Woodev_Payment_Gateway (accidentally deleted in s2 cleanup). Calls existed 32+ times across the codebase but definitions were gone. Had to add them back with proper deprecation annotation for `is_echeck_gateway()`.
- → Gotcha documented: docs-internal/gotchas/gateway-type-methods-required.md

### Gotcha population
- Created 10 gotcha files in docs-internal/gotchas/ across 6 namespaces (bootstrap, naming, compat, php, deprecation, lifecycle)
- Updated GOTCHAS.md index with 10 entries
- Real bug discovered and documented: get_missing_php_functions() uses extension_loaded instead of function_exists

### Bug fix
- Fixed get_missing_php_functions() in class-woodev-plugin-dependencies.php:374 — extension_loaded → function_exists
- PHPStan: ❌ (OOM at 512M — pre-existing), Tests: ✅ 114/114 passed

### Legacy cleanup (v2.0.0 prep) — commit 728c6f9, -1647 lines
- Removed 12 dead compat guards: WOOCOMMERCE_VERSION (×2), WC 3.0 select2 else-branch, WC_Order_Item_Meta, legacy order edit URL, wc_get_page_screen_id fallback, is_enhanced_admin_available version check, WC 5.3 nonce guard, wp_convert_hr_to_bytes manual fallback, wp_doing_ajax fallback, rest_get_url_prefix guard, FeaturesUtil class_exists
- Removed 47 deprecated methods (@deprecated since 1.1.8–1.3.2): 13 from Woodev_Plugin, 2 from Woodev_Helper, 12 from class-payment-gateway.php (get_post/get_request + 10 capture methods), 12 from My_Payment_Methods, 3 from Payment_Token, 3 from Admin_Order, 1 from Order_Compatibility
- Deleted abstract-data-compatibility.php (empty deprecated class), removed its include and extends reference
- Removed FEATURE_APPLE_PAY constant + Google Pay card icons (unused)
- Fixed 4 stale comments (outdated version references, ancient WP trac tickets)
- Updated test: is_enhanced_admin_available_returns_true (always true, WC 4.0+ guaranteed)
- class-payment-gateway.php: 2984 lines (was 3927, -943)
- Tests: ✅ 114/114 passed

## s1 (2026-05-09): AGENTS.md created, CLAUDE.md refactored, docs-internal/ finalized

## s1 (2026-05-09): AGENTS.md created, CLAUDE.md refactored, docs-internal/ finalized
- Created AGENTS.md — common entry point for ALL AI agents (modeled after woodev_theme)
- Refactored CLAUDE.md — now extends AGENTS.md with Claude-specific MCP rules (Serena, Context7)
- Expanded Documentation Structure section in both AGENTS.md and CLAUDE.md with explicit "Working with" instructions:
  - Public docs (`docs/`): mkdocs build, `%%FRAMEWORK_VERSION%%` injection, markdownlint, GH Pages deploy
  - Internal docs (`docs-internal/`): no build step, gotcha recording protocol, session logging, ADR template
- Updated QWEN.md — Documentation Structure and Knowledge Persistence sections
- Updated .gitignore: added `/_site/` (mkdocs artifact) + docs-internal/ tracking comment
- Updated .markdownlintignore: excluded docs-internal/SESSION-LOG.md, GOTCHAS.md, CURRENT-STATE.md
- Key decision: Two-tier doc architecture — `docs/` (GH Pages public) strictly separated from `docs-internal/` (AI agents internal)
- Build: n/a (docs/restructure only, no code changes)

## s0 (2026-05-09): docs-internal/ structure initialized
- Created docs-internal/ directory for internal technical documentation
- Separated public docs (docs/ → GH Pages) from internal docs (docs-internal/ → AI agents)
- Setup: DOCS-INDEX.md, DOCS-SCHEMA.md, AGENT-RULES.md, CURRENT-STATE.md, SESSION-LOG.md, GOTCHAS.md, FUTURE-BACKLOG.md
- Created subdirectories: gotchas/, adr/, archive/, wiki/
- Updated gateway files (CLAUDE.md, QWEN.md) to reference docs-internal/
- Added _site/ to .gitignore
- Build: n/a (docs only)
