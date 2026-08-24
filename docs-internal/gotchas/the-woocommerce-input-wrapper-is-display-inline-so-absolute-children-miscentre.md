# `.woocommerce-input-wrapper` is `display: inline`, so an absolutely-positioned child centres on the line box

**Namespace:** `[shipping/checkout]`
**Found:** s90 (25.08.2026).

## The trap

A spinner placed inside a checkout field's wrapper with the usual

```css
.woodev-location-spinner { position: absolute; top: 0; bottom: 0; display: flex; align-items: center }
```

sits slightly high in the field. The operator spotted it on sight; measured on the rig:

| | top | bottom | height | centre |
|---|---|---|---|---|
| `.woocommerce-input-wrapper` | 388 | 418 | 30 | **403** |
| the select2 control inside it | 382 | 432 | 50 | **407** |

WooCommerce's field wrapper is `display: inline`. Its box is therefore a **line box sized by
`line-height`** (30.8px here), not the box of the control it contains. `top: 0; bottom: 0` resolve
against that line box, so the ring centres 4px above the control.

No CSS bridges it: the gap is the theme's `line-height` plus the widget's own padding, neither of
them a constant a stylesheet can encode.

## ✅ What to do

Size the indicator to the box of the control the customer can actually SEE, and keep the CSS as the
fallback for when there is nothing laid out to measure:

```js
var control = host.querySelector( '.select2-selection' ) || field;
var hostBox = host.getBoundingClientRect();
var controlBox = control.getBoundingClientRect();

if ( controlBox.height ) {
    spinner.style.top = ( controlBox.top - hostBox.top ) + 'px';
    spinner.style.height = controlBox.height + 'px';
    spinner.style.bottom = 'auto';
}
```

`.select2-selection`, not `.select2-container`: the selection carries the border the customer reads
as "the field", and is 4px taller than its own container.

**The same misalignment affects `location-typeahead.js`'s own search spinner**, which this does not
touch — nobody has reported it, and widening the change to a second widget on a hunch is how a small
fix becomes a regression.

## Related

- [a-cached-asset-under-an-unchanged-ver-reads-as-a-broken-feature](a-cached-asset-under-an-unchanged-ver-reads-as-a-broken-feature.md) — the other rig trap from the same pass
