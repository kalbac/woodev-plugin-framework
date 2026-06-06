# ESCALATION gate-s1-p1-pickup-selection -- gate escalation

**Task:** s1-p1-pickup-selection -- Pickup_Selection — chosen-point WC session persistence (session-only, plugin-supplied key)
**Type:** needs-guard
**What happened:** Gate decision: ESCALATE. zone 'db_schema' touched but is NOT auto_guardable (human-only: Custom DB tables/schemas. Schema diffs are NOT mechanically mutatable -> human-only.)
**Decision you need to make:** Approve this change manually?
**Option A:** Approve + commit
**Option B:** Reject
**Cost of being wrong:** touches an unguarded contract zone or the constitution

**Evidence:**
```
{
    "task_id":  "s1-p1-pickup-selection",
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
                              "id":  "order_session_meta",
                              "auto_guardable":  true,
                              "guarded":  true,
                              "guard_test":  "tests/unit/Contract/YandexOrderMetaPrefixContractTest.php",
                              "mutation_passed":  true,
                              "blessed":  true
                          },
                          {
                              "id":  "db_schema",
                              "auto_guardable":  false,
                              "guarded":  false,
                              "guard_test":  null,
                              "mutation_passed":  false,
                              "blessed":  false
                          }
                      ],
    "decision":  "ESCALATE",
    "reasons":  [
                    "zone \u0027db_schema\u0027 touched but is NOT auto_guardable (human-only: Custom DB tables/schemas. Schema diffs are NOT mechanically mutatable -\u003e human-only.)"
                ],
    "changed_files":  [
                          "autodev/queue/pending/s1-p1-pickup-selection.md",
                          "autodev/queue/pending/s1-p1-pickup-source.md",
                          "serena/project.yml",
                          "woodev/shipping-method/pickup/class-abstract-warehouse-store.php",
                          "woodev/shipping-method/pickup/class-pickup-selection.php",
                          "woodev/shipping-method/pickup/interface-warehouse-store.php",
                          "autodev/queue/active/s1-p1-pickup-selection.md",
                          "autodev/queue/done/s1-p1-pickup-source.md",
                          "serena/memories/memory_maintenance.md"
                      ]
}
```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.
