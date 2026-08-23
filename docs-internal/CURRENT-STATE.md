# Current State — Woodev Plugin Framework

> **State only — never history.** Phase status, open debt, next actions, rig/infrastructure facts.
> Session history → `SESSION-LOG.md` (index) + `sessions/sNN.md` (per-session detail).
> Lessons learned → a gotcha (`gotchas/{slug}.md`) if it is about code or a mechanism, the session
> file if it is about how the work went. **Never a third copy here.**
> Program history snapshot → `platform-v2-program-tracker.md`; active program map →
> `specs/2026-06-25-shipping-module-decisions.md`.

**As of 2026-08-23 (s87).** **The s86 stack is merged** — #461 → #462 → #464 → #467 → #468, with
#456 closed as absorbed. Closed with it: #448, #460, #455, #447, #450, #457, #465, #459. **#449 is
half closed** — the real `AbortController` through `options.fetch` is still open.

**THREE PRs are open, green, critic-passed and deliberately NOT merged: #470, #471, #472.** They are
shopper-facing, so they wait for the operator's own rig pass (standing merge policy). Each is based
on `main` — no stacking — so any subset merges in any order.

**The primary checkout is parked on `rig/s87-checkout-fixes`** (= `main` + all three, pushed). The
rig serves the working tree, so it already shows everything. **Do NOT `git checkout` the primary
checkout while anyone is looking at the rig** — a docs-only switch counts, and it cost the operator a
review pass in s86. Docs work goes in a separate Orca worktree.

**⛔ Codex is CRITIC-ONLY until 27.08.2026** (operator, 21.08.2026 — one overnight burned 45% of the
weekly allowance). All implementation goes to Sonnet workers. Caps: **2–3 concurrent agents**,
**2–3 rounds per card**. s87 respected both: two workers, three Codex runs, all critics.

## ⚠ The checkout location layer

**#466 was never the network and never WooCommerce — it was our own §8 adapter.** `runTakeover()`
walks EVERY field in the store, and `applyTakeover()` reads "not a takeover field for this country"
as "revert it to a text input" — which, for a `source_kind === 'location'` field, was the ONLY thing
that function ever did. It destroyed the `<select>` the cascade had attached ~100 ms earlier; the
apparent 3–13 s delay is the length of the FIRST `update_order_review`. The region survived only
because `isWcManagedField()` matches `/(^|_)state$/` — a NAME heuristic, not ownership. Found by
browser measurement with stack traces. → **PR #471**. Detail: gotcha
`the-classic-adapter-reverts-a-select-the-location-cascade-owns`.

### The three open PRs — detail on each PR page

| PR | Card | Critic | Rig |
|---|---|---|---|
| **#470** | #463 | **APPROVE** — 7 checkpoints, 5 by execution | **not** rig-verified: needs BOTH axes on related-list, and `get_field_mode_settlement()` clamps by provider capability and by the region axis (#404) |
| **#471** | #466 | **APPROVE WITH NOTES** | verified: both fields select2-backed by t=244 ms, no revert, address lock intact |
| **#472** | #458 | **REJECT**, two HIGH blockers; on ROUND 3 (the cap) | measured by me — below |

**#472's two blockers, both reproduced by execution:** (1) after the live «ship to a different
address» toggle **NEITHER column has the cascade widget** — `activeAddressSection()` is evaluated
only while `buildChain()` builds `entry.chain`, and the change handler then arbitrates over that
frozen chain; (2) an explicitly declared field **silently** suppresses a fanned location variant
(`+=` keeps existing keys, direct assignment overwrites), so on the rig billing got the location
layer at the **address level only** while Rule 7b says both columns, with no diagnostic anywhere —
exactly how s86 lost the s44 decision. **No installed-data-contract loss was found** (both ids are
native; WooCommerce still owns address persistence).

**#475 (`Инбокс`) is the fork this raised, and only the operator can settle it:** does the cascade
become per-section — two chains, two `/select` queues, one location record per column or per
customer, and which one drives pickup and rates?

### Also open in this layer — detail on the cards

