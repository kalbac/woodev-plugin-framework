# A button group that clips with `overflow: hidden` cuts the frame its button draws — and our own divider replaces one side of it

> Namespace: `admin-ui/*` — added session 164 (2026-10-09). Caught by the operator on the rig: the enabled bulk
> «Применить действие» button had no left edge and a short bottom edge.

## The trap

`.woodev-orders-bulk` draws ONE frame around the bulk picker and its apply button (`border`, `border-radius`,
`overflow: hidden`) and gives the button a grey `border-left` as the divider. Three things then conspire:

1. The enabled secondary button draws its blue frame on its **own** top, right and bottom — measured on the rig:
   `border-top/right/bottom: 1px solid rgb(56, 88, 233)`, `box-shadow: none`. The brief assumed WordPress's inset
   box-shadow frame; the computed style said otherwise. **Read `getComputedStyle()`, do not assume the mechanism.**
2. Our grey `border-left` replaced the one side the blue frame needed, so the frame was open on the left.
3. `overflow: hidden` clips everything outside the group: a SQUARE-cornered button lost its frame where the group's
   curve passes, and WordPress's focus ring (an OUTSIDE `box-shadow`) was half cut.

## ✅ Correct

```scss
&__apply.components-button {
	border-left: 1px solid wd.$border;                       // disabled: the group's grey divider
	border-radius: 0 calc( #{ wd.$radius-sm } - 1px ) calc( #{ wd.$radius-sm } - 1px ) 0;   // the group's INNER curve
	&:not( :disabled, [aria-disabled='true'] ) { border-left-color: currentColor; }          // enabled: closed frame
	&:focus-visible { box-shadow: inset 0 0 0 var( --wp-admin-border-width-focus, 2px ) var( --wp-admin-theme-color, currentColor ); }
}
```

Measured on the rig by injecting the compiled stylesheet (`npx sass --load-path=src …`) into the live orders page
and clipping a 4x screenshot of the group — disabled look unchanged, enabled frame closed, focus ring complete.
`tests/js/shipping-orders-page-bulk-frame-styles.test.js` pins the three decisions at source level (jsdom has no cascade).

## Related

- [flat-where-isolation-loses-to-a-longer-theme-selector](flat-where-isolation-loses-to-a-longer-theme-selector.md) — the other "our CSS is fine, the cascade is not" admin-ui trap
