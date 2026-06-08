# ESCALATION gate-s1-p1-pickup-models -- gate escalation

**Task:** s1-p1-pickup-models -- Pickup_Point + Warehouse value objects (pure, immutable)
**Type:** needs-guard
**What happened:** Gate decision: ESCALATE. zone 'gateway_id' touched but no mutation-verified guard covers it (needs guard)
**Decision you need to make:** Approve this change manually?
**Option A:** Approve + commit
**Option B:** Reject
**Cost of being wrong:** touches an unguarded contract zone or the constitution

**Evidence:**
```
{
    "task_id":  "s1-p1-pickup-models",
    "composer_green":  true,
    "constitution_touched":  [

                             ],
    "zones_touched":  [
                          {
                              "id":  "shipping_method_id",
                              "auto_guardable":  true,
                              "guarded":  true,
                              "guard_test":  "tests/unit/Contract/ShippingMethodIdContractTest.php",
                              "mutation_passed":  true,
                              "blessed":  true
                          },
                          {
                              "id":  "gateway_id",
                              "auto_guardable":  true,
                              "guarded":  false,
                              "guard_test":  null,
                              "mutation_passed":  false,
                              "blessed":  false
                          }
                      ],
    "decision":  "ESCALATE",
    "reasons":  [
                    "zone \u0027gateway_id\u0027 touched but no mutation-verified guard covers it (needs guard)"
                ],
    "changed_files":  [
                          "autodev/queue/pending/s1-p1-map-provider-php.md",
                          "autodev/queue/pending/s1-p1-pickup-models.md",
                          "serena/project.yml",
                          "woodev/shipping-method/pickup/class-pickup-point.php",
                          "woodev/shipping-method/pickup/class-warehouse.php",
                          "autodev/queue/active/s1-p1-pickup-models.md",
                          "autodev/queue/done/s1-p1-map-provider-php.md",
                          "serena/memories/memory_maintenance.md"
                      ]
}
```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.


---
**RESOLVED 2026-06-06 (operator: A / approved).** FALSE POSITIVE — gateway_id grep `$this->id=` matched the shipping Warehouse value-object id, not a real WC payment-gateway contract. Committed fecdd9a; root cause fixed in 57b65b4 (gateway_id scoped to woodev/payment-gateway/**).
