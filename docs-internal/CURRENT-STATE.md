# Current State — Woodev Plugin Framework

> Last updated: 2026-05-10 (s2)

## Phase Status

| Phase | Code | Browser-verified | Notes |
|-------|------|------------------|-------|
| Framework Core | ✅ | ✅ | Bootstrap, Plugin base, Lifecycle — stable |
| Payment Gateway | ✅ | ✅ | class-payment-gateway.php (~3900 lines) |
| Shipping Method | ✅ | ✅ | PSR-4 namespaced |
| Licensing | ✅ | ✅ | EDD store integration |
| Settings API | ✅ | ✅ | Typed settings framework |
| Box Packer | ✅ | ✅ | Shipping box-packing algorithm |
| REST API | ✅ | ✅ | Plugin REST routes |
| Documentation Structure | ✅ | — | Two-tier: docs/ (GH Pages) + docs-internal/ (AI agents). AGENTS.md, CLAUDE.md refactored |

## Known Bugs (open)

- [⚠️] 50+ PHPStan baseline ignores (see phpstan-baseline.neon)
- [⚠️] 11 deprecated methods in Woodev_Plugin (~lines 1486–1629), slated for removal in v2.0.0
- [⚠️] class-payment-gateway.php is ~3900 lines — candidate for trait extraction
- [✅] Woodev_Plugin_Dependencies::get_missing_php_functions() used extension_loaded() instead of function_exists() — fixed in `4d00539`

## Next Actions (priority order)

1. ~~Populate docs-internal/gotchas/ with initial gotchas from codebase patterns~~ ✅ done (s2)
2. ~~Fix get_missing_php_functions() bug — extension_loaded → function_exists~~ ✅ done (s2, `4d00539`)
3. Extract traits from class-payment-gateway.php
4. Clean up PHPStan baseline
5. Execute deprecation removal for v2.0.0

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

> s2 — 10 gotcha files created + bug fix (extension_loaded→function_exists). Ready for trait extraction (#3).

## Infrastructure Reference

- **Framework version:** Woodev_Plugin::VERSION (in woodev/class-plugin.php)
- **PHP target:** 8.1 (composer platform)
- **WP minimum:** 5.9
- **WC minimum:** 5.6
- **Test framework:** Brain Monkey (unit) + WP Test Library (integration)
- **CI:** GitHub Actions (docs.yml, markdown-lint.yml, release workflow)
