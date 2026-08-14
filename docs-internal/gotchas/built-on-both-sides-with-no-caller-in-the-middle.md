# A feature built on both sides, with nothing calling it in the middle

**Namespace:** `[framework/wiring]` · **Discovered:** s56 (2026-08-08), issue #219

## What happened

The viewport strategy's lazy detail fetch existed end to end — except for the one line that would
have made it run:

| Link | State |
|---|---|
| REST route `/points/(?P<id>…)` | built |
| `Point_Source::fetch_details()` | built, implemented in both fixtures |
| Server-side verdict recomputation over the full record | built |
| `dataSource.fetchDetails()` | built, and **unit-tested** (nonce header, 404 mapping, no debounce) |
| A production caller | **none, anywhere** |

```bash
$ grep -rn "fetchDetails" woodev/ --include=*.js
# only the definition, plus a mention in another file's docblock
```

The consequence was not cosmetic: a viewport carrier may omit `accepts_cod`/`max_weight` from its
bbox listing, so every point stayed selectable and the refusal only surfaced at order submission.

## Why nothing caught it

- **The piece had its own passing tests.** `pickup-datasource.test.js` proves `fetchDetails()`
  sends the nonce, maps a 404 and is never debounced. All true, all irrelevant to whether anything
  calls it. **Tests of a unit never test its wiring.**
- **The other strategy does not need it.** `bulk` gets the full record in the listing, so the only
  code path that would have exercised this had no reason to.
- **A migration moved a responsibility and dropped half of it.** The map provider used to pull
  points through an injected dataSource; a later task moved fetching to the mount.
  `fetchPoints` was rewired, `fetchDetails` was not.
- **The evidence was sitting in a test file's own docblock** — *"`fetchDetails()` is unused by
  this file and stubbed trivially"* — written as a note about the stub, read by nobody as a
  finding.

## The tell, and how to look for it

The signature is a **fossil docblock**: prose describing an arrangement the code no longer has.
Here, `map-provider-yandex.js` still opened with

> THIS FILE NO LONGER FETCHES ANYTHING ITSELF. The old version pulled points through an injected
> `dataSource` (`fetchPoints`/`fetchDetails`) and decided when to call it. That is now the
> caller's job.

Two names go out, one name comes back. That sentence was the bug report, in the repository, the
whole time.

**When a responsibility moves between files, enumerate what moved and grep each name for a
PRODUCTION caller** — not a test, not a docblock, not an export:

```bash
grep -rn "<name>" <source-dirs> --include=*.js | grep -v tests/
```

**And treat "a strategy/branch nobody exercises end to end" as a place this is likely already
true.** This one survived from the migration until the first rig run of that strategy, several
sessions later.

## s59 addendum — the SECOND occurrence, found the same way, one layer up (#238)

Same file, same shape, three sessions later. `pickup-mount.js` exports
`{ mountAll, getSession }`; `getSession( fieldId ).refresh()` is the documented cart-change entry
point. Grepping the whole repository outside `tests/js/` for `getSession` returns **the definition
and the export, and nothing else**.

`refresh()` is therefore reachable only from tests — and `forgetPointDetails()`, added by #232
precisely so a cart change invalidates every stored `selectable` verdict, is called from `refresh()`
and nowhere else. So the invalidation mechanism has never run in production.

The tell was identical: a confident docblock naming a mechanism nobody wired.

> `refresh()`, EXPOSED PER SESSION VIA {@see getSession}: re-runs whatever the CURRENT
> strategy/viewport/type-filter state describes…

It says how to reach it. It does not say who does, and nobody does. `onCheckoutUpdated()` exists in
the same file and is wired to `mountAll` only.

**The addendum to the rule: an EXPORT is not a caller.** The first occurrence was found by grepping
a moved name; this one hid from that check because nothing had moved — the export made the symbol
look consumed. Grep for call sites (`getSession(`, `.refresh(`), not for the identifier.

Second lesson, on damage assessment: the blast radius was much smaller than "the mechanism never
runs" suggests, and saying so mattered. A fresh session (and a fresh pool, and a fresh memo) is
built on **every** trigger click, so closing and reopening the picker already resets everything;
the exposure is only a cart change while the picker stays open. Establish that before writing the
card, or the card overstates and gets triaged wrong.

## s66 addendum — third occurrence, and this one is a whole PUBLIC SEAM

Found while assembling the brainstorm brief for the locality/address cards, not while chasing a bug:

- `Woodev\Framework\Shipping\Address\Address_Normalizer` — an interface with `suggest()` and
  `normalize()`, whose own docblock says implementations "wrap an address-data provider (such as
  DaData)".
