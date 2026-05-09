# Current State — Woodev Plugin Framework

> Last updated: 2026-05-09 (s1)

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

## Next Actions (priority order)

1. Populate docs-internal/gotchas/ with initial gotchas from codebase patterns
2. Extract traits from class-payment-gateway.php
3. Clean up PHPStan baseline
4. Execute deprecation removal for v2.0.0

## Active Queue

> s1 — docs-internal/ structure established. Ready for content population.

## Infrastructure Reference

- **Framework version:** Woodev_Plugin::VERSION (in woodev/class-plugin.php)
- **PHP target:** 8.1 (composer platform)
- **WP minimum:** 5.9
- **WC minimum:** 5.6
- **Test framework:** Brain Monkey (unit) + WP Test Library (integration)
- **CI:** GitHub Actions (docs.yml, markdown-lint.yml, release workflow)
