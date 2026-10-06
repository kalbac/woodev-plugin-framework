# Shipping model, rates, location, and pickup

## Methods and rate calculation

- Extend `Woodev\Framework\Shipping\Shipping_Plugin`; implement abstract `get_shipping_method_classes()` and `get_api()`. `register_shipping_methods()` is final. It keeps only loaded subclasses of `Shipping_Method`; include/require method classes before this list is read or an unloaded class is silently dropped. See `woodev/shipping-method/class-shipping-plugin.php` (`get_valid_shipping_method_classes()`, `register_shipping_methods()`).
- Implement these `Shipping_Method` contracts with the exact signatures below. A direct subclass also implements `get_delivery_type()`; `Shipping_Method_Courier`, `Shipping_Method_Pickup`, and `Shipping_Method_Postal` provide it as final. `calculate_shipping()` and `calculate_rate()` are final; `rate_package()` is the carrier rate seam. See `woodev/shipping-method/class-shipping-method.php` and `class-shipping-method-{courier,pickup,postal}.php`.

  ```php
  public static function get_method_id(): string;
  public function get_delivery_type(): string; // direct Shipping_Method subclass only
  protected function get_plugin(): Shipping_Plugin;
  protected function get_method_form_fields(): array;
  protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate;
  ```

- Return a `Shipping_Rate` or `null` when unavailable. Keep the carrier's total as returned; the framework does not sum parcel prices. See `woodev/shipping-method/class-shipping-rate.php` and `docs-internal/gotchas/shipping-rate-no-parcel-sum.md`.
- `Shipping_API` exposes `calculate_rates( array ): \Woodev_API_Response`, `get_pickup_points( array ): \Woodev_API_Response`, `create_order( \WC_Order ): \Woodev_API_Response`, `get_order( string ): \Woodev_API_Response`, `cancel_order( string ): \Woodev_API_Response`, `get_tracking( string ): \Woodev_API_Response`, `get_request(): \Woodev_API_Request`, and `get_response(): ?\Woodev_API_Response`. `get_api()` returns this interface; `Abstract_Shipping_API` supplies HTTP plumbing. Source: `woodev/shipping-method/api/interface-shipping-api.php`.

## Rate cache and packing

- Rate caching is opt-in. Override `get_rate_cache_context( array $package ): array`, call `$context = parent::get_rate_cache_context( $package );`, add every quote input the parent cannot see, then return it. The parent includes package/destination, method instance settings, packing, payment, and selected pickup point; add credentials, global account/origin settings, and other inputs read by `rate_package()`. Values must be finite scalars, `null`, or arrays of those; invalid context disables caching. See `woodev/shipping-method/class-shipping-method.php` and `class-shipping-rate-cache.php`.
- Declare features such as `FEATURE_RATE_CACHE`, `FEATURE_BOX_PACKING`, or `FEATURE_SHIPPING_CLASSES` after `parent::__construct()` with `add_support()`, or set `$this->supports` before the parent call. Calling `add_support()` before the parent leaves the method id unset and fires its hook under the wrong name. Box packing and shipping classes also shape instance fields. See `Shipping_Method::add_support()`.
- The framework supplies missing product dimensions through `Default_Dimensions_Settings`; treat these as fallbacks to catalog weight and dimensions. For parcel packing, use `FEATURE_BOX_PACKING` and the contracts in `woodev/box-packer/`; see `docs-internal/wiki/architecture.md` and `docs-internal/gotcha-index/box-packer.md`.

## Location

- Location support is opt-in: override `Shipping_Plugin::needs_location_provider()` to return `true`, and then MUST override `get_location_adapter()` to return a `Location_Adapter`. Its `resolve( Location_Record )` maps the neutral record to an opaque carrier identity; return `null` when not served and throw only for a retryable failure. To get the carrier's own city code for a DaData-produced record, call `$this->get_location_service()->get_delivery_ids( $record )` — never read the DaData token or `raw()['kladr_id']` yourself. It answers `findById/delivery` (cached) as `[ 'cdek_id' => '44', 'boxberry_id' => …, 'dpd_id' => … ]` (strings; absent carrier = absent key), `[]` when DaData is not configured, the record is not DaData's, or DaData knows nothing, and throws `Location_Provider_Exception` only on a transport failure (retryable, so let it propagate from `resolve()`); verify the code against the carrier before trusting it. If the plugin returns true without the adapter, resolution reports `_doing_it_wrong()` and cannot produce the carrier identity. See `woodev/shipping-method/class-shipping-plugin.php` and `woodev/shipping-method/location/interface-location-adapter.php`.
- DaData is bundled in `woodev/shipping-method/location/providers/class-dadata-provider.php`; provide a carrier-owned `Location_Provider` only when the provider's credentials belong to the carrier. The shared layer owns locality inputs, search, popular settlements, and region-to-settlement cascading. Do not create a carrier city dropdown.
- Preserve the selected location's stable key and record; display names are not identifiers. Scope settlement lists by region. See `Location_Service::resolve_for()`, `interface-location-provider.php`, and [shipping traps](traps.md).

## Pickup and checkout

