# Current State — Woodev Plugin Framework
> Last updated: 2026-05-30 (Platform v2 Phase 5 helper fallback cleanup complete)

## Phase Status

| Phase | Code | Browser-verified | Notes |
|-------|------|------------------|-------|
| Framework Core | ✅ | ✅ | Bootstrap, Plugin base, Lifecycle — stable |
| Payment Gateway | ✅ | ✅ | class-payment-gateway.php: 2378 lines (was 3927, US payment cleanup complete) |
| Shipping Method | ✅ | ✅ | PSR-4 namespaced |
| Licensing | ✅ | ✅ | EDD store integration |
| Settings API | ✅ | ✅ | Typed settings framework |
| Box Packer | ✅ | ✅ | Shipping box-packing algorithm |
| REST API | ✅ | ✅ | Plugin REST routes |
| Documentation Structure | ✅ | — | Two-tier: docs/ (GH Pages) + docs-internal/ (AI agents) |
| Legacy Cleanup (v2.0.0) | ✅ | — | WP 6.3+/WC 7.0+ minimum gate complete; US-specific payment paths removed/isolated |
| PHPStan Baseline | ✅ | ✅ | 0 errors, baseline cleaned up with documented ignores |
| eCheck/ACH Removal | ✅ | ✅ | Active ACH API/direct transaction paths removed; deprecated false-return wrappers retained |
| eCheck/ACH Audit | ✅ | — | Audit done (s3): 14 files, 5-phase removal plan in wiki/echeck-ach-audit.md |

## Known Bugs (open)

- [⚠️] class-payment-gateway.php is 2378 lines — candidate for trait extraction
- [✅] 50+ PHPStan baseline ignores — cleaned up (s3)
- [✅] Woodev_Plugin_Dependencies::get_missing_php_functions() — fixed `4d00539`
- [✅] 47 deprecated methods total — removed `728c6f9`
- [✅] Woodev_Helper::get_post() non-existent method — fixed (s3)
- [✅] Woodev_Payment_Gateway::$voided_order_message dynamic — fixed (s3)
- [✅] eCheck/ACH payment type — removed (s3), `is_echeck_gateway()` returns false, deprecated

## Next Actions (priority order)

1. ~~Populate docs-internal/gotchas/~~ ✅ s2
2. ~~Fix get_missing_php_functions() bug~~ ✅ s2
3. ~~Clean up PHPStan baseline~~ ✅ s3
4. ~~eCheck/ACH audit + removal~~ ✅ s3
5. Extract traits from class-payment-gateway.php (now 2378 lines, down from 3927)

### Platform v2 (strategy alignment)

