# Gotcha: [shipping/checkout] — Two Store API cart requests that overlap on the server lose each other's session writes

**Namespace:** `shipping/checkout` · **Discovered:** s189 (2026-10-20), #1357
> **Measured on:** the rig (WooCommerce 11.2.1, CDEK on a live account), with plain HTTP requests and no
> browser (scratchpad probes `session-race.js`, `confirm-race.js`), then in Chromium through the block checkout.

## What happens

The CDEK acceptance failed one block pickup scenario per run, a different one each time: (a) the point's
street was already in the form, the pickup rate still selected, and the button again said «choose a pickup
point»; (b) the pickup dialog closed itself right after the click and the rate became Free shipping. Both
looked like picker bugs, since the picker tears its session down when the rate changes. They were not:
the server's own cart had really changed.

## Root cause

WooCommerce keeps the cart's server state in ONE session row: the chosen rate, the address, and the pickup
point the framework remembers. A request reads the row when it starts and writes it back WHOLE when it ends
(`WC_Session_Handler::save_data()`). So when two cart requests of one shopper overlap on the server, the
one that ends last writes back what it read before the other one's change:

- core's focusout address push takes 1.2–1.8 s on a carrier that rates live, and the shopper's rate pick
  (~80 ms) sent meanwhile is gone when the push ends. Overlapped 3/3 lost, sent one after the other 2/2 kept;
- a pickup confirmation (1.1–3.4 s) overlapped by a later cart request is gone from the session, together
  with `remembered_point_id`, while the browser has already adopted the point's street (3/3 with a quantity
  change, which changes neither the rate nor the address).

Core sends such overlapping pairs routinely: its push, its rate selection, its draft-order sync and every
extension update are separate `apiFetch` calls. Its single `isCustomerDataUpdating` flag is cleared by
whichever address request answers FIRST, so the rate list is enabled while another push is still on the
server.

## Fix

Do not mask the symptom in the picker (re-selecting the rate, re-confirming the point): the browser cannot
see what the server lost, and every retry is one more overlapping request.

The checkout-blocks bundle serializes every Store API cart MUTATION the page sends through `apiFetch`
(`src/checkout-blocks/store-api-queue.ts`, the outermost middleware). Each mutation waits for the previous
one's answer, with a 15 s cap and FIFO order. Reads pass through. A plugin's own storefront JS must
send its cart mutations through `apiFetch` (`extensionCartUpdate`, the cart store's actions), never a raw
`fetch()`: a raw request bypasses the queue and can erase the shopper's rate or point again. The classic
checkout has the same server-side race through `admin-ajax`, and nothing guards it (a per-session server
lock is a possible follow-up).

## Related

- [wc-store-api-batches-update-customer-300ms-after-updating-flips](wc-store-api-batches-update-customer-300ms-after-updating-flips.md) — how core sends the address push
- [a-restore-tied-to-a-server-confirmation-looks-like-a-render-artefact](a-restore-tied-to-a-server-confirmation-looks-like-a-render-artefact.md) — settle «who did this» with a timestamped ledger
- [woocommerce-resets-the-chosen-shipping-method-on-any-rate-set-change](woocommerce-resets-the-chosen-shipping-method-on-any-rate-set-change.md) — the other way the chosen rate is lost
