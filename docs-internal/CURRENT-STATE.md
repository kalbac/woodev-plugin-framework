# Current State — Woodev Plugin Framework

> Lean state doc: phase status, open bugs, next actions. **Full session history → `SESSION-LOG.md`** (newest on top). Program-level status → `platform-v2-program-tracker.md`.
> Last updated: 2026-06-21 (session 28 — Competitor Notification module v2 rework SHIPPED (PR #79 `f96e9ce`); Codex-reviewed + re-critic'd; 760 unit; new gotcha phpstan-windows-segfault).

## Last session context (≤3 lines)

- **s28 (DONE — SHIPPED, PR #79 `f96e9ce`):** Competitor Notification module rebuilt as a reusable v2 framework module (PSR-4 `Woodev\Framework\Competitor\`, `@since 2.0.2`, version NOT bumped). Platform-neutral `Competitor_Notification_Handler` engine + declarative `Competitor_Rule` VO + pluggable renderers (`WC_Admin_Notes_Renderer` selected by `class_exists(Note::class)` — the gotcha-correct gate; `Admin_Notice_Renderer` fallback). Opt-in via `Woodev_Plugin::get_competitor_notification_handler()` (null default) on `current_screen`. Smart recommend link (connected+owned → extensions page via cached `woodev_account_purchases` transient; else public URL). Codex review (3 HIGH/3 MED/1 LOW) all fixed + re-critic'd (all sound). **760 unit** (+31), phpcs clean. PHPStan passed on **Linux CI** (local Windows segfault was environmental → new gotcha `phpstan-windows-parallel-worker-segfault`). Spec/plan: `docs-internal/specs|plans/2026-06-21-competitor-notification*`. Out of scope: yandex subclass migration (at plugin rewrite), Setup Wizard (OB-10).
- **s27 (DONE — SHIPPED, PR #78 `1aa4ec4`):** Framework runtime autoloader (`Woodev_Framework_Autoloader`, hand-written spl_autoload, **no Composer in shipped plugins**) + generated `woodev/class-map.php` + `bin/generate-class-map.php`. **Plugin type now declared solely by `extends`** — `capabilities` array fully removed (loader-definition, resolver `load_early_capability_classes` deleted, bootstrap gate, 7 fixtures). New `Woodev_Loader` facade. Resolves PLANS §5 (bootstrap fate + type declaration) as **variant C** (progressive path to godaddy versioned-namespaces, deferred — spec §8). Godaddy fork studied (OB-5 DONE — findings spec §9). **729 unit**, phpstan L3 + phpcs clean. Codex + Claude independent review (no CRITICAL/HIGH blocker); 3 findings fixed + re-critic. Autonomous overnight. Spec/plan: `docs-internal/specs|plans/2026-06-21-plugin-type-autoloader*`.
- **✅ B-2 loader-protocol forward-tolerance — HANDLED (discussed + resolved with operator s27):** the resolver loads framework **classes from the highest registered copy for the whole fleet**, regardless of which copy wins the bootstrap rendezvous — so a newer plugin never breaks against an older rendezvous winner; the older plugin (vs newer framework) is the at-risk one, and the **existing `backwards_compatible` min-version guard** (`resolver:148-153`) deactivates-with-notice any plugin below the loaded copy's min — same as v1. Two standing rules (now written in `AGENT-RULES.md` Rule 3): every loader definition MUST set `version` + `backwards_compatible`; the registration contract is additive-only from v2.0.0. The removed `capabilities` protocol was never released → cannot break any deployed plugin. See SESSION-LOG s27 + gotcha `framework-classmap-autoload-vendored-boot`.
- **s27 part-2 (design only, NO code):** **Competitor Notification module** brainstormed with operator → **spec ready** `docs-internal/specs/2026-06-21-competitor-notification-design.md` (5 decisions: declarative rules with `mode` recommend|conflict; neutral engine + WC-Notes/admin-notice renderers; smart recommend link-target via account ecosystem #8 with degradation; framework default i18n templates + per-plugin mapping; respect-dismissal + auto-delete). v1 raw ref: `woocommerce-yandex-delivery/woodev/handlers/competitor-notification.php`. **Implementation = s28** (start with `writing-plans`; see next-session-prompt). Setup Wizard parked as **OB-10** (separate brainstorm later).
- **s26 part-2 (DONE — SHIPPED, PR #77 `086585c`):** #8 install purchased plugin from connector. Rig e2e PASSED + operator browser-verified; Codex no CRITICAL/HIGH. **Connector deployed to prod woodev.ru.** Version NOT bumped (still 2.0.1 unreleased, `@since 2.0.2`).
- **s26 part-2 (build detail):** #8 install purchased plugin from connector. Framework: `POST woodev/v1/account/install` (cap `install_plugins`+nonce) → `Woodev_Account_Installer` (SSRF host-pin guard + `Plugin_Upgrader`, **no activation**) + React «Установить» button (idle/installing/done/error) in card + «Мои покупки». Connector (woodev_theme `d375d6d`): `GET /download/{id}` (HMAC + ownership) → **`edd_get_download_file_url`** purchase link (order-bound, not domain-bound — gotcha `edd-sl-package-download-domain-bound`); signed `woodev_install` marker bypasses per-file limit on that path only; account-scoped rate-limit. **725 unit** + 52 connector unit, phpcs/phpstan/build-parity green. Codex: no CRITICAL/HIGH; MEDIUM (atomic rate-limit) + LOW (https pin) **FIXED + re-critic'd**. Polish in PR: full-card install **spinner overlay** (`ab8fe38`) + **filterable request timeout** (install `/download` 30s, `284bdd0`). **Rig e2e PASSED** — server-side install smoke + operator browser-verified (install from catalog & «Мои покупки», inactive, error on file-less product).
- **PENDING (operator):** deploy connector to **prod woodev.ru**; merge PR #77 (`--squash --delete-branch`, not `--auto`); resync main. Rig left wired for re-tests (issuer mu-plugin `zz-rig-host-rewrite.php`, 3 stub products 36/23/26, re-seeded consumer). Issuer :8090 brought back via `npx wp-env start` (Bash + `MSYS_NO_PATHCONV=1`; volumes survived).
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

`composer check` green at s28: **760 unit tests** / 2151 assertions (66 skipped), 41 integration (baseline). Keep green after each change. Note: PHPStan crashes locally on Windows (`-1073741819`, environmental — gotcha `phpstan-windows-parallel-worker-segfault`); Linux CI "Run PHPStan" is the authoritative gate.

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
- 📥 **Remaining backlog** (`FUTURE-BACKLOG.md` → "Operator backlog dump — s13"): OB-4 reusable-JS-php-based principle · ~~OB-5 godaddy fork study~~ **DONE s27** (findings in spec `2026-06-21-plugin-type-autoloader-design.md` §9; borrow candidates: `Block_Integration_Trait` High, `Enum_Trait`+pseudo-enums Med, `CanConvertToArrayTrait` Med) · OB-7 modernize Plugins page · OB-9 shipping nuances.
- **🚧 BIG NEXT — Shipping module (operator-led, needs his participation):** PLANS §3.2 "ideal & maximally universal". Skeleton rich but **never validated by a real plugin**; concrete gaps (s27 audit): `admin/views/html-admin-shipping-method-status.php` is a 30% STUB; no setup-wizard; **no label/export abstraction**; JS/CSS assets unverified; webhook handler not yandex-validated. Plan: conformance audit vs 3 reference plugins → close gaps → pilot-migrate yandex (ПВЗ reference). Good autodev-loop candidate.
- **Big ones (operator-scheduled, not solo):** payment-gateway trait extraction (autodev-loop); the big review #4 — `array()`→`[]` (~797) + type declarations everywhere + `@since` sweep + enforce `Generic.Arrays.DisallowLongArraySyntax`. ~~B-2 loader-protocol forward-tolerance~~ **HANDLED s27** (highest-version classes always load regardless of rendezvous winner; `backwards_compatible` guard deactivates-with-notice too-old plugins; rules in AGENT-RULES Rule 3 — see "Last session context"). box-packer (s27 audit): minimal-virtual-box already replaced by a grid heuristic (PR #21, works for PLANS examples, not provably optimal); non-WC wrapper still deferred — closer to done than thought.

## 🔔 Cross-Project Reminder — Ecosystem Orchestration (dormant)

- **Trigger:** v2.0.0 shipped AND stable in production for several weeks. When it fires, surface it in the session-opening summary; do **NOT** auto-start; point the operator to the spec and read its "Prompt for the Future Agent" section first.
- **Spec:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`. Cross-ref: `FUTURE-BACKLOG.md` → "Cross-Project Initiatives" #7.

## Local rig

- **s28 — consumer stand DISMANTLED (operator request).** The s11/s12 PULL consumer stand on `:8888` was removed: deleted `.wp-env-stand/` (the `woodev-stand` plugin), the `woodev-stand` mapping in `.wp-env.override.json`, `.rig-stubs/` (s26 install stubs), and the `.gitignore` entry. The project wp-env (`.wp-env.json` fixtures) + `composer test:integration` are untouched and were never dependent on the stand. To drop the stand plugin from a running instance: `wp-env start --update`. Rig knowledge retained in gotcha `wp-safe-remote-request-local-rig` (historical).
- **Issuer `:8090` — KEPT, do NOT touch.** It is effectively a copy of prod (woodev_theme = local woodev.ru + EDD SL + deactivator, with test data); operator uses it independently. Container `c8ec47a5...-wordpress-1`. Authority pubkey `QSisoK0CDOmIOqGHvilMe+4mB/LMRFHf9hi6BxatfMk=`. The rig-only `zz-rig-host-rewrite.php` mu-plugin was **removed in s28** (from the container's `wp-content/mu-plugins/` and the `woodev_theme/` source), so :8090 is now a clean prod copy. NB: `docker exec ... rm /var/www/...` needs `MSYS_NO_PATHCONV=1` on Git-Bash or the path is mangled and the rm silently no-ops (gotcha `wpenv-windows-gitbash-path-mangling`).
- Drive via `docker exec <cli> wp eval-file ...` (cyrillic/quoting breaks inline `wp eval` — always eval-file). Do NOT run `do_action('admin_init')` in wp-cli (WC OrderAttributionController fatals). All rig traps: gotcha `wp-safe-remote-request-local-rig`.

## Infrastructure Reference

- **Version:** `Woodev_Plugin::VERSION` (in `woodev/class-plugin.php`) = 2.0.1 (unreleased).
- **PHP target:** 8.1 · **WP min:** 6.3 · **WC min:** 7.0
- **Tests:** Brain Monkey (unit) + WP Test Library (integration). `composer check` = phpcs + phpstan L3 + unit.
- **CI:** GitHub Actions. **Merge PRs:** `gh pr merge <N> --squash --delete-branch` only after confirmed-green CI; never `gh pr merge --auto`.
