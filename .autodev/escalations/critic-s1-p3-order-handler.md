# ESCALATION critic-s1-p3-order-handler -- critic broken

**Task:** s1-p3-order-handler -- Shipping_Order_Handler — HPOS-safe order-meta read/write (prefix-driven)
**Type:** disagreement
**What happened:** Critic verdict: broken. Diff touches `woodev/shipping-method/**`, a contract zone in INVARIANTS (`shipping_method_id`) and writes order meta in the `order_session_meta` zone. GUARDS only blesses selected method IDs and Yandex `_yandex_delivery_` prefix, not these newly hardcoded order-meta suffix contracts. No test changes were present in the supplied diff.
**Decision you need to make:** Override the critic, or fix the diff?
**Option A:** Send back to worker
**Option B:** Override (commit anyway)
**Cost of being wrong:** a real contract break could land

**Evidence:**
```
{
    "verdict":  "broken",
    "broken_contracts":  [
                             {
                                 "zone":  "order_session_meta",
                                 "file":  "woodev/shipping-method/order/class-shipping-order-handler.php",
                                 "line":  55,
                                 "evidence":  "Hardcodes neutral order-meta suffix `chosen_point`, and set_chosen_point() writes it via prefix composition (lines 219-220, 259-260). With Yandex\u0027s preserved `_yandex_delivery_` prefix this creates `_yandex_delivery_chosen_point`, but canonical Yandex order meta is decomposed (`_yandex_delivery_destination_station_id`, `_destination_station_address`, etc.) and the session key is separately `chosen_yandex_pickup_point`; no guard in .autodev/GUARDS.md asserts this new composed key."
                             },
                             {
                                 "zone":  "order_session_meta",
                                 "file":  "woodev/shipping-method/order/class-shipping-order-handler.php",
                                 "line":  49,
                                 "evidence":  "Hardcodes generic carrier-data suffixes `carrier_order_id`, `tracking_number`, and `carrier_status` for order meta (lines 49, 52, 58), then writes them through update_meta(). Edostavka\u0027s preserved order-meta suffixes under `_wc_edostavka_` are `cdek_order_id`, `tracking_code`, and `status`; these neutral names would orphan installed order data if used as the framework order-meta backbone."
                             }
                         ],
    "notes":  "Diff touches `woodev/shipping-method/**`, a contract zone in INVARIANTS (`shipping_method_id`) and writes order meta in the `order_session_meta` zone. GUARDS only blesses selected method IDs and Yandex `_yandex_delivery_` prefix, not these newly hardcoded order-meta suffix contracts. No test changes were present in the supplied diff.",
    "confidence":  0.86
}
```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.


---
**RESOLVED 2026-06-06 (operator: A / send back + fix spec).** Critic CONFIRMED CORRECT (0.86). Framework hardcoded neutral order-meta suffixes (chosen_point, carrier_order_id, tracking_number, carrier_status) that match no installed plugin (edostavka cdek_order_id/tracking_code/status; yandex decomposed _destination_station_*). Spec §4.3 corrected: handler takes a plugin-supplied logical-field -> real-meta-key MAP, hardcodes nothing. Task re-scoped + returned to pending; wrong impl discarded (never committed).
