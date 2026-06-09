---
id: virtual-box-rsort-axis-alignment
namespace: box-packer
tags: [box-packer, php, algorithm]
session: 2026-06-09
---

# virtual-box-rsort-axis-alignment

`rsort()` on the `calculate_virtual_box_dimensions()` result array destroys axis-name alignment for non-normalized items.

## The bug

After choosing the minimum-volume candidate `$best = [l, w, h]`, applying `rsort($best)` reorders by value. For an item `length=1, width=10, height=1`, Option A produces `[max(1)=1, max(10)=10, sum(1)=1]` → after rsort → `[10, 1, 1]`. The result is returned as `['length'=>10, 'width'=>1, 'height'=>1]`. But `box_width=1 < item_width=10` → the packing algorithm rejects the item as non-fitting.

The implicit assumption was that items are always normalized (`length ≥ width ≥ height`). This holds for `Woodev_Packer_Item_Implementation` which sorts in its constructor, but NOT for arbitrary `Woodev_Box_Packer_Item` implementations.

## The fix

Do NOT rsort. Each axis-assignment candidate already guarantees `box_axis ≥ max(item_axis)` by construction (Option A: `max(lengths) × max(widths) × sum(heights)` — every axis covers its named dimension across all items). The returned array already has the correct `['length', 'width', 'height']` naming.

## Related

- [[virtual-box-null-best-inf-overflow]] — the other S2-P2 critic catch, same function
