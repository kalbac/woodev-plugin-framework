# A records table fits a 784px card or not by its widest value and its sort arrows, not by its `ch` widths

**Namespace:** `[admin-ui/tables]` · **Discovered:** s241 (22.11.2026, #1918)

## What happens

Ozon Seller «Товары» (883px) and «Сопоставление» (772px) scroll inside their card at a 1024px window with the WP menu expanded (cards of 784px and 750px). Shrinking a column's `width: <n>ch` changes nothing: the state column stays 130px whatever its rule says.

## Root cause

- A `width` in `ch` on a table cell is a floor. Chrome's auto table layout widens the column to its widest cell, and a `nowrap` badge or price is a rigid cell: «Не связан × 3» (114px) and «1000 – 1200 / —» (102px) fix their columns, whatever the first rows show. Measure over ALL rows, not the first three.
- Every sortable left-aligned heading carries a 24px sort arrow that is always laid out (invisible until the column is sorted) plus the WooCommerce button's 12px right padding: 36px of dead width per column, four columns in the products table.

## Fix

- ✅ `.woocommerce-table__header.is-left-aligned.is-sortable .components-button { padding-left: 0; padding-right: 0 }` and the cells pad 8px (as «Заказы доставки»): 883 → 784px, 772 → 750px.
- ✅ Text columns (name, article) are the ones to narrow: the article column is the engine's 14ch with an ellipsis and a `title`, the name's floor is 12ch (13ch on the mappings page, where its link must hold «сопоставление»).
- To know the slack, read the table's min-content width: set the card to `width: 200px` in the page and read the scroll container's `scrollWidth` (products 780px of 784px, mappings 732px of 750px). A card with ~0px of slack fits only the rig's data: a wider badge or price scrolls it again.
- Measure the real stylesheet: build the bundle into a scratch directory and serve it through Playwright `page.route` in place of the rig's `style-index.css`. An `addStyleTag` override is NOT faithful — it loads after WooCommerce's CSS and silently wins rules the real stylesheet loses (the header's right padding showed 8px instead of 0).

## Related

- [a-sortable-woocommerce-header-stands-16-to-17px-right-of-its-values](a-sortable-woocommerce-header-stands-16-to-17px-right-of-its-values.md) — the same header, its left side
