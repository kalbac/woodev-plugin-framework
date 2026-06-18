# Current State — Woodev Plugin Framework

> Lean state doc: phase status, open bugs, next actions. **Full session history → `SESSION-LOG.md`** (newest on top). Program-level status → `platform-v2-program-tracker.md`.
> Last updated: 2026-06-19 (session 21 — license Item 0 fix + OB-7 «Плагины» React redesign + brand polish, PRs #68/#69/#70 merged + rig-verified).

## Last session context (≤3 lines)

- **s21 (DONE):** (1) **License Item 0** (BLOCKING) fixed — bad key no longer strands the user; `get_display_status()` overrides with `error` only when it's a machine token (free-text store errors kept polluting status → `unknown` group), + JS `unknown` fallback `changeKey:true` (PR #68). (2) **OB-7 Phase A** — «Woodev → Плагины» rebuilt as a WP-React catalog over a new `woodev/v1/extensions` REST proxy (normalizer, transient cache), RU-localized, account scaffold behind a feature flag; legacy view removed, slug+cap preserved (PR #69). (3) **OB-7 polish** — wide, grid 4/2/1, compact branded cards (cyan `#00C9FD`), `thumbnails.small`; normalizer forward-compatible for `_product_icon`/`_coming_soon` (PR #70).
- **Verified:** `composer check` green (**665 unit / 1954 assertions**, phpcs 0, phpstan 0); Codex inline critics on both (one finding — partial-payload caching — fixed with tests); rig browser-verified (license group E; plugins catalog renders from live store, filter/search, 4-col wide, 0 console errors); CI + Assets-build-parity green each PR. New gotcha `edd-api-v2-products-no-post-meta`; updated `edd-error-field-vs-license-status`.
- **Next (s22):** woodev.ru-side work in **`D:\Projects\woodev_theme\plugins\`** — (1) extend `woodev-core` edd-api to expose `_product_icon` + `_coming_soon` + **rating** (key names TBC); (2) build **`woodev-account-connector`** (OB-7 Phase B, WC-Helper-style OAuth). v2.0.1 still **NOT released** → `@since 2.0.2`.

## Program status (high level)

| Stage | Status | Notes |
|---|---|---|
| S0 Platform Split | ✅ DONE | tag `platform-v2-split-done`; base platform-neutral, resolver minimal, clean-break Phase 3 shims deleted |
| S1 Shipping | ✅ DONE | PR #20; PSR-4 module; rate/packing seam + conformance audit (s2–s4) |
| S2 Box-packer | ✅ DONE | PR #21/#22; woven into rate-calc single-seam template |
| S3 Licensing | ✅ DONE | need-license (PR #25) → React UI (PR #31) → webhooks + Ed25519 signing (PR #35) |
| Remote-deactivation UX | ✅ DONE | s10–s12; command cycle proven live (push prod + pull rig); B-13/14/15 resolved |
| S4 EDD / S5 React admin / S6 ecosystem | ⚪ deferred | post-v2.0 |

`composer check` green at s21: **665 unit tests** / 1954 assertions (65 skipped), 41 integration (baseline). Keep green after each change.

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

- ✅ **[RESOLVED s21] License page Item 0** — bad/non-existent key no longer strands the user (PR #68). See SESSION-LOG s21.
- [⚠️] `class-payment-gateway.php` ~3,542 lines — trait-extraction candidate (grooming, s13; → `FUTURE-BACKLOG`).
- [ℹ️ OB-7 follow-up] «Плагины» still shows discontinued/coming-soon items (Беру.ру/GOODS) — `edd-api/v2` exposes no `_coming_soon`/`_product_icon`/rating; needs a woodev.ru-side API extension (s22 task #1). Framework normalizer already consumes them forward-compatibly.
- **All earlier release-blocker findings RESOLVED** (2026-06-01 audit, PHPStan masks, base-class leaks, eCheck/ACH removal, payment-gateway base-method regression, etc.) — see `SESSION-LOG.md` + git history. Not repeated here.

### Public-docs API staleness — DEFERRED (operator decision, s13)

- `docs/` (GH Pages) registration examples still teach the **v1 `register_plugin( '1.4.0', ... )` positional API**, which in v2 is a **tombstone** (quarantines the caller, never registers). The live API is `register_loader_definition([...])`. Examples also hardcode `'1.4.0'`/`VERSION='1.4.1'` instead of the `%%FRAMEWORK_VERSION%%` placeholder / `2.0.1`. Affected: `getting-started.md`, `core-framework.md`, `payment-gateway.md`, `shipping-method.md`, `README.md`.
- **Operator decision (s13): do NOT touch public docs yet** — he is currently the only consumer of the framework; the public docs get rewritten once everything is fully ready. Recorded so it is not mistaken for an oversight.

## Next Actions

- ✅ **s21 DONE — license Item 0 + OB-7 «Плагины» React redesign + polish (PRs #68/#69/#70):** see "Last session context" + `SESSION-LOG.md` s21. 665 unit, rig-verified, CI green. Spec/plan: `docs-internal/specs|plans/2026-06-18-plugins-page-ob7-redesign*`.
- 🎯 **s22 (operator-set) — woodev.ru-side work in `D:\Projects\woodev_theme\plugins\`:**
  1. **`woodev-core` edd-api extension (task #1):** expose `_product_icon` + `_coming_soon` + product **rating** in the `edd-api/v2` products payload (via `edd_api_products_product`-style filter). **Confirm the exact meta key names first** (operator unsure of names). Then the framework «Плагины» page auto-uses them (icon, hides coming-soon, can show rating). See gotcha `edd-api-v2-products-no-post-meta` + spec §8a.
  2. **`woodev-account-connector` (OB-7 Phase B):** new plugin in `woodev_theme\plugins\` — WC-Helper-style OAuth provider (`/oauth/{request_token,authorize,access_token,me,invalidate_token}` + `/purchases`) so the framework account panel can connect. Spec §7. Build via **autodev-loop** or `codex:*` (operator's choice next session).
  - Prompt: `docs-internal/next-session-prompt.md`.
- ✅ **OB-3 COMPLETE** (s15 F11/F12/F13, s16 F2/F7+F5, s17 move, s18 F8/F9/F10, s19 F1/F3); only **F6** backoff deferred (endpoint-wide-key question).
- 📥 **Remaining backlog** (`FUTURE-BACKLOG.md` → "Operator backlog dump — s13"): OB-4 reusable-JS-php-based principle · OB-5 godaddy fork study (GPT research delegation) · OB-7 modernize Plugins page (WP React + woodev.ru account) · OB-9 shipping nuances. Big ones: payment-gateway trait extraction; review #4 (`array()`→`[]` + typing + `@since` sweep).
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
