# Orders, export, automation, and tracking

## Shared order plumbing

Register the provider and its handlers on every request, including REST, webhook, and cron requests. Do not put registration inside `is_admin()`: the framework's status automation and scheduled actions need the carrier registered in the request that processes the order.

```php
$registry = Orders_Registry::instance();
$provider = Orders_Provider::create(
    'carrier',
    __( 'Carrier', 'my-carrier' ),
    '_carrier_marker',
    [ 'carrier_courier', 'carrier_pickup' ],
    [
        'marker_writer' => static function ( \WC_Order $order, array $context ): void {
            $order->update_meta_data( '_carrier_marker', '1' );
        },
    ]
);
$registry->register_provider( $provider, $this );
$registry->register_shipment_handler( 'carrier', $shipment_handler );
$registry->register_tracking_handler( 'carrier', $tracking_handler );
```

The marker writer must persist a non-empty scalar marker or the order can appear in the shared list without a carrier owner. The plugin argument to `register_provider()` associates the provider with its `Shipping_Plugin` and enables automation. The realistic fixture's `init_realistic_orders_page()` shows provider and handler setup; `tests/_fixtures/woodev-test-shipping-method/class-test-shipment-handler.php` shows a fuller shipment handler.

Extend `Abstract_Shipment_Handler` for export/cancel. Its constructor takes `Shipping_API`, `Shipping_Order_Handler`, a hook prefix, and an optional `Popular_Settlement_Store`; its sole abstract method is `extract_carrier_order_id( \Woodev_API_Response $response ): string`. Use the shared export, lock, fingerprint/idempotence, retry, and cancellation path in `woodev/shipping-method/order/abstract-shipment-handler.php` rather than making a parallel transport.

## Export settings and automation

The framework adds its `Выгрузка` section to the carrier tab when `Orders_Registry::plugin_exports_orders()` is true for a provider and shipment handler registered in this request. `get_export_settings()` is a getter, not an extension point; do not add a second export settings handler. Merchants control `Export_Settings` (`auto_export_orders`, `export_statuses`), including values migrated from the old integration option. `Order_Automation::auto_export_statuses()` reads those settings. See `Shipping_Plugin::get_settings_providers()`, `get_export_settings()`, and `woodev/shipping-method/order/class-order-automation.php`.

## Delivery statuses and tracking

Map carrier events to the canonical `Delivery_Status` values: `pending`, `created`, `in_transit`, `ready_for_pickup`, `delivered`, `returning`, `returned`, `failed`, `cancelled`, and `unknown`. `Abstract_Tracking_Handler::map_events()` converts API responses into framework tracking events; preserve unmapped raw events as unknown instead of inventing canonical states. Sources: `woodev/shipping-method/order/class-delivery-status.php` and `abstract-tracking-handler.php`.

Implement webhooks by extending `Abstract_Webhook_Handler` and providing `verify_signature()`, `parse_payload()`, `get_namespace()`, and `get_route()`. Return it from `Shipping_Plugin::get_webhook_handler()` so `add_hooks()` registers the route. The base verifies and dispatches the request; it does not update orders or deduplicate carrier event IDs. The plugin must deduplicate events and persist the carrier's raw status under the provider's configured `status_meta_key`, which the shared `status_map` resolves to a canonical delivery status. For a migrated plugin, namespace, route, and the old callback URL are installed-site contracts: keep the old callback working during migration. See `woodev/shipping-method/order/abstract-webhook-handler.php`, `woodev/shipping-method/admin/orders/class-orders-provider.php`, and `docs-internal/specs/2026-10-06-cdek-v2-plugin-design.md` §2.

`Delivery_Sync_Status` records the last delivery refresh; it does not schedule polling. The carrier plugin owns its cron or Action Scheduler schedule. Shipment creation state (remote id/tracking) and delivery status remain separate concerns; preserve in-flight order metadata during migration. See `woodev/shipping-method/order/class-delivery-sync-status.php` and `docs-internal/specs/2026-06-25-shipping-module-decisions.md` §12 and §19.

## Buyer emails and carrier documents

- Buyer status emails are provided by the framework through WooCommerce → Settings → Emails. After
  persisting a changed raw status, a carrier calls
  `Delivery_Status_Events::notify( $order, $provider, $previous_canonical_status )`; this resolves
  the new canonical status and fires `woodev_shipping_delivery_status_changed` once for framework
  emails and extensions. Do not send carrier-owned buyer status emails. Carrier-specific template
  values can be added with the `woodev_shipping_delivery_email_placeholders` filter; the base set
  includes order number, tracking number/URL, carrier name, pickup point and delivery date.
- Carrier documents use the shared `Order\Document_Source` seam (#1134). Register a source against the carrier's orders-provider id with `Orders_Registry::register_document_source()`, declare `supports_label_printing` on the provider, return supported types from `get_document_types()`, and implement `get_document()` as a short request that returns `Document_Result::binary()`, `url()`, `pending($retry_after)`, or `failed($reason)`. Do not block while a carrier generates a file: return `pending` and let the merchant retry. The framework owns the REST download response, filename, authorization, and download meta flag; document bytes are fetched on demand and are not persisted.
