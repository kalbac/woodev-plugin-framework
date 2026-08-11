# A programmatic parent `change` must not run a destructive cascade

**Namespace:** `[shipping/checkout]` · **Discovered:** s66 (2026-08-11), rig measurement on `:8973/classic-checkout/` (#272)

## The trap

A dependent-select cascade is **destructive by design**: when the region changes, the city chosen
for the old region is stale, so `cascadeChild()` clears the child's value in the store and in the
DOM before asking the source for the new option set.

WooCommerce fires **programmatic `change` events on address fields while initialising the
checkout**, carrying the value the field already has. The adapter's `meaningful` gate lets those
through legitimately — it only filters the `'*'` wildcard and empties-without-`originalEvent`, and
a non-empty `'77'` is neither. So on **every page load** a cascade ran against a parent that had
not changed, and destroyed the value the server had just rendered.

The damage does not come from the clearing itself but from the **window it opens**. Measured, in
order:

```
DCL      t=0      <input name="billing_city" value="Москва">   server rendered it correctly
t=7002   cascadeChild  $child.val( '' )                        synchronous wipe: DOM + store
t=7003   GET  …/field-source/billing_city?parent=77&country=RU  source asked
t=7054   POST /?wc-ajax=update_order_review   billing_city=''   WC's OWN init update_checkout
t=9957   fillSelect → 'Москва'                                 answer lands 2.9 s later
```

WooCommerce's own initialisation `update_checkout` serialises the form ~50 ms after the wipe and
~2.9 s **before** the source answers, so the empty value reaches `update_order_review()` and is
written into `WC()->customer`. From then on the server renders an empty field on every load and
the loss is self-sustaining — the page erases its own value, permanently, with no user action.

**Two things make this hard to read from the code.** First, `#272` was filed against the *takeover
conversion* (`ensureSelect()` building a `<select>` with no options), because the measured symptom
is `options: [""]`; the conversion is downstream — it faithfully converts a field the cascade has
*already* emptied (`fromVal: ""` at the `replaceWith` call). Second, **no `change` event fires on
the child at all** (`changes: []`), so "stop the conversion from firing `change` before it
transfers the value" — the obvious fix, and the one the card proposed — would have changed nothing.

A third, independent defect hid in the same function: `cascadeChild()` captured `$child` **before**
the request and wrote the response into that captured reference. The takeover replaces the node
mid-flight, so the late restore landed on a **detached element** — invisible to the customer and to
form serialization alike, which is why even the 2.9 s-late recovery never showed up on screen.

## The fix

Track, per child, the parent value the child is already consistent with, and gate the destruction
on a real change. Two records, not one, because they answer different questions:

- `entry.resolved[ childId ]` — the parent value the child's **value** is consistent with. Seeded
  in `prefill()` from the parent's rendered value, because the server rendered the pair
  *together*. Gates the clearing.
- `entry.fetched[ childId ]` — the parent value the child's **option set** was fetched against.
  Gates the request. It must be separate: at load the value is already consistent (nothing to
  clear) while the options have never been fetched, and a dependent select still needs them —
  `applyTakeover()` cannot supply them, it sends `parent: ''`.

Released on `.fail()` (`fetched` only), or a repeat of the same change never retries.

```js
// ❌ every cascade destroys, including the no-op one WooCommerce fires at init
store.setValue( childId, '' )
$child.val( '' )

// ✅ destroy only when the parent actually changed
var changed = entry.resolved[ childId ] !== parentValue

if ( changed ) {
    store.setValue( childId, '' )
    $child.val( '' )
    entry.resolved[ childId ] = parentValue
}
```

Re-resolve the node in `.done()` (`$child = $( '#' + childId )`) instead of trusting the captured
reference, and when the source omits the current value, branch on the same verdict: parent changed
→ the value is genuinely stale, clear it; parent unchanged → the value is legitimate and the source
merely did not return it (a truncated or query-scoped set), so **re-add it as a selected option**
rather than dropping it.

Compare parent values through a normaliser: `.val()` yields `undefined` for a missing element and
`null` for an option-less `<select>`, while the same value arrives from the server as a string, so
an un-normalised comparison declares a change where there is none.

## Rule of thumb

**A destructive reaction needs a real transition, not an event.** An event says "something fired",
not "the value differs" — and any framework that re-renders will fire plenty of the former. Key the
destruction on a remembered previous value.

And when a value is destroyed in a window that some *other* actor closes, the harm is set by that
actor's schedule, not yours: here a 3-second async gap was crossed by WooCommerce's own 50 ms form
serialization, which converted a transient UI blip into permanent session damage. Ask what else
reads this state before the answer lands.

## Related

- [[checkout-field-takeover-woocommerce-states]] — the takeover's own value-preservation guards
  (`re-add value as option`, the `updated_checkout` safety net). They were never reached here: the
  cascade had already emptied both the DOM and the store before the takeover ran.
- [[custom-checkout-field-is-empty-on-reload-by-construction]] — the *other* reason a checkout
  field is empty after a reload. That one is by construction and has no bug to find; this one is a
  real defect, and the two look identical from the browser. Distinguish them by reading the
  rendered `value` **attribute**: present means the server knows the value and the client is
  losing it.
- [[wc-does-not-save-the-address-until-every-required-text-field-is-filled]] — the same
  `update_checkout` path from the other side: when the client gate is *closed* nothing persists at
  all. Both must be understood to reason about what the checkout actually stores.
- [[built-on-both-sides-with-no-caller-in-the-middle]] — the class of defect that only a real page
  reveals; this adapter had **no** jest coverage at all until #272, which is why a load-time
  regression could live in it indefinitely.
