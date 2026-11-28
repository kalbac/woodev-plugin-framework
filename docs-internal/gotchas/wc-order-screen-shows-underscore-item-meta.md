# Gotcha: [admin-ui/orders] — WooCommerce's order screen shows item meta that starts with an underscore

> Tags: woocommerce, order-items, meta | Session: card #2007 (28.11.2026)

## What happens

The items table of an order prints `_woodev_mp_unit: 101` and `_woodev_mp_delivery_line: 1` under a line and under the shipping
line. The merchant reads internal keys. Every test was green: it takes a browser.

## Root cause

On the storefront and in e-mails `get_formatted_meta_data()` leaves out keys that start with `_`. The admin items table asks with
an empty hide prefix, so the underscore hides nothing there. Only the keys listed in `woocommerce_hidden_order_itemmeta` are
left out, and that list holds WooCommerce's own.

## Fix

```php
// ❌ assume the underscore is enough
$item->update_meta_data( '_woodev_mp_unit', $unit_id );

// ✅ and list every key you write on an item or a shipping line
add_filter(
    'woocommerce_hidden_order_itemmeta',
    static function ( array $hidden ): array {
        return array_merge( $hidden, [ '_woodev_mp_unit' ] );
    }
);
```

The framework hides its own two (`Admin\Order\Order_Screen::hide_item_meta()`); a marketplace adapter that writes item meta in
`Order_Meta_Contract::write_line_meta()` hides its own.

## Related

- [Orders the seller delivers](../wiki/marketplace-admin.md#orders-the-seller-delivers-2007)
- [A custom order box's column CSS](wc-order-data-column-width-beats-a-custom-order-box-column-css.md) — the same screen
