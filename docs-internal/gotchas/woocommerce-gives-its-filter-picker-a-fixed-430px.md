# WooCommerce gives `FilterPicker` a FIXED 430px, so a third control breaks the filter row

> Namespace: `admin-ui/*` — added session 128 (2026-09-09). Caught by the operator on a
> medium screen; the cause is invisible from our own stylesheet, which sets no width at all.

## The trap

A filter row built as a plain flex container looks right on a wide screen and falls apart
on a normal laptop — controls stacking one per line with large empty gaps beside them. It
reads as a wrapping bug in our CSS, and our CSS contains no width rule to blame.

Measured on the rig at a 1100 px viewport:

```text
.woodev-orders__basic-filters   860 px   (the row)
.woocommerce-filters-filter     430 px   (carrier picker)
.woocommerce-filters-filter     430 px   (date picker)
```

430 + 430 = 860 exactly. Nothing else fits, so a third control — a toggle here — wraps, and
then the date picker follows it. Row height went 78 → 172 px at 1100 and 900, and 228 px at
782.

**430 px is WooCommerce's own fixed width**, not a minimum and not content-derived: the
carrier picker was showing «Все перевозчики (71)».

## Why Analytics never shows it

WooCommerce's own reports put fewer controls in a wider container, so the fixed width never
runs out of room there. Copying the layout from Analytics — which is the right instinct, and
what §D7 did — copies a constraint that only holds at their control count.

## ✅ Correct — bound it inside your own container

Scope the override so it cannot leak into anything else WooCommerce renders:

```scss
.woodev-orders__basic-filters {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;

	.woocommerce-filters-filter {
		flex: 0 1 auto;
		width: auto;
		min-width: 200px;
		max-width: 430px;

		.woocommerce-filters-label {
			width: auto;
		}
	}
}
```

The inner `.woocommerce-filters-label` also carries the width and has to be released, or the
child keeps the parent's old size.

## Measure it, do not eyeball it

The useful measurement is the ROW HEIGHT at several widths, which turns "looks wrong" into a
number that a later change can be compared against:

```js
for ( const width of [ 1600, 1100, 900, 782 ] ) {
	await page.setViewportSize( { width, height: 1000 } );
	const box = await page.locator( '.woodev-orders__basic-filters' ).boundingBox();
	console.log( width, Math.round( box.height ) );   // 82 / 78 / 78 / 78 once fixed
}
```

⚠ Anything that ADDS a control to this row — #841's «только новые» filter is the next one —
has to re-run that measurement rather than assume the room is still there.

## Related

- [a-filter-option-keyed-key-instead-of-value-disables-the-filter-button](a-filter-option-keyed-key-instead-of-value-disables-the-filter-button.md) — the other s128 case of a vendor contract that our own code could not reveal
- `specs/2026-09-07-sp10-orders-page-design.md` §D7 — the row this constrains
