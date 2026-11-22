# WooCommerce `Pagination` renders NOTHING for a single page, including the per-page picker

**Namespace:** `[admin-ui/tables]` · **Discovered:** s239 (21.11.2026, #1901 / #1905)

## What happens

A records table with few rows shows no pager and no «rows per page» control, so the user cannot change the page size. The picker also appears only when the total exceeds the smallest option (25).

## Root cause

`@woocommerce/components` `Pagination` returns nothing when there is one page; its picker is rendered by the same component and is gated by `total > smallest per-page option`.

## Fix

- ❌ Rely on `Pagination` for the per-page picker.
- ✅ Render the picker and the row count in our own footer row (always visible), and mount `Pagination` only for more than one page.
- ✅ Operator rule (22.11.2026, s241): a records table whose whole result fits the smallest page size has NO footer at all (no picker, no count) — `fitsOnePage()` in `src/admin-records/pager.tsx`, class `woodev-records__no-footer` hides the vendor footer. «Заказы доставки» keeps its picker on a short result (#1903).

## Related

- [a-select-whose-value-is-no-option-shows-its-first-option](a-select-whose-value-is-no-option-shows-its-first-option.md) — a page size outside WooCommerce's list
- [s239 session](../sessions/s239.md)
