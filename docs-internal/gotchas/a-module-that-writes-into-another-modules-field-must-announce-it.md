# [framework/wiring] A module writing into another module's field must ANNOUNCE the write — otherwise the owner reads it as the user's

> Namespace: `framework/*` — added session 75 (2026-08-16), issue #339.

## The trap

Two modules share a DOM field. One owns it and watches `change` to decide whether the value
still matches its own confirmed state. The other writes into it programmatically.

The owner cannot tell the two apart. A programmatic write arrives as exactly the same event a
human edit does, carrying exactly the same kind of value — so the owner applies its rule
("the text no longer matches the record → the record is stale") and throws away state the
user actually created.

Measured (#339): `pickup-mount.js` writes a selected pickup point's address into the shared
WooCommerce address fields. `location-cascade.js` owns those fields. The carrier answers
«Москва» in Cyrillic while the provider had said «Moscow» (the account locale transliterates
— see [[a-locality-display-name-is-not-an-identifier]]), so the cascade dropped the
settlement record and the next address search left **without `within`**, country-wide,
although the customer had picked the city.

**One differing character is enough.** «Санкт-Петербург» vs «Санкт Петербург», «пос.
Ватутинки» vs «поселок Ватутинки» — the failure does not need an exotic locale.

## Why the obvious fixes are wrong

❌ **Don't write the field at all.** The point may legitimately stand in a NEIGHBOURING
settlement (ordinary for New Moscow), and these fields are what reach the order. Not writing
fixes the search scope by corrupting the delivery address.

❌ **Re-resolve the value through a service first** so both sides agree byte for byte. Measured
on the rig (#339): normalizing the point's address returns the key of the DEEPEST resolved
object — a house or a street, never the settlement — costs a paid uncached call per selection,
is an OPTIONAL provider capability whose absence THROWS rather than returning null, and on a
carrier-shaped string can answer a different address entirely (`Москва Внуково Центральная 6`
→ `г Москва, ул Центральная, д 6`, `qc = 3` = "alternatives exist, needs manual review").

❌ **Compare keys instead of text.** The carrier's address is a plain string; it has no key.

## Correct: announce, synchronously, BEFORE the write

```js
// writer — pickup-mount.js
fireDocumentEvent( 'woodev_pickup_address_replacing', { fields: fields, fieldId: config.fieldId } );

writeAndFireChange( target + '_city', locality, locality );   // …then write
```

```js
// owner — location-cascade.js
function handlePickupAddressReplacing( event ) {
	var fields = event && event.detail ? event.detail.fields : null;
	if ( ! fields || 'object' !== typeof fields ) { return; }

	entries.forEach( function ( entry ) {
		Object.keys( fields ).forEach( function ( fieldId ) {
			if ( ! nodeInfo( entry, fieldId ) ) { return; }
			writeSilently( entry, fieldId, String( fields[ fieldId ] ) );   // re-seed `resolved`
		} );
	} );
}
```

`dispatchEvent()` runs listeners **inline**, so the re-seed completes before the write's own
`change` is fired — no ordering flag, no timer, no suppression window to leak.

## The two properties that make it safe

1. **Re-seed, never suppress.** The values still land in the fields and the store; only the
   owner's *confirmed record* survives untouched. Suppressing the write would trade one bug
   for a wrong delivery address.
2. **Scoped to the announced write and nothing after it.** The very next genuine manual edit
   still invalidates. Pin this with a test — a fix that quietly deafens the watcher passes
   every test about the bug it fixed.

Rig-verified in one run (#339): announced write → record survives, next search carries
`within`; a hand edit right after → record invalidated, `within` gone.

## Related

- [[a-locality-display-name-is-not-an-identifier]] — why the two spellings differ at all.
- [[a-programmatic-parent-change-must-not-run-a-destructive-cascade]] — the same family: WooCommerce's own programmatic `change` events.
- [[a-dom-attribute-is-the-wrong-seam-on-a-woocommerce-checkout]] — publish cross-module state through a channel you own; this event is one.
- [[built-on-both-sides-with-no-caller-in-the-middle]] — a seam needs both halves wired AND a test that proves the wiring.
