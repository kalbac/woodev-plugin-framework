# `react-dates` renders a fixed 300px month, so a padded wrapper overflows it

**Discovered:** s132 (12.09.2026), building the «Период» control for #855.

## The trap

`window.wc.components.DateRange` is a wrapper around `react-dates`, and `react-dates` sizes
`.CalendarMonth` at a **fixed 300px** regardless of its container. A WordPress `Popover` is 320px.
So the calendar fits with 20px to spare and **any horizontal padding on the wrapper breaks it** —
the month table overflows to the right and the day grid reads as slid out of alignment.

`padding: 16px` — an unremarkable line to write — is 32px of the 20px available.

Measured on the rig, WooCommerce 11.1.0:

| | WooCommerce `/analytics/orders` | ours, with `padding: 16px` | ours, fixed |
|---|---|---|---|
| popover | 320 | 320 | 320 |
| `.woocommerce-calendar` | **318** | **286** | **318** |
| `.CalendarMonth` | 300 — fits | 300 — overflows by 14 | 300 — fits |

WooCommerce gives its own calendar the full popover width and lets
`.woocommerce-calendar__inputs` carry the inset. Do the same: nothing horizontal on the wrapper,
and give the buttons below their own padding.

## ❌ Wrong

```scss
.my-period-custom {
    padding: 16px;   // squeezes 320 → 286; the month still wants 300
}
```

## ✅ Correct

```scss
.my-period-custom {
    padding: 0;
}

.my-period-actions {
    padding: 0 16px 16px;   // the buttons get the inset, the calendar does not
}
```

## How to check it in one call

```js
const p = document.querySelector( '.components-popover' );
const month = p.querySelector( '.CalendarMonth' ).getBoundingClientRect();
month.right - p.getBoundingClientRect().right;   // must be NEGATIVE (inside)
```

## Why the symptom misleads

The overflow presents as «the calendar is shifted right», which invites a fix to the calendar's
alignment — margins, `justify-content`, the popover's placement. None of those is the cause, and
each leaves the real one in place. Measure the month against the popover before touching anything.

⚠ Do not confuse it with the popover's own placement: a WordPress `Popover` is CENTRED on its
toggle, so a 320px popover under a 200px button overhangs by 60px on each side. That is
WooCommerce's own behaviour — their `FilterPicker` on the same row does it too (measured: toggle
200..403, popover 141..461) — and it is not a defect.

## Related

- [a-wide-cell-breaks-woocommerce-s-nth-child-grid-borders](a-wide-cell-breaks-woocommerce-s-nth-child-grid-borders.md) — the other half of the same control
- [woocommerce-gives-its-filter-picker-a-fixed-430px](woocommerce-gives-its-filter-picker-a-fixed-430px.md) — same shape of trap: a vendor metric our stylesheet never mentions
