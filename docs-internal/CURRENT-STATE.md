# Current State — Woodev Plugin Framework

> **State only — never history.** Phase status, open debt, next actions, rig/infrastructure facts.
> Session history → `SESSION-LOG.md` (index) + `sessions/sNN.md` (per-session detail).
> Lessons learned → a gotcha (`gotchas/{slug}.md`) if it is about code or a mechanism, the session
> file if it is about how the work went. **Never a third copy here.**
> Program history snapshot → `platform-v2-program-tracker.md`; active program map →
> `specs/2026-06-25-shipping-module-decisions.md`.

**As of 2026-08-26 (s94).** Merged this session: **#535** (#530, popular settlements shown to the
customer) — `6017a0c`. **ONE PR OPEN: #543**, carrying #536, #538, #541, #540 and part 1 of #539.
It **waits on the operator's rig pass** and on nothing else; every check is green.

⚠ **#537 no longer exists as a PR.** Squash-merging #535 deleted its head branch, which was #537's
BASE, and GitHub auto-CLOSED #537 — a closed PR whose base branch is gone cannot be reopened or
retargeted (`Cannot change the base branch of a closed pull request`). The branch was rebased onto
`main` (`git rebase --onto origin/main d966b00`, dropping the two #530 commits) and re-opened as
**#543**, same content. This is the `stacked-pr-github-mechanics` gotcha, one symptom further than
it was written for.

**The repo is PRIVATE since 25.08.2026** and GitHub Pages is retired with it (Free plan). TS was
measured and scoped: `src/` only (#542), never the raw-served frontend.

**Baselines, all measured in the PRIMARY checkout, 26.08.2026.** `main` after #535:
`composer check` **2791** / 6826 / **66 skipped**; jest **1491**.
**On PR #543's branch:** **2800** / 6839 / **66 skipped**; jest **1535**.

⚠ **s92's figures were wrong** (2783/6800/66, jest 1459) and rode into the handoff. Re-measured by
stashing a diff on a clean `main`: really 2787/6813/66 and jest 1467. **A gate number copied from a
previous handoff is an INFERENCE — re-measure before comparing.** Detail: `sessions/s93.md`.

**#528 — the merchant opt-in «Разрешить использовать города не из списка»**, default OFF, only for
«Список с поиском». ON → select2 `tags`; OFF → #517's abandon mechanism is gated off and the address
lock stands. Detail → `sessions/s92.md`.

**`select2:close` fires BEFORE `select2:select`** (four rig reproductions). Any guard shaped as "the
pick will cancel the close" cannot work. Gotcha `select2-close-fires-before-select2-select`.

**Popular settlements' customer-facing half is MERGED (#530 via #535)** — empty state, ranking,
region filtering. What it taught is two gotchas: seeding `<option>`s is not sufficient in ajax
mode, and a popular list must never arrive PRE-SELECTED.

**#541's real cause is an ASYMMETRY between the two select renderers, not the `/select` queue** —
`ajax-select2` has the record in the pick, `related-list:region` must match label text against
`/location/list` (9.7 s cold) first. New seam: `options.onResolving()`, a pick announced by LEVEL.
Detail → `sessions/s94.md`; the near-miss it taught → gotcha
`an-empty-list-while-the-search-runs-is-not-a-zero-result`.

**Open cards from s92-s94:** #523, #524, #525, #527, #529, #531, #532, #537(closed, superseded),
#539 (part 3 only), #542. Which are COMMITMENTS, and where each was decided, is the handoff's
carry-over section — not this file.

## ⚠ The checkout location layer

**#466 was our own §8 adapter, not the network and not WooCommerce** — fixed in #471 by guarding on
ownership rather than a name heuristic. Gotcha:
`the-classic-adapter-reverts-a-select-the-location-cascade-owns`.

### Open in this layer

| Card | State |
|---|---|
| **#437** | **The next big one, not started.** Spec `specs/2026-08-21-settlement-search-design.md`; its popular-settlements half was split out into #488, whose STORAGE/verification/tools side is done — the client-facing list is #530. Measured 24.08: a region-scoped settlement list returns exactly **500** = `LIST_HARD_CAP` — what disproved #404 and killed the settlement `related-list` mode (#486). |
| **#488** | Popular settlements — **CLOSED in the scope of D1–D8** (#493, #496, #500, #501, #508; #520 closed after the tools ran against a seeded table). `CAPABILITY_RESOLVE_KEY` + `resolve_key()`, the table, whole-record storage, the two clocks, lazy D5 verification, D6's four outcomes, D7 adopt-or-cancel, and the D8 «Инструменты» section all shipped. `null` from `resolve_key()` means exactly one thing — "asked, answered, does not know this key" — because D6 DELETES the row on it; every other failure throws. Full detail: `sessions/s89.md`–`s92.md`. The customer-facing half it never covered is **#530**, in PR #535. |
| **#512** | Remainder of #494 (closed in #507). `compose( ...parse( $k ) )` is no longer the identity for a DERIVED key and silently flips `is_derived()` to false — no in-repo caller reaches it, but `Locality_Key` is contract for third-party providers. Needs a docblock warning plus a pinning test. Also: escaping adds 8 bytes to a value stored in a `VARCHAR(191)` UNIQUE column with no length guard. |
| ~~**#502**~~ | **DONE — #509.** An implicit default no longer unlocks the address; merged only after a rebase and full CI re-run on the current base. Its other half became **#536**. |
| ~~**#517**~~ | **DONE — #522, accepted.** Needed #528. Detail: `sessions/s92.md`. |
| **#518** | **DECIDED 25.08 — a pickup selection lifts the implicit flag.** `handlePickupAddressReplacing()` must make the settlement record EXPLICIT, not merely refresh the lock (`settlementRecordIsImplicit()` would still answer `true`). Measure whether the server needs the same write: a local-only flip re-locks the address after a reload. Not observed live — the critic derived it from the code. |
| **#473** | **Did NOT reproduce in s89** — the gate `! $field.val()` never opened across four driven scenarios; the measurement is on the issue. The card's OTHER half — the unconditional `maybeInitSelect2()` on a field the cascade owns — is reachable without it and is what should be fixed (`isLocationOwnedField()` is already in that file). |
| **#474** | "A location field is never a takeover field" is an UNENFORCED invariant. **Operator decision needed** — public contract. |
| **#483** | `set_label()` on a location field never reaches the markup; the checkout shows WooCommerce's own labels. **Not a regression** — the same was true before #481. Possibly correct behaviour; filed as a question in Инбокс. |

**Rule 7 now has three parts** (`AGENT-RULES.md`) — 7c was settled 24.08 (#475): the fields live on
both columns, but exactly **one live cascade**, on the column that currently determines delivery,
moving in **both directions** on the toggle, **and carrying its records with it**. The live checkbox
is the only thing that picks the column; `woocommerce_ship_to_destination` merely decides whether the
checkbox exists (`billing_only`) or what it defaults to — five `file:line` citations are in the rule.

**⚠ Tooling traps — hooks only; each owned by its gotcha.** **Compare SKIPPED, not assertions — the
primary is 66** (`a-worktree-silently-skips-five-contract-tests`; s87 saw it invert — a critic's
worktree reported 1 skipped because its environment RAN 65 tests the primary skips). Also
`phpunit-result-cache-makes-a-run-unreproducible`,
`local-npm-run-build-is-not-assets-parity-evidence`,
`powershell-drops-the-roots-flag-from-the-jest-command`,
`three-agents-is-the-concurrency-cap-on-this-machine`,
`starting-codex-under-orca-needs-four-steps-not-one`,
`dispatch-inject-reports-failure-after-succeeding`,
`stacked-pr-github-mechanics` (s87 Symptom 4: a rig aggregate branch turns the rest of a stack
`DIRTY` on the first squash-merge),
`integration-jobs-die-on-a-github-api-504-not-on-your-code` (**but read the log — s87 mistook a real
test failure for it**),
`orca-worktree-create-base-branch-takes-the-local-ref` (use `origin/main`, and verify), and
`the-three-location-field-modes-and-their-russian-labels` (**«Список с поиском» is `ajax-select2`,
NOT `related-list`** — this one cost the operator a wasted rig pass).

**⚠ #405 is still NOT rig-verified** (unchanged since s83). With a deliberately bogus CDEK client id
— confirmed in wp-config, transient cleared, measured against a control — the provider returned the
same results as with valid keys and never threw.

**Operator decision, #409 (closed):** `@since` records the **planned release** (`2.0.2`); `VERSION`
records the **released** one (`2.0.1`) and lags on purpose (#285).

**Orca:** a fresh worktree is gate-capable with **no install step** (`orca.yaml` shares
`node_modules`; `.worktreeinclude` copies `vendor`, `plugins-reference` and local config).
Worktrees live at `.orca/worktrees/`; `vendor` must be COPIED, never shared; a fresh worktree starts
dirty with seven CRLF-only files — **never `git add -A` there**. Remove them **through Orca**, never
`git worktree remove`.

Gotchas: **209**.

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

## Known Bugs / Open debt

- [⚠️] `class-payment-gateway.php` ~3,542 lines — trait-extraction candidate (→ board №6).
- **B-2 loader-protocol forward-tolerance — standing behavior:** the resolver loads framework classes from the **highest registered copy for the whole fleet**, and `backwards_compatible` deactivates-with-notice any plugin below that copy's min. Standing rules → `AGENT-RULES.md` Rule 3.
- [ℹ️ OB-7] «Плагины» still shows discontinued/coming-soon items — `edd-api/v2` exposes no `_coming_soon`/`_product_icon`/rating; needs a woodev.ru-side API extension.
- All earlier release-blocker findings are RESOLVED (2026-06-01 audit) — see `SESSION-LOG.md` + git history.

### The repo is PRIVATE since 25.08.2026 — GitHub Pages is gone with it

Operator decision, once measured that hiding one file was theatre: **all 433 `docs-internal` files
were already public.** Measured consequences: **Pages is dead** (needs Pro for a private repo, the
account is Free; the API 404s), so `docs.yml` is disabled on `push` and left runnable by hand —
publishing is one uncommented block away. **0 forks**, nothing detached. **History is NOT hidden
retroactively** for anyone who already cloned it; nothing was rewritten and no rewrite was asked
for. `next-session-prompt.md` is **tracked again**; only the gate's `.prev` snapshot stays ignored.

### Public-docs API staleness — DEFERRED (operator decision)

`docs/` (GH Pages) still teaches the v1 positional `register_plugin( '1.4.0', ... )`, which in v2 is
a **tombstone** (quarantines the caller, never registers); the live API is
`register_loader_definition([...])`. Versions are hardcoded instead of using
`%%FRAMEWORK_VERSION%%`. Affected: `getting-started.md`, `core-framework.md`, `payment-gateway.md`,
`shipping-method.md`, `README.md`. **Do NOT touch public docs yet** — the operator is the only
consumer today; they get rewritten once everything is ready. Recorded so it is not mistaken for an
oversight.

## Next Actions

**Ничего не ждёт кнопки — открытых PR нет.** Следующий по величине кусок:

1. **#437** — окружающий редизайн поиска НП. Спека `specs/2026-08-21-settlement-search-design.md`,
   развилки закрыты, не начат. Самый крупный оставшийся.
2. **#530** — популярные НП покупателю: пустое состояние + ранжирование совпадений. Требование есть
   в спеке, решения D1–D8 его не несут, поэтому не построено. Отдельным PR. Референс —
   `wc_edostavka_get_preloaded_data_locations()` в СДЭК (фиксированный список, но те же две работы).
   ⚠ `minimumInputLengthFor('settlement') === 2`, поэтому пустое состояние надо засевать настоящими
   `<option>`, а не через ajax-адаптер.
3. **#526** — английское «No results found» на русском чекауте. Оператор ответил в карточке: строки
   брать из `wc_country_select_params` через `language`, свои переводы не изобретать; пример —
   `plugins-reference/woocommerce-edostavka/assets/js/frontend/city-select.js:186-215`.
4. **#503** — маска телефона. В `Бэклог`, ответ оператора в карточке. Не начата.
5. **Хвосты этой сессии:** #525 (тесты не удерживают порядок событий select2 — переворот фейка не
   краснит ни одного из 329), #529 (`related-list:settlement` недостижим, но код под него жив),
   #527, #532, #523, #524. **#531 в Инбоксе** — развилка про серверную половину опции #528.
6. **#518** — выбор ПВЗ снимает «неявность». Решено 25.08, **не начато**.
7. **Дёшево сейчас, дорого после релиза:** #512 (остаток #494), #514 (остаток ревью #505).
8. **#473** — достижимая половина через `isLocationOwnedField()`. **Мелочи:** #444, #451, #453.
   **Остаток ревью 27B:** #391, #393, #396, #397, #399, #400, #402.
9. **#405** — сперва найти условие, при котором фикстура СДЭК реально падает.
10. **Остатки слоя локаций:** #353, #356, #358, #361, #410.
11. 🙋 **НЕ брать автономно:** **#474**, **#483**, **#511**, **#515**, **#531** (в Инбоксе), **#331**,
    **#332**, **#374** (его прямая просьба). **Отложено до релиза:** #285, #247.
    **Старое:** #289, #270, #310, #318, #321, #322.

**Техдолг и улучшения карты (181, 159, 152, 148, 182, 174, 173, 151) осознанно НЕ трогаем до пилотной миграции** — пилот на живом карьере покажет, какие из этих карточек реальны, а какие мы придумали сами.

Deferred (всё остальное — board №6): UK-CFR (settings extensibility) и прочие отложенные карточки живут на доске; `FUTURE-BACKLOG.md` заморожен.

## 🔔 Cross-Project Reminder — Ecosystem Orchestration (dormant)

- **Trigger:** v2.0.0 shipped AND stable in production for several weeks. When it fires, surface it in the session-opening summary; do **NOT** auto-start; point the operator to the spec and read its "Prompt for the Future Agent" section first.
- **Spec:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`.

## Local rig

- **The picker lives on `/classic-checkout/`, NOT `/checkout/`** — the latter is the BLOCK checkout (the adapter is SP-11, unbuilt), where there is no `form.checkout`, no `carrier_pickup_point` and no trigger, which reads as a broken build rather than the wrong URL. Product id `12` fills the cart via `?add-to-cart=12`. Gotcha: `rig-checkout-url-is-the-block-checkout`.
- **The rig serves the WORKING TREE.** Name the branch out loud, switch the tree BEFORE asking anyone to look, and leave it there until the pass is over — s92 switched back «for tidiness» and cost the operator a whole pass. Confirm by measurement: `grep -c "<a symbol the fix introduces>" <the served file>`. Gotcha `rig-serves-the-working-tree-branch-switch-reverts-fixes`. **Tree is on `feat/536-default-locality-visible` (s94) — PR #543, left there deliberately for the operator's pass.** `wp_woodev_popular_settlements` is SEEDED: 3 `test-cdek` rows each for Москва (`r81`) and Санкт-Петербург (`r82`), all `last_verified_at = NULL`, so D5's lazy check really runs. Orca worktrees removed.
- **Rig location config, left as of 24.08.2026 — NOT the historical default.** Provider
  **`test-cdek`**; region axis **«Предустановленный список»** (`related-list`); settlement axis
  **«Список с поиском»** (`ajax-select2`). Set deliberately so the operator can exercise the
  region-preset + settlement-search combination, which had never been run live. Options:
  `woodev_location_active_provider`, `woodev_location_field_mode_region`,
  `woodev_location_field_mode_settlement`. **Also measured 26.08.2026, because the s93 handoff had
  it wrong: `woodev_location_default_locality_policy` = `fixed` («Москва», `test-cdek:44`) and
  `woodev_location_allow_custom_settlement` = `no`.** The handoff records that second option as
  `yes`; it is not, so #528's tag row is correctly ABSENT on the rig — read as a regression once
  before it was checked. Read the option, never a doc, before calling a missing tag row a bug.
  Back to the older default: provider `dadata` and both
  axes `ajax-select2`. Note **DaData can never offer `related-list`** — the capability it
  structurally cannot have — so switching the provider back silently removes «Предустановленный
  список» from the region select too. Switching the provider also makes a customer record whose
  level the new provider does not own read as ABSENT (by design, s78): the chain empties and the
  address locks until re-picked.
- **Switching the provider now has a visible consequence** (s78, by design): a customer record from the provider that no longer owns its level reads as ABSENT, so the chain empties and the address field locks until the customer re-picks. The record is NOT deleted — restoring the provider brings it straight back (verified). If a rig session suddenly "loses" its locality, check the active provider before suspecting a bug.
- **Fixture and option HISTORY — why the pickup method, the company field, the two providers and the live-Yandex switch are set the way they are: [wiki/local-rig.md](wiki/local-rig.md).** Only the current values live here.
- **`/suggest` на риге отвечает 6–10 секунд** (для неизвестного НП стабильно ~10) — измерено 25.08.2026, а не 2,4–4,5 с, как считалось. Ждать результат по факту появления строки, а не по таймеру; и если начать набирать второй запрос, не дождавшись первого, первый ОТМЕНЯЕТСЯ и abandon по нему не срабатывает (это by design).
- **Ports: dev `:8973` / tests `:8974`** (chrome-devtools MCP driver). Ports live in the gitignored `.wp-env.override.json`.
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
