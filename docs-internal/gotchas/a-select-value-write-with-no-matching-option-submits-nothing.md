# Writing `.value` to a `<select>` with no matching `<option>` submits nothing — and select2 will not redraw even when it works

**Namespace:** `[js/*]` · **Discovered:** s86 (2026-08-22), rig measurement on `:8973/classic-checkout/` (#460, #455, #447); half 3 added s86 (2026-08-23) from the operator's own rig pass (#465)

## The trap

Two separate failures, one root: **a `<select>` is not an `<input>` with a longer tag name.**

### Half 1 — the write silently evaporates

```js
el.value = 'Московская';   // el is a <select>
```

If no `<option>` carries that value, the HTMLSelectElement value setter selects **nothing**:
`selectedIndex` becomes `-1` and `el.value` reads back as `''`. No error, no warning.

Downstream that is not a cosmetic loss. jQuery's `.val()` on an unselected `<select>` returns
**`null`** (not `''` — that is the native getter), `jQuery.param()` puts it on the wire as a
PRESENT-but-empty `shipping_state=`, and `WC_Data::set_props()` skips only `null`, never `''`.
So WooCommerce stores an empty string over a good value, or — when the field never reaches the
POST at all — keeps its own `woocommerce_default_country` `RU:*` default forever. **Measured on
the rig: `wp_woocommerce_sessions` held `shipping_city = ""` in five sessions while
`usermeta.shipping_city` was still `Москва`. The customer's profile survives; the SESSION is
wiped, so the ORDER is what suffers.**

This is what made #460 look like a rendering bug when it was a persistence bug: the region field
came back empty because the value had never been stored, not because the render dropped it.

### Half 2 — even a *successful* selection leaves the widget stale

Fix half 1 by selecting a real option and you hit the next one:

```js
el.selectedIndex = i;   // correct value, invisible change
```

select2/selectWoo renders its **own** detached UI and re-reads the underlying `<select>` only
when a `change` event fires (`selectWoo.full.js:5345-5354`). Assigning `selectedIndex` fires
nothing, so the customer sees the old or empty selection while the form posts the new value.

The asymmetry that makes this genuinely hard to reason about: selectWoo also runs a
`MutationObserver` with `childList: true, subtree: false` (`selectWoo.full.js:5573-5611`), so a
**freshly appended** selected `<option>` *is* picked up automatically — while **reusing an
existing** option only mutates a node and is not. Two code paths that look equivalent behave
differently.

## The fix

Select a real option, then nudge the widget with a **namespaced** trigger:

```js
function applyValueToElement( el, value ) {
    if ( 'SELECT' !== el.tagName ) {
        el.value = value;
        return;
    }

    // 1. an existing option whose value already matches
    // 2. an existing option whose visible TEXT matches (related-list registers
    //    WC-canonical options whose value is wc_strtoupper( trim( label ) ))
    // 3. otherwise a synthetic option, reused across calls, not appended each time
    // ...then, for cases 1 and 2:
    refreshSelectWooWidget( el );
}

function refreshSelectWooWidget( el ) {
    if ( ! window.jQuery ) {
        return; // plain <select>: nothing to redraw
    }

    window.jQuery( el ).trigger( 'change.select2' );
}
```

**Why `change.select2` and not `change`:** jQuery invokes only handlers whose namespace is a
superset of the triggered one. So `change.select2` reaches select2's internal listener and
**never** the module's own un-namespaced `change` gate — which matters enormously here, because
that gate (`handleFieldChanged`) runs a *destructive* cascade clear when it reads a parent field
as user-changed. A plain `change` would wipe the descendants this restore exists to preserve.
Verified against jQuery's own `dispatch`/`handlers` matching, not inferred.

A native `<select>` has no `.change()` method, so the native world does not fire either — the
degradation is safe by construction, which is what lets the jsdom tests (no select2 package at
all) exercise the same code path.

## Half 3 — select2 caches the option's data ON THE NODE, so mutating it in place lies

Fix half 2 and you hit the deepest one. **selectWoo stores a result's `{id, text}` on the
`<option>` element itself**, under the static key `$.data( option, 'data' )`
(`SelectAdapter.prototype.item()` returns the cached object when present and only rebuilds it from
live DOM when absent). The widget renders from that cache, not from the node's current
`value`/`textContent`.

So reusing an `<option>` by mutating it — the obvious way to avoid appending a new node on every
write — leaves the widget showing the previous locality while the form submits the new one:

```js
optionValue:       "Tatarstan"
optionText:        "Tatarstan"
select2CachedData: { id: "Moscow", text: "Moscow" }   // measured on the rig
widgetShows:       "Moscow"
```

The signature is distinctive and worth recognising: **the first write works and every later one
does not.** The first write APPENDS a node, and selectWoo's own `MutationObserver`
(`childList: true, subtree: false`) notices an added child and rebuilds; every later write reuses
the same node, which the observer never sees.

Invalidate the cache before refreshing:

```js
if ( window.jQuery ) {
    window.jQuery.removeData( option, 'data' );   // static $.data key, not $( option ).data()
}

refreshSelectWooWidget( el );
```

And route the DESTRUCTIVE clears through the same write path — a clear that sets `el.value = ''`
directly leaves the widget rendering the old locality just as surely as a stale cache does.

**Why this survived a full test suite and two critics:** every existing test asserted
`select.value`, which was correct the whole time. The bug lived entirely in what the customer saw.
A test for a select2-backed field must assert the RENDERED text (`.select2-selection__rendered`),
and must drive at least THREE consecutive writes without a reload — two passes even when the cache
is stale, because the first append is what works.

## Rule of thumb

**Before writing a value into a checkout field, ask what element it actually is now.** In this
layer a field's tag is not fixed: `location-select-modes.js` replaces a plain `<input>` with a
`<select>` depending on a merchant setting, so the same `fieldId` is an `<input>` on one shop and
a select2-managed `<select>` on the next. Code that writes `.value` unconditionally is correct on
exactly one of them.

Corollary: **when a value "does not survive a reload", check whether it was ever stored before
looking at the render.** #460 burned a session's worth of hypotheses on the rendering path
because the empty field was assumed to be a display fault.

## Related

- [[jquery-trigger-change-fires-no-native-event]] — the other half of the two-event-worlds
  problem: there a real pick was invisible to a native listener; here a programmatic write is
  invisible to the widget. Same seam, opposite direction.
- [[a-programmatic-parent-change-must-not-run-a-destructive-cascade]] — why the namespaced
  trigger is load-bearing rather than a nicety: an un-namespaced `change` reaches the destructive
  gate.
- [[wc-uppercases-the-posted-state-and-flips-the-map]] — why a `related-list` region's
  `<option>` value is `wc_strtoupper( trim( label ) )` and therefore never matches this layer's
  own bare-name value vocabulary.
- [[a-locality-display-name-is-not-an-identifier]] — the same two-value-spaces disease at the
  record level.
