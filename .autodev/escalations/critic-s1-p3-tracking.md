# ESCALATION critic-s1-p3-tracking -- critic broken

**Task:** s1-p3-tracking -- Abstract_Tracking_Handler — tracking model + display hooks
**Type:** disagreement
**What happened:** Critic verdict: broken. Touches shipping_method_id by path and hooks via do_action(). No tests were modified. No shipping method ID changed, but the new hook contracts are unguarded and the class is not wired into runtime loading.
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
                                 "zone":  "hooks",
                                 "file":  "woodev/shipping-method/order/abstract-tracking-handler.php",
                                 "line":  119,
                                 "evidence":  "Introduces public action `woodev_shipping_{prefix}_tracking_admin_display`; GUARDS.md has no guard asserting it."
                             },
                             {
                                 "zone":  "hooks",
                                 "file":  "woodev/shipping-method/order/abstract-tracking-handler.php",
                                 "line":  140,
                                 "evidence":  "Introduces public action `woodev_shipping_{prefix}_tracking_frontend_display`; GUARDS.md has no guard asserting it."
                             },
                             {
                                 "zone":  "load_order",
                                 "file":  "woodev/shipping-method/class-shipping-plugin.php",
                                 "line":  93,
                                 "evidence":  "Shipping_Plugin::includes() does not require the new tracking-handler file, and no other repository reference loads Abstract_Tracking_Handler."
                             }
                         ],
    "notes":  "Touches shipping_method_id by path and hooks via do_action(). No tests were modified. No shipping method ID changed, but the new hook contracts are unguarded and the class is not wired into runtime loading.",
    "confidence":  0.98
}
```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.
