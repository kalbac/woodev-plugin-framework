# A wide cell inside WooCommerce's `SegmentedSelection` breaks its `nth-child` borders

**Discovered:** s132 (12.09.2026), building the «Период» control for #855.

## The trap

WooCommerce's `SegmentedSelection` draws its column divider and its row rules **by child
parity**, not by grid position (`assets/client/admin/components/style.css`, WooCommerce 11.1.0):

```css
.woocommerce-segmented-selection__container { display: grid; grid-template-columns: 1fr 1fr;
                                              border-top: 1px solid #ccc; border-bottom: 1px solid #ccc;
                                              background-color: #ccc }
.woocommerce-segmented-selection__item:nth-child(2n)   { border-left: 1px solid #ccc; border-top: 1px solid #ccc }
.woocommerce-segmented-selection__item:nth-child(2n+1) { border-top: 1px solid #ccc }
.woocommerce-segmented-selection__item:nth-child(-n+2) { border-top: 0 }
```

So the rules assume «even child = right column». Put ONE full-width cell (`grid-column: 1 / -1`)
at the top and every following item's parity is shifted by one: the LEFT column takes the divider
that belongs to the right one, the right column loses it entirely, and the «no top border on the
first row» rule lands a row out.

**Nothing errors, nothing logs, and the layout is geometrically correct** — the wide cell really
does span both columns. Only the borders are on the wrong sides, which reads as a sloppy grid
rather than as a rule mismatch, and it was the operator who caught it on his own rig pass.

## ❌ Wrong

```jsx
<div className="woocommerce-segmented-selection__container">
  <div className="woocommerce-segmented-selection__item wide">…</div>  {/* shifts parity */}
  { ten.map( … ) }
</div>
```

## ✅ Correct

Keep the container holding **only** the items their parity rules were written for, and put the
wide rows outside it:

```jsx
<fieldset className="woocommerce-segmented-selection">
  <div className="my-wide-row">…</div>
  <div className="woocommerce-segmented-selection__container">{ ten.map( … ) }</div>
  <div className="my-wide-row">…</div>
</fieldset>
```

The wide rows need no borders of their own: the container already carries `border-top` and
`border-bottom`, which is exactly the rule between each of them and the grid.

## How to check it in one call

Ask each item which side its border is on, and compare with its measured `x`:

```js
[ ...document.querySelectorAll( '.woocommerce-segmented-selection__item' ) ].map( ( it ) => ( {
    x: Math.round( it.getBoundingClientRect().x ),
    borderLeft: getComputedStyle( it ).borderLeftWidth,
} ) );
// every left-column item must read borderLeft 0px, every right-column item 1px
```

## Two more things measured in the same component

- **It declares no `cursor` anywhere** — not on the item, not on the label. Rows read as
  non-clickable until you add `cursor: pointer` yourself. That is true of their own picker too.
- `.woocommerce-segmented-selection__label` carries `padding: 12px 12px 12px 36px`; the 36px is
  room for the `::before` dot on the checked entry, so text in it is never visually centred by
  accident.

## Related

- [react-dates-renders-a-fixed-300px-month-so-a-padded-wrapper-overflows-it](react-dates-renders-a-fixed-300px-month-so-a-padded-wrapper-overflows-it.md) — the other half of the same control, same session
- [wc-date-throws-on-a-half-filled-custom-range](wc-date-throws-on-a-half-filled-custom-range.md) — why this control exists at all
- [woocommerce-gives-its-filter-picker-a-fixed-430px](woocommerce-gives-its-filter-picker-a-fixed-430px.md) — the same class of trap: their metric, invisible from our stylesheet
