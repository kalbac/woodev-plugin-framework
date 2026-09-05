# Current State — Woodev Plugin Framework

> **State only — never history.** Phase status, open debt, next actions, rig/infrastructure facts.
> Session history → `SESSION-LOG.md` (index, s50+) + `sessions/sNN.md` (per-session detail).
> Lessons learned → a gotcha (`gotchas/{slug}.md`) if it is about code or a mechanism, the session
> file if it is about how the work went. **Never a third copy here.**
> Program map → `specs/2026-06-25-shipping-module-decisions.md`.

**As of 2026-09-05 (s119).** `main` clean, **no open PRs, no worktrees, Инбокс EMPTY**. s119 closed **#644** whole (both remaining parts — the docs contradiction map and all 60 open cards verified against the code), plus **#113 #148 #381**; filed **#779 #780**. **58 open cards**, every one carrying a «Приоритет»; «Сейчас» is empty.

⛔ **THE PILOT IS STOPPED (operator, 05.09.2026).** s116 refactored the old plugin instead of WRITING
A NEW one on v2; post-mortem in `sessions/s116.md`. **New course: the framework is finished ON
FIXTURES**; the shipping plugin is written later, from scratch, own repo, version **2.3.0.0**
(⚠ `version_compare('2.3.0.0','2.3.2')` is LESS — valid only if 2.3.x never shipped). `#762` and
`edostavka#3/#4/#5` are FROZEN; migration branches parked, `origin/master` (`34d21af`) intact.

