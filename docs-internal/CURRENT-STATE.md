# Current State — Woodev Plugin Framework

> **State only — never history.** Phase status, open debt, next actions, rig/infrastructure facts.
> Session history → `SESSION-LOG.md` (index, s50+) + `sessions/sNN.md` (per-session detail).
> Lessons learned → a gotcha (`gotchas/{slug}.md`) if it is about code or a mechanism, the session
> file if it is about how the work went. **Never a third copy here.**
> Program map → `specs/2026-06-25-shipping-module-decisions.md`.

**As of 2026-08-26 (s96).** `main` is at `6e4478b`, tree clean, **no open PRs.** Merged this
session: **#547** (#539 part 3, accepted by the operator on the rig) and **#552** (#551 + #553).

**Baselines on `main`, measured in the PRIMARY checkout 26.08.2026 at `6e4478b`:** `composer check`
**2828** / 6921 / **66 skipped**; jest **1535** in 21 suites.

⚠ **A gate number copied from a previous handoff is an INFERENCE — re-measure before comparing.**

⚠ **A green unit suite is NOT sufficient where our code meets someone else's contract** — s96's
#551 round 1 was green, falsified and CI-clean, and returned Galicia for Moscow. Measure the real
collaborator once. Gotcha `a-mocked-provider-proves-the-mock-not-the-contract`.

**The repo is PRIVATE since 25.08.2026** and GitHub Pages is retired with it (Free plan).

**`select2:close` fires BEFORE `select2:select`** — pinned by a test since #525.

**The settlement search is scoped by the region even when the region came from the DEFAULT**
(#551/#552) — and any region whose `key()` is not in the settlement's own `ancestors()` is refused.

**Open cards:** #523, #524, #527, #532, #546 (`@since` drift — operator decision), #554 (docs
reading budget — operator decision). Which are COMMITMENTS, and where each was decided, is the
handoff's carry-over section — not this file.

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

**Open cards after s95:** #523, #524, #527, #532, #539 (part 3 built, in PR #547, awaiting the
operator), #546 (NEW — the `@since` drift, in `Инбокс`, needs his decision). **Closed in s95:**
#512, #525, #529, #531, #542. Which are COMMITMENTS, and where each was decided, is the handoff's
carry-over section — not this file.

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
| **#518** | **DECIDED 25.08 — a pickup selection lifts the implicit flag.** `handlePickupAddressReplacing()` must make the settlement record EXPLICIT, not merely refresh the lock (`settlementRecordIsImplicit()` would still answer `true`). Measure whether the server needs the same write: a local-only flip re-locks the address after a reload. Not observed live — the critic derived it from the code. |
| **#473** | **Did NOT reproduce in s89** — the gate `! $field.val()` never opened across four driven scenarios; the measurement is on the issue. The card's OTHER half — the unconditional `maybeInitSelect2()` on a field the cascade owns — is reachable without it and is what should be fixed (`isLocationOwnedField()` is already in that file). |
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

**Operator decision, #409 (closed):** `@since` records the **planned release** (`2.0.2`); `VERSION`
records the **released** one (`2.0.1`) and lags on purpose (#285).

**Orca:** a fresh worktree is gate-capable with **no install step** (`orca.yaml` shares
`node_modules`; `.worktreeinclude` copies `vendor`, `plugins-reference` and local config).
Worktrees live at `.orca/worktrees/`; `vendor` must be COPIED, never shared; a fresh worktree starts
dirty with seven CRLF-only files — **never `git add -A` there**. Remove them **through Orca**, never
`git worktree remove`.

Gotchas: **216**.

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

**Ждёт кнопки оператора: PR #547** (#539 часть 3) — 18/18 зелёные, UI, ручной проход на риге.
⚠ Риг стоит на `main` — чтобы смотреть #547, сперва переключить дерево на
`feat/539-merge-popular-with-provider-results`.

1. **#437** — окружающий редизайн поиска НП. Спека `specs/2026-08-21-settlement-search-design.md`.
   Самый крупный оставшийся. 🙋 **Оператор пометил: нужна беседа об объёме, автономно НЕ брать.**
2. **#546 (в Инбоксе, новая)** — `@since` разошёлся: 1460 докблоков говорят `2.0.2`, слой локаций
   ставит `2.1.0`, `AGENTS.md` говорит `2.0.2`. Развилка про планируемый релиз — только оператор.
3. **#518** — выбор ПВЗ снимает «неявность». Решено 25.08, **не начато**.
4. **#514** — остаток ревью #505. ⚠ Карточка СМЕШАННАЯ: m1/m2 не-UI, а m4 (контраст WCAG AA) и
   m5 (ширина селектора) — UI. Разделить на два PR, иначе не-UI половина застрянет в ожидании рига.
5. **#503** — маска телефона. В `Бэклог`, ответ оператора в карточке. Не начата.
6. **Хвосты:** #527, #532, #523, #524.
7. **#473** — достижимая половина через `isLocationOwnedField()`. **Мелочи:** #444, #451, #453.
   **Остаток ревью 27B:** #391, #393, #396, #397, #399, #400, #402.
8. **Остатки слоя локаций:** #353, #356, #358, #361, #410.
9. 🙋 **НЕ брать автономно:** **#437**, **#474**, **#483**, **#511**, **#515**, **#546**, **#331**,
   **#332**, **#374** (его прямая просьба). **Отложено до релиза:** #285, #247.
   **Старое:** #289, #270, #310, #318, #321, #322.

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
