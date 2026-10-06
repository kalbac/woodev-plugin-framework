# KITE-714 — buyer shipment status emails

## Delivered

- Added `Delivery_Status_Events::notify()` as the shared seam carriers call after persisting a raw delivery status. It resolves and publishes `woodev_shipping_delivery_status_changed` with the order, previous/current canonical statuses and provider.
- Registered four `WC_Email` notifications in WooCommerce's standard email settings. Created/in-transit, ready-for-pickup and delivered start enabled; returned/failed starts disabled. Subject, heading and body are editable, with theme-overridable HTML and plain templates.
- Added default order/tracking/carrier/pickup placeholders, a carrier extension filter, buyer-email guard, and per-email/per-status HPOS-safe deduplication.
- Added a realistic-shipping fixture status trigger, unit coverage, updated the shipping skill reference and added new strings to the POT/PO catalogues.

## Verification

- `composer test:unit`: 5,933 tests, 32,290 assertions, 1 skipped.
- `composer phpcs`: passed.
- `composer phpstan`: passed.
- `npm run lint:docs`: passed.
- `npm run lint:i18n-sources`: passed (1,109 source msgids).
- Focused email/event tests: 5 tests, 23 assertions.
- Integration suite was not run, as requested.

The repository's PHP navigation MCP Serena was not available in this Codex worker; the brief explicitly authorized shell-based PHP inspection. The `.mo` was not rebuilt; the coordinator should regenerate it in the primary checkout from the updated `.po`. No JavaScript bundle rebuild is needed.
