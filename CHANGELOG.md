# Changelog

All notable changes to Woodev Plugin Framework are documented here.
## [2.0.0] — 2026-06-12
### Bug Fixes
- Use tests-cli container name for wp-env run commands

- Set WP_ENV_HOME at job level so all steps find the environment

- Resolve 2 failing integration tests

- Remove duplicate custom_dir from mkdocs.yml root level

- **dependencies**: Use function_exists instead of extension_loaded in get_missing_php_functions()

- **s3**: PHPStan baseline cleanup — 410 errors → 0, fix 4 code bugs

- **licensing**: Remove wc date helpers from license messages

- **utilities**: Remove wc enqueue helper from job batch handler

- **admin**: Remove wc doing it wrong helper from setup wizard

- **helper**: Guard early doing it wrong fallback

- **helper**: Add phase 5 platform-neutral fallbacks

- **licensing**: Preserve WooCommerce helper contracts

- **payment-gateway**: Restore base methods removed by v2 cleanup

- **payment-gateway**: Use instanceof guards for hosted payment notification dispatch

- **box-packer**: Split Woodev_Box_Packer_Item into base + With_Product contract

- **shipping**: Narrow Shipping_API interface to base framework contracts

- **plugin**: Move get_woocommerce_uploads_path to Woodev_Woocommerce_Plugin with deprecation shim

- **plugin**: Make Woodev_Plugin::\ nullable for pure-WP subclasses

- **payment-gateway**: Add explicit nullable types to 13 legacy method parameters

- **framework-resolver**: H2/H3/H4 — inject notice callbacks, idempotent load, plugin_id dedup

- **bootstrap**: M-2 — delegate is_woocommerce_active() to Woodev_Helper

- **framework**: M-4 — move add_class_form_wrap_* to Woodev_Woocommerce_Plugin

- **plugin**: Make B-2 shim reference the FQCN \\Woodev\\Framework\\Woocommerce_Plugin

- **resolver**: Apply P3 clean-break audit findings (GPT-5.5), verified

- **p4**: Declare HPOS/Blocks compat early via loader metadata (GPT-5.5 audit), verified

- **p6**: Close split-done audit findings — base REST neutrality, plugin-file contract, HPOS (GPT-5.5)

- **autodev**: Robust native-command runner + anti-drift; record bootstrap digest

- **autodev**: Process-driven watchdog, persist worker output, literal locator match

- **autodev**: Scheduler honors depends_on (dependency DAG, not just file-sets)

- **autodev**: Critic robustness + gate-as-lock (worker no longer self-commits)

- **autodev**: Scope gateway_id zone to payment-gateway path; drop generic $this->id= grep that false-tripped shipping value objects

- **s1**: Pickup_Selection is session-only — session key and order-meta prefix are distinct contracts

- **autodev**: Scope gate zone-detection to task file_set + refund attempt on rate-limit

- **s1**: Order-handler spec — plugin supplies full meta-key map, framework hardcodes no suffix

- **autodev**: Refund attempt on critic rate-limit (exit 4) so codex 429s never false-poison a DONE task

- **autodev**: Gate RETRY returns task to pending, not active

- **autodev**: Invoke-critic must not misread benign repo text as a 429

- **autodev**: Expose Pickup_Point id wire alias for shipped pickup-map.js (resolves ajax-base)

- **autodev**: Shipping_API::get_response() is nullable (resolves abstract-api)

- **autodev**: Complete Shipping_Plugin wiring + session->order pickup handoff (resolves R1)

