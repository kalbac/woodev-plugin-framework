# Current State — Woodev Plugin Framework

> **State only — never history.** Phase status, open debt, next actions, rig/infrastructure facts.
> Session history → `SESSION-LOG.md` (index) + `sessions/sNN.md` (per-session detail).
> Lessons learned → a gotcha (`gotchas/{slug}.md`) if it is about code or a mechanism, the session
> file if it is about how the work went. **Never a third copy here.**
> Program history snapshot → `platform-v2-program-tracker.md`; active program map →
> `specs/2026-06-25-shipping-module-decisions.md`.

**As of 2026-08-20 (s83).** Branch `feat/location-post-review-fixes` — integration branch off `main`,
**not pushed, not merged**. `#403` was merged to `main` first (19/19 CI jobs green, state CLEAN), so
the four post-review cards branch from a clean base instead of stacking.

**All four post-review cards are done: #404, #405, #406, #407** — three Sonnet workers under Orca
orchestration, each result reviewed by a Codex critic, every approved finding fixed rather than
deferred. #406 needed three rounds; its last round was still running when this was written.

Tests measured on `main`: **2454 unit / 6090 assertions / 1241 jest** (the s82 handoff's
2448/6067/1232 was stale, and both workers were briefed with the wrong numbers before it was caught).
On the integration branch with #404/#407 and #405 merged: phpcs clean, phpstan **0 errors**,
**2467 unit / 6111 assertions**, **1251 jest**, asset build parity holds. Gotchas: **172**.

**Orca is now the subagent path** — worker = Sonnet 5, critic = Codex, nobody accepts their own work.
Recipe, placement rules and traps: `wiki/orchestrating-agents-with-orca.md`. Two gotchas came out of
using it, both orchestrator errors: a worker's Serena `activate_project` path must be its OWN
worktree (`serena-activate-path-must-be-the-worker-s-worktree`), and `input_accepted` is not proof a
worker started (`input-accepted-is-not-proof-a-worker-started`).

**⚠️ #405 is NOT rig-verified.** With a deliberately bogus CDEK client id — confirmed in wp-config,
token transient cleared, measured against a control — the provider returned the same results as with
valid keys and never threw. The rig's CDEK fixture does not fail on a bad client id at the settlement
level, so s82's observed conditions were never reproduced. #405 rests on unit tests covering both
states and the critic's trace of every failure path. #404 and #407 WERE verified live.

New cards: **#408** (Orca repo setup hook empty → fresh worktrees have no `vendor/`/`node_modules/`),
**#409** (`@since` convention contradicts the code — `Инбокс`, needs the operator's decision),
**#410**, **#411** (the `truncated` flag nobody reads), **#412** (settlement option set not live).

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
| Location provider layer | ✅ DONE | 16/16 tasks; record-level defects closed: #334, #330, #336, #328, and in s78 #352 (mixed-provider chain), #350 (settlement typed without picking), #346 + #333 (stale record reads as absent) |
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

0. **Смержить `feat/location-post-review-fixes`.** Ветка собрана локально, **не запушена**. Осталось
   влить в неё #406 (третий круг критика), прогнать гейты целиком, запушить, открыть PR, проверить
   КАЖДУЮ джобу CI отдельно на pass + state CLEAN, и только тогда мержить. Оператор уже разрешил
   мержить самому: «мержь сам, по-нормальному».
1. **#409 — конвенция `@since`, в `Инбокс`, ждёт твоего решения.** Три варианта в карточке. Правило
   в `AGENT-RULES.md` Rule 5 сейчас противоречит коду: 1388 тегов `2.0.2` против одного `2.0.1`.
   Пока не решено — новый код тегать `2.0.2`, как весь остальной репозиторий.
2. **#408 — прописать setup-скрипт репо в Orca** (`composer install && npm ci`). Твоё действие в UI,
   команды в CLI нет. Без этого каждый воркер тратит круг на бутстрап.
3. **Настройки приложения Orca** — то, о чём ты просил и что сделано лишь наполовину. Из CLI снято
   всё измеримое (см. `sessions/s83.md`), панели Settings в десктопном приложении НЕ пройдены.
   `orca computer` умеет их водить, но не при живых воркерах.
