# Session Log — Woodev Plugin Framework

## s2 (2026-05-10): Gotcha population + legacy cleanup (2 commits)

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
