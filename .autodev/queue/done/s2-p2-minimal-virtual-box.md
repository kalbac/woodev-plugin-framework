---
id: s2-p2-minimal-virtual-box
title: Minimal-virtual-box axis-assignment algorithm
phase: S2 Box-Packer
type: fix
touches_contract_zone: false
writes_guard: false
file_set:
  - woodev/box-packer/class-packer-virtual-box.php
depends_on: []
contract_zones_touched: []
needs_guard: no
acceptance:
  - composer check green
  - PHPStan 0 errors
  - for 2 items (10x10x5 and 15x10x20 sorted as 20x15x10) result volume <= 4500
  - for 10 identical items 10x10x5 result volume = 5000 (not 500)
  - box_length >= max(item_lengths), box_width >= max(item_widths), box_height >= max(item_heights)
---

# Task

Spec §P2 — `Woodev_Packer_Virtual_Box::calculate_virtual_box_dimensions()` takes the
maximum of each axis independently, producing a box the size of just ONE item regardless
of how many items there are. For 10 identical items of 10×10×5, result is 10×10×5 —
a box that physically cannot contain all items.

The correct approach: try all 3 axis-assignment options (which dimension gets `sum()` vs
`max()`); return the one with minimum enclosing volume. Items are pre-normalised by
`Woodev_Packer_Item_Implementation.__construct()` (sorted descending: length ≥ width ≥ height).

## Algorithm

Replace the private method `calculate_virtual_box_dimensions()` with:

```php
private function calculate_virtual_box_dimensions( array $items ): array {
    // Items are pre-normalised: length >= width >= height.
    // Stack items along one axis: that axis gets sum(), the other two get max().
    // Try all three axis assignments; return the combination with minimum volume.

    $lengths = array_map( fn( Woodev_Box_Packer_Item $i ) => $i->get_length(), $items );
    $widths  = array_map( fn( Woodev_Box_Packer_Item $i ) => $i->get_width(),  $items );
    $heights = array_map( fn( Woodev_Box_Packer_Item $i ) => $i->get_height(), $items );

    $candidates = [
        // Option A: stack along height (smallest dim) — common case for flat items
        [ max( $lengths ), max( $widths ),  array_sum( $heights ) ],
        // Option B: stack along width (middle dim)
        [ max( $lengths ), array_sum( $widths ),  max( $heights ) ],
        // Option C: stack along length (largest dim) — common case for long thin items
        [ array_sum( $lengths ), max( $widths ),  max( $heights ) ],
    ];

    // Initialise to first candidate so $best is never null (even if all volumes overflow to INF).
    $best        = $candidates[0];
    $best_volume = PHP_FLOAT_MAX;

    foreach ( $candidates as $dims ) {
        $volume = $dims[0] * $dims[1] * $dims[2];
        if ( $volume < $best_volume ) {
            $best_volume = $volume;
            $best        = $dims;
        }
    }

    // Each candidate guarantees box_axis >= max(item_axis) by construction.
    // Do NOT rsort: that destroys axis-name alignment for non-normalised custom items.
    return [
        'length' => $best[0],
        'width'  => $best[1],
        'height' => $best[2],
    ];
}
```

The `Woodev_Packer_Box_Implementation` constructor already accepts any (l, w, h) — no
change needed there.

## What NOT to change

- Do not touch `pack()` — it creates the box and packed-box correctly; only the dimension
  calculation is wrong.
- Do not change `order_items_by_volume_desc()`.
- Do not touch any other file.

## Verification examples (from spec)

2 items [10×10×5, 20×15×10]:
- Option A: 20×15×15 → vol 4500 ← winner
- Option B: 20×25×10 → vol 5000
- Option C: 30×15×10 → vol 4500

10 identical [10×10×5]:
- Option A: 10×10×50 → vol 5000 ← all options tie
- (vs current broken: 10×10×5 = vol 500 for 1 item)

## Spec reference

`docs-internal/platform-v2-s2-boxpacker-spec.md §P2`

<!-- committed: 7abd7a4 -->