⚠ **When that plugin IS written, three facts decide the cost.** (1) Repointing at a v2 base costs
**11 fatals and 8 unimplemented abstracts** — run `npm run probe:signature` (#767), never a hand
count; argument and rules in [migration/signature-probe.md](migration/signature-probe.md) + gotcha
`a-stricter-base-class-fatals-on-signatures`. (2) `Shipping_Method::calculate_shipping()` is
**`final`**. (3) `register_shipping_methods()` is `final` and filters through
`is_subclass_of( $class, Shipping_Method::class )`, dropping the rest **SILENTLY** — a method left on
`WC_Shipping_Method` vanishes from checkout with no fatal and no log line.

✅ **Три субсистемы имеют ПРИНУДИТЕЛЬНЫЙ контракт сборки** (#758/#759): не построивший обработчик
уведомлений, лицензию или жизненный цикл подкласс получает `_doing_it_wrong()` под `WP_DEBUG`, а
фреймворк строит дефолт — их разыменовывают **17 / 13 / 2** раза без проверки на null. Субсистемы с
**0** незащищённых вызовов остаются опциональными.

⚠ **`test-cdek` is a client of the LIVE CDEK test contour, not a fixture dictionary** — a grep over
its file says nothing about which cities it knows (`sessions/s113.md`).

✅ **CI works and the repo is PUBLIC** (since 27.08.2026) — public repos on standard runners consume
no quota, so the s98 billing block lifted the moment it was switched. The symptom (every job failing
in two seconds with no log, which reads as a red build): **#583** + gotcha
`every-ci-job-failing-in-two-seconds-is-a-billing-block`; rule in the global `CLAUDE.md`.

**Baselines — ALL re-measured 05.09.2026 (s118) against `567218b`, sodium enabled:** unit
**3524** / 8647 / **1 skipped**; jest **1628** in **25** suites; **integration 143 / 530**; phpcs
clean — **with the warning level ON**; phpstan level 3 no errors; `lint:i18n`, `lint:mo` and
`lint:docs` OK; **e2e 7 / 7** re-run on the WP 7.1 + WC 11.1.0 rig, no longer stale.
⚠ The s117 figure «integration 138 / 522» in this file was WRONG — the handoff's 143 / 530 was right.

⚠ **`phpstan` locally needs `--memory-limit=4G`** — at 2G the parallel worker dies and prints
`Found 1 error` + "result is incomplete", which reads like a real failure. CI stays green at 2G.
Gotcha `phpstan-windows-parallel-worker-segfault`.

⚠ **Measure with `php -d extension=sodium`, or SKIPPED is meaningless** — off it reads 67, on it
reads **1 in the primary, 6 without `plugins-reference/`** (CI reports 6). Gotcha
`the-skipped-count-is-dominated-by-whether-sodium-is-enabled`.

✅ **`--order-by=reverse` is GREEN and GATED IN CI** (#606), target PHP.

✅ **`npm run test:e2e` — 7 Playwright tests against the LIVE RIG `:8973`, NOT in CI (#723)**,
~2.5 min. ⚠ Tests the WORKING TREE the rig serves, and does NOT replace his own pass.
Detail: `wiki/rig-pickup-walkthrough.md`.

✅ **Integration is the COORDINATOR's job and is not optional.** A worktree cannot run it — and the
reason is NOT «no wp-env»: the rig containers DO see worktrees (`.wp-env.json` maps the repo root and
`.orca/` sits inside it), phpunit starts there and then dies resolving the fixtures, because
`WOODEV_FRAMEWORK_DIR` points at the main checkout (measured s118). Run it from a **detached checkout
of the branch in the main tree**. `wp i18n make-mo` on a worktree's `.po`, by contrast, works fine.
jest runs from bash, never `npx jest`; `jest-unit.config.js` scopes `roots`, so a bare
`npm run test:js` is correct on its own (#188).

⚠ **A gate number copied from a previous handoff is an INFERENCE — re-measure** (s93, s100); and a
green unit suite is not sufficient where our code meets someone else's contract (gotcha
`a-mocked-provider-proves-the-mock-not-the-contract`).

**The settlement search is scoped by the region even when it came from the DEFAULT** (#551/#552);
a region whose `key()` is not in the settlement's own `ancestors()` is refused. ⚠ **Ask
`Location_Record::is_within()`, never `ancestors()` raw** — it is reflexive, and a settlement that IS
its own region publishes NO ancestors (#707, gotcha `dadata-collapses-region-and-settlement-into-one-key`).

**Open cards — 58, and PRIORITY NOW LIVES ON THE BOARD, not in this file** (operator, 04.09.2026,
#644 part 3). Board №6 field «Приоритет» (`PVTSSF_lAHOAIbGB84BeLaozhhRouo`), six values: `Сейчас`
`Следом` `Потом` `Ждёт оператора` `Заморожено` `После v2` — every open card carries one, none is
empty. Milestones: `v2.0 релиз` (#247 #285 #567) and `Пилот edostavka`. **Read the board, never a
card list retyped here** — a retyped list is exactly what went stale and got #644 filed.

**`location.levels` is a per-country matrix** (`levels[country][level]`) and the client reads it that
way; `location.countries` stays a flat chain-wide union, never naively combined with it (#289, s110).

**#621 is held behind #639**, and its cheap fix is disproven: `get_order()` must preserve the
caller's concrete order class or a `WC_Subscription` becomes a plain order (`sessions/s103.md`).

**i18n — four rules, and they live in `AGENTS.md` → Conventions, not here.** The one that is not
obvious from them: classify by the RENDER PATH, never by the file's directory (gotcha
`classify-an-i18n-string-by-its-render-path-not-its-file-path`).
**И теперь это ПРИНУЖДАЕТСЯ** (#771, s118): `lint:i18n` падает на английском msgid без перевода вне
`scripts/i18n-allowlist.json`, `lint:mo` — на `.mo`, отставшем от `.po`; оба в `ci.yml`. `.mo`
собирается ТОЛЬКО `wp i18n make-mo` в контейнере рига — рукописный компилятор даёт другой файл и
ломает инвариант готчи `the-mo-is-reproducible-from-the-po`.

**`Shipping_Plugin::includes()` АВТОРИТЕТЕН — [ADR-012](adr/012-shipping-includes-stays-authoritative.md)** (#138, s118).
Новый класс под `woodev/shipping-method/**` дописывается в него, иначе падает
`ClassMapCompletenessTest`. ⚠ Обратный сторож — **единственный** гейт на «класс есть в карте, но не
требуется»: интеграция на этом зелёная, автозагрузчик достаёт класс из `class-map.php`.

**A foreign exception's raw text is decided by WHO READS IT** (#608/#610): merchant or plugin
author → kept; customer → redacted; every LOG sink redacts unconditionally (#594). RESPONSE and NOTE
boundaries only; per-site table on the cards + `sessions/s101.md`.

**The checkout layer REPORTS a builder conflict, it does not throw** — 17 `_doing_it_wrong()`
against one `throw`, and that throw is a failed lookup. A location field's `takeover_condition` is
dropped and reported (#474, s113). An architectural card is decided by measurement, not by asking.

**The phpcs warning level is ARMED since s110 (#139)**; `[]`-only too. **Line length is the one
deliberate hole and needs its own ruleset** —
`vendor/bin/phpcs --standard=phpcs-line-length.xml --report=summary ./woodev` → **1393 in 138
files**; why it cannot be revived from the CLI: gotcha
`a-phpcs-rule-silenced-by-exclude-pattern-cannot-be-revived-from-the-cli`.

⚠ **`AGENTS.md` and this file both run near their 28 KB gates** — any addition must displace
something. That is the reading-budget gate working, not a defect.

**The checkout invariants that survive their cards** — #708: `validate()` enforces a takeover
field's `required` only when its condition owns the field AND WooCommerce rendered it. #707: ask
`Location_Record::is_within()`, never `ancestors()` raw. #709: `is_pickup_shipping()` is the single
source for the other three declarations, resolved LAZILY. **And the one that keeps costing
sessions: the «required» rule is implemented TWICE** — server `validate()` and the browser's
`refreshGate()` — so fixing one leaves the other (gotcha
`the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other`). #725 touched only the
browser half, deliberately and with a comment saying so.

**The «Place order» block is OPTIONAL** (#725, s112): checkbox «Блокировать оформление заказа»,
default ON; off makes `refreshGate()` **leave the button alone**, not force-enable it. ⚠ WooCommerce
NEVER disables that button itself. Settings section «Форма заказа», slug `checkout`.

**What closed when** is the handoff's carry-over section and the per-session files — not this file.

**Operator decisions still shaping the work:**

- *Хэндшейк-секрет остаётся в URL при редиректе на woodev.ru* (#382, 05.09.2026). В адресную
  строку едет РАЗОВЫЙ секрет — 15 минут, привязан к `state` + `user_id`, гасится после обмена
  (`class-account-connection.php:469-478`); долгоживущий `access_token_secret` приходит POST'ом и в URL
  не попадает никогда. PKCE рассмотрен и отклонён: цена — правка протокола на двух сторонах.

- *Настройки плагина по умолчанию — на `Woodev → Настройки`; вкладка WooCommerce «Интеграции»
  НЕ отменена и используется при необходимости* (#777, 05.09.2026). Швы, контракт хранилища и
  запрет выдумывать границу за него — `AGENT-RULES.md` Rule 8.

- *We offer narrowing, we never force it; the merchant's only switch is the region field itself*
  (#437). Surviving rules from #531/#542: `guard_custom_settlement()` below, and the `src/`
  TypeScript row in `AGENTS.md`.

**FIRST vendored runtime JS in the framework: IMask, pinned, for the checkout phone mask.** Its
country table is GENERATED (`npm run generate:phone-masks`, `lint:phone-masks` fails when stale);
libphonenumber is a devDependency and must never be enqueued; adding a country is one ISO code, never
a typed template. **[ADR-011](adr/011-vendored-imask-and-generated-phone-masks.md)** + gotcha
`a-hand-typed-format-table-drifts-from-the-real-spec`.

**No jargon in merchant-facing copy** — rule in `AGENTS.md`. **TS is scoped to `src/` only** (#542),
never the raw-served frontend.

**#528 «Города вне списка»** — default OFF, only for «Список с поиском»; ON → select2 `tags`, OFF →
#517's abandon mechanism gated off.

**`select2:close` fires BEFORE `select2:select`** — any guard shaped as "the pick will cancel the
close" cannot work. Gotcha `select2-close-fires-before-select2-select`.

## Contracts, traps and tooling — pointers only

**The checkout location layer's contract facts moved to
[wiki/architecture.md](wiki/architecture.md) in s119 (#778)** — `resolve_key()`'s `null`,
`compose(...parse())` not being the identity, `set_label()` on native fields, the ownership guard,
and the «required» rule's two halves. They are reference: true regardless of which card is open.

**Open architectural question in that layer: #474** — "a location field is never a takeover field"
is an UNENFORCED invariant. **Decide it by measurement** (s108/s110), not by asking. Everything else
open in the layer is on the board; do not retype a card list here — that is what got #644 filed.

**Rule 7 (which checkout column the cascade attaches to) has three parts and lives in
`AGENT-RULES.md`**, 7c settled 24.08.2026 (#475), with five `file:line` citations in the rule
itself. Do not summarise it here; 7b was re-litigated once already because it lived only in a
session file.

**⚠ The ONE tooling number to carry: compare SKIPPED, not assertions** — and only with sodium
enabled, where the primary checkout reads **1** and any checkout without `plugins-reference/` reads
**6** (gotchas `a-worktree-silently-skips-five-contract-tests`,
`the-skipped-count-is-dominated-by-whether-sodium-is-enabled`; the old "66" was never a contract).
Every other trap — worktrees, jest/PowerShell, Codex under Orca, stacked-PR merges, integration
flakiness, the three field modes and their Russian labels — is one line under the `[tooling/*]`,
`[testing/*]` and `[rig/*]` tags of `GOTCHAS.md`, which is read at session start anyway.

⚠ Before probing `test-cdek` credentials, read gotcha
`the-cdek-fixture-credentials-are-not-the-option-they-look-like` — the obvious option is a decoy.

**`@since` = the PLANNED release `2.0.2`; `VERSION` = the released one and lags on purpose**
(#409, #546; full rule in `AGENT-RULES.md` Rule 5, which also covers INHERITED code → `1.0.0`).
Nothing above `2.0.2` remains — #116(a) closed that in s111, and `SinceTagCeilingTest` now gates it.

**Agents and Orca — the recipes, the caps and the launch traps are
[wiki/orchestrating-agents-with-orca.md](wiki/orchestrating-agents-with-orca.md).** The two facts
worth carrying without opening it: a fresh worktree needs **no install step** but its `vendor` must
be COPIED and never shared, and it starts dirty with seven CRLF-only files — **never `git add -A`
there**, and remove the worktree through Orca.

**Building a rate? Read the two `[woocommerce/shipping]` gotchas from s117 first** — `add_rate()`
silently ignores `description`/`delivery_time`, and stringifying a numeric cost lets
`wc_format_decimal()` turn `1.0e20` into `1.02`.

Gotchas: **279**.

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

**The per-subsystem matrix moved to [wiki/architecture.md](wiki/architecture.md) in s119 (#778)** —
it changes once every several sessions, which makes it reference. Every subsystem is ✅ in code, and
all but PHPStan and Documentation are browser-verified. The live PROGRAMME stage is the table above.

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

⛔ **ПИЛОТ ОСТАНОВЛЕН** (оператор, 05.09.2026; **#762** ЗАМОРОЖЕНА). Доводим фреймворк **на
фикстурах**; боевой плагин пишется позже и с нуля. Заморозку карточек карты снимет слой ПВЗ нового
плагина. Следующее берётся с доски по полю «Приоритет», сверив карточку с кодом ДО взятия.

**Списков карточек этот файл больше не держит — они на доске, поле «Приоритет».** Именно
пересказанные здесь списки устаревали молча; ради этого и заведена #644. `FUTURE-BACKLOG.md`
заморожен.

## 🔔 Cross-Project Reminder — Ecosystem Orchestration (dormant)

- **Trigger:** v2.0.0 shipped AND stable in production for several weeks. When it fires, surface it in the session-opening summary; do **NOT** auto-start; point the operator to the spec and read its "Prompt for the Future Agent" section first.
- **Spec:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`.

## Local rig

**How to operate it — the carrier table, the standard option values, the two environments, the
shell recipes and the timings — is [wiki/local-rig.md](wiki/local-rig.md) → "Operating the rig".**
Moved there in s119 (#778). What stays here is what changes between sessions, plus the two things
that must never be missed.

- ✅ **WordPress 7.1 + WooCommerce 11.1.0** since 05.09.2026 (s117), dev `:8973` / tests `:8974`.
  Integration re-run green on that stack; rig state survived byte-identical.
- ✅ **At standard, re-verified 02.09.2026 (s112)** — modal, map, tiles and clustered Moscow points.
  **Two carriers side by side** since s112 (#734/#735), the first on live Yandex by operator
  decision (#734).
- **Tree is on `main`** (verified 27.08.2026, s100); Orca worktrees removed.
- ⛔ **Never `docker volume prune` / `docker system prune --volumes` on this machine.** The
  operator's `wordpress-test` stack holds ALL real plugins in one env and its volume sits unattached
  while the stack is `Exited` — a prune wipes it. Inventory: [wiki/local-rig.md](wiki/local-rig.md).
- ⛔ **Issuer `:8090` — KEPT, do NOT touch.** The operator uses it independently.
- ⚠ **The rig serves the WORKING TREE.** Name the branch out loud, switch the tree BEFORE asking
  anyone to look, and leave it there until the pass is over — s92 switched back «for tidiness» and
  cost the operator a whole pass. Confirm by measurement, not by intention:
  `grep -c "<a symbol the fix introduces>" <the served file>`. Gotcha
  `rig-serves-the-working-tree-branch-switch-reverts-fixes`.
- ⚠ **The picker lives on `/classic-checkout/`, NOT `/checkout/`** — the latter is the BLOCK
  checkout (SP-11 unbuilt), with no `form.checkout` and no trigger, which reads as a broken build
  rather than the wrong URL. Cart: `?add-to-cart=12`. Gotcha
  `rig-checkout-url-is-the-block-checkout`.
- Going to the rig for the pickup layer? The order matters or the button is simply absent:
  [wiki/rig-pickup-walkthrough.md](wiki/rig-pickup-walkthrough.md).
## Infrastructure Reference

- **Version:** `Woodev_Plugin::VERSION` (in `woodev/class-plugin.php`) = 2.0.1 (unreleased). **Raising VERSION on `main` publishes a release** — do it deliberately (#285).
- **PHP target:** 8.1 · **WP min:** 6.6 · **WC min:** 7.0
- **Tests:** Brain Monkey (unit) + WP Test Library (integration). `composer check` = phpcs + phpstan L3 + unit. JS tests must run **from bash**, not PowerShell (gotcha `powershell-drops-the-roots-flag-from-the-jest-command`).
- **CI:** GitHub Actions. **Merge PRs:** `gh pr merge <N> --squash --delete-branch` only after every job is confirmed green with state CLEAN; never `gh pr merge --auto`.
