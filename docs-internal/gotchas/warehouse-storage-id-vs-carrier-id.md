# Warehouse identity: storage row id ≠ carrier-unique id

> Namespace: `shipping/*` — added session 2 (2026-06-09)

## Context

A warehouse has **two distinct identities**:
- **storage row id** — the integer auto-increment PK in the backing table; what
  `Warehouse_Store::get(int)/save():int/delete(int)` key on.
- **carrier-unique id** — the carrier's own code for the warehouse (Yandex `station_id`,
  etc.); what `Warehouse::get_id()` returns (a string).

These are different values with different lifecycles. Conflating them corrupts installed-site
data: writing a numeric row id into the carrier-id field overwrites the carrier's real code.

## The gotcha

1. The `Warehouse` value object historically carried **only** the carrier id, not the row
   id. So `Abstract_Warehouse_Store::save()` — which decided update-vs-insert by reading the
   PK out of `to_row()` — could **never** produce that PK, so it **always inserted, never
   updated** (a latent bug that no test caught because nothing exercised a second save).
2. The first warehouses REST controller "fixed" the missing row id by folding the URL
   `(int)$request['id']` into `Warehouse::get_id()` — corrupting the carrier code on every
   update (critic 0.99).

## Correct (the redesign, session 2)

- `Warehouse` carries a **nullable `storage_id`** (`get_storage_id(): ?int` +
  immutable `with_storage_id()`), separate from the carrier `get_id()`.
- The abstract store stamps `storage_id` from the PK column in `get()/all()`, and `save()`
  reads it from the VO (`get_storage_id() ?? 0` → positive updates, null inserts) while
  stripping the PK column from the written row data.
- REST: route `(?P<id>\d+)` = storage row id; response `id` = storage row id; response
  `code` = carrier id. The route id is **never** written into `get_id()`.
- Carrier-specific columns (Yandex `geo_id`, `comment`, `time_from/to`, `flat`, `entrance`,
  `floor`, `intercom`) round-trip through the `raw` escape hatch; the abstract controller is
  generic and exposes them via subclass seams.

## Incorrect

- Treating `get_id()` as both the DB key and the carrier code.
- Building a `Warehouse` from only the supplied REST params on update (drops every omitted
  field) — use **read-merge** (start from the persisted `to_array()`).

## Related

- [[dispatcher-files-unwired-in-includes]] — the controller was also unwired in production.
- Resolved the deferred `s1-p4-rest-warehouses` escalation.
