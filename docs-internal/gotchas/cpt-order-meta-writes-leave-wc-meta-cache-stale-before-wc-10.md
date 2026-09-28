# CPT order-meta writes leave WooCommerce's cached order meta stale on WC 8.5 / 9.3

> [compat/woocommerce] — discovered 2026-09-28 fixing the #710 integration leg (WC 8.5.1 and 9.3.0, legacy CPT only).

## The trap

`Woodev_Order_Compatibility::add_order_meta()` / `update_order_meta()` / `delete_order_meta()` write
through `add_post_meta()` & co. when HPOS is off. WooCommerce keeps a per-order meta cache
(`WC_Data::read_meta_data()`, group `orders`) and flushes it from `WC_Post_Data::flush_object_meta_cache`,
which WC 8.5.1 and 9.3.0 hook to `updated_post_meta` ONLY. WC 11.1 also hooks `added_post_meta` and
`deleted_post_meta`. So on the older versions adding or deleting a key through the helper left the cached
copy stale: an order read once came back without the change on the next `wc_get_order()` (with a
persistent object cache, until the entry expired). The wizard's «export right away» wrote the carrier
order id this way and a fresh read saw `''`.

## Evidence

Probe on 8.5.1 / 9.3.0: `cache_primed=true`, the cached rows lack the key, `get_post_meta()` has it.
Green on 11.1 and on HPOS (the object API flushes itself), which is why it only showed on CI's old-WC legs.

## Fix

`Woodev_Order_Compatibility::flush_order_meta_cache()` calls `WC_Cache_Helper::invalidate_cache_group(
'object_' . $id )` after each CPT write (no-op where WC already flushed). Regression:
`tests/integration/OrderMetaCacheFlushTest.php` — red on 8.5.1 without the flush, green with it.

## How to apply

- Never trust `wc_get_order()` to see a raw `*_post_meta` write on WC < 10; write through the helper.
- To run integration on an old WC locally, override the plugin dir in `tests/bootstrap.php` temporarily
  and PROVE the version (`WC_VERSION`) — an env var lost to shell word-splitting silently runs 11.1.

## Related

- [brain-monkey-function-pollution](brain-monkey-function-pollution.md) — the same session's other "green here, red on CI"
