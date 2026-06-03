# Platform v2 — Clean-Break "Finish the Split" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Branch:** all work lands on `refactor/platform-v2-clean-break` (baseline frozen at tag `platform-v2-pivot-baseline`; pristine pre-v2 framework at `platform-v2-pre-refactor`). Never commit this work to `main`.
>
> **Source of truth for direction:** `docs-internal/platform-v2-direction-audit-2026-06-03.md` (§5 decisions D-1..D-5, §6 corrected direction). This plan operationalizes audit §6.5.

**Goal:** Reach a genuinely clean "platform split done" state — `Woodev_Plugin` platform-neutral and no longer a god-object, the resolver actually minimal, all internal-API back-compat scaffolding deleted — while preserving every installed-site data contract.

**Architecture:** The load path stays `bootstrap.php` (global rendezvous for multi-copy vendoring) → `Framework_Resolver` (discovery/validation/invocation) → explicit `Framework_Plugin_Loader_Definition` → `Woodev_Plugin` (platform-neutral base) → `Woodev\Framework\Woocommerce_Plugin` → specialized bases (`Woodev_Payment_Gateway_Plugin`, `Woodev\Framework\Shipping\Shipping_Plugin`). The clean break removes the legacy positional registration path, the global class aliases, and every internal-API deprecation shim. Installed-site contracts (option keys, method/gateway IDs, hook names, cron, REST namespaces, license/instance IDs) are **frozen invariants** and are never code we are allowed to break.

**Tech Stack:** PHP 7.4–8.1 (platform 8.1); Brain Monkey + Mockery unit tests (no WP runtime); PHPStan level 3; PHP_CodeSniffer (WPCS); `composer check` is the gate.

**Validation model (operator decision, deviates from audit §6.4):** the §6.4 "real plugin" gate is satisfied by an **in-repo, edostavka-shaped realistic fixture**, not a cross-repo rewrite of the live plugin. Consequence — this branch proves the **code architecture / load path**, not installed-site **data preservation**. The data-preservation surface is captured as a written checklist (Phase 2, Task 2.4) and is actually exercised later, in each plugin's own repo, when that plugin is rewritten onto v2. This is recorded so no future agent mistakes "fixtures green" for "live sites proven safe."

---

## Phase order (audit §6.5, pilot-first)

```
P0  Branch + frozen baseline ........................................ ✅ DONE (tags + green baseline 197/197)
P1  CLAUDE.md / AGENTS.md reconciliation (clean-break policy) ....... stops the back-compat bleeding
P2  Pilot gate: edostavka-shaped fixture through the new path ....... VALIDATION GATE (drives everything)
P3  Delete internal-API back-compat debt (one cohesive change) ..... aliases, shims, legacy registration
P4  Decompose Woodev_Plugin (D-3) — see base-decomposition sub-plan . extract last inline subsystems + strip WC seams
P5  Re-minimize the resolver against ADR-003 ....................... justify or extract each responsibility
P6  "Split done" gate ............................................... pure-WP loads · base not god-object · resolver minimal · green
```

**Why pilot-first:** the entire audit thesis is "prove the new path on a realistic plugin shape *once, before further framework polishing* — it surfaces real gaps faster than analysis." P2 is the cheap gate; because it is an automated fixture test, re-running it after P4/P5 is free, so the big refactors are validated for nothing extra.

**Commit discipline:** clean-break removals are batched into **one cohesive refactor per concern** (per audit §6.3 "not 14 PRs"). Run `composer check` green before every commit. Conventional Commits; `!` + `BREAKING CHANGE:` footer on the internal-API removals.

---

## Phase 1 — Reconcile CLAUDE.md / AGENTS.md to the clean-break policy

**Objective:** Remove the `CLAUDE.md` ↔ `PLANS.md` contradiction (audit §4.2) that has made every prior session reflexively add a deprecation shim. This is the single highest-leverage edit and must come first so P3 work is not fighting the project's own stated rules.

