# Packaging, packing modes and parcels

Source of truth: `woodev/box-packer/`, `woodev/shipping-method/class-packaging.php`,
`settings/class-boxes-settings.php`, `settings/class-packaging-settings.php`; the compiled reference is the
"Box packer" section of `docs-internal/wiki/architecture.md`.

## Two lists of boxes — never merge them

- **The store's boxes** live in «Доставка» → «Упаковка» (`Boxes_Settings`, option `woodev_boxes_boxes`, the `boxes-table`
  control; stored as one `name; length; width; height; max weight; box weight` row per box, in the STORE's units). One
  list for the whole site, usable by every carrier. Never duplicate it inside a carrier; a carrier that needs the
  merchant's v1 boxes migrates them into this option.
- **A carrier's preset boxes** are declared in code with `Shipping_Plugin::get_box_presets()`, in **fixed centimetres and
  kilograms** (gotcha [carrier box presets need fixed units](../../../../docs-internal/gotchas/carrier-box-presets-need-fixed-units.md)):
  a stable string `id`, `name`, positive `length`/`width`/`height`, optional `max_weight`/`box_weight` (0 = unlimited / no
  own weight) and a `cost_mode`:
  - `carrier` — the merchant may toggle «Учитывать стоимость»; pass the packed id/count list to the carrier's quote.
    The framework adds no cost for these boxes.
  - `fixed` — declare a nonnegative numeric `cost`; the merchant sees it read-only.
  - `merchant` — the merchant enters an amount or `N%`, charged against that parcel's allocated merchandise value after
    line discounts, before taxes (so the cache identity MUST carry per-line values — gotcha
    [percentage box cost cache needs line values](../../../../docs-internal/gotchas/percentage-box-cost-cache-needs-line-values.md)).

`uses_boxes()` defaults to true when presets are declared; override it to true if the carrier uses only store boxes. A
carrier declaring neither gets no packaging section and its instances offer explicit packing choices without «Как в
настройках плагина». Every `Shipping_Method` that packs declares `FEATURE_BOX_PACKING` in its constructor **before**
`parent::__construct()` (set `$this->supports`; `add_support()` before the parent leaves the method id unset).

```php
public function get_box_presets(): array {
    return [
        [ 'id' => 'CARTON_M', 'name' => 'M', 'length' => 30, 'width' => 20, 'height' => 15, 'cost_mode' => 'carrier' ],
        [ 'id' => 'CARTON_L', 'name' => 'L', 'length' => 40, 'width' => 30, 'height' => 20, 'cost_mode' => 'fixed', 'cost' => 50 ],
    ];
}
// Inside your Shipping_Method::rate_package( $package, $packed ):
$boxes = \Woodev\Framework\Shipping\Packaging::get_carrier_boxes( $packed );
// $boxes = [ [ 'id' => 'CARTON_M', 'count' => 2 ] ]; map to the carrier's own API.
// Return the carrier quote; the framework adds store/fixed/merchant box costs once.
```

Store/fixed/merchant box surcharges also apply when the carrier makes the delivery rate free. Carrier dimensions and
weights are independent of the store units; packed parcel getters return cm/kg.

Carrier settings: `woodev_{underscored plugin id}_packaging_*` — `packing_algorithm`, `unpacked_algorithm`,
`box_{stable id}_{enabled|charge|cost}`. Preset boxes start disabled. The settings are appended to the carrier's single
tab; do not register another handler on these ids or a section named `packaging`. An instance's `packing_algorithm`
of `default` means the carrier-level setting (`unpacked_algorithm` inherits the same way).

## Packing modes (`Woodev_Packer_Dispatcher`)

| Mode | Meaning |
|---|---|
| `virtual` — «Всё в одну коробку» | one virtual box for everything, sized from a REAL placement (below) |
| `separately` | every unit is its own parcel |
| `boxes` | pack into the store's boxes plus the carrier's enabled presets |

