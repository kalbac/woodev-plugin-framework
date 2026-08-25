# Critic review — #505, the «Инструменты» section (PR #508, round 1)

> Round 1 critic pass on branch `feat/505-shipping-tools-section` at `a81363b`
> (feature `4701b88` + orchestrator bundle rebuild `a81363b`). Author: Sonnet worker.
> Reviewed 25.08.2026. Nobody in this project accepts their own work — this is the
> independent pass, and it did not edit, stage or push any source file.

**Verdict: REJECT.** Everything security-relevant is clean: the tool callback has no
serialization escape route, the REST route is a faithful mirror of `test_connection()`, and
D3's server-side capability re-check could not be defeated. The defects are in what the
merchant sees and in what a partial sweep claims about itself.

Reviewed against:

- `docs-internal/specs/2026-08-25-shipping-tools-section.md` — D1–D6
- `docs-internal/specs/2026-08-24-popular-settlements-design.md` — D4 (capability gate),
  D6 (`failed` ≠ `deleted`), D8 (the two merchant actions)

## Counts

| Class | Total | Blocking for merge | Follow-up card |
|---|---|---|---|
| MAJOR | 3 | 3 | 0 |
| MINOR | 6 | 1 | 5 |
| Documentation (public contract) | 1 | 1 | 0 |
| Test gaps | 3 | 2 | 1 |
| **Total** | **13** | **7** | **6** |

## Gates, run by this reviewer

| Gate | Result | `main` baseline |
|---|---|---|
| `phpcs` | 225 files, exit 0, no errors | — |
| `phpstan analyse --memory-limit=4G` | 225 files, `[OK] No errors` | — |
| `phpunit --testsuite=Unit` (after `rm -f .phpunit.result.cache`) | 2740 tests, 6747 assertions, **66 skipped**, exit 0 | 2718 / **66** |
| `npm run test:js -- --roots "<rootDir>/tests/js"` (from bash) | 18 suites, **1388** tests passed, exit 0 | 1380 |
| `php bin/generate-class-map.php` re-run | "Wrote 211 entries"; `git diff woodev/class-map.php` empty | — |

SKIPPED is unchanged at 66, so the test-count rise is new coverage, not newly-skipped work.
`composer check` is `phpcs + phpstan + test:unit` only — the integration suite was **not**
run (see "What only the operator can confirm").

---

## MAJOR

### M1 — a stale tool result survives a selector change

- **Severity:** MAJOR — **blocking for merge**
- **Where:** `src/settings-page/tools-block.js:55`

`ToolCard` clears `result` in exactly one place, inside `run()` at
`src/settings-page/tools-block.js:27`. The provider selector's handler at line 55 is the bare
setter, `onChange={ setValue }`, so changing the provider updates `value` and leaves the
previous run's result mounted at lines 77-81.

**Failing scenario.** A merchant opens «Доставка» → «Инструменты». On the
«Очистить список популярных городов» card the selector defaults to `dadata`. They press
«Очистить» and get the green `is-ok` panel reading «Удалено записей: 37.». They then change
the selector on that same card to a second capable provider. The green «Удалено записей: 37.»
is still sitting directly under the control, now reading as a report about the provider
currently named in the selector. Nothing was deleted for that provider.

**Why it is wrong.** For a destructive tool this is not a cosmetic staleness — the panel makes
a positive factual claim about a deletion that did not happen. The sibling block in the same
directory already establishes the rule and states the reason,
`src/settings-page/connection-block.js:34-37`:

```js
// An ephemeral test result goes stale the moment a credential changes — drop
// it so a prior "success" never lingers next to edited fields.
```

