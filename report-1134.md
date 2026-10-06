# KITE-1134 — carrier documents

Implemented the carrier document source contract and its ready binary, ready URL, pending, and failed results. The framework registers document sources by provider, gates them with `supports_label_printing` and an existing carrier order id, exposes authorized nonce-protected REST downloads, records an HPOS-safe downloaded timestamp, and offers waybill/barcode actions on exported order rows. The realistic fixture demonstrates binary waybill and pending barcode responses; the shipping plugin author guide and translation catalogues now cover the seam.

## Validation

- `composer test:unit` — 5,930 tests, 32,270 assertions, 1 skipped.
- `composer phpcs` — passed.
- `composer phpstan -- --memory-limit=4G` — passed.
- `npm run test:js` — 2,911 tests across 61 suites, passed.
- `npm run typecheck` and `npm run lint:ts-baseline` — passed.
- `npm run lint:docs` and `npm run lint:i18n-sources` — passed.
- Integration suite not run, per task instructions.

The orders page TypeScript changed. Its built bundle must be rebuilt in the primary checkout; no bundle was committed from this worktree.
