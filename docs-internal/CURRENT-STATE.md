# Current State — Woodev Plugin Framework

> Lean state doc: phase status, open bugs, next actions. **Full session history → `SESSION-LOG.md`** (newest on top). Program-level status → `platform-v2-program-tracker.md`.
> Last updated: 2026-06-17 (session 18 — OB-3 Step 4 contract-touching F8/F9/F10, PR #62 merged).

## Last session context (≤3 lines)

- **s18:** OB-3 Step 4 done — F8 (`in_plugin_update_message-{$file}` 2nd arg → response object + repaired the dead consumer `plugin_row_license_missing`), F9 (changelog endpoint unslash/sanitize + strict `plugin === $this->name`, no nonce), F10 (cache value source-stamp, frozen key untouched). **No data contract broken.** PR #62 merged (`bcfd271`), CI green, Codex critic SHIP after one HOLD fix (F9 rawurldecode). 645 unit tests.
- **Open:** v2.0.1 in code, **NOT released** (operator rule: don't bump `VERSION` per change). New symbols → `@since 2.0.2`.
- **Carried:** OB-3 F1+F3 BLOCKED (normalization + cache-**key** isolation, need store payload from rig) + OB-5/7/9 + the two big ones. **Not browser-verified:** F8 multisite update-row notice + F9 changelog endpoint (unit-only).

## Program status (high level)

| Stage | Status | Notes |
|---|---|---|
| S0 Platform Split | ✅ DONE | tag `platform-v2-split-done`; base platform-neutral, resolver minimal, clean-break Phase 3 shims deleted |
| S1 Shipping | ✅ DONE | PR #20; PSR-4 module; rate/packing seam + conformance audit (s2–s4) |
| S2 Box-packer | ✅ DONE | PR #21/#22; woven into rate-calc single-seam template |
| S3 Licensing | ✅ DONE | need-license (PR #25) → React UI (PR #31) → webhooks + Ed25519 signing (PR #35) |
| Remote-deactivation UX | ✅ DONE | s10–s12; command cycle proven live (push prod + pull rig); B-13/14/15 resolved |
| S4 EDD / S5 React admin / S6 ecosystem | ⚪ deferred | post-v2.0 |

`composer check` green at s18: **645 unit tests** / 1888 assertions (65 skipped), 41 integration (baseline). Keep green after each change.

## Phase Status (subsystems)

| Phase | Code | Browser-verified | Notes |
|-------|------|------------------|-------|
| Framework Core | ✅ | ✅ | Bootstrap, Plugin base, Lifecycle — stable |
| Payment Gateway | ✅ | ✅ | `class-payment-gateway.php`: **~3,542 lines** (whole tree ~13.8k); trait-extraction candidate |
| Shipping Method | ✅ | ✅ | PSR-4 namespaced |
| Licensing | ✅ | ✅ | EDD store integration; React license page on core `woodev/v1` REST |
| Settings API | ✅ | ✅ | Typed settings framework |
| Box Packer | ✅ | ✅ | Shipping box-packing algorithm |
| REST API | ✅ | ✅ | Plugin REST routes |
| PHPStan | ✅ | — | Level 3, **no baseline** (`phpstan-baseline.neon` removed; do not reintroduce) |
| Documentation | ✅ | — | Two-tier: `docs/` (GH Pages) + `docs-internal/` (AI agents) |

## P6 gate evidence — base is platform-neutral & not a god-object (reference)

- **Platform neutrality:** base `Woodev_Plugin` declares **zero** WooCommerce/HPOS-named methods; the last HPOS seam (`is_hpos_compatible()`) was removed. Late-safe WC hooks live in `Woodev\Framework\Woocommerce_Plugin::register_woocommerce_hooks()`; early `before_woocommerce_init` feature declarations are wired by the bootstrap from loader `supported_features` metadata. Enforced by `PlatformNeutralBaseHasNoWcMethodTest`, `PlatformNeutralRestApiTest`, `BootstrapRegistrationTest`.
- **Base size (2026-06-04):** `woodev/class-plugin.php` ~1,274 lines / 74 methods (56 public) after the P6 split-done follow-up.
- **Construction shape:** `__construct()` is an ordered list of `init_*_handler()`/`load_*` calls ending with `add_hooks()`; `add_hooks()` wires only base-owned hooks.

## Known Bugs / Open debt

- [⚠️] `class-payment-gateway.php` ~3,542 lines — trait-extraction candidate (grooming, s13; → `FUTURE-BACKLOG`).
- **All earlier release-blocker findings RESOLVED** (2026-06-01 audit, PHPStan masks, base-class leaks, eCheck/ACH removal, payment-gateway base-method regression, etc.) — see `SESSION-LOG.md` + git history. Not repeated here.

### Public-docs API staleness — DEFERRED (operator decision, s13)

- `docs/` (GH Pages) registration examples still teach the **v1 `register_plugin( '1.4.0', ... )` positional API**, which in v2 is a **tombstone** (quarantines the caller, never registers). The live API is `register_loader_definition([...])`. Examples also hardcode `'1.4.0'`/`VERSION='1.4.1'` instead of the `%%FRAMEWORK_VERSION%%` placeholder / `2.0.1`. Affected: `getting-started.md`, `core-framework.md`, `payment-gateway.md`, `shipping-method.md`, `README.md`.
- **Operator decision (s13): do NOT touch public docs yet** — he is currently the only consumer of the framework; the public docs get rewritten once everything is fully ready. Recorded so it is not mistaken for an oversight.

## Next Actions

- ✅ **s18 done:** OB-3 Step 4 — F8/F9/F10 contract-touching, operator sign-off per fix (PR #62 merged `bcfd271`). No data contract broken; +12 tests (645 unit); Codex SHIP. Two new gotchas (`in-plugin-update-message-arg-shape`, `updater-cache-source-stamp-not-key`).
- ✅ **s17 done:** OB-3 Step 5 — MOVE `woodev/plugin-updater/` → `woodev/licensing/updater/` (PR #61 merged `829420d`). Pure byte-identical rename; 6 frozen contracts preserved; Codex SHIP.
- ⛔ **OB-3 Step 3 remaining — F1+F3 BLOCKED:** `sections` normalization + cache **key** isolation need store payload shape verified on the rig. Cannot proceed autonomously without the rig or API access.
- 🌐 **Browser-verify on rig (carried):** F8 multisite custom update-row + the "backup before updating" / license-missing notice now actually rendering; F9 changelog endpoint (`?woodev_action=view_plugin_changelog`). Unit-verified only in s18.
- 📥 **Remaining backlog** (`FUTURE-BACKLOG.md` → "Operator backlog dump — s13"): OB-4 reusable-JS-php-based principle · OB-5 godaddy fork study (GPT research delegation) · OB-7 modernize Plugins page (WP React + woodev.ru account) · OB-9 shipping nuances (dedicated session).
- **Big ones (operator-scheduled, not solo):** payment-gateway trait extraction (autodev-loop); the big review #4 — `array()`→`[]` (~797) + type declarations everywhere + `@since` sweep + enforce `Generic.Arrays.DisallowLongArraySyntax`. B-2 loader-protocol forward-tolerance before S4/EDD.

## 🔔 Cross-Project Reminder — Ecosystem Orchestration (dormant)

- **Trigger:** v2.0.0 shipped AND stable in production for several weeks. When it fires, surface it in the session-opening summary; do **NOT** auto-start; point the operator to the spec and read its "Prompt for the Future Agent" section first.
- **Spec:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`. Cross-ref: `FUTURE-BACKLOG.md` → "Cross-Project Initiatives" #7.

## Local rig (s11/s12 — reusable)

- **Issuer** (woodev_theme = local woodev.ru + EDD SL + deactivator): `wp-env` in `d:\projects\woodev_theme`, `http://localhost:8090`. Authority pubkey `QSisoK0CDOmIOqGHvilMe+4mB/LMRFHf9hi6BxatfMk=`. Local SSRF-bypass in `Push_Delivery::is_safe_target()` (env==='local'). Command queue: `wp_woodev_pd_commands`.
- **Stand/consumer** (framework wp-env): `http://localhost:8888`, WP 6.9 / WC 10.8.1. Gitignored `.wp-env-stand/`. Channel = PULL. Login `admin`/`password`.
- Drive via `docker exec <cli> wp eval-file ...` (cyrillic/quoting breaks inline `wp eval` — always eval-file). Do NOT run `do_action('admin_init')` in wp-cli (WC OrderAttributionController fatals). All rig traps: gotcha `wp-safe-remote-request-local-rig`.

## Infrastructure Reference

- **Version:** `Woodev_Plugin::VERSION` (in `woodev/class-plugin.php`) = 2.0.1 (unreleased).
- **PHP target:** 8.1 · **WP min:** 6.3 · **WC min:** 7.0
- **Tests:** Brain Monkey (unit) + WP Test Library (integration). `composer check` = phpcs + phpstan L3 + unit.
- **CI:** GitHub Actions. **Merge PRs:** `gh pr merge <N> --squash --delete-branch` only after confirmed-green CI; never `gh pr merge --auto`.
