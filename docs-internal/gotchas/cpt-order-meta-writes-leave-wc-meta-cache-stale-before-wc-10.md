# CPT order-meta writes leave WooCommerce's cached order meta stale on WC 8.5 / 9.3

> [compat/woocommerce] — discovered 2026-09-28 fixing the #710 integration leg (WC 8.5.1 and 9.3.0, legacy CPT only).

## The trap

`Woodev_Order_Compatibility::add_order_meta()` / `update_order_meta()` / `delete_order_meta()` write
through `add_post_meta()` & co. when HPOS is off. WooCommerce keeps a per-order meta cache
(`WC_Data::read_meta_data()`, group `orders`) and flushes it from `WC_Post_Data::flush_object_meta_cache`.
WC 8.5.1 and 9.3.0 hook that to `updated_post_meta` ONLY (`class-wc-post-data.php:60`); WC 11.1 also hooks
`added_post_meta` and `deleted_post_meta` (`class-wc-post-data.php:69-71`). So on the older versions adding
or deleting a key through the helper left the cached copy stale: an order read once came back without the
change on the next `wc_get_order()` (with a persistent object cache, until the entry expired). The wizard's
«export right away» wrote the carrier order id this way and a fresh read saw `''`.

Two traps inside the fix:

- **`update_post_meta()` on a key that does not exist yet fires `added_post_meta`, not `updated_post_meta`.**
  The first `update_order_meta()` of a key is an ADD as far as WooCommerce's hooks go — the carrier-id write
  is exactly that. «Updates never need a flush» is wrong.
- **Flushing after WooCommerce already did is not a no-op**: `invalidate_cache_group()` writes a fresh cache
  prefix, so an unconditional flush double-invalidates on 11.1.

## Evidence

Probe on 8.5.1 / 9.3.0: `cache_primed=true`, the cached rows lack the key, `get_post_meta()` has it.
Green on 11.1 and on HPOS (the object API flushes itself), which is why it only showed on CI's old-WC legs.
A first `update_order_meta()` flush-less on 8.5.1 / 9.3.0 fails `OrderEditorDatastoresTest::
test_an_order_created_by_the_wizard_can_be_exported_at_once` («legacy CPT»): `'' starts with "TESTCARRIER-EXPORT-"`.

## Fix

`Woodev_Order_Compatibility::flush_order_meta_cache()` snapshots `did_action()` for the three post-meta
actions before the write (`count_post_meta_actions()`), and after it invalidates
`WC_Cache_Helper::invalidate_cache_group( 'object_' . $id )` only if the action that ACTUALLY fired has no
`WC_Post_Data::flush_object_meta_cache` listener (`has_action()`). Detection, not a version constant.
Regression: `tests/integration/OrderMetaCacheFlushTest.php` — visibility tests are red on 8.5.1 without the
flush; the prefix tests (cache prefix at priority 99 of the action vs after the helper) are red on 11.1 if the
flush is unconditional.

## How to apply

- Never trust `wc_get_order()` to see a raw `*_post_meta` write on WC < 10; write through the helper.
- Decide «did core flush?» by the hook that fired and `has_action()`, never by the method name or a version.
- To run integration on an old WC locally, override the plugin dir in `tests/bootstrap.php` temporarily
  (`WOODEV_WC_PROBE=<version>`, copy pushed with `tar | docker exec -i`) and PROVE the version
  (`WC_VERSION`) — an env var lost to shell word-splitting silently runs 11.1.
- macOS `sed -i` takes the next argument as a backup suffix: `sed -i "s/…/" file` runs `file` as a script
  and changes nothing. Use `sed -i ''` or python for a mutation check.

## Related

- [brain-monkey-function-pollution](brain-monkey-function-pollution.md) — the same session's other "green here, red on CI"
