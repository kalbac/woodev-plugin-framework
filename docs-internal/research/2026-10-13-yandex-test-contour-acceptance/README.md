# Yandex test contour acceptance (Y14)

## Status

- **Date:** 2026-10-13. Plugin card `kalbac/woocommerce-yandex-delivery#17`, PR #45.
- **Verdict:** the test contour is **silent** from this network. No scenario could be run live; every live scenario is UNVERIFIED. Production was not used as a fallback.
- **What is documented-only:** the response shapes below come from Yandex's public reference (`yandex.com/support/delivery-profile/ru/api/other-day/...`, fetched 2026-10-13) and are labelled *docs*. Nothing here is a recorded live response.

## Step 0 — why the contour does not answer

Measured with plain `curl` and the public test token from the v1 plugin (never written down here), egress in Russia:

| Probe | Result |
|---|---|
| `POST https://b2b.taxi.tst.yandex.net/api/b2b/platform/location/detect`, token, `Content-Type`, `Accept`, `Accept-Language: ru` | `200 Ok`, `Content-Length: 0`, no `Content-Type` |
| The same without `Accept-Language` | identical |
| A nonexistent path `/api/b2b/platform/nope` | identical |
| No `Authorization` header | identical |
| A deliberately invalid token | identical |
| `GET /` | identical |
| `GET /ping` | `200`, body `rs_weight=1` (a balancer health answer) |
| HTTP/1.1 and HTTP/2 negotiation; three user agents | identical |
| Same call after a 20 s pause | identical |
| `https://b2b-authproxy.taxi.tst.yandex.net` (the CNAME target, same IPs) | identical |
| Plain `http://` on the test host | connection timeout |
| Production host `b2b-authproxy.taxi.yandex.net`, a **bad** token, `location/detect` (read-only, no real token) | `401`, `application/json`, `{"code":"unauthorized","message":"Access denied"}` |

Conclusions:

1. The reply does not depend on path, method, headers or token, so it is not our request. The balancer answers and no backend handles `/api/b2b/platform/*`.
2. Host, path prefix, token and Moscow-only note match Yandex's current docs (`.../other-day/access`) and v1 (`api/class-api.php:9`). The plugin client builds `https://b2b.taxi.tst.yandex.net` + `/api/b2b/platform/<endpoint>` (`includes/api/class-yandex-api-client.php:18`, `includes/api/class-yandex-api-request.php`), the same URL. Existing unit test `test_test_contour_uses_test_host` pins the host.
3. One real difference from v1: v1 set `Accept-Language: ru`, the v2 client does not. It made no difference on the silent contour; it may affect the language of error text on a working contour. Not a proven defect.
4. A contour that answers 200/empty to everything is the Yandex side's state (decommissioned or not routed for non-corporate callers). Re-test it later; nothing in the plugin needs to change for that.

Behaviour of the plugin on that reply (unit test `test_http_200_with_an_empty_body_carries_no_data`): no data, no `request_id`, no offers. In `create_order` an empty `offers/create` is a refusal and an empty `offers/confirm` reply is an *unknown* export (framework `abstract-shipment-handler.php:301-310`, sticky guard) — fail-closed.

## Documented shapes (docs, not live)

- `offers/confirm` 200: `{"request_id": "<id>-udp"}` only. **No `sharing_url`.** 400: `{"code":"bad_request","message":"Missing required field 'offer_id'"}`. No expiry error is documented.
- `request/info` 200: `request_id`, `full_items_price`, `request{info, source, destination, items, places, billing_info, recipient_info, last_mile_policy, available_actions}`, `state{status, description, timestamp, timestamp_utc, reason?}`, `courier_order_id`, `return_places`, `self_pickup_node_code{code,type}`, **`sharing_url`** (the documented home of the tracking link).
- Delivery date/window: there is **no `delivery_date`** field. The window is `request.destination.interval_utc{from,to}` (null when the destination is a `platform_station`, i.e. pickup point) and `request.source.interval_utc`. Reference types disagree (`from` is declared UNIX integer, examples show ISO strings).
- `request/history` 200: `{"state_history":[{status, description, timestamp, timestamp_utc, reason?}]}` — the key is `state_history` (v1 `api/class-response.php:13,85` reads the same key).
- `request/cancel` 200: `{status: CREATED|SUCCESS|ERROR, reason, description}`; 403 `{code:"customer_order_not_found"}`; 404 `{code:"not_found"}`; 500 `{code:"conflict"}`.
- `request/generate-labels` 200: `application/pdf`, binary (body fields `request_ids`, `generate_type` one|many, `label_size_mm`, `language`). `request/get-handover-act` 200: `application/pdf` binary (`editable_format` switches to Word). Neither documents an error body. This supports Y10b's raw-`%PDF-` assumption; it is **not** confirmed on the contour.
- Status model (docs `.../other-day/status-model`): courier path `CREATED → DELIVERY_PROCESSING_STARTED → SORTING_CENTER_LOADED → SORTING_CENTER_AT_START → SORTING_CENTER_PREPARED → SORTING_CENTER_TRANSMITTED → DELIVERY_AT_START → DELIVERY_TRANSPORTATION_RECIPIENT → DELIVERY_TRANSMITTED_TO_RECIPIENT → DELIVERY_DELIVERED`. Pickup path `… → DELIVERY_AT_START → DELIVERY_TRANSPORTATION → DELIVERY_ARRIVED_PICKUP_POINT → CONFIRMATION_CODE_RECEIVED → DELIVERY_TRANSMITTED_TO_RECIPIENT → DELIVERY_DELIVERED`, plus `PARTICULARLY_DELIVERED`, `CANCELLED` (reasons incl. `PICKUP_EXPIRED`), the `RETURN_*` set and `DELIVERY_TIME_INTERVALS_UPDATED` (reschedule). The page lists statuses, not transitions: the order is table order.

