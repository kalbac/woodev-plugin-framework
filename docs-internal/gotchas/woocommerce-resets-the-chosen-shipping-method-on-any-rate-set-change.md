# gotcha: WooCommerce resets the buyer's chosen shipping method whenever the SET of rates changes

**Namespace:** `[woocommerce/shipping]`
**Discovered:** s176 (2026-10-13), Y15 coexistence acceptance (defect F2); fixed by #1266 / PR #1268

## What happens

`wc_get_chosen_shipping_method_for_package()` (`includes/wc-cart-functions.php` ~452, WC 11.2.1) keeps the session's
chosen method only while `wc_shipping_methods_have_changed()` (~591) says the list of rate ids is identical to the previous
request. If ONE rate id appears or disappears, it calls `wc_get_default_shipping_method_for_package()` (~503), which picks the
first non-local-pickup rate — on most shops «Free shipping». WooCommerce exempts only its OWN local pickup ("Default to local
pickup if its chosen already"); a carrier plugin's pickup method has no such protection.

On a carrier rig this read as «choosing a pickup point switches the order to Free shipping»: the point's address is written
into `address_1`, the Yandex COURIER quote for that address refuses (or a previously refused one starts to succeed), the rate
set changes, the choice resets, and the order is placed with Free shipping while the point's meta sits on it. A Yandex-only
shop hits it too — no second carrier is needed.

## ❌ Wrong

Making one carrier's rate "stable" so the set never changes (PR yandex#54, closed): a critic showed the set still changes
when a previously refused courier rate APPEARS after the pick, and the plugin then quoted a street-less city price.

## ✅ Correct

Filter `woocommerce_shipping_chosen_method( $default, $rates, $chosen_method )` once for the whole fleet: when
`$chosen_method` belongs to a framework carrier and is still a key of `$rates`, return it; otherwise return `$default`
untouched (`Shipping_Plugin`, #1268). Classic and Store API both go through this function.

## Related

- [rig-checkout-url-is-the-block-checkout](rig-checkout-url-is-the-block-checkout.md) — where the classic probe runs
- Session: [../sessions/s176.md](../sessions/s176.md)
