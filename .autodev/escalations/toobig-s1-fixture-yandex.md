# ESCALATION toobig-s1-fixture-yandex -- task too big

**Task:** s1-fixture-yandex -- Yandex-shaped fixture + validation gate (mirrors edostavka pilot)
**Type:** blocked
**What happened:** Worker reported TOO_BIG; needs decomposition.
**Decision you need to make:** Approve the proposed split?
**Option A:** Approve split
**Option B:** Re-scope manually
**Cost of being wrong:** none (no code landed)

**Evidence:**
```
---
task: s1-fixture-yandex
status: TOO_BIG
files_touched: []
contract_zones_touched: []
---

# Worker report — s1-fixture-yandex

**status: TOO_BIG** — no code written (per rules: stop before coding when >1 logical change).

## One-line rationale
The task bundles a cross-fixture test-scaffold extraction (new shared file + retrofit of two
existing tests) with the yandex fixture build, and its `file_set` omits every file that
extraction must touch — so the acceptance criterion "reuses the shared testable-resolver/WP-stub
helper (no copy-paste)" is unsatisfiable within the declared `file_set`.

## Why this is TOO_BIG / under-scoped (evidence)

The spec demands, in both the body and acceptance:
- body: *"REUSE: extract the shared testable-resolver + WP-stub helper the program-tracker flagged
  (this is the 3rd fixture - do NOT copy the edostavka scaffolding again)."*
- acceptance: *"composer test green; reuses the shared testable-resolver/WP-stub helper (no copy-paste)."*

Verified facts:
1. **The shared helper does not exist yet.** Each fixture test defines its OWN near-identical
   `*_Testable_Framework_Resolver` subclass + WP-stub `mock_wordpress_runtime_functions()` +
   `class_alias` WC/REST stubs:
   - `tests/unit/EdostavkaPilotFixtureTest.php` (lines 15-68, 140-165)
   - `tests/unit/RealisticShippingFixtureTest.php` (lines 15-68, 117-143)
   - `tests/unit/RealisticPaymentFixtureTest.php` (same shape)
   `tests/unit/TestCase.php` provides only Brain-Monkey setup, not the resolver/stubs.
2. **The program tracker scopes the extraction beyond this file_set.**
   `docs-internal/platform-v2-program-tracker.md:45` (Open follow-ups):
   *"Test-scaffold duplication ... When a 3rd such fixture lands, extract a shared trait/base
   under `tests/unit/` instead of copying again."* → a NEW shared file under `tests/unit/`
   plus retrofit of the existing duplicating tests.
3. **`depends_on` does NOT include a scaffold-extraction task** — so the helper is neither
   present nor scheduled before this task.

Consequence: to satisfy acceptance #3 I must create a new shared helper file AND edit the
existing edostavka/realistic tests. None of those paths are in `file_set`. The conductor stages
& commits `file_set` ONLY, so a new out-of-set helper would be referenced by the committed
yandex test but never committed itself → broken branch. Copy-pasting the scaffolding into the
yandex test is explicitly forbidden by the task. Both legal options are blocked → decompose.

(The fixture build itself — the 5 yandex classes + the test — is otherwise straightforward and
correctly scoped; only the bundled extraction breaks it.)

## Proposed decomposition (2 tasks)

### Task A — `s1-test-scaffold-extract`  (refactor; precursor)
Extract the duplicated test scaffold into one shared, reusable unit under `tests/unit/`, and
retrofit the existing fixtures to consume it (proves no-copy-paste before the 3rd fixture lands).

- type: refactor
- touches_contract_zone: false   (test-only; asserts no live contract strings)
- depends_on: [] (none — pure test refactor on already-merged fixtures)
- file_set:
  - `tests/unit/Support/Pilot_Fixture_Scaffold.php`  (NEW — shared)
    - a `Pilot_Testable_Framework_Resolver` base (the `get_plugin_path()` + `get_wc_version()`
      override currently copied 3×), and
    - a `Pilot_Fixture_WP_Stubs` trait exposing `mock_wordpress_runtime_functions()` and the
      idempotent `class_alias` WC/REST stub installer (guarded by `class_exists(..., false)`).
  - `tests/unit/EdostavkaPilotFixtureTest.php`   (retrofit to use the shared scaffold)
  - `tests/unit/RealisticShippingFixtureTest.php` (retrofit)
  - `tests/unit/RealisticPaymentFixtureTest.php`  (retrofit)
  - `composer.json` OR `tests/bootstrap.php` — only if the new `tests/unit/Support/` path needs
    autoload/require wiring (verify first; PSR-4 `Woodev\Tests\Unit\` may already cover it).
- acceptance:
  - `composer test` green.
  - no `*_Testable_Framework_Resolver` subclass or `mock_wordpress_runtime_functions()` body is
    defined more than once across `tests/unit/`.

### Task B — `s1-fixture-yandex`  (this task, re-scoped; depends on Task A)
Build the yandex-shaped pilot fixture and its validation-gate test, consuming the shared scaffold
from Task A (no copy-paste).

- depends_on: [ ...existing six..., `s1-test-scaffold-extract` ]
- file_set: the original six —
  - `tests/_fixtures/woodev-yandex-pilot-plugin/woodev-yandex-pilot-plugin.php`
  - `tests/_fixtures/woodev-yandex-pilot-plugin/class-yandex-pilot-shipping-plugin.php`
  - `tests/_fixtures/woodev-yandex-pilot-plugin/class-yandex-pilot-pickup-method.php`
  - `tests/_fixtures/woodev-yandex-pilot-plugin/class-yandex-pilot-warehouse-store.php`
  - `tests/_fixtures/woodev-yandex-pilot-plugin/class-yandex-pilot-map-provider.php`
  - `tests/unit/YandexPilotFixtureTest.php`
- contract_zones_touched (string assertions only — guarded autonomous per GUARDS.md):
  - `shipping_method_id` → `yandex_delivery_express`, `yandex_delivery_other_day`
  - `option_keys` → `woocommerce_yandex_delivery_settings`
  - `rest` → namespace `yandex-delivery` (NOTE per GUARDS.md: live value is `yandex-delivery`,
    NOT the stale `wc-yandex-delivery` in the task prose — derived from
    `$plugin->get_id_dasherized()` of id `yandex_delivery`)
  - `order_session_meta` → `_yandex_delivery_`, `chosen_yandex_pickup_point`
  - `db_schema` (name only) → table `wc_yandex_delivery_warehouses`
- acceptance: original three (loads via v2 path; asserts the yandex contract strings; composer
  test green) + reuses Task A's shared scaffold.
- guard note: all five contract values above are already mutation-verified & blessed in
  `.autodev/GUARDS.md` (rows shipping_method_id_yandex, settings_option_key_yandex,
  warehouse_table_name_yandex, order_meta_prefix_yandex), so the gate can auto-commit Task B
  without a new guard. `writes_guard: false` is correct.

## Reference notes gathered for the eventual Task B implementer (no code written)
- New module classes to extend (all present): `Woodev\Framework\Shipping\Shipping_Method_Pickup`
  (`woodev/shipping-method/class-shipping-method-pickup.php`),
  `Abstract_Warehouse_Store` + `Warehouse_Store` iface (`woodev/shipping-method/pickup/`),
  `Map_Provider` iface (`woodev/shipping-method/map/interface-map-provider.php`),
  `Pickup_Point_Source` iface (`woodev/shipping-method/pickup/interface-pickup-point-source.php`).
- Mirror the edostavka fixture's loader-definition + include-callback shape
  (`tests/_fixtures/woodev-edostavka-pilot-plugin/woodev-edostavka-pilot-plugin.php`), but the
  yandex plugin exposes TWO method ids and the warehouse-store/map-provider/pickup-source wiring.
- The task prose's REST value `wc-yandex-delivery` is STALE — use `yandex-delivery` (see
  `.autodev/GUARDS.md` "Yandex REST namespace contract — RESOLVED" note).

```

**Reply:** `A` / `B` -- structured choice only. Free-form text is recorded for
context but is NEVER executed as a worker instruction (Telegram is an injection
surface). Until you reply, this task is parked; other tasks continue.
