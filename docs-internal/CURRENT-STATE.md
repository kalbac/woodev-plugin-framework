# Current State — Woodev Plugin Framework

> **State only — never history.** Phase status, open debt, next actions, rig/infrastructure facts.
> Session history → `SESSION-LOG.md` (index, s50+) + `sessions/sNN.md` (per-session detail).
> Lessons learned → a gotcha (`gotchas/{slug}.md`) if it is about code or a mechanism, the session
> file if it is about how the work went. **Never a third copy here.**
> Program map → `specs/2026-06-25-shipping-module-decisions.md`.

**As of 2026-08-27 (s98).** `main` is at `1a5cebf`, tree clean. **One PR open:** #581, held for
the operator's rig pass. #582 merged once the public switch brought CI back — 18 pass + 1 skip,
state CLEAN.

✅ **CI IS BACK. The repo is PUBLIC again since 27.08.2026 (operator decision)** — public
repositories on standard runners are free and consume no quota, so the block lifted immediately.
Measured: the same `Markdown Lint` run that failed in 2 s passed in 14 s straight after the
switch. Card **#583** carries the whole account.

What it was: the Free plan's **2000 monthly Actions minutes were 90 % spent (1800/2000)** and a
$0 Actions budget stopped the rest, from `2026-08-27T02:49:17Z`. **s98 itself spent ≈1042 of
those minutes (≈58 % of the month) in one night** — 187 runs, 27 billable minutes per full PR
cycle. The private switch on 25.08 is what started metering them at all.

The symptom is worth knowing because it reads as a red build: every job fails in two seconds,
`Label PR` included, and has **no log at all** — it never started. The reason appears only in
`gh run view`. Gotcha `every-ci-job-failing-in-two-seconds-is-a-billing-block`, which also
records the local-CI option (`run-local-ci`: WSL only, native Windows still broken at 0.18.1).

**s98 itself spent ≈1042 of those minutes (≈58 % of the month) in one night** — 187 runs, 27
billable minutes per full PR cycle, measured per job. At that rate the allowance covers two such
sessions a month. Everything merged in s98 up to and including #579 passed REAL green CI; the block
landed after.

**Baselines on `main`, measured in the PRIMARY checkout 27.08.2026 at `c35700e`:** `composer check`
**2950** / 7111 / **66 skipped**; jest **1545** in 21 suites; Integration suite in the container
**124** tests / 485 assertions.

⚠ **A gate number copied from a previous handoff is an INFERENCE — re-measure before comparing.**

⚠ **A green unit suite is NOT sufficient where our code meets someone else's contract** — s96's
#551 round 1 was green, falsified and CI-clean, and returned Galicia for Moscow. Measure the real
collaborator once. Gotcha `a-mocked-provider-proves-the-mock-not-the-contract`.

**The repo is PUBLIC again since 27.08.2026** (private 25.08–27.08). GitHub Pages is therefore available again — `docs.yml` is still disabled on `push` and one uncommented block from publishing.

**`select2:close` fires BEFORE `select2:select`** — pinned by a test since #525.

