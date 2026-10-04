# gotcha: WP renders a metabox band in REGISTRATION order, and WooCommerce registers its order boxes before `add_meta_boxes` fires

**Namespace:** `[admin-ui/*]`
**Discovered:** s150 (2026-10-04), card #947 (operator: «поставить метабокс под Order actions»)

## What happened

Our shipping metabox was moved to `side`/`high` with a later `add_meta_boxes` priority (35), expecting to land right
under WooCommerce's «Order actions». The rig probe showed the side column as: Order actions → **Order attribution →
Customer history** → ours → Order notes. All three WC boxes are `side`/`high` too, and within one band
(`$wp_meta_boxes[ $screen ][ $context ][ $priority ]`) WordPress renders in the order the boxes were REGISTERED.
On the HPOS screen WC adds all of them in `Edit::add_order_meta_boxes()` / `maybe_register_order_attribution()`
BEFORE it fires `add_meta_boxes` (`src/Internal/Admin/Orders/Edit.php:76-84, :226-280`), so no hook priority can put a
later box between them. (Legacy `shop_order`: WC adds them from its own `add_meta_boxes` callback at priority 30.)

## ❌ Wrong

```php
add_action( 'add_meta_boxes', [ $this, 'register' ], 35 ); // «after WC» — still below attribution + history
```

## ✅ Correct

```php
add_meta_box( self::METABOX_ID, $title, $cb, $screen, 'side', 'high' );
// Re-key the band so our box follows the anchor; every other box keeps its relative order.
$GLOBALS['wp_meta_boxes'][ $screen ]['side']['high'] = self::move_box_after(
	$GLOBALS['wp_meta_boxes'][ $screen ]['side']['high'], self::METABOX_ID, 'woocommerce-order-actions'
);
```

A merchant's saved drag order (`meta-box-order_*` user meta) is applied later by WP and still wins — do not touch it.
Verify on the rig by reading `#side-sortables > .postbox` ids, not by reasoning about priorities.

## Related

- `woodev/shipping-method/admin/class-shipping-admin-order.php` — `move_box_after()`
- [wc-admin-register-page-ignores-order-and-its-neighbours-declare-no-position](wc-admin-register-page-ignores-order-and-its-neighbours-declare-no-position.md) — the same «position is registration order» trap on wc-admin pages
- `../gotcha-index/admin-ui.md`
