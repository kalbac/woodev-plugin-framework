# A sortable WooCommerce table header stands 16–17px to the right of its values

**Namespace:** `[admin-ui/tables]` · **Discovered:** s240 (21.11.2026, #1907 and #1909)

## What happens

In a `TableCard` the label of a sortable column («Заказ», «Дата», «Артикул») starts 17–19px to the right of the values under it, while the unsortable headers line up.

## Root cause

A sortable header cell is `th.woocommerce-table__header.is-left-aligned.is-sortable` pad 16px on the left, and holds a `.components-button` that pads 12px more. A plain cell pads whatever the table sets. Overriding `th, td { padding }` does not reach the sortable header: WooCommerce's rule has three classes, so a `.woodev-orders th` (0,2,1) loses.

## Fix

- ❌ `.woodev-orders th, .woodev-orders td { padding-left: 8px }` — the sortable heading keeps 16 + 12.
- ✅ `.woocommerce-table__header.is-left-aligned.is-sortable { padding-left: <cell padding>; .components-button { padding-left: 0 } }` (≥ 3 classes). Measured on the rig: label-to-value delta 17px → 1px. The sort arrow hangs in the button's own padding and does not move the label.
- Measure with the label's own text node against the value's text node, not the cell boxes: the boxes were always equal.

## Same pass: what fits a 1024px screen

With the WP menu expanded the card is 784px wide (908px folded, 1040px at 1280). The delivery table needed 849px at 12px cell padding; 8px padding made it 784. A status badge is `nowrap`, so «Не доставлено» (122px) and a row of four or five 32.5px action icons widen the table beyond that: at 8px the first two fit, five icons (810px) still scroll inside the card.

## Related

- [a-tooltip-nested-in-a-sortable-header-button-does-nothing](a-tooltip-nested-in-a-sortable-header-button-does-nothing.md) — the other trap of a sortable header
