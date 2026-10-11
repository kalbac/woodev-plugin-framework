# Gotcha: [box-packer/boxes] — `Woodev_Packer_Boxes` checks volume, it places nothing
> Tags: box-packer, merchant-boxes, cdek | Session: s168

## What happens

The merchant-boxes mode (`Woodev_Packer_Dispatcher::ALGORITHM_BOXES` → `Woodev_Packer_Boxes`) looks like a 3-D
packer and is not one. It can report as packed a set that does not physically fit: three 29×29×10 plates «fit» a
30×30×30 box (25 230 of 27 000 cm³), although only two lie flat. The merchant gets fewer parcels than the carrier
will count at drop-off.

## Root cause

`Woodev_Box_Packer_Packed_Box::try_to_pack()` (`woodev/box-packer/class-packed-box.php:94`) accepts an item when
(a) its three sorted sides each fit the box's sorted sides and (b) the running SUM of item volumes stays within the
box volume. No coordinates, no layers, no voids. Found by Fable while designing #1212 (s168): it was the intended
«oracle» for the new virtual box and could not be one.

## Fix

❌ Trusting `Woodev_Packer_Boxes` / `Packed_Box::get_packed_items()` as proof that items fit a given box.

✅ For «does this set fit this box» use a real placement: `Woodev_Packer_Virtual_Box` (#1212, PR #1213) places
units into maximal free spaces and exposes `get_placement()`. Rebuilding the merchant-boxes mode on it is card
#1214; the parcel-locker cell check (#1215) must use it with the cell as a fixed box.

## Related

- [virtual-box-rsort-axis-alignment](virtual-box-rsort-axis-alignment.md)
- `docs-internal/sessions/s168.md`
