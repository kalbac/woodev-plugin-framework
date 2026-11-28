# WooCommerce 8.5 and 9.3 report `wpdb::prepare` when an order's personal data is erased

**Namespace:** `[woocommerce/privacy]`
**Discovered:** #2007 (PR #2072, 28.11.2026)

## What happens

An integration test that runs WooCommerce's order erasure (`WC_Privacy_Erasers::order_data_eraser()` or
`remove_order_personal_data()`) fails on the `WP 6.4 / WC 8.5.1` and `WP 6.6 / WC 9.3.0` CI jobs and passes on
`latest-stable`:

```text
Unexpected incorrect usage notice for wpdb::prepare.
Unsupported value type (object).
```

The message carries no stack, so it reads like a defect of the code under test. It fails on both order storages.

## Root cause

WooCommerce's own analytics hooks `Admin\API\Reports\Customers\DataStore::anonymize_customer( $order_id )` on
`woocommerce_privacy_remove_order_personal_data`, and that action passes the **order object** (read in the 8.5.1
source). The object goes straight into `$wpdb->prepare( '… order_id = %d', $order_id )`. Later releases accept the
object. Any listener of that action is therefore not at fault.

## ❌ Wrong

```php
$this->setExpectedIncorrectUsage( 'wpdb::prepare' ); // Fails on latest-stable, where nothing is reported.
```

## ✅ Correct

Unhook WooCommerce's listener in the test that is not about analytics (the test case restores hooks):

```php
remove_action(
    'woocommerce_privacy_remove_order_personal_data',
    [ \Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore::class, 'anonymize_customer' ]
);
```

## Related

- [HPOS reads `status => any` literally on WC 8.5 and 9.3](order-query-status-any-is-read-literally-by-hpos-on-wc-8-5-and-9-3.md) — another difference only the older CI jobs show
- Test: `tests/integration/Marketplace/SellerDeliveredOrdersTest.php`
