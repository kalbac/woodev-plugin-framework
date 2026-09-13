CANARY: cedar-fix

# Gotcha corpus fixes — s135

## Summary

Applied the audit decisions on `docs/s135-gotcha-fixes`: merged three duplicate pairs, archived the resolved PHP 8.4 trap, corrected verified stale claims, converted legacy wikilinks across the gotcha corpus, and added the requested platform and host-measurement qualifiers.

## Files changed

- `docs-internal/GOTCHAS.md` — removed duplicate active-index rows and archived the resolved implicit-nullable entry.
- `docs-internal/gotchas/a-cached-token-makes-an-invalid-credential-test-pass.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-capability-flag-that-removes-a-ui-layer-silences-every-branch-that-reported-through-it.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-constant-field-cannot-be-a-verdict.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-control-that-changes-the-subject-must-announce-it.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-derived-ancestor-is-not-the-one-the-customer-picked.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-dom-attribute-is-the-wrong-seam-on-a-woocommerce-checkout.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-fixtures-no-op-becomes-a-fatal-the-day-the-fixture-goes-live.md` — replaced the drifted shipping-plugin line citation with its method name.
- `docs-internal/gotchas/a-fresh-worktree-is-born-dirty-on-four-js-files.md` — deleted after merging its duplicate content into the retained CRLF gotcha.
- `docs-internal/gotchas/a-git-hook-committed-non-executable-is-silently-ignored-on-posix.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/a-locality-display-name-is-not-an-identifier.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-module-that-writes-into-another-modules-field-must-announce-it.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-mutation-you-did-not-confirm-applied-proves-nothing.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-no-leak-test-needs-a-low-entropy-placeholder.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-per-cycle-memo-is-not-in-flight-deduplication.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-probe-that-uses-the-production-accessor-creates-the-state-it-measures.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-programmatic-parent-change-must-not-run-a-destructive-cascade.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-pull-request-workflow-can-simply-not-fire.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-registered-setting-without-a-control-never-renders.md` — removed the dangling Related target and converted legacy links.
- `docs-internal/gotchas/a-select-value-write-with-no-matching-option-submits-nothing.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/a-stale-primary-checkout-degrades-every-worktree-made-from-it.md` — replaced a Windows absolute path with a path-free primary-checkout command.
- `docs-internal/gotchas/a-worker-can-fan-out-to-background-forks-past-the-concurrency-cap.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/an-action-beside-a-filter-must-carry-the-filters-result.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/an-empty-domain-key-is-not-a-key.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/an-invented-fixture-tests-your-assumptions-not-the-carrier.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/an-oom-killed-check-wait-reads-as-an-empty-timeout.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/an-orca-worktree-starts-dirty-with-crlf-churn.md` — merged the CRLF duplicate, added the verified 14-blob/eol=lf measurement, and cross-linked the staging consequence.
- `docs-internal/gotchas/array-cast-of-get-states-false-is-not-empty.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/autodev-critic-overflag.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/autodev-critic-ratelimit-false-positive.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/autodev-loop-gate-fence-pitfalls.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/bounding-the-address-resolve-breaks-the-normal-case.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/box-packer-interface-unwired-in-includes.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/brain-monkey-expect-with-does-not-reject-extra-calls.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/brain-monkey-function-pollution.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/build-artifacts-eol-lf-windows-parity.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/built-on-both-sides-with-no-caller-in-the-middle.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/card-renders-from-a-snapshot-the-writers-never-touch.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/checkout-field-takeover-woocommerce-states.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/ci-failing-gate-skips-dependent-jobs.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/classify-an-i18n-string-by-its-render-path-not-its-file-path.md` — re-derived the current pickup accent-colour example.
- `docs-internal/gotchas/classmap-autoload-breaks-class-exists-once-guard.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/codex-in-wsl-needs-a-relative-gitdir.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/codex-shell-sandbox-broken-windows.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/composer-audit-no-prod-deps.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/contract-string-not-derivable.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/css-hidden-attribute-needs-explicit-override.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/custom-checkout-field-is-empty-on-reload-by-construction.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/dispatcher-files-unwired-in-includes.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/edd-api-v2-products-no-post-meta.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/edd-error-field-vs-license-status.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/edd-sl-get-version-serialized-sections.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/edd-sl-package-download-domain-bound.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/empty-status-rollup-can-be-a-github-actions-outage.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/esc-url-raw-for-js-consumed-urls.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/extensions-catalog-fetch-5s-timeout.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/file-deletion-tail-includes-classmap-fixtures.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/fixture-classes-must-live-inside-plugin-init.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/flat-where-isolation-loses-to-a-longer-theme-selector.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/focusgroup-only-moved-for-clustered-points.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/format-validator-null-strlen-deprecation.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/framework-classmap-autoload-vendored-boot.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/git-add-all-sweeps-crlf-normalisation-in-a-fresh-worktree.md` — cross-linked the retained CRLF worktree gotcha and converted legacy links.
- `docs-internal/gotchas/git-bash-mangles-cyrillic-in-curl-arguments.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/git-checkout-destroys-uncommitted-mutation-revert.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/git-credential-manager-hangs-silently-in-an-agent-session.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/git-push-hangs-silently-under-credential-manager.md` — removed the dangling Related target and added its platform qualifier.
- `docs-internal/gotchas/git-squash-onto-stale-origin-main-diverge.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/git-worktree-remove-empties-the-primary-checkout-s-node-modules.md` — merged the duplicate and qualified the symlink mechanism as non-macOS.
- `docs-internal/gotchas/git-worktree-remove-empties-the-primary-checkouts-node-modules.md` — deleted after merging its duplicate content into the retained node_modules gotcha.
- `docs-internal/gotchas/grep-through-wp-env-run-loses-the-include-glob-and-the-wc-directory-is-not-called-woocommerce.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/guest-session-write-needs-the-cart-cookie.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/handler-extraction-must-preserve-override-chain.md` — replaced drifted class-plugin line citations with stable method names.
- `docs-internal/gotchas/hook-snapshot-restore-defeats-an-identity-based-reset.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/hostile-theme-button-display-none-needs-important.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/in-plugin-update-message-arg-shape.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/integration-jobs-die-on-a-github-api-504-not-on-your-code.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/integration-test-global-admin-hooks-output-and-submenu-accumulation.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/jest-resetmodules-leaves-listeners-on-the-surviving-body.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/jest-scans-agent-worktrees-inside-the-repo.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/jest-toequal-empty-array-ignores-undefined.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/jquery-trigger-change-fires-no-native-event.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/js-store-instance-registry-cross-module.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/license-key-option-double-prefix.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/license-need-vs-required.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/license-page-css-bundle-only.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/local-npm-run-build-is-not-assets-parity-evidence.md` — updated bundle count to six and qualified non-macOS symlink behavior.
- `docs-internal/gotchas/markdownlint-ignorefile-vs-globs.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/mask-constant-backed-field-even-when-constant-undefined.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/mockery-mock-new-method-full-suite.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/modal-backdrop-opacity-dims-the-whole-dialog.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/mutation-sweep-branch-only-false-confidence.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/npm-run-test-js-is-not-the-whole-js-gate.md` — updated the JS job from five to seven commands.
- `docs-internal/gotchas/npx-jest-bypasses-wp-scripts-jsdom.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/one-identity-two-roles-one-must-refuse-the-other-must-fall-back.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/orca-account-list-serves-a-cached-rate-limit.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/orca-terminal-command-bash-lands-in-wsl-on-windows.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/orca-worktree-create-base-branch-takes-the-local-ref.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/patchwork-early-load-bootstrap.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/per-viewport-cache-is-unbounded-by-construction.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/perl-multiline-mutation-silently-misses-crlf-files.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/php-stdlib-traps-that-survive-tests.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/php84-implicit-nullable-payment-handlers.md` — marked resolved under ef3d067 while retaining the historical detail file.
- `docs-internal/gotchas/phpcs-does-not-enforce-line-length.md` — rewrote stale warning-tier claims as history and retained the live LineLength measurement.
- `docs-internal/gotchas/phpstan-windows-parallel-worker-segfault.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/phpunit-defects-cache-hides-cross-test-session-leaks.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/phpunit-multiple-file-args.md` — deleted after merging its duplicate content into the retained PHPUnit gotcha.
- `docs-internal/gotchas/playwright-mcp-does-not-fire-wc-checkout-ajax.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/powershell-drops-the-roots-flag-from-the-jest-command.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/pr-conflict-skips-pull-request-ci.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/public-repo-third-party-credentials.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/react-missing-key-state-bleed-across-tabs.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/reflection-setaccessible-version-guard.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/rest-endpoint-not-for-browser-cookie-auth.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/rig-checkout-url-is-the-block-checkout.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/rig-serves-the-working-tree-branch-switch-reverts-fixes.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/run-a-migrating-plugin-s-phpstan-in-the-container-not-on-windows.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/section-empty-setting-ids-renders-all-fields.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/serena-index-vs-git-worktree.md` — rewrote the obsolete Serena avoidance advice as historical failure and stated the own-worktree rule.
- `docs-internal/gotchas/serena-replace-content-eol-flip.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/serenas-mcp-dies-when-uvx-picks-a-python-with-no-pyyaml-wheel.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/session-key-vs-order-meta-prefix.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/setanchor-resorts-but-never-shows-the-sidebar.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/settings-sensitive-secret-empty-skip-is-client-side.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/shipping-rate-no-parcel-sum.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/single-plugin-site-cannot-render-its-own-deactivation-banner.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/stacked-pr-github-mechanics.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/starting-kilo-under-orca-repeats-every-codex-launch-trap.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/the-bash-tools-quoted-heredoc-still-eats-backslashes.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/the-classic-adapter-reverts-a-select-the-location-cascade-owns.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/the-integration-suite-has-a-wc-session-a-rest-request-does-not.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/the-local-php-is-four-versions-above-the-ci-floor.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/the-skipped-count-is-dominated-by-whether-sodium-is-enabled.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/the-three-location-field-modes-and-their-russian-labels.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/three-agents-is-the-concurrency-cap-on-this-machine.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/two-agents-one-file-is-the-orchestrator-s-bug.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/two-hook-registrations-can-mean-two-options-not-two-outputs.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/updater-cache-source-stamp-not-key.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/virtual-box-null-best-inf-overflow.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/virtual-box-rsort-axis-alignment.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/warehouse-storage-id-vs-carrier-id.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/wc-address-autocomplete-hosts-only-address1-and-flattens-identity.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/wc-address-autocomplete-registry-wrap-is-not-a-documented-contract.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/wc-blocks-subscriber-wp-admin-403-test.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/wc-does-not-save-the-address-until-every-required-text-field-is-filled.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/wc-renders-a-label-for-hidden-fields.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/wc-uppercases-the-posted-state-and-flips-the-map.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/woodev-setting-get-value-is-cached-not-a-live-option-read.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/wp-http-duplicate-headers-arrive-as-arrays.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/wp-nonce-url-esc-html-breaks-js-urls.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/wp-safe-remote-request-local-rig.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/wp-scripts-jsx-runtime-wp66.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/wpenv-resolver-fixture-mapping.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/wpenv-resolves-environment-from-cwd.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/wpenv-windows-gitbash-path-mangling.md` — added the requested platform or host-measurement qualifier and converted legacy links where present.
- `docs-internal/gotchas/wrong-dirname-depth-aborts-the-whole-integration-suite.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/ymaps-camera-moves-are-async.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/ymaps-control-options-must-be-nested.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/ymaps-copyright-pane-is-trapped-in-a-stacking-context.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/ymaps-draw-then-move-parks-the-overlay.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/ymaps-html-icon-layout-anchors-at-its-top-left.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/ymaps-html-icon-layout-needs-iconshape.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/ymaps-locale-region-drives-units.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/ymaps-margin-area-needs-explicit-width.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/ymaps-objectmanager-properties-are-plain.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/ymaps-objectmanager-setfilter-single-argument.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/gotchas/ymaps-suggest-not-geocode-for-address-lists.md` — converted legacy [[...]] references to resolving Markdown links.
- `docs-internal/sessions/s125.md` — repointed the deleted CRLF duplicate to the retained gotcha.
- `docs-internal/sessions/s9.md` — repointed the deleted PHPUnit duplicate to the retained gotcha.
- `.orca/audit/report-fix.md` — durable delivery report for this worker task.