- `Null_Address_Normalizer` — the no-op default, so "the shipping module can always depend on an
  `Address_Normalizer` being present".
- `Shipping_Plugin::get_address_normalizer()` — lazily builds it, overridable per plugin.
- Both in `woodev/class-map.php`, so they load in production too.
- **Zero call sites.** `get_address_normalizer(` appears only at its own definition; `->suggest(` and
  `->normalize(` appear nowhere in `woodev/` or `tests/`. Nothing in §8 or the pickup layer consults
  it. The framework additionally *registers* the DaData client assets
  (`jquery-suggestions`, `woodev-dadata-suggestions`, `class-plugin.php:514-515`) which the framework
  never enqueues and no reference plugin uses — Почта ships its own copy.

Two things this occurrence adds to the rule:

1. **The Null Object is what makes it comfortable.** A null default is the right pattern *and* it
   removes the only symptom an unwired seam would otherwise have: nothing is ever missing, nothing
   throws, every consumer "works". Whenever a seam ships with a Null default, the wiring check has
   to be deliberate — the absence of breakage proves nothing.
2. **Card #127 says this is unbuilt.** It plans "`suggest(field, query)` / `normalize(address)` plus
   a reusable client widget" as future work, and that shape is already on disk. So the drift runs
   both ways: a docblock can claim a caller that does not exist, and a CARD can claim absence where
   code exists. Check the code before planning either way — the s52 lesson, in the other direction.

## s72 addendum — the caller EXISTS, and nothing pins it there

Fifth occurrence (2026-08-14, adversarial review of PR #315), and the first with a *live* caller.
The whole point of #300 was one line — `get_response_data_for_broadcast()` calling the sanitizer
instead of the raw getter. Six new tests were written, every one of them calling
`get_sanitized_response_headers()` **directly**. Nothing asserted that the broadcast used it.

The critic reverted that single line to the raw getter and ran the suite:

```
Tests: 2143, Assertions: 5384, Skipped: 71.   OK
```

Green. The security hole fully restored, CI 100% clean, and the file's own header comment still
claiming the issue was fixed.

So the family now has two shapes, and the second is harder to see:

| Shape | Tell |
|---|---|
| No caller at all (s56/s59/s66/s68) | `grep` for call sites returns only the definition |
| Caller present, **untested** (s72) | the suite passes with the call site reverted |

The first is found by grepping. The second is found only by **mutating the call site itself**, not
the function it calls. Mutation-testing the helper proves the helper is tested — which is exactly
what everyone already believed. When a fix is "route X through Y", the test that matters asserts on
X's output, never on Y's.

## s73 addendum — wired to N−1 of N call sites, and the docblock lists the N−1

Sixth occurrence (2026-08-14, issue #324), and the one that defeats both tells above.
`Location_Controller::bridge_wc_session()` exists, is documented, is called, and has three
dedicated tests asserting it is called — from `/suggest`, `/admin-suggest` and `/list`. It was
never called from `/select`, the one route in the layer that **writes**, and therefore the only
route where a missing guest session loses data rather than merely widening a search.

Grepping finds callers. Mutating the call sites finds the call sites that exist. Neither asks the
question that mattered.

The tell is in the docblock, and it reads as thoroughness:

> Called from every customer-facing handler that can reach `Location_Service::get_customer_record()`
> (`perform_suggest()`, `handle_list_request()`)

That sentence is **true** and complete against its own rule — and the rule is READ-shaped
(`get_customer_record()`), so the write path was never a candidate. The enumeration in the
parentheses then hardens the omission: a later reader checks the list against the code, finds
them consistent, and moves on.

**A seam described by the callers it happens to have cannot reveal the caller it lacks.**
Describe it by the CONDITION it serves — here "wherever a guest's session must exist" — and the
write path fails the test immediately. Same discipline as the s58 `cardOpened` case: enumerate
every route into the state, then check each one.

Cost: a guest's chosen locality was never persisted, so address suggestions silently searched the
whole country instead of the chosen settlement, with `persisted: false` travelling all the way to
the browser as an honest, unheeded signal.

## Related

- [[dispatcher-files-unwired-in-includes]] — the PHP shape of the same thing: a class that exists,
  is loaded by the test autoloader, and fatals in production because nothing requires it.
- [[mutation-sweep-branch-only-false-confidence]] — another "green run proves less than it looks".
- [[plain-object-is-not-an-insertion-ordered-map]] — the other s59 find; both came from an
  adversarial pass rather than from the suite.
