# ESCALATION critic-s1-p4-rest-warehouses -- critic broken

**Task:** s1-p4-rest-warehouses -- Abstract_Warehouses_Controller — warehouses CRUD REST controller base
**Type:** disagreement
**What happened:** Critic verdict: broken. Touches `shipping_method_id` via path glob and `rest` via `register_rest_route()`. No tests modified. Namespace and route literals are dynamic, but REST schema incompatibility and partial-update data loss are proven.
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
                                 "file":  "woodev/shipping-method/rest-api/abstract-warehouses-controller.php",
                                 "line":  348,
                                 "evidence":  "Partial updates persist a Warehouse built only from supplied parameters. Omitted fields default to empty values, overwriting existing installed warehouse data."
                             },
                             {
                                 "zone":  "rest",
                                 "file":  "woodev/shipping-method/rest-api/abstract-warehouses-controller.php",
                                 "line":  513,
                                 "evidence":  "The schema changes the existing Yandex warehouse API: `id` becomes string and existing fields including `geo_id`, `comment`, `time_from`, `time_to`, `flat`, `entrance`, `floor`, and `intercom` disappear. GUARDS.md confirms no Yandex REST guard exists."
                             }
                         ],
    "notes":  "Touches `shipping_method_id` via path glob and `rest` via `register_rest_route()`. No tests modified. Namespace and route literals are dynamic, but REST schema incompatibility and partial-update data loss are proven.",
    "confidence":  0.97
}
```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.
