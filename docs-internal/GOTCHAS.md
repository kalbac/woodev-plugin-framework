# Gotchas — Woodev Plugin Framework
> **46 atomic gotchas in 16 namespaces** — update count when adding/removing.
> Last updated: 2026-06-13 (session 12: 2 gotchas — `is_enhanced_admin_available()` returns true unconditionally (guard WC code on the Note class); WC-note breadcrumb survives a single-v2-plugin deactivation only if created AFTER handle_deactivation's source bulk-delete)

## Index

<!-- Format: - [namespace/tag] summary → [gotchas/slug.md](gotchas/slug.md) (s{N}) -->

### [naming/*] — Identifier conventions
- [naming/woodev-spelling] woodev (single 'd'), NEVER wooddev → [gotchas/woodev-spelling.md](gotchas/woodev-spelling.md) (s2)

### [php/*] — PHP / WordPress patterns
- [php/dependency-function-check-bug] get_missing_php_functions() uses extension_loaded instead of function_exists → [gotchas/dependency-function-check-bug.md](gotchas/dependency-function-check-bug.md) (s2)
- [php/namespace-migration-legacy-psr4] Legacy Woodev_* vs PSR-4 Woodev\Framework\* conventions → [gotchas/namespace-migration-legacy-psr4.md](gotchas/namespace-migration-legacy-psr4.md) (s2)
- [php/gateway-type-methods-required] Never blanket-ignore `Call to an undefined method` on a class hierarchy — same class of bug as 2026-05-31; audit 2026-06-01 found 3 more surviving instances → [gotchas/gateway-type-methods-required.md](gotchas/gateway-type-methods-required.md) (s3; recurred 2026-05-31; re-audited 2026-06-01)
- [php/blocks-handler-typed-property-trap] Non-nullable typed return on `get_blocks_handler()` can TypeError for pure-WordPress subclasses (property only initialized in Woocommerce_Plugin) → [gotchas/blocks-handler-typed-property-trap.md](gotchas/blocks-handler-typed-property-trap.md) (2026-06-01)
- [php/php84-implicit-nullable-payment-handlers] Legacy payment handler files use implicit-nullable `$arg = null` — deprecated PHP 8.4+, hidden by `error_reporting` mask in RealisticPaymentFixtureTest → [gotchas/php84-implicit-nullable-payment-handlers.md](gotchas/php84-implicit-nullable-payment-handlers.md) (2026-06-01)
- [php/wc-compat] `Woodev_Plugin_Compatibility::is_enhanced_admin_available()` returns `true` UNCONDITIONALLY — it can't gate WC-only code out of the unit suite; guard WC-Admin code on `class_exists('\Automattic\WooCommerce\Admin\Notes\Note')` (checked first, before any mocked accessor) → [gotchas/is-enhanced-admin-available-always-true.md](gotchas/is-enhanced-admin-available-always-true.md) (s12)

### [deprecation/*] — Deprecation cycle
- [deprecation/deprecated-which-function] wc_deprecated_function vs _deprecated_function — which to use when → [gotchas/deprecated-which-function.md](gotchas/deprecated-which-function.md) (s2)
- [deprecation/hook-deprecator-usage] Use Woodev_Hook_Deprecator, not _deprecated_hook() directly → [gotchas/hook-deprecator-usage.md](gotchas/hook-deprecator-usage.md) (s2)

### [bootstrap/*] — Multi-version loading
- [bootstrap/singleton-instantiation] Bootstrap is singleton, constructor is private → [gotchas/singleton-instantiation.md](gotchas/singleton-instantiation.md) (s2)
- [bootstrap/plugin-registration-timing] register_plugin() must run before plugins_loaded → [gotchas/plugin-registration-timing.md](gotchas/plugin-registration-timing.md) (s2)
- [bootstrap/payment-gateway-conditional-load] Payment gateway base class loaded only when is_payment_gateway arg is set → [gotchas/payment-gateway-conditional-load.md](gotchas/payment-gateway-conditional-load.md) (s2)
- [bootstrap/multiversion-early-class-guards] Early-loaded support classes must be guarded and loaded from the selected framework copy → [gotchas/multiversion-early-class-guards.md](gotchas/multiversion-early-class-guards.md) (s4)
- [bootstrap/resolver-bootstrap-coupling] `Framework_Resolver` references `Woodev_Plugin_Bootstrap::instance()` in 3 places for notice wiring — undermines "minimal resolver" boundary; tests don't catch because happy-path data → see [../../docs-internal/audit-2026-06-01.md#m1](../../docs-internal/audit-2026-06-01.md) (2026-06-01)

### [php/*] — PHP class loading patterns
- [php/class-alias-phpstan-resolution] `class_alias()` in a conditionally-loaded file is invisible to PHPStan; use FQCN in internal code OR declare a real subclass → [gotchas/class-alias-phpstan-resolution.md](gotchas/class-alias-phpstan-resolution.md) (2026-06-02)

### [compat/*] — Backward compatibility, HPOS
- [compat/hpos-order-meta-safety] Never use get_post_meta() on orders — use Woodev_Order_Compatibility → [gotchas/hpos-order-meta-safety.md](gotchas/hpos-order-meta-safety.md) (s2)

### [lifecycle/*] — Install/upgrade routines
- [lifecycle/install-upgrade-detection] Lifecycle detects install vs upgrade by version comparison → [gotchas/lifecycle-install-upgrade-detection.md](gotchas/lifecycle-install-upgrade-detection.md) (s2)

### [woocommerce/*] — WooCommerce-specific
- [woocommerce/shipping-api-broken-contract] `Woodev\Framework\Shipping\Shipping_API` interface references 6 types (Rate_Response, Order_Response, Tracking_Response, Pickup_Points_Response, Exportable_Order, Shipping_Exception) that don't exist in the framework — masked by blanket PHPStan ignore → [gotchas/shipping-api-broken-contract.md](gotchas/shipping-api-broken-contract.md) (2026-06-01)

### [framework/*] — Framework internals
- [framework/includes-wiring] New framework class files must be `require_once`'d in the right `includes()` (dependency order; WC files gated) — the Composer classmap loads them in tests but production fatals if unwired → [gotchas/dispatcher-files-unwired-in-includes.md](gotchas/dispatcher-files-unwired-in-includes.md) (session 2)
- [framework/includes-wiring] `class-item-implementation.php` implemented `Woodev_Box_Packer_Item_With_Product` whose interface file was never required in `includes()` → release-blocking WSOD on every real vendored v2 boot (no runtime autoloader); classmap masked it in tests; first live boot caught it → [gotchas/box-packer-interface-unwired-in-includes.md](gotchas/box-packer-interface-unwired-in-includes.md) (s11)

### [testing/*] — Testing patterns
- [testing/integration] Integration fixtures need the framework mapped at the bootstrap's load path (`woodev-framework/tests/_fixtures/*/woodev` in `.wp-env.json`), not just the `wp-content/plugins/*` mount — the v2 resolver requires each fixture's bundled `woodev/class-plugin.php` → [gotchas/wpenv-resolver-fixture-mapping.md](gotchas/wpenv-resolver-fixture-mapping.md) (2026-06-08)
- [testing/unit] Brain Monkey `expect`/`when` DEFINES a function and PHP can't un-define it, so it leaks (`function_exists` true) into later tests in the same process — order-dependent "passes locally / fails on CI"; isolate "function-absent" tests with `@runInSeparateProcess` → [gotchas/brain-monkey-function-pollution.md](gotchas/brain-monkey-function-pollution.md) (2026-06-08)
- [testing/unit] Reflection `setAccessible()` is REQUIRED on PHP < 8.1 and DEPRECATED on 8.5 — guard private getValue/invoke with `if ( PHP_VERSION_ID < 80100 )` to satisfy both ends of the supported range → [gotchas/reflection-setaccessible-version-guard.md](gotchas/reflection-setaccessible-version-guard.md) (2026-06-08)
- [testing/integration] `rest_cookie_check_errors()` only checks the nonce when global `$wp_rest_auth_cookie === true`; nonce comes from superglobals, not the request object; missing nonce demotes to anonymous (later 401 via `rest_authorization_required_code()`), only an invalid nonce errors directly → [gotchas/rest-cookie-nonce-auth-semantics.md](gotchas/rest-cookie-nonce-auth-semantics.md) (s8)
- [testing/unit] PHPUnit silently runs ONLY the first file argument when given several — "both files green" can mean file B never executed; run per-file or use --testsuite/--filter → [gotchas/phpunit-multiple-file-args.md](gotchas/phpunit-multiple-file-args.md) (s9)
- [testing/integration] wp-env on Windows Git-Bash: MSYS mangles absolute container paths (`/var/www/…` → `C:/Program Files/Git/…`) — run from PowerShell or wrap in `bash -c "cd …"`; integration bootstrap also needs `TEST_SUITE=integration` → [gotchas/wpenv-windows-gitbash-path-mangling.md](gotchas/wpenv-windows-gitbash-path-mangling.md) (s9)
- [testing/unit] Patchwork redefinable internals (`function_exists`, `error_log`) need Patchwork force-loaded in bootstrap BEFORE source files — Brain Monkey loads it lazily at first setUp(), but PHPUnit compiles all required source at suite-build time → order-dependent dead stubs → [gotchas/patchwork-early-load-bootstrap.md](gotchas/patchwork-early-load-bootstrap.md) (s9)
- [testing/integration] Local two-stack e2e rig: `wp_safe_remote_request` (framework licensing transport) blocks private hosts (`host.docker.internal`) + non-80/443/8080 ports → silent swallowed throw, pull never runs. Stand-only fix: `http_request_host_is_external` + `http_allowed_safe_ports` filters + `woodev_licensing_api_url` + local-pubkey define; use PULL (cross-container push can't work) → [gotchas/wp-safe-remote-request-local-rig.md](gotchas/wp-safe-remote-request-local-rig.md) (s11)

### [api/*] — API layer
<!-- No entries yet -->

### [licensing/*] — License/EDD store
- [licensing/two-layer] `is_need_license()` (Woodev_Plugin, presentation, UNTRUSTED) vs `is_license_required()` (Woodev_Plugins_License, enforcement, server-trusted) — gating a feature/enforcement on the local flag reopens the piracy hole; the local flag renders UI only → [gotchas/license-need-vs-required.md](gotchas/license-need-vs-required.md) (2026-06-10)
- [licensing/remote-deactivation] A remotely-deactivated single-v2-plugin can't render its own `admin_notices` banner (no framework code loads when inactive); surface it via a WC Admin inbox note created AFTER `deactivate_plugins()`/`handle_deactivation` (which bulk-deletes notes by source) so it survives → [gotchas/wc-note-breadcrumb-survives-deactivation.md](gotchas/wc-note-breadcrumb-survives-deactivation.md) (s12)
- [licensing/option-keys] License-key option double-prefix for plugin ids starting with `woodev`: `get_plugin_option_name()` always prepends `woodev_`, `Woodev_License` only conditionally → write/read diverge. Real plugin ids unaffected; never name a plugin/fixture id `woodev*` → [gotchas/license-key-option-double-prefix.md](gotchas/license-key-option-double-prefix.md) (s11)

### [build/*] — Build/CI/release
- [build/ci] A failing early CI job (e.g. Lint) silently SKIPS jobs that `needs:` it — skipped ≠ failed, so the suite looks green while dependent jobs (the whole Unit matrix here) never run; fixing the gate REVEALS masked failures → [gotchas/ci-failing-gate-skips-dependent-jobs.md](gotchas/ci-failing-gate-skips-dependent-jobs.md) (2026-06-08)
- [build/ci] `composer audit --no-dev` errors "No installed packages found" for a library with no runtime deps — use `composer audit --locked` → [gotchas/composer-audit-no-prod-deps.md](gotchas/composer-audit-no-prod-deps.md) (2026-06-08)
- [build/ci] markdownlint-cli2 ignores `.markdownlintignore` when globs are passed as CLI args — manage exclusions in the workflow glob; MD051 disabled (can't validate Cyrillic anchors) → [gotchas/markdownlint-ignorefile-vs-globs.md](gotchas/markdownlint-ignorefile-vs-globs.md) (2026-06-08)
- [build/ci] A PR that conflicts with base (`mergeStateStatus: DIRTY`) runs NO `pull_request` CI — only `pull_request_target`; "all green" can mean the matrix never ran. Check `gh pr view --json mergeable,mergeStateStatus`; rebase onto the new base after a squash-merge → [gotchas/pr-conflict-skips-pull-request-ci.md](gotchas/pr-conflict-skips-pull-request-ci.md) (session 2)
- [build/js] `@wordpress/scripts` default (automatic) JSX runtime depends on the `react-jsx-runtime` script handle — registered only in WP ≥ 6.6; for WP 6.3+ support force the classic runtime via `babel.config.js` and import `createElement`/`Fragment` in every JSX file → [gotchas/wp-scripts-jsx-runtime-wp66.md](gotchas/wp-scripts-jsx-runtime-wp66.md) (s8)

### [box-packer/*] — Box-packer algorithm (S2)
- [box-packer/virtual-box-rsort-axis-alignment] `rsort()` on the axis-assignment result destroys axis-name alignment for non-normalized items — Option A `[1,10,1]` after rsort → `[10,1,1]` → `box_width=1 < item_width=10` → packing rejects item. Never rsort the candidate; each option guarantees axis alignment by construction → [gotchas/virtual-box-rsort-axis-alignment.md](gotchas/virtual-box-rsort-axis-alignment.md) (2026-06-09)
- [box-packer/virtual-box-null-best-inf-overflow] `$best=null; $best_volume=PHP_FLOAT_MAX` → if all candidate volumes overflow to INF, `INF < PHP_FLOAT_MAX = false` → `$best` never set → null dereference. Fix: initialize `$best = $candidates[0]` → [gotchas/virtual-box-null-best-inf-overflow.md](gotchas/virtual-box-null-best-inf-overflow.md) (2026-06-09)

### [shipping/*] — Shipping module (S1)
- [shipping/contracts] Session key ≠ order-meta prefix — composing one key for both checkout session and order meta breaks installed-site data (Yandex: `chosen_yandex_pickup_point` vs `_yandex_delivery_`) → [gotchas/session-key-vs-order-meta-prefix.md](gotchas/session-key-vs-order-meta-prefix.md) (2026-06-06)
- [shipping/contracts] Installed-site contract strings (AJAX action, admin slug, meta key…) are NOT derivable from the plugin id by convention — the plugin must supply each; edostavka `wc_edostavka_orders` vs yandex `wc-yandex-orders` proves no single rule → [gotchas/contract-string-not-derivable.md](gotchas/contract-string-not-derivable.md) (2026-06-06)
- [shipping/rate-calc] Do NOT sum per-parcel prices in the framework rate seam — `calculate_rate` (final template) only wires packing; per-parcel summing mis-prices multi-place carrier tariffs (СДЭК/Яндекс quote a whole shipment in one request). The carrier subclass aggregates → [gotchas/shipping-rate-no-parcel-sum.md](gotchas/shipping-rate-no-parcel-sum.md) (s3)
- [shipping/warehouse-identity] Warehouse storage row id ≠ carrier-unique id — VO carries a nullable `storage_id` (DB PK) separate from `get_id()` (carrier code); store keys CRUD on the row id, never fold the REST route id into `get_id()`, and use read-merge on update → [gotchas/warehouse-storage-id-vs-carrier-id.md](gotchas/warehouse-storage-id-vs-carrier-id.md) (session 2)

### [i18n/*] — Localization
- [i18n/russian-source-plural-n] `_n()` with Russian SOURCE strings falls back to English 2-form plural logic (no ru catalog exists — Russian IS the source) → 21/31/101 render the wrong form; avoid `_n()`, use count-neutral phrasing → [gotchas/russian-source-i18n-plural-n.md](gotchas/russian-source-i18n-plural-n.md) (s7)

### [autodev/*] — Adversarial dev loop tooling
- [autodev/serena-worktree] Serena MCP index is bound to the MAIN working tree (its current branch + uncommitted edits) — workers operating in a git worktree must use Grep/Read under the worktree root, never Serena; `invoke-worker.ps1` prompt says the opposite (follow-up) → [gotchas/serena-index-vs-git-worktree.md](gotchas/serena-index-vs-git-worktree.md) (s7)
- [autodev/circuit-breaker] Refund the attempt on EVERY external pause (worker AND critic 429), not just the worker's — an unrefunded critic rate-limit marches a DONE task into a false poison → [gotchas/autodev-attempt-refund-symmetry.md](gotchas/autodev-attempt-refund-symmetry.md) (2026-06-06)
- [autodev/critic] Critic over-flags two non-breaks as `broken` on every incremental task: a NEW additive hook name, and "class not yet wired into includes()" (wiring is the separate s1-p6 task). Keep its correct contract/logic findings; recalibrate only these two → [gotchas/autodev-critic-overflag.md](gotchas/autodev-critic-overflag.md) (2026-06-06)
- [autodev/critic] invoke-critic mis-read benign repo text as a 429: it scanned the critic's ENTIRE output (incl. docs the critic READ that mention the prior critic-429 fix) with a hard-coded non-zero exit, discarding valid verdicts and re-queueing forever. Fix: parse the verdict first (it wins); rate-limit only when no verdict, using codex's real exit code → [gotchas/autodev-critic-ratelimit-false-positive.md](gotchas/autodev-critic-ratelimit-false-positive.md) (2026-06-07, fixed b186c52)

### [tooling/*] — Dev tooling, codex critic
- [tooling/codex-shell-sandbox-broken-windows] `codex exec -s read-only` shell-spawn fails on this Windows box (`CreateProcessAsUserW failed: 5`) — run critics with an INLINE bundle (spec+diffs+reference source in the prompt), never relying on codex shell access → [gotchas/codex-shell-sandbox-broken-windows.md](gotchas/codex-shell-sandbox-broken-windows.md) (s10)

## Archive (resolved gotchas)
<!-- Resolved gotchas move here; keep for 2 sessions then remove -->
