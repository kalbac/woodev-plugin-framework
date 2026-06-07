# ESCALATION critic-s1-fixture-yandex -- critic broken

**Task:** s1-fixture-yandex -- Yandex-shaped fixture + validation gate (mirrors edostavka pilot)
**Type:** disagreement
**What happened:** Critic verdict: broken. The new test asserts only the table name, so it cannot detect this schema contract break. Other touched Yandex strings match canonical values. Independently, the map provider claims an adapter handle but enqueues only the remote Yandex API, not an adapter implementation.
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
                                 "zone":  "db_schema",
                                 "file":  "tests/_fixtures/woodev-yandex-pilot-plugin/class-yandex-pilot-warehouse-store.php",
                                 "line":  46,
                                 "evidence":  "The live table name `wc_yandex_delivery_warehouses` is bound to an incompatible 6-column DDL. The canonical preservation checklist requires the existing 15-column schema byte-for-byte. Abstract_Warehouse_Store::install() passes this schema to dbDelta(). GUARDS blesses only the table name; db_schema is explicitly human-only."
                             }
                         ],
    "notes":  "The new test asserts only the table name, so it cannot detect this schema contract break. Other touched Yandex strings match canonical values. Independently, the map provider claims an adapter handle but enqueues only the remote Yandex API, not an adapter implementation.",
    "confidence":  0.99
}
```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.
