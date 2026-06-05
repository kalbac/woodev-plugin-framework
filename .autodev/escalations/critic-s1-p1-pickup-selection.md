# ESCALATION critic-s1-p1-pickup-selection -- critic broken

**Task:** s1-p1-pickup-selection -- Pickup_Selection — session + order-meta persistence (prefix-driven)
**Type:** disagreement
**What happened:** Critic verdict: broken. Diff touches contract zone `shipping_method_id` by path (`woodev/shipping-method/**`) and introduces order/session meta persistence. No modified tests are present in the reviewed diff. The contract break is the new generic key composition for pickup selection state, which cannot preserve the canonical Yandex chosen pickup session key under the documented constructor contract.
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
                                 "file":  "woodev/shipping-method/pickup/class-pickup-selection.php",
                                 "line":  52,
                                 "evidence":  "Pickup_Selection composes the WC session/order-meta key as `$meta_prefix . $key`, with default key `pickup_point` at line 38, and later writes that composed key to order meta at line 134. For Yandex, `.autodev/INVARIANTS.md:116` and `docs-internal/migration/yandex-data-preservation-checklist.md:85` require the installed checkout session key `chosen_yandex_pickup_point` / `chosen_yandex_pickup_point_test`, not `_yandex_delivery_pickup_point`. GUARDS.md only blesses `_yandex_delivery_` order meta prefix, not the chosen pickup session keys."
                             }
                         ],
    "notes":  "Diff touches contract zone `shipping_method_id` by path (`woodev/shipping-method/**`) and introduces order/session meta persistence. No modified tests are present in the reviewed diff. The contract break is the new generic key composition for pickup selection state, which cannot preserve the canonical Yandex chosen pickup session key under the documented constructor contract.",
    "confidence":  0.82
}
```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.


---
**RESOLVED 2026-06-06 (operator: A / send back + fix spec).** Critic CONFIRMED CORRECT. Root cause was a spec bug (§4.1.v conflated the WC session key with the order-meta prefix; for Yandex these are DISTINCT contracts — session `chosen_yandex_pickup_point` vs order-meta prefix `_yandex_delivery_`). Spec §4.1.v corrected to session-only; order-meta persistence reassigned to the Phase-3 order handler (§4.3). Task re-scoped (session-only) and returned to pending; wrong implementation discarded (not committed).
