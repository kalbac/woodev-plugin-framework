# Re-critic review — #505, the «Инструменты» section (PR #508, round 2)

> Round 2 re-critic on `feat/505-shipping-tools-section` at `9efbad7` (round-2 fixes
> `ba335ee` + bundle rebuild `9efbad7`). Reviewed 25.08.2026. This pass edited, staged and
> pushed nothing: every mutation below was reverted with `git checkout --` and the tree was
> verified clean after each one.

**Verdict on the work: the seven blocking findings are all genuinely fixed, not moved.**
Two of them were mutation-verified; M1 was additionally attacked on the three paths round 1
never drove, and held.

**Verdict on merge: NOT YET — blocked on two things, neither of which is a third critic
round.** (1) CI has never run on the round-2 commits. (2) M3's fix is silently revertable:
the whole 1389-test JS suite stays green with it removed.

## Findings scorecard

| # | Verdict | Evidence |
|---|---|---|
| M1 | **FIXED** | Mutation-verified + 3 adversarial probes |
| M2 | **FIXED** | Mutation-verified |
| M3 | **FIXED in behaviour, UNPINNED by tests** | Source + shipped bundle both carry it; mutation leaves 1389/1389 green |
| m3 | **FIXED** (blocking half) / **PARTIAL** (the door as a whole) | Guard is reject-one-keep-the-rest and cannot be bypassed; a second consumer is still unguarded |
| D1 | **FIXED** | Deadline re-derived from source, wording is true |
| T1 | **FIXED, has teeth** | 1 failure when M2 is reverted |
| T2 | **FIXED, has teeth** | 1 failure when M1 is reverted |

## Gates, run by this reviewer at `9efbad7`

| Gate | Result | Round 1 | Delta |
|---|---|---|---|
| `phpcs` | 225 files, exit 0 | exit 0 | — |
| `phpstan --memory-limit=4G` | 225 files, `[OK] No errors` | `[OK]` | — |
| `phpunit --testsuite=Unit` (cache removed first) | **2742 tests, 6752 assertions, 66 skipped**, exit 0 | 2740 / 66 | **+2 = T1 + the m3 test** |
| `npm run test:js -- --roots "<rootDir>/tests/js"` (bash) | 18 suites, **1389** passed, exit 0 | 1388 | **+1 = T2** |
| `php bin/generate-class-map.php` | "Wrote 211 entries", `git diff woodev/class-map.php` **empty** | empty | — |

SKIPPED is unchanged at **66** across main → round 1 → round 2, so the rise is new coverage.
Note the brief's `main` baseline (2701) and round 1's table (2718) disagree; `main` is moving
under this branch (a new `test(shipping)` commit landed at 01:41). The number that settles it
is the delta on this branch: +2 unit, +1 jest, exactly the three new tests.

---

## A. Did each finding actually get fixed?

### M1 — stale result on selector change: **FIXED**

`tools-block.js:44-49` adds `handleSelectorChange` (clear result, then set value) and wires it
at `:64`. It clears on **every** path that invalidates a result, not just the one T2 drives.

**Mutation check.** Reverted `onChange={ handleSelectorChange }` → `onChange={ setValue }`.
Result: **1 failed, 8 passed** — exactly the new test, failing on
`queryByText('Удалено записей: 37.').not.toBeInTheDocument()`. Restored, tree clean. T2 has
teeth.

**Three adversarial probes** (written into the suite, run, then reverted — the tree was
verified clean afterwards). All three passed:

- **Rapid selector change during an in-flight run.** Cannot happen. `SelectField` renders its
  trigger as a real `disabled` native `<button>` (`select-field.js:78-87`), and `busy` is set
  synchronously in the click handler before the request starts, so `disabled={ tool.disabled
  || busy }` is already applied on the first re-render. The probe asserted `trigger.disabled
  === true` mid-flight and that clicking it anyway does not mount the popover. This closes the
  one race that `setResult(null)` alone would not have closed — an in-flight promise resolving
  onto a changed selector.
- **A run that REJECTS, then a selector change.** The `.catch()` sets a failure result; the
  subsequent selector change clears it. Probe confirmed.
