# Gotcha: [compat/hpos-order-meta-safety] — Never use get_post_meta() on orders
> Tags: hpos, woocommerce, order-meta, compatibility | Session: s2

## What happens
Using `get_post_meta()`, `update_post_meta()`, `add_post_meta()`, or `delete_post_meta()` directly on WooCommerce orders **silently reads/writes the wrong storage** when HPOS (High-Performance Order Storage) is enabled. Data written to `wp_postmeta` table will NOT be visible in order views, and data read via `get_post_meta()` will miss metadata stored in the HPOS orders_meta table.

## Root cause
When HPOS is enabled, WooCommerce stores order data in dedicated `wc_orders` and `wc_orders_meta` tables instead of `wp_posts` and `wp_postmeta`. WordPress post meta functions don't know about these tables. `Woodev_Order_Compatibility` provides HPOS-aware wrappers that route to the correct storage.

## Fix

❌ **Wrong:**
```php
// WRONG: silently reads from wp_postmeta, misses HPOS-stored data
$meta = get_post_meta( $order_id, '_my_custom_field', true );

// WRONG: writes to wp_postmeta, invisible in HPOS order view
update_post_meta( $order_id, '_my_custom_field', $value );
```

✅ **Correct:**
```php
// Always use Woodev_Order_Compatibility
$meta = Woodev_Order_Compatibility::get_order_meta( $order_id, '_my_custom_field', true );

Woodev_Order_Compatibility::update_order_meta( $order_id, '_my_custom_field', $value );

// Or use WC_Order methods directly (they are HPOS-aware)
$order = wc_get_order( $order_id );
$meta  = $order->get_meta( '_my_custom_field', true );
$order->update_meta_data( '_my_custom_field', $value );
$order->save();
```

## Related
- [class-order-compatibility.php](../../woodev/compatibility/class-order-compatibility.php) — Full compatibility layer
- [class-plugin-compatibility.php](../../woodev/compatibility/class-plugin-compatibility.php) — `is_hpos_enabled()` detection
- [AGENT-RULES.md](../AGENT-RULES.md) — PHP/WP Gotchas Summary: HPOS Compatibility
