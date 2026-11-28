# Gotcha: [woocommerce/orders] — `wc_get_order()` answers false for a deleted order and for one it failed to load

> Tags: woocommerce, orders, deletion, personal-data | Session: card #2007 (28.11.2026)

## What happens

A cleanup walks a list of order ids, skips every id `wc_get_order()` answers `false` for, and then drops the list. One
transient failure leaves the order's data in place with nothing left that names the order: the cleanup reports success and
cannot be repeated.

## Root cause

`WC_Order_Factory::get_order()` returns `false` when there is no such order, when the order's class cannot be resolved
(`get_class_names_for_order_ids()`), and when constructing it throws an `Exception` — the factory catches and logs it (read in
the latest release; critic review of PR #2072). The answer does not say which.

## ❌ Wrong

```php
$order = wc_get_order( $order_id );

if ( $order instanceof \WC_Order ) {
    erase( $order );
}

delete_reference( $order_id ); // Also after a failed load.
```

## ✅ Correct

Keep the reference unless the order's data is gone or a **successful** read of the current order store finds no row; a failed
existence query (`$wpdb->last_error`, a `null` count) confirms nothing. Throw, so the job retries.
`Deletion\Posting_Eraser` does this over `Site_Orders_Storage`.

## Related

- [Orders the seller delivers](../wiki/marketplace-admin.md#orders-the-seller-delivers-2007)
- `tests/unit/Marketplace/Deletion/PostingEraserTest.php`, `tests/integration/Marketplace/SellerDeliveredOrdersTest.php`
