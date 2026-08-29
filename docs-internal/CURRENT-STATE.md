# Current State — Woodev Plugin Framework

> **State only — never history.** Phase status, open debt, next actions, rig/infrastructure facts.
> Session history → `SESSION-LOG.md` (index, s50+) + `sessions/sNN.md` (per-session detail).
> Lessons learned → a gotcha (`gotchas/{slug}.md`) if it is about code or a mechanism, the session
> file if it is about how the work went. **Never a third copy here.**
> Program map → `specs/2026-06-25-shipping-module-decisions.md`.

**As of 2026-08-29 (s104).** `main` clean, **no open PRs**. Merged in s104, six: **#655** (#650),
**#656** (#646), **#658** (#647), **#659** (#653, part), **#660** (#644's material), and **#657**
(#361) after the operator's own rig pass. s103 merged four; history → `sessions/s103.md`,
`sessions/s104.md`.

✅ **The main checkout is on `main`** (verified 27.08.2026, s100). The rig serves the working tree,
so whenever a branch is parked there for a pass, say so here AND put it back afterwards.

✅ **CI works and the repo is PUBLIC** (since 27.08.2026, operator decision) — public repositories
on standard runners consume no quota, so the s98 billing block lifted the moment it was switched.
The whole account, the cost measurement and the symptom (every job failing in two seconds with no
log, which reads as a red build) live on card **#583** and in gotcha
`every-ci-job-failing-in-two-seconds-is-a-billing-block`. The standing rule that came out of it is
in the global `CLAUDE.md` → «GitHub Actions budget».

**Baselines on `main`, measured 29.08.2026 IN THE PRIMARY CHECKOUT (s104), sodium enabled:**
`composer check` **3216** / 7894 / **1 skipped**, and the same three numbers under
`--order-by=reverse`. Integration **126** / 494. jest **1566** in 21 suites.

⚠ **Measure with `php -d extension=sodium`, or the SKIPPED number is meaningless** — off it reads 67,
on it reads **1 in the primary and 6 wherever `plugins-reference/` is absent** (CI reports 6). Why,
and why the old "the primary is 66" rule was measuring the operator's php.ini: gotcha
`the-skipped-count-is-dominated-by-whether-sodium-is-enabled`.

✅ **`--order-by=reverse` is GREEN on `main` and GATED IN CI — #606, closed in s102 (PR #624).** CI
runs it on the target PHP version only, deterministically, so a failure reproduces locally with the
same command. What it found on its first night, and why the suite had been green by alphabetical
accident: `sessions/s102.md`.

✅ **Integration on `main` (s104, primary checkout): 126 tests / 494 assertions, OK** — re-measured,
not copied forward; unchanged since s100.

✅ **jest on `main` (s104): 1566 tests in 21 suites.** Run from bash with `--roots`,
never `npx jest`.

⚠ **A gate number copied from a previous handoff is an INFERENCE — re-measure before comparing**
(s93, again s100). And **a green unit suite is not sufficient where our code meets someone else's
contract**: s96's #551 round 1 was green, falsified and CI-clean, and returned Galicia for Moscow.
Gotcha `a-mocked-provider-proves-the-mock-not-the-contract`.

**The settlement search is scoped by the region even when the region came from the DEFAULT**
(#551/#552) — and any region whose `key()` is not in the settlement's own `ancestors()` is refused.

**Open cards after s104:** **#621** (item 1 measured; the FIX is untouched and is held BEHIND
**#639** — see below), the locations leftovers **#356/#358/#410** (#361 shipped and merged as PR #657
after his own rig pass; of the remaining three, #356 and #358 need a CONTRACT decision before any
code and #410 is decided and buildable), **#589**, **#644** (material delivered, prioritisation is
his), **#652**, **#653** (the inline-jQuery half only), **#639**, **#437** (needs a scope
conversation — do NOT take autonomously), and the standing list #474, #483, #515, #331, #332,
#374. Deferred to release: #285, #247, and **#567's remainder** (150 English msgids with no
translation — operator, 29.08.2026: leave them, regenerate the `.pot` and rebuild the `.mo` before
release).

⚠ **#511 was on the waiting-list for four days after it closed** (25.08.2026), and #159 sat in the
held-tech-debt line for two weeks after closing (13.08.2026). Both removed here; the #644 audit
found them by checking every card number in this file against `gh issue view`, which is the only
method that works. Full map → `reviews/2026-08-29-docs-and-board-audit.md`.

