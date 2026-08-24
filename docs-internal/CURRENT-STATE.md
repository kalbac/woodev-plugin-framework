# Current State — Woodev Plugin Framework

> **State only — never history.** Phase status, open debt, next actions, rig/infrastructure facts.
> Session history → `SESSION-LOG.md` (index) + `sessions/sNN.md` (per-session detail).
> Lessons learned → a gotcha (`gotchas/{slug}.md`) if it is about code or a mechanism, the session
> file if it is about how the work went. **Never a third copy here.**
> Program history snapshot → `platform-v2-program-tracker.md`; active program map →
> `specs/2026-06-25-shipping-module-decisions.md`.

**As of 2026-08-25 (s90).** **#500 and #501 merged — #488 slice 3 is done on the server and the
client.** No PR is open. Cards filed: **#499**, **#502**, **#503**, **#505**, **#506**, all Бэклог
except #503 (Инбокс — the operator's own).

**#488 slice 3's ADMIN half was built wrong and is being rebuilt.** The two D8 merchant actions
shipped as two separate fields-less connection sections; the operator's shape is a **«Инструменты»**
section, last on the «Доставка» tab, built as a registry any carrier plugin can register into. Spec:
`specs/2026-08-25-shipping-tools-section.md`, card **#505**. The branch
`feat/488-slice3-d8-merchant-actions` is **NOT continued** — it carries the wrong shape in its
foundation, and D5 of that spec lists exactly what survives (the server mechanics) and what unwinds
with it (the staged-provider guard, the public-interface widening, the REST allow-list widening).

**#337 is OVERTURNED (operator, 25.08.2026).** The address field now waits for `/select` to confirm
rather than unlocking on the pick itself. Recorded in `isAddressLocked()`'s docblock, at the old call
site in `onSelectFor()`, and on issue #337 — three places, because a decision recorded only in a
session file came back for re-litigation once already.

**The standing lesson of s90: the operator's rig pass found four defects that 1380 green tests, five
Codex rounds and a driven browser pass of mine all missed** — all four presentational, none reachable
by a programmatic assertion (`sessions/s90.md`).

**The 2–3 agent cap stands, but the RAM ceiling was never about agents:** the Telegram Claude Code
plugin leaked ~230 MB/hour per session (two instances held 7.35 GB). Disabled in
`~/.claude/settings.json`; commit went 44.6 → 36.2 GB.

## ⚠ The checkout location layer

**#466 was our own §8 adapter, not the network and not WooCommerce** — fixed in #471 by guarding on
ownership rather than a name heuristic. Gotcha:
`the-classic-adapter-reverts-a-select-the-location-cascade-owns`.

### Open in this layer

| Card | State |
|---|---|
| **#437** | **The next big one, not started.** Spec `specs/2026-08-21-settlement-search-design.md`; its popular-settlements half was split out into #488 and is now done bar D8. Measured 24.08: a region-scoped settlement list returns exactly **500** = `LIST_HARD_CAP` — what disproved #404 and killed the settlement `related-list` mode (#486). |
| **#488** | Popular settlements. **Slices 1 and 2 are MERGED (#493, #496): `CAPABILITY_RESOLVE_KEY` + `resolve_key( string $key ): ?Location_Record` exist, DaData and the `test-cdek` fixture declare it, every other provider inherits a throwing default.** `null` means exactly one thing — "asked, answered, does not know this key" — because spec D6 DELETES the row on it; unconfigured, transport failure, malformed payload and unmappable row all throw. **Slice 2 added the table (D3), whole-record storage (D1), the two clocks (D2), the D4a derived-key gate settled by the operator in #491, and the enrolment path.** **Slice 3's D5–D7 are MERGED (#500 server, #501 client): the lazy check inside `/select`, D6's four outcomes, and D7's adopt-or-cancel with «Данные не актуальны, выберите заново».** Left: **D8**, and it is now card **#505** — the two actions move into a new «Инструменты» section, spec `specs/2026-08-25-shipping-tools-section.md`. |
| **#497** | The store default on `Shipping_Admin_Order`'s constructor is what makes popular-settlements enrolment reachable at all, and no test pins it — removing it would silently switch the feature off, which already happened twice during #488 slice 2. The equivalent default on `Abstract_Shipment_Handler` IS covered. |
| **#494** | The derived-key marker is a bare `derived:` prefix, so a provider native id beginning with it would be misread as derived. Reserving the prefix was tried and REVERTED — it makes such a native id unrepresentable. Needs an escaping scheme that preserves every native id; cheap now, expensive after release. |
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

Gotchas: **199**.

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

**Ничего не ждёт кнопки — открытых PR нет.** Автономно можно брать всё, кроме отмеченного 🙋.

1. **#505 — секция «Инструменты»**, спека `specs/2026-08-25-shipping-tools-section.md`, все развилки
   закрыты. Ветку `feat/488-slice3-d8-merchant-actions` НЕ продолжать: новая от `main`, переносом
   только выжившего по D5 спеки. Это закрывает #488 целиком.
2. **#502** — адрес открыт до выбора НП при РАЗНЫХ провайдерах на уровнях. **Начинать с замера**, а
   не с правки `isAddressLocked()`: сперва установить, какой guard реально открыл поле.
3. **#503** 🙋 — маска телефона. В `Инбоксе`, постановка оператора; спека в самой карточке.
4. **#437** — окружающий редизайн поиска НП. Спека готова, развилки закрыты, не начат. Самый крупный
   оставшийся кусок.
