---
id: s2-p3-validation-gate
title: BoxPacker S2 validation gate — unit tests for P1 + P2
phase: S2 Box-Packer
type: test
touches_contract_zone: false
writes_guard: false
file_set:
  - tests/unit/BoxPackerMinimalVirtualBoxTest.php
depends_on: [s2-p1-wc-neutral-single-box, s2-p2-minimal-virtual-box]
contract_zones_touched: []
needs_guard: no
acceptance:
  - composer check green
  - all 6 test methods pass
  - PHPStan 0 errors
---

# Task

Spec §P3 — create `tests/unit/BoxPackerMinimalVirtualBoxTest.php` proving both P1 and P2
are correct. No WooCommerce or WordPress required; use Brain Monkey test setup from
`tests/unit/TestCase.php` (already in place from S0/S1).

## Items helper

Use `Woodev_Packer_Item_Implementation` directly — no WC needed (item constructor takes
floats only). Use `Woodev_Packer_Box_Implementation` for boxes where needed.

## Required test methods

### 1. test_single_item_returns_item_dimensions()
One item 20×15×10. Virtual_Box result must equal 20×15×10 (no inflation).

### 2. test_two_items_plans_md_example()
Items: (10,10,5) and (20,15,10) — using the sorted dimensions from PLANS.md §3.5.1.
Result: `length=20, width=15, height=15` AND volume ≤ 4500.

### 3. test_three_items_volume_less_than_naive()
Add a third item (10,10,5) to the pair above.
Assert result volume ≤ 6000 (the minimum achievable by the 3-option search).
The OLD algorithm would give 20×15×10=3000 which is less than item volumes — assert
that the NEW result volume ≥ sum of item volumes:
  item1=500, item2=3000, item3=500, total=4000 → result ≥ 4000.

### 4. test_ten_identical_items_physically_possible()
10 items of (10,10,5). Result volume must be ≥ 10 × 500 = 5000.
Assert result volume == 5000.

### 5. test_result_dimensions_sorted_descending()
Several items with mixed sizes. Assert `length ≥ width ≥ height` on every result.

### 6. test_single_box_pack_without_woocommerce()
Create a `Woodev_Packer_Single_Box`, add 3 items of (10,10,5), call `pack()`.
Assert no exception thrown and `get_packages()` returns exactly 1 package.
This proves P1 (no `wc_list_pluck()` call). Brain Monkey is already set up; WC
functions are NOT stubbed — the test must pass without them.

## Notes

- All tests extend `TestCase` from `tests/unit/TestCase.php`.
- `@runInSeparateProcess` is NOT needed here (no Brain Monkey function stubs defined).
- Constructor: items can be created as `new Woodev_Packer_Item_Implementation(l, w, h)`.
- Virtual box is tested via `Woodev_Packer_Virtual_Box` directly: `add_item()`, `pack()`,
  `get_packages()[0]->get_box()` → assert `get_length()` / `get_width()` / `get_height()`.

## Spec reference

`docs-internal/platform-v2-s2-boxpacker-spec.md §P3`
