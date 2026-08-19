# Gotchas — Woodev Plugin Framework

> **Index only.** 166 atomic gotchas across 31 namespaces. Every entry is ONE line: a hook you can
> recognise, and a link to the file that holds the detail. Never paste the detail here — a second
> copy drifts from the first, and this file is read at the start of every session.
> **Adding one:** create `gotchas/{slug}.md` (format: `DOCS-SCHEMA.md`), then add one line below
> under the right `[topic/*]` section. Dedup first — update the existing file, never open a second
> one on the same topic. History of what changed when → `SESSION-LOG.md` + `sessions/sNN.md`.

## Index

<!-- Format: - [namespace/tag] summary → [gotchas/slug.md](gotchas/slug.md) (s{N}) -->

### [naming/*] — Identifier conventions
- [naming/woodev-spelling] **woodev (single 'd'), NEVER wooddev.** → [woodev-spelling](gotchas/woodev-spelling.md) (s2)

### [php/*] — PHP / WordPress patterns
- [php/dependency-function-check-bug] **get_missing_php_functions() uses extension_loaded instead of function_exists.** → [dependency-function-check-bug](gotchas/dependency-function-check-bug.md) (s2)
- [php/namespace-migration-legacy-psr4] **Legacy Woodev_* vs PSR-4 Woodev\Framework\*.** → [namespace-migration-legacy-psr4](gotchas/namespace-migration-legacy-psr4.md) (s2)
- [php/gateway-type-methods-required] **Never blanket-ignore `Call to an undefined method` on a class hierarchy.** → [gateway-type-methods-required](gotchas/gateway-type-methods-required.md) (s3; recurred 2026-05-31; re-audited 2026-06-01)
- [php/blocks-handler-typed-property-trap] **Non-nullable typed return can TypeError for pure-WordPress plugin subclasses.** → [blocks-handler-typed-property-trap](gotchas/blocks-handler-typed-property-trap.md)
- [php/php84-implicit-nullable-payment-handlers] **Legacy payment handler files use implicit-nullable parameters; PHP 8.4+ deprecates them.** → [php84-implicit-nullable-payment-handlers](gotchas/php84-implicit-nullable-payment-handlers.md)
- [php/wc-compat] **`Woodev_Plugin_Compatibility::is_enhanced_admin_available()` returns `true` unconditionally.** → [is-enhanced-admin-available-always-true](gotchas/is-enhanced-admin-available-always-true.md) (s12)
- [php/in-plugin-update-message-arg-shape] **`in_plugin_update_message-{$file}` — arg shape: `package`/`new_version` live on arg 2 (response), not arg 1.** → [in-plugin-update-message-arg-shape](gotchas/in-plugin-update-message-arg-shape.md) (s18)
- [php/updater-cache-source-stamp-not-key] **Isolating a cache by source without changing a frozen option key — stamp metadata inside the value.** → [updater-cache-source-stamp-not-key](gotchas/updater-cache-source-stamp-not-key.md) (s18)
- [php/class-alias-phpstan-resolution] **class_alias() and PHPStan.** → [class-alias-phpstan-resolution](gotchas/class-alias-phpstan-resolution.md)
- [php/stdlib] **Three PHP/WP stdlib behaviours that pass tests and fail in production.** → [php-stdlib-traps-that-survive-tests](gotchas/php-stdlib-traps-that-survive-tests.md) (s45)

### [settings-api/*] — Settings API
- [settings-api/save-path] **Settings-API save path — validate enums by key-or-value, coerce numbers, sanitize HTML.** → [settings-api-control-save-path-pitfalls](gotchas/settings-api-control-save-path-pitfalls.md) (s31)
- [settings-api/secrets] **A `constant_name`-backed field must be masked even when the constant is UNDEFINED.** → [mask-constant-backed-field-even-when-constant-undefined](gotchas/mask-constant-backed-field-even-when-constant-undefined.md) (s38)
- [settings-api/validation] **Format validators must guard non-string input (is_email/strpos on null → PHP 8.1 deprecation).** → [format-validator-null-strlen-deprecation](gotchas/format-validator-null-strlen-deprecation.md) (s39)
- [settings-api/secrets] **Settings sensitive secret: the "don't overwrite with empty" guard is CLIENT-side, not server.** → [settings-sensitive-secret-empty-skip-is-client-side](gotchas/settings-sensitive-secret-empty-skip-is-client-side.md) (s41)
- [settings-api/caching] **`Woodev_Setting::get_value()` returns a cached property, so `update_option()` mid-request is invisible to it.** → [woodev-setting-get-value-is-cached-not-a-live-option-read](gotchas/woodev-setting-get-value-is-cached-not-a-live-option-read.md) (s71)
- [settings-api/section-empty-ids] **A settings section declaring NO setting ids renders the WHOLE handler, because `get_settings( [] )` means "all".** → [section-empty-setting-ids-renders-all-fields](gotchas/section-empty-setting-ids-renders-all-fields.md) (s79)

### [deprecation/*] — Deprecation cycle
- [deprecation/deprecated-which-function] **wc_deprecated_function vs _deprecated_function.** → [deprecated-which-function](gotchas/deprecated-which-function.md) (s2)
- [deprecation/hook-deprecator-usage] **Use Woodev_Hook_Deprecator, not _deprecated_hook().** → [hook-deprecator-usage](gotchas/hook-deprecator-usage.md) (s2)

