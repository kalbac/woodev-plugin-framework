# ESCALATION critic-s1-p3-webhook -- critic broken

**Task:** s1-p3-webhook -- Abstract_Webhook_Handler — generic inbound receiver (scaffolding only)
**Type:** disagreement
**What happened:** Critic verdict: broken. Touches shipping_method_id by path, REST via register_rest_route(), and hooks via do_action(). No existing contract string or test was changed; the proven failure is load-order/reachability.
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
                                 "zone":  "logic/load-order",
                                 "file":  "woodev/shipping-method/class-shipping-plugin.php",
                                 "line":  93,
                                 "evidence":  "Shipping_Plugin::includes() loads shipping framework classes but never requires `order/abstract-webhook-handler.php`. A dependent plugin extending the new class through the framework will fatal with class not found unless it manually loads the file."
                             }
                         ],
    "notes":  "Touches shipping_method_id by path, REST via register_rest_route(), and hooks via do_action(). No existing contract string or test was changed; the proven failure is load-order/reachability.",
    "confidence":  0.94
}
```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.
