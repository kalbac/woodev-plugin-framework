# Current State — Woodev Plugin Framework

> **State only — never history.** Phase status, open debt, next actions, rig/infrastructure facts.
> Session history → `SESSION-LOG.md` (index, s50+) + `sessions/sNN.md` (per-session detail).
> Lessons learned → a gotcha (`gotchas/{slug}.md`) if it is about code or a mechanism, the session
> file if it is about how the work went. **Never a third copy here.**
> Program map → `specs/2026-06-25-shipping-module-decisions.md`.

**As of 2026-09-03 (s113).** `main` clean, **no open PRs, no worktrees**. s113 merged **#739 #740
#742 #743** and closed **#736 #474 #111 #738 #163 #150**; **#741** was filed and closed the same
session as mistaken. **65 open cards.**

⚠ **`test-cdek` is a client of the LIVE CDEK test contour, not a fixture dictionary** — a grep over
its file says nothing about which cities it knows. History → `sessions/s113.md`.

✅ **The main checkout is back on `main`** — the #163 branch was parked there for the operator's
pass, he merged it, and the tree was returned. The rig serves the working tree, so whenever a branch
is parked here, say so AND put it back.

✅ **CI works and the repo is PUBLIC** (since 27.08.2026) — public repos on standard runners consume
no quota, so the s98 billing block lifted the moment it was switched. Account, cost measurement and
the symptom (every job failing in two seconds with no log, which reads as a red build): card **#583**
and gotcha `every-ci-job-failing-in-two-seconds-is-a-billing-block`; standing rule in the global
`CLAUDE.md` → «GitHub Actions budget».