**The settlement search is scoped by the region even when the region came from the DEFAULT**
(#551/#552) — and any region whose `key()` is not in the settlement's own `ancestors()` is refused.

**Open cards after s98:** #514 (m4/m5 only — UI), #518, #559, #560, and the ones s98 filed —
#567, #570, #573, #577, #583. Everything else s98 touched is closed. Which are COMMITMENTS, and
where each was decided, is the handoff's carry-over section — not this file.

**Operator decisions still shaping the work:**

- ~~**#531**~~ **SHIPPED in s95 (#545).** `Checkout_Handler::guard_custom_settlement()` on
  `woocommerce_checkout_process`: option OFF **and** the posted settlement does not match
  `get_customer_record_at('settlement', $country)` → checkout blocked. `ajax-select2` ONLY. The
  discriminator is the SERVER RECORD, never a client flag.
- ~~**#542**~~ **SHIPPED in s95 (#544).** TypeScript is the default for NEW files in `src/`,
  enforced by `npm run lint:ts-baseline` against `scripts/ts-baseline.txt`; existing files migrate
  ON TOUCH by deleting their baseline line. `woodev/**/assets/js/frontend/` stays out.
- **#437 — STAYS OPEN, needs a scope conversation. Do NOT take autonomously.** The thing the spec
  is titled for already happened another way; decision 6's capability model and decision 8's
  checkbox do not exist in code. Live remainder: 7/8 and 9. Two of its three open questions closed.
  Detail on the card and in the spec's own status banner.

**The repo is PRIVATE since 25.08.2026** and GitHub Pages is retired with it (Free plan). TS was
measured and scoped: `src/` only (#542), never the raw-served frontend.

⚠ **A gate number copied from a previous handoff is an INFERENCE — re-measure before comparing.**
s92's figures rode into two handoffs wrong. Detail: `sessions/s93.md`.

**#528 — the merchant opt-in «Разрешить использовать города не из списка»**, default OFF, only for
«Список с поиском». ON → select2 `tags`; OFF → #517's abandon mechanism is gated off and the address
lock stands. Detail → `sessions/s92.md`.

**`select2:close` fires BEFORE `select2:select`** (four rig reproductions). Any guard shaped as "the
pick will cancel the close" cannot work. Gotcha `select2-close-fires-before-select2-select`.

**#541's cause was an ASYMMETRY between the two select renderers, not the `/select` queue** — new
seam `options.onResolving()`, a pick announced by LEVEL. Detail → `sessions/s94.md`; the two
near-miss frames it taught → gotcha `an-empty-list-while-the-search-runs-is-not-a-zero-result`.

## ⚠ The checkout location layer

**#466 was our own §8 adapter, not the network and not WooCommerce** — fixed in #471 by guarding on
ownership rather than a name heuristic. Gotcha:
`the-classic-adapter-reverts-a-select-the-location-cascade-owns`.

### Open in this layer

| Card | State |
|---|---|
| **#437** | **The next big one, not started.** Spec `specs/2026-08-21-settlement-search-design.md`; its popular-settlements half was split out into #488, whose STORAGE/verification/tools side is done — the client-facing list is #530. Measured 24.08: a region-scoped settlement list returns exactly **500** = `LIST_HARD_CAP` — what disproved #404 and killed the settlement `related-list` mode (#486). |
| ~~**#488**~~ | **CLOSED (D1-D8).** The one fact still load-bearing: `null` from `resolve_key()` means exactly one thing — "asked, answered, does not know this key" — because D6 DELETES the row on it; every other failure THROWS. History: `sessions/s89.md`-`s92.md`. |
| ~~**#512**~~ | **DONE — #548 (s95).** Surviving contract fact: `compose( ...parse( $k ) )` is NOT the identity for a DERIVED key — documented on both methods and PINNED by a test. The `VARCHAR(191)` length question was measured and closed with no guard (100+ chars of headroom). |
| **#518** | **DECIDED 25.08, still NOT started — the one carried-over build item.** A pickup selection lifts the implicit flag. `handlePickupAddressReplacing()` must make the settlement record EXPLICIT, not merely refresh the lock (`settlementRecordIsImplicit()` would still answer `true`). Measure whether the server needs the same write: a local-only flip re-locks the address after a reload. Not observed live — the critic derived it from the code. |
| ~~**#473**~~ | **CLOSED in s98 (#571).** The ownership guard now sits at the top of the `updated_checkout` loop, as it already did in `applyTakeover()`. Two facts worth keeping: the bare `$field.val()` restore is reachable in principle but was NOT reproduced live in four rig scenarios (the cascade's own restore gets there first), and the card's SECOND half — a second select2 from `maybeInitSelect2()` — **cannot happen at all**: it acts on `source_kind === 'suggest'`, ownership tests `'location'`, and `source_kind` is a single scalar (`class-field.php:265,315`). |
| **#474** | "A location field is never a takeover field" is an UNENFORCED invariant. **Operator decision needed** — public contract. |
| **#483** | `set_label()` on a location field never reaches the markup; the checkout shows WooCommerce's own labels. **Not a regression** — the same was true before #481. Possibly correct behaviour; filed as a question in Инбокс. |

**Rule 7 now has three parts** (`AGENT-RULES.md`) — 7c was settled 24.08 (#475): the fields live on
both columns, but exactly **one live cascade**, on the column that currently determines delivery,
moving in **both directions** on the toggle, **and carrying its records with it**. The live checkbox
is the only thing that picks the column; `woocommerce_ship_to_destination` merely decides whether the
checkbox exists (`billing_only`) or what it defaults to — five `file:line` citations are in the rule.

**⚠ Tooling traps — the ONE number to carry, everything else is in `GOTCHAS.md`.**
**Compare SKIPPED, not assertions — the primary is 66** (`a-worktree-silently-skips-five-contract-tests`; s87 saw it invert). Every other trap in this
area — worktrees, jest/PowerShell, Codex under Orca, stacked-PR merges, integration-job
flakiness, the three field modes and their Russian labels — is one line each under the
`[tooling/*]`, `[testing/*]` and `[rig/*]` tags of `GOTCHAS.md`, which is read at session start
anyway. Scan the tag for your task; do not keep a second copy here.

**✅ #405 IS rig-verified as of s95 — and the s83 note that said otherwise was measuring the wrong
thing.** The `test-cdek` fixture reads its credentials from the WooCommerce integration settings
array (`woocommerce_woodev_test_shipping_method_settings`), NOT from the same-looking standalone
`woodev_location_cdek_client_id` option, which nothing reads. Every earlier bogus-key probe wrote
the decoy. Written correctly, the provider throws exactly as #405's contract promises. Two further
masks: a cached `woodev_test_cdek_token` short-circuits `token()` before credentials are consulted,
and the REGION level answers from `woodev_test_cdek_regions_{country}` without touching the network
— so on this rig (region axis `related-list`) the region level can never demonstrate a credential
failure at all. Full measurement table + control:
gotcha `the-cdek-fixture-credentials-are-not-the-option-they-look-like`.

**Operator decision, #409 and again #546 (27.08.2026):** `@since` records the **planned release**,
which is and stays **`2.0.2`** — «иначе врём потребителям, у нас по факту ещё даже 2.0.0 не было».
`VERSION` records the **released** one (`2.0.1`) and lags on purpose (#285). Every `@since` above
`2.0.2` was normalised down in #555; `2.0.0` and `2.0.1` are historical v2 tagging and were left
alone — a separate question nobody has decided.

**The critic is kilo, not Codex, until the subscription is paid.** Model
`kilo/openai/gpt-5.6-sol-discounted` (operator: prefer discounted variants). **Orca cannot
supervise it** — `--inject` revokes the capability before the worker reports, on every launch path
including Orca's own UI. Dispatch WITHOUT `--inject`; full recipe in
[wiki/orchestrating-agents-with-orca.md](wiki/orchestrating-agents-with-orca.md), open question
on **#559**.

**Orca:** a fresh worktree is gate-capable with **no install step** (`orca.yaml` shares
`node_modules`; `.worktreeinclude` copies `vendor`, `plugins-reference` and local config).
Worktrees live at `.orca/worktrees/`; `vendor` must be COPIED, never shared; a fresh worktree starts
dirty with seven CRLF-only files — **never `git add -A` there**. Remove them **through Orca**, never
`git worktree remove`.

Gotchas: **222**.

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

**⛔ Ждёт кнопки оператора — БЛОКИРУЮЩЕЕ:** **#583**, GitHub Actions заблокирован по биллингу
с `02:49:17Z` 27.08.2026. Пока он не снят, **мержить нельзя ничего** — правило проекта требует
зелёной CI по каждой джобе, и «локально зелено» его не заменяет. После разблокировки: `close`/
`reopen` на **#581** и **#582**.

1. **#582** — фиксы по итогам критик-прохода (два MAJOR в уже смерженном коде). Локально всё
   зелено, ждёт только CI.
2. **#581** — ошибки полей мастера установки (#397). Видимый результат — красное поле; харнесса
   на это в репо нет. **Нужен проход по ригу**, потом мерж.
3. **#518** — выбор ПВЗ снимает «неявность» записи. Решено в s92, **не начато**. Видно
   покупателю → строить можно, мержить нельзя без его прохода.
4. **#514, остаток** — m4 (контраст WCAG AA) и m5 (ширина селектора). Оба UI, ждут рига.
   m6 и T3 закрыты в s98 (#563).
5. **#353** — начат и осознанно откачен в s98; замер объёма на карточке. Сначала решить вопрос
   про страно-слепой `provider_for_level()`, потом включать правило регистрации.
6. **Остатки слоя локаций:** #356, #358, #361, #410. **Мелочи:** #444 ✅, #451 (единственная
   не взятая из «мелочей»), #453 ✅.
7. **#503** — маска телефона. В `Бэклог`, ответ оператора в карточке. Не начата.
8. 🙋 **Ждут решения ОПЕРАТОРА, автономно не брать:** **#583** (биллинг), **#567** (язык msgid —
   305 строк работы в одну сторону, замер на карточке), **#570** (нетипизированный шов
   `Settings_Provider::create()`), **#577** (частота nopriv-лога), **#559** (Orca не
   супервизирует kilo), **#560** (`credential.helper` → `gh`), **#437** (нужна беседа об
   объёме), **#474**, **#483**, **#511**, **#515**, **#331**, **#332**, **#374**.
   **Отложено до релиза:** #285, #247. **Старое:** #289, #270, #310, #318, #321, #322.

