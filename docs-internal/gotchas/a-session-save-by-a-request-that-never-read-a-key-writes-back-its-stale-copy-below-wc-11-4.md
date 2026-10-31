# gotcha: below WooCommerce 11.4 a request that never read a session key writes back its stale copy of it

**Namespace:** `[shipping/location]` · **Discovered:** s206 (31.10.2026), kalbac/woocommerce-yandex-delivery#150

WooCommerce < 11.4 saves the session row WHOLE at shutdown. A request that loaded the row before the customer picked a city
and saves after it — without ever reading the location record (`wc-ajax=apply_coupon` with a wrong code, any notice-only AJAX)
— writes back its old copy, and the city is gone. `Session_Key_Merger` used to protect only keys the request touched, so such a
request was unprotected. Measured on the rig (WC 11.2.1): a slow `apply_coupon` across `/location/select` lost the record 6 of 6;
the customer's own typing sequence lost it 0 of ~19, so the customer's exact trigger is still unproven.

❌ Protect a session key only in requests that read or wrote it.
✅ `Session_Key_Merger::protect_location_record()` (2.0.18, PR #1524) puts the fixed location key under the merge in every request
of a location-enabled plugin; the SELECT is paid only by a dirty session save, and nothing is hooked on WC 11.4+ (per-key writes,
woocommerce#69559). Two real writers still race last-writer-wins.

## Related

- [a-closing-keyword-in-a-plugin-pr-notifies-the-customer-before-edd-serves-the-fix](a-closing-keyword-in-a-plugin-pr-notifies-the-customer-before-edd-serves-the-fix.md)
