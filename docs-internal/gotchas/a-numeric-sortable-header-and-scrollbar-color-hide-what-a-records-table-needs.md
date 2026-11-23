# A right-aligned sortable header wastes 48px, and `scrollbar-color` switches the always-drawn scroll bar off

**Namespace:** `[admin-ui/tables]` · **Discovered:** s243 (23.11.2026, #1945 and #1930)

## What happens

- «Заказы маркетплейсов» was 855px (1057px on the operator's data) in the 784px card of a 1024px window, and the «Сумма» column was 139px for 12 characters. «Товары сайта» was 813px once a variable product added the 28px expand column.
- The table scrolled sideways and nothing said so: the card's «always drawn» scroll bar was not drawn on macOS.

## Root cause

- WooCommerce pads the button of every right-aligned header `padding: 0 24px` (`.woocommerce-table__header:not( .is-left-aligned ) button`); the s241 fix zeroed only the left-aligned ones. The cell's own 8px is all a numeric heading needs.
- A badge is `nowrap` and a date is one nowrap line, so «Не связан × 3» (114px) and «10.11.2026 08:30» fix their columns whatever the stylesheet's `ch` widths say.
- Chrome ignores `::-webkit-scrollbar` as soon as `scrollbar-color` is set on the element. A standard-styled bar on macOS is the overlay one, hidden until the table is touched, so the 12px bar the stylesheet declared never appeared.

## Fix

- ✅ `.woocommerce-table__header.is-sortable:not( .is-left-aligned ) .components-button { padding-left: 0; padding-right: 0 }`.
- ✅ A badge breaks at its spaces and `min-height: 2.5em` keeps one line 2.5em tall; the date is two `nowrap` spans with a breakable space between; the name's floor is 10ch; the buyer is `overflow-wrap: anywhere` (the delivery page's text-column rule).
- ✅ The bar is the webkit one; `scrollbar-color` / `scrollbar-width` only inside `@supports not selector( ::-webkit-scrollbar )` (Firefox).
- Measure on the rig, not on a fixture: Playwright against :8973 with `page.route()` serving the worktree's `style-index.css` and `index.js` in place of the plugin's `marketplace-admin` ones shows the real pages with the real data (headless Chromium hides scroll bars unless launched with `ignoreDefaultArgs: ['--hide-scrollbars']`; its bar thickness is `offsetHeight - clientHeight`).

## Related

- [a-records-table-fits-or-not-by-its-widest-values-and-sort-arrows](a-records-table-fits-or-not-by-its-widest-values-and-sort-arrows.md) — the left-aligned half of the same dead width