4. **#411 — флаг `truncated` никто не читает**: список локаций молча режется на 500 записях, и для
   покупателя это выглядит как «моего города нет». Тот же класс тихого отказа, что и #405.
5. **#412** — набор значений «Тип поля НП» обновляется только после сохранения и перезагрузки, тогда
   как смена провайдера рядом работает на лету.
6. **#374 (названия опций и словарь значений)** — НЕ начинать без оператора, его прямая просьба.
7. **#379 (цвет/текст кнопки карты)** — низкий приоритет; `resolve_accent_color()` уже реализован.
8. **Ревью локальной 27B — 20 карточек #383–#402 в `Инбокс`**, приоритизация за оператором.
   **#383 — критический IDOR**, чинить первым. Ещё 6 развилок в комментарии к #382.
9. **Остатки слоя локаций:** #353, #356, #358, #361, #410.
10. **Постановки оператора:** #331, #332. **Отложено до релиза:** #285, #247.
    **Старое:** #289, #270, #310, #318, #321, #322.

**Техдолг и улучшения карты (181, 159, 152, 148, 182, 174, 173, 151) осознанно НЕ трогаем до пилотной миграции** — пилот на живом карьере покажет, какие из этих карточек реальны, а какие мы придумали сами.

Deferred (всё остальное — board №6): UK-CFR (settings extensibility) и прочие отложенные карточки живут на доске; `FUTURE-BACKLOG.md` заморожен.

## 🔔 Cross-Project Reminder — Ecosystem Orchestration (dormant)

- **Trigger:** v2.0.0 shipped AND stable in production for several weeks. When it fires, surface it in the session-opening summary; do **NOT** auto-start; point the operator to the spec and read its "Prompt for the Future Agent" section first.
- **Spec:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`.

## Local rig

- **The picker lives on `/classic-checkout/`, NOT `/checkout/`** — the latter is the BLOCK checkout (the adapter is SP-11, unbuilt), where there is no `form.checkout`, no `carrier_pickup_point` and no trigger, which reads as a broken build rather than the wrong URL. Product id `12` fills the cart via `?add-to-cart=12`. Gotcha: `rig-checkout-url-is-the-block-checkout`.
- **The rig serves the WORKING TREE.** Name the branch out loud whenever you ask anyone to look, and switch the tree BEFORE asking — handing the operator a checklist while the tree holds another branch has already cost a wasted pass (gotcha `rig-serves-the-working-tree-branch-switch-reverts-fixes`).  **Дерево сейчас на `feat/location-post-review-fixes` (s83)** — интеграционная ветка с #404/#407 и #405. Риг отдаёт именно её. После мержа переключить на `main`.
- **There IS a pickup-type shipping method on the rig now (s81), and it lives OUTSIDE the repo.** Until s81 the only active method was `Woodev Test Shipping`, whose `delivery_type` is `courier` — so `Checkout_Config::pickup_method_ids()` resolved to `[]` and the entire `hide_for_pickup` branch of the checkout-field policy was physically unreachable on the rig. Fixed with a container-only mu-plugin, `wp-content/mu-plugins/zz-rig-test-pickup-shipping.php` (that directory is NOT bind-mounted from the repo — `zz-rig-yandex-key.php` was already there as precedent), registering `woodev_test_pickup_shipping` (`Woodev Test Pickup`) whose `get_delivery_type()` is `pickup`. It is enabled in zone 1 «Russia» as instance 4, alongside `free_shipping` and `woodev_test_shipping`, so a checkout session can switch between a pickup rate and a courier rate. **Keep it** — it is what made the s80 gap verifiable, and it is the only way to exercise that branch live. To remove: delete the mu-plugin file and `wp wc shipping_zone_method delete 1 4 --user=1`.
- **The active location provider on the rig is `test-cdek`, deliberately.** Kept that way at the end of s78: the mixed pair (CDEK for region+settlement, DaData for address) is the configuration that exercises every location fix shipped so far, and it is the only way to reproduce #352/#333 at all. Back to DaData: `wp option update woodev_location_active_provider dadata`.
- **Switching the provider now has a visible consequence** (s78, by design): a customer record from the provider that no longer owns its level reads as ABSENT, so the chain empties and the address field locks until the customer re-picks. The record is NOT deleted — restoring the provider brings it straight back (verified). If a rig session suddenly "loses" its locality, check the active provider before suspecting a bug.
- **Ports: dev `:8973` / tests `:8974`** (chrome-devtools MCP driver). Ports live in the gitignored `.wp-env.override.json`.
- **Two live location providers on the rig now (s76).** DaData is active by default; the CDEK test
  contour is registered as `test-cdek` (fixture
  `tests/_fixtures/woodev-test-shipping-method/class-test-cdek-location-provider.php`) and its
  credentials sit in the container's wp-config as `WOODEV_TEST_CDEK_CLIENT_ID` /
  `WOODEV_TEST_CDEK_CLIENT_SECRET`. Flip with
  `wp option update woodev_location_active_provider test-cdek` (back: `dadata`). CDEK serves
  region+settlement only, so with DaData also configured the address level falls back to DaData —
  that is the layer answering honestly, not a bug (gotcha
  `a-level-served-can-come-from-the-fallback-not-the-active-provider`).
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

### Риг-проход по слою ПВЗ — порядок, который реально работает (проверено s75)

Порядок важен, иначе кнопки ПВЗ просто нет:

1. `http://localhost:8973/?add-to-cart=12` — наполнить корзину, затем `/classic-checkout/`.
2. Выбрать метод доставки **Woodev Test Shipping** (при `free_shipping` кнопка ПВЗ скрыта).
3. Location-слой сидит на **`shipping_state` / `shipping_city` / `shipping_address_1`**.
   `billing_city` — select2 старого демо §8, НЕ наш слой, и его источник подсказок на риге ничего
   не отдаёт (это блокирует попытку оформить заказ целиком).
