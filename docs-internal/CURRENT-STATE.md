# Current State — Woodev Plugin Framework

> **State only — never history.** Phase status, open debt, next actions, rig/infrastructure facts.
> Session history → `SESSION-LOG.md` (index) + `sessions/sNN.md` (per-session detail).
> Lessons learned → a gotcha (`gotchas/{slug}.md`) if it is about code or a mechanism, the session
> file if it is about how the work went. **Never a third copy here.**
> Program history snapshot → `platform-v2-program-tracker.md`; active program map →
> `specs/2026-06-25-shipping-module-decisions.md`.

**As of 2026-08-22 (s86, autonomous).** **`main` carries PR #454** (#448 — the address field no
longer inherits the settlement axis mode), merged after the operator's rig pass. **Two PRs are
open, green, CLEAN and critic-approved, and deliberately NOT merged: #461 and #462** — they change
what the shopper sees at checkout, so they wait for a manual rig pass per the merge policy.
**PR #456 needs no separate merge — its commits are inside #461**; close it once #461 lands.

**The primary checkout is parked on `rig/s86-checkout-fixes`** (= `main` + #461 + #462, pushed).
The rig serves the working tree, so it already shows both fixes. Return the tree to `main` after
the pass.

**⛔ Budget constraint, operator decision 21.08.2026: Codex is CRITIC-ONLY until 27.08.2026.** One
overnight session burned **45% of the weekly Codex allowance** by running it as worker, planner and
critic at once. Until that date all implementation goes to Sonnet workers. Standing caps from the
same decision: **2–3 concurrent agents** (not 5–6) and **2–3 rounds per card** — a card still
REJECTed after the third verdict needs decomposition or an operator decision, not a fourth round.

## ⚠️ The checkout location layer — one disease, two staged PRs, four cards still open

**The five defects s85 left were mostly ONE root.** The layer has two value spaces and writes
across them without translation: `location-select-modes.js` put the PROVIDER KEY into the
`<option>` value of a `<select>` carrying the field's own `name`, so the key is what the form
submitted; `location-cascade.js` speaks the other space (`fieldValueFor()`, the bare name); and
**writing `.value` into a `<select>` with no matching `<option>` selects NOTHING** — the field
drops out of the POST. That produced the visible key (#455), the measured data loss (#447) and
#460 (the region was never STORED, so the empty rendered field was a symptom, not the bug).
Detail + the selectWoo half → gotcha `a-select-value-write-with-no-matching-option-submits-nothing`.

**The s85 white spot is CLOSED: there is no server path — the key never leaves the client.**
select2 creates its own `<option>` with `id = entry.key`, and #447's seeding derives the visible
label from that value. Proved by execution (revert-and-reproduce), consistent with every s85
measurement.

**PR #461 (#455/#449/#457)** — submitted value is now the field value; record identity moved to
`entry.key` via select2's `select2:select`. **PR #462 (#460)** — `writeSilently()` selects a real
option and nudges the widget with a NAMESPACED `change.select2` (an un-namespaced `change` would
trip this module's own destructive cascade gate). Each was REJECTed once by Codex on findings
proved by invocation, fixed, and re-critiqued clean (APPROVE WITH NOTES / APPROVE).

**Both are green, CLEAN and deliberately NOT merged** — shopper-facing, so they wait for a rig
pass. **Merge order: #461 first, then #462.** Full account → `sessions/s86.md`.

### Still open in this layer

| Card | State |
|---|---|
| **#449** | **Half closed, and the PR says so.** The abort marks a superseded response stale (blink gone) but does NOT cancel the `fetch()`. Real cancellation needs an AbortController through `options.fetch`. |
| **#459** | **Narrowed by measurement, not fixed.** Integration test run in the primary checkout: `OK (12 tests, 45 assertions)` — the chain survives and the server returns it, so the s85 guess is wrong. Cause is CLIENT-side; three candidates, starting with whether the rendered config block actually carries `chain`/`current`. |
| **#463** | **New.** `related-list:settlement` still submits the provider key — its `/location/list` entries never carry `.value`. Same disease, other branch. |
| **#458** | **Not a framework defect — a fixture choice. Operator decision pending; card in `Инбокс`.** `Field::set_section()` is plugin-supplied, `Checkout_Field_Policy` iterates both columns, the JS is section-aware; the rig fixture pins the fields to `shipping` deliberately. Real gap: `pickup/class-address-target.php` already encodes the `billing_only` → `billing` rule and the location module has no equivalent. Fork on the card; recommendation is to derive the section as `Address_Target` does. |

**#437 — settlement search replaces the preset list. Spec:
`specs/2026-08-21-settlement-search-design.md`** (decided end of s84; **absorbs #411**; not
started). The spec owns the reasoning — `list_localities()` for settlements and
`LIST_HARD_CAP = 500` are deleted rather than tuned, the framework never stores a settlement
dictionary, and "связанный поиск" becomes a single checkbox that self-releases on read. **#423 is
now CLOSED**, so no interim truncation measure exists.

**⚠️ Tooling traps — hooks only, each fully owned by its gotcha.** A worktree's suite is not this
tree's suite (**compare skips first, not assertions** — primary is **66**):
`a-worktree-silently-skips-five-contract-tests`. Also
`phpunit-result-cache-makes-a-run-unreproducible` (`rm -f .phpunit.result.cache` before every
measurement), `local-npm-run-build-is-not-assets-parity-evidence` (bundles build in the PRIMARY
checkout only), `three-agents-is-the-concurrency-cap-on-this-machine`,
`starting-codex-under-orca-needs-four-steps-not-one` (extended s86: the update dialog can reappear
after a clean read, and Enter there means "Update now"), and
`a-class-exists-guarded-test-stub-is-won-by-whoever-loads-first`.

**⚠️ #405 is still NOT rig-verified** (unchanged since s83). With a deliberately bogus CDEK client
id — confirmed in wp-config, transient cleared, measured against a control — the provider returned
the same results as with valid keys and never threw. It rests on unit tests plus the critic's
trace. #404 and #407 WERE verified live.

**Operator decision, #409 (closed):** `@since` records the **planned release** (currently `2.0.2`);
`VERSION` records the **released** one (`2.0.1`) and lags on purpose, because raising it publishes a
release (#285). Two workers drifted to `2.0.3` in s84 and were corrected by critics — it is not a
per-commit bump.

**Orca is configured, not left on defaults.** A fresh worktree is gate-capable with **no install
step**: `orca.yaml` shares `node_modules` by symlink; `.worktreeinclude` copies `vendor`,
`plugins-reference` (added s84), `.mcp.json`, `.wp-env.override.json` and
`.claude/settings.local.json`. Worktrees live at `.orca/worktrees/` inside the project. `vendor`
must be COPIED and never shared (gotcha
`sharing-vendor-breaks-composer-autoload-in-a-worktree`). Every fresh worktree also starts dirty
with seven CRLF-only files — **never `git add -A` there** (gotcha
`an-orca-worktree-starts-dirty-with-crlf-churn`).

Gotchas: **185**.

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

## P6 gate evidence (reference)

Base `Woodev_Plugin` is platform-neutral (**zero** WC/HPOS-named methods; enforced by
`PlatformNeutralBaseHasNoWcMethodTest`, `PlatformNeutralRestApiTest`, `BootstrapRegistrationTest`)
and not a god-object (`woodev/class-plugin.php` ~1,274 lines / 74 methods). Detail →
`wiki/architecture.md`.

## Known Bugs / Open debt

- [⚠️] `class-payment-gateway.php` ~3,542 lines — trait-extraction candidate (→ board №6).
- **B-2 loader-protocol forward-tolerance — standing behavior:** the resolver loads framework classes from the **highest registered copy for the whole fleet**, and `backwards_compatible` deactivates-with-notice any plugin below that copy's min. Standing rules → `AGENT-RULES.md` Rule 3.
- [ℹ️ OB-7] «Плагины» still shows discontinued/coming-soon items — `edd-api/v2` exposes no `_coming_soon`/`_product_icon`/rating; needs a woodev.ru-side API extension.
- All earlier release-blocker findings are RESOLVED (2026-06-01 audit) — see `SESSION-LOG.md` + git history.

### Public-docs API staleness — DEFERRED (operator decision)

`docs/` (GH Pages) still teaches the v1 positional `register_plugin( '1.4.0', ... )`, which in v2 is
a **tombstone** (quarantines the caller, never registers); the live API is
`register_loader_definition([...])`. Versions are hardcoded instead of using
`%%FRAMEWORK_VERSION%%`. Affected: `getting-started.md`, `core-framework.md`, `payment-gateway.md`,
`shipping-method.md`, `README.md`. **Do NOT touch public docs yet** — the operator is the only
consumer today; they get rewritten once everything is ready. Recorded so it is not mistaken for an
oversight.

## Next Actions

0. **Риговый проход за тобой.** Основное дерево стоит на **`rig/s86-checkout-fixes`**
   (= `main` + #461 + #462). Проверить: город показывает НАЗВАНИЕ, а не `dadata:…` (#455); регион
   переживает перезагрузку (#460); виджет select2 ПОКАЗЫВАЕТ восстановленное значение, а не
   пустоту при верном значении внутри (находка критика); в консоли нет `TypeError` на
   `update_checkout` (#457); поиск не уходит при пустом вводе и список не мигает (#449).
   Чисто → мержить **#461, затем #462** (порядок важен), закрыть **#456** (его коммиты уже в
   #461), вернуть дерево на `main`.
1. **#459 — доделать сужение.** Причина клиентская (замерено). Первым делом замерить, что реально
   попадает в отрисованный конфиг-блок на `/classic-checkout/`: несёт ли он `chain`/`current`.
2. **#458 — развилка за тобой** (карточка в `Инбокс`): (а) предупреждать о мёртвой секции или
   (б) выводить секцию самим, как `Address_Target`. Рекомендация — (б).
2a. **#463** — `related-list:settlement` сабмитит ключ провайдера; та же болезнь, другая ветка.
2b. **#449 доделать по-настоящему** — отмена запросов через AbortController в `options.fetch`.
3. **#437 — поиск НП вместо предустановленного списка.** Спека готова
   (`specs/2026-08-21-settlement-search-design.md`), поглощает #411. Не начата. Учесть, что #423
   закрыт, то есть промежуточной меры по обрезке списка больше нет.
4. **Мелкие остатки этой сессии:** #444 (26 строк i18n без домена — оснастка уже есть, довести
   `TextDomainConsistencyTest` до отчёта о вызовах короче позиции домена), #451 (сырая
   reason-фраза), #453 (числовые строковые ключи опций).
5. **Остаток ревью 27B:** #391, #393, #396, #397, #399, #400, #402. Ещё 6 развилок в комментарии
   к #382 — они за тобой.
6. **#405 — долг по проверке.** Вживую не подтверждена. Прежде чем мерить — найти условие, при
   котором фикстура СДЭК реально падает, иначе замер бессмысленный.
7. **#374 (названия опций и словарь значений)** — НЕ начинать без тебя, твоя прямая просьба.
8. **#379 (цвет/текст кнопки карты)** — низкий приоритет; `resolve_accent_color()` уже реализован.
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
- **The rig serves the WORKING TREE.** Name the branch out loud whenever you ask anyone to look, and switch the tree BEFORE asking — handing the operator a checklist while the tree holds another branch has already cost a wasted pass (gotcha `rig-serves-the-working-tree-branch-switch-reverts-fixes`).  **Дерево на `rig/s86-checkout-fixes` (s86), НЕ на `main`.** Ветка = `main` + #461 + #462, запушена; риг уже отдаёт обе починки. Вернуть дерево на `main` после риг-прохода. `rig/s85-select2-verify` тоже не удалена.
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