**Baselines measured 02–03.09.2026 IN THE PRIMARY CHECKOUT (s113), sodium enabled:** unit
**3401** / 8348 / **1 skipped** (against `dc0c15c`); jest **1621** in **24** suites (after #743); phpcs clean —
**with the warning level ON**; phpstan no errors; **integration 129 / 506**; **e2e 7 / 7** against
the live rig.
⚠ A gate number is only true against a NAMED COMMIT — s109 read three different unit counts on
`main` in one evening.

⚠ **`phpstan` locally needs `--memory-limit=4G`** — at 2G the parallel worker dies and prints
`Found 1 error` + "result is incomplete", which reads like a real failure. CI stays green at 2G.
Gotcha `phpstan-windows-parallel-worker-segfault`.

⚠ **Measure with `php -d extension=sodium`, or SKIPPED is meaningless** — off it reads 67, on it
reads **1 in the primary, 6 without `plugins-reference/`** (CI reports 6). Gotcha
`the-skipped-count-is-dominated-by-whether-sodium-is-enabled`.

✅ **`--order-by=reverse` is GREEN and GATED IN CI** (#606, s102) on the target PHP version only, so
a failure reproduces locally with the same command. Why it had been green by accident: `sessions/s102.md`.

✅ **`npm run test:e2e` — 7 Playwright tests against the LIVE RIG `:8973`, NOT in CI (#723)**,
~2.5 min, pinning the walkthrough 20+ sessions ran by hand. Costs nothing new (Playwright ships with
`@wordpress/scripts`). ⚠ Tests the WORKING TREE the rig serves, and does NOT replace his own pass.
Detail: `wiki/rig-pickup-walkthrough.md`.

✅ **A worktree cannot run integration at all (no wp-env), so running it is the COORDINATOR's job
and is not optional.** jest runs from bash, never `npx jest`; `jest-unit.config.js` scopes `roots`,
so a bare `npm run test:js` is correct on its own (#188).

⚠ **A gate number copied from a previous handoff is an INFERENCE — re-measure** (s93, s100). And
**a green unit suite is not sufficient where our code meets someone else's contract**: s96's #551
round 1 was green, falsified and CI-clean, and returned Galicia for Moscow. Gotcha
`a-mocked-provider-proves-the-mock-not-the-contract`.

**The settlement search is scoped by the region even when it came from the DEFAULT** (#551/#552);
a region whose `key()` is not in the settlement's own `ancestors()` is refused. ⚠ **Ask
`Location_Record::is_within()`, never `ancestors()` raw** — it is reflexive, and a settlement that
IS its own region publishes NO ancestors (#707, gotcha
`dadata-collapses-region-and-settlement-into-one-key`).

**Open cards after s113 — 65.** **Waiting on HIM:** #644 (prioritisation), #652 (scenario 1, his
rig), #331/#332 (his own "not now"), **#734** (the work is DONE — only his choice about the live
Yandex source and its undiagnosed point 3 remain) and **#737** (is the rig mu-method still wanted?
measured: it WIDENS the first fixture's field and backstop, so removing it is safe). Rationale for
the rest: `reviews/2026-08-31-644-prioritisation-material.md` §4. Still open and NOT waiting on him:
**#621** (held BEHIND **#639**), **#589**, **#639**, **#689**, **#310** (rewritten in s110 to one
button), **#701** (a research record with a stated entry condition, held the same way #689 is).
Deferred to release: #285, #247, and **#567's remainder** (150 English msgids — operator,
29.08.2026: leave them, regenerate `.pot` and rebuild `.mo` before release).

**`location.levels` is a per-country matrix** (`levels[country][level]`), and the client reads it
that way; `location.countries` stays a flat chain-wide union and is never combined with it naively.
#289, closed s110.

**#621 is held behind #639**, and its cheap fix is disproven: `get_order()` must preserve the
caller's concrete order class or a `WC_Subscription` becomes a plain order (`sessions/s103.md`).

**i18n has four rules now, and they are in `AGENTS.md` → Conventions, not here.** Storefront →
English msgid; admin → a Russian msgid stays, an English one must be translated; **logs and
anything not on a screen need not be wrapped in `__()` at all**; classify by the RENDER PATH, never
by the file's directory (gotcha `classify-an-i18n-string-by-its-render-path-not-its-file-path`).

**A foreign exception's raw text is decided by WHO READS IT** (#608/#610): merchant or plugin
author → kept; customer → redacted; every LOG sink redacts unconditionally (#594). Governs RESPONSE
and NOTE boundaries only. Per-site table: the cards + `sessions/s101.md`.

**The checkout layer REPORTS a builder conflict, it does not throw** — 17 `_doing_it_wrong()`
against one `throw`, and that throw is a failed lookup. A location field's `takeover_condition` is
dropped and reported (#474, s113). An architectural card is decided by measurement, not by asking.

**The phpcs warning level is ARMED since s110 (#139)**; noisy sniffs are excluded individually with
reasons. **Line length is the one deliberate hole, with its own ruleset:**
`vendor/bin/phpcs --standard=phpcs-line-length.xml --report=summary ./woodev` → **1393 in 138
files** — a separate file because a rule silenced by `exclude-pattern` cannot be revived from the
CLI, and its `tab-width=4` is load-bearing (gotcha
`a-phpcs-rule-silenced-by-exclude-pattern-cannot-be-revived-from-the-cli`). `[]`-only is enforced
too (`Generic.Arrays.DisallowLongArraySyntax`).

⚠ **`AGENTS.md` sits at 28.0 KB of its 28.0 KB gate.** The next addition to it must displace
something. This is the reading-budget gate working, not a defect.

**The checkout invariants that survive their cards** — #708: `validate()` enforces a takeover
field's `required` only when its condition owns the field AND WooCommerce rendered it. #707: ask
`Location_Record::is_within()`, never `ancestors()` raw. #709: `is_pickup_shipping()` is the single
source for the other three declarations, resolved LAZILY. **And the one that keeps costing
sessions: the «required» rule is implemented TWICE** — server `validate()` and the browser's
`refreshGate()` — so fixing one leaves the other (gotcha
`the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other`). #725 touched only the
browser half, deliberately and with a comment saying so.

**The «Place order» block is OPTIONAL since s112** (#725, PR #727): checkbox «Блокировать
оформление заказа», default ON. Turning it off makes `refreshGate()` **leave the button alone**, not
force-enable it. ⚠ Measured: **WooCommerce NEVER disables that button itself** — the gate exists
only because #274 added it. The settings section is now **«Форма заказа»**, slug **`checkout`**
(was «Поля»/`fields`).

**The warehouse scaffold is GONE** (#141, s112) — §17 finally true. `wc_yandex_delivery_warehouses`
survived as an installed-site contract, asserted by a still-green test.

**What closed when** is the handoff's carry-over section and the per-session files — not this file.

**Operator decisions still shaping the work:**

- *We offer narrowing, we never force it; the merchant's only switch is the region field itself*
  (#437). Surviving rules from #531/#542: `guard_custom_settlement()` below, and the `src/`
  TypeScript row in `AGENTS.md`.

**FIRST vendored runtime JS in the framework: IMask, pinned, for the checkout phone mask.** Its
country table is GENERATED (`npm run generate:phone-masks`, `lint:phone-masks` fails when stale);
libphonenumber is a devDependency and must never be enqueued; adding a country is one ISO code, never
a typed template. **[ADR-011](adr/011-vendored-imask-and-generated-phone-masks.md)** + gotcha
`a-hand-typed-format-table-drifts-from-the-real-spec`.

**No jargon in merchant-facing copy** — «чекаут»/«фреймворк» swept in s109; rule in `AGENTS.md`.

**TS was measured and scoped: `src/` only (#542), never the raw-served frontend.**

**#528 «Города вне списка»** — default OFF, only for «Список с поиском»; ON → select2 `tags`, OFF →
#517's abandon mechanism gated off. Detail → `sessions/s92.md`.

**`select2:close` fires BEFORE `select2:select`** (four rig reproductions). Any guard shaped as "the
pick will cancel the close" cannot work. Gotcha `select2-close-fires-before-select2-select`.

## ⚠ The checkout location layer

**A §8 adapter of ours can look exactly like a third party misbehaving** (#466/#471 — guard on
ownership, never a name heuristic). Gotcha `the-classic-adapter-reverts-a-select-the-location-cascade-owns`.

### Open in this layer

| Card | State |
|---|---|
| **#474** | "A location field is never a takeover field" is an UNENFORCED invariant. **Architectural — decide by measurement** (s108/s110), not by asking. |
| closed | **#437 #483** (s109), **#488 #512 #518 #473** — history in `sessions/`. Three contract facts survive them: `null` from `resolve_key()` means ONLY "asked, answered, does not know this key" (D6 deletes the row; every other failure THROWS); `compose( ...parse( $k ) )` is NOT the identity for a DERIVED key, pinned by a test; and `set_label()` applies only to fields WC does not define itself — for a native one `address-i18n.js` rewrites the rendered `<label>` AFTER render (gotcha `wc-address-i18n-reshows-fields-with-an-inline-display-block`). |

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

⚠ Before probing `test-cdek` credentials, read the gotcha
`the-cdek-fixture-credentials-are-not-the-option-they-look-like` — the obvious option is a decoy.

**`@since` = the PLANNED release, `2.0.2`; `VERSION` = the released one and lags on purpose**
(#409, #546; full rule in `AGENT-RULES.md` Rule 5, which now also covers INHERITED code → `1.0.0`).
**Nothing above `2.0.2` remains — #116(a) closed it in s111**; #555 had not normalised them.

✅ **Every Codex round gets a CANARY** — facts you already know, answered first; it caught a
misread file list in s110. Recipe: gotcha `starting-codex-under-orca-needs-four-steps-not-one`.

✅ **Codex is a full WORKER in a worktree since s107, not only a critic — #510 closed.**

⚠ **`orca orchestration worker-start --agent codex` starts it in ONE command** (s108 #683,
confirmed again in s110 for four workers). Its tool shell is the variable to measure first — the
relative-`gitdir` rewrite is a remedy for a POSIX shell, not a step 0; `worktree.useRelativePaths`
is never the fix.

⚠ **`input_accepted` is not proof the brief arrived.** In s111 `worker-read` and `terminal read`
returned EMPTY buffers all session for live, working agents — the reliable signals were the terminal
TITLE (the worker sets it from its task) and `git status --short` in its worktree. Recipe:
[wiki/orchestrating-agents-with-orca.md](wiki/orchestrating-agents-with-orca.md).

**kilo is the FALLBACK critic, not the default** — Orca cannot supervise it and the model must be
pinned via `--command`. Recipe: [wiki/orchestrating-agents-with-orca.md](wiki/orchestrating-agents-with-orca.md).

**Orca:** a fresh worktree is gate-capable with **no install step** (`orca.yaml` shares
`node_modules`; `.worktreeinclude` copies `vendor`, `plugins-reference` and local config).
Worktrees live at `.orca/worktrees/`; `vendor` must be COPIED, never shared; a fresh worktree starts
dirty with seven CRLF-only files — **never `git add -A` there**. Remove them **through Orca**, never
`git worktree remove`.

Gotchas: **264**.

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
- **B-2 loader-protocol forward-tolerance:** the resolver loads framework classes from the **highest registered copy for the whole fleet**; `backwards_compatible` deactivates-with-notice any plugin below that copy's min. Rules → `AGENT-RULES.md` Rule 3.
- [ℹ️ OB-7] «Плагины» still shows discontinued/coming-soon items — `edd-api/v2` exposes no `_coming_soon`/`_product_icon`/rating; needs a woodev.ru API extension.
- All earlier release-blocker findings are RESOLVED (2026-06-01 audit) — see `SESSION-LOG.md` + git history.

### Public-docs API staleness — DEFERRED (operator decision)

`docs/` still teaches the v1 positional `register_plugin()`, a v2 **tombstone**, and hardcodes
versions instead of `%%FRAMEWORK_VERSION%%` (5 files). **Do NOT touch public docs yet** — he is the
only consumer; they get rewritten once everything is ready.

## Next Actions

✅ **CI работает, мержить можно как обычно** — блок по биллингу снят публичностью репозитория
27.08.2026, история на **#583**.

**Порядок работ и объём — в `next-session-prompt.md` → «С чего начать».** Этот файл держит только
состояние и запреты; список из шести карточек, живший здесь с s108, устарел целиком — все шесть
закрыты (#515 #374 #483 #437 #503 в s109, #653 в s108).

🙋 **Ждут решения ОПЕРАТОРА:** **#644** (расстановка приоритетов), **#652** (его глаза на риге),
**#331**/**#332** (его «не сейчас» от 15.08.2026), **#734** (риг: держать ли первого перевозчика на
живом Яндексе), **#737** (нужен ли ещё риговый mu-метод) и **PR #743** (#163 — риговый проход;
дерево под него припарковано). **#621** держится за **#639**.
**Отложено до релиза:** #285, #247, остаток #567.

**Техдолг и улучшения карты (181, 152, 148, 182, 174, 173, 151) осознанно НЕ трогаем до пилотной
миграции** — пилот на живом карьере покажет, какие из этих карточек реальны, а какие мы придумали
сами. `FUTURE-BACKLOG.md` заморожен; всё остальное живёт на доске №6.

## 🔔 Cross-Project Reminder — Ecosystem Orchestration (dormant)

- **Trigger:** v2.0.0 shipped AND stable in production for several weeks. When it fires, surface it in the session-opening summary; do **NOT** auto-start; point the operator to the spec and read its "Prompt for the Future Agent" section first.
- **Spec:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`.

## Local rig

- **The picker lives on `/classic-checkout/`, NOT `/checkout/`** — the latter is the BLOCK checkout (the adapter is SP-11, unbuilt), where there is no `form.checkout`, no `carrier_pickup_point` and no trigger, which reads as a broken build rather than the wrong URL. Product id `12` fills the cart via `?add-to-cart=12`. Gotcha: `rig-checkout-url-is-the-block-checkout`.
- **The rig serves the WORKING TREE.** Name the branch out loud, switch the tree BEFORE asking anyone to look, and leave it there until the pass is over — s92 switched back «for tidiness» and cost the operator a whole pass. Confirm by measurement: `grep -c "<a symbol the fix introduces>" <the served file>`. Gotcha `rig-serves-the-working-tree-branch-switch-reverts-fixes`. **Tree is on `main` (verified 27.08.2026, s100) — the #518 pass is over and it was returned.** `wp_woodev_popular_settlements` is SEEDED: 3 `test-cdek` rows each for Москва (`r81`) and Санкт-Петербург (`r82`), all `last_verified_at = NULL`, so D5's lazy check really runs. Orca worktrees removed.
- ✅ **Rig at standard, re-verified 02.09.2026 (s112)** after a measurement that mutated it three
  ways and restored all three: the two pickup constants, `woodev_customer_location` (byte-identical
  to its prior value) and the WC customer city/state. Confirmed by reopening the modal — map, tiles
  and clustered Moscow points. `field_mode_region` is `related-list`, default locality
  `test-cdek:44`. Popular settlements still hold 5 `dadata` rows beside the 6 `test-cdek`.
- ✅ **THE RIG RUNS TWO CARRIERS SIDE BY SIDE since s112** (#734, PR #735) — the ordinary production
  arrangement, which it could not reproduce before. The framework separates pickup sources per
  PLUGIN, never per method (`Pickup_Controller` builds a distinct route per plugin on purpose), so
  the second carrier is a second fixture plugin, not a second method:

  | method | source | REST route | checkout field |
  |---|---|---|---|
  | `woodev_test_shipping` | LIVE Yandex, ~300 Moscow points | `/pickup/woodev-test-shipping-method/points` | `carrier_pickup_point` |
  | `woodev_realistic_pickup_shipping` | static fixture — Москва 3, **Краснодар 1** | `/pickup/woodev-realistic-shipping/points` | `realistic_pickup_point` |

  Each button is visible only under its own method. **#150 was tested through this and CLOSED in
  s113 — it does not reproduce**: Краснодар is the single-point city whose bounds degenerate, tiles
  render fully at max zoom, and a test pins that count — do not add a second point.
  ✅ **The first carrier STAYS on the live Yandex source — operator decision, 03.09.2026 (#734).**
  With two carriers the rig now shows both shapes at once (live data and clustering on one,
  deterministic fixture data on the other), which is closer to production than either alone.
  ⚠ `WOODEV_TEST_PICKUP_LIVE_YANDEX = true` still WINS over `WOODEV_TEST_PICKUP_STRATEGY` for the
  FIRST carrier, so a change to `Woodev_Test_Bulk_Point_Source` still never reaches the rig; reach
  static data through the second carrier instead of flipping constants. Gotchas
  `the-rig-runs-the-live-yandex-point-source-so-a-fixture-change-may-never-reach-it` and
  `standing-up-a-second-carrier-plugin-has-three-traps-a-green-unit-suite-cannot-see`.
  ⚠ Rig state this required, not tracked by git: `npx wp-env start` (new mapping),
  `wp plugin activate woodev-realistic-shipping-plugin`, and the method added to zone 1 as
  instance **5**.

  **STANDARD values, read off the container, never off a doc** (the s93 handoff had two wrong):

  | Option | Value |
  |---|---|
  | `woodev_location_active_provider` | `test-cdek` |
  | `woodev_location_field_mode_region` | `related-list` |
  | `woodev_location_field_mode_settlement` | `ajax-select2` |
  | `woodev_location_default_locality_policy` | `fixed` |
  | `woodev_location_default_locality_record` | the WHOLE `Location_Record` as JSON, key `test-cdek:44` — **not the key itself**, gotcha `the-default-locality-option-stores-a-whole-record-not-a-key` |
  | `woodev_location_allow_custom_settlement` | `no` |
  | checkout fields | `address_field` and `postcode_field` = `hide_for_pickup`, `region_field` = `show` |

  `mu-plugins/` holds ONLY `zz-rig-yandex-key.php` since s113. `zz-rig-test-pickup-shipping.php`
  and its third pickup method `woodev_test_pickup_shipping` were **REMOVED** (#737, operator
  03.09.2026): the second carrier covers those scenarios properly, while the mu-method was a
  half-declared carrier whose chosen point never survived a reload. It is not tracked by git; a copy
  is kept outside the repo, and restoring it means dropping the file back into `mu-plugins/`.
  Verified after removal: the method is gone from the checkout, both real carriers still work, and
  the #736 reconciliation stays silent. Switching to `geoip` needs `dadata` + a pinned non-local IP:
  gotcha `the-geoip-default-locality-cannot-resolve-on-a-local-rig`.
- **Fixture and option HISTORY — why the pickup method, the company field, the two providers and the live-Yandex switch are set the way they are: [wiki/local-rig.md](wiki/local-rig.md).** Only the current values live here.
- **`/suggest` на риге отвечает 6–10 секунд** (для неизвестного НП стабильно ~10) — измерено 25.08.2026, а не 2,4–4,5 с, как считалось. Ждать результат по факту появления строки, а не по таймеру; и если начать набирать второй запрос, не дождавшись первого, первый ОТМЕНЯЕТСЯ и abandon по нему не срабатывает (это by design).
- **Ports: dev `:8973` / tests `:8974`** (chrome-devtools MCP driver). Ports live in the gitignored `.wp-env.override.json`.
- **tests `:8974` carries NO `WOODEV_TEST_*` constants** — deleted with `wp config delete` so the integration suite is deterministic locally. The authority is `wp config set` **inside the container**, not `.wp-env.override.json`, which is only a mirror (measured).
- **Issuer `:8090` — KEPT, do NOT touch.** Effectively a copy of prod (woodev_theme = local woodev.ru + EDD SL + deactivator, with test data); the operator uses it independently. Container `c8ec47a5...-wordpress-1`. Authority pubkey `QSisoK0CDOmIOqGHvilMe+4mB/LMRFHf9hi6BxatfMk=`.
- Drive via `docker exec <cli> wp eval-file ...` (cyrillic/quoting breaks inline `wp eval` — always eval-file). Do NOT run `do_action('admin_init')` in wp-cli (WC OrderAttributionController fatals). All rig traps: gotcha `wp-safe-remote-request-local-rig`.
- Rig probes: write them to the scratchpad, **NOT** into the repo (a stray probe file once rode along in a commit). **`docker cp` INTO the container fails here** (a bind mount defeats it, and `wp eval-file` then reports a plain "does not exist") — pipe instead: `docker exec -i "$C" sh -c 'cat > /tmp/probe.php' < probe.php`, and add `--user=N` whenever the probe touches user-scoped data. Gotcha `docker-cp-into-the-wp-env-container-fails-pipe-the-probe-instead`.
- Integration tests run through the container (`npx wp-env run` breaks on command parsing here):

  ```bash
  MSYS_NO_PATHCONV=1 docker exec -w /var/www/html/woodev-framework -e TEST_SUITE=integration \
    de59f74e6d3d19d18a7f7b6608fda7e7-tests-cli-1 \
    sh -c 'rm -f .phpunit.result.cache; vendor/bin/phpunit --testsuite=Integration'
  ```

### Риг-проход по слою ПВЗ — порядок важен, иначе кнопки ПВЗ просто нет

Пошаговая процедура вынесена в [wiki/rig-pickup-walkthrough.md](wiki/rig-pickup-walkthrough.md)
(проверена в s75). Открывать, когда идёшь на риг проверять ПВЗ.

### Docker — DO NOT blindly prune

⛔ **Never run `docker volume prune` / `docker system prune --volumes` on this machine.** The
operator's `wordpress-test` stack holds ALL real plugins in one env and its volume sits unattached
while the stack is `Exited` — a prune wipes it. Full inventory, which container is which, and the
two exec traps: [wiki/local-rig.md](wiki/local-rig.md).

## Infrastructure Reference

- **Version:** `Woodev_Plugin::VERSION` (in `woodev/class-plugin.php`) = 2.0.1 (unreleased). **Raising VERSION on `main` publishes a release** — do it deliberately (#285).
- **PHP target:** 8.1 · **WP min:** 6.6 · **WC min:** 7.0
- **Tests:** Brain Monkey (unit) + WP Test Library (integration). `composer check` = phpcs + phpstan L3 + unit. JS tests must run **from bash**, not PowerShell (gotcha `powershell-drops-the-roots-flag-from-the-jest-command`).
- **CI:** GitHub Actions. **Merge PRs:** `gh pr merge <N> --squash --delete-branch` only after every job is confirmed green with state CLEAN; never `gh pr merge --auto`.