4. Локаль рига английская: DaData отдаёт транслит («Russia, Moscow city»), карьер — кириллицу.
   Не баг рига, а естественная почва для расхождения написаний.
5. Список пунктов открывается кнопкой `.woodev-pickup-list__toggle` (рядом
   `.woodev-pickup-filter__toggle` — это фильтр, легко перепутать).
6. Читать состояние сразу после подтверждения пункта **рано** — идёт `update_checkout`, поля в
   переходном виде. Подождать и перечитать.

### Docker inventory — DO NOT blindly prune

- **`wordpress-test` stack** (`wordpress-test` + `wp-mysql` + `wp-phpmyadmin`, volume `wordpress-test_db_data`, ~`:8080`) is the operator's **production-plugins test instance — ALL real plugins in one env** (intentional single instance, to test plugin↔plugin compatibility). **NEVER delete it or its volume, even when its containers are `Exited`.**
- Because that volume is unattached while the stack is down, **never run `docker volume prune` / `docker system prune --volumes` here** — it would wipe `wordpress-test_db_data`. Clean docker only surgically: `docker builder prune`, `docker image prune` (dangling), and explicitly-identified orphans.
- Project wp-env = `de59f74e…` (dev `:8973` + tests `:8974`); issuer = `c8ec47a5…` (`:8090`). Both KEEP.

## Infrastructure Reference

- **Version:** `Woodev_Plugin::VERSION` (in `woodev/class-plugin.php`) = 2.0.1 (unreleased). **Raising VERSION on `main` publishes a release** — do it deliberately (#285).
- **PHP target:** 8.1 · **WP min:** 6.6 · **WC min:** 7.0
- **Tests:** Brain Monkey (unit) + WP Test Library (integration). `composer check` = phpcs + phpstan L3 + unit. JS tests must run **from bash**, not PowerShell (gotcha `powershell-drops-the-roots-flag-from-the-jest-command`).
- **CI:** GitHub Actions. **Merge PRs:** `gh pr merge <N> --squash --delete-branch` only after every job is confirmed green with state CLEAN; never `gh pr merge --auto`.