- **Two tool cards on screen.** `ToolCard` state is per-card; changing card A's selector clears
  A's result and leaves B's intact. Probe confirmed both halves.

One further path is closed structurally rather than by this fix: `SectionView` is keyed
`${tab.id}:${section.id}` (`app.js:325`), so leaving and re-entering «Инструменты» remounts
the block and drops all tool state.

**Not closed, and out of scope:** running «Очистить» does not invalidate a result still shown
on the «Проверить» card. Cross-card staleness was not in round 1's scope and is not a factual
misclaim about the provider named in the selector, so I am not raising it as a defect —
recording it only so it is not mistaken for something this pass missed.

### M2 — a sweep with failures reported as success: **FIXED**

`class-popular-settlements-tools.php:119-136`: the message is built once, then `failed > 0`
returns `Tool_Result::failure( $message )`, otherwise `Tool_Result::success( $message )`.

The three sub-questions from the brief, each checked:

- **Counts still all reach the merchant.** The same `sprintf` feeds both branches — no branch
  drops or shortens the message. `run_tool()` returns `rest_ensure_response( $result->to_array() )`
  at HTTP **200** with `{success:false, message}`, and `tools-block.js:86` renders it in the
  `is-error` panel with the full text. Verified along the whole path, not just at the return
  statement.
- **`failed` stays distinguishable from `deleted`.** `Popular_Settlement_Verifier::sweep()`
  keeps `OUTCOME_GONE → deleted` and `OUTCOME_FAILED → failed` as separate counters
  (`class-popular-settlement-verifier.php:145-159`); the outcome flag now reads only `failed`.
  Spec D6's separation is preserved and is now load-bearing rather than decorative.
- **A zero-row sweep is NOT reported as a failure.** `$counts` is initialised to all-zero, an
  empty `all_for_provider()` leaves `failed === 0`, so `Tool_Result::success` is returned.
  This is the pre-existing `test_run_sweep_reports_counts_in_russian` case, which still asserts
  `assertTrue( $result->is_success() )` and still passes.

**Mutation check.** Replaced `if ( $counts['failed'] > 0 )` with `if ( false )`. Result:
**10 tests, 1 failure** — `test_run_sweep_reports_failure_when_a_row_could_not_be_verified`,
"Failed asserting that true is false". Restored (CRLF preserved), tree clean. T1 has teeth.

### M3 — dead «Сохранить» under a fields-less section: **FIXED in behaviour, UNPINNED by tests**

`app.js:343` wraps the actions footer in `{ ! section.is_tools && ( … ) }`.

- **It keys off the right thing.** `build_sections()` sets `$entry['is_tools'] = true` *only*
  inside `if ( $section->is_tools() )`; for every other section the key is absent, so
  `section.is_tools` is `undefined` and the footer renders. Connection sections and ordinary
  field sections are untouched.