- A pickup method implements `Shipping_Method_Pickup::get_point_source()` and returns a `Point_Source`; the inherited `get_pickup_point_source()` exposes it to the pickup REST and rate paths. Implement `fetch_points()` and `fetch_details()` with normalized `Pickup_Point` values. Bulk sources receive a locality query; viewport sources receive bounds and must honor point-type filters. See `class-shipping-method-pickup.php`, `interface-point-source.php`, `class-point-query.php`, and `class-pickup-point.php`.
- The plugin constructs and returns a `Pickup_Handler` from `Shipping_Plugin::get_pickup_handler()`. It has 14 positional constructor arguments; copy `init_realistic_pickup()` in the realistic fixture so the plugin is argument 14 and `Selection_Scope` is argument 13. Missing plugin context falls back to DOM place names; missing selection scope removes Store API transport and the block checkout pickup button. **Call `$handler->register()` yourself, once, from the memoised builder** — `Shipping_Plugin::add_hooks()` registers the checkout and webhook handlers for you but NOT the pickup handler; without the call there is no checkout button, no REST controller and no selection persistence, and no error. Do not call `register()` on the checkout handler — the framework already does.
- The plugin also constructs and returns `Checkout_Handler` from `get_checkout_handler()`. The fixture builds `Checkout_Fields`, a pickup field, and method scope there. If omitted, there are no managed checkout fields or `persist_values()` order-marker write. `Checkout_Field_Policy` handles shared fields. The framework supports classic and block checkout; do not duplicate the selector/map or rely on classic hooks for Store API behavior.
- `get_webhook_handler()` must return the constructed webhook handler or the framework does not register its route. See the override table below.
- Use loader `type => 'shipping'`; shipping defaults `blocks.cart` and `blocks.checkout` to true. Declare `supported_features['hpos'] => true` only when the plugin supports HPOS. See `Framework_Plugin_Loader_Definition::get_supported_features_for_definition()`.

## Plugin override seams

`Shipping_Plugin` defaults the nullable handlers below to `null`; the framework does not construct them for you. See `woodev/shipping-method/class-shipping-plugin.php`.

| Plugin seam | What to return or implement | If omitted / fixture |
|---|---|---|
| `get_shipping_method_classes()` and `get_api()` | Abstract methods: return loaded method class names and the `Shipping_API`. | Methods are not registered or API operations have no carrier implementation. See `class-realistic-shipping-plugin.php`. |
| `needs_location_provider()` + `get_location_adapter()` | Opt in with `true` and return the required adapter. | Shared locality section and resolution stay inactive or carrier identity cannot resolve. See `class-test-location-adapter.php` and `class-test-cdek-location-provider.php`. |
| `get_checkout_handler()` | Return a configured `Checkout_Handler` with managed fields and pickup method IDs as needed. | No managed checkout fields, persistence, or marker write. See `class-realistic-shipping-plugin.php`. |
| `get_pickup_handler()` | Return a configured `Pickup_Handler` with source, scope, and owning plugin, and call its `register()` yourself (the framework does not). | No shared map/settings or proper selected-point cache context. See `init_realistic_pickup()` in `class-realistic-shipping-plugin.php`. |
| `get_webhook_handler()` | Return the handler instance. | `add_hooks()` cannot register its REST route. |
| `get_tab_settings_providers()` | Return `Settings_Provider` descriptors with plugin-global `Woodev_Abstract_Settings` handlers. | Plugin-specific global settings tab sections are absent. No carrier fixture overrides it yet: see `tests/unit/Shipping/Settings/CarrierSettingsTabTest.php` for the seam and `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php` (`Woodev_Test_Settings`) for the handler/section/connection shape. |
| `get_integration_handler()` | Optional legacy WooCommerce integration handler. | Only needed when preserving an existing integration surface; it is not the usual settings provider seam. |

## Fixture map

Paths below are under `tests/_fixtures/`.

| Implementation | File to study |
|---|---|
| Method fields and rate method | `woodev-realistic-shipping-plugin/includes/abstract-class-realistic-shipping-method.php` |
| Concrete method IDs and delivery type | `woodev-realistic-shipping-plugin/includes/class-realistic-shipping-method.php` |
| Plugin API and handlers / orders registration | `woodev-realistic-shipping-plugin/includes/class-realistic-shipping-plugin.php` |
| Location adapter and carrier provider | `woodev-test-shipping-method/class-test-location-adapter.php`; `class-test-cdek-location-provider.php` |
| Pickup point source and selection scope | `woodev-realistic-shipping-plugin/includes/class-realistic-point-source.php`; `class-realistic-selection-scope.php` |
| Pickup method source seam (`extends Shipping_Method_Pickup`, `get_point_source()`) | `woodev-yandex-pilot-plugin/class-yandex-pilot-pickup-method.php` |
| Checkout handler and pickup handler construction | `woodev-realistic-shipping-plugin/includes/class-realistic-shipping-plugin.php` (`get_checkout_handler()`, `init_realistic_pickup()`) |
| Shipment handler | `woodev-realistic-shipping-plugin/includes/class-realistic-shipment-handler.php`; `woodev-test-shipping-method/class-test-shipment-handler.php` |
| Tracking handler | `woodev-realistic-shipping-plugin/includes/class-realistic-tracking-handler.php` |
| Webhook handler | No concrete carrier webhook fixture exists; use `woodev/shipping-method/order/abstract-webhook-handler.php` as the dispatch contract. |
| Plugin-global settings and connection test | `tests/unit/Shipping/Settings/CarrierSettingsTabTest.php` (seam); `woodev-test-plugin/woodev-test-plugin.php` (`Woodev_Test_Settings`, `Settings_Section::create()` / `create_connection()`) — no carrier fixture overrides `get_tab_settings_providers()` yet |
| Integrations-tab EXCEPTION example (not the default path) | `woodev-test-shipping-method/class-test-cdek-integration.php` |

## Checkout traps

- Never hide a required field only in JavaScript; server validation can still reject an invisible field. Block checkout uses Store API requests, not classic checkout hooks. See [shipping traps](traps.md).
- Managed custom checkout fields are intentionally blank on reload; persist the order data through the framework handler rather than assuming a field value will repopulate itself.
