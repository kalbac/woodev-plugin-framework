# Gotcha: [woocommerce/*] — WooCommerce's order `EditLock` is HPOS-only, yet its heartbeat handler is global and breeds `_edit_lock` rows on CPT
> Tags: woocommerce, hpos, cpt, edit-lock, heartbeat | Session: s144 (#982, PR #995)

## What happens
A feature that "reuses WooCommerce's order lock" through `Automattic\WooCommerce\Internal\Admin\Orders\EditLock` works on HPOS and
silently does nothing on legacy CPT: `lock()` writes nothing readable, `is_locked_by_another_user()` is always false, a competing save
gets 200 instead of 409. Worse, a page that sends WooCommerce's heartbeat key `wc-refresh-order-lock` for a CPT order makes WooCommerce
INSERT a new `_edit_lock` postmeta row on every tick (measured: 3 rows after two ticks; ~60/hour per open order) — reads stay
consistent, so no ordinary test notices.

## Root cause
- `class-wc-order-data-store-cpt.php:59` lists `_edit_lock` in `$internal_meta_keys`, so on CPT the `WC_Order` meta API
  (`get_meta()` / `update_meta_data()`, which `EditLock` uses) never reads it back; `save_meta_data()` → `add_metadata( unique=false )`
  adds a fresh row each time.
- `EditLock` is wired only on WooCommerce's HPOS order screen (`src/Internal/Admin/Orders/PageController.php:97`
  `handle_edit_lock()`). On CPT the native editor is the WP post screen, which uses WordPress core `wp_set_post_lock()` /
  `wp_check_post_lock()` (post meta `_edit_lock`, same `time:user_id` format, window filter `wp_check_post_lock_window`, 150 s) and the
  core heartbeat key `wp-refresh-post-lock`.
- But `class-wc-ajax.php:239-253` hooks `EditLock::refresh_lock_ajax` on `heartbeat_received` (priority 11) UNCONDITIONALLY — any page
  that sends `wc-refresh-order-lock` triggers it, whatever the datastore.

## Fix
❌ `( new EditLock() )->lock( $order )` for every order, and the wizard sending `wc-refresh-order-lock`.

✅ One seam that picks the backend by datastore — HPOS → `EditLock`; CPT → `wp_check_post_lock()` / `wp_set_post_lock()` on the order's
post id (`require_once ABSPATH . 'wp-admin/includes/post.php'` in a REST request) — and a **framework-owned** heartbeat key handled only
by our own `heartbeat_received` filter, so neither WooCommerce's nor WordPress's handler runs for our ticks. Pin it with an integration
test on both datastores: two ticks through the real filter → exactly ONE `_edit_lock` row. As built: `Order_Edit_Lock`
(`class-order-edit-lock.php`), `Orders_Registry::refresh_order_edit_lock()`, key `woodev-refresh-order-lock` from the page bootstrap (#996).

## Related
- [cpt-order-meta-writes-leave-wc-meta-cache-stale-before-wc-10](cpt-order-meta-writes-leave-wc-meta-cache-stale-before-wc-10.md) — the other CPT order-meta trap
- [../gotcha-index/woocommerce.md](../gotcha-index/woocommerce.md) — topic index
