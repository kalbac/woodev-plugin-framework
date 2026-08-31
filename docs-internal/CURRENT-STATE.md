# Current State — Woodev Plugin Framework

> **State only — never history.** Phase status, open debt, next actions, rig/infrastructure facts.
> Session history → `SESSION-LOG.md` (index, s50+) + `sessions/sNN.md` (per-session detail).
> Lessons learned → a gotcha (`gotchas/{slug}.md`) if it is about code or a mechanism, the session
> file if it is about how the work went. **Never a third copy here.**
> Program map → `specs/2026-06-25-shipping-module-decisions.md`.

**As of 2026-08-31 (s110).** `main` clean at **`c321d6a`**, **no open PRs, no worktrees**. s110 merged
**#705** and closed **#139** and **#289**; rewrote **#310** to its live remnant; decided **#701**
(a record, not work) and settled the **#474** doc contradiction. The **#644 tail is finished** — 48
cards verified against the CODE across two workers, 13 comments — and **#695**'s reconciliation pass
is done. **71 open cards.** History → `sessions/s110.md`, `sessions/s109.md`.

✅ **The main checkout is on `main`** (verified 27.08.2026, s100). The rig serves the working tree,
so whenever a branch is parked there for a pass, say so here AND put it back afterwards.

✅ **CI works and the repo is PUBLIC** (since 27.08.2026) — public repos on standard runners consume
no quota, so the s98 billing block lifted the moment it was switched. Account, cost measurement and
the symptom (every job failing in two seconds with no log, which reads as a red build): card **#583**
and gotcha `every-ci-job-failing-in-two-seconds-is-a-billing-block`; standing rule in the global
`CLAUDE.md` → «GitHub Actions budget».

**Baselines on `main`, measured 31.08.2026 IN THE PRIMARY CHECKOUT (s110), sodium enabled, against
`c321d6a`:** unit **3364** / 8302 / **1 skipped**; jest **1598** in **23** suites; phpcs clean —
**now with the warning level ON**; phpstan no errors. **Integration 129 / 506 carried from s109 and
NOT re-measured in s110** (no PHP behaviour changed; CI's three wp-env stacks passed on PR #705).
⚠ A gate number is only true against a NAMED COMMIT — s109 read three different unit counts on
`main` in one evening.

⚠ **`phpstan` locally now needs `--memory-limit=4G`.** At 2G — the value CI uses and the older
gotcha recommends — the parallel worker dies on the memory limit and prints `Found 1 error` plus
"result is incomplete", which reads exactly like a real analysis failure over the diff you just
wrote. CI stays green at 2G. Gotcha `phpstan-windows-parallel-worker-segfault`, s106 section.

⚠ **Measure with `php -d extension=sodium`, or the SKIPPED number is meaningless** — off it reads 67,
on it reads **1 in the primary and 6 wherever `plugins-reference/` is absent** (CI reports 6). Why,
and why the old "the primary is 66" rule was measuring the operator's php.ini: gotcha
`the-skipped-count-is-dominated-by-whether-sodium-is-enabled`.

✅ **`--order-by=reverse` is GREEN on `main` and GATED IN CI — #606, closed in s102 (PR #624),** on
the target PHP version only, so a failure reproduces locally with the same command. Why the suite
had been green by alphabetical accident: `sessions/s102.md`.

✅ **A worktree cannot run integration at all (no wp-env), so running it is the COORDINATOR's job
and is not optional.** jest runs from bash, never `npx jest`; `jest-unit.config.js` scopes `roots`,
so a bare `npm run test:js` is correct on its own (#188).

⚠ **A gate number copied from a previous handoff is an INFERENCE — re-measure before comparing**
(s93, s100). And **a green unit suite is not sufficient where our code meets someone else's
contract**: s96's #551 round 1 was green, falsified and CI-clean, and returned Galicia for Moscow.
Gotcha `a-mocked-provider-proves-the-mock-not-the-contract`.

**The settlement search is scoped by the region even when it came from the DEFAULT** (#551/#552);
a region whose `key()` is not in the settlement's own `ancestors()` is refused.

**Open cards after s110 — 71. `Инбокс` holds #694 (his call); `В работе` holds #116** (only its part
(а) is left). **Eight cards genuinely need HIS answer, and the list is now verified rather than
assumed** — #644, #652, #694, #692, #331/#332, #695, #141 — see
`reviews/2026-08-31-644-prioritisation-material.md` §4, which also records why #639, #621, #285,
#567, #701, #247, #689, #589 and #371 were considered and excluded. Still open and NOT waiting on
him: **#621** (held BEHIND **#639**), **#589**, **#639**, **#689**, **#474** (architectural —
decide by measurement, see below), **#310** (rewritten in s110 to one button), **#701** (a research
record with a stated entry condition, held the same way #689 is). Deferred to release: #285, #247,
and **#567's remainder** (150 English msgids — operator, 29.08.2026: leave them, regenerate `.pot`
and rebuild `.mo` before release).