| Step | Status | Artifact |
|------|--------|----------|
| 1 Dependency matrix | ✅ 2026-05-28 | `docs-internal/platform-v2-dependency-matrix.md` |
| 2 ADR bootstrap + plugin type | ✅ 2026-05-28 | `docs-internal/adr/001-*.md`, `002-*.md` |
| 3 Epic 1 spec (platform layer) | ✅ 2026-05-28 accepted | `docs-internal/platform-v2-epic1-spec.md` |
| 4 v2 cleanup #1–#2 gate | ✅ 2026-05-28 `f9fea5f` | WP 6.3+ / WC 7.0+; ACH/eCheck surface removed |
| 5 Spike branch | ✅ 2026-05-28 `0ed6df8` | `feat/platform-v2-epic1-spike` — Woodev_Woocommerce_Plugin + bootstrap metadata |
| 6 Strategy alignment | ✅ 2026-05-29 | `docs-internal/platform-v2-strategy-alignment.md` — hybrid roadmap, rewrite-first migration, minimal resolver |
| 7 Deep analysis | ✅ 2026-05-29 | `docs-internal/platform-v2-next-analysis.md`, ADR-003, ADR-004 — resolver, loader API, migration contracts |
| 8 Implementation spec | ✅ 2026-05-29 | `docs-internal/platform-v2-implementation-spec.md` — active source for resolver-first implementation |
| 9 PHP implementation | ✅ 2026-05-29 | Resolver facade + explicit loader definition slice implemented |
| 10 Platform class split | ✅ 2026-05-29 | Hook ownership, initial WooCommerce feature/Blocks state, system-status rows, WooCommerce logger, template loader, HPOS/Blocks feature declarations, and payment/shipping specialized inheritance moved to `Woodev_Woocommerce_Plugin`; remaining base items are compatibility wrappers or Phase 5 module cleanup |
| 11 Early class availability | ✅ 2026-05-29 | Payment/shipping early capabilities load WooCommerce base from selected framework copy; callback timing test proves specialized child classes can be declared inside plugin callback |
| 12 Phase 5 cleanup #1 | ✅ 2026-05-29 | Base-owned API, lifecycle, and licensing deprecated wrappers now use WordPress core deprecation helpers instead of WooCommerce wrappers |
| 13 Phase 5 cleanup #2 | ✅ 2026-05-29 | Settings API boolean and URL helpers now use local platform-neutral equivalents preserving `yes`/`no` storage and `http`/`https` validation contracts |
| 14 Phase 5 cleanup #3 | ✅ 2026-05-30 | Licensing helper slice now uses local platform-neutral equivalents for `wc_strtolower()`, `wc_print_r()`, and licensing API URL validation while preserving case-insensitive action checks, print_r-style request logging output, and `http`/`https` URL acceptance contracts |
| 15 Phase 5 cleanup #4 | ✅ 2026-05-30 | Lifecycle event history now uses a local platform-neutral recursive sanitization helper instead of `wc_clean()` while preserving stored event name/version/data cleaning semantics in a no-WooCommerce unit context |
| 16 Phase 5 cleanup #5 | ✅ 2026-05-30 | Plugin updater beta opt-in now uses a local platform-neutral boolean helper in `Woodev_Plugin` instead of `wc_string_to_bool()`, preserving the installed-site `beta_version` option key and WooCommerce-compatible truthy semantics in a no-WooCommerce unit context |
| 17 Phase 5 cleanup #6 | ✅ 2026-05-30 | Dependency PHP setting size parsing now uses a local platform-neutral byte conversion helper in `Woodev_Plugin_Dependencies` instead of `wc_let_to_num()`, preserving incompatible-setting detection and formatted notice payloads in a no-WooCommerce unit context |
| 18 Phase 5 cleanup #7 | ✅ 2026-05-30 | Admin notice dismiss JavaScript now queues through `Woodev_Helper::enqueue_js()` instead of `wc_enqueue_js()`, with footer print hooks registered by the helper so base-owned admin notices work in a no-WooCommerce unit context |
| 19 Phase 5 cleanup #8 | ✅ 2026-05-30 | Settings API error paths now use WordPress `_doing_it_wrong()` instead of `wc_doing_it_wrong()`, preserving register-setting and register-control failure messages in a no-WooCommerce unit context |
| 20 Phase 5 cleanup #9 | ✅ 2026-05-30 | Licensing date formatting now uses WordPress date formatting in `Woodev_License_Messages` instead of `wc_date_format()`, `wc_string_to_datetime()`, and `wc_format_datetime()`, preserving localized expiration-date message output in a no-WooCommerce unit context |
| 21 Phase 5 cleanup #10 | ✅ 2026-05-30 | Job batch handler inline JavaScript now queues through `Woodev_Helper::enqueue_js()` instead of `wc_enqueue_js()`, preserving the batch-handler payload and footer print-hook contract in a no-WooCommerce unit context |
| 22 Phase 5 cleanup #11 | ✅ 2026-05-30 | Setup wizard step-registration error reporting now uses WordPress `_doing_it_wrong()` instead of `wc_doing_it_wrong()`, preserving invalid-step diagnostics in a no-WooCommerce unit context |
| 23 Phase 5 cleanup #12 | ✅ 2026-05-30 | `Woodev_Helper::maybe_doing_it_early()` now falls back to WordPress `_doing_it_wrong()` when WooCommerce is unavailable while preserving the WooCommerce diagnostic path where `wc_doing_it_wrong()` exists |
| 24 Phase 5 cleanup #13 | ✅ 2026-05-30 | `Woodev_Helper::format_percentage()` now falls back to local decimal formatting when `wc_format_decimal()` is unavailable while preserving the WooCommerce decimal-helper path and trim/precision contract in a no-WooCommerce unit context |
| 25 Phase 5 cleanup #14 | ✅ 2026-05-30 | `Woodev_Helper::shop_has_virtual_products()` now returns `false` when `wc_get_products()` is unavailable, preserving published-virtual-product detection without fataling in a no-WooCommerce unit context |

