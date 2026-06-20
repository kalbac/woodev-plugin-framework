# Current State — Woodev Plugin Framework

> Lean state doc: phase status, open bugs, next actions. **Full session history → `SESSION-LOG.md`** (newest on top). Program-level status → `platform-v2-program-tracker.md`.
> Last updated: 2026-06-20 (session 26 part-2 — #8 install-from-connector implemented, PR #77 OPEN (CI running); Codex-reviewed no CRITICAL/HIGH; 3 findings pending operator triage; rig e2e + merge pending).

## Last session context (≤3 lines)

- **s26 part-2 (PR #77 OPEN — do NOT auto-merge):** #8 install purchased plugin from connector. Framework: `POST woodev/v1/account/install` (cap `install_plugins`+nonce) → `Woodev_Account_Installer` (SSRF host-pin guard + `Plugin_Upgrader`, **no activation**) + React «Установить» button (idle/installing/done/error) in card + «Мои покупки». Connector (woodev_theme `d375d6d`): `GET /download/{id}` (HMAC + ownership) → **`edd_get_download_file_url`** purchase link (order-bound, not domain-bound — gotcha `edd-sl-package-download-domain-bound`); signed `woodev_install` marker bypasses per-file limit on that path only; account-scoped rate-limit. **724 unit** + 52 connector unit, phpcs/phpstan/build-parity green. **Codex: no CRITICAL/HIGH.** Findings **triaged + FIXED + re-critic'd** (operator chose fix MEDIUM+LOW): MEDIUM atomic rate-limit via `wp_cache_add`+`wp_cache_incr` (connector `72904dd`); LOW https transport pin (`b888ba0`); INFO left as-is.
- **PENDING (with operator):** **two-stack rig e2e** — issuer :8090 containers were removed end of prev session (volumes `c8ec47a5_*` INTACT; needs `npx wp-env start` from woodev_theme — my non-interactive harness can't run it, operator restarts); then EDD SL download w/ files + seeded order + connection; operator **deploys connector to prod woodev.ru**; then merge PR #77 (`--squash --delete-branch`, not `--auto`).
- **s25 (DONE — SHIPPED, PR #75 `bbc09bb`):** #7 «Мои покупки» tab + «Куплено» badge. `Woodev_Account_Purchases` + `GET woodev/v1/account/purchases` signed proxy + React tab + cyan «Куплено» badge. Codex-reviewed, rig-verified.
- **Bonus fix (s25):** failed/denied connect bounced to the extensions page silently — added `Woodev_Account_Connection::render_connect_notice()` on `admin_notices` + clearer denial message.
- **Flagged, NOT fixed (operator decision pending):** catalog proxy uses default 5s `wp_safe_remote_get` timeout; issuer products endpoint ~8.6s → cold-cache catalog fails (gotcha `extensions-catalog-fetch-5s-timeout`). One-line fix available. v2.0.1 still unreleased → `@since 2.0.2`.

## Program status (high level)

| Stage | Status | Notes |
|---|---|---|
| S0 Platform Split | ✅ DONE | tag `platform-v2-split-done`; base platform-neutral, resolver minimal, clean-break Phase 3 shims deleted |
| S1 Shipping | ✅ DONE | PR #20; PSR-4 module; rate/packing seam + conformance audit (s2–s4) |
| S2 Box-packer | ✅ DONE | PR #21/#22; woven into rate-calc single-seam template |
| S3 Licensing | ✅ DONE | need-license (PR #25) → React UI (PR #31) → webhooks + Ed25519 signing (PR #35) |
| Remote-deactivation UX | ✅ DONE | s10–s12; command cycle proven live (push prod + pull rig); B-13/14/15 resolved |
| S4 EDD / S5 React admin / S6 ecosystem | ⚪ deferred | post-v2.0 |

`composer check` green at s25: **706 unit tests** / 2045 assertions (65 skipped), 41 integration (baseline). Keep green after each change.

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
- ✅ **s22 DONE — OB-7 store-side (cross-project, woodev_theme) + plugins-page rating (PR #71):**
  1. **`woodev-core` edd-api (DONE, woodev_theme `8a32fda`):** `enrich_product()` (hooks `edd_api_products_product_v2`) now sends `info._product_icon` (theme `_product_icon` attachment-ID → URL) + `info._coming_soon` (bool incl. legacy `edd_coming_soon`). **rating was already present** (top-level, 0–100). Real keys found in `woodev-theme/inc/metaboxes.php`.
  2. **`woodev-account-connector` (DONE, woodev_theme, new plugin):** WC-Helper-style OAuth provider per spec §7. 6 endpoints + authorize screen + connections table + HMAC + EDD purchases. 31 tests, rig-verified, Codex-hardened (timestamp-freshness / atomic consume / same-origin). Driven by me + Codex critic (operator's choice). Deferred Low: rate-limit `/oauth/request_token` (woodev_theme FUTURE-BACKLOG).
  3. **Framework follow-up (DONE, PR #71):** `normalize_product()` surfaces a 0–5 `rating` + React card stars. **Forward-compat for live handshake stays gated** (`woodev_extensions_account_enabled` default false) until the framework **account client** (`Woodev_Account_Connection`, spec §7) is built — that is the open Phase-B item.
- ✅ **s23 DONE — catalog polish (OB-8, PR #72) + account-connection client SPEC.** See "Last session context" + SESSION-LOG s23. Spec: `docs-internal/specs/2026-06-19-account-connection-client-design.md`.
- ✅ **s24 DONE — account-connection client implemented + SHIPPED (PRs #73/#74):** `Woodev_Account_Connection` + connect/return handlers + REST disconnect + `AccountMenu` #6/#9 + `Установлен` badge #5 + OAuth `state` binding; connector authorize → front-end `parse_request` screen (WC_Auth-style) + branded `/login` + richer approval; flag `woodev_extensions_account_enabled` flipped default true after prod connector deploy. Rig-verified, Codex-reviewed. See SESSION-LOG s24.
- ✅ **s25 DONE — #7 «Мои покупки» + «Куплено» badge (PR #75 `bbc09bb`):** `Woodev_Account_Purchases` + `GET woodev/v1/account/purchases` signed proxy + React tab (cross-ref link by id) + cyan «Куплено» badge («Установлен» wins) + connect-notice fix. 706 unit, Codex-reviewed, rig-verified. See "Last session context" + SESSION-LOG s25.
- ✅ **s26 part 1 DONE — catalog fetch timeout fix (PR #76 `9d67f67`):** `Woodev_REST_API_Extensions::FETCH_TIMEOUT = 20` passed to both store fetches (default 5s was too short for the ~250KB enriched products → cold-cache `stale`). 707 unit, CI green. Gotcha `extensions-catalog-fetch-5s-timeout` marked fixed.
- 🎯 **s26 part 2 (PR #77 OPEN, do NOT auto-merge) — #8 install-from-connector DONE pending triage+e2e:** `Plugin_Upgrader` + connector `GET /download/{id}` using the order-bound `edd_get_download_file_url` purchase link (NOT the domain-bound SL `package_download` token — gotcha `edd-sl-package-download-domain-bound`). Codex: no CRITICAL/HIGH. **Morning:** triage 3 findings (MEDIUM atomic throttle, LOW https-pin, INFO) → apply + re-critic; rig e2e; operator deploys connector to prod; merge.
- ℹ️ **Rating-in-API (woodev_theme, deferred):** public edd-api omits `rating` despite reviews existing (`query_reviews()`/global-`$post` gap; repro inconclusive). Operator-skipped — revisit on the woodev_theme side if/when "Мои покупки" lands.
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
