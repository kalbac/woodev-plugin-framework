# Current State — Woodev Plugin Framework

> **State only — never history.** Phase status, open debt, next actions, rig/infrastructure facts.
> Session history → `SESSION-LOG.md` (index, s50+) + `sessions/sNN.md` (per-session detail).
> Lessons learned → a gotcha (`gotchas/{slug}.md`) if it is about code or a mechanism, the session
> file if it is about how the work went. **Never a third copy here.**
> Program map → `specs/2026-06-25-shipping-module-decisions.md`.

**As of 2026-10-06 (s154).** ✅ SP-11 tails #1107 #1110 #1111 #1113 merged (PR #1120, `8c3ccec8`, accepted by the operator on the rig). #1113 settled as variant A (postcode stays required on blocks). Accepted residuals: #1118, #1119. Next: #1121 (blocks compatibility by default for shipping plugins). Earlier: #1102 #1091 #1101 (s153), #1096 #1100 #1090 (s152). Details: `sessions/s154.md`. GlitchTip receiver (#1082) is live. ✅ **SP-10 is COMPLETE** (#820, s137): a carrier's legacy v1
orders-page slug redirects to the framework page with that carrier preselected, accepted on the rig.
✅ **Both machines are live**; routine sync is git ONLY (`wiki/two-machine-setup.md`). The order
metabox (#856) and the orders table (#870) stay accepted.

✅ **Fixtures carry real download ids `9001`-`9005`** (#910), so `LicenseCommandEndpointTest` drives
the real signed-envelope route. The admin license REST route refuses an ambiguous id (#907) but
**NOT** an id without a store product — registration retains such an engine, deliberately; the
divergence is stated in `resolve_license()`'s docblock.

✅ **#928 CLOSED (s140): the orders page resolves its marker scope to ids and passes `post__in`** (`Orders_Id_Resolver`; 4 carriers × 10 k orders 11.7 s → 13 ms; empty list → NO_MATCH). Detail: `sessions/s140.md`, gotcha `an-or-of-exists-meta-clauses-joins-the-meta-table-once-per-key-unpredicated`; frozen follow-up #940.

⚠ **Addressing a `meta_query` part by "has a `relation` key" cannot tell the `AND` wrapper from a single
unwrapped part** — gotcha `a-relation-key-does-not-tell-the-and-wrapper-from-a-single-meta-query-part`.

⚠ **Four ways a UI change passes every gate and is still wrong** — a GREP'd vendor rule, a green
build that committed no bundles, a row rebuilt after an action (legacy CPT only), a probe whose
selector matches nothing. One line each under the `[rig/*]` and `[build/*]` topic indexes.

⛔ **The operator reordered the work, 12.09.2026, reconfirmed 13.09** — *«пока у нас не будет готов
базовый минимум самого фреймворка, мы плагин не пилим»*. **#786 is OUT of the queue** («Заморожено»).
**«Base minimum» = milestone «v2.0 релиз» (operator, s150):** #1078 (SP-11: C-1 #1087, C-2a #1088, C-2b #1089, C-3 #1090, #1096, #1100, #1102, #1091 C-4, #1101 done; boundaries #1107 #1110 #1111 #1113 merged in PR #1120 (s154), #1108 #1109 #1112 #1114 on the board), then #247 #285 #567 last; #947 #948 #130 #1081 #1093 done. `docs/` after the plugin; #621 behind #639; reporter receiver = GlitchTip (#1082: deployed s152; DSN baked into each plugin; mail via Yandex SMTP pending on him).
Next: see `next-session-prompt.md`. The «new» scope + badge = unexported ∩ `EXPORTABLE_STATUSES` (#1024, his decision). Codex: default `gpt-6-luna`, hard tasks `gpt-6.1-sol` (operator, 01.10.2026; `CLAUDE.md`). Codex is back in use (operator, 29.09.2026): **1 of 3 usage-limit resets spent** (none spent in s146), recipe in gotcha `starting-codex-under-orca-needs-four-steps-not-one` — on 0.158 the report-FILE path works, `worker_done` never comes.

✅ **CI first-try reliability is ENFORCED** (#871): `.githooks/pre-push` rebuilds the bundles and
runs the catalogue gates by exit code in ~22 s — a worker may not build bundles while
`lint:i18n-sources`/`lint:i18n` read msgids out of the built one. Cause: `sessions/s134.md`.

⚠ **Two integration runs at once share ONE test database**; the loser reports YOUR code failing —
forbid the suite in every brief (gotcha `two-concurrent-integration-runs-share-one-test-database`).

⚠ **The orders page's own contract facts live in [wiki/architecture.md](wiki/architecture.md)** —
where it is mounted, how it highlights its menu item, which `window.wc.*` handles it declares by hand
and why every filter is URL-driven. They are reference: true regardless of which card is open.

⚠ **Do NOT ask the operator to log into the rig — a Playwright probe logs in itself**
(`admin`/`password`). For numbers without a browser use `wp eval` in the container, but **only the
browser catches a client defect** (s128). ⛔ **And no screenshots for him** — UI acceptance is
«готово, смотри риг»; artifacts live in temp and are deleted (operator, 13.09.2026).
⚠ The rig runs **`WPLANG=en_US`** — English chrome is the LOCALE, not a defect.
⚠ The rig theme is **Storefront** (s147, his choice). **We do not restyle WP/WC defaults** — gotcha `short-select2-fields-on-storefront-are-woocommerce-s-not-ours`.

⛔ **THE PILOT IS STOPPED (operator, 05.09.2026).** s116 refactored the old plugin instead of WRITING
A NEW one on v2; post-mortem in `sessions/s116.md`. **New course: the framework is finished ON
FIXTURES**; the shipping plugin is written later, from scratch, own repo, version **2.3.0.0** —
✅ **SETTLED 06.09.2026:** nothing above **`2.2.5.5`** is installed anywhere, so
`version_compare('2.3.0.0','2.2.5.5')` = GREATER and the update reaches every site. `#762` and
`edostavka#3/#4/#5` are FROZEN; migration branches parked, `origin/master` (`34d21af`) intact.

⚠ **Three facts decide that plugin's cost when it IS written** — on #786, with #767's measurement.

⚠ **`test-cdek` is a client of the LIVE CDEK contour, not a dictionary** — a grep over it says nothing about which cities it knows (`sessions/s113.md`).

✅ **CI works and the repo is PUBLIC** (since 27.08.2026) — no quota consumed; the exhaustion symptom is gotcha `every-ci-job-failing-in-two-seconds-is-a-billing-block`.

**`main` after s149 (`ef7a5ea1`, macOS, 03.10.2026):** unit **5241 / 29902** (1 skipped), jest **2540** in **53** suites; integration local **475 / 6432**.

**Baselines — macOS laptop, 27.09.2026 (s140)** (the two machines matched to the digit in s136, so
these are not platform-dependent): unit **4132 / 14586**, 1 skipped, sodium ON; jest **1975** in
**33** suites; **integration 200 / 730**; e2e **7** in 13 s; `npm run build` zero git diff; phpcs
clean **with the warning level ON**; phpstan level 3 no errors. ⚠ A number copied from a handoff is an INFERENCE — re-measure.

⚠ **A `.ts` msgid fails `lint:i18n-sources`** — it extracts from the BUILT bundle (s129).

⚠ **Integration only runs INSIDE the container**; `composer test:integration` on the host dies with
`Class "WP_UnitTestCase" not found`, which reads like a bootstrap regression and is not one. The
command is in gotcha `wpenv-windows-gitbash-path-mangling`.

⚠ **`phpstan` locally needs `--memory-limit=4G`** — at 2G the parallel worker dies printing `Found 1 error` + "result is incomplete", which reads like a real failure. CI stays green at 2G. Gotcha `phpstan-windows-parallel-worker-segfault`.

⚠ **Measure with `php -d extension=sodium`, or SKIPPED is meaningless** — 1 in the primary, 6 without `plugins-reference/`. Gotcha `the-skipped-count-is-dominated-by-whether-sodium-is-enabled`.

✅ **`npm run test:e2e` — 7 Playwright tests against the LIVE RIG `:8973`, NOT in CI (#723)**, ~2.5 min. ⚠ Tests the WORKING TREE the rig serves, and does NOT replace his own pass.

✅ **Integration is the COORDINATOR's job and is not optional.** A worktree cannot run it, and the
reason is NOT «no wp-env»: phpunit starts there and dies resolving fixtures, because
`WOODEV_FRAMEWORK_DIR` points at the main checkout (measured s118). Run it on the branch **checked
out in the main tree, inside the `tests-cli` container** — exact command in the gotcha named above.
jest runs from bash, never `npx jest`; `jest-unit.config.js` scopes `roots`, so a bare
`npm run test:js` is correct on its own (#188).

⚠ **A gate number copied from a handoff is an INFERENCE — re-measure** (s93, s100, s120); and a green
unit suite is not sufficient where our code meets someone else's contract (gotcha
`a-mocked-provider-proves-the-mock-not-the-contract`).

**The settlement search is scoped by the region even when it came from the DEFAULT** (#551/#552) — a
region whose `key()` is not in the settlement's own `ancestors()` is refused. ⚠ **Ask
`Location_Record::is_within()`, never `ancestors()` raw** — it is reflexive, and a settlement that IS
its own region publishes NO ancestors (#707, gotcha `dadata-collapses-region-and-settlement-into-one-key`).

**Open cards — 50, measured 02.10.2026 (s149):** Инбокс holds only **#922** (Supermemory, parked by his word).
⚠ Count with
`gh issue list --limit 300` and `project item-list --limit 1000` — s127's 53 was an undercount from
exactly that trap, and a naive reader reports a milestone-carrying card as empty. **PRIORITY LIVES ON
THE BOARD, not in this file** (operator, 04.09.2026, #644 part 3); its field and option ids are in
`AGENTS.md` → Backlog rule, and every open card carries one.
**`V2 готов` = #786 works** (operator, 07.09.2026) — the gate #247/#285 wait on; #567 was moved
AHEAD of the plugin by that same decision. **Read the board, never a card list retyped here** — a
retyped list is what went stale and got #644 filed.

**`location.levels` is a per-country matrix** (`levels[country][level]`) and the client reads it that
way; `location.countries` stays a flat chain-wide union, never naively combined with it (#289).

**#621 is held behind #639**, and its cheap fix is disproven: `get_order()` must preserve the
caller's concrete order class or a `WC_Subscription` becomes a plain order (`sessions/s103.md`).

**i18n — четыре правила живут в `AGENTS.md` → Conventions, не здесь.** Неочевидное из них:
классифицировать по ПУТИ ОТРИСОВКИ, а не по каталогу файла (готча
`classify-an-i18n-string-by-its-render-path-not-its-file-path`). ⚠ **Плюс: русский msgid во
множественном числе обязан нести ВСЕ ТРИ формы `msgstr`, а в JS `_n()` не чинится вовсе** — готча
`russian-source-i18n-plural-n`, переписана в s134 замером.
**Принуждается ЧАСТИЧНО** (#771, #791, #800): `lint:i18n` падает на английском msgid без перевода
вне `scripts/i18n-allowlist.json`, `lint:mo` — на отставшем `.mo`, `lint:i18n-sources` гоняет
`make-pot` по `woodev/` и требует каждый msgid и в `.pot`, и в `.po` (ОДНОСТОРОННЕ; wp-cli
приколочен на 2.12.0). ⚠ `.mo` собирается ТОЛЬКО `wp i18n make-mo` в контейнере рига, а `update-po`
сносит хвост `#~` — готчи `the-mo-is-reproducible-from-the-po`,
`a-po-merge-that-drops-obsolete-entries-still-looks-well-formed`,
`lint-i18n-answers-about-the-catalogue-not-the-code`.
⚠ JS-бандлы читают переводы из handle-named JSON, не из `.mo` (`lint:js-i18n`, #1032) — готча `js-translations-are-handle-named-json-files`.
⛔ **Остаток #567 — визуальный проход по переводам — ГЕЙТОВАН РЕЛИЗОМ, не ответом оператора**
(05.09.2026, повторено 07.09): перед релизом каталог всё равно проходят целиком, а строки до того ещё
изменятся. Код и каталог закрыты, карточка «Заморожено» с этим условием. Не переоткрывать.

**Фичу метода доставки можно объявить ОБОИМИ способами** — `$this->supports` до `parent::__construct()` (#811) и `add_support()` после (#813); до s124 не работал ни один. Готча `a-base-constructor-that-assigns-what-the-subclass-just-set`.

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

⚠ **`AGENTS.md` and this file both run near their 28 KB gates** — any addition must displace something.

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

**A checkout renderer's `detach()` unbinds and cancels NOTHING** (#573, s120) — the cascade counts
PICKS and asks `release.isStale()`; the busy token is the WRONG key for that question. Full detail:
gotcha `a-detach-that-only-unbinds-still-writes-through-whatever-was-in-flight`.

⚠ Кнопка экспорта — «Экспорт» (оператор, 13.09.2026, #890). Где объявлен набор действий и как ставится пункт меню — [wiki/architecture.md](wiki/architecture.md).

✅ **На риге ДВА перевозчика, ~294 заказа.** ⚠ **Агрегат с ОДНИМ источником — не малое N, а другая форма:** это скрывало два дефекта подряд (s127, s128). Любой тест на агрегат регистрирует минимум двух перевозчиков. ⚠ Сеялки ДОБАВЛЯЮТ, а не досевают (#868): каждая новая колонка вскрывает, что старые строки её не несут — так было с покупателем (#861) и с методом оплаты (#876).

**Operator decisions still shaping the work:**

- *Легаси-CPT датастор ОСТАЁТСЯ поддержанным* (#839, 26.09.2026) — «ещё много сайтов
  сидят на нём». HPOS-only отклонён, значит **#928 обязан работать на ОБОИХ хранилищах.**

- *У заказа НЕ БОЛЬШЕ ОДНОГО маркера перевозчика — YAGNI* (26.09.2026): «на практике нет; для этого
  нужна реализация мультидоставки, которой у нас нет». Это **разблокировало дешёвую форму #928**, решило
  **#924** («оставить инвариант и записать»), закрыло **#929**. ⚠ Код его НЕ обеспечивает (`edostavka`
  пишет маркер на каждый пакет), поэтому сторож `_doing_it_wrong()` под `WP_DEBUG` (#932, s140) сообщает о
  таком заказе.

- *Хэндшейк-секрет остаётся в URL при редиректе на woodev.ru* (#382) — он РАЗОВЫЙ (15 минут,
  привязан к `state` + `user_id`); долгоживущий `access_token_secret` идёт POST'ом, PKCE отклонён.

- *Настройки плагина по умолчанию — на `Woodev → Настройки`; вкладка WooCommerce «Интеграции»
  НЕ отменена и используется при необходимости* (#777) — швы и границы: `AGENT-RULES.md` Rule 8.

- *We offer narrowing, we never force it; the merchant's only switch is the region field itself*
  (#437).

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
`[testing/*]` and `[rig/*]` topic indexes, mapped from `GOTCHAS.md`.

⚠ Before probing `test-cdek` credentials, read gotcha
`the-cdek-fixture-credentials-are-not-the-option-they-look-like` — the obvious option is a decoy.

**`@since` = the PLANNED release `2.0.2`; `VERSION` = the released one and lags on purpose**
(#409, #546; full rule in `AGENT-RULES.md` Rule 5, which also covers INHERITED code → `1.0.0`).
Nothing above `2.0.2` remains — #116(a) closed that in s111, and `SinceTagCeilingTest` now gates it.

**Agents and Orca — the recipes, the caps and the launch traps are
[wiki/orchestrating-agents-with-orca.md](wiki/orchestrating-agents-with-orca.md).** The two facts
worth carrying without opening it: a fresh worktree needs **no install step** but its `vendor` must
be COPIED and never shared; stage files by name, **never `git add -A`** there (the CRLF-dirty start
ended in s135), and remove the worktree through Orca.

**Building a rate? Read the two `[woocommerce/shipping]` gotchas from s117 first** — `add_rate()`
silently ignores `description`/`delivery_time`, and stringifying a numeric cost lets
`wc_format_decimal()` turn `1.0e20` into `1.02`.

Gotchas: count in the `GOTCHAS.md` header.

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

- [⚠️] `class-payment-gateway.php` ~3.6k lines — trait-extraction candidate (#117).
- **B-2 loader-protocol forward-tolerance:** the resolver loads framework classes from the **highest registered copy for the whole fleet**; `backwards_compatible` deactivates-with-notice any plugin below that copy's min. Rules → `AGENT-RULES.md` Rule 3.
- [ℹ️] OB-7 moved to the board as **#809** (07.09.2026) — debt lives there, not here.
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
- **Two machines since s135** (Windows desktop + macOS laptop): [wiki/two-machine-setup.md](wiki/two-machine-setup.md).
  ⏸ **Desktop rig STOPPED** 13.09.2026 (`wp-env stop`, volumes kept) — start it before use.
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