## Findings against the plugin

| # | Where | Finding |
|---|---|---|
| F1 | `includes/tracking/class-yandex-tracking-handler.php:26` | Reads `$data->history`; Yandex answers `state_history`. Every real history maps to zero events (the poller's history fallback at `class-yandex-status-poller.php:130` is dead). The recorded `request-history.json` fixture used the invented `history` key, which is why the test passed. |
| F2 | `includes/tracking/class-yandex-delivery-status.php:25-71` | No row for `DELIVERY_TRANSPORTATION`, `DELIVERY_ARRIVED_PICKUP_POINT`, `CONFIRMATION_CODE_RECEIVED`, `PARTICULARLY_DELIVERED`, `DELIVERY_TIME_INTERVALS_UPDATED`. Nothing maps to `ready_for_pickup` — the arrival status exists (`DELIVERY_ARRIVED_PICKUP_POINT`), so «ждёт в пункте» is reachable in principle once mapped. `DELIVERY_TRANSMITTED_TO_RECIPIENT` stays `in_transit` (gotcha `delivery-transmitted-to-recipient-is-not-ready-for-pickup`); in the pickup path it is followed by `DELIVERY_DELIVERED`. |
| F3 | `includes/tracking/class-yandex-status-poller.php:183` | `apply_delivery_date` reads `$body->delivery_date ?? $state->delivery_date`; neither is documented, so the date is never recorded. Courier orders have the window in `request.destination.interval_utc.from/to` (convert to the shop-local date); pickup-point orders have `interval_utc = null` and no documented delivery date. |
| F4 | `includes/tracking/class-yandex-status-poller.php:92-99` + framework `Order_Marker` | Admin-created orders (see below). |
| F5 | `includes/api/class-yandex-api-client.php:270` | Expiry recreate keys on code `offer_expired` **or** `expires_at <= now`. The docs list no expiry error, so the code spelling is unverifiable; the time clause keeps the retry working if the code differs. Residual risk: a stale/unknown offer id whose `expires_at` is still in the future is not retried (and surfaces as a refusal, which is the safe outcome). |
| F6 | `includes/api/class-yandex-api-client.php:126` | `request_info( $id, false )` sends `slim=0`; the reference calls `slim` a boolean. Acceptance of `0` is UNVERIFIED. |

### Admin-created order (F4), by code

- The marker `_yandex_delivery_state_status` is written only by the framework `Order_Marker`, which runs from `Checkout_Handler::persist_values()` — hooked to the classic checkout (`woocommerce_checkout_order_processed`) and the Store API (`woocommerce_store_api_checkout_order_processed`) (`woodev/shipping-method/checkout/class-checkout-handler.php:396-397,3438`) — and from the framework's own order editor (`woodev/shipping-method/admin/orders/class-order-editor.php:861,872`).
- A native WooCommerce «Add order» in wp-admin with a Yandex method set by hand runs none of these: **no marker**. Exporting it stores `_yandex_delivery_request_id` but nothing writes the marker.
- The poller query (`class-yandex-status-poller.php:92-99`) joins `REQUEST_ID EXISTS` AND `state_status NOT IN (final)`. In `WP_Meta_Query` a `NOT IN` clause is an INNER JOIN on the key, so an order **without** the marker is excluded: it is never polled. The orders page finds orders by marker `EXISTS`, so it is not listed either.
- Suggested fix (not applied — `includes/tracking/` and `includes/orders/` belong to Y10b): after `shipment_exported` (`Yandex_Shipment_Handler`), write the marker if absent (e.g. `CREATED`), and/or make the poller clause `relation OR [ state_status NOT EXISTS, state_status NOT IN (final) ]`. Orders made in the framework's own order editor are fine.

## Scenario coverage

| Scenario | Result |
|---|---|
| 1 create → confirm → info → cancel (4 combos) | UNVERIFIED — contour silent. |
| 2 expiry refusal | UNVERIFIED live; docs list no expiry error; see F5. |
| 3 status sequence, `READY_FOR_PICKUP`, date field | docs-derived only (above); F1–F3. |
| 4 admin-created order marker | Answered by code: **FAIL** for native admin orders (F4). |
| 5 CI stub vs reality | Corrected to the *published* shapes (PR #45): `request-info.json`, `request-cancel.json`; the history stub is wrong too but cannot be changed without breaking `includes/tracking/` (F1) — the corrected shape is in `tests/fixtures/yandex-api/documented/request-history.json`. |

## Follow-up

Re-run once the contour answers: confirm one synthetic Moscow offer (never with a production `y0_` token), record the redacted `sharing_url`, the real status walk, the expiry error and the PDF endpoints' content type and first bytes.

## Related

- Spec: [`../../specs/2026-10-12-yandex-v2-plugin-design.md`](../../specs/2026-10-12-yandex-v2-plugin-design.md) §8.6, §14.
- Y10b measurement: [`../2026-10-13-yandex-sharing-url/README.md`](../2026-10-13-yandex-sharing-url/README.md).
