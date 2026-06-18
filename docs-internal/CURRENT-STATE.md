# Current State — Woodev Plugin Framework

> Lean state doc: phase status, open bugs, next actions. **Full session history → `SESSION-LOG.md`** (newest on top). Program-level status → `platform-v2-program-tracker.md`.
> Last updated: 2026-06-18 (session 20 — «Woodev → Лицензии» UI/UX redesign merged (PR #64) + rig browser-verified).

## Last session context (≤3 lines)

- **s20 (DONE, operator-approved "выглядит солидно"):** «Woodev → Лицензии» **UI/UX redesign** + polish + activation/deactivation bug-fix rounds, across 5 PRs all rig-verified + merged on green CI: **#64** redesign (additive `renewal_url`, RU-localized messages, pure `card-state.js` 7-group machine, rewritten card, info-notice intro, 3/2/1 grid, compact quick-link cards) · **#65** polish (form-group height/border, centered card icons, load skeleton) · **#66** 6 activation bugs (re-validate + outage-safe, error-aware status, no-license text, raw renewal URL, post-deactivate «Активировать», «Продлить» icon) · **#67** «Изменить ключ» on revoked key. No installed-site data contract touched.
- **Verified:** `composer check` green (**656 unit / 1921 assertions**, phpcs 0, phpstan 0); Codex inline critics (no blockers, all findings applied + re-criticked); **rig browser-verified** all real EDD states (A/B/B′/C/D/E/F/S0 + flows), 0 console errors; full CI + Assets-build-parity green each PR. New gotchas: `edd-error-field-vs-license-status`, `esc-url-raw-for-js-consumed-urls`.
- **Next (s21):** **OB-7 — modernize «Woodev → Плагины» page** (operator pick). v2.0.1 still **NOT released**; new symbols → `@since 2.0.2`. **OB-3 COMPLETE** except deferred F6 (backoff).

## Program status (high level)

| Stage | Status | Notes |
|---|---|---|
| S0 Platform Split | ✅ DONE | tag `platform-v2-split-done`; base platform-neutral, resolver minimal, clean-break Phase 3 shims deleted |
| S1 Shipping | ✅ DONE | PR #20; PSR-4 module; rate/packing seam + conformance audit (s2–s4) |
| S2 Box-packer | ✅ DONE | PR #21/#22; woven into rate-calc single-seam template |
| S3 Licensing | ✅ DONE | need-license (PR #25) → React UI (PR #31) → webhooks + Ed25519 signing (PR #35) |
| Remote-deactivation UX | ✅ DONE | s10–s12; command cycle proven live (push prod + pull rig); B-13/14/15 resolved |
| S4 EDD / S5 React admin / S6 ecosystem | ⚪ deferred | post-v2.0 |

`composer check` green at s20: **656 unit tests** / 1921 assertions (65 skipped), 41 integration (baseline). Keep green after each change.

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

- [🔴 BLOCKING, s21 Item 0] License page: «Изменить ключ» + ввод несуществующего ключа → бейдж «Неизвестный статус», нет кнопки «Изменить ключ» → пользователь застревает (как был отозванный до #67). Fallback-группа `unknown` в `card-state.js` имеет `changeKey: false`. Fix: захватить реальный EDD-токен мусорного ключа (возможно маппить в группу E), и дать `unknown` `changeKey: true`. Детали: `next-session-prompt.md` Item 0. Найден оператором после s20.
- [⚠️] `class-payment-gateway.php` ~3,542 lines — trait-extraction candidate (grooming, s13; → `FUTURE-BACKLOG`).
- **All earlier release-blocker findings RESOLVED** (2026-06-01 audit, PHPStan masks, base-class leaks, eCheck/ACH removal, payment-gateway base-method regression, etc.) — see `SESSION-LOG.md` + git history. Not repeated here.

### Public-docs API staleness — DEFERRED (operator decision, s13)

- `docs/` (GH Pages) registration examples still teach the **v1 `register_plugin( '1.4.0', ... )` positional API**, which in v2 is a **tombstone** (quarantines the caller, never registers). The live API is `register_loader_definition([...])`. Examples also hardcode `'1.4.0'`/`VERSION='1.4.1'` instead of the `%%FRAMEWORK_VERSION%%` placeholder / `2.0.1`. Affected: `getting-started.md`, `core-framework.md`, `payment-gateway.md`, `shipping-method.md`, `README.md`.
- **Operator decision (s13): do NOT touch public docs yet** — he is currently the only consumer of the framework; the public docs get rewritten once everything is fully ready. Recorded so it is not mistaken for an oversight.

## Next Actions

- ✅ **s20 DONE — license page redesign + bug-fix rounds (PRs #64/#65/#66/#67), operator-approved:** see "Last session context" above + `SESSION-LOG.md` s20. 656 unit, all states rig-verified, CI green. Plan: `docs-internal/plans/2026-06-18-license-page-redesign.md`.
- 🔴 **s21 Item 0 (BLOCKING, do FIRST):** license-page `unknown` fallback strands the user on a non-existent key (no «Изменить ключ») — see Known Bugs above + `next-session-prompt.md` Item 0.
- 🎯 **s21 NEXT — OB-7: modernize «Woodev → Плагины» page (operator pick):** server-rendered addon list (`woodev/admin/pages/views/html-admin-page-plugins.php` + controller `Woodev_Admin_Plugins`, menu slug `woodev-extensions`) is outdated + English. Rebuild in the new design language (parity with the license page), RU-localize, modern WP components. Future idea: woodev.ru account integration (see OB-7 in `FUTURE-BACKLOG.md`). Start with `brainstorming` (design not yet specced) → `writing-plans` → TDD + rig browser-verify + Codex critic. Ref UX: WC extensions screen.
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
