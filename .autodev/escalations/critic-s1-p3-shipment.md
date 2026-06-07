# ESCALATION critic-s1-p3-shipment -- critic broken

**Task:** s1-p3-shipment -- Abstract_Shipment_Handler — create/cancel/export with retry
**Type:** disagreement
**What happened:** Critic verdict: broken. Also touches shipping_method_id by path and hooks through additive do_action() calls; neither changes existing contract values. No tests are modified.
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
                                 "zone":  "background_jobs",
                                 "file":  "woodev/shipping-method/order/abstract-shipment-handler.php",
                                 "line":  202,
                                 "evidence":  "create_job() receives only [\u0027order_id\u0027 =\u003e ...], but Woodev_Background_Job_Handler::process_job() requires a `data` array and throws before process_item(). Failed exports create unprocessable persisted jobs, so retries never run. No background_jobs guard exists."
                             }
                         ],
    "notes":  "Also touches shipping_method_id by path and hooks through additive do_action() calls; neither changes existing contract values. No tests are modified.",
    "confidence":  0.99
}
```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.