- **#469** — our fields lose `data-input-classes`, so WC stamps a literal `undefined` class
  (verified against WC source). Touches `inject()`, so held back from #472; do it first once #472 lands.
- **#473** — the same disease as #466 in the same file's `updated_checkout` subscriber. The sink was
  **driven to fire**; the live WooCommerce path that empties the select is what is still unproven.
- **#474** — "a location field is never a takeover field" is an UNENFORCED invariant.

**#437 — settlement search replaces the preset list.** Spec
`specs/2026-08-21-settlement-search-design.md` (end of s84; **absorbs #411**; not started):
`list_localities()` for settlements and `LIST_HARD_CAP = 500` are deleted rather than tuned, the
framework never stores a settlement dictionary, and "связанный поиск" becomes one checkbox that
self-releases on read. **#423 is CLOSED**, so no interim truncation measure exists.

**⚠ Tooling traps — hooks only; each owned by its gotcha.** **Compare SKIPPED, not assertions — the
primary is 66** (`a-worktree-silently-skips-five-contract-tests`; s87 saw it invert — a critic's
worktree reported 1 skipped because its environment RAN 65 tests the primary skips). Also
`phpunit-result-cache-makes-a-run-unreproducible`,
`local-npm-run-build-is-not-assets-parity-evidence` (bundles build in the PRIMARY checkout only),
`powershell-drops-the-roots-flag-from-the-jest-command`,
`three-agents-is-the-concurrency-cap-on-this-machine`,
`starting-codex-under-orca-needs-four-steps-not-one`,
`dispatch-inject-reports-failure-after-succeeding` (s87: an `ok:false` inject costs the worker its
`worker_done` but not its work), and `stacked-pr-github-mechanics` (s87 Symptom 4: a rig aggregate
branch turns the rest of a stack `DIRTY` on the first squash-merge; fix with
`git rebase --onto origin/main <rig merge commit>`). CI integration jobs can die on an `HTTP/2 504`
from api.github.com during the wp-env build — not ours, `gh run rerun --failed`.

**⚠ #405 is still NOT rig-verified** (unchanged since s83). With a deliberately bogus CDEK client id
— confirmed in wp-config, transient cleared, measured against a control — the provider returned the
same results as with valid keys and never threw. #404 and #407 WERE verified live.

**Operator decision, #409 (closed):** `@since` records the **planned release** (`2.0.2`); `VERSION`
records the **released** one (`2.0.1`) and lags on purpose — raising it publishes a release (#285).

**Orca is configured, not left on defaults.** A fresh worktree is gate-capable with **no install
step**: `orca.yaml` shares `node_modules` by symlink; `.worktreeinclude` copies `vendor`,
`plugins-reference`, `.mcp.json`, `.wp-env.override.json` and `.claude/settings.local.json`.
Worktrees live at `.orca/worktrees/`. `vendor` must be COPIED, never shared
(`sharing-vendor-breaks-composer-autoload-in-a-worktree`), and a fresh worktree starts dirty with
seven CRLF-only files — **never `git add -A` there**.

Gotchas: **187**.

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

0. **Ручная проверка рига — она за тобой, и дерево уже стоит на нужной ветке.**
   `rig/s87-checkout-fixes` = `main` + **#470** + **#471** + **#472**. Что смотреть:
   - **#471 (#466):** поле НП должно быть select2 сразу, а не через 3–13 секунд. Регион тоже.
     Адрес заблокирован до выбора НП. Старое демо §8 на `billing_city` должно продолжать работать.
   - **#472 (#458):** переключить магазин в «Force shipping to the customer billing address» и
     убедиться, что наши поля применились к billing. **И отдельно — переключить чекбокс «доставить
     по другому адресу» ПОСЛЕ загрузки:** критик воспроизвёл состояние, в котором виджета не
     остаётся ни в одной колонке.
   - **#470 (#463):** требует переключить ОБЕ оси на «Связанный список». На риге не проверялось.
   Три PR независимы (каждый от `main`), мержить можно любым подмножеством в любом порядке.
1. **#472 — два подтверждённых замечания, решать до мержа.** (а) правило 7b выполняется молча
   не полностью, когда id уже занят явным объявлением плагина; (б) живое переключение
   «другой адрес» оставляет обе колонки без виджета. Подробности — в таблице выше и в PR.
2. **#469** — `data-input-classes` в PHP. Маленькая, но трогает `inject()`, поэтому её держали
   отдельно от #472. После мержа #472 сделать первой.
3. **#473** — сток из `updated_checkout`. Сток уже доведён до срабатывания; осталось найти живой
   путь WooCommerce, который делает селект пустым при непустом сторе. Воспроизвести на риге и
   только потом чинить.
4. **#449, вторая половина** — настоящая отмена запросов через `AbortController` в `options.fetch`.
5. **#474** — закрепить инвариант `source_location()` × `set_takeover_condition()` в билдере.
   Развилка (исключение против `_doing_it_wrong()`) за тобой — это публичный контракт.
6. **#437 — поиск НП вместо предустановленного списка.** Спека готова
   (`specs/2026-08-21-settlement-search-design.md`), поглощает #411. Не начата. #423 закрыт, то есть
   промежуточной меры по обрезке списка больше нет.
7. **Мелочи:** #444 (26 строк i18n без домена — оснастка уже есть в `TextDomainConsistencyTest`),
   #451 (сырая reason-фраза), #453 (числовые строковые ключи опций).
8. **Остаток ревью 27B:** #391, #393, #396, #397, #399, #400, #402. Ещё 6 развилок в комментарии
   к #382 — они за тобой.
9. **#405 — долг по проверке.** Вживую не подтверждена. Прежде чем мерить — найти условие, при
   котором фикстура СДЭК реально падает, иначе замер бессмысленный.
10. **#374 (названия опций и словарь значений)** — НЕ начинать без тебя, твоя прямая просьба.
    **#379 (цвет/текст кнопки карты)** — низкий приоритет.
11. **Остатки слоя локаций:** #353, #356, #358, #361, #410.
12. **Постановки оператора:** #331, #332. **Отложено до релиза:** #285, #247.
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

### Риг-проход по слою ПВЗ — порядок важен, иначе кнопки ПВЗ просто нет

Пошаговая процедура вынесена в [wiki/rig-pickup-walkthrough.md](wiki/rig-pickup-walkthrough.md)
(проверена в s75). Открывать, когда идёшь на риг проверять ПВЗ.

### Docker inventory — DO NOT blindly prune

- **`wordpress-test` stack** (`wordpress-test` + `wp-mysql` + `wp-phpmyadmin`, volume `wordpress-test_db_data`, ~`:8080`) is the operator's **production-plugins test instance — ALL real plugins in one env** (intentional single instance, to test plugin↔plugin compatibility). **NEVER delete it or its volume, even when its containers are `Exited`.**
- Because that volume is unattached while the stack is down, **never run `docker volume prune` / `docker system prune --volumes` here** — it would wipe `wordpress-test_db_data`. Clean docker only surgically: `docker builder prune`, `docker image prune` (dangling), and explicitly-identified orphans.
- Project wp-env = `de59f74e…` (dev `:8973` + tests `:8974`); issuer = `c8ec47a5…` (`:8090`). Both KEEP.

## Infrastructure Reference

- **Version:** `Woodev_Plugin::VERSION` (in `woodev/class-plugin.php`) = 2.0.1 (unreleased). **Raising VERSION on `main` publishes a release** — do it deliberately (#285).
- **PHP target:** 8.1 · **WP min:** 6.6 · **WC min:** 7.0
- **Tests:** Brain Monkey (unit) + WP Test Library (integration). `composer check` = phpcs + phpstan L3 + unit. JS tests must run **from bash**, not PowerShell (gotcha `powershell-drops-the-roots-flag-from-the-jest-command`).
- **CI:** GitHub Actions. **Merge PRs:** `gh pr merge <N> --squash --delete-branch` only after every job is confirmed green with state CLEAN; never `gh pr merge --auto`.
