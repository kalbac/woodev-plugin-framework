# Current State — Woodev Plugin Framework
> Last updated: 2026-05-10 (s3)

## Phase Status

| Phase | Code | Browser-verified | Notes |
|-------|------|------------------|-------|
| Framework Core | ✅ | ✅ | Bootstrap, Plugin base, Lifecycle — stable |
| Payment Gateway | ✅ | ✅ | class-payment-gateway.php: ~2990 lines (was 3927, -937) |
| Shipping Method | ✅ | ✅ | PSR-4 namespaced |
| Licensing | ✅ | ✅ | EDD store integration |
| Settings API | ✅ | ✅ | Typed settings framework |
| Box Packer | ✅ | ✅ | Shipping box-packing algorithm |
| REST API | ✅ | ✅ | Plugin REST routes |
| Documentation Structure | ✅ | — | Two-tier: docs/ (GH Pages) + docs-internal/ (AI agents) |
| Legacy Cleanup (v2.0.0) | ✅ | — | ~1647 lines removed: dead compat, deprecated methods, US-specific types |
| PHPStan Baseline | ✅ | ✅ | 0 errors, baseline cleaned up with documented ignores |

## Known Bugs (open)

- [⚠️] class-payment-gateway.php is ~2990 lines — candidate for trait extraction
- [✅] 50+ PHPStan baseline ignores — cleaned up (s3)
- [✅] Woodev_Plugin_Dependencies::get_missing_php_functions() — fixed in `4d00539`
- [✅] 11 deprecated methods in Woodev_Plugin — removed in `728c6f9`
- [✅] 47 deprecated methods total across codebase — removed in `728c6f9`
- [✅] 12 dead compat guards for WP/WC below minimums — removed in `728c6f9`
- [✅] Woodev_Helper::get_post() call to non-existent method — fixed in s3 (→ get_posted_value)
- [✅] Woodev_Payment_Gateway::$voided_order_message dynamic property — fixed in s3 (declared private)

## Next Actions (priority order)

1. ~~Populate docs-internal/gotchas/~~ ✅ done (s2)
2. ~~Fix get_missing_php_functions() bug~~ ✅ done (s2)
3. ~~Clean up PHPStan baseline~~ ✅ done (s3)
4. Extract traits from class-payment-gateway.php (deferred to big refactoring session)
5. eCheck/ACH removal (separate session)

## Planned — v2.0.0 & Beyond

> Detailed specs in `docs-internal/FUTURE-BACKLOG.md`

| # | Task | Category | Target |
|---|------|----------|--------|
| 1 | Bump WP/WC minimums (WP 6.3+, WC 7.0+) + remove deprecated compat code | Maintenance | v2.0.0 |
| 2 | Remove unused US-specific payment types (echeck, Apple Pay, Google Pay) | Cleanup | v2.0.0 |
| 3 | Push notifications & webhooks (server→client) | Feature | Post v2.0.0 |
| 4 | Shipping module boilerplate | Feature | Post v2.0.0 |
| 5 | React-oriented admin UI | Feature | Post v2.0.0 |
| 6 | Framework decoupling — support pure WP plugins + future EDD | Architecture | v2.0.0 |

> **v2.0.0 execution order:** #1 → #2 (cleanup legacy) → #6 (architectural split). Features #3–#5 post v2.0.0.

## Active Queue

> s3 — PHPStan baseline cleanup complete: 410 errors → 0. 4 code bugs fixed. 400+ payment-gateway self-references documented and ignored.

## Infrastructure Reference

- **Framework version:** Woodev_Plugin::VERSION (in woodev/class-plugin.php)
- **PHP target:** 8.1 (composer platform)
- **WP minimum:** 5.9
- **WC minimum:** 5.6
- **Test framework:** Brain Monkey (unit) + WP Test Library (integration)
- **CI:** GitHub Actions (docs.yml, markdown-lint.yml, release workflow)