## Planned — v2.0.0 & Beyond

> Detailed specs in `docs-internal/FUTURE-BACKLOG.md`

| # | Task | Category | Target |
|---|------|----------|--------|
| 1 | Bump WP/WC minimums (WP 6.3+, WC 7.0+) + remove deprecated compat code | ✅ Done | v2.0.0 |
| 2 | Remove unused US-specific payment types (echeck, Apple Pay, Google Pay) | ✅ Done | v2.0.0 |
| 3 | Push notifications & webhooks (server→client) | Feature | Post v2.0.0 |
| 4 | Shipping module boilerplate | Feature | Post v2.0.0 |
| 5 | React-oriented admin UI | Feature | Post v2.0.0 |
| 6 | Framework decoupling — support pure WP plugins + future EDD | Architecture | v2.0.0 |
| 7 | Cross-project ecosystem orchestration ("Оркестрация экосистемы Woodev") | Cross-Project | Post v2.0.0 stable |

> **v2.0.0 execution order:** #1 → #2 (cleanup legacy) → #6 (architectural split). Features #3–#5 post v2.0.0. **#7 is a cross-project initiative that unlocks only after v2.0.0 is shipped AND stable — see Cross-Project Reminders below.**

## 🔔 Cross-Project Reminders

> **For the agent reading this on session start:** if any item in this section is triggered, surface it in your session opening summary so Maksim is reminded.

### Post-v2.0.0 Trigger — Ecosystem Orchestration

- **Status:** dormant — waiting for Framework v2.0.0 to ship and stabilize
- **Trigger condition:** when v2.0.0 tasks #1, #2, #6 are all marked ✅ in the Phase Status table AND v2.0.0 has been live for several weeks without major regressions
- **What to remind Maksim about:** the concept spec **"Оркестрация экосистемы Woodev"** — system-wide automation across all Woodev projects (framework, ~12 plugins, woodev-theme, n8n automations, marketing/content). Goal: zero unnecessary human in the change-propagation flow
- **Spec location:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`
- **Why this lives in this project's docs:** Framework v2.0 is the gating prerequisite for the orchestration work. The reminder belongs where v2.0 progress is tracked
- **What the agent must do when trigger fires:**
  1. Mention the reminder in the session opening summary — do NOT bury it
  2. Do **NOT** auto-start implementation work
  3. Point Maksim to the spec file above and ask whether he wants to revisit it now
  4. If yes — read the spec's "Prompt for the Future Agent" section first (it has explicit anti-implementation instructions)
- **Cross-reference:** `FUTURE-BACKLOG.md` → "Cross-Project Initiatives" → #7

## Active Queue

> Platform v2 resolver facade + explicit loader definition slice is complete. Phase 3 is stopped: WooCommerce hook ownership, initial `supported_features`/Blocks handler construction, WooCommerce system-status row ownership, WooCommerce logger ownership, WooCommerce template loader ownership, HPOS/Blocks feature declarations, and payment/shipping specialized base inheritance live in `Woodev_Woocommerce_Plugin`; remaining `Woodev_Plugin` WooCommerce-adjacent items are compatibility wrappers or broader Phase 5 cleanup. Phase 4 callback timing coverage is complete for payment/shipping specialized child class declaration. Phase 5 cleanup now has fourteen verified slices, with the latest helper fallback work removing no-WooCommerce fatals from `Woodev_Helper::format_percentage()` and `Woodev_Helper::shop_has_virtual_products()` while preserving the WooCommerce execution paths when available. Remaining residual seams after the re-scan are the boundary-sensitive `wc_rest_check_manager_permissions()` path in the REST settings controller plus intentional WooCommerce wrappers/diagnostics in `woodev/class-helper.php`; no third clean atomic Phase 5 slice is currently defined from this boundary. Stop gate: external review by another model is required before any Phase 6 migration-contract or production-loader work begins.

## Infrastructure Reference

- **Framework version:** Woodev_Plugin::VERSION (in woodev/class-plugin.php)
- **PHP target:** 8.1 (composer platform)
- **WP minimum:** 6.3
- **WC minimum:** 7.0
- **Test framework:** Brain Monkey (unit) + WP Test Library (integration)
- **CI:** GitHub Actions (docs.yml, markdown-lint.yml, release workflow)
