# gotcha: a backdrop's `opacity` dims the dialog too when the dialog is its child

**Namespace:** `[admin-ui/modal]`
**Discovered:** s48 (2026-08-01), on the rig — no test could see it

## Symptom

The pickup map opened and everything worked, but the whole dialog was translucent: the checkout
page's own labels and inputs showed straight through the map tiles and the point card. It reads as
"the map failed to load properly", not as a styling bug.

## Root cause

`woodev-modal.js` puts BOTH classes on ONE element and nests the dialog inside it:

```html
<div class="woodev-modal woodev-modal-backdrop">
    <div class="woodev-modal__content"> … the whole dialog … </div>
</div>
```

The stylesheet then dimmed it the way WooCommerce's own backbone modal does:

```css
/* ❌ */
.woodev-modal-backdrop { background: #000; opacity: 0.7; }
```

CSS `opacity` creates a stacking context and applies to the **entire subtree**. WooCommerce can
afford this because its backdrop is a SEPARATE element, a sibling of the dialog. Ours is the
dialog's ancestor, so every child inherited the 0.7.

Note the computed values look innocent when inspected on the dialog itself — `.woodev-modal__content`
reports `opacity: 1` and `background: rgb(255,255,255)`. The dimming is entirely in the ancestor.

## Fix

Dim with an alpha background, which paints only that element:

```css
/* ✅ */
.woodev-modal-backdrop { background: rgba( 0, 0, 0, 0.7 ); }
```

The alternative — making the backdrop a real sibling — also works, but it costs an extra element and
`woodev-modal.js`'s focus trap and teardown are written against the single-root shape.

## Why no test caught it

jsdom does not render. `getComputedStyle` in jest resolves declared values, not composited output,
and nothing in the DOM shape is wrong — the markup and the class list are exactly as designed. Only
a real browser paints the subtree, and only a screenshot shows it. Three separate presentation
defects on the SP-5 branch were invisible to 391 green jest tests and visible in the first rig
screenshot; this was one of them. The other two: a CSS rule sizing `.woodev-pickup-map` matched
nothing because no element ever carried that class, and the bulk points query omitted the buyer's
locality so the map was empty in a city full of points.

**Rule of thumb:** any change whose failure mode is "it looks wrong" must be checked in a browser.
Green jest on presentation code proves the DOM, never the pixels.

## Related

- [[wp-scripts-css-enqueue-version-by-mtime]] — the other stylesheet trap on this feature
- [[playwright-mcp-does-not-fire-wc-checkout-ajax]] — use chrome-devtools MCP for rig checks
- `docs-internal/specs/2026-08-01-sp5-pickup-map-rework-design.md` — D-13, the generic modal
