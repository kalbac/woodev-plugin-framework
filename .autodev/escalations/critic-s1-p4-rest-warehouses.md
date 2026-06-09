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

---

**RESOLVED 2026-06-09 (operator-directed redesign; commits `e3f9e7d` + `1033c62`).**
All three findings fixed by the warehouse-layer redesign (no longer a deferred React-only item):
- (a) **partial-update data loss** -> `update_item()` is now read-merge: it loads the persisted
  warehouse and uses it as the base (`prepare_item_for_database($request, $existing)`), so omitted
  fields are preserved instead of overwritten.
- (b) **REST schema dropping carrier fields** -> the abstract schema declares only the generic core
  + a readonly `id` (storage row) + writable `code` (carrier id); carrier-specific fields
  (geo_id/comment/time_from/time_to/flat/entrance/intercom/floor) are added by the concrete
  controller via three seams (`get_additional_schema_properties`, `merge_additional_fields_into_data`,
  `prepare_additional_response_fields`) and round-tripped through the Warehouse `raw` escape hatch.
- (c) **storage-row-id vs carrier-id conflation** -> the `Warehouse` VO gained a nullable
  `storage_id` (separate from the carrier `get_id()`); `Abstract_Warehouse_Store` stamps it from the
  PK in `get()/all()` and reads it in `save()` (positive -> update, null -> insert). The REST route
  `(?P<id>\d+)` is the storage row id; the body `code` is the carrier id; the route id is never
  folded into the carrier id.
Validated by `WarehousesControllerDataPreservationTest` (Yandex-shaped fixture: table
`wc_yandex_delivery_warehouses`, `station_id`, ns `yandex-delivery`) + `WarehouseStorageIdTest`.
composer check green (PHPCS 152/152, PHPStan 0, PHPUnit 259/812). Adversarial critic: SAFE TO COMMIT.
