# `disabled` drops a checkout field from the form; `readonly` is inert on a `<select>`

**Namespace:** `[shipping/checkout]`
**Found:** s90 (25.08.2026).

## The trap

Making a checkout field non-interactive while a request is in flight looks like a one-liner. Every
obvious instrument has a cost, and two of the three are silent. All three measured live on the rig:

| Instrument | Value still in `form.checkout.serialize()` | Actually blocks the customer |
|---|---|---|
| `el.disabled = true` | **NO** — the field leaves the serialized form entirely | yes |
| `$( el ).select2( 'enable', false )` | **NO** — it is implemented on top of the native attribute | yes |
| `$( el ).select2( 'readonly', true )` | — | **throws**: the v3-era string command does not exist in the build WooCommerce ships |
| `el.readOnly = true` on an `<input>` | yes | yes |
| `el.readOnly = true` on a `<select>` | yes | **NO** — the element has no `readOnly` property at all and the option still changes |

The dangerous row is the first. WooCommerce builds `update_order_review` from
`$( 'form.checkout' ).serialize()`, and a disabled control is not serialized — so for the length of
the disabled window, any `update_checkout` (from an unrelated field, from WooCommerce's own churn)
reprices the order **without that field**. Measured directly: with `shipping_city` disabled,
`shipping_city=` was absent from the serialized form.

The window is not theoretical. A `/select` round trip on the rig is 2.4–4.5 seconds.

## ✅ What to do

Block the pointer, state it to assistive tech, and leave the wire alone:

```js
host.classList.add( 'woodev-location-field-busy' );  // CSS: pointer-events: none; touch-action: none
el.setAttribute( 'aria-busy', 'true' );
el.setAttribute( 'aria-disabled', 'true' );

if ( 'readOnly' in el ) {   // real on <input>, absent on <select>
    el.readOnly = true;
}
```

`pointer-events` + `touch-action` is what the widely-circulated select2 read-only recipe uses too
(keyed on `select[readonly].select2-hidden-accessible + .select2-container`). Prefer our own host
class over that selector: it does not couple the rule to markup select2 owns.

**A locked field that is EMPTY by construction may still use `disabled`** — the address lock does,
and correctly: there is no value to drop.

## Related

- [wc-does-not-save-the-address-until-every-required-text-field-is-filled](wc-does-not-save-the-address-until-every-required-text-field-is-filled.md) — the other place WooCommerce's own serialization decides what our layer can do
