# A second store instance silently diverges — the checkout store needs an instance registry

**Namespace:** `[shipping/checkout]` · **Discovered:** s45 (2026-07-31), building the SP-5 mount

## The trap

`window.WoodevCheckoutFieldStore` exposes only `{ createStore }` — a **factory**. The §8 classic
adapter calls it inside an IIFE and keeps the resulting instances in a local `stores` array that is
never exposed:

```js
var stores = Object.keys( window ).filter( … ).map( function( key ) {
    return { store: factory.createStore( config ), config: config }
} )
```

So a second script — SP-5's pickup mount — has no way to reach the instance the §8 gate is actually
reading. The obvious workaround is to call `createStore( sameConfig )` again, and it is **wrong in a
way that looks like a different bug**: two instances hold independent state, the mount writes the
chosen pickup point into instance B, the A2 gate reads instance A, and the order stays blocked with
a "выберите пункт выдачи" error while the customer can plainly see they selected one.

That symptom reads as "the gate is broken", not "there are two stores", and would burn a rig session.

## The fix

Give the store module an **instance registry**, and key the lookup on **field ownership**, not on
the plugin id:

```js
function getStoreForField( fieldId )   // → the registered store whose config declares fieldId, or null
```

Field ownership is the right key because `config_object_suffix()` already collapses distinct plugin
ids (`carrier-x`, `carrier_x`, `carrier.x` all sanitise to one global name — issue #142), so a
plugin-id key would be ambiguous exactly where it matters. Registration happens inside
`createStore()`, so existing callers need no change at all — the classic adapter's diff was zero
lines.

When two stores declare the same field id, the registry returns the most recently created one. That
is a **determinism tie-break, not a correctness claim**: in production it resolves to
`wp_localize_script` print order. Two plugins declaring the same checkout field id is a
misconfiguration, not a supported arrangement.

## Rule of thumb

Any storefront module that must agree with the §8 checkout field layer about a field's value writes
**through the store the layer itself registered** — resolved via `getStoreForField()` — and then
mirrors to the DOM (WooCommerce serialises the DOM on submit, so the mirror is required, not
optional). Never construct a parallel store from the same config.

## Related

- [[checkout-field-takeover-woocommerce-states]] — why the store exists and why raw DOM writes lose values
- [[react-missing-key-state-bleed-across-tabs]] — the same class of bug in React: one logical thing, two instances
