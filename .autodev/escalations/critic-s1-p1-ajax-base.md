# ESCALATION critic-s1-p1-ajax-base -- critic broken

**Task:** s1-p1-ajax-base -- Shipping AJAX base (pickup search + set-point endpoints)
**Type:** disagreement
**What happened:** Critic verdict: broken. Diff touches shipping_method_id via the broad shipping-method path invariant and ajax_actions via wp_ajax hooks. Neither has a blessed guard. No tests were modified. Action names remain plugin-supplied, but the PHP-to-JS payload contract is broken.
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
                                 "zone":  "ajax_actions / pickup response payload",
                                 "file":  "woodev/shipping-method/ajax/class-shipping-ajax.php",
                                 "line":  148,
                                 "evidence":  "Search serializes Pickup_Point::to_array(), which exposes identifier as `code` (class-pickup-point.php:168), but shipped pickup-map.js posts `point_id: point.id` (pickup-map.js:229). Returned points therefore have undefined `id`; selecting one stores an empty pickup-point code."
                             }
                         ],
    "notes":  "Diff touches shipping_method_id via the broad shipping-method path invariant and ajax_actions via wp_ajax hooks. Neither has a blessed guard. No tests were modified. Action names remain plugin-supplied, but the PHP-to-JS payload contract is broken.",
    "confidence":  0.99
}
```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.
