# The §8 classic adapter reverts a `<select>` the location cascade owns — and the guard was a name suffix

**Namespace:** `[shipping/checkout]` · **Discovered:** s87 (2026-08-23), browser measurement on the
rig with the renderer registry, every widget `detach()` and every DOM-mutating jQuery method wrapped
and stack-traced (#466)

## The trap

Two of our own modules enhance the same checkout node, and only one of them knows it.

`checkout-field-classic.js` (`runTakeover()`) walks **every** field in the §8 store and calls
`applyTakeover()` on it. That function reads *"this field is not a takeover field for this
country"* as *"revert it to a plain text input"*, via `ensureText()`.

A `source_kind === 'location'` field is **never** a takeover field — `Checkout_Handler::inject()`
skips takeover fields outright, so the descriptor carries no `takeover` map at all. So that revert
branch was the **only** thing the adapter ever did to a location field, and what it did was destroy
the `<select>` the location cascade had attached a hundred milliseconds earlier.

## What it looked like, and why it fooled everyone

The symptom read as an **attach-timing asymmetry**: the region field became a select2 in ~250 ms
while the settlement field stayed a plain `<input>` for seconds. Measured attach delays across runs
were 3.1 / 3.5 / 4.3 / 8.6 and 13.0 s. A 2.5× spread looks like the network, so two workers went
looking for a race in the cascade's own attach path and found nothing — correctly, because there
was nothing there.

The real timeline:

```
t= 126  ATTACH  shipping_city  ajax-select2  INPUT -> SELECT   (location-cascade.js, on boot)
t= 227  replaceWith #shipping_city
            at ensureText     (checkout-field-classic.js:497)
            at applyTakeover  (checkout-field-classic.js:517)
            at                (checkout-field-classic.js:598)  <- runTakeover()
   …    the field sits as a bare <input> under the same `name`
t=5981  updated_checkout -> reconcileAfterCheckoutUpdate() re-attaches the widget
```

The "delay" was never a timer. It is the length of the **first `update_order_review`** — the next
event that makes the cascade reconcile. Confirmed by coincidence in one run: the field re-attached
at 41480 ms while that request completed at 41467 ms, 13 ms apart.

**The region survived by accident.** `isWcManagedField()` matches `/(^|_)state$/`, so
`shipping_state` was skipped — a NAME heuristic, not an ownership fact. `shipping_address_1` escaped
for a third unrelated reason: it is a plain `<input>`, and `ensureText()` returns early on
`! $field.is( 'select' )`. Exactly one field in the layer was exposed, and it happened to be the one
with no protective suffix.

**It does not reproduce in jsdom.** `ensureText()` only bites a field that is already a `<select>`,
and in jsdom there is no real select2 to produce one.

## ❌ Wrong

```js
function isWcManagedField( fieldId ) {
	return /(^|_)state$/.test( fieldId )        // a name, not an owner
}

function applyTakeover( entry, fieldId, country ) {
	if( isWcManagedField( fieldId ) ) {
		return
	}
	// …
	if( ! entry.store.takeoverFor( fieldId, country ) ) {
		ensureText( entry, fieldId )            // fires for EVERY location field
		return
	}
```

## ✅ Correct

```js
function isLocationOwnedField( entry, fieldId ) {
	var field = entry.store.getField( fieldId )

	return !! field && 'location' === field.source_kind
}

function applyTakeover( entry, fieldId, country ) {
	if( isWcManagedField( fieldId ) ) {
		return
	}

	if( isLocationOwnedField( entry, fieldId ) ) {
		return
	}
```

Rig-verified after the fix: both `#shipping_state` and `#shipping_city` are select2-backed by
t=244 ms, neither reverts, and the address lock is still applied.

## The general rule this is an instance of

**A module that iterates "every field in the store" is forming an opinion about fields it does not
own.** The §8 store holds every managed field, including the three the location cascade owns — that
is how `runTakeover()` reached one in the first place. Guard by OWNERSHIP (`source_kind`), never by
a name pattern that happens to cover today's cases.

The same file has a **second** iterator with the same shape: the `updated_checkout` subscriber
writes `$field.val( stored )` into a possibly-`<select>` field and then calls `maybeInitSelect2()`
on it. Writing a bare value into a `<select>` with no matching `<option>` selects **nothing** and
drops the field from the POST — the measured #447 data loss, from a different module. Filed as
**#473**; the §8 store was measured to hold values for location fields
(`getValue( 'shipping_state' ) === 'Novosibirskaya'`), but the firing condition itself was not
measured, which is why it is a card and not part of the #466 fix.

## Related

- [[a-select-value-write-with-no-matching-option-submits-nothing]] — the value-space disease this
  adapter can feed into, from the other end.
- [[a-programmatic-parent-change-must-not-run-a-destructive-cascade]] — the earlier defect in this
  same file, also caused by it acting on an event that was not about the customer.
- [[rig-checkout-url-is-the-block-checkout]] — none of this exists on `/checkout/`.