### [bootstrap/*] — Multi-version loading
- [bootstrap/singleton-instantiation] **Bootstrap is singleton, constructor is private.** → [singleton-instantiation](gotchas/singleton-instantiation.md) (s2)
- [bootstrap/plugin-registration-timing] **register_plugin() must run before plugins_loaded.** → [plugin-registration-timing](gotchas/plugin-registration-timing.md) (s2)
- [bootstrap/payment-gateway-conditional-load] **Payment gateway base class loads conditionally.** → [payment-gateway-conditional-load](gotchas/payment-gateway-conditional-load.md) (s2)
- [bootstrap/multiversion-early-class-guards] **Guard and source early support classes.** → [multiversion-early-class-guards](gotchas/multiversion-early-class-guards.md) (s4)

### [compat/*] — Backward compatibility, HPOS
- [compat/hpos-order-meta-safety] **Never use get_post_meta() on orders.** → [hpos-order-meta-safety](gotchas/hpos-order-meta-safety.md) (s2)

### [lifecycle/*] — Install/upgrade routines
- [lifecycle/install-upgrade-detection] **Lifecycle detects install vs upgrade by version comparison.** → [lifecycle-install-upgrade-detection](gotchas/lifecycle-install-upgrade-detection.md) (s2)

### [woocommerce/states] — The `woocommerce_states` table
- [woocommerce/states] **`(array) WC()->countries->get_states( $country )` is NEVER empty for a country without states.** → [array-cast-of-get-states-false-is-not-empty](gotchas/array-cast-of-get-states-false-is-not-empty.md) (s71)
- [woocommerce/states] **WooCommerce uppercases the posted state and rewrites it through a flipped map — a human label used as a state KEY is mangled and the select then loses….** → [wc-uppercases-the-posted-state-and-flips-the-map](gotchas/wc-uppercases-the-posted-state-and-flips-the-map.md) (s71)

### [woocommerce/*] — WooCommerce-specific
- [woocommerce/shipping-api-broken-contract] **Shipping_API interface references types that don't exist in the framework.** → [shipping-api-broken-contract](gotchas/shipping-api-broken-contract.md)

### [framework/*] — Framework internals
- [framework/includes-wiring] **New framework class files must be wired into `includes()`, not just the Composer classmap.** → [dispatcher-files-unwired-in-includes](gotchas/dispatcher-files-unwired-in-includes.md)
- [framework/includes-wiring] **Vendored framework has no runtime autoloader — every `includes()` require must be complete.** → [box-packer-interface-unwired-in-includes](gotchas/box-packer-interface-unwired-in-includes.md) (s11)
- [framework/autoload] **Framework classes must be in the generated classmap, or they WSOD on a real vendored boot.** → [framework-classmap-autoload-vendored-boot](gotchas/framework-classmap-autoload-vendored-boot.md) (s27)
- [framework/autoload] **class_exists() "init-once" guards break under the runtime class-map autoloader.** → [classmap-autoload-breaks-class-exists-once-guard](gotchas/classmap-autoload-breaks-class-exists-once-guard.md) (s35)

- [framework/autoload] **Deleting a framework file has a tail: `includes()`, the class map, and fixture bootstraps.** → [file-deletion-tail-includes-classmap-fixtures](gotchas/file-deletion-tail-includes-classmap-fixtures.md) (s45)
- [framework/handler-extraction] **A handler extraction that self-registers its hook can silently disable a subclass override.** → [handler-extraction-must-preserve-override-chain](gotchas/handler-extraction-must-preserve-override-chain.md)

### [framework/contracts] — What the framework guarantees to its consumers
- [framework/contracts] **A field that is a VIEW of another must be derived at the boundary, never at the display sites.** → [derive-a-view-field-at-the-boundary-not-at-display-sites](gotchas/derive-a-view-field-at-the-boundary-not-at-display-sites.md) (s64)
- [framework/contracts] **A cross-provider `within` is handed over as COMPONENTS, never as a key — no key translation layer is needed or wanted.** → [a-cross-provider-within-is-handed-over-as-components](gotchas/a-cross-provider-within-is-handed-over-as-components.md) (s76)

### [woocommerce/*] — WooCommerce-specific (session)
- [woocommerce/address-save] **WooCommerce saves no address until every required TEXT field in the block is filled — the gate is in the JS.** → [wc-does-not-save-the-address-until-every-required-text-field-is-filled](gotchas/wc-does-not-save-the-address-until-every-required-text-field-is-filled.md) (s65)
- [woocommerce/session] **A guest's `WC()->session->set()` can silently not persist — a logged-in developer never sees it.** → [guest-session-write-needs-the-cart-cookie](gotchas/guest-session-write-needs-the-cart-cookie.md) (s65)

- [framework/contracts] **An empty string from a domain seam is the domain FAILING to answer, not a key.** → [an-empty-domain-key-is-not-a-key](gotchas/an-empty-domain-key-is-not-a-key.md) (s65)

- [woocommerce/address-autocomplete] **WC Address Autocomplete hosts ONLY address_1, flattens identity, and clears what a provider omits.** → [wc-address-autocomplete-hosts-only-address1-and-flattens-identity](gotchas/wc-address-autocomplete-hosts-only-address1-and-flattens-identity.md) (s67)
- [woocommerce/address-autocomplete] **Wrapping `window.wc.addressAutocomplete.providers` touches a namespace, not a contract — and two implementation traps along the way.** → [wc-address-autocomplete-registry-wrap-is-not-a-documented-contract](gotchas/wc-address-autocomplete-registry-wrap-is-not-a-documented-contract.md) (s69)