- **`single` (items stacked along one axis) is retired from the choice (#1212).** The constant, `pack('single')` and
  `Woodev_Packer_Single_Box` stay in code, but a stored `single` — as the packing mode or as the leftovers mode — is read
  as `virtual` through `Woodev_Packer_Dispatcher::normalize_stored_algorithm()`. A v1 migration that carries a
  `single` value needs no special case; the settings page shows it as `virtual`. Leftovers (`unpacked_algorithm`) are
  `virtual` or `separately` only. Sources: `class-packer-dispatcher.php` (`ALGORITHM_SINGLE`, `normalize_stored_algorithm()`),
  `settings/class-packaging-settings.php`, PR #1213.
- **The virtual box is a real 3-D placement, not an arithmetic box** (#1212): units are placed largest-first into the
  deepest-bottom-left free space of a set of maximal free boxes (any rotation; small items land in the voids), the smallest
  volume wins and, within 10 %, the shortest longest side. The work is bounded by COUNTS, never by the clock — the rate cache
  keys on the box, so the result must be deterministic. Above `Woodev_Packer_Free_Space::MAX_UNITS` (120) units the old
  arithmetic grid box is returned; the grid is also the ceiling the placed box never exceeds. `get_placement()` exposes the
  frame and each unit's corners for verification. Measured, 16 units (summed 76 230 cm³): single 141×60×30, grid 88×74×60,
  placement 60×50×40 (s168).
- **`boxes` accepts a set only when it can really be placed** (#1214 → PR #1218): `Woodev_Packer_Boxes` builds each box with
  `new Woodev_Box_Packer_Packed_Box( $box, $items, true )`, which checks every item against `Woodev_Packer_Free_Space` (all
  six orientations; at most 120 placements per parcel — the rest of the cart waits for the next parcel). Before it, a set was
  accepted by sorted sides and summed volume alone, and ~30 % of mixed carts got a parcel that physically could not hold its
  contents (merchant under-counted parcels by 11–33 %). Gotcha
  [Packer_Boxes checked volume, placed nothing](../../../../docs-internal/gotchas/woodev-packer-boxes-checks-volume-not-placement.md).
- A unit that fits no box is NEVER dropped: it travels as a parcel of its own, or joins the leftovers' virtual box. With no
  boxes defined every unit is such a parcel.
- Box selection maximises packed units to reduce the parcel count; on equal fill store boxes win over carrier boxes,
  independently of cost; then the smallest volume.
- **Measure a packing change, do not eyeball it.** The grid "fix" for the virtual box looked right until its volume was
  measured (×1.5 the placed box): it would have raised merchants' shipping costs (s168). Run the reference sets before and after.

## Which items are in which parcel

For a multi-parcel export use `$method->pack_order( $order )` — the same policy as checkout — or any `Woodev_Packer_Result`.
Each `Woodev_Packer_Package_Result` has `get_items()` (`[ ['key' => cart-item key / order-item id, 'product_id' =>
variation or product id, 'quantity' => units in this parcel], … ]`), `get_box_id()`, `get_box_name()` and
`get_box_origin()` (`store`, `carrier` or empty for an unboxed parcel); `to_array()` carries the same. This works for every
algorithm, and the quantities of one `key` add up to the line's quantity. A rate's cart keys and an order's item ids differ:
compare parcels' sizes and weights across a quote and an export, never `items`.

## Cache identity

Use the inherited `get_rate_cache_context()` when extending cache identity: it already includes box declarations, toggles,
costs, effective leftovers and per-line values. Add only what the carrier reads beyond that.

## Migrating a v1 box list

Decide per plugin and write it down. CDEK carried the v1 boxes to everybody but ENABLED them only for shops that packed
into boxes in v1, so behaviour did not change for the rest (s158); its old carrier box codes (`S`, `L`, `500GR`, …) were
NOT carried because the live catalogue gives other dimensions under other codes (`docs/cdek-api/contract.md` in the plugin).

## Parcel-locker cells — not on `main` yet

Checking that the order fits the CELL of a parcel locker is framework PR #1219 (card #1215): `Pickup_Point` carries optional
cells and `Constraint_Checker` places the cart items into each cell as a fixed box. Until it merges, `Constraint_Checker`
checks weight and cash-on-delivery only, and `Pickup_Point` has no cells — do not write a carrier-side check; re-read
`pickup/class-pickup-point.php` once #1219 is merged. The CDEK mapper half is plugin PR #64 (`dimensions` on `POSTAMAT`).
