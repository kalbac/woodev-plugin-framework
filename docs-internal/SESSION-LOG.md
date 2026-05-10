# Session Log — Woodev Plugin Framework

## s2 (2026-05-10): Gotcha population — 10 atomic gotcha files created
- Populated docs-internal/gotchas/ with 10 gotcha files from codebase analysis:
  - [naming] woodev-spelling — single 'd', never wooddev
  - [bootstrap] singleton-instantiation — constructor is private, must use ::instance()
  - [bootstrap] plugin-registration-timing — register_plugin() must run before plugins_loaded
  - [bootstrap] payment-gateway-conditional-load — is_payment_gateway arg required for gateways
  - [compat] hpos-order-meta-safety — never use get_post_meta() on orders (HPOS silent data loss)
  - [php] dependency-function-check-bug — REAL BUG: extension_loaded instead of function_exists
  - [php] namespace-migration-legacy-psr4 — legacy Woodev_* vs PSR-4 Woodev\Framework\*
  - [deprecation] deprecated-which-function — wc_deprecated_function vs _deprecated_function decision rule
  - [deprecation] hook-deprecator-usage — Woodev_Hook_Deprecator, not _deprecated_hook()
  - [lifecycle] install-upgrade-detection — version comparison drives install/upgrade logic
- Updated GOTCHAS.md index — 10 entries across 6 namespaces (was 0 empty namespaces)
- Updated CURRENT-STATE.md — marked #1 done, added real bug to Known Bugs, added #2 (bugfix)
- Key discovery: get_missing_php_functions() bug is a copy-paste error from get_missing_php_extensions() (line 374 vs line 344)
- Each gotcha file follows DOCS-SCHEMA.md format: What happens + Root cause + Fix (❌/✅ code) + Related links
- Build: n/a (docs only, no code changes)

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
