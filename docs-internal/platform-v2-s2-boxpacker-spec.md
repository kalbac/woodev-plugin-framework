# S2 Box-Packer Spec — Minimal-Virtual-Box + WC-Neutral Core

> Authored: 2026-06-08. Branch: `autodev/loop-s2`.
> Source: `PLANS.md §3.5–3.5.1`. Program tracker: `platform-v2-program-tracker.md`.
> Predecessor: S1 (merged to main 2026-06-08, PR #20).

---

## Scope

Two independent improvements to `woodev/box-packer/`:

| Problem | Source | Fix |
|---------|--------|-----|
| `Woodev_Packer_Single_Box` calls `wc_list_pluck()` — hard WC dependency in core packer logic | `PLANS.md §3.5` "привязан к WooCommerce" | Replace with `array_map()` — no behaviour change |
| `Woodev_Packer_Virtual_Box::calculate_virtual_box_dimensions()` takes `max()` per axis independently, producing a physically impossible box when items have mismatched sizes | `PLANS.md §3.5.1` | Try all 3 axis-assignment options (which dimension gets `sum()` vs `max()`), return the minimum-volume result |

**Not in scope:**
- `Woodev_Packer_Separately` (WC-specific by design — it formats box names from WC product data; no change)
- `Woodev_Box_Packer_Item_With_Product` interface (WC-specific optional extension; non-WC users implement `Woodev_Box_Packer_Item` only)
- Full 3D bin-packing / NP-hard optimal placement
- Namespace migration (box-packer stays in global namespace for now; no installed-site contracts)

---

## P1 — WC-Neutral Single-Box Core

**File:** `woodev/box-packer/class-packer-single-box.php`

**What:** `Woodev_Packer_Single_Box::get_items_dimensions()` calls `wc_list_pluck()` (WooCommerce function). If WC is inactive, calling `Woodev_Packer_Single_Box::pack()` fatals.

**Fix:** replace `wc_list_pluck( $this->items, 'get_X' )` with `array_map( fn( $item ) => $item->get_X(), $this->items )` for all three dimensions.

**Before:**
```php
private function get_items_dimensions(): array {
    return array(
        'height' => wc_list_pluck( $this->items, 'get_height' ),
        'length' => wc_list_pluck( $this->items, 'get_length' ),
        'width'  => wc_list_pluck( $this->items, 'get_width' ),
    );
}
```

**After:**
```php
private function get_items_dimensions(): array {
    return array(
        'height' => array_map( fn( Woodev_Box_Packer_Item $item ) => $item->get_height(), $this->items ),
        'length' => array_map( fn( Woodev_Box_Packer_Item $item ) => $item->get_length(), $this->items ),
        'width'  => array_map( fn( Woodev_Box_Packer_Item $item ) => $item->get_width(), $this->items ),
    );
}
```

**Contract zones:** none. No option keys, hooks, routes, cron, meta keys touched.
**Acceptance:** `composer check` green; behaviour identical for any input.

---

## P2 — Minimal-Virtual-Box Algorithm

**File:** `woodev/box-packer/class-packer-virtual-box.php`

### The problem

`calculate_virtual_box_dimensions()` currently:
```php
$max_length = max per item of get_length()   // independent axis max
$max_width  = max per item of get_width()
$max_height = max per item of get_height()
return ['length' => $max_length, 'width' => $max_width, 'height' => $max_height]
```

This gives the **bounding box of ONE item** (the largest per axis). For N identical items of
10×10×5, the result is 10×10×5 — a box the size of one item, yet `pack()` forces all N items
in. The resulting `Woodev_Box_Packer_Packed_Box` fails volume checks when `get_packed_items()`
is called: only the first item fits, the rest are "nofit".

### The fix — axis-assignment search

`Woodev_Packer_Item_Implementation.__construct()` pre-sorts each item's dimensions descending:
`length ≥ width ≥ height`. This normalisation is already in production.

Items stacked along one axis occupy: `max(other two dims) × max(other two dims) × sum(stack axis)`.
This guarantees all items physically fit (each item's stack-axis value is ≤ the total sum,
the other two axes are ≤ the respective max).

Try all three axis assignments; return the one with minimum enclosing volume:

```
Option A — stack along height (smallest dim):
    box = max(lengths) × max(widths) × sum(heights)

Option B — stack along width (middle dim):
    box = max(lengths) × sum(widths) × max(heights)
    then rsort dims to keep length ≥ width ≥ height

Option C — stack along length (largest dim):
    box = sum(lengths) × max(widths) × max(heights)
    then rsort dims
```

Pick the option with `L × W × H` minimised; rsort the result so `length ≥ width ≥ height`.

### Algorithm walkthrough (PLANS.md §3.5.1 examples)

**2 items:** Item1 = 10×10×5, Item2 = 15×10×20 (sorted: 20×15×10)

| Option | Calculation | Volume |
|--------|-------------|--------|
| A (sum H) | max(10,20)=20 × max(10,15)=15 × sum(5,10)=15 | 4500 |
| B (sum W) | max(20)=20 × sum(10,15)=25 × max(5,10)=10 → rsort: 25×20×10 | 5000 |
| C (sum L) | sum(10,20)=30 × max(10,15)=15 × max(5,10)=10 → rsort: 30×15×10 | 4500 |

**Winner: Option A → 20×15×15** (matches PLANS.md expected result) ✓

**3 items:** adding 10×10×5

| Option | Calculation | Volume |
|--------|-------------|--------|
| A (sum H) | 20×15×sum(5,10,5)=20 | 20×15×20 = **6000** |
| B (sum W) | 20×sum(10,15,10)=35×max(5,10,5)=10 → 35×20×10 | 7000 |
| C (sum L) | sum(10,20,10)=40×15×10 → 40×15×10 | 6000 |

**Winner: Option A → 20×15×20** (volume 6000 vs current 20×15×10=3000 which is impossible)

**10 identical items 10×10×5:**

| Option | Calculation | Volume |
|--------|-------------|--------|
| A (sum H) | 10×10×50 | 5000 |
| B (sum W) | 10×100×5 → 100×10×5 | 5000 |
| C (sum L) | 100×10×5 → same | 5000 |

**Winner: any → 10×10×50** (vs current impossible 10×10×5 for 1 item)

### Contract zones

None. No option keys, hooks, routes, cron, or meta keys touched. Pure in-memory algorithm.

### Acceptance

- `composer check` green
- Single item: result equals item dimensions (no regression)
- 2 items: result ≤ volume of sum-smallest-axis option (= PLANS.md expected 20×15×15)
- 3+ identical small items: result volume = N × item_volume (correct stacking)
- Result `length ≥ width ≥ height` always (rsort invariant)

---

## P3 — Validation Gate

**File:** `tests/unit/BoxPackerMinimalVirtualBoxTest.php` (new file)

Unit tests using Brain Monkey. No WordPress or WooCommerce required.

**Test cases:**

1. `test_single_item_returns_item_dimensions()` — 1 item, result = item dims, no inflation
2. `test_two_items_plans_md_example()` — Items from PLANS.md §3.5.1: result = 20×15×15, volume ≤ 4500
3. `test_three_items_volume_correct()` — 3 items (PLANS.md): result volume ≤ Single_Box result (20×15×20)
4. `test_ten_identical_items_volume_equals_sum()` — 10 items of 10×10×5: result volume = 5000 (not 500)
5. `test_result_dims_sorted_descending()` — length ≥ width ≥ height for any input
6. `test_single_box_wc_free()` — call `Woodev_Packer_Single_Box::pack()` without WC active; verify no fatal

**Helper:** use `Woodev_Packer_Item_Implementation` directly (no WC needed) for items.
Use `Woodev_Packer_Box_Implementation` for boxes.

**Contract zones:** none.

**Acceptance:** all 6 tests pass; `composer check` green; PHPStan 0 errors.

---

## Data preservation checklist (installed-site contracts)

Box-packer has **zero installed-site data contracts** — it is a pure in-memory utility:
- No option keys
- No hook names
- No REST routes
- No cron schedules
- No DB schema
- No admin page slugs
- No AJAX actions

No migration checklist required for S2.

---

## Task queue summary

| Task ID | Title | File | Contract zones | Gate |
|---------|-------|------|---------------|------|
| `s2-p1-wc-neutral-single-box` | Remove `wc_list_pluck()` from Single_Box | `class-packer-single-box.php` | none | auto-commit |
| `s2-p2-minimal-virtual-box` | Minimal-virtual-box axis-assignment algorithm | `class-packer-virtual-box.php` | none | auto-commit |
| `s2-p3-validation-gate` | Unit tests for P1 + P2 | `tests/unit/BoxPackerMinimalVirtualBoxTest.php` | none | auto-commit |