### [shipping/location] — Location provider layer
- [shipping/location] **`within_applied` reports what the scope BUILDER decided, not what the provider honoured — the one field that could detect a dropped scope cannot.** → [within-applied-reports-the-scope-builder-not-the-provider](gotchas/within-applied-reports-the-scope-builder-not-the-provider.md) (s78)
- [shipping/location] **A served level can come from the FALLBACK provider, not the active one — "the active provider lacks X" and "X is unserved" are different statements.** → [a-level-served-can-come-from-the-fallback-not-the-active-provider](gotchas/a-level-served-can-come-from-the-fallback-not-the-active-provider.md) (s76)
- [shipping/location] **One identity, two roles: one must refuse, the other must fall back.** → [one-identity-two-roles-one-must-refuse-the-other-must-fall-back](gotchas/one-identity-two-roles-one-must-refuse-the-other-must-fall-back.md) (s74)
- [shipping/location] **A derived ancestor is not the one the customer picked.** → [a-derived-ancestor-is-not-the-one-the-customer-picked](gotchas/a-derived-ancestor-is-not-the-one-the-customer-picked.md) (s74)
- [shipping/location] **A DOM attribute is the wrong seam on a WooCommerce checkout — the node is not yours.** → [a-dom-attribute-is-the-wrong-seam-on-a-woocommerce-checkout](gotchas/a-dom-attribute-is-the-wrong-seam-on-a-woocommerce-checkout.md) (s72)
- [shipping/location] **A locality's display NAME is not an identifier — the same settlement answers «Москва» or «Moscow» depending on the account's locale.** → [a-locality-display-name-is-not-an-identifier](gotchas/a-locality-display-name-is-not-an-identifier.md) (s71)

### [rig/*] — Local verification rig
- [rig/browser] **The rig's `/checkout/` is the BLOCK checkout — the picker lives on `/classic-checkout/`.** → [rig-checkout-url-is-the-block-checkout](gotchas/rig-checkout-url-is-the-block-checkout.md) (s65)
- [rig/browser] **The rig serves the working tree — a branch switch silently un-fixes things, and a concurrent agent's half-written edit fatals every request.** → [rig-serves-the-working-tree-branch-switch-reverts-fixes](gotchas/rig-serves-the-working-tree-branch-switch-reverts-fixes.md) (s56, s81)
- [rig/browser] **Playwright MCP does not fire WooCommerce's checkout submit; chrome-devtools MCP does.** → [playwright-mcp-does-not-fire-wc-checkout-ajax](gotchas/playwright-mcp-does-not-fire-wc-checkout-ajax.md) (s44)

### [framework/wiring] — Responsibilities that moved
- [framework/wiring] **A module writing into another module's field must ANNOUNCE the write — otherwise the owner reads it as the user's.** → [a-module-that-writes-into-another-modules-field-must-announce-it](gotchas/a-module-that-writes-into-another-modules-field-must-announce-it.md) (s75)
- [framework/wiring] **An action fired beside a filter must carry the filter's RESULT, not its input.** → [an-action-beside-a-filter-must-carry-the-filters-result](gotchas/an-action-beside-a-filter-must-carry-the-filters-result.md) (s65)
- [framework/wiring] **A feature built on both sides, with nothing calling it in the middle.** → [built-on-both-sides-with-no-caller-in-the-middle](gotchas/built-on-both-sides-with-no-caller-in-the-middle.md) (s56, extended s59)