5. **Дёшево сейчас, дорого после релиза:** #494 (экранирование метки производного ключа), #497 (тест
   на дефолт стора), #498 (интеграционный тест на саму таблицу), #506 (classmap).
6. **#473** — чинить достижимую половину: безусловный `maybeInitSelect2()` через
   `isLocationOwnedField()`; замер в комментарии к карточке.
7. **Мелочи:** #444, #451, #453. **Остаток ревью 27B:** #391, #393, #396, #397, #399, #400, #402.
8. **#405** — долг по проверке; сперва найти условие, при котором фикстура СДЭК реально падает.
9. **Остатки слоя локаций:** #353, #356, #358, #361, #410.
10. 🙋 **НЕ брать автономно:** **#474** (развилка по публичному контракту), **#483** (вопрос в
    Инбоксе), **#331**, **#332**, **#374** (его прямая просьба). **#379** — низкий приоритет.
    **Отложено до релиза:** #285, #247. **Старое:** #289, #270, #310, #318, #321, #322.

**Техдолг и улучшения карты (181, 159, 152, 148, 182, 174, 173, 151) осознанно НЕ трогаем до пилотной миграции** — пилот на живом карьере покажет, какие из этих карточек реальны, а какие мы придумали сами.

Deferred (всё остальное — board №6): UK-CFR (settings extensibility) и прочие отложенные карточки живут на доске; `FUTURE-BACKLOG.md` заморожен.

## 🔔 Cross-Project Reminder — Ecosystem Orchestration (dormant)

- **Trigger:** v2.0.0 shipped AND stable in production for several weeks. When it fires, surface it in the session-opening summary; do **NOT** auto-start; point the operator to the spec and read its "Prompt for the Future Agent" section first.
- **Spec:** `D:\Projects\woodev_theme\docs\superpowers\specs\2026-05-13-woodev-ecosystem-orchestration-spec.md`.

## Local rig

- **The picker lives on `/classic-checkout/`, NOT `/checkout/`** — the latter is the BLOCK checkout (the adapter is SP-11, unbuilt), where there is no `form.checkout`, no `carrier_pickup_point` and no trigger, which reads as a broken build rather than the wrong URL. Product id `12` fills the cart via `?add-to-cart=12`. Gotcha: `rig-checkout-url-is-the-block-checkout`.
- **The rig serves the WORKING TREE.** Name the branch out loud whenever you ask anyone to look, and switch the tree BEFORE asking — handing the operator a checklist while the tree holds another branch has already cost a wasted pass (gotcha `rig-serves-the-working-tree-branch-switch-reverts-fixes`).  **Дерево на `main` (s90).** Патч фикстуры СДЭК снят, таблица `wp_woodev_popular_settlements` очищена, ворктри Orca сняты.
- **There IS a pickup-type shipping method on the rig now (s81), and it lives OUTSIDE the repo.** Until s81 the only active method was `Woodev Test Shipping`, whose `delivery_type` is `courier` — so `Checkout_Config::pickup_method_ids()` resolved to `[]` and the entire `hide_for_pickup` branch of the checkout-field policy was physically unreachable on the rig. Fixed with a container-only mu-plugin, `wp-content/mu-plugins/zz-rig-test-pickup-shipping.php` (that directory is NOT bind-mounted from the repo — `zz-rig-yandex-key.php` was already there as precedent), registering `woodev_test_pickup_shipping` (`Woodev Test Pickup`) whose `get_delivery_type()` is `pickup`. It is enabled in zone 1 «Russia» as instance 4, alongside `free_shipping` and `woodev_test_shipping`, so a checkout session can switch between a pickup rate and a courier rate. **Keep it** — it is what made the s80 gap verifiable, and it is the only way to exercise that branch live. To remove: delete the mu-plugin file and `wp wc shipping_zone_method delete 1 4 --user=1`.
- **`woocommerce_checkout_company_field` was flipped `hidden` → `optional` on the rig
  (24.08.2026).** The §8 demo moved onto `billing_company`/`billing_address_2` (#481), and
  `billing_company` is a field WooCommerce REMOVES from the checkout array entirely when that
  setting is `hidden` — measured: with it hidden the id was absent even with the customer country
  set to RU, so the demo had nothing to take over and was invisible on the rig. Revert with
  `wp option update woocommerce_checkout_company_field hidden`, but the §8 root demo then goes dark
  again. Note that both demo fields keep WooCommerce's OWN labels server-side (`Company name`,
  `Apartment, suite, unit, etc.`): a takeover field is converted CLIENT-side by
  `checkout-field-classic.js`, and `inject()` deliberately leaves WC's entry alone
  (`test_inject_leaves_takeover_fields_to_woocommerce` asserts exactly that). Do not read the
  native label as "the demo is not working".
- **Rig location config, left as of 24.08.2026 — NOT the historical default.** Provider
  **`test-cdek`**; region axis **«Предустановленный список»** (`related-list`); settlement axis
  **«Список с поиском»** (`ajax-select2`). Set deliberately so the operator can exercise the
  region-preset + settlement-search combination, which had never been run live. Options:
  `woodev_location_active_provider`, `woodev_location_field_mode_region`,
  `woodev_location_field_mode_settlement`. Back to the older default: provider `dadata` and both
  axes `ajax-select2`. Note **DaData can never offer `related-list`** — the capability it
  structurally cannot have — so switching the provider back silently removes «Предустановленный
  список» from the region select too. Switching the provider also makes a customer record whose
  level the new provider does not own read as ABSENT (by design, s78): the chain empties and the
  address locks until re-picked.
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
