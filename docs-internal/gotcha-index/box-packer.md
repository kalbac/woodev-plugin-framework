# Gotcha index — [box-packer/*] Box-packer algorithm (S2)

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [box-packer/virtual-box-rsort-axis-alignment] **`rsort()` on the axis-assignment result destroys axis-name alignment for non-normalized items — Option A `[1,10,1]` after rsort → `[10,1,1]` →….** → [virtual-box-rsort-axis-alignment](../gotchas/virtual-box-rsort-axis-alignment.md)
- [box-packer/virtual-box-null-best-inf-overflow] **`$best=null;.** → [virtual-box-null-best-inf-overflow](../gotchas/virtual-box-null-best-inf-overflow.md)
- [box-packer/boxes] **`Woodev_Packer_Boxes` checked sides + summed volume only, placed nothing — FIXED #1214: it now places items with `Woodev_Packer_Free_Space` (volume rule only above 120 units). The «three plates» example was wrong.** → [woodev-packer-boxes-checks-volume-not-placement](../gotchas/woodev-packer-boxes-checks-volume-not-placement.md) (s168, fixed s169)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