### [testing/*] — Testing patterns
- [testing/*] **Measure a gate only where the gate can actually fire — an AND-ed precondition you did not control answers for you.** → [measure-a-gate-where-the-gate-can-actually-fire](gotchas/measure-a-gate-where-the-gate-can-actually-fire.md) (s78)
- [testing/*] **A `perl -0pi` mutation with `\n` silently misses every CRLF file, and the green run reads as proof.** → [perl-multiline-mutation-silently-misses-crlf-files](gotchas/perl-multiline-mutation-silently-misses-crlf-files.md) (s78)
- [testing/*] **A probe that uses the production accessor creates the state it measures.** → [a-probe-that-uses-the-production-accessor-creates-the-state-it-measures](gotchas/a-probe-that-uses-the-production-accessor-creates-the-state-it-measures.md) (s74)
- [testing/*] **A mutation you did not confirm APPLIED proves nothing — a silently-missed edit reads as "the test survives it".** → [a-mutation-you-did-not-confirm-applied-proves-nothing](gotchas/a-mutation-you-did-not-confirm-applied-proves-nothing.md) (s73)
- [testing/unit] **`Functions\expect( 'f' )->once()->with( X )` does NOT reject a second call with different arguments.** → [brain-monkey-expect-with-does-not-reject-extra-calls](gotchas/brain-monkey-expect-with-does-not-reject-extra-calls.md) (s72)
- [testing/integration] **The integration suite always has a `WC()->session`; a real REST request does not.** → [the-integration-suite-has-a-wc-session-a-rest-request-does-not](gotchas/the-integration-suite-has-a-wc-session-a-rest-request-does-not.md) (s73)
- [testing/integration] **Integration fixtures need the framework mapped at the bootstrap's load path, not just wp-content.** → [wpenv-resolver-fixture-mapping](gotchas/wpenv-resolver-fixture-mapping.md)
- [testing/unit] **Brain Monkey function definitions leak across tests — PHP can't un-define a function.** → [brain-monkey-function-pollution](gotchas/brain-monkey-function-pollution.md)
- [testing/unit] **Reflection `setAccessible()` — required on PHP < 8.1, deprecated on 8.5; guard it.** → [reflection-setaccessible-version-guard](gotchas/reflection-setaccessible-version-guard.md)
- [testing/unit] **A test proving a credential does NOT leak will fail the credential scanner if its placeholder looks real.** → [a-no-leak-test-needs-a-low-entropy-placeholder](gotchas/a-no-leak-test-needs-a-low-entropy-placeholder.md) (s68)
- [testing/integration] **WP REST cookie-nonce auth semantics — what `rest_cookie_check_errors()` actually does.** → [rest-cookie-nonce-auth-semantics](gotchas/rest-cookie-nonce-auth-semantics.md) (s8)
- [testing/unit] **PHPUnit silently runs ONLY the first file argument when given several.** → [phpunit-multiple-file-args](gotchas/phpunit-multiple-file-args.md) (s9)
- [testing/integration] **wp-env on Windows: Git-Bash mangles container paths (MSYS conversion).** → [wpenv-windows-gitbash-path-mangling](gotchas/wpenv-windows-gitbash-path-mangling.md) (s9)
- [testing/integration] **wp-env resolves its environment from the current working directory.** → [wpenv-resolves-environment-from-cwd](gotchas/wpenv-resolves-environment-from-cwd.md) (s60)
- [testing/unit] **Patchwork redefinable internals need an EARLY load in bootstrap — Brain Monkey's lazy load misses suite-build-time source files.** → [patchwork-early-load-bootstrap](gotchas/patchwork-early-load-bootstrap.md) (s9)
- [testing/wc-admin-access-403] **Testing an admin capability gate (403) on the rig: WooCommerce blocks subscribers from wp-admin — use an EDITOR.** → [wc-blocks-subscriber-wp-admin-403-test](gotchas/wc-blocks-subscriber-wp-admin-403-test.md) (s19)
- [testing/integration] **Local e2e rig: `wp_safe_remote_request` blocks the private issuer host + non-standard port.** → [wp-safe-remote-request-local-rig](gotchas/wp-safe-remote-request-local-rig.md) (s11)
- [testing/unit] **Adding a method to a Mockery-mocked class → stub it AND run the FULL unit suite.** → [mockery-mock-new-method-full-suite](gotchas/mockery-mock-new-method-full-suite.md) (s40)
- [testing/integration] **A wrong `dirname(__DIR__, N)` depth aborts the ENTIRE Integration suite, not one file.** → [wrong-dirname-depth-aborts-the-whole-integration-suite](gotchas/wrong-dirname-depth-aborts-the-whole-integration-suite.md) (s44)
- [testing/integration] **A fixture class that implements a framework interface must be declared inside the plugin's init callback.** → [fixture-classes-must-live-inside-plugin-init](gotchas/fixture-classes-must-live-inside-plugin-init.md) (s46)
- [testing/integration] **integration tests — don't fire global admin hooks; `$menu`/`$submenu` accumulate across tests.** → [integration-test-global-admin-hooks-output-and-submenu-accumulation](gotchas/integration-test-global-admin-hooks-output-and-submenu-accumulation.md) (s34)
- [testing/integration] **A stale `.phpunit.result.cache` hides cross-test state leaks CI cannot avoid.** → [phpunit-defects-cache-hides-cross-test-session-leaks](gotchas/phpunit-defects-cache-hides-cross-test-session-leaks.md) (s71)
- [testing/integration] **A fresh guest's shipping country resolves through geolocation (hardcoded `US` fallback), not the store default.** → [wc-customer-default-location-geolocation-fallback](gotchas/wc-customer-default-location-geolocation-fallback.md) (s78)
- [testing/integration] **`WP_UnitTestCase` restores the hook table after every test — an identity-based `reset_for_tests()` cannot remove what comes back.** → [hook-snapshot-restore-defeats-an-identity-based-reset](gotchas/hook-snapshot-restore-defeats-an-identity-based-reset.md) (s70)

- [testing/unit] **A mutation sweep over branch conditions reads as complete and is not.** → [mutation-sweep-branch-only-false-confidence](gotchas/mutation-sweep-branch-only-false-confidence.md) (s45)

- [testing/unit] **An invented fixture tests your assumptions, not the carrier.** → [an-invented-fixture-tests-your-assumptions-not-the-carrier](gotchas/an-invented-fixture-tests-your-assumptions-not-the-carrier.md) (s57)

### [js/*] — JavaScript language traps
- [js/jquery-event-worlds] **A jQuery `.trigger( 'change' )` fires no native event — and that is how select2 reports a pick.** → [jquery-trigger-change-fires-no-native-event](gotchas/jquery-trigger-change-fires-no-native-event.md) (s66)
- [js/object-as-map] **A plain object is not an insertion-ordered map, and not a safe one.** → [plain-object-is-not-an-insertion-ordered-map](gotchas/plain-object-is-not-an-insertion-ordered-map.md) (s59)

### [testing/js] — JavaScript testing pitfalls
- [testing/js] **PowerShell drops `--roots` from the documented jest command.** → [powershell-drops-the-roots-flag-from-the-jest-command](gotchas/powershell-drops-the-roots-flag-from-the-jest-command.md) (s73)
- [testing/js] **`npx jest` is not how this project runs JS tests — it silently loses jsdom.** → [npx-jest-bypasses-wp-scripts-jsdom](gotchas/npx-jest-bypasses-wp-scripts-jsdom.md)
- [testing/js] **A local jest run counts every agent worktree nested inside the repo.** → [jest-scans-agent-worktrees-inside-the-repo](gotchas/jest-scans-agent-worktrees-inside-the-repo.md) (s55)
- [testing/js] **`jest.resetModules()` gives a fresh module, not a fresh `document.body` — zombie listeners keep answering.** → [jest-resetmodules-leaves-listeners-on-the-surviving-body](gotchas/jest-resetmodules-leaves-listeners-on-the-surviving-body.md) (s70)
- [testing/js] **A test that advances the WHOLE interval does not pin the delay — it passes for 0 too.** → [advancing-the-whole-interval-does-not-pin-a-delay](gotchas/advancing-the-whole-interval-does-not-pin-a-delay.md) (s64)
- [testing/js] **`toEqual( [] )` against a "was not called" recorder can pass while the call happened.** → [jest-toequal-empty-array-ignores-undefined](gotchas/jest-toequal-empty-array-ignores-undefined.md) (s52)

### [api/*] — API layer
- [api/http-headers] **A WordPress response header can be an ARRAY, and `Set-Cookie` usually is.** → [wp-http-duplicate-headers-arrive-as-arrays](gotchas/wp-http-duplicate-headers-arrive-as-arrays.md) (s72)
- [api/rest-not-for-browser-auth] **A REST endpoint can't back a browser-facing screen that relies on cookie login.** → [rest-endpoint-not-for-browser-cookie-auth](gotchas/rest-endpoint-not-for-browser-cookie-auth.md) (s24)
- [api/catalog-fetch-timeout] **«Плагины» catalog fetch uses the default 5s timeout — cold cache fails on a slow issuer.** → [extensions-catalog-fetch-5s-timeout](gotchas/extensions-catalog-fetch-5s-timeout.md) (s25; fixed s26)

### [licensing/*] — License/EDD store
- [licensing/two-layer] **`is_need_license()` (presentation) vs `is_license_required()` (enforcement).** → [license-need-vs-required](gotchas/license-need-vs-required.md)
- [licensing/remote-deactivation] **A single-plugin site cannot render its own deactivation banner — accepted by design.** → [single-plugin-site-cannot-render-its-own-deactivation-banner](gotchas/single-plugin-site-cannot-render-its-own-deactivation-banner.md) (s12)
- [licensing/edd-sl-get-version-payload] **EDD SL `get_version` returns `sections`/`banners`/`icons` as PHP-serialized STRINGS.** → [edd-sl-get-version-serialized-sections](gotchas/edd-sl-get-version-serialized-sections.md) (s19)
- [licensing/edd-error-vs-license] **EDD reports activation failures via `error`, not `license` — but only TOKEN errors.** → [edd-error-field-vs-license-status](gotchas/edd-error-field-vs-license-status.md) (s20)
- [licensing/edd-api-no-meta] **woodev.ru `edd-api/v2` products payload is fixed EDD fields — no post meta.** → [edd-api-v2-products-no-post-meta](gotchas/edd-api-v2-products-no-post-meta.md) (s21)
- [licensing/edd-sl-package-vs-purchase-url] **EDD SL `package_download` token is DOMAIN-bound; account-install must use the purchase link.** → [edd-sl-package-download-domain-bound](gotchas/edd-sl-package-download-domain-bound.md) (s26)
- [licensing/option-keys] **License-key option double-prefix for plugin ids starting with `woodev`.** → [license-key-option-double-prefix](gotchas/license-key-option-double-prefix.md) (s11)

### [build/*] — Build/CI/release
- [build/ci] **A failing early CI job silently SKIPS dependent jobs — they never run.** → [ci-failing-gate-skips-dependent-jobs](gotchas/ci-failing-gate-skips-dependent-jobs.md)
- [build/ci] **`composer audit --no-dev` errors when there are no runtime dependencies.** → [composer-audit-no-prod-deps](gotchas/composer-audit-no-prod-deps.md)
- [build/git] **`git add -A` in a fresh worktree sweeps CRLF→LF normalisation of files you never touched into your commit.** → [git-add-all-sweeps-crlf-normalisation-in-a-fresh-worktree](gotchas/git-add-all-sweeps-crlf-normalisation-in-a-fresh-worktree.md) (s71)
- [build/ci] **markdownlint-cli2 ignores `.markdownlintignore` when globs are passed as CLI args.** → [markdownlint-ignorefile-vs-globs](gotchas/markdownlint-ignorefile-vs-globs.md)
- [build/ci] **A credential that is public elsewhere is still not ours to commit here.** → [public-repo-third-party-credentials](gotchas/public-repo-third-party-credentials.md) (s55)
- [build/ci] **An empty `statusCheckRollup` + `CLEAN` can be a GitHub Actions OUTAGE, not your config.** → [empty-status-rollup-can-be-a-github-actions-outage](gotchas/empty-status-rollup-can-be-a-github-actions-outage.md) (s54)
- [build/ci] **A PR that conflicts with base runs no `pull_request` CI — only `pull_request_target`.** → [pr-conflict-skips-pull-request-ci](gotchas/pr-conflict-skips-pull-request-ci.md)
- [build/js] **`@wordpress/scripts` automatic JSX runtime requires WP ≥ 6.6 — use the classic runtime for WP 6.3+ support.** → [wp-scripts-jsx-runtime-wp66](gotchas/wp-scripts-jsx-runtime-wp66.md) (s8)
- [build/assets-eol] **rebuilding the license-page bundle on Windows — pin build artifacts to LF or CI build-parity fails.** → [build-artifacts-eol-lf-windows-parity](gotchas/build-artifacts-eol-lf-windows-parity.md) (s14)
- [build/assets-version] **`woodev-modal.js` is versioned by `self::VERSION`, so editing it never busts the browser cache.** → [modal-script-versioned-by-version-constant-not-filemtime](gotchas/modal-script-versioned-by-version-constant-not-filemtime.md) (s62)
- [build/css-enqueue-version] **enqueue the wp-scripts `style-index.css` with its OWN filemtime, not the JS bundle's asset-hash version.** → [wp-scripts-css-enqueue-version-by-mtime](gotchas/wp-scripts-css-enqueue-version-by-mtime.md) (s31)

### [admin-ui/*] — Admin pages / React UI
- [admin-ui/license-page] **the v2 license page only enqueues the React bundle CSS — server-rendered sections need their styles in style.scss.** → [license-page-css-bundle-only](gotchas/license-page-css-bundle-only.md) (s14)
- [admin-ui/esc-url-raw-for-js] **Use `esc_url_raw` (not `esc_url`) for URLs handed to JS / REST.** → [esc-url-raw-for-js-consumed-urls](gotchas/esc-url-raw-for-js-consumed-urls.md) (s20)
- [admin-ui/wp-nonce-url-esc-html] **`wp_nonce_url()` HTML-encodes `&` → breaks a URL consumed by JS/JSON.** → [wp-nonce-url-esc-html-breaks-js-urls](gotchas/wp-nonce-url-esc-html-breaks-js-urls.md) (s24)

### [admin-ui/modal] — Framework modal shell
- [admin-ui/css] **Flat `:where()` isolation loses to an ordinary longer theme selector — no `!important` required.** → [flat-where-isolation-loses-to-a-longer-theme-selector](gotchas/flat-where-isolation-loses-to-a-longer-theme-selector.md) (s69)
- [admin-ui/css] **`disabled` alone is not a visual signal: a theme's own `input` rule erases the browser's greying, and the field reads as broken rather than blocked.** → [disabled-alone-is-not-a-visual-signal](gotchas/disabled-alone-is-not-a-visual-signal.md) (s76)
- [admin-ui/modal] **a backdrop's `opacity` dims the dialog too when the dialog is its child.** → [modal-backdrop-opacity-dims-the-whole-dialog](gotchas/modal-backdrop-opacity-dims-the-whole-dialog.md) (s48)

### [admin-ui/react-state] — React component state
- [admin-ui/react-state] **React: a stateful section component bleeds state across tabs without a `key`.** → [react-missing-key-state-bleed-across-tabs](gotchas/react-missing-key-state-bleed-across-tabs.md) (s38)

### [box-packer/*] — Box-packer algorithm (S2)
- [box-packer/virtual-box-rsort-axis-alignment] **`rsort()` on the axis-assignment result destroys axis-name alignment for non-normalized items — Option A `[1,10,1]` after rsort → `[10,1,1]` →….** → [virtual-box-rsort-axis-alignment](gotchas/virtual-box-rsort-axis-alignment.md)
- [box-packer/virtual-box-null-best-inf-overflow] **`$best=null;.** → [virtual-box-null-best-inf-overflow](gotchas/virtual-box-null-best-inf-overflow.md)

### [shipping/checkout] — Checkout field layer (§8)
- [shipping/checkout] **The block checkout never sees `woocommerce_checkout_fields`, but it DOES honour the country locale (order, hidden, required).** → [block-checkout-reads-country-locale-not-checkout-fields](gotchas/block-checkout-reads-country-locale-not-checkout-fields.md) (s79)
- [shipping/checkout] **WooCommerce renders a `<label>` for hidden fields — only `checkbox` is excluded.** → [wc-renders-a-label-for-hidden-fields](gotchas/wc-renders-a-label-for-hidden-fields.md) (s72)
- [shipping/checkout] **A dependent-select cascade is DESTRUCTIVE, and WooCommerce fires PROGRAMMATIC `change` events on address fields while initialising the checkout — carr….** → [a-programmatic-parent-change-must-not-run-a-destructive-cascade](gotchas/a-programmatic-parent-change-must-not-run-a-destructive-cascade.md) (s66)

- [shipping/checkout] **Checkout takeover: use `woocommerce_states` for region fields, not client DOM conversion.** → [checkout-field-takeover-woocommerce-states](gotchas/checkout-field-takeover-woocommerce-states.md) (s42)

- [shipping/checkout] **A custom checkout field is empty after a reload BY CONSTRUCTION.** → [custom-checkout-field-is-empty-on-reload-by-construction](gotchas/custom-checkout-field-is-empty-on-reload-by-construction.md) (s65)

- [shipping/checkout] **A second store instance silently diverges — the checkout store needs an instance registry.** → [js-store-instance-registry-cross-module](gotchas/js-store-instance-registry-cross-module.md) (s45)

### [shipping/pickup] — Pickup point picker / ymaps
- [shipping/pickup] **A restore tied to a server confirmation looks like a render artefact — settle "who did this" with a timestamped ledger AND a control, never by watching.** → [a-restore-tied-to-a-server-confirmation-looks-like-a-render-artefact](gotchas/a-restore-tied-to-a-server-confirmation-looks-like-a-render-artefact.md) (s77)
- [shipping/pickup] **Two hook registrations in a reference can mean two OPTIONS, not two outputs.** → [two-hook-registrations-can-mean-two-options-not-two-outputs](gotchas/two-hook-registrations-can-mean-two-options-not-two-outputs.md) (s73)
- [shipping/pickup] **A capability flag that removes a whole UI layer silences every branch that REPORTED through it.** → [a-capability-flag-that-removes-a-ui-layer-silences-every-branch-that-reported-through-it](gotchas/a-capability-flag-that-removes-a-ui-layer-silences-every-branch-that-reported-through-it.md) (s66)
- [shipping/pickup] **ymaps camera moves are asynchronous — losing the `setBounds()` promise breaks two different things.** → [ymaps-camera-moves-are-async](gotchas/ymaps-camera-moves-are-async.md) (s46, extended s47)

- [shipping/pickup] **Draw-then-move parks a ymaps overlay off screen — and `setBounds()` starts its camera action LATE.** → [ymaps-draw-then-move-parks-the-overlay](gotchas/ymaps-draw-then-move-parks-the-overlay.md) (s52)

- [shipping/pickup] **an ObjectManager layout gets PLAIN properties, a Placemark layout gets a data manager.** → [ymaps-objectmanager-properties-are-plain](gotchas/ymaps-objectmanager-properties-are-plain.md) (s49)

- [shipping/pickup] **A theme's `button { display: none !important }` hid every button inside the modal, close included.** → [hostile-theme-button-display-none-needs-important](gotchas/hostile-theme-button-display-none-needs-important.md) (s50, extended s51, s54)

- [shipping/pickup] **Two mobile-only defects, both invisible without an actual narrow viewport.** → [mobile-inline-min-width-and-floating-control-stacking](gotchas/mobile-inline-min-width-and-floating-control-stacking.md) (s50)

- [shipping/pickup] **`setAnchor()` re-sorts the list body but never opens it — a stale card survived an address pick.** → [setanchor-resorts-but-never-shows-the-sidebar](gotchas/setanchor-resorts-but-never-shows-the-sidebar.md) (s50)

- [shipping/pickup] **`ObjectManager.setFilter()`'s callback takes ONE argument, not `(objectId, object)` — selecting any specific type hid every marker.** → [ymaps-objectmanager-setfilter-single-argument](gotchas/ymaps-objectmanager-setfilter-single-argument.md) (s50)

- [shipping/pickup] **`focusGroup()` only recentred the camera for clustered points — a plain marker click did nothing.** → [focusgroup-only-moved-for-clustered-points](gotchas/focusgroup-only-moved-for-clustered-points.md) (s50)

- [shipping/pickup] **A `hidden`-attribute element needs its own `[hidden] { display: none }` — an author `display` rule at equal specificity beats the UA default.** → [css-hidden-attribute-needs-explicit-override](gotchas/css-hidden-attribute-needs-explicit-override.md) (s50)

- [shipping/pickup] **ymaps control options must be nested under `options` — a flat object is silently ignored.** → [ymaps-control-options-must-be-nested](gotchas/ymaps-control-options-must-be-nested.md) (s50)

- [shipping/pickup] **An HTML icon layout has no hit area without `iconShape` — clicks fall through to Yandex's POI layer.** → [ymaps-html-icon-layout-needs-iconshape](gotchas/ymaps-html-icon-layout-needs-iconshape.md) (s50)

- [shipping/pickup] **The ymaps `lang` parameter picks units, not just labels — `en_US` gives miles.** → [ymaps-locale-region-drives-units](gotchas/ymaps-locale-region-drives-units.md) (s47)

- [shipping/pickup] **`map.margin.addArea()` needs an EXPLICIT `width` — `right` is an offset, not a size.** → [ymaps-margin-area-needs-explicit-width](gotchas/ymaps-margin-area-needs-explicit-width.md)

- [shipping/pickup] **A custom HTML icon layout draws with its top-left corner AT the anchor — `iconShape` is centred, the artwork isn't.** → [ymaps-html-icon-layout-anchors-at-its-top-left](gotchas/ymaps-html-icon-layout-anchors-at-its-top-left.md) (s51)

- [shipping/pickup] **Address lookup needs `ymaps.suggest()`, not `geocode()` — and `value`, not `displayName`.** → [ymaps-suggest-not-geocode-for-address-lists](gotchas/ymaps-suggest-not-geocode-for-address-lists.md) (s51)

- [shipping/pickup] **Bounding the address geocode by the pickup-point area breaks the normal case — self-inflicted, s51.** → [bounding-the-address-resolve-breaks-the-normal-case](gotchas/bounding-the-address-resolve-breaks-the-normal-case.md) (s51)

- [shipping/pickup] **ymaps' copyright strip ignores `margin.addArea()` and sits in a stacking context the sidebar's z-index can't reach.** → [ymaps-copyright-pane-is-trapped-in-a-stacking-context](gotchas/ymaps-copyright-pane-is-trapped-in-a-stacking-context.md) (s51)

- [shipping/pickup] **The card renders from a snapshot the writers never touch.** → [card-renders-from-a-snapshot-the-writers-never-touch](gotchas/card-renders-from-a-snapshot-the-writers-never-touch.md) (s57)

- [shipping/pickup] **A per-cycle memo is not in-flight de-duplication.** → [a-per-cycle-memo-is-not-in-flight-deduplication](gotchas/a-per-cycle-memo-is-not-in-flight-deduplication.md) (s57)

- [shipping/pickup] **A field that never varies cannot be a verdict.** → [a-constant-field-cannot-be-a-verdict](gotchas/a-constant-field-cannot-be-a-verdict.md) (s58)

- [shipping/pickup] **A control that changes WHAT a surface is about must emit the same event every other route to that state emits.** → [a-control-that-changes-the-subject-must-announce-it](gotchas/a-control-that-changes-the-subject-must-announce-it.md) (s58)

- [shipping/pickup] **A per-viewport cache is unbounded by construction.** → [per-viewport-cache-is-unbounded-by-construction](gotchas/per-viewport-cache-is-unbounded-by-construction.md) (s58)

### [shipping/*] — Shipping module (S1)
- [shipping/contracts] **Session key ≠ order-meta prefix — two distinct installed-site contracts.** → [session-key-vs-order-meta-prefix](gotchas/session-key-vs-order-meta-prefix.md)
- [shipping/contracts] **Installed-site contract strings are NOT mechanically derivable — the plugin must supply them.** → [contract-string-not-derivable](gotchas/contract-string-not-derivable.md)
- [shipping/rate-calc] **Do NOT sum per-parcel prices in the framework rate seam.** → [shipping-rate-no-parcel-sum](gotchas/shipping-rate-no-parcel-sum.md) (s3)
- [shipping/warehouse-identity] **Warehouse identity: storage row id ≠ carrier-unique id.** → [warehouse-storage-id-vs-carrier-id](gotchas/warehouse-storage-id-vs-carrier-id.md)

### [i18n/*] — Localization
- [i18n/russian-source-plural-n] **`_n()` with Russian source strings renders wrong plural forms without a translation catalog.** → [russian-source-i18n-plural-n](gotchas/russian-source-i18n-plural-n.md) (s7)

### [autodev/*] — Adversarial dev loop tooling
- [autodev/serena-worktree] **Serena MCP index is bound to the main working tree — agents editing in a git worktree must NOT navigate via Serena.** → [serena-index-vs-git-worktree](gotchas/serena-index-vs-git-worktree.md) (s7)
- [autodev/circuit-breaker] **Refund the circuit-breaker attempt on EVERY external pause, not just the worker's.** → [autodev-attempt-refund-symmetry](gotchas/autodev-attempt-refund-symmetry.md)
- [autodev/critic] **Autodev critic over-flags two non-breaks on every incremental task.** → [autodev-critic-overflag](gotchas/autodev-critic-overflag.md)
- [autodev/critic] **invoke-critic mis-reads benign repo text as a rate-limit (the loop's own docs poison its 429 detector).** → [autodev-critic-ratelimit-false-positive](gotchas/autodev-critic-ratelimit-false-positive.md)
- [autodev/gate-fence] **autodev-loop gate/fence design pitfalls (per-value guards, fingerprint fence).** → [autodev-loop-gate-fence-pitfalls](gotchas/autodev-loop-gate-fence-pitfalls.md) (s33)

### [tooling/*] — Dev tooling, codex critic
- [tooling/codex-shell-sandbox-broken-windows] **codex exec shell-sandbox broken on this Windows box — run critics with an inline bundle.** → [codex-shell-sandbox-broken-windows](gotchas/codex-shell-sandbox-broken-windows.md) (s10, extended s61, root-caused s72)
- [tooling/windows] **Git Bash mangles Cyrillic in curl arguments — the API answers 400/500 and it reads as the API's fault.** → [git-bash-mangles-cyrillic-in-curl-arguments](gotchas/git-bash-mangles-cyrillic-in-curl-arguments.md) (s76)
- [tooling/serena-eol-flip] **Serena `replace_content`/`replace_symbol_body` rewrites the whole file as CRLF on Windows.** → [serena-replace-content-eol-flip](gotchas/serena-replace-content-eol-flip.md) (s25)
- [tooling/phpstan-windows-segfault] **PHPStan crashes with exit `-1073741819` on Windows — environmental, not a code error.** → [phpstan-windows-parallel-worker-segfault](gotchas/phpstan-windows-parallel-worker-segfault.md) (s28)
- [tooling/phpcs] **`composer phpcs` does not enforce the 120-char limit, and never sees `tests/`.** → [phpcs-does-not-enforce-line-length](gotchas/phpcs-does-not-enforce-line-length.md) (s45; fix tracked as #139)
- [tooling/git-checkout] **`git checkout <file>` reverts a deliberate-regression mutation by deleting the uncommitted implementation with it.** → [git-checkout-destroys-uncommitted-mutation-revert](gotchas/git-checkout-destroys-uncommitted-mutation-revert.md) (s52)
- [tooling/git-merge] **GitHub squash-merge onto a stale origin/main leaves local main "diverged but content-complete".** → [git-squash-onto-stale-origin-main-diverge](gotchas/git-squash-onto-stale-origin-main-diverge.md) (s33)
- [tooling/git-merge] **Stacked PRs: GitHub CLOSES (never retargets) a downstream PR when its base branch is deleted; `ci.yml` never runs on a PR whose base isn't `main`.** → [stacked-pr-github-mechanics](gotchas/stacked-pr-github-mechanics.md) (s80)

## Archive (resolved gotchas)
<!-- Resolved gotchas move here; keep for 2 sessions then remove -->

- [bootstrap/resolver-bootstrap-coupling] **RESOLVED.** `Framework_Resolver` no longer references
  `Woodev_Plugin_Bootstrap::instance()` at all — the notice renderers are injected
  (`$update_notice_renderer`, `$deactivation_notice_renderer`). Verified against the source at the
  s75 docs cleanup; original finding: `audit-2026-06-01.md` §H2.