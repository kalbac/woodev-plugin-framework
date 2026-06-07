# ESCALATION critic-s1-p4-admin-order -- critic broken

**Task:** s1-p4-admin-order -- Shipping_Admin_Order — order-list column + order metabox
**Type:** disagreement
**What happened:** Critic verdict: broken. The diff touches the shipping_method_id invariant zone through woodev/shipping-method/** and get_method_id(), but does not alter a concrete method ID. No tests were modified. No existing installed-site contract string was renamed or removed.
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
                                 "zone":  "logic/regression",
                                 "file":  "woodev/shipping-method/admin/class-shipping-admin-order.php",
                                 "line":  356,
                                 "evidence":  "The track action calls display_admin() during an admin-post request, then redirects. display_admin() fires a rendering hook whose subscribers output tracking history, so output is discarded and may prevent the redirect due to headers already sent. Tracking must render on the order-edit request, not before redirecting."
                             }
                         ],
    "notes":  "The diff touches the shipping_method_id invariant zone through woodev/shipping-method/** and get_method_id(), but does not alter a concrete method ID. No tests were modified. No existing installed-site contract string was renamed or removed.",
    "confidence":  0.97
}
```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.