- **It cannot suppress Save for a section that shows settings.** This is stronger than it
  looks: `section-view.js:32-34` returns `<ToolsBlock>` *before* any field rendering, so an
  `is_tools` section never renders a field regardless of what `setting_ids` it declares. The
  suppression is therefore exactly aligned with what is on screen. (Nothing rejects an
  `is_tools` + `is_connection` section, which would render as a connection block with Save
  suppressed — that contradictory-flags family is m6, already on #514.)
- **`renderSection()` was not otherwise reshaped.** The diff is the wrapper and nothing else;
  `hasChanges`, `hasProviderMismatch`, the notices and `SectionView` props are byte-identical.

**But it is not pinned.** No test in any suite references `is_tools` in `app.js`. Mutation:
replaced `{ ! section.is_tools && (` with `{ true && (` and ran the full JS suite —
**18 suites, 1389 passed**. The fix reverts silently. Restored, tree clean. Round 1's own
argument applies verbatim: M2 survived 2740 green tests for exactly this reason. See N1.

### m3 — typed param + guarded direct-construction door: **FIXED** (blocking half) / **PARTIAL** (the door)

The closure is gone entirely, replaced by a `foreach` with `! $tool instanceof
\Woodev\Framework\Shipping\Settings\Shipping_Tool` → `_doing_it_wrong()` + `continue`
(`class-settings-page-registry.php:191-213`). Checked against the brief's three questions:

- **Reject-one-keep-the-rest, not a fatal and not a whole-section drop.** `$entry['tools']` is
  initialised to `[]` and appended per surviving entry, so one bad entry costs one entry.
  `test_build_sections_rejects_a_non_conforming_tool_entry` asserts exactly this with a mixed
  `[ $conforming, 'not-a-tool' ]` array: one `_doing_it_wrong`, one surviving tool.
- **It cannot be bypassed.** `instanceof` against a class name does not autoload, so the
  theoretical bypass is "class not loaded → everything rejected". That path is unreachable:
  an object *of* class `Shipping_Tool` cannot exist unless the class is loaded, so the check
  never false-negatives on a genuine tool. A false *positive* would need a subclass, and the
  class is `final`.
- **It matches the filter door.** Same idiom as `Shipping_Tools_Registry::collect():140-152`.

Worth noting the round-2 test change was *necessary*, not cosmetic: the old
`test_build_sections_marks_tools_and_serializes_descriptors_without_callback` used a Mockery
mock, which the new guard would reject. Replacing it with a real `Shipping_Tool` means that
test now genuinely exercises `Shipping_Tool::to_array()` — a strict improvement.

**PARTIAL as a door:** see N3 — a second consumer of the same array is still unguarded.

### D1 — the `init:25` deadline: **FIXED**

Both docblocks carry the wording (`class-shipping-tools-registry.php:55-62` on the constant,
`:127-134` on the `apply_filters`). I re-derived the deadline rather than trusting the
sentence, because it is emergent:

- `Shipping_Settings_Tab::hook_once():361` — `add_action( 'init', [ $this, 'register' ], 25 )`.
- `collect():113-117` memoizes on `$this->collected`, and **every** public accessor
  (`get_tools():197`, `has_tools():210`, `run():233`) calls it — there is no path that reads
  the registry without first triggering or having triggered the collect.
- The only earlier `init` binding in this area is `Location_Provider_Registry::collect()` at
  `init` 20, which does not touch the tools registry.
- "Register from `plugins_loaded` or from `init` at a priority below 25" is correct:
  `Location_Provider_Registry::add_hooks()` — which adds the framework's own `FILTER_TOOLS`
  callback — runs from `plugins_loaded`, and that is before `init` at any priority.

The sentence is true of the code as it now stands.

### T1 / T2 — both FIXED and both have teeth

Mutation results above.

---

## B. What the round-2 diff introduced

### N1 — the M3 fix has no regression test — **MINOR, but fix before merge**

Proved by mutation: reverting `! section.is_tools &&` leaves **1389/1389 green**. Round 1
demanded regression tests for M1 and M2 on the grounds that an untested fix is a fix waiting
to be undone; M3 got the fix without the test. `tests/js/settings-page-app.test.js` already
renders sections, so this is roughly one case ("a tools section renders no Сохранить button"),
not a piece of design work.

### N2 — the generic settings-page layer now names a shipping class in executable code — **MINOR, informational**

`woodev/settings-page/class-settings-page-registry.php:192` hard-codes the FQN
`\Woodev\Framework\Shipping\Settings\Shipping_Tool`. The dependency is not new in *intent* —
`Settings_Section::create()`'s docblock already types `$tools` as
`array<int, \Woodev\Framework\Shipping\Settings\Shipping_Tool>` — but it is new in *code*, and
it means a non-shipping carrier that wanted an `is_tools` section would have to depend on the
shipping namespace. Flagged, not blocking; the alternative (an interface, or a duck-typed
`method_exists` check) is a design call, and this coupling is at least honest about what the
existing contract already said. The same coupling now exists in `SettingsPageRegistryTest`,
which `require_once`s two shipping class files.

### N3 — the m3 guard's parity claim is one call site short — **MINOR, add to #514**

The new comment says a non-conforming entry "is rejected the same way the FILTER_TOOLS filter
door rejects one". That is now true at `build_sections()` and **not** at the second consumer
of the same array: `Woodev_REST_API_Settings_Page::run_tool()` iterates
`$section->get_tools()` and calls `$candidate->get_id()`
(`class-rest-api-settings-page.php:346-352`) with no `instanceof` gate. A malformed
directly-constructed section fatals there — an HTTP 500 on one route rather than round 1's
page-wide fatal, so strictly less severe than what was fixed, and *pre-existing* rather than a
regression. It matters because a reader of the new comment will believe the door is shut.
Round 1's own shape-of-correct-behaviour for m3 ("whatever validates the filter path should
validate this path too") points at `Settings_Section::create()` as the one place that would
close both consumers at once — which is where m6/#514 is already heading. **One line on #514,
not a third round.**

### N4 — no functional regressions found

`phpcs`, `phpstan` and both suites are green, skip count unchanged at 66, class map clean.
I specifically checked that the two new `require_once` lines in `SettingsPageRegistryTest`
(which define shipping classes for the whole unit run) did not cause any test to skip or
change branch: the skip count is identical to round 1 and to `main`.

---

## C. The deliberately-deferred follow-ups — genuinely untouched

Verified by file: the round-2 diff touches eight files, and **none** of them is where a
follow-up would have to land.

| Item | Would live in | Touched by `ba335ee`? |
|---|---|---|
| m1 (`FILTER_TOOLS` not removed in `reset_for_tests`) | `class-location-provider-registry.php` | **no** — file absent from the diff |
| m2 (integration reset for the tools registry) | `class-shipping-tools-registry.php` / integration suite | **no** — the registry file's diff is docblock-only (D1); `reset_for_tests()` is byte-identical |
| m4 (WCAG contrast) | `style.scss` | **no** |
| m5 (selector width) | `style.scss` | **no** |
| m6 (eight-argument constructor) | `class-settings-section.php` | **no** |
| T3 (REST test, tool on another provider) | `SettingsRestControllerTest.php` | **no** |
| per-tab `hasChanges` | `app.js` | only the M3 wrapper; `hasChanges` itself untouched |

Nothing is half-applied. Both cards exist and cover the right ground: **#514** (OPEN) carries
m1, m2, m4, m5, m6 **and T3** — I read its body and confirmed all six sections are present.
**#515** (OPEN) carries the per-tab `hasChanges` question.

---

## D. Round 1's "Confirmed clean" — re-examined, not re-litigated

I re-read all four and **agree with round 1 on every one**; none is re-opened.

- **Callback serialization.** Round 2 strengthens it: the only serializer is still
  `to_array()`, and it is now reached only after an `instanceof` gate. Nothing new can reach
  the browser.
- **The REST route.** Re-read in full at `:315-386`. Still a faithful mirror of
  `test_connection()`. The one thing round 1 did not note is N3 above, which is about the
  tools *array*, not about the route's gating — the permission check, the allow-list, the
  `\Throwable` catch and all three 404s are as round 1 described.
- **The D3 capability gate.** Untouched by round 2; `resolve_capable_provider()` still
  re-derives from the live registry.
- **The lazy-collect verdict.** Untouched, and D1 now supplies the mitigation round 1 asked
  for. The file docblock's claim that the settings tab is "the only consumer"
  (`class-shipping-tools-registry.php:19-21`) is still there and still slightly wrong —
  `run_tool()` is a second, harmless-because-later consumer. Round 1 recorded this as a caveat
  rather than a finding; I am doing the same.

## The bundle

Both `chore(assets)` commits are generated output only, with nothing hand-written riding
along: `a81363b` touches the four `woodev/assets/build/settings-page/*` files, `9efbad7`
touches two (`index.js` + `index.asset.php`) — and the CSS files correctly did **not** change,
because round 2 touched no SCSS.

I read the round-2 fixes out of the minified bundle rather than trusting the commit message:

- M1: `onChange:e=>{m(null),a(e)}` — the clear-then-set pair, not the bare setter.
- M3: `!n.is_tools&&(0,b.jsx)("div",{className:"woodev-settings__actions"…` — the gate is there.

**Caveat I cannot remove:** this proves the bundle *contains* the fixes, not that it is
byte-identical to what CI's `npm run build` produces. A worktree build cannot establish that
(gotcha `local-npm-run-build-is-not-assets-parity-evidence`), so parity remains the CI job's
to prove — and see below.

## ⛔ The merge blocker that is not in the code

**CI has never run on either round-2 commit.** Measured at 01:46 UTC on 25.08.2026 via
`gh api .../commits/{sha}/check-runs`:

| Commit | Check runs present |
|---|---|
| `33475f0` (round-1 report, pre-fix) | CI ✅, Integration Tests ✅, Markdown Lint ✅ |
| `ba335ee` (the round-2 fixes) | **Label PR only** |
| `9efbad7` (PR head) | **Label PR only** |

`ba335ee` was pushed at 01:27 and `9efbad7` at 01:35 — 19 and 11 minutes before measurement —
and other branches' CI ran normally in that window (`fix/502-…` at 01:34), so this is not a
global queue stall. The PR is not a draft and its base is `main`, so the `pull_request` trigger
should have fired. Only the `pull_request_target` workflow (PR Triage) did.

This matters more than usual here, because **"Assets build parity"** lives in that CI workflow
(`.github/workflows/ci.yml:258-299`) and is the *only* mechanism that can prove the committed
bundle matches a clean build. The last time it ran, it ran on the pre-fix tree.

Under this project's rule — every CI job verified pass **and** state clean before merge — the
PR cannot be merged until CI runs green on `9efbad7`.

---

## Verdict

**The round-2 work: ACCEPT.** All seven blocking findings are genuinely fixed. Two were
mutation-verified; M1 was attacked on three further paths and held. The two regression tests
both have teeth. Nothing was half-done, no deferred item was quietly touched, and no
functional regression was introduced.

**Merge: NOT YET.** Two conditions, in order:

1. **Get CI green on `9efbad7`** — it has never run. Re-trigger it (an empty commit, or
   close/reopen the PR) and read every job, Assets build parity included.
2. **Pin M3 with one test** (N1). A fix that the entire suite cannot tell from its own
   absence is the exact failure mode round 1 was convened to punish.

Optionally, in the same pass: append N3 to #514 (one paragraph — the unguarded `run_tool()`
loop belongs with m6, since `Settings_Section::create()` is where both consumers get closed at
once).

