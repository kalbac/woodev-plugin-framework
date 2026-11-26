# Gotcha: [admin-ui/tables] — one over-long provider string fails the whole records response
> Tags: records-engine, marketplace, bounds | Session: card #2003 (26.11.2026)

## What happens

A row action reason longer than 500 characters — a marketplace's own refusal passed through — turns the whole table read into an
error: no row is shown, not only the one with the long sentence.

## Root cause

`Row_Normalizer` and `Action_Response` do not truncate. A string over its bound throws, and the engine answers a generic 500
(`woodev_records_invalid_provider_response`). The same holds for a report group's reason (500) and examples (200), more than 50
groups, and more than 5 examples.

## Fix

```php
// ❌ the marketplace's text as it came
'reason' => $adapter->seller_action_refusal( $order, $unit, $id ),

// ✅ the provider cuts everything it did not write itself
'reason' => mb_substr( $reason, 0, Records_Definition::MAX_STRING_LENGTH ),
```

## Related

- [Seller actions on an order](../wiki/marketplace-admin.md#seller-actions-on-an-order-2003)
- [Records engine](../wiki/marketplace-admin.md#records-engine)
