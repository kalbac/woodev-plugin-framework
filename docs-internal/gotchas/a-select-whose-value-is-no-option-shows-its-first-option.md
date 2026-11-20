# gotcha: a `<select>` whose value matches no option shows its FIRST option — the delivery page's picker said 25 over a page of 20

**Namespace:** `[admin-ui/tables]`
**Discovered:** s239 (2026-11-20), #1902

## Symptom

«Заказы доставки» showed «Rows per page: 25» while page 1 held 20 rows.

## Root cause

WooCommerce's `TableCard` pagination offers the fixed sizes 25 / 50 / 75 / 100 and takes no option list. The page passed
`rowsPerPage={ 20 }` (its own `DEFAULT_PER_PAGE`); a controlled `<select>` with a value that is none of its options draws
its first option, so the picker lied while the request was really for 20. No error, no warning.

## ❌ Wrong

```ts
const DEFAULT_PER_PAGE = 20; // not a WooCommerce picker size
```

## ✅ Correct

A default that is one of the picker's sizes (`25`), or — on the records engine — our own pager, which takes PHP's
`per_page_options` and adds the current size when it is missing (`src/admin-records/pager.tsx`).

## Related

- [a-records-page-can-pass-every-test-while-its-extensions-are-never-mounted](a-records-page-can-pass-every-test-while-its-extensions-are-never-mounted.md)