**Is a third critic round warranted? No.** Nothing here needs another adversarial pass. Item 1
is infrastructure, item 2 is a fifteen-line test, and N2/N3 are card material. A worker should
do both, and the PR should merge on confirmed-green CI without a round three. If item 2 is
judged not worth doing now, it should become a line on #514 rather than a silent omission —
but doing it is cheaper than arguing about it.

## What I did NOT check

- **The integration suite.** `composer check` is `phpcs + phpstan + test:unit`;
  `composer test:integration` needs a WordPress install and was not run. m2 remains reasoned,
  not measured — unchanged from round 1.
- **`npm run build` byte-parity.** Deliberately not attempted in a worktree. The bundle was
  read for content, not reproduced. This is CI's job and CI has not run.
- **Anything on the rig.** No browser was opened. Every JS claim above rests on jsdom and on
  reading the bundle.
- **Whether the `pull_request` trigger failure is a GitHub hiccup or a repo-config problem.**
  I established that CI is absent on both round-2 commits and that other branches were
  unaffected in the same window; I did not diagnose the cause.

## Needs a human at the rig

Unchanged from round 1, plus one item round 2 creates:

- **m5, the dropdown width** — still derived from CSS, still unobserved.
- **The overall weight of the section** — whether two bordered cards read as a tools shelf.
- **Whether more than one provider declares `CAPABILITY_RESOLVE_KEY` today.**
- **New: what «Инструменты» looks like with unsaved edits pending on another sub-tab.** Round 2
  removed the Save button from this section, which is the right call — but it means a merchant
  who wandered in with pending edits now sees no affordance at all telling them edits are
  waiting. That is #515's territory; one look decides whether #515 is a polish item or urgent.

## Related

- [2026-08-25-505-tools-section-critic-round-1.md](2026-08-25-505-tools-section-critic-round-1.md)
  — the round-1 pass this verifies
- [../specs/2026-08-25-shipping-tools-section.md](../specs/2026-08-25-shipping-tools-section.md) — D1–D6
- [../specs/2026-08-24-popular-settlements-design.md](../specs/2026-08-24-popular-settlements-design.md)
  — D6, `failed` ≠ `deleted`, the rule M2 turns on
