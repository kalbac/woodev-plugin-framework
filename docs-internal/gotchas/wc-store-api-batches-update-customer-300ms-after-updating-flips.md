# Gotcha: [shipping/checkout] — WooCommerce sends `update-customer` inside `/wc/store/v1/batch`, ~300 ms AFTER `isCustomerDataUpdating()` turns true

**Namespace:** `shipping/checkout` · **Discovered:** s156 (2026-10-06), #1118, two REJECTs in a row
> **Measured on:** the WC 11.1 rig in Chromium (Playwright); WC 9.9.0 and 11.1.x read from source.

## What happens

To tell OUR address reply apart from another cart request's reply, #1118 added an `apiFetch`
middleware. Round 1 matched the path `/wc/store/v1/cart/update-customer` — and **never fired**: every
real address reply was then rejected and the address went permanently stale (worse than the race it
fixed). Round 2 parsed the batch — and was **inert live**: it snapshotted "the pending request" when
`isCustomerDataUpdating()` flipped true, but the POST leaves ~300 ms later, so tracking never engaged and
the hook silently degraded to the old behaviour. Both rounds had green jest suites.

## Root cause

- `updateCustomerData` goes through the Store API **batch** control: `POST /wc/store/v1/batch` with
  `{ requests: [ { path: '/wc/store/v1/cart/update-customer', … } ] }`, answered `207` with
  `{ responses: [ { status, body, headers } ] }` (9.9.0 `data/shared-controls.ts:70-74,129-133`;
  11.1.x `public-api/block-data/shared-controls.ts:129-136`). One batch may carry several requests —
  correlate by response index.
- The batch is assembled by a DataLoader with a delay: `updating=true` is dispatched first, the HTTP
  request ~300 ms later. A foreign `receiveCart` can land in that gap.
- Plain-permalink sites address it as `?rest_route=/wc/store/v1/batch`.
- Our bundle externalizes `wp-api-fetch` (`index.asset.php` dependencies), so `apiFetch.use()` from our
  code DOES see WC's requests — that part was never the problem.

## Fix

❌ Match the direct route, or snapshot the request at `updating=true`; test with `apiFetch` fired before
`updating` flips — the opposite of WC's order.

✅ At `updating=true` snapshot counters; credit a reply only after a matching address request has
STARTED (inside a batch, by index) and its response is classified; fall back to "any reply counts" only
when `updating` ends with no matching request observed. Tests drive WC's real order with fake timers
(`updating` → ~300 ms → batch POST → foreign reply in the gap → our reply) and copy request/response
shapes from WC source with file:line. Then re-measure LIVE — jsdom proved nothing twice.

## Related

- [woocommerce-starts-the-cart-store-from-localstorage-and-skips-the-page-s-own-cart](woocommerce-starts-the-cart-store-from-localstorage-and-skips-the-page-s-own-cart.md) — another WC cart-store timing trap
- [jsdom-click-skips-the-mousedown-focus-a-real-pointer-gives](jsdom-click-skips-the-mousedown-focus-a-real-pointer-gives.md) — same session, same lesson: green jest, wrong in the browser