**Files:**
- Modify: `CLAUDE.md` — "## Backward Compatibility" section + "## Known Technical Debt"
- Modify: `AGENTS.md` — "**Backward compatibility — MOST CRITICAL:**" block + Coding Principle #3
- Modify: `docs-internal/adr/002-*.md` — add "superseded by 2026-06-03 clean-break decision" note (audit §8)
- Create: `docs-internal/adr/005-platform-v2-clean-break-policy.md` — record D-2 as a first-class ADR
- Do NOT touch: `~/.claude/CLAUDE.md` (operator's personal cross-project file — out of scope for repo edits)

- [ ] **Step 1.1 — Replace the CLAUDE.md backward-compat mandate.**
  In `CLAUDE.md` "## Backward Compatibility", replace the strict-deprecation block with the clean-break policy. New content:

  ```markdown
  ## Backward Compatibility — clean-break policy (v2.0 branch)

  > Policy set 2026-06-03 (direction audit D-2). Supersedes the prior "deprecation cycle for everything" rule on the `refactor/platform-v2-clean-break` branch.

  This is a near-new framework; the old one is being rewritten and the dependent
  plugins will be rewritten onto it (PLANS.md §2.4). Two different rules apply:

  - **Internal code (FREE TO BREAK on the v2 branch):** class names, method
    signatures, the plugin entry/registration shape, namespacing, file layout.
    Do NOT add `@deprecated` shims, `class_alias` files, or `_deprecated_function()`
    wrappers for moved/renamed internal APIs. Delete existing ones.
  - **Installed-site data contracts (RELEASE-BLOCKING — never break):** option keys
    & settings arrays, license key option names + activation state + instance IDs,
    updater identity, WC payment-gateway IDs, WC shipping-method IDs + instance
    setting keys, public action/filter hook names, scheduled cron hooks +
    recurrence + payload shape, custom DB tables/schemas, REST route namespaces,
    AJAX action names, admin page slugs, log source names, background-job IDs,
    order/session meta keys. Preserve these byte-for-byte.

  When a plugin is migrated onto v2, run the data-preservation checklist
  (`docs-internal/migration/<plugin>-data-preservation-checklist.md`) — that is
  where the "never break" list is enforced, at rewrite time, per plugin.
  ```

- [ ] **Step 1.2 — Update CLAUDE.md "Known Technical Debt".**
  Replace the "11 deprecated methods… slated for removal in v2.0.0" and class_alias-related lines with: "Internal-API back-compat scaffolding (2 `class_alias` files, ~10 `_deprecated_function` shims, legacy positional registration) is being deleted on `refactor/platform-v2-clean-break` per the clean-break policy — see `docs-internal/platform-v2-cleanbreak-plan.md` Phase 3."

- [ ] **Step 1.3 — Update AGENTS.md.**
  In the "**Backward compatibility — MOST CRITICAL:**" block, replace the "NEVER delete/rename… ALWAYS use `@deprecated`… Minimum one full version" bullets with a pointer to the clean-break policy (internal free to break on v2 branch; installed-site data release-blocking). In "## 🎯 Coding Principles" #3, change "backward compatibility is mandatory" → "preserve installed-site data contracts; internal APIs may break on the v2 branch."

- [ ] **Step 1.4 — ADR notes.**
  Append to `docs-internal/adr/002-*.md`: "> **Superseded (2026-06-03, D-2):** the 'deprecated metadata bridge for ≥1 minor release' is overruled by the clean-break decision. Internal-API continuity is no longer maintained; see ADR-005." Create `docs-internal/adr/005-platform-v2-clean-break-policy.md` recording D-2 (context, decision, consequences) and add it to `docs-internal/adr/README.md`.

- [ ] **Step 1.5 — Commit.**
  No code changed → `composer check` not required, but run `npx markdownlint-cli2 "docs-internal/adr/**/*.md"` is NOT required (docs-internal excluded). Commit:
  ```bash
  git add CLAUDE.md AGENTS.md docs-internal/adr/
  git commit -m "docs(policy): reconcile CLAUDE.md/AGENTS.md to clean-break (D-2)" \
    -m "Internal APIs free to break on the v2 branch; installed-site data contracts release-blocking. Stops the reflexive deprecation-shim tax (audit §4.2). ADR-002 superseded; ADR-005 records the policy."
  ```

**Phase 1 exit gate:** project docs no longer mandate internal-API deprecation cycles; the clean-break policy is written down where the next agent will read it.

---

## Phase 2 — Pilot gate: an edostavka-shaped fixture through the new path

**Objective:** Prove, once, that a plugin of edostavka's real complexity loads end-to-end through the new path (`register_loader_definition` → resolver → `Shipping_Plugin`/`Woocommerce_Plugin` inheritance), and capture edostavka's installed-site data contracts as a written checklist. This is the gate the original Phase 6A skipped (audit §4.3).

**Why a new fixture (not the existing `woodev-realistic-shipping-plugin`):** the existing fixture is generic and thin. The pilot must model edostavka's actual shape so the gate is meaningful: a shipping plugin with an integration/settings object, an admin page slug, a cron hook, a custom data store, and the real method ID `edostavka` — registered through the **explicit loader definition** and extending the **new** hierarchy (the live plugin still extends `Woodev_Plugin` directly via the legacy path; the pilot models the *post-rewrite target*).

**Files:**
- Create: `tests/_fixtures/woodev-edostavka-pilot-plugin/woodev-edostavka-pilot-plugin.php` (entry: constants + loader-definition fn + init callback + getter)
- Create: `tests/_fixtures/woodev-edostavka-pilot-plugin/includes/class-edostavka-pilot-plugin.php` (`extends Woodev\Framework\Shipping\Shipping_Plugin`)
- Create: `tests/_fixtures/woodev-edostavka-pilot-plugin/includes/class-edostavka-pilot-shipping-method.php` (method id `edostavka`)
- Create: `tests/_fixtures/woodev-edostavka-pilot-plugin/includes/class-edostavka-pilot-integration.php` (settings object; option key `woocommerce_edostavka_settings`)
- Create: `tests/unit/EdostavkaPilotFixtureTest.php`
- Create: `docs-internal/migration/edostavka-data-preservation-checklist.md`
- Reference (read-only, gitignored): `plugins-reference/woocommerce-edostavka`

- [ ] **Step 2.1 — Write the failing pilot test.**
  Model `tests/unit/RealisticShippingFixtureTest.php` (the Explore-surveyed pattern: testable resolver subclass overriding `get_plugin_path()`/`get_wc_version()`, Brain Monkey stubs, `register_loader_definition()` → `load_plugins()`). Assertions:

  ```php
  // tests/unit/EdostavkaPilotFixtureTest.php (core assertions)
  $this->assertTrue( $accepted, 'edostavka-shaped definition accepted by resolver' );
  $this->assertCount( 1, $resolver->get_active_plugins() );

  $plugin = woodev_edostavka_pilot_plugin();
  $this->assertInstanceOf( \Woodev\Framework\Woocommerce_Plugin::class, $plugin );
  $this->assertInstanceOf( \Woodev\Framework\Shipping\Shipping_Plugin::class, $plugin );

  // Installed-site contract: the shipping method ID must be exactly 'edostavka'
  $this->assertSame(
      [ 'edostavka' => 'Woodev_Edostavka_Pilot_Shipping_Method' ],
      $plugin->get_fixture_shipping_method_classes()
  );

  // Installed-site contract: settings option key preserved
  $this->assertSame(
      'woocommerce_edostavka_settings',
      $plugin->get_fixture_settings_option_name()
  );
  ```

- [ ] **Step 2.2 — Run it, verify it fails.**
  Run: `./vendor/bin/phpunit tests/unit/EdostavkaPilotFixtureTest.php -v`
  Expected: FAIL — fixture entry file / classes do not exist yet.

- [ ] **Step 2.3 — Build the fixture to pass.**
  Create the entry file and `includes/` classes modeled on `tests/_fixtures/woodev-realistic-shipping-plugin` but with edostavka's contract strings:
  - loader definition: `plugin_id => 'woodev-edostavka-pilot'`, `platform => PLATFORM_WOOCOMMERCE`, `capabilities => [CAPABILITY_SHIPPING_METHOD]`, `requirements => ['php'=>'7.4','wordpress'=>'6.3','woocommerce'=>'7.0']`, `main_class => 'Woodev_Edostavka_Pilot_Plugin'`, `callback => 'woodev_edostavka_pilot_plugin_init'`.
  - plugin class `extends Woodev\Framework\Shipping\Shipping_Plugin`; `get_shipping_method_classes()` returns `['edostavka' => 'Woodev_Edostavka_Pilot_Shipping_Method']`; expose `get_fixture_shipping_method_classes()` and `get_fixture_settings_option_name()` returning `'woocommerce_edostavka_settings'`.
  - shipping method class with `$this->id = 'edostavka'`.
  - integration/settings class holding the option name constant.
  Run: `./vendor/bin/phpunit tests/unit/EdostavkaPilotFixtureTest.php -v` → Expected: PASS.

- [ ] **Step 2.4 — Fill the data-preservation checklist from the reference plugin.**
  Repurpose `docs-internal/platform-v2-migration-contract-template.md` as a filled checklist for edostavka at `docs-internal/migration/edostavka-data-preservation-checklist.md`. Populate from the survey (these are the release-blocking invariants — copy exact strings):
  - Shipping method ID: `edostavka`; download/EDD ID: `216`.
  - Option keys: `woocommerce_edostavka_settings`, `wc_edostavka_webhook_ids`, `wc_edostavka_shipping_fee_payments`, migration flags `wc_edostavka_upgraded_to_2_2_2_0`, legacy `woocommerce_edostavka-integration_settings`, legacy `cdek_woocommerce_shipping_method_license_key`.
  - Order meta prefix `_wc_edostavka_` (status, cdek_order_id, tracking_code, customer_location, delivery_point); shipping-item meta `edostavka_rate`.
  - Cron: hook `wc_edostavka_orders_update`, schedule `wc_edostavka_orders`.
  - AJAX actions: `edostavka_*` family (list).
  - Admin page slug `wc_edostavka_orders`; log source `edostavka_orders`.
  - Data stores `customer-location` / `customer-location-session`; REST namespace `wc/v3`; webhook endpoints `woocommerce_api_wc_edostavka_*`.
  Mark each "must be preserved by the eventual rewrite — not enforced by this fixture."

- [ ] **Step 2.5 — Commit.**
  ```bash
  composer check    # expect green
  git add tests/_fixtures/woodev-edostavka-pilot-plugin tests/unit/EdostavkaPilotFixtureTest.php docs-internal/migration/edostavka-data-preservation-checklist.md
  git commit -m "test(pilot): edostavka-shaped fixture validates the new load path + data-preservation checklist"
  ```

**Phase 2 exit gate (VALIDATION GATE):** an edostavka-complexity plugin loads through `register_loader_definition` → resolver → `Shipping_Plugin` inheritance with `composer check` green; edostavka's data-preservation surface is written down. Any gap the fixture surfaces is fixed here before Phase 3. **Do not start Phase 3 until this gate is green.**

---

## Phase 3 — Delete internal-API back-compat debt (one cohesive clean-break)

**Objective:** Remove every internal-API shim/alias/legacy path catalogued in the deletion inventory, bounded by in-repo references (per the validation model, no live plugin is rewritten here). Batch into a small number of cohesive commits, not micro-slices. Installed-site data contracts are untouched — class names and registration shapes are internal code, free to break on this branch.

**Pre-req inside this phase:** the 3 legacy fixtures (`woodev-test-plugin`, `woodev-test-payment-gateway`, `woodev-test-shipping-method`) use legacy positional `register_plugin()`; they must be converted to `register_loader_definition()` before the legacy adapter can go.

**Files (from the deletion inventory):**
- Convert: `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php`, `tests/_fixtures/woodev-test-payment-gateway/woodev-test-payment-gateway.php`, `tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php`
- Delete: `woodev/class-woocommerce-plugin-alias.php`, `woodev/class-woocommerce-helper-alias.php`
- Delete methods: `Woodev_Plugin::add_class_form_wrap_start/end()` (class-plugin.php ~538–552), `Woodev_Plugin::get_woocommerce_uploads_path()` (~1295–1310); `Woodev_Helper::{get_order_line_items,is_order_virtual,shop_has_virtual_products,render_select2_ajax}()` (class-helper.php)
- Delete class: `Woodev_License_Settings` shim (`woodev/licensing/class-plugin-license-settings.php`)
- Modify: `woodev/bootstrap.php` (remove `register_plugin()` positional intake), `woodev/class-framework-resolver.php` (remove `register_legacy_plugin()` + legacy preload-by-flag), `woodev/class-framework-plugin-loader-definition.php` (remove `from_legacy_registration()` + `is_payment_gateway`/`load_shipping_method` flag mapping; capabilities array becomes the only source)
- Update resolver class-loading: load `class-woocommerce-plugin.php` / `class-woocommerce-helper.php` (namespaced) **without** the alias files
- Update/delete tests: `AddClassFormWrapLocationTest`, `WoocommerceHelperLocationTest`, `WoocommerceHelperShimTest`, `WoocommerceUploadsPathLocationTest`, `WoocommerceLicenseSettingsLocationTest`, `BootstrapRegistrationTest`, `FrameworkResolverTest`, `PlatformNeutralHelperTest`, `RealisticPaymentFixtureTest`/`RealisticShippingFixtureTest`/`WoocommercePluginTest` (swap global alias `Woodev_Woocommerce_Plugin`/`Woodev_Woocommerce_Helper` → FQCN `\Woodev\Framework\Woocommerce_Plugin` / `\Woodev\Framework\Woocommerce_Helper`)

- [ ] **Step 3.1 — Convert the 3 legacy fixtures to explicit loader definitions.**
  Rewrite each `woodev-test-*` entry to call `register_loader_definition([...])` (model on `woodev-realistic-*` fixtures). Map old flags: `is_payment_gateway => true` ⇒ `capabilities => [CAPABILITY_PAYMENT_GATEWAY]`; `load_shipping_method => true` ⇒ `capabilities => [CAPABILITY_SHIPPING_METHOD]`. Update `BootstrapRegistrationTest`/`FrameworkResolverTest` legacy-positional assertions to the new definition shape (or delete the ones that exist only to test the legacy adapter).
  Run: `composer test:unit` → expect green. Commit: `test(fixtures): convert woodev-test-* to explicit loader definitions`.

- [ ] **Step 3.2 — Delete the legacy registration path.**
  Remove `Woodev_Plugin_Bootstrap::register_plugin()` (bootstrap.php), `Framework_Resolver::register_legacy_plugin()`, and `Framework_Plugin_Loader_Definition::from_legacy_registration()` + the `is_payment_gateway`/`load_shipping_method` flag reads. Capabilities now come only from the explicit `capabilities` array. Remove now-dead legacy tests.
  Run: `composer check` → green. (Do not commit yet; bundle with 3.3–3.4 per cohesive-commit discipline, or commit as one `refactor!` — your call, keep it green.)

- [ ] **Step 3.3 — Delete the global class aliases.**
  Delete `class-woocommerce-plugin-alias.php` and `class-woocommerce-helper-alias.php`. In `class-framework-resolver.php`, drop the `require_once` of both alias files (keep the `require_once` of the real namespaced `class-woocommerce-plugin.php` / `class-woocommerce-helper.php`). Update every in-repo test that referenced the global short names to the FQCN (inventory lists exact file:line). `class-payment-gateway.php:2706` already uses the FQCN — no change.
  Run: `composer check` → green.

- [ ] **Step 3.4 — Delete the deprecated method/class shims.**
  Remove the 3 `Woodev_Plugin` shims, the 4 `Woodev_Helper` shims, and the `Woodev_License_Settings` shim class file. Delete their dedicated tests (`AddClassFormWrapLocationTest`, `WoocommerceUploadsPathLocationTest`, `WoocommerceHelperShimTest`, `WoocommerceLicenseSettingsLocationTest`) and prune the moved-method assertions in `WoocommerceHelperLocationTest`/`PlatformNeutralHelperTest` down to "the real method lives on the WC class" (keep the location guarantee, drop the shim guarantee). The real implementations on `Woocommerce_Plugin`/`Woocommerce_Helper`/`Woocommerce_License_Settings` stay.
  Run: `composer check` → green.

- [ ] **Step 3.5 — Sweep for residue + commit.**
  Grep `woodev/` for remaining `class_alias(` and `_deprecated_function(`/`_doing_it_wrong(` — only legitimate non-back-compat ones may remain (clone/wakeup guards at class-plugin.php:331/338; genuine misuse markers in settings-api/setup-wizard/api-base/payment-gateway that are NOT internal-API-move shims). Anything that exists only to bridge a moved/renamed internal symbol must be gone.
  ```bash
  composer check   # green
  git add -A
  git commit -m "refactor!: delete internal-API back-compat scaffolding (aliases, shims, legacy registration)" \
    -m "Removes 2 class_alias files, 3 Woodev_Plugin + 4 Woodev_Helper deprecated shims, the Woodev_License_Settings shim, and the legacy positional register_plugin()/register_legacy_plugin()/from_legacy_registration() path. Capabilities now come only from the explicit loader definition. Installed-site data contracts untouched." \
    -m "BREAKING CHANGE: internal class/method names and the legacy positional register_plugin() entry are removed. Dependent plugins adopt the explicit loader definition + namespaced classes when rewritten onto v2."
  ```

**Phase 3 exit gate:** no internal-API alias/shim/legacy-registration code remains; `composer check` green; only the explicit loader-definition path exists.

---

## Phase 4 — Decompose `Woodev_Plugin` (D-3)

**Objective:** Make the base no longer a god-object and strip its residual WooCommerce seams, **pragmatically** (extract the clearest remaining inline subsystems into injected handlers; DI only as far as WP load order allows; no DI container).

**Scope note (corrected by code survey):** the base is already ~80% delegated — 18 subsystems are handler classes constructed by the base. The real, bounded D-3 work is: (a) extract the **4 remaining inline concerns** (cron scheduling, plugin action links, translation loading, API request logging) into handlers; (b) introduce a uniform **handler collection** so the constructor stops being a 12-step inline orchestration and subsystems become independently testable/injectable; (c) remove the **WC seams** from the platform-neutral base (`add_woocommerce_hooks()` empty stub and any remaining WC-shaped methods). Full task-by-task TDD steps live in the dedicated sub-plan.

**→ See `docs-internal/platform-v2-base-decomposition-subplan.md` for the detailed, TDD-staged tasks.** Execute that sub-plan as Phase 4, then return here.

**Phase 4 exit gate:** the 4 inline concerns are handlers; construction is uniform; the base has no WC-named seams; `composer check` green; the no-WC neutrality test still proves the base loads with WooCommerce absent.

---

## Phase 5 — Re-minimize the resolver against ADR-003

**Objective:** With the legacy adapter gone (Phase 3), re-justify each remaining `Framework_Resolver` responsibility against ADR-003 §6.2/6.3 ("owns only early infrastructure: discovery → validation → invocation; not runtime platform behavior"). Extract only what is clearly non-core; do not gold-plate.

**Files:**
- Modify: `woodev/class-framework-resolver.php`
- Possibly create: `woodev/class-framework-compatibility-report.php` (value object) — only if the incompatibility bookkeeping is cleanly separable
- Update: `docs-internal/adr/003-*.md` — record the post-minimization responsibility table
- Tests: `tests/unit/FrameworkResolverTest.php`

- [ ] **Step 5.1 — Write the resolver responsibility table.**
  Enumerate the now-remaining public methods (Phase 3 already removed `register_legacy_plugin`). For each, classify: **Core** (intake `register_loader_definition`; invocation `load_plugins`; sorting `framework_compare`; path/version/wc-version utilities used during load) vs **Reporting** (the 6 `get_incompatible_*` getters + `render_update_notices` + `get_invalid_loader_definitions` + `maybe_deactivate_framework_plugins`). Put the table in ADR-003.

- [ ] **Step 5.2 — Extract compatibility reporting if cleanly separable.**
  If the `$incompatible_{framework,php,wp,wc}_plugins` bookkeeping + `has_update_notices()` + `render_update_notices()` form a cohesive unit, move them into a `Framework_Compatibility_Report` collaborator that `load_plugins()` populates and the injected notice callbacks read. Keep the resolver's public surface to: intake, `load_plugins`, the active/registered getters, and the report accessor. **If extraction adds indirection without clarity, stop and document why the methods stay** (pragmatic scope — no academic purity).
  TDD: write a `FrameworkResolverTest` case asserting the report object carries the four incompatibility buckets; make it pass; keep existing arbitration/boundary tests green.

- [ ] **Step 5.3 — Verify the boundary still holds + commit.**
  The existing resolver-boundary negative test (resolver must not own runtime platform behavior) must stay green. `composer check` green.
  ```bash
  git add woodev/class-framework-resolver.php docs-internal/adr/003-*.md tests/unit/FrameworkResolverTest.php
  git commit -m "refactor(resolver): re-minimize to discovery/validation/invocation per ADR-003"
  ```

**Phase 5 exit gate:** every resolver responsibility is either core-justified in ADR-003 or extracted; boundary test green; `composer check` green.

---

## Phase 6 — "Split done" gate

**Objective:** Confirm the corrected definition of "done" (audit §6.1) is met. This is a verification phase — no new feature work.

- [ ] **Step 6.1 — Pure-WP load.** Confirm the no-WC neutrality test passes: the base loads and a `PLATFORM_WORDPRESS` plugin invokes with WooCommerce entirely absent (no WC seams in the base).
- [ ] **Step 6.2 — Base is not a god-object.** Confirm the 4 inline concerns are handlers and construction is uniform (Phase 4). Record the new base line/method count in CURRENT-STATE for the record.
- [ ] **Step 6.3 — Resolver minimal.** Confirm ADR-003 responsibility table matches the code (Phase 5).
- [ ] **Step 6.4 — No internal-API back-compat residue.** `grep -rn "class_alias\|register_legacy_plugin\|from_legacy_registration" woodev/` returns nothing; remaining `_deprecated_function`/`_doing_it_wrong` are only legitimate guards/misuse markers, not internal-move shims.
- [ ] **Step 6.5 — Green.** `composer check`: phpcs clean, phpstan 0 errors, phpunit all green.
- [ ] **Step 6.6 — Record the gate.** Update `docs-internal/CURRENT-STATE.md` (phase table → "Platform split: DONE") and append a `SESSION-LOG.md` entry. Tag `platform-v2-split-done`.

**Phase 6 exit gate = the deliverable:** pure-WP loads · base decomposed · resolver minimal · zero internal-API shims · `composer check` green. After this gate, domain modules begin (shipping → box-packer → licensing), each as its own spec — **out of scope for this plan.**

---

## Self-review notes (spec coverage vs audit §6)

- §6.1.1 platform-neutral base (no WC seams) → P4 + P6.1. §6.1.2 minimal resolver → P5. §6.1.3 decompose base (D-3) → P4/sub-plan. §6.1.4 namespaced-only new code → enforced by P3 deletions (no new globals).
- §6.2 preserve-vs-delete → P3 (delete internal) + P2.4 (preserve list captured). §6.3 stops (no new shims, no micro-slices, off `main`) → encoded in Phase 1 policy + Phase 3 cohesive-commit discipline + branch rule.
- §6.4 prove on a real plugin → P2 (operator-scoped to an in-repo edostavka-shaped fixture; deviation + consequence recorded in the validation model above). §6.5 sequencing → the phase order. §6.6 keep rendezvous → untouched (no phase removes `bootstrap.php`; P5 explicitly keeps intake/handoff).
- **Known deviation, recorded:** data-preservation (§6.2 "PRESERVE") is documented (P2.4) but not runtime-validated on this branch — it is enforced per-plugin at rewrite time. This is the operator's fixture-over-real-plugin decision; surfaced so it is not mistaken for "live sites proven."

## Related
- [platform-v2-direction-audit-2026-06-03.md](platform-v2-direction-audit-2026-06-03.md) — course source of truth
- [platform-v2-base-decomposition-subplan.md](platform-v2-base-decomposition-subplan.md) — Phase 4 detail (D-3)
- [platform-v2-implementation-spec.md](platform-v2-implementation-spec.md) — architecture reference (sequencing overridden by the audit)
- [platform-v2-migration-contract-template.md](platform-v2-migration-contract-template.md) — repurposed as the P2.4 data-preservation checklist