- **autodev**: Loop hardening per the 2026-06-11 tooling review (#30)


### CI/CD
- Phase 1 upgrades — PHP 8.3, security audit, Dependabot

- Add integration tests workflow with wp-env and WooCommerce

- Add GitHub Pages deployment with MkDocs Material theme

- Add WP × WC × PHP version matrix to integration tests

- Auto-merge Dependabot PRs when all checks pass

- Fix security-audit and scope markdown lint to published docs


### Documentation
- Add CI status badges to README

- Overhaul GitHub Pages with custom hero and dark theme

- Auto-inject framework version into docs at build time

- Add Woodev branding, ecosystem links, and auto-release version

- Add plugins showcase section to home page

- Convert plugins grid to infinite auto-scrolling carousel

- Replace carousel with 3-column plugin grid

- Replace WB placeholder with real plugin data from woodev.ru

- Add Licensing & Updates card to feature grid

- Fix code examples, add <?php tags, fix markdown lint

- Verify and fix all code examples against framework source

- **s1**: Establish docs-internal/ structure, create AGENTS.md, refactor CLAUDE.md

- **s2**: Populate gotchas with 10 atomic files from codebase analysis

- **s2**: Update session state after legacy cleanup

- **s3**: ECheck/ACH audit — 14 files, 5-phase removal plan

- **s3**: Add gotcha for missing gateway type methods, update session log for JS/CSS cleanup

- Restructure PLANS.md refactor brainstorm into clear sections

- **platform**: Align v2 strategy planning

- **platform**: Analyze v2 resolver strategy

- **platform**: Add v2 implementation spec

- **platform**: Add next session prompt

- **platform**: Keep next prompt in chat

- **platform**: Record feature compatibility session

- Record Phase 5 admin notice cleanup commit hash

- **platform-v2**: Add reference migration contract draft

- **platform-v2**: Add second reference migration draft

- **platform-v2**: Reconcile roadmap with framework-first strategy

- **audit-2026-06-01**: Record second-model independent audit findings

- **audit-fixes**: Record resolution of B-2, B-3, H1 + add new tests

- **framework**: L-1, L-5, L-6 — sync docblock + comment cleanups

- **audit-fixes**: Record 12/13 deferred audit resolutions + abandoned L-2 5th test

- **platform-v2**: Record direction audit + namespace/clean-break course correction

- **plan**: Platform v2 clean-break plan, base-decomposition sub-plan, execution protocol + program tracker

- **policy**: Reconcile CLAUDE.md/AGENTS.md to clean-break (D-2); add ADR-005

- **review**: P2 pilot external-audit packet; tracker -> P2 ext-audit pending

- **tracker**: P2 gate passed (ext audit applied) -> P3 next

- **tracker**: P3.1 fixtures converted; 3.2-3.5 deletions next

- **review**: P3 clean-break external-audit packet; tracker -> P3 ext-audit pending

- **tracker**: P3 gate closed (audit fixes verified); P4 started — Translation_Handler done

- **autodev**: Add autonomous adversarial dev loop runbook + impl brief

- **p4**: Cron_Handler extracted; record decision to NOT extract action-links/api-logging (polymorphic)

- **state**: Record P6 gate evidence for Woodev_Plugin base size after handler extraction

- **wiki**: Update extension-point article index line after seam removal

- **review**: P4 decomposition external-audit packet; tracker -> P4 ext-audit pending

- **autodev**: Incorporate external review (Planner role, repo-aware critic, mutation recipes, anti-drift depth)

- **tracker**: P4 gate passed (HPOS-timing fix verified); P5 next, orphan cleanup tracked

- **p5**: Verify resolver minimality + responsibility table (ADR-003); no extraction needed

- **review**: P6 split-done external-audit packet; all verification checks green

- **tracker**: P6 fixes applied+verified (195/592); awaiting tag decision

- **tracker**: S0 platform split COMPLETE — tagged platform-v2-split-done; S1 next (autodev loop)

- **cleanbreak-plan**: Mark plan SUPERSEDED + Phase 3 DONE; point to tracker

- **s1**: Planning brief for shipping module spec + autodev decomposition

- **s1**: Resolve 3 pending yandex contracts (EDD 821, warehouse DDL, cron payload)

- **s1**: Correct yandex id-derived contract values to live (operator maksim)

- **autodev**: Gotcha for critic-429 false-poison + record Q3 resolution

- **autodev**: Session log — 2 escalations resolved + critic-429 false-poison fix (2026-06-06)

- **autodev**: Session-save — refresh digest + CURRENT-STATE (escalations closed, Q3 fixed)

- **autodev**: S1 complete — close 6 escalations + unblocked chain; record session

- **autodev**: SESSION-LOG entry for the 2026-06-07 S1-completion session

- **autodev**: Holistic integration review + R1/R2 remediation record

- **autodev**: Session save — PR #20 CI fixed (green), 6 CI/test gotchas

- **session-2**: Dispatcher wiring + warehouse redesign session save + next-session prompt

- Formalize Capability-Gated Feature Seam pattern (wiki + ADR-006)

- Next-session prompt — shipping conformance audit vs capability-gated seam

- **s4**: Next-session prompt (S3 Licensing fork) + reconcile stale program-tracker

- **s5**: Mark S3.1 is_need_license MERGED (#25) + next-session prompt (S3 sub-stage 2/3 fork)

- **s5**: Triage Fable 5 architecture review + autodev model re-tiering

- **s7**: Fold Fable findings B-3/B-4/B-6 into the S3.1 licensing spec (#28)

- **s7**: Session save — CURRENT-STATE/SESSION-LOG/tracker + 2 gotchas (#29)

- **s8**: Session save — S3.2 merged (PR #31) + 2 gotchas (#32)

- **s8**: Next-session prompt — s9 = S3.3 webhooks + section-4 signing (#33)

- **s9**: Tracker + state — PR #35 merged, S3 complete (#36)


### Features
- **platform**: Add resolver facade loader

- **platform**: Namespace resolver slice

- **platform**: Move WooCommerce feature state

- **platform**: Move WooCommerce system status ownership

- **platform**: Move WooCommerce logger ownership

- **platform**: Move WooCommerce template ownership

- **platform**: Move WooCommerce feature compatibility ownership

- **autodev**: Scaffold adversarial dev-loop infrastructure (conductor suite + blackboard)

- **s1**: Commit planner artifacts — shipping spec, queue manifest, 29-task autodev queue

- **shipping**: Add Address_Normalizer interface + Null default

- **autodev**: S1 shipping module complete — clean-break + shipping module via adversarial loop

- **s2**: Dispatcher production wiring + shipping packing seam + warehouses REST redesign (#22)

- **s3**: Weave box-packing into shipping rate-calc (single-seam template) (#23)

- **s3**: Licensing sub-stage 1 — is_need_license safe-scaffold (#25)

- **bootstrap**: B-1 mixed v1/v2 fleet hard-gate (probe + register_plugin tombstone) (#27)

- **s3**: S3.2 modern license-page UI — React card grid + woodev/v1 REST, legacy Settings form removed (#31)

- **s3**: S3.3 built-in webhooks + §4 Ed25519 signing (remote deactivate, signed claims, pull-fallback acks) (#35)

- **s3**: Embed production License_Authority public key (#37)

- **licensing**: Send framework_version on every licensing API request (#38)

- Bump framework version to 2.0.0 (#39) ⚠ BREAKING


### Refactoring
- **v2.0.0**: Remove dead compat code, deprecated methods, and US-specific payment types ⚠ BREAKING

- **v2.0.0**: Remove eCheck from JS, CSS, and dist assets

- **platform**: Remove wc deprecation wrappers from base modules

- **platform**: Decouple settings API helpers from WooCommerce

- **platform-v2**: Remove licensing woocommerce helpers

- **platform-v2**: Decouple lifecycle event sanitization

- **platform-v2**: Decouple beta opt-in helper

- **platform-v2**: Decouple dependency size parser

- **platform-v2**: Decouple admin notice dismiss JavaScript

- **platform-v2**: Decouple settings API error paths

- **helper**: Split Woodev_Woocommerce_Helper off Woodev_Helper (M-1, L-4)

- **licensing**: Split Woodev_Woocommerce_License_Settings off Woodev_License_Settings

- Delete legacy positional registration path ⚠ BREAKING

- Delete internal-API class aliases and deprecated shims ⚠ BREAKING

- **base**: Extract Translation_Handler from Woodev_Plugin

- **base**: Extract Cron_Handler

- **base**: Remove the add_woocommerce_hooks WooCommerce seam from the platform-neutral base

- **p4**: Remove orphaned handle_features_compatibility; migrate WP-neutrality coverage to bootstrap

- **autodev**: Pickup-map.js core (MapAdapter contract) + Leaflet adapter + css

- **autodev**: Map_Provider interface + registry + Leaflet default (PHP descriptor)

- **autodev**: Pickup_Point + Warehouse value objects (pure, immutable)

- **autodev**: Checkout_Fields — custom checkout field definitions (pure)

- **autodev**: Pickup_Point_Source interface + Pickup_Point_Filter (sourcing axis)

- **autodev**: Pickup_Selection — session-only chosen-point WC session persistence

- **autodev**: Checkout_Handler — field injection, posted-data, validation orchestration

- **autodev**: Warehouse_Store interface + abstract table-backed default

- **autodev**: Wire PVZ selection into Shipping_Method_Pickup (extend existing marker)

- **autodev**: Shipping_Order_Handler — HPOS-safe order-meta read/write (plugin-supplied key map)

- **autodev**: Abstract_Tracking_Handler — tracking model + display hooks

- **autodev**: Abstract_Webhook_Handler — inbound receiver scaffolding (spec §4.3)

- **autodev**: Shipping_Method rate-cache pre-filter + get_pickup_point_source() seam

- **autodev**: Abstract_Shipment_Handler — create/cancel/export with retry

- **autodev**: Shipping_Admin — admin suite bootstrap

- **autodev**: Shipping_REST_API — REST extension bootstrap

- **autodev**: Abstract_Pickup_Points_Controller — pickup-search REST controller base

- **autodev**: Wire get_pickup_point_source() override into Shipping_Method_Pickup

- **autodev**: Shipping_Admin_Order — render tracking in metabox, not before redirect (resolves admin-order)

- **autodev**: Status view reads is_configured() from the integration (resolves status-view)

- **autodev**: Warehouse_Admin builds submenu URLs under admin.php (resolves warehouse-admin)

- **autodev**: Pickup checkout handler + modal/balloon + checkout.js (resolves p2-pickup-checkout)

- **autodev**: Register new shipping subsystems in Shipping_Plugin (integration)

- **autodev**: Extract shared pilot-fixture test scaffold (resolver + WP stubs)

- **autodev**: Pickup map JS must honor the AJAX success flag (no false selection)

- **box-packer**: S2 — WC-neutral core + minimal virtual-box algorithm + tests (#21)

- **shipping**: Supports_*() predicate wrappers + host-facing supports() docs (pattern conformance) (#24)

- **licensing**: Cheap-check-first condition + ?? over isset (#34)


### Tests
- Add comprehensive integration tests for WP/WC environment

- Fix integration tests based on real WP/WC environment findings

- **platform**: Cover specialized callback timing

- **platform-v2**: Add realistic payment fixture for runtime validation

- **framework-resolver**: L-2 coverage + docs(wiki): L-3 extension-point pattern

- **pilot**: Edostavka-shaped fixture validates the new load path + data-preservation checklist

- **pilot**: Harden P2 fixture/test per external audit (GPT-5.5)

- **fixtures**: Convert woodev-test-* fixtures to explicit loader definitions

- **autodev**: Write mutation-verified edostavka contract guards (shipping method id + settings option key)

- **autodev**: Mutation-verified yandex contract guards (4 of 5; REST blocked)

- **autodev**: Yandex-shaped pilot fixture + validation gate (resolves fixture-yandex, Task B)

- Bundle framework into integration fixtures for the v2 resolver

- Map framework into integration fixtures via wp-env (fix resolver load)

- Make Unit suite CI-robust (gitignored reference + function pollution)

- SetAccessible for reflection on PHP 7.4/8.0 (version-guarded)


### Style
- **framework**: M-3 + M-5 — docblock annotations + indentation fixes



## [1.4.1] — 2026-03-12
### Bug Fixes
- Enforce WordPress coding standards across framework codebase

- Resolve static analysis and code quality issues

- Resolve all PHPCS errors blocking CI

- Suppress PHPCS warning exit code in CI

- Increase PHPStan memory limit to 1G for WooCommerce stubs

- Disable PHPStan parallel workers to prevent OOM crashes

- Use 2G memory limit for PHPStan with WooCommerce stubs

- Correct PHPStan type error in token editor filter docblock

- Add setAccessible(true) for PHP 7.4/8.0 Reflection compatibility

- Add missing setAccessible(true) in test_singleton_reset_via_reflection


### CI/CD
- Fix failing GitHub Actions workflows


### Documentation
- Expand CLAUDE.md, revise AI agents/skills, and update tooling config


### Features
- **payment-tokens**: Auto-invalidate token cache on WooCommerce token events

- **shipping**: Implement admin notice handlers for countries, debug mode, and plugin configuration


### Refactoring
- Move Woodev_Plugin_Compatibility to compatibility/ directory


### Tests
- Restructure fixtures and improve integration test bootstrap




