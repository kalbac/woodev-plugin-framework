# Wrapping `window.wc.addressAutocomplete.providers` touches a namespace, not a contract — and two implementation traps along the way

**Session:** s69 (12.08.2026, location-provider Task 12) · **Context:** per-country arbitration
against WC Address Autocomplete (spec D2), building on the s67 measurement in gotcha
[[wc-address-autocomplete-hosts-only-address1-and-flattens-identity.md]].

## The trap

`location-cascade.js`'s suppression module (§ "WC Address Autocomplete suppression") replaces
each entry of `window.wc.addressAutocomplete.providers` with a delegating clone whose
`canSearch()` returns `false` for our own countries. This is the ONLY client-side lever s67
found for the mixed-country case the server-side full kill (`woocommerce_address_providers` →
`[]`) must not touch (a store selling to both RU and, say, DE must keep WC's own autocomplete
alive for DE).

**`window.wc.addressAutocomplete` is a global object a WooCommerce script happens to leave
behind — there is no `@wordpress/*` package, no semver, no deprecation cycle.** Nothing commits
WC to keeping this shape across a major version. Re-verify the whole thing — `providers` keyed
by id, `serverProviders` array, frozen provider objects, the live per-country arbitration loop in
`address-autocomplete.js`'s `setActiveProvider()` — against `woocommerce/woocommerce` trunk on
every WC major bump this framework targets. If the shape moves, this module must fail SAFE: the
fence (`! window.wc || ! window.wc.addressAutocomplete || ! window.wc.addressAutocomplete.providers`)
already means a renamed/restructured namespace degrades to "we do nothing," never a hard crash —
keep it that way when adapting to a new shape rather than loosening the fence.

## Trap 1 (implementation): shadowing a frozen inherited property throws in strict mode

The natural way to write "a clone of `provider` with one method overridden" is:

```js
var clone = Object.create( provider );
clone.canSearch = function( country ) { /* ... */ };  // THROWS
```

`provider` is `Object.freeze()`d by WC's own registration code
(`address-autocomplete-common.js`), so `provider.canSearch` is a non-writable, non-configurable
**inherited** property once `clone`'s prototype is `provider`. Plain assignment goes through
`[[Set]]`, and `[[Set]]` walks the prototype chain: finding a non-writable data property up the
chain rejects the write — in strict mode (this file is `'use strict'`) that is a `TypeError`,
thrown from inside the very `Object.keys(registry).forEach()` loop that is supposed to suppress
WC's autocomplete, i.e. exactly the country-change handler a real customer's `change` event would
run. The fix is `Object.defineProperty()`, which installs a fresh **own** property on `clone` and
bypasses `[[Set]]` entirely — legal regardless of what the prototype's descriptor says:

```js
Object.defineProperty( clone, 'canSearch', {
	value: function( country ) { /* ... */ },
	writable: true, enumerable: true, configurable: true,
} );
```

General rule, not specific to this file: **shadowing a property that is non-writable on an
object's prototype chain must use `defineProperty()`, never assignment** — this applies to any
delegate/decorator built via `Object.create()` over a frozen (or otherwise locked-down) base.

## Trap 2 (test authoring): a fixture helper that returns the SAME container it hands to the code under test cannot prove non-mutation

The first version of this module's jest fixture looked like:

```js
function installWcAddressAutocomplete( ids ) {
	const providers = {};
	ids.forEach( ( id ) => { providers[ id ] = Object.freeze( { /* ... */ } ); } );
	window.wc = { addressAutocomplete: { providers, /* ... */ } };
	return providers;               // ← the SAME object as window.wc.addressAutocomplete.providers
}

const original = installWcAddressAutocomplete( [ 'google' ] );
boot( /* triggers the wrap, which does registry[id] = clone */ );
expect( original.google ).toBe( /* the pre-wrap object */ );   // FAILS — reads the CLONE
```

`original` is not a snapshot — it is the exact same `providers` dictionary the source code later
mutates in place (`registry[id] = wrapProvider(...)` where `registry === providers`). Reading
`original.google` AFTER the wrap ran does not return "the object as it was when captured"; it
returns whatever currently lives at that key, i.e. the clone. Four assertions in this file failed
this way with a genuinely confusing symptom — `Object.isFrozen(original.google)` reporting
`false`, as if a frozen object had been un-frozen, when in fact the wrap was working exactly as
intended and `original.google` was quietly resolving to the (never-frozen) clone every time it
was read.

The fix: capture the SPECIFIC provider object into its own variable **before** triggering the
code under test, not the container it lives in —

```js
const providers = installWcAddressAutocomplete( [ 'google' ] );
const originalGoogle = providers.google;   // snapshot the OBJECT, not the container
boot( /* ... */ );
expect( window.wc.addressAutocomplete.providers.google ).not.toBe( originalGoogle );
expect( Object.isFrozen( originalGoogle ) ).toBe( true );
```

General rule: **when a fixture factory returns a container that the code under test will mutate
in place, "capture the return value" is not the same as "capture a pre-mutation snapshot" — pull
the specific nested reference out into its own variable before the mutating call.**

## Related

- [[wc-address-autocomplete-hosts-only-address1-and-flattens-identity.md]] — the s67 measurement
  this Task builds on (registry shape, why `canSearch` needs no per-field/region context, the
  documented server-side full kill)
- `docs-internal/specs/2026-08-12-location-provider-design.md` — D2 (per-country suppression),
  §4.7 (coexistence & degradation)
- `woodev/shipping-method/assets/js/frontend/location-cascade.js` — the suppression module
  section
- `woodev/shipping-method/checkout/class-checkout-handler.php::maybe_suppress_wc_address_providers()`
  — the server-side full-kill half (spec D2)