**#621 is held behind #639 deliberately.** The cheap fix (a `WC_Order` subclass) was written,
measured and REVERTED in s103 — `get_order()` must preserve the caller's concrete order class, or a
`WC_Subscription` silently becomes a plain order. What remains is a ~138-site context object, and
investing that in a subsystem whose size #639 is questioning is the wrong order. Detail:
`sessions/s103.md`.

**i18n has four rules now, and they are in `AGENTS.md` → Conventions, not here.** Storefront →
English msgid; admin → a Russian msgid stays, an English one must be translated; **logs and
anything not on a screen need not be wrapped in `__()` at all**; classify by the RENDER PATH, never
by the file's directory (gotcha `classify-an-i18n-string-by-its-render-path-not-its-file-path`).

**#613 is closed** (47 of 51 sites guarded; `payment-tokens-handler.php:700` closed by docblock
in s102 after measuring the hook has zero consumers, `is_available` triaged HARMLESS). History →
`sessions/s101.md` / `s102.md`.

**Operator decision, 27.08.2026 (#608, #610) — whether a foreign exception's raw text may stand is
decided by WHO READS IT, not by how dangerous the text looks.** MERCHANT or plugin author → kept;
CUSTOMER → redacted. Every LOG sink redacts unconditionally (**#594**); this rule is about RESPONSE
and NOTE boundaries only. His reasoning verbatim and the per-site table: cards **#608** / **#610**,
`sessions/s101.md`.

**What closed when** is the handoff's carry-over section and the per-session files — not this
file. s104 closed #650, #646, #647, #361; s103 closed #567 (rule 1), #627, #353.

**Operator decisions still shaping the work:**

- **#531** (s95, PR #545) and **#542** (s95, PR #544) both SHIPPED; the surviving rules are the
  `guard_custom_settlement()` line further down this file and the `src/` TypeScript row in
  `AGENTS.md` → Conventions. History: `sessions/s95.md`.
- **#437 — STAYS OPEN, needs a scope conversation. Do NOT take autonomously.** The thing the spec
  is titled for already happened another way; decision 6's capability model and decision 8's
  checkbox do not exist in code. Live remainder: 7/8 and 9. Two of its three open questions closed.
  Detail on the card and in the spec's own status banner.

**TS was measured and scoped: `src/` only (#542), never the raw-served frontend.**

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
| ~~**#518**~~ | **CLOSED 27.08.2026 — PR #586, accepted live by the operator.** A pickup selection now makes the settlement record EXPLICIT, and the address stays unlocked after a reload. His acceptance note became its own card: the lock is not scoped to pickup-type shipping methods, so under a courier method no pickup selection can clear it. **This row claimed «DECIDED, still NOT started» for two sessions after the card closed** — the miss that prompted the #644 audit. |
| ~~**#473**~~ | **CLOSED in s98 (#571).** The ownership guard now sits at the top of the `updated_checkout` loop, as it already did in `applyTakeover()`. Two facts worth keeping: the bare `$field.val()` restore is reachable in principle but was NOT reproduced live in four rig scenarios (the cascade's own restore gets there first), and the card's SECOND half — a second select2 from `maybeInitSelect2()` — **cannot happen at all**: it acts on `source_kind === 'suggest'`, ownership tests `'location'`, and `source_kind` is a single scalar (`class-field.php:265,315`). |
| **#474** | "A location field is never a takeover field" is an UNENFORCED invariant. **Operator decision needed** — public contract. |
| **#483** | `set_label()` on a location field never reaches the markup; the checkout shows WooCommerce's own labels. **Not a regression** — the same was true before #481. Possibly correct behaviour; filed as a question in Инбокс. |

**Rule 7 now has three parts** (`AGENT-RULES.md`) — 7c was settled 24.08 (#475): the fields live on
both columns, but exactly **one live cascade**, on the column that currently determines delivery,
moving in **both directions** on the toggle, **and carrying its records with it**. The live checkbox
is the only thing that picks the column; `woocommerce_ship_to_destination` merely decides whether the
checkbox exists (`billing_only`) or what it defaults to — five `file:line` citations are in the rule.

**⚠ Tooling traps — the ONE number to carry, everything else is in `GOTCHAS.md`.**
**Compare SKIPPED, not assertions — but only with sodium enabled, where the primary is 1 and any
checkout without `plugins-reference/` is 6** (`a-worktree-silently-skips-five-contract-tests` for
the 5, `the-skipped-count-is-dominated-by-whether-sodium-is-enabled` for why the old "66" was not a
contract). Every other trap in this
area — worktrees, jest/PowerShell, Codex under Orca, stacked-PR merges, integration-job
flakiness, the three field modes and their Russian labels — is one line each under the
`[tooling/*]`, `[testing/*]` and `[rig/*]` tags of `GOTCHAS.md`, which is read at session start
anyway. Scan the tag for your task; do not keep a second copy here.

**✅ #405 IS rig-verified as of s95** — the s83 note that said otherwise was writing a decoy
option. Before probing `test-cdek` credentials on the rig, read the gotcha
`the-cdek-fixture-credentials-are-not-the-option-they-look-like`: it carries the real option name,
two further masks, the measurement table and the control.

**Operator decision, #409 and again #546 (27.08.2026):** `@since` records the **planned release**,
which is and stays **`2.0.2`** — «иначе врём потребителям, у нас по факту ещё даже 2.0.0 не было».
`VERSION` records the **released** one (`2.0.1`) and lags on purpose (#285). Every `@since` above
`2.0.2` was normalised down in #555; `2.0.0` and `2.0.1` are historical v2 tagging and were left
alone — a separate question nobody has decided.

✅ **Codex is BACK and is the critic again** — subscription renewed 28.08.2026 (s102), smoke-tested
through Orca the same day rather than assumed: real Codex TUI, live shell, and a four-fact canary
answered with three exact hits. The fourth (a commit hash) lost its leading character — not
fabrication, but why every Codex round still gets one fact you already know. Recipe and the two
ways CLI 0.150.1 departs from it: gotcha `starting-codex-under-orca-needs-four-steps-not-one`.

**kilo is the FALLBACK critic now, not the default** (it held the seat 27.08–28.08 while the
subscription was unpaid). If it is used again it has its own traps — Orca cannot supervise it, and
the model must be pinned via `--command`. Figures, cost and full recipe:
[wiki/orchestrating-agents-with-orca.md](wiki/orchestrating-agents-with-orca.md).

**Orca:** a fresh worktree is gate-capable with **no install step** (`orca.yaml` shares
`node_modules`; `.worktreeinclude` copies `vendor`, `plugins-reference` and local config).
Worktrees live at `.orca/worktrees/`; `vendor` must be COPIED, never shared; a fresh worktree starts
dirty with seven CRLF-only files — **never `git add -A` there**. Remove them **through Orca**, never
`git worktree remove`.

Gotchas: **242**.

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

### Public-docs API staleness — DEFERRED (operator decision)

`docs/` (GH Pages) still teaches the v1 positional `register_plugin( '1.4.0', ... )`, which in v2 is
a **tombstone** (quarantines the caller, never registers); the live API is
`register_loader_definition([...])`. Versions are hardcoded instead of using
`%%FRAMEWORK_VERSION%%`. Affected: `getting-started.md`, `core-framework.md`, `payment-gateway.md`,
`shipping-method.md`, `README.md`. **Do NOT touch public docs yet** — the operator is the only
consumer today; they get rewritten once everything is ready. Recorded so it is not mistaken for an
oversight.

## Next Actions

✅ **CI работает, мержить можно как обычно.** Блок по биллингу снят публичностью репозитория
27.08.2026 — история на **#583**.

1. **#410 — решено оператором 29.08.2026 и готово к реализации.** Админ-уведомление на страницах
   Woodev; механизм `Woodev_Admin_Notice_Handler` теперь под тестами и с тайп-хинтами (PR #659),
   так что причина ждать #653 снята.
2. **#621, пункты 2-3** — как чинить динамические свойства на `WC_Order`. Пункт 1 закрыт замером в
   s102. Остаётся контекст-объект на ~138 правок. **Держится за #639** — не начинать до ответа.
3. **Остатки слоя локаций: #356 и #358** — по обоим нужен РАЗБОР комментарием на карточку, а не
   код: #358 прямо запрещает «чинить» баном cross-provider scope и несёт два ПРОТИВОПОЛОЖНЫХ
   измеренных исхода; #356 — проектирование forget-пути.
4. **#653, вторая половина** — инлайновый jQuery. Замер и рекомендация («оставить как есть, если
   не сводить несколько мелких админских скриптов в один бандл разом») уже на карточке, в `Инбокс`.
5. **#644** — материал по приоритетам сдан (`reviews/2026-08-29-docs-and-board-audit.md`).
   Расстановка приоритетов — ЕГО. В `Инбокс`.
6. **#503** — маска телефона. В `Бэклог`, ответ оператора в карточке. Не начата.
7. **#589** — шов «IP → координаты». Только если найдётся ВТОРОЙ потребитель, иначе не начинать.

⚠ **Аудит #644 нашёл 93 открытые карточки, а не 86**, и что из них по коду проверены только ~10 —
остальные оттриажены по заголовку. Полная поштучная сверка остаётся отдельной работой.
🙋 **Ждут решения ОПЕРАТОРА, автономно не брать:** **#567** (язык msgid — 305 строк работы в одну сторону, замер на
карточке), **#437** (нужна беседа об объёме), **#613** в части `payment-tokens-handler.php:700`,
**#474**, **#483**,
**#515**, **#331**, **#332**, **#374**. **Отложено до релиза:** #285, #247. **Старое:** #289, #270, #310, #318,
и #321, #322.

**Техдолг и улучшения карты (181, 152, 148, 182, 174, 173, 151) осознанно НЕ трогаем до пилотной миграции** (#159 из этого списка закрыт 13.08.2026) — пилот на живом карьере покажет, какие из этих карточек реальны, а какие мы придумали сами.

Deferred (всё остальное — board №6): UK-CFR (settings extensibility) и прочие отложенные карточки живут на доске; `FUTURE-BACKLOG.md` заморожен.

## 🔔 Cross-Project Reminder — Ecosystem Orchestration (dormant)

- **Trigger:** v2.0.0 shipped AND stable in production for several weeks. When it fires, surface it in the session-opening summary; do **NOT** auto-start; point the operator to the spec and read its "Prompt for the Future Agent" section first.
- **Spec:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`.

## Local rig

- **The picker lives on `/classic-checkout/`, NOT `/checkout/`** — the latter is the BLOCK checkout (the adapter is SP-11, unbuilt), where there is no `form.checkout`, no `carrier_pickup_point` and no trigger, which reads as a broken build rather than the wrong URL. Product id `12` fills the cart via `?add-to-cart=12`. Gotcha: `rig-checkout-url-is-the-block-checkout`.
- **The rig serves the WORKING TREE.** Name the branch out loud, switch the tree BEFORE asking anyone to look, and leave it there until the pass is over — s92 switched back «for tidiness» and cost the operator a whole pass. Confirm by measurement: `grep -c "<a symbol the fix introduces>" <the served file>`. Gotcha `rig-serves-the-working-tree-branch-switch-reverts-fixes`. **Tree is on `main` (verified 27.08.2026, s100) — the #518 pass is over and it was returned.** `wp_woodev_popular_settlements` is SEEDED: 3 `test-cdek` rows each for Москва (`r81`) and Санкт-Петербург (`r82`), all `last_verified_at = NULL`, so D5's lazy check really runs. Orca worktrees removed.
- ✅ **The rig is BACK in its standard state — measured 27.08.2026 (s100), not inferred.** The #518
  pass is over and everything the s99 detour changed has been put back. Read straight off the
  container:

  | Option | Value |
  |---|---|
  | `woodev_location_active_provider` | `test-cdek` |
  | `woodev_location_field_mode_region` | `related-list` |
  | `woodev_location_field_mode_settlement` | `ajax-select2` |
  | `woodev_location_default_locality_policy` | `fixed` |
  | `woodev_location_default_locality_record` | `test-cdek:44` (Москва) |
  | `woodev_location_allow_custom_settlement` | `no` |

  `wp-content/mu-plugins/` holds only `zz-rig-test-pickup-shipping.php` and `zz-rig-yandex-key.php`
  — the temporary `zz-rig-geoip-ip.php` is gone.

  **Switching it again** (`geoip` needs `dadata` + a pinned non-local IP; restoring options is not
  enough because a stored customer location survives): gotcha
  `the-geoip-default-locality-cannot-resolve-on-a-local-rig`.

- **The option VALUES the rig must be restored to are the table above** — read them off the
  container, never off a doc (the s93 handoff had two of them wrong, and a correctly-absent
  #528 tag row was read as a regression once because of it). Why each value is set the way it
  is, and what changes when the provider is switched back to `dadata`: [wiki/local-rig.md](wiki/local-rig.md).
  One consequence worth knowing before you switch anything: **DaData structurally cannot offer
  `related-list`**, so moving the provider back silently removes «Предустановленный список» from
  the region select.
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
