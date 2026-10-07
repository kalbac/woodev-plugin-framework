---
name: woodev-shipping-plugin
description: Load before writing or changing a shipping plugin that runs on Woodev Framework v2. Guides plugin authors through shipping methods, rates, checkout, pickup, orders, tracking, settings, and v1 migration.
---

# Woodev Shipping Plugin Development

Use this skill for a carrier plugin that consumes Woodev Framework v2. Also load [woodev-plugin-core](../woodev-plugin-core/SKILL.md) for shared plugin loading, lifecycle, settings, licensing, i18n, testing, and framework conventions; this skill adds shipping-specific seams.

## Start with the framework seams

- Use `Shipping_Plugin` and concrete subclasses of `Shipping_Method`. The courier, pickup, and postal bases are `Shipping_Method_Courier`, `Shipping_Method_Pickup`, and `Shipping_Method_Postal`. See [shipping model and rates](references/model-rates-location-pickup.md).
- `Shipping_Plugin::register_shipping_methods()` is `final`; provide method classes through `get_shipping_method_classes()`. A class that does not extend `Shipping_Method` is silently filtered out. `Shipping_Method::calculate_shipping()` is `final`; implement `rate_package()` instead.
- The framework supplies shared checkout and order plumbing, but the plugin must return its concrete checkout, pickup, and webhook handlers from `Shipping_Plugin` overrides. Follow the [override seam table](references/model-rates-location-pickup.md#plugin-override-seams).
- Declare HPOS compatibility in the plugin loader definition and support both classic and block checkout. Do not implement a carrier-owned city dropdown or a second pickup selector/map. See [checkout and pickup](references/model-rates-location-pickup.md).

## Build the merchant settings and operations

- Use the settings ownership map in [settings and migration](references/settings-migration.md): plugin-global sections are supplied by `get_tab_settings_providers()`, while tariffs and per-method fields belong to the WooCommerce shipping-method instance. The framework adds shared sections, including `Выгрузка` when orders are registered; use `woodev-settings` by default and take a concrete Integrations-tab exception to the operator.
- Use the shared locality fields and map, shipment/order handlers, canonical delivery statuses, tracking handler, and webhook base. See [model, rates, location, and pickup](references/model-rates-location-pickup.md) and [orders and tracking](references/orders-tracking.md).
- Buyer status emails and carrier documents (waybill/barcode download) are FRAMEWORK features: never write carrier-owned buyer emails or a download flow. Declare `status_meta_key`/`status_map` on the provider (the framework publishes status changes, and the emails, by itself) and implement a `Document_Source` — see [orders and tracking](references/orders-tracking.md).
- For a v1 rewrite, preserve installed-site contracts byte-for-byte and make an explicit migration checklist from `docs-internal/migration/*`; run `npm run probe:signature` and follow `docs-internal/migration/signature-probe.md`.

## Use fixtures as worked examples

- Use the [fixture map](references/model-rates-location-pickup.md#fixture-map) to jump from each implementation seam to its working example. For the Yandex pilot pickup-source shape, see [`tests/_fixtures/woodev-yandex-pilot-plugin/`](../../../tests/_fixtures/woodev-yandex-pilot-plugin/).
- Use [shipping traps](references/traps.md) while designing and debugging. The linked gotchas explain real framework and WooCommerce failures that matter to carrier authors.


## Store and carrier packaging

The store keeps its common boxes in «Доставка» → «Упаковка» (`woodev_boxes_boxes`). Never duplicate
that list inside a carrier. In `Shipping_Plugin`, override `get_box_presets()` to declare preset
boxes in **store units**, with stable string `id`, `name`, positive `length`/`width`/`height`, optional
`max_weight`/`box_weight` (zero means unlimited/no own weight), and `cost_mode`:

- `carrier`: the merchant can toggle «Учитывать стоимость»; pass the packed id/count list to your
  carrier's quote request. The framework adds no cost for these boxes.
- `fixed`: declare a nonnegative numeric `cost`; the merchant sees it read-only.
- `merchant`: the merchant enters an amount or `N%`, charged against that parcel's allocated
  merchandise value after line discounts, before taxes.

`uses_boxes()` defaults to true when presets are declared. Override it to true if the carrier
uses only store boxes and still needs carrier packing defaults. A carrier declaring neither gets
no packaging section. Each `Shipping_Method` that packs must also declare `FEATURE_BOX_PACKING`
in its constructor before the parent constructor; the realistic courier fixture shows this.

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

Carrier settings live in `woodev_{underscored plugin id}_packaging_*`: `packing_algorithm`,
`unpacked_algorithm`, and `box_{stable id}_{enabled|charge|cost}`. Preset boxes start disabled.
`fixed` dimensions and costs always come from the carrier declaration. Settings are appended to
the carrier's single settings tab; do not register another handler using these setting IDs or a
section named `packaging`. The instance's existing `packing_algorithm` remains readable,
including legacy `virtual`; `default` means the carrier setting. `unpacked_algorithm` has the same
inheritance, with `separately`/`single`. Use the inherited `get_rate_cache_context()` when extending
cache identity: it includes box declarations, toggles, costs, effective leftovers, and line values.

Packed parcels retain `get_items()` (source key, product id, quantity). `get_box_id()` and
`get_box_origin()` identify their chosen box; origin is `store`, `carrier`, or empty for unboxed
parcels. Enabled store boxes win whenever they fit a remaining item, independently of cost.
Leftovers use the existing single-box or separate-item mechanism. `pack_order()` uses the same
policy as checkout; carrier order export should continue consuming those allocations.
