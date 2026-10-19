# Current State — Woodev Plugin Framework

> **State only — never history.** Phase status, open debt, next actions, rig/infrastructure facts.
> Session history → `SESSION-LOG.md` (index, s50+) + `sessions/sNN.md` (per-session detail).
> Lessons learned → a gotcha (`gotchas/{slug}.md`) if it is about code or a mechanism, the session
> file if it is about how the work went. **Never a third copy here.**
> Program map → `specs/2026-06-25-shipping-module-decisions.md`.

**As of 2026-10-20 (s188, hotfix day with the operator):** released and verified — framework `v2.0.5` (after `v2.0.4`), CDEK `v2.3.0.2` (edostavka `v2` `c43f30e`), Yandex `v2.0.2` (yandex `main` `6e8a7a1`); Yandex `v2.0.3` (map fallback key, yandex#127) — see `next-session-prompt.md` for whether it is merged/released. EDD upload is his (2.3.0.2 / 2.0.3 ZIPs on his Desktop). Open from his demo pass: #1367 #1368 #1370 (Следом), #1369 (Потом); also #1357 → #1169. Detail: `sessions/s188.md` → «Hotfix day».
**Before that — as of 2026-10-19 (s188, releases published on his word):** framework `v2.0.3`, CDEK `v2.3.0.0` (edostavka `v2` `cd26eda`, after
#121 v1 fixed-price migration) and Yandex `v2.0.0` (yandex `main` `c361dec`) are tagged and released on GitHub with the release ZIPs
(the plugin `release.yml` runs failed on the Actions billing block, so he ran `gh release create` with the Desktop ZIPs — same
sha256). Open: yandex#123 (reverses s16 — his call), #1357 then #1169, EDD upload + posts 02–04 (his). Detail: `sessions/s188.md`.
**Before that — as of 2026-10-19 (s187, with the operator):** everything that must precede the plugin releases is PUBLISHED on woodev.ru — blog
145839 (`/woodev-new-foundation.html`, cover «Woodev 2.0»), docs `plugins-update` (new), `woodev-plugins-basics`, `cdek-plugin-setup`,
`cdek-migration`, `yandex-delivery-migration`, and 48467 rewritten in place with the 145849 text; BetterDocs FAQ groups «Плагин СДЭК» (58),
«Плагин Яндекс Доставка» (90), «Общие вопросы» (63) rewritten. The warm-up Telegram post is scheduled by him for 19.10 10:00 MSK. Next: the
CDEK 2.3 and Yandex 2.0 releases, then posts 02–04. Detail: `sessions/s187.md`.
**Earlier sessions:** see `SESSION-LOG.md` (s186 `sessions/s186.md`).

✅ **Fixtures carry real download ids `9001`-`9005`** (#910), so `LicenseCommandEndpointTest` drives
the real signed-envelope route. The admin license REST route refuses an ambiguous id (#907) but
**NOT** an id without a store product — registration retains such an engine, deliberately; the
divergence is stated in `resolve_license()`'s docblock.

⚠ **Addressing a `meta_query` part by "has a `relation` key" cannot tell the `AND` wrapper from a single
unwrapped part** — gotcha `a-relation-key-does-not-tell-the-and-wrapper-from-a-single-meta-query-part`.

⚠ **Four ways a UI change passes every gate and is still wrong** — a GREP'd vendor rule, a green
build that committed no bundles, a row rebuilt after an action (legacy CPT only), a probe whose
selector matches nothing. One line each under the `[rig/*]` and `[build/*]` topic indexes.

✅ **Base minimum is complete; SP-11 (#1078) is closed.** The current carrier work and
release checks are in `next-session-prompt.md`; priorities live on the board. The «new» orders
scope and badge mean unexported ∩ `EXPORTABLE_STATUSES` (#1024). Codex model rules live in
`CLAUDE.md`; launch and durable report-file fallback live in
`wiki/orchestrating-agents-with-orca.md`.

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

⛔ **The old migration pilot (#762) remains frozen.** It does not describe the current
CDEK v2 plugin work; use the active handoff and the board for that work. The new plugin ships as `2.3.0.0` — above
the highest installed `2.2.5.5` (operator, 06.09.2026), so the update reaches every site; spec `specs/2026-10-06-cdek-v2-plugin-design.md`.

⚠ **`test-cdek` is a client of the LIVE CDEK contour, not a dictionary** — a grep over it says nothing about which cities it knows (`sessions/s113.md`).

✅ **CI works and the repo is PUBLIC** (since 27.08.2026) — no quota consumed; the exhaustion symptom is gotcha `every-ci-job-failing-in-two-seconds-is-a-billing-block`.

**Test measurements:** framework unit **7398** / jest **3409** on main after #1305 (CI green on every merged head); full e2e on minified assets **45 passed / 6 failed (#1306, pre-existing) / 1 flake / 3 skipped**; Yandex unit **1159** (yandex#122) / **1160** (#123); CDEK unit **1565** (edostavka#120) / **1576** (#121); CDEK acceptance **98–99 / 1 / 11–12 / 4 BLOCKED** — a different block-checkout pickup case fails each run (#1357), measured 19.10.2026 (s188; was 105/0/12 on 15.10). After #1311 the unit suite skips **1** (was 6) — the Yandex/CDEK contract tests now run on
committed fixtures (s183).
Integration is CI/coordinator-owned. Re-measure before quoting these as current gate evidence.

⚠ **A `.ts` msgid fails `lint:i18n-sources`** — it extracts from the BUILT bundle (s129).

⚠ **Integration only runs INSIDE the container**; `composer test:integration` on the host dies with
`Class "WP_UnitTestCase" not found`, which reads like a bootstrap regression and is not one. The
command is in gotcha `wpenv-windows-gitbash-path-mangling`.

⚠ **`phpstan` locally needs `--memory-limit=4G`** — at 2G the parallel worker dies printing `Found 1 error` + "result is incomplete", which reads like a real failure. CI also requests 4G in `.github/workflows/ci.yml`. Gotcha `phpstan-windows-parallel-worker-segfault`.

⚠ **Check `extension_loaded('sodium')` before interpreting SKIPPED** — as measured in s138, 1 in the primary and 6 without `plugins-reference/`. Windows needs `-d extension=sodium`; Homebrew PHP already has it compiled in. Gotcha `the-skipped-count-is-dominated-by-whether-sodium-is-enabled`.

✅ **`npm run test:e2e` runs Playwright against the LIVE RIG `:8973`, outside CI (#723).** ⚠ Tests the WORKING TREE the rig serves, and does NOT replace his own pass.

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

**Open issues — 55, measured 12.10.2026 (s171):** board Инбокс has **1** item, **#922** (Supermemory, parked by his word).
⚠ Count with
`gh issue list --repo kalbac/woodev-plugin-framework --state open --limit 500 --json number` and `project item-list --limit 1000` — s127's 53 was an undercount from
exactly that trap, and a naive reader reports a milestone-carrying card as empty. **PRIORITY LIVES ON
THE BOARD, not in this file** (operator, 04.09.2026, #644 part 3); its field and option ids are in
`AGENTS.md` → Backlog rule, and every open card carries one.
**`V2 готов` = two carrier plugins on v2, tested and compatible** (operator, 08.10.2026, #1179) — met; #567, #247, #285 done and 2.0.2 released in s182. **Read the board, never a card list retyped here** — a
retyped list is what went stale and got #644 filed.

**`location.levels` is a per-country matrix** (`levels[country][level]`) and the client reads it that
way; `location.countries` stays a flat chain-wide union, never naively combined with it (#289).

**#621 is held behind #639**, and its cheap fix is disproven: `get_order()` must preserve the
caller's concrete order class or a `WC_Subscription` becomes a plain order (`sessions/s103.md`).

**i18n rules live in `AGENTS.md` → Conventions.** Classify strings by their render path,
not their directory (`classify-an-i18n-string-by-its-render-path-not-its-file-path`). Russian
plural msgids need all three `msgstr` forms; in JS `_n()` cannot be fixed at all — do not rely on it
(`russian-source-i18n-plural-n`). Enforcement is partial: `lint:i18n` checks untranslated
English entries outside `scripts/i18n-allowlist.json`; `lint:mo` checks compiled freshness;
`lint:i18n-sources` requires extracted msgids in both `.pot` and `.po` (one-way, wp-cli 2.12.0).
Build `.mo` with `wp i18n make-mo` in the rig container; `update-po` can drop the obsolete
`#~` tail. Details: `the-mo-is-reproducible-from-the-po`,
`a-po-merge-that-drops-obsolete-entries-still-looks-well-formed`,
`lint-i18n-answers-about-the-catalogue-not-the-code`. JS bundles read handle-named JSON,
not `.mo` (`lint:js-i18n`, #1032; `js-translations-are-handle-named-json-files`).

**#567 visual translation review: DONE (s182)** — framework catalogue fresh (all four i18n gates green); the operator's ru_RU pass found only the Yandex plugin's missing catalogue (fixed, yandex#104). Every plugin needs a storefront `languages/*.po/.mo` + a catalogue-coverage test (skill `woodev-plugin-core` → services.md).

**Shipping method features support both declarations:** `$this->supports` before
`parent::__construct()` and `add_support()` afterwards. See
`a-base-constructor-that-assigns-what-the-subclass-just-set`.

**`Shipping_Plugin::includes()` is authoritative — [ADR-012](adr/012-shipping-includes-stays-authoritative.md).**
Add new shipping classes there, or `ClassMapCompletenessTest` fails. The reverse guard is the **only** gate on
"in the map but never required": integration stays green on it, because the autoloader resolves the class from `class-map.php`.

**A foreign exception's raw text is decided by WHO READS IT** (#608/#610): merchant or plugin
author → kept; customer → redacted; every LOG sink redacts unconditionally (#594). RESPONSE and NOTE
boundaries only; per-site table on the cards + `sessions/s101.md`.

**The checkout layer reports a builder conflict**; a failed lookup may throw. A location field's `takeover_condition` is
dropped and reported (#474, s113). An architectural card is decided by measurement, not by asking.

**The phpcs warning level is ARMED (#139)** — locally and in CI since #1235 (s176); `[]`-only too. **Line length is the one
deliberate hole and needs its own ruleset** —
`vendor/bin/phpcs --standard=phpcs-line-length.xml --report=summary ./woodev`; why it cannot be revived from the CLI: gotcha
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

⚠ The export button is «Экспорт» (operator, 13.09.2026, #890). Action/menu declarations live in
[wiki/architecture.md](wiki/architecture.md).

✅ **The rig has two carrier fixtures; the order count is mutable.** Aggregate tests need
at least two carriers: one source exercises a different shape. Seeders add new rows; they
do not backfill existing ones (#868), so a new column needs representative rows carrying it.

**Operator decisions still shaping the work:**

- Legacy CPT storage remains supported (#839): «ещё много сайтов сидят на нём».
  HPOS-only was rejected; order operations must work with both stores.

- At most one carrier marker per order remains the declared invariant (#924). The code
  does NOT enforce it — it only reports violations through `_doing_it_wrong()` under `WP_DEBUG` (#932).
  Operator (26.09.2026, YAGNI): «на практике нет; для этого нужна реализация мультидоставки, которой у нас нет».
  Details: gotcha `the-orders-filter-stands-on-two-unenforced-carrier-invariants`.

- The handshake secret remains in the redirect URL (#382): one use, 15-minute lifetime,
  bound to `state` + `user_id`; the long-lived `access_token_secret` travels by POST.
  PKCE was rejected.

- Default plugin settings live on `Woodev → Настройки`; WooCommerce «Интеграции» remains
  available when needed (#777; `AGENT-RULES.md` Rule 8).

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

**The #474 ownership invariant is enforced:** a location field’s `takeover_condition` is
dropped and reported. Current open work lives on the board.

**Rule 7 (which checkout column the cascade attaches to) has three parts and lives in
`AGENT-RULES.md`**, 7c settled 24.08.2026 (#475), with five `file:line` citations in the rule
itself. Do not summarise it here; 7b was re-litigated once already because it lived only in a
session file.

**⚠ Compare SKIPPED, not assertions** — with sodium enabled, the s138 baseline was
**1** with `plugins-reference/` and **6** without it; re-measure before using those as current counts (gotchas `a-worktree-silently-skips-five-contract-tests`,
`the-skipped-count-is-dominated-by-whether-sodium-is-enabled`; the old "66" was never a contract).
Every other trap — worktrees, jest/PowerShell, Codex under Orca, stacked-PR merges, integration
flakiness, the three field modes and their Russian labels — is one line under the `[tooling/*]`,
`[testing/*]` and `[rig/*]` topic indexes, mapped from `GOTCHAS.md`.

⚠ Before probing `test-cdek` credentials, read gotcha
`the-cdek-fixture-credentials-are-not-the-option-they-look-like` — the obvious option is a decoy.

**`@since` ceiling:** `composer.json` → `extra.woodev.planned-release` currently says `2.0.3`; the released `VERSION` is `2.0.2`. `SinceTagCeilingTest` enforces the configured ceiling. See `AGENT-RULES.md` Rule 5 (semver, and inherited code → `1.0.0`).

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
| Shipping SP-track | ✅ DONE | SP-1…SP-11 complete; current release work is carrier verification. Map = `specs/2026-06-25-shipping-module-decisions.md` |
| Location provider layer | ✅ DONE | 16/16 tasks; record-level defects closed: #334, #330, #336, #328, and in s78 #352 (mixed-provider chain), #350 (settlement typed without picking), #346 + #333 (stale record reads as absent) |
| S5 React admin | ✅ DONE | React settings, wizard, licenses, plugins and shipping orders shipped |
| S4 EDD / S6 ecosystem | ⚪ deferred | post-v2.0 |

## Phase Status (subsystems)

**The per-subsystem matrix is [wiki/architecture.md](wiki/architecture.md).** Browser verification
there is historical evidence, not a fresh acceptance run. The live programme stage is the table above.

## Known Bugs / Open debt

- **#1361:** framework-domain loading fix prepared on `fix/1361-framework-textdomain` for 2.0.5:
  shared init-0 loader (including older resolvers), explicit bundled fallback, locale reloads, lazy gateway milestones/pickup labels.
  Validation and delivery: [s188](sessions/s188.md#framework-translations-1361). Release VERSION unchanged.

- **#1289 / PR #1353:** merged s186 (`7a74a8d8`); [validation](sessions/s186.md).
- [⚠️] `class-payment-gateway.php` ~3.6k lines — trait-extraction candidate (#117).
- **s184 open debt:** >1000 variations are silently truncated by the wizard (cap 20×50, rated near-zero, not carded). (#1332, the
  #1279 upstream issue and the rig orders were all closed on 16.10.2026.)
- **B-2 loader-protocol forward-tolerance:** the resolver loads framework classes from the **highest registered copy for the whole fleet**; `backwards_compatible` deactivates-with-notice any plugin below that copy's min. Rules → `AGENT-RULES.md` Rule 3.

### Public docs

**Merchant docs on woodev.ru (Russian, BetterDocs) — separate from this developer site:** plan and decisions on #1342.

✅ **Published (s183):** Astro Starlight site in `website/` → <https://kalbac.github.io/woodev-plugin-framework/> (GitHub
Pages, source Actions; `docs.yml` builds every PR touching `website/**` and deploys on `main`). All 33 pages written
against 2.0.x and critic-checked; the framework version is read from `Woodev_Plugin::VERSION` at build time. Rules:
`DOCS-SCHEMA.md` → "Public docs". Code/docblock discrepancies found while writing: #1316.

## Next Actions

**Next:** use `next-session-prompt.md` for the current handoff and GitHub Project №6 for open work and priorities.

**Issue lists and priorities live on the board.** `FUTURE-BACKLOG.md` is frozen.

## 🔔 Cross-Project Reminder — Ecosystem Orchestration (dormant)

- **Trigger:** v2.0.0 shipped AND stable in production for several weeks. When it fires, surface it in the session-opening summary; do **NOT** auto-start; point the operator to the spec and read its "Prompt for the Future Agent" section first.
- **Spec:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`.

## Local rig

**How to operate it — the carrier table, the standard option values, the two environments, the
shell recipes and the timings — is [wiki/local-rig.md](wiki/local-rig.md) → "Operating the rig".**
This section retains mutable state and the operating warnings.

- **Last recorded stack:** WordPress 7.1 + WooCommerce 11.1.0, measured 05.09.2026 (s117),
  dev `:8973` / tests `:8974`. Measure the served stack before relying on these versions.
- **Two machines:** Windows desktop + macOS laptop; inspect container state before use.
  Operating recipes: [wiki/two-machine-setup.md](wiki/two-machine-setup.md).
- ⛔ **Never `docker volume prune` / `docker system prune --volumes` on this machine.** The
  operator's `wordpress-test` stack holds ALL real plugins in one env and its volume sits unattached
  while the stack is `Exited` — a prune wipes it. Inventory: [wiki/local-rig.md](wiki/local-rig.md).
- ⛔ **Issuer `:8090` — KEPT, do NOT touch.** The operator uses it independently.
- ⚠ **The rig serves the WORKING TREE.** Name the branch out loud, switch the tree BEFORE asking
  anyone to look, and leave it there until the pass is over — s92 switched back «for tidiness» and
  cost the operator a whole pass. Confirm by measurement, not by intention:
  `grep -c "<a symbol the fix introduces>" <the served file>`. Gotcha
  `rig-serves-the-working-tree-branch-switch-reverts-fixes`.
- ⚠ **The classic picker probe uses `/classic-checkout/`.** `/checkout/` is the block checkout:
  SP-11 is implemented, but classic `form.checkout` selectors do not apply there. Cart: `?add-to-cart=12`. Gotcha
  `rig-checkout-url-is-the-block-checkout`.
- Going to the rig for the pickup layer? The order matters or the button is simply absent:
  [wiki/rig-pickup-walkthrough.md](wiki/rig-pickup-walkthrough.md).
## Infrastructure Reference

- **Version:** `Woodev_Plugin::VERSION` (in `woodev/class-plugin.php`) = 2.0.2, released at tag `v2.0.2`. **Raising VERSION on `main` publishes a release** — do it deliberately (#285).
- **PHP target:** 8.1 · **WP min:** 6.6 · **WC min:** 7.0
- **Tests:** Brain Monkey (unit) + WP Test Library (integration). `composer check` = phpcs + phpstan L3 + unit. JS tests must run **from bash**, not PowerShell (gotcha `powershell-drops-the-roots-flag-from-the-jest-command`).
- **CI:** GitHub Actions. **Merge PRs:** `gh pr merge <N> --squash --delete-branch` only after every job is confirmed green with state CLEAN; never `gh pr merge --auto`.