Spec D2 makes the selector the load-bearing control of both tools ("the merchant states which
provider's list they are checking or clearing"), so the one input the whole design turns on is
the one input that does not invalidate the result.

**Shape of correct behaviour.** Any change to a tool card's selector must invalidate that
card's result before the new value is applied — the same invalidation `ConnectionBlock`
performs on a field change and on a field revert. The result region is ephemeral state scoped
to one (tool, selector-value) pair; it must not outlive the pair.

### M2 — a sweep with failures is reported as a success

- **Severity:** MAJOR — **blocking for merge**
- **Where:** `woodev/shipping-method/location/class-popular-settlements-tools.php:119`

`run_sweep()` returns `Tool_Result::success( … )` unconditionally. The `failed` count is
interpolated into the message at line 127, but never consulted for the outcome flag.

**Failing scenario.** The popular-settlements table holds 30 rows for the chosen provider. The
provider's API times out on 12 of them, so `Popular_Settlement_Verifier::sweep()` returns
`checked: 30, unchanged: 18, updated: 0, deleted: 0, failed: 12`. The merchant sees the green
`is-ok` panel (`src/settings-page/tools-block.js:78`, `src/settings-page/style.scss:183-186`)
reading «Проверено: 30. Без изменений: 18. Обновлено: 0. Удалено: 0. Ошибок: 12.» — a success
banner over a run that could not verify 40% of the table.

**Why it is wrong.** The popular-settlements design's D6 separates `failed` from `deleted` on
purpose: a `failed` row is **not** gone, it is *unverified*, and its `last_verified_at` clock
(D2, "two clocks, never one") did not tick. The whole point of keeping the two counters apart
is destroyed if the outcome flag reports "fine" either way, because the merchant's next action
depends on the difference — a `deleted` row needs nothing, a `failed` row needs the sweep run
again. The green panel actively discourages the re-run.

**Shape of correct behaviour.** A sweep that could not verify every row it checked is not a
successful sweep. `failed > 0` must not produce the success treatment; the counts stay in the
message either way so the merchant can see the split. `Tool_Result` is new in this PR and has
only `success`/`failure`, so the choice is either reporting failure with the full counts or
introducing a third, visually distinct partial state — the second is legitimate design work
and should be a decision, not an accident.

### M3 — a dead «Сохранить» button under a section that has nothing to save

- **Severity:** MAJOR — **blocking for merge (operator's call on the shape)**
- **Where:** `src/settings-page/app.js:343-351`

`renderSection()` renders the actions footer for **every** section it draws. The tools section
is fields-less by construction — `Settings_Section::create( 'tools', …, [], … )` at
`woodev/shipping-method/settings/class-shipping-settings-tab.php:329-337` passes an empty
`setting_ids` array, so `class-settings-page-registry.php:186` gives it `'fields' => []`.

**Failing scenario, part one.** The merchant opens «Инструменты». Under a section whose entire
premise is that it holds actions rather than settings, there is a greyed-out «Сохранить»
button that can never become active from anything on that screen.

**Failing scenario, part two, and the worse half.** `hasChanges` at
`src/settings-page/app.js:293` is computed per **tab** (`edits[tab.id]`), not per section. The
merchant edits a field on the «Поля» sub-tab, switches to «Инструменты» without saving, and
the Save button under the tools section is now **enabled** — offering to save edits that are
nowhere on the screen, from a section that owns no settings at all.

**Why it is wrong.** The spec's opening line defines this section by the contrast: "One place
… where **actions** live — as opposed to settings, which is what every other section on that
tab holds." A Save control under it contradicts the one distinction the section exists to
draw, and part two makes the control actively misleading about what it would write.

**Recorded counter-argument, so the author is judged fairly.** Connection sections already
behave this way — `src/settings-page/app.js:209` excludes them from `allFields`, so they too
show a Save button governed by other sections' edits. The author can claim precedent. The
difference is that a connection section at least owns fields; this one owns none by design.

**Shape of correct behaviour.** A section that declares no settings must not present a control
whose only meaning is "persist this section's settings". Whether that means suppressing the
footer for `is_tools` sections or moving the save affordance out of the per-section card is a
UI decision — see "What only the operator can confirm".

---

## MINOR

### m1 — `FILTER_TOOLS` is added in `add_hooks()` but never removed in `reset_for_tests()`

- **Severity:** MINOR — **follow-up card**
- **Where:** `woodev/shipping-method/location/class-location-provider-registry.php:684-687`
  (added) versus `:503-515` (`reset_for_tests()`, which does not remove it)

**Failing scenario.** None reproducible today — see below. The failure this guards against is
the one the method's own docblock describes: a registration made by `add_hooks()` outliving a
reset and firing against state the reset was supposed to erase.

**Why it is flagged anyway.** `Location_Provider_Registry::reset_for_tests()` carries an
explicit rule, set by the Codex critic on #488 slice 2 and quoted verbatim in the code at
`:509-511`:

> Every callback `add_hooks()` registers must be removable here or an integration test keeps a
> stale registry instance alive across a reset.

The new `add_filter` is the first callback `add_hooks()` registers that the reset does not
remove. The author's inline comment at `:678-683` argues the exemption — a static
class-and-method callback, nothing instance-bound, and WordPress dedupes `add_filter()` by
that string — and **the argument is correct**: `Popular_Settlements_Tools::register_tools` is
static and reads the current `Location_Provider_Registry::instance()`, so no stale instance
is kept alive by it. The defect is that an absolute rule now has a silent exception, and the
next reader cannot tell an argued exemption from an oversight without re-deriving the whole
argument.

**Shape of correct behaviour.** Either the reset removes every callback `add_hooks()` adds, or
the rule's wording admits the class of callback that needs no removal and says why. One of the
two must be true for the rule to keep working as a rule.

### m2 — the tools registry has no integration-suite reset

- **Severity:** MINOR — **follow-up card**
- **Where:** `woodev/shipping-method/settings/class-shipping-tools-registry.php:93`
  (`reset_for_tests()`), called only from the four new unit suites

**Failing scenario.** `Shipping_Tools_Registry` memoizes its collection per singleton
(`$collected` at `:66`, set in `collect()` at `:100-103`), and
`Shipping_Settings_Tab::register()` runs at `init` priority 25 on **every** request — its own
docblock at `class-shipping-settings-tab.php:370-372` says so. In the integration suite the
first test that fires `init` therefore freezes the tool list, and its selector options, for
the whole PHP process. `tests/integration/Shipping/LocationRouteTest.php:78` and
`tests/integration/Shipping/ProviderSelectionScopeAgreementTest.php:146` reset
`Location_Provider_Registry`; nothing resets the tools registry. The first integration test
that changes the provider set and then asserts on tools will read the previous test's list.

**Why it is wrong.** It is the same class of cross-test contamination that
`Location_Provider_Registry::reset_for_tests()`'s docblock was written about, arriving through
memoization instead of through the hook table. It is latent — no integration test asserts on
tools today — but a latent isolation bug surfaces as a defect report against the code under
test, not against the harness.

**Shape of correct behaviour.** Wherever the integration suite resets
`Location_Provider_Registry`, the tools registry's memoization must be reset too; a registry
whose collection is process-lifetime needs a reset seam wired into the same place its
collaborators' resets are.

**Not measured.** This is reasoned from the reset call sites, not observed — the integration
suite was not run.

### m3 — untyped closure parameter on the only tools serialization seam

- **Severity:** MINOR — **blocking for merge** (the type declaration; the
  `Settings_Section::create()` guard below is a follow-up card)
- **Where:** `woodev/settings-page/class-settings-page-registry.php:194`

```php
static function ( $tool ): array {
    return $tool->to_array();
},
```

**Failing scenario.** `Settings_Section::create()` at
`woodev/settings-page/class-settings-section.php:87` is public, takes `array $tools`, and
carries the element type in the docblock only. A carrier plugin that builds its own
`is_tools` section and puts anything other than a `Shipping_Tool` in that array produces
`Call to a member function to_array() on string` at line 195 — a fatal inside
`Settings_Page_Registry::build_sections()`, which is on the path of the settings **schema**
route. One plugin's mistake takes down the whole settings page for every tab.

**Why it is wrong.** Two rules, one line. The project convention requires a type declaration
on every parameter, and this closure has none. And the framework's own discipline for
third-party input on this exact feature is `_doing_it_wrong()` plus skip, not a fatal —
`Shipping_Tools_Registry::collect()` at `class-shipping-tools-registry.php:125-136` rejects a
non-conforming entry and lets the rest of the list register. The filter door is guarded; the
direct-construction door is not.

**Shape of correct behaviour.** The parameter carries its type, so the failure is a typed
error at the boundary rather than a method call on a string. Whatever validates the filter
path should validate this path too, with the same reject-one-keep-the-rest behaviour rather
than a page-wide fatal.

### m4 — result panel text fails WCAG AA contrast

- **Severity:** MINOR — **follow-up card**
- **Where:** `src/settings-page/style.scss:183-191`

`is-ok` puts `$ok` (#00a32a) on `rgba(0,163,42,0.12)` over `$surface` white → ≈ **2.88:1**.
`is-error` puts `$error` (#d63638) on `rgba(214,54,56,0.12)` → ≈ **3.96:1**. Both are under
the 4.5:1 AA threshold for the 13px text set at `:178`.

**Failing scenario.** A merchant with reduced contrast sensitivity, or on a laptop screen in a
bright warehouse, cannot reliably read the outcome of a destructive action.

**Why it is flagged as minor rather than major.** It is inherited in kind, not introduced:
`.woodev-connection__result` at `src/settings-page/style.scss:108-109` already uses the same
two tokens as text colours on plain white (≈3.31:1). The tinted background this PR adds makes
the green case modestly worse rather than creating the problem.

**Shape of correct behaviour.** Text inside a tinted status panel needs a darker value than
the token used for a status dot or a border; the tint and the ink cannot both come from the
same token. Fixing it for the tools panel without fixing `.woodev-connection__result` would
leave two different treatments for the same semantic, so this belongs in one pass over both.

### m5 — the provider dropdown is shrink-to-fit and will resize when a provider is picked

- **Severity:** MINOR — **follow-up card**
- **Where:** `src/settings-page/style.scss:143-147`
  (`.woodev-tool__selector { display: flex; align-items: center; … }`)

**Failing scenario.** `.woodev-select` is `display: block` with a `width: 100%` trigger
(`src/components/_field.scss:469-478`). As a flex item with no `flex` or `width` of its own it
takes its base size from its content, so the control is exactly as wide as the currently
selected provider's name plus the chevron. Choosing a provider with a longer name widens the
control; choosing a shorter one narrows it. The row visibly reflows on every selection.

**Why it is wrong.** Every other use of `.woodev-select` in this page sits in the field grid
at `$control-col: 425px` (`src/components/tokens.scss`), so this is the one place the control
has no stable width, and a control that changes size when you use it reads as broken rather
than as responsive.

**Shape of correct behaviour.** The selector's width is a property of the control, not of the
currently selected option — it needs a stable width consistent with the page's control column.

**Not observed.** Derived from the CSS; needs a look at the rig to confirm (see below).

### m6 — an eight-argument positional named constructor

- **Severity:** MINOR — **follow-up card**
- **Where:** `woodev/settings-page/class-settings-section.php:87`, called at
  `woodev/shipping-method/settings/class-shipping-settings-tab.php:329-337`

**Failing scenario.** Reaching the two new tools parameters requires threading two placeholder
arguments past them: `create( 'tools', …, [], …, false, '', true, $tools )` — a `false` that
means "not a connection" and an `''` that means "no action label", present only as spacers. A
future seventh flag, or a caller that mis-orders `false, ''`, produces a section that is
silently the wrong kind: `is_connection` and `is_tools` are independent booleans and nothing
rejects both being true.

**Why it is wrong.** The named constructor exists to make construction readable; at eight
positional parameters with two spacers it no longer does, and the two mutually-exclusive
section kinds are expressible as a contradictory pair.

**Shape of correct behaviour.** A section kind is one thing, not a bag of independent
booleans; the call site should state which kind it is building rather than positioning flags
around it.

---

## Documentation of a public contract

### D1 — `FILTER_TOOLS` does not state its registration deadline

- **Severity:** MINOR (public contract) — **blocking for merge**
- **Where:** `woodev/shipping-method/settings/class-shipping-tools-registry.php:50-58` (the
  constant's docblock) and `:110-122` (the `apply_filters` docblock)

**Failing scenario.** A carrier plugin author reads the filter's documentation, which says
what to return and nothing about when. They call
`add_filter( 'woodev_shipping_tools', … )` from a callback on `init` priority 30, or from
`admin_init`. Collection has already happened at `init:25` and is memoized, so their tool
never appears — **with no `_doing_it_wrong()`, no log line and no error**. The section renders
correctly with everyone else's tools, which is the worst possible presentation of the failure.

**Why it is wrong.** D6 makes this filter the public registration seam for third parties:
"any carrier plugin can register its own and have it appear in the same section." A public
seam whose only failure mode is silence must state the condition that triggers the silence.
The sibling `Location_Provider_Registry::FILTER_PROVIDERS` is collected by an `add_action(
'init', …, 20 )` a plugin author can read off the source; this one's deadline is an emergent
property of who touches the registry first.

**Shape of correct behaviour.** The filter's docblock states the deadline and where it comes
from. Recommended wording, to be placed on both the `FILTER_TOOLS` constant and the
`apply_filters` docblock:

> **Registration deadline: `init` priority 25.** This filter is applied once per request, on
> the registry's first access, and the result is memoized. The first access in a normal
> request is `Shipping_Settings_Tab::register()` at `init` priority 25, so a callback added
> later than that is silently never collected. Register from `plugins_loaded` or from `init`
> at a priority below 25.

---

## The deliberate deviation: lazy collection instead of an `init` hook

**Verdict: the lazy collect is SAFE as written. It is not a defect, and it should stay. What
it costs is a documented deadline, which is D1 above — the wrong fix would be to abandon the
laziness.**

The author's own argument, recorded in `class-shipping-tools-registry.php:19-28`, is that the
only consumer is `Shipping_Settings_Tab::build_sections()`, which already runs at `init:25` by
which point every filter callback has had its chance. I tried to break that argument on every
path the brief named and could not:

- **The filter is added before any `init` priority.**
  `Location_Provider_Registry::add_hooks()` — which contains the new `add_filter` at
  `class-location-provider-registry.php:684-687` — runs from `declare_needed()`, called from
  `Shipping_Plugin::add_hooks()` at `plugins_loaded`.
- **Providers exist before the collect.** `Location_Provider_Registry::collect()` is bound at
  `init` priority 20 (`class-location-provider-registry.php:671`);
  `Shipping_Settings_Tab::hook_once()` binds `register()` at `init` priority 25
  (`class-shipping-settings-tab.php:361`), and its docblock at `:344-347` states the ordering
  as the reason for the number. So `Popular_Settlements_Tools::capable_providers()` sees a
  populated registry, not an empty one.
- **REST cannot get there first.** `rest_api_init` fires during `parse_request`, long after
  `init:25`. Even on a hypothetical request where the settings tab never registered,
  `Shipping_Tools_Registry::run()` calls `collect()` itself
  (`class-shipping-tools-registry.php:216`) at a point where every filter is in place.
- **There is no admin-only window.** `register()` runs on every request the hook fires on,
  including frontend and REST — its docblock at `class-shipping-settings-tab.php:370-372` says
  so explicitly. There is no request shape where the collect happens at a different stage.
- **WP-CLI and the setup wizard both load WordPress through `init`,** so neither reaches the
  registry earlier than `init:25`.
- **`reset_for_tests()` genuinely re-collects.** It nulls the singleton
  (`class-shipping-tools-registry.php:93-95`), so `$collected` goes with it — asserted by
  `ShippingToolsRegistryTest::test_reset_for_tests_forces_a_fresh_collection`.

So the "memoization plus an early first access equals a permanently empty tool list" failure
has no live call path in this codebase today.

**What the deviation actually costs.** It converts a *stated* deadline into an *emergent* one.
With an `init` hook, the deadline is a number in the source that a plugin author can read and
that only changes when someone edits that number. With lazy collection, the deadline is
"whenever the first consumer happens to touch the registry" — today `init:25`, but it moves
under every already-shipped plugin the moment a new consumer appears earlier, with no code
change in this class and no test that would notice. That is a real cost and it is worth
accepting, because the counterpart benefit is also real: hooking nothing means
`reset_for_tests()` has no `remove_action()` half to write and no instance-bound hook-table
entry to leak across a reset, which is exactly the trap
`Location_Provider_Registry::reset_for_tests()` documents at length at `:463-500`.

The mitigation is one docblock (D1), not a redesign.

**Two caveats on the author's supporting claims, neither of which changes the verdict.** The
docblock says the tab is "the only consumer"; `Woodev_REST_API_Settings_Page::run_tool()` at
`woodev/rest-api/controllers/class-rest-api-settings-page.php:381` is a second one, harmless
because it is strictly later. And the "no hook to remove" benefit is partly notional, because
`add_hooks()` now registers a filter that nothing removes anyway — see m1.

---

## Test gaps

The suite does assert the rules rather than only the happy path — absence with no capable
provider (`PopularSettlementsToolsTest::test_no_tools_when_no_provider_is_resolve_key_capable`),
absence of the whole section with no tools
(`ShippingSettingsTabTest::test_no_tools_section_without_registered_tools`), the section being
last (`…::test_tools_section_is_last_and_carries_registered_tools`), the REST allow-list at
both layers (`SettingsRestControllerTest::test_run_tool_scopes_args_and_returns_the_result_payload`
and `ShippingToolsRegistryTest::test_run_scopes_args_to_the_tool_s_declared_selector_names`),
both 404s, the logged 500, callback-never-serialized, and `_doing_it_wrong()` on both a
non-conforming entry and a duplicate id. Three cases are missing.

### T1 — no sweep with `failed > 0`

- **Severity:** test gap — **blocking for merge** (this is M2's regression test)
- **Where:** `tests/unit/Shipping/Location/PopularSettlementsToolsTest.php:276-289`

`test_run_sweep_reports_counts_in_russian` installs an empty store, so the only sweep ever
exercised returns `checked: 0` and every other counter zero, and the test asserts
`assertTrue( $result->is_success() )` against that. No test distinguishes a clean sweep from
a sweep that failed on part of the table, which is precisely why M2 passed 2740 green tests.

**What a correct test asserts.** A sweep whose fixture provider fails on some rows must be
distinguishable, by outcome and not only by the text of the message, from one that succeeded
on all of them.

### T2 — no test that a selector change clears a stale result

- **Severity:** test gap — **blocking for merge** (this is M1's regression test)
- **Where:** `tests/js/settings-page-tools-block.test.js` — 8 tests, none touching the
  selector after a run

The file covers the result rendering below the action row, the success style, the error style,
the synchronous busy transition, arg scoping, the no-selector case and the disabled case. The
one interaction that invalidates a result — changing the provider — is never performed after a
completed run.

**What a correct test asserts.** After a run resolves and its result is visible, changing the
card's selector leaves no result rendered.

### T3 — no REST test for a tool declared on a different provider

- **Severity:** test gap — **follow-up card**
- **Where:** `tests/unit/SettingsRestControllerTest.php:184-197`
  (`test_run_tool_unknown_tool_is_404`)

The unknown-tool 404 is covered, but the `break 2` scoping at
`woodev/rest-api/controllers/class-rest-api-settings-page.php:340-352` — which is what stops
`/settings/{tabA}/tool/{toolOnTabB}/run` from resolving — is never exercised with a tool that
genuinely exists on another provider. The 404 currently passes for the trivial reason that the
tool exists nowhere.

**What a correct test asserts.** A tool id that resolves on provider B is a 404 when requested
against provider A, and the callback is never invoked.

---

## Confirmed clean

Recorded so a later round does not re-litigate settled ground.

- **The callback cannot reach the browser.** `Shipping_Tool` is `final` with all properties
  `private`, implements no `JsonSerializable`, and defines no `__debugInfo`, `__get` or
  `__toString` — `json_encode()` on the object yields `{}`. `to_array()`
  (`class-shipping-tool.php:170-190`) is the only serializer in the repo and omits `callback`;
  `class-settings-page-registry.php:189-199` routes through it exclusively with a comment
  saying why. Every other serialization surface was traced: the inline bootstrap at
  `class-settings-page-registry.php:488-497` carries only `restRoot`, `nonce` and `adminUrl`,
  and no `set_transient`, `update_option` or `json_encode` anywhere touches a
  `Settings_Section`. `_doing_it_wrong()` and the `error_log()` in `run_tool` emit ids and
  `$e->getMessage()`, never the callable. Asserted by
  `ShippingToolTest::test_to_array_never_includes_the_callback` and
  `SettingsPageRegistryTest::test_build_sections_marks_tools_and_serializes_descriptors_without_callback`.
- **The REST route is a faithful mirror of `test_connection()`.**
  `class-rest-api-settings-page.php:88-97` and `:315-388`: same `save_permissions_check` gate,
  same `WP_REST_Server::EDITABLE`, same `array_intersect_key` allow-list idiom, same
  `\Throwable` catch to a logged 500, same `rest_ensure_response( $result->to_array() )`.
  Unknown provider → 404, unknown tool → 404, tool on another tab → 404 via `break 2`. The
  allow-list is applied at both the REST layer and inside `Shipping_Tools_Registry::run()`,
  labelled defence in depth in the docblock and correctly so. The one divergence — no
  `instanceof` 400 analogue — is defensible, because there is no tools interface to check.
  One latent coupling worth knowing: the section lookup is per-provider while `run()` targets
  the global registry, which is harmless while the shipping tab is the only source of
  `is_tools` sections.
- **D3, the capability gate, holds and the run-time re-check is real.**
  `capable_providers()` (`class-popular-settlements-tools.php:169-179`) filters on
  `CAPABILITY_RESOLVE_KEY`; `register_tools()` returns `$tools` untouched when that set is
  empty, so both tools are absent rather than present-and-disabled, and `build_sections()`
  (`class-shipping-settings-tab.php:326-338`) omits the section entirely when no tool is
  registered. `resolve_capable_provider()` re-derives the capable list from the live registry
  on every run and uses the posted id only as a lookup key — a posted id absent from the
  selector and a posted id present but not capable both resolve to `null`. I could not defeat
  it.
- **D5's removals all held.** `get_connection_ids()` appears nowhere in `woodev/`, `src/` or
  `tests/`. No staged-versus-persisted guard, no composite routing by connection-id ownership,
  no widening of the REST allow-list (`save()` still intersects with declared setting ids, and
  the tools section contributes an empty list). The `Woodev_Settings_Connection_Test` interface
  file is not in the diff — the public contract shipped plugins implement is unchanged.
- **D6's registration seam is argued, not assumed.** The filter-over-method choice is reasoned
  in the registry's file docblock against `Location_Provider_Registry::FILTER_PROVIDERS`; a
  non-conforming entry is `_doing_it_wrong()`'d and skipped without poisoning the list; a
  duplicate id is first-wins with an explicit argument at
  `class-shipping-tools-registry.php:139-146` ("more likely an accidental id clash than a
  deliberate override").
- **Conventions.** Namespaced new code with no legacy `Woodev_*`, short array syntax,
  `@since 2.0.2` on every public and protected method, pure methods `static`
  (`Popular_Settlements_Tools` entirely so), Russian user-facing strings through
  `__( …, 'woodev-plugin-framework' )` with correct `/* translators: */` comments, English
  code, comments and docs, English `_doing_it_wrong()` messages. The single exception is m3.
- **The class map is current.** `php bin/generate-class-map.php` re-run by this reviewer: 211
  entries, `git diff woodev/class-map.php` empty, all three new `Settings\*` classes and
  `Location\Popular_Settlements_Tools` present.
- **Presentation items that were right.** The result renders below the action row
  (`tools-block.js:77-81`, with the reason recorded at `style.scss:170-173`); success and
  failure are visually distinct tinted panels rather than bare text — better than the sibling
  connection block's colour-only treatment; busy is set synchronously on click before the
  request starts (`tools-block.js:26`, and tested). There is no separate spinner to centre:
  `Button isBusy` is WordPress's own pulsing-background affordance, and the SCSS says so
  honestly at `style.scss:159-161`.

---

## Not checked

- **The integration suite.** `composer check` runs `phpcs`, `phpstan` and `test:unit` only.
  `composer test:integration` needs a WordPress install and was not run. m2 is reasoned from
  reset call sites, not measured.
- **`npm run build`.** Not run, deliberately — a worktree build cannot match CI, and the
  orchestrator rebuilt the bundles in the primary checkout. Commit `a81363b` was verified to
  touch only the four generated `woodev/assets/build/settings-page/*` files with nothing
  hand-written riding along, but the bundle content was not verified against the source.
- **`Shipping_Tool::to_array()` under a plugin-supplied selector.** Only the framework's own
  selector shape was exercised; a third-party tool declaring a malformed `selector` array was
  not tested.

## What only the operator can confirm

Nothing below can be settled by reading source or by running a gate.

- **M3, the Save button.** Whether a greyed-out «Сохранить» under a fields-less section reads
  as broken or as consistent-with-the-rest is a judgement about the screen. The
  precedent-versus-contradiction question is real in both directions.
- **m5, the dropdown width.** The reflow-on-selection is derived from the CSS and is close to
  certain, but it has not been seen. One look at the «Инструменты» section with two capable
  providers whose names differ in length settles it.
- **The overall weight of the section.** Two bordered cards stacked with `$gap-lg` between
  them, each with a title, a description, a labelled selector and a button, appended after
  «Поля» / «Карта» / «Локация». Whether that reads as a tools shelf or as two more settings
  blocks is exactly the question the s90 pass got wrong from the code alone.
- **Whether more than one provider declares `CAPABILITY_RESOLVE_KEY` today.** If DaData is the
  only one, both cards show a one-option searchable dropdown. Spec D2 says always-visible, so
  it is per spec — but a searchable popover over a single option is worth a look.

## Related

- [../specs/2026-08-25-shipping-tools-section.md](../specs/2026-08-25-shipping-tools-section.md)
  — the spec this reviews against (D1–D6)
- [../specs/2026-08-24-popular-settlements-design.md](../specs/2026-08-24-popular-settlements-design.md)
  — D4 the capability gate, D6 `failed` ≠ `deleted`, D8 the two merchant actions
- [../AGENT-RULES.md](../AGENT-RULES.md) — «фреймворк = механизм + контракт + хуки»
- [../adr/005-platform-v2-clean-break-policy.md](../adr/005-platform-v2-clean-break-policy.md)
  — why the untouched `Woodev_Settings_Connection_Test` interface matters
