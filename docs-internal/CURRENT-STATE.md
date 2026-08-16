# Current State — Woodev Plugin Framework

> **State only — never history.** Phase status, open debt, next actions, rig/infrastructure facts.
> Session history → `SESSION-LOG.md` (index) + `sessions/sNN.md` (per-session detail).
> Lessons learned → a gotcha (`gotchas/{slug}.md`) if it is about code or a mechanism, the session
> file if it is about how the work went. **Never a third copy here.**
> Program history snapshot → `platform-v2-program-tracker.md`; active program map →
> `specs/2026-06-25-shipping-module-decisions.md`.

**As of 2026-08-16 (s75).** `main` = `d4cd0d1` · no open PRs · working tree clean, rig on `main`.
Tests: **2272 unit / 5632 assertions / 1126 jest / 110 integration**; phpcs and phpstan clean.
Gotchas: **154**. Docs gate: `npm run lint:docs` (session-start reading 86 KB of a 120 KB budget).

## Program status (high level)

| Stage | Status | Notes |
|---|---|---|
| S0 Platform Split | ✅ DONE | tag `platform-v2-split-done`; base platform-neutral, resolver minimal, clean-break Phase 3 shims deleted |
| S1 Shipping | ✅ DONE | PR #20; PSR-4 module; rate/packing seam + conformance audit |
| S2 Box-packer | ✅ DONE | PR #21/#22; woven into rate-calc single-seam template |
| S3 Licensing | ✅ DONE | need-license (PR #25) → React UI (PR #31) → webhooks + Ed25519 signing (PR #35) |
| Remote-deactivation UX | ✅ DONE | command cycle proven live (push prod + pull rig); B-13/14/15 resolved |
| Checkout field layer (§8) | ✅ DONE | PR #132 → `957c039` |
| Shipping SP-track | 🚧 IN PROGRESS | SP-1…SP-5 done (настройки, auth+секреты, валидация, show_if, карта/ПВЗ incl. pickup selection + viewport accumulation); SP-6…SP-11 pending; map = `specs/2026-06-25-shipping-module-decisions.md` |
| Location provider layer | ✅ DONE | 16/16 tasks; all four known record-level defects closed (#334, #330, #336, #328) |
| S4 EDD / S5 React admin / S6 ecosystem | ⚪ deferred | post-v2.0 |

## Phase Status (subsystems)

| Phase | Code | Browser-verified | Notes |
|-------|------|------------------|-------|
| Framework Core | ✅ | ✅ | Bootstrap, Plugin base, Lifecycle — stable |
| Payment Gateway | ✅ | ✅ | `class-payment-gateway.php`: **~3,542 lines** (whole tree ~13.8k); trait-extraction candidate |
| Shipping Method | ✅ | ✅ | PSR-4 namespaced |
| Licensing | ✅ | ✅ | EDD store integration; React license page on core `woodev/v1` REST |
| Settings API | ✅ | ✅ | Typed settings framework |
| Settings React page (SP-1) | ✅ | ✅ | `Woodev > Настройки`: registry + `woodev/v1/settings` REST + React surface on the UI-kit |
| Setup wizard (UK-3/4) | ✅ | ✅ | React wizard on the shared UI-kit (PR #99) |
| Box Packer | ✅ | ✅ | Shipping box-packing algorithm |
| REST API | ✅ | ✅ | Plugin REST routes |
| PHPStan | ✅ | — | Level 3, **no baseline** (`phpstan-baseline.neon` removed; do not reintroduce) |
| Documentation | ✅ | — | Two-tier: `docs/` (GH Pages) + `docs-internal/` (AI agents) |

## P6 gate evidence — base is platform-neutral & not a god-object (reference)

- **Platform neutrality:** base `Woodev_Plugin` declares **zero** WooCommerce/HPOS-named methods; the last HPOS seam (`is_hpos_compatible()`) was removed. Late-safe WC hooks live in `Woodev\Framework\Woocommerce_Plugin::register_woocommerce_hooks()`; early `before_woocommerce_init` feature declarations are wired by the bootstrap from loader `supported_features` metadata. Enforced by `PlatformNeutralBaseHasNoWcMethodTest`, `PlatformNeutralRestApiTest`, `BootstrapRegistrationTest`.
- **Base size:** `woodev/class-plugin.php` ~1,274 lines / 74 methods (56 public).
- **Construction shape:** `__construct()` is an ordered list of `init_*_handler()`/`load_*` calls ending with `add_hooks()`; `add_hooks()` wires only base-owned hooks.

## Known Bugs / Open debt

- [⚠️] `class-payment-gateway.php` ~3,542 lines — trait-extraction candidate (→ board №6).
- **B-2 loader-protocol forward-tolerance — standing behavior:** the resolver loads framework classes from the **highest registered copy for the whole fleet** regardless of the bootstrap rendezvous winner; the `backwards_compatible` min-version guard deactivates-with-notice any plugin below the loaded copy's min. Standing rules (every loader definition MUST set `version` + `backwards_compatible`; registration contract additive-only from v2.0.0) → `AGENT-RULES.md` Rule 3.
- [ℹ️ OB-7 follow-up] «Плагины» still shows discontinued/coming-soon items (Беру.ру/GOODS) — `edd-api/v2` exposes no `_coming_soon`/`_product_icon`/rating; needs a woodev.ru-side API extension. Framework normalizer already consumes them forward-compatibly.
- All earlier release-blocker findings are RESOLVED (2026-06-01 audit) — see `SESSION-LOG.md` + git history.

### Public-docs API staleness — DEFERRED (operator decision)

- `docs/` (GH Pages) registration examples still teach the **v1 `register_plugin( '1.4.0', ... )` positional API**, which in v2 is a **tombstone** (quarantines the caller, never registers). The live API is `register_loader_definition([...])`. Examples also hardcode `'1.4.0'`/`VERSION='1.4.1'` instead of the `%%FRAMEWORK_VERSION%%` placeholder / `2.0.1`. Affected: `getting-started.md`, `core-framework.md`, `payment-gateway.md`, `shipping-method.md`, `README.md`.
- **Do NOT touch public docs yet** — the operator is currently the only consumer of the framework; the public docs get rewritten once everything is fully ready. Recorded so it is not mistaken for an oversight.

## Next Actions

0. **🎯 Доска — свободных «взять и делать» карточек две** (#337, #325), плюс операторская постановка #343 в Инбоксе.
   - ~~**#339**~~ — **ЗАКРЫТ** (PR #344). Предложение оператора прогонять адрес ПВЗ через `normalize()` замерено на риге и отклонено: ключ приходит от самого глубокого объекта (дом/улица), для Москвы и СПб компоненты НП нет вовсе, на строке карьера возможна тихая подмена адреса (`qc=3`), вызов платный и без кэша, capability опциональна и при отсутствии бросает исключение. Сделан вариант (2): `pickup-mount.js` объявляет запись событием `woodev_pickup_address_replacing`, каскад пересеивает `resolved`. Риг-проверено с контролем в том же прогоне.
   - **#337** — адрес без выбора НП. Замерено 16.08.2026: сценарий **достижим**, догадка оператора о недостижимости не подтвердилась (автозаполнение НП/Региона — это значения полей, а не запись). Остаётся вопрос веса, не факта: обходной путь очевиден, значит деградация, не блокер.
   - **#325** — мультипакетная корзина. Архитектурное: слой §8 односоставен по построению (одно ПВЗ-поле, один «выбранный метод», один слот). Согласовать объём с оператором до начала.
1. **Постановки оператора, обсуждены и записаны:** #331 (подсказки в КОРЗИНЕ, кнопку ПВЗ там сознательно не рисуем), #332 (подсказки в ЛК), #333 (протухание записи покупателя при смене провайдера).
2. **Постановка оператора, ждёт его решения:** #343 (фикстура провайдера СДЭК: сценарий «провайдер без адресных подсказок» + второй провайдер с suggest только на поле «Адрес»; <https://apidoc.cdek.ru/>). Смежна с #333.
3. **Отложено оператором до релиза:** #285, #247. **Старое:** #289, #270, #310, #318, #321, #322.
4. **Тулинг:** Codex чинится одной командой из-под администратора — `winget install --id Microsoft.PowerShell`. Готча `codex-shell-sandbox-broken-windows`.

**Техдолг и улучшения карты (181, 159, 152, 148, 182, 174, 173, 151) осознанно НЕ трогаем до пилотной миграции** — пилот на живом карьере покажет, какие из этих карточек реальны, а какие мы придумали сами.

Deferred (всё остальное — board №6): UK-CFR (settings extensibility) и прочие отложенные карточки живут на доске; `FUTURE-BACKLOG.md` заморожен.

## 🔔 Cross-Project Reminder — Ecosystem Orchestration (dormant)

- **Trigger:** v2.0.0 shipped AND stable in production for several weeks. When it fires, surface it in the session-opening summary; do **NOT** auto-start; point the operator to the spec and read its "Prompt for the Future Agent" section first.
- **Spec:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`.

## Local rig

- **The picker lives on `/classic-checkout/`, NOT `/checkout/`** — the latter is the BLOCK checkout (the adapter is SP-11, unbuilt), where there is no `form.checkout`, no `carrier_pickup_point` and no trigger, which reads as a broken build rather than the wrong URL. Product id `12` fills the cart via `?add-to-cart=12`. Gotcha: `rig-checkout-url-is-the-block-checkout`.
- **The rig serves the WORKING TREE.** Name the branch out loud whenever you ask anyone to look, and switch the tree BEFORE asking — handing the operator a checklist while the tree holds another branch has already cost a wasted pass (gotcha `rig-serves-the-working-tree-branch-switch-reverts-fixes`). Current state: tree on `main`.
- **Ports: dev `:8973` / tests `:8974`** (chrome-devtools MCP driver). Ports live in the gitignored `.wp-env.override.json`.
- **dev `:8973` — LIVE YANDEX bulk ON.** `WOODEV_TEST_PICKUP_LIVE_YANDEX=1` wins over `WOODEV_TEST_PICKUP_LIVE_POCHTA=false` and `WOODEV_TEST_PICKUP_STRATEGY=viewport`; the rig serves 812 live Yandex points (Moscow). The DaData token and `clean_secret` are both configured. Fixture is active only when both live flags are false. `WOODEV_TEST_POCHTA_ACCOUNT_ID` / `WOODEV_TEST_POCHTA_ACCOUNT_TYPE` (operator-supplied Отправка credentials — never committed) let `WOODEV_TEST_PICKUP_EMBEDDED=1` drive the live Почта widget; that switch is currently OFF.
- **tests `:8974` carries NO `WOODEV_TEST_*` constants** — deleted with `wp config delete` so the integration suite is deterministic locally. The authority is `wp config set` **inside the container**, not `.wp-env.override.json`, which is only a mirror (measured).
- **Issuer `:8090` — KEPT, do NOT touch.** Effectively a copy of prod (woodev_theme = local woodev.ru + EDD SL + deactivator, with test data); the operator uses it independently. Container `c8ec47a5...-wordpress-1`. Authority pubkey `QSisoK0CDOmIOqGHvilMe+4mB/LMRFHf9hi6BxatfMk=`.
- Drive via `docker exec <cli> wp eval-file ...` (cyrillic/quoting breaks inline `wp eval` — always eval-file). Do NOT run `do_action('admin_init')` in wp-cli (WC OrderAttributionController fatals). All rig traps: gotcha `wp-safe-remote-request-local-rig`.
- Rig probes: `docker cp` into the container's `/tmp` and `wp eval-file` — write them to the scratchpad, **NOT** into the repo (a stray probe file once rode along in a commit).
- Integration tests run through the container (`npx wp-env run` breaks on command parsing here):

  ```bash
  MSYS_NO_PATHCONV=1 docker exec -w /var/www/html/woodev-framework -e TEST_SUITE=integration \
    de59f74e6d3d19d18a7f7b6608fda7e7-tests-cli-1 \
    sh -c 'rm -f .phpunit.result.cache; vendor/bin/phpunit --testsuite=Integration'
  ```

### Docker inventory — DO NOT blindly prune

- **`wordpress-test` stack** (`wordpress-test` + `wp-mysql` + `wp-phpmyadmin`, volume `wordpress-test_db_data`, ~`:8080`) is the operator's **production-plugins test instance — ALL real plugins in one env** (intentional single instance, to test plugin↔plugin compatibility). **NEVER delete it or its volume, even when its containers are `Exited`.**
- Because that volume is unattached while the stack is down, **never run `docker volume prune` / `docker system prune --volumes` here** — it would wipe `wordpress-test_db_data`. Clean docker only surgically: `docker builder prune`, `docker image prune` (dangling), and explicitly-identified orphans.
- Project wp-env = `de59f74e…` (dev `:8973` + tests `:8974`); issuer = `c8ec47a5…` (`:8090`). Both KEEP.

## Infrastructure Reference

- **Version:** `Woodev_Plugin::VERSION` (in `woodev/class-plugin.php`) = 2.0.1 (unreleased). **Raising VERSION on `main` publishes a release** — do it deliberately (#285).
- **PHP target:** 8.1 · **WP min:** 6.6 · **WC min:** 7.0
- **Tests:** Brain Monkey (unit) + WP Test Library (integration). `composer check` = phpcs + phpstan L3 + unit. JS tests must run **from bash**, not PowerShell (gotcha `powershell-drops-the-roots-flag-from-the-jest-command`).
- **CI:** GitHub Actions. **Merge PRs:** `gh pr merge <N> --squash --delete-branch` only after every job is confirmed green with state CLEAN; never `gh pr merge --auto`.