**`location.levels` is a per-country matrix** (`levels[country][level]`), and the client reads it
that way; `location.countries` stays a flat chain-wide union and is never combined with it naively.
#289, closed s110.

**#621 is held behind #639 deliberately**, and its cheap fix is already disproven: `get_order()`
must preserve the caller's concrete order class, or a `WC_Subscription` silently becomes a plain
order (measured and reverted, `sessions/s103.md`).

**i18n has four rules now, and they are in `AGENTS.md` → Conventions, not here.** Storefront →
English msgid; admin → a Russian msgid stays, an English one must be translated; **logs and
anything not on a screen need not be wrapped in `__()` at all**; classify by the RENDER PATH, never
by the file's directory (gotcha `classify-an-i18n-string-by-its-render-path-not-its-file-path`).

**Operator decision, 27.08.2026 (#608, #610) — whether a foreign exception's raw text may stand is
decided by WHO READS IT, not by how dangerous it looks.** MERCHANT or plugin author → kept; CUSTOMER
→ redacted. Every LOG sink redacts unconditionally (**#594**); this rule governs RESPONSE and NOTE
boundaries only. Reasoning and per-site table: cards **#608**/**#610**, `sessions/s101.md`.

**#474 is ARCHITECTURAL — decide by measurement, do not ask.** Its own card text calls it the
operator's call; that text is older than the s108 reclassification recorded here, and this side
governs. Said so on the card in s110 so the two stop disagreeing; the card was not otherwise touched.

**The phpcs warning level is ARMED since s110 (#139)** — `warning-severity=0` used to silence 19
sniffs wholesale. Sixteen now fail the run; the noisy ones are excluded individually, each with its
reason. **Line length is the one deliberate hole and it has its own ruleset:**
`vendor/bin/phpcs --standard=phpcs-line-length.xml --report=summary ./woodev` → **1393 in 138
files**. It needs a separate file because a rule silenced by `exclude-pattern` cannot be revived by
any CLI flag, and its `tab-width=4` is load-bearing (without it the same sniff reports 1002). Gotcha
`a-phpcs-rule-silenced-by-exclude-pattern-cannot-be-revived-from-the-cli`. The `[]`-only convention
is enforced too now (`Generic.Arrays.DisallowLongArraySyntax`, ERROR, 633 sites fixed by `phpcbf`).

⚠ **`AGENTS.md` sits at 28.0 KB of its 28.0 KB gate.** The next addition to it must displace
something. This is the reading-budget gate working, not a defect.

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

**#528 — the merchant opt-in «Города вне списка»** (shortened by #374), default OFF, only for
«Список с поиском». ON → select2 `tags`; OFF →
#517's abandon mechanism is gated off. Detail → `sessions/s92.md`.

**`select2:close` fires BEFORE `select2:select`** (four rig reproductions). Any guard shaped as "the
pick will cancel the close" cannot work. Gotcha `select2-close-fires-before-select2-select`.

## ⚠ The checkout location layer

**#466 was our own §8 adapter, not the network and not WooCommerce** — fixed in #471 by guarding on
ownership rather than a name heuristic. Gotcha:
`the-classic-adapter-reverts-a-select-the-location-cascade-owns`.

### Open in this layer

| Card | State |
|---|---|
| ~~**#437**~~ | **CLOSED in s109.** `/location/list` lost `LIST_HARD_CAP`, `limit` and `truncated`; a stored `related-list` now reads as `ajax-select2`, option untouched, no migration. Decisions 8/9 retired by the operator 31.08.2026. Detail: `sessions/s109.md`. |
| ~~**#488**~~ | **CLOSED (D1-D8).** The one fact still load-bearing: `null` from `resolve_key()` means exactly one thing — "asked, answered, does not know this key" — because D6 DELETES the row on it; every other failure THROWS. History: `sessions/s89.md`-`s92.md`. |
| ~~**#512**~~ | **DONE — #548 (s95).** Surviving contract fact: `compose( ...parse( $k ) )` is NOT the identity for a DERIVED key — documented on both methods and PINNED by a test. The `VARCHAR(191)` length question was measured and closed with no guard (100+ chars of headroom). |
| ~~**#518**~~ | **CLOSED 27.08.2026 — PR #586.** A pickup selection makes the settlement record EXPLICIT and the address stays unlocked after a reload. This row claimed «DECIDED, still NOT started» for two sessions after the card closed — the miss that prompted #644. |
| ~~**#473**~~ | **CLOSED in s98 (#571).** Ownership guard sits at the top of the `updated_checkout` loop. Its second half cannot happen at all: `maybeInitSelect2()` acts on `source_kind === 'suggest'` while ownership tests `'location'`, and `source_kind` is a single scalar (`class-field.php:265,315`). |
| **#474** | "A location field is never a takeover field" is an UNENFORCED invariant. **Operator decision needed** — public contract. |
| ~~**#483**~~ | **CLOSED in s109 (PR #697).** Contract: `set_label()` applies only to fields WC does not define itself — for a native one `address-i18n.js` overwrites the rendered `<label>` from the country locale AFTER render. Gotcha `wc-address-i18n-reshows-fields-with-an-inline-display-block`. |

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

**Operator decision, #409 and again #546 (27.08.2026):** `@since` records the **planned release**,
which is and stays **`2.0.2`** — «иначе врём потребителям, у нас по факту ещё даже 2.0.0 не было».
`VERSION` records the **released** one (`2.0.1`) and lags on purpose (#285). Every `@since` above
`2.0.2` was normalised down in #555; `2.0.0` and `2.0.1` are historical v2 tagging and were left
alone — a separate question nobody has decided.

✅ **Every Codex round gets a CANARY — a few facts you already know, answered before anything else.**
It earns its keep: in s110 the canary made the critic say «не смог прочитать» for the one fact it
could not obtain instead of inventing it, and exposed that its file list was misread. Recipe:
gotcha `starting-codex-under-orca-needs-four-steps-not-one`.

✅ **Codex is a full WORKER in a worktree since s107, not only a critic — #510 closed.**

⚠ **`orca orchestration worker-start --agent codex` starts it in ONE command** (s108 #683,
confirmed again in s110 for four workers). Its tool shell is the variable to measure first — the
relative-`gitdir` rewrite is a remedy for a POSIX shell, not a step 0; `worktree.useRelativePaths`
is never the fix.

⚠ **`input_accepted` is not proof the brief arrived — READ THE BUFFER BACK.** The dispatch body does
not always reach the prompt, and the worker then honestly reports having nothing to do. ⚠ And read
the buffer's SIZE before concluding: the tail is truncated, so a missing phrase can simply have
scrolled off (that misled the coordinator once in s110). Recipe:
[wiki/orchestrating-agents-with-orca.md](wiki/orchestrating-agents-with-orca.md).

**kilo is the FALLBACK critic, not the default** (it held the seat 27.08–28.08 while the
subscription was unpaid) and has its own traps — Orca cannot supervise it, and the model must be
pinned via `--command`. Recipe: [wiki/orchestrating-agents-with-orca.md](wiki/orchestrating-agents-with-orca.md).

**Orca:** a fresh worktree is gate-capable with **no install step** (`orca.yaml` shares
`node_modules`; `.worktreeinclude` copies `vendor`, `plugins-reference` and local config).
Worktrees live at `.orca/worktrees/`; `vendor` must be COPIED, never shared; a fresh worktree starts
dirty with seven CRLF-only files — **never `git add -A` there**. Remove them **through Orca**, never
`git worktree remove`.

Gotchas: **254**.

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

Полный список открытых карточек — в блоке «Open cards after s108» выше; он и есть источник правды,
здесь только порядок и запреты.

1. **#515**, **#374**, **#483**, **#437**, **#503** — все разблокированы 31.08.2026 и берутся без
   вопросов. Порядок и объём каждой — в `next-session-prompt.md` → «С чего начать».
2. **#644** — доделать поштучную сверку открытых карточек по КОДУ: аудит 29.08.2026 проверил так
   лишь ~10, остальные оттриажены по заголовку. Расстановка приоритетов — ЕГО, сверка — агента.
3. **#589** — шов «IP → координаты». Только если найдётся ВТОРОЙ потребитель.
4. **#689** — косметика по RFC 6265 §5.4, отклонена осознанно; брать только если появится потребитель.

🙋 **Ждут решения ОПЕРАТОРА — список СИЛЬНО сократился 31.08.2026.** Брейншторм s108 обнаружил,
что **девять из одиннадцати** «ждущих» карточек ответ уже имели, прямо на себе, и этот файл держал
работу зря. Реально его ждут только: **#644** (расстановка приоритетов), **#652** (его глаза на
риге) и **#331**/**#332** (он сам поставил «не сейчас» 15.08.2026). Решены в s108: **#483**,
**#374**, **#437**; закрыта **#653**. Ответы давно лежали на карточках: **#503**, **#515**,
**#247**. **#474** отнесена к архитектурным — решать замером, не спрашивать. **#621** держится за
**#639** (условие входа: 2-3 плагина оплаты разных типов). **Отложено до релиза:** #285, #247,
остаток #567.

**Техдолг и улучшения карты (181, 152, 148, 182, 174, 173, 151) осознанно НЕ трогаем до пилотной
миграции** — пилот на живом карьере покажет, какие из этих карточек реальны, а какие мы придумали
сами. `FUTURE-BACKLOG.md` заморожен; всё остальное живёт на доске №6.

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
  | `woodev_location_default_locality_record` | the WHOLE `Location_Record` as JSON, whose `key` is `test-cdek:44` (Москва) — **not the key itself**, gotcha `the-default-locality-option-stores-a-whole-record-not-a-key` |
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

### Docker inventory — DO NOT blindly prune

- **`wordpress-test` stack** (`wordpress-test` + `wp-mysql` + `wp-phpmyadmin`, volume `wordpress-test_db_data`, ~`:8080`) is the operator's **production-plugins test instance — ALL real plugins in one env** (intentional single instance, to test plugin↔plugin compatibility). **NEVER delete it or its volume, even when its containers are `Exited`.**
- Because that volume is unattached while the stack is down, **never run `docker volume prune` / `docker system prune --volumes` here** — it would wipe `wordpress-test_db_data`. Clean docker only surgically: `docker builder prune`, `docker image prune` (dangling), and explicitly-identified orphans.
- Project wp-env = `de59f74e…` (dev `:8973` + tests `:8974`); issuer = `c8ec47a5…` (`:8090`). Both KEEP.

## Infrastructure Reference

- **Version:** `Woodev_Plugin::VERSION` (in `woodev/class-plugin.php`) = 2.0.1 (unreleased). **Raising VERSION on `main` publishes a release** — do it deliberately (#285).
- **PHP target:** 8.1 · **WP min:** 6.6 · **WC min:** 7.0
- **Tests:** Brain Monkey (unit) + WP Test Library (integration). `composer check` = phpcs + phpstan L3 + unit. JS tests must run **from bash**, not PowerShell (gotcha `powershell-drops-the-roots-flag-from-the-jest-command`).
- **CI:** GitHub Actions. **Merge PRs:** `gh pr merge <N> --squash --delete-branch` only after every job is confirmed green with state CLEAN; never `gh pr merge --auto`.