## Decisions declined

- Did not change `docs-internal/gotchas/README.md`: the brief explicitly reserves it for the coordinator. Its two legacy `[[...]]` entries therefore remain outside this worker's permitted file set.
- Did not update the `GOTCHAS.md` header count: the brief permits index lines only and explicitly forbids header changes. Deleting the three requested duplicate files makes its existing `321 atomic gotchas` count stale.
- Kept the resolved `php84-implicit-nullable-payment-handlers.md` detail file exactly as instructed, but the current linter deliberately excludes the Archive section from its active index and therefore reports that retained file as unindexed. This is a linter/brief contract conflict, not a missing archive entry.

## Validation

```text
git diff --check
exit 0

Deleted-slug search outside docs-internal/archive
PASS: no live references to a-fresh-worktree-is-born-dirty-on-four-js-files
PASS: no live references to git-worktree-remove-empties-the-primary-checkouts-node-modules
PASS: no live references to phpunit-multiple-file-args

grep/rg '\\[\\[' docs-internal/gotchas
Only docs-internal/gotchas/README.md:31-32 remain; README is coordinator-owned by the brief.

node scripts/lint-docs.mjs
exit 1
gotchas: 318 files, 317 index entries
- php84-implicit-nullable-payment-handlers.md is not listed in GOTCHAS.md
- GOTCHAS.md header says 321 atomic gotchas, but 318 files exist
```

## Commit

`a2564af` (will be amended once this report records the final hash).

## Inbound references I could not change

None. All live inbound links to deleted slugs within the allowed file set were repointed; archive references were deliberately excluded by the brief.
