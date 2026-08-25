# An empty list while the search is still running is not a zero result

**Namespace:** `[js/select-value-space]`
**Found:** s94 (26.08.2026), fixing #539.

## The trap

select2 asks `language.noResults` **whenever the rendered list is empty**. It has exactly one
question — "is there anything to show?" — and no notion of *why* there is not. So the moment any
code paints an empty list before the provider has answered, the customer is told
«Поиск не дал результатов» over a search that has not finished, or in the worst case has not even
been sent.

#539 walked straight into this. The fix narrows the popular list locally on each keystroke, which
is correct — but a term no popular entry matches narrows to `[]`, and that `[]` is rendered
immediately. Measured on the rig, region «Санкт-Петербург», term «Выборг»:

```
+211 ms  Searching… · Санкт-Петербург · Пушкин · Репино   ← the stale frame being removed
+413 ms  Поиск не дал результатов.                        ← WRONG: /suggest had not been sent
+6626 ms Поиск не дал результатов.                        ← only NOW is it true
```

That is strictly worse than the stale frame #539 set out to remove: a stale list is misleading, a
false "nothing exists" is an answer, and the customer acts on it.

This is the **same conflation #405 exists to prevent**, one layer up. #405 separated "the source
could not answer" (`unavailable`) from "searched, found nothing" (`noResults`). This adds the third
member of that family: **"the source has not answered YET"**, which is neither.

## Why the ordering is the whole fix

The guard is a single in-flight flag, and *where it is raised* is the entire difference:

```js
// ❌ raised after the local paint — the first render still sees `false`
if ( popularAvailable ) {
    success( { results: matchingPopular( seed.popular(), term ) } );
}
searchInFlight = true;

// ✅ raised before it — everything that could short-circuit has already returned above,
//    so by this line a real request is a certainty, not a prediction
searchInFlight = true;

if ( popularAvailable ) {
    success( { results: matchingPopular( seed.popular(), term ) } );
}
```

`noResults` then answers with WooCommerce's own `i18n_searching` while the flag is up, and with the
real empty-state string once the request settles — in both the resolved and the rejected branch, so
the flag can never stick.

## ⚠ The test that could not see it

The first version of the #539 tests sampled `config.language.noResults(...)` **after
`transport()` returned**. By then the flag was `true` in both orderings, so a probe that moved the
assignment to the wrong side left all 149 tests green — while the rig showed the defect plainly.

The hook has to be sampled **at render time**, because that is when select2 asks it: the callback
select2 hands the transport runs `processResults()` → `callback()` → `results:all` synchronously
(`selectWoo.full.js:3586-3600`), and an empty list's message is resolved inside that call.

```js
// ✅ the harness asks the hook from inside `success`, exactly as select2's own render does
const success = jest.fn( () => {
    seenNoResults.push( config.language.noResults( { term } ) );
} );
```

With that, the probe goes red. This is the same family as the fake-order lesson in
[select2-close-fires-before-select2-select](select2-close-fires-before-select2-select.md) and as
#525: a test that samples state at a moment the browser never samples it pins a fiction.

## The other half: ANY `success()` strips select2's own loading row

Found by the operator on the rig one round later, and it is the more general fact of the two.

select2 shows "Searching…" by PREPENDING a result row on `query`
(`Results.prototype.showLoading`, `selectWoo.full.js:940-954`) — and
`Results.prototype.append()` opens with `hideLoading()` (:856). So **every** call to the
transport's `success()` removes the indicator. Nothing about the payload matters; painting anything
at all takes it away.

That is invisible while the transport only calls `success()` once, at the end — the indicator is
supposed to go then. It becomes a defect the moment anything paints EARLY, which is exactly what
local narrowing does. The zero-match branch hid it, because an empty list re-stated "searching"
through the `noResults` hook above; the local-match branch showed a list with no indicator at all.

### ✅ Carry your own row, shaped as select2's

```js
// select2's own shape, from showLoading() — not invented
narrowed.push( { disabled: true, loading: true, text: searching( { term: term } ) } );
```

`disabled` is load-bearing and verifiable in source: `Results.prototype.option()` (:960-975) deletes
`data-selected` for a disabled item, and BOTH the click binding (`mouseup` delegated to
`.select2-results__option[data-selected]`, :1232) and `highlightFirstItem()` (:891-905) filter on
that attribute. So the row cannot be clicked, cannot be keyboard-selected, and never steals the
first-item highlight. Confirmed in the live DOM afterwards: `selectable: false`,
`aria-disabled="true"`.

Put it LAST so real matches stay where the customer looks, and omit it entirely when the host has
no localized `searching` string rather than inventing one.

**The general rule:** in a select2 ajax transport, calling `success()` before the real answer is a
supported thing to do — but it costs you the loading indicator, and you owe the customer a
replacement in the same payload.

## How to measure it again

On the rig, a settlement field under `ajax-select2` with a popular list, region «Санкт-Петербург»:
open the dropdown, type a term matching NO popular entry («Выборг»), and record the rendered rows
every 200 ms. The correct sequence shows «Searching…» alone until the provider answers.

## Related

- [gotchas/select2-close-fires-before-select2-select.md](select2-close-fires-before-select2-select.md) — the other measured select2 ordering fact
- [CURRENT-STATE.md](../CURRENT-STATE.md) — the checkout location layer
- #539, #405, #526