**Техдолг и улучшения карты (181, 159, 152, 148, 182, 174, 173, 151) осознанно НЕ трогаем до пилотной миграции** — пилот на живом карьере покажет, какие из этих карточек реальны, а какие мы придумали сами.

Deferred (всё остальное — board №6): UK-CFR (settings extensibility) и прочие отложенные карточки живут на доске; `FUTURE-BACKLOG.md` заморожен.

## 🔔 Cross-Project Reminder — Ecosystem Orchestration (dormant)

- **Trigger:** v2.0.0 shipped AND stable in production for several weeks. When it fires, surface it in the session-opening summary; do **NOT** auto-start; point the operator to the spec and read its "Prompt for the Future Agent" section first.
- **Spec:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`.

## Local rig

- **The picker lives on `/classic-checkout/`, NOT `/checkout/`** — the latter is the BLOCK checkout (the adapter is SP-11, unbuilt), where there is no `form.checkout`, no `carrier_pickup_point` and no trigger, which reads as a broken build rather than the wrong URL. Product id `12` fills the cart via `?add-to-cart=12`. Gotcha: `rig-checkout-url-is-the-block-checkout`.
- **The rig serves the WORKING TREE.** Name the branch out loud, switch the tree BEFORE asking anyone to look, and leave it there until the pass is over — s92 switched back «for tidiness» and cost the operator a whole pass. Confirm by measurement: `grep -c "<a symbol the fix introduces>" <the served file>`. Gotcha `rig-serves-the-working-tree-branch-switch-reverts-fixes`. **Tree is on `main` (`c67de29`) — s95 left it there; PR #547 is NOT checked out, so the rig does NOT currently serve #539 part 3. Check out `feat/539-merge-popular-with-provider-results` before the operator's pass on it.** `wp_woodev_popular_settlements` is SEEDED: 3 `test-cdek` rows each for Москва (`r81`) and Санкт-Петербург (`r82`), all `last_verified_at = NULL`, so D5's lazy check really runs. Orca worktrees removed.
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
