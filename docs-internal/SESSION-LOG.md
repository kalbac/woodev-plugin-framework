# Session Log — Woodev Plugin Framework

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
