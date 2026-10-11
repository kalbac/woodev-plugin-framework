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

The framework adds its `Выгрузка заказов` section to the carrier tab when `Orders_Registry::plugin_exports_orders()` is true for a provider and shipment handler registered in this request. `get_export_settings()` is a getter, not an extension point; do not add a second export settings handler. Merchants control `Export_Settings` (`auto_export_orders`, `export_statuses`, `status_delivered`, and since #1203 `status_cancelled`), including values migrated from the old integration option. `Order_Automation::auto_export_statuses()` reads those settings. The section also carries «Обновить статусы сейчас» (fires the provider's `cron_hook`) and your own fields via `get_export_section_setting_ids()` — see [settings and migration](settings-migration.md#framework-sections-of-the-carrier-tab). See `Shipping_Plugin::get_settings_providers()`, `get_export_settings()`, and `woodev/shipping-method/order/class-order-automation.php`.

## Delivery statuses and tracking

Map carrier events to the canonical `Delivery_Status` values: `pending`, `created`, `in_transit`, `ready_for_pickup`, `delivered`, `returning`, `returned`, `failed`, `cancelled`, and `unknown`. `Abstract_Tracking_Handler::map_events()` converts API responses into framework tracking events; preserve unmapped raw events as unknown instead of inventing canonical states. Sources: `woodev/shipping-method/order/class-delivery-status.php` and `abstract-tracking-handler.php`.

A carrier that pushes events to you implements webhooks by extending `Abstract_Webhook_Handler` and providing `verify_signature()`, `parse_payload()`, `get_namespace()` and `get_route()`. Return it from `Shipping_Plugin::get_webhook_handler()` so `add_hooks()` registers the route. The default returns `null`, which is correct for an outbound-only carrier such as Yandex (`abstract-webhook-handler.php:23–24`, `class-shipping-plugin.php:2640–2641`). The base verifies and dispatches the request; it does not update orders or deduplicate carrier event ids. The plugin must deduplicate events and persist the carrier's raw status under the provider's configured `status_meta_key`, which the shared `status_map` resolves to a canonical delivery status. For a migrated plugin, namespace, route and the old callback URL are installed-site contracts: keep the old callback working during migration. See `woodev/shipping-method/order/abstract-webhook-handler.php`, `woodev/shipping-method/admin/orders/class-orders-provider.php`, and `docs-internal/specs/2026-10-06-cdek-v2-plugin-design.md` §2. The rules below come from the first full implementation (CDEK: `includes/tracking/`, edostavka#27, `docs/cdek-api/webhook-events.md` in the plugin).

- **CDEK's strategy: the body is a hint; a fresh authenticated GET of the carrier's record is the truth.** Follow the carrier's documented trust and consistency contract, not this one by default: some carriers make the authenticated event itself authoritative, and some provide no read endpoint to re-fetch. The framework-neutral rule is that the base only verifies and dispatches the payload (`abstract-webhook-handler.php:130–136`); what the plugin writes from it is the plugin's decision. CDEK's `Cdek_Webhook_Handler` docblock never writes a status, a price or a date from the body, and its poller and webhook share one snapshot diff, so the two paths cannot disagree.
- **Acknowledge every AUTHENTIC event with HTTP 200, whatever its type.** A type the plugin does not know is logged at debug level and dropped; an unknown order or status also gets a plain 200 with no change. CDEK deletes a subscription whose URL fails for 24 h and disables a URL after 12 failed retries — a 4xx/5xx for "I do not handle this" costs the merchant the whole feed. Authenticity is the token carried by the subscription: a foreign, empty or missing token is refused with 401 and changes nothing.
- **Retries are idempotent.** The carrier retries (CDEK: up to 12 times in 75 minutes): a replay is acknowledged with no second read and no order note, while the NEXT event is still applied. A transient failure while applying is retried through Action Scheduler (`woodev_edostavka_webhook_retry`, 3 attempts), not by failing the response.
- **Serialise per order**: a per-order claim around "read → stage carrier-only data → `Shipment_Facts_Events::record()`", and propagate a `false` result of `record()` so the caller retries (see the facts section below).
- **Subscriptions are opt-in and cap-aware.** CDEK allows 2 active subscriptions PER TYPE per account (`v2_too_many_webhooks`); reconcile each type on its own, check the slots before subscribing, never delete a foreign subscription, never subscribe from a local site, and remember that "a test SITE ≠ a test CONTOUR" — a test site with production keys takes a production slot. Offer «Подключить» / «Отключить» buttons with the result shown at once (the framework's `Settings_Group` seam, [settings and migration](settings-migration.md#framework-sections-of-the-carrier-tab)). Per-account state belongs in its own option (`woodev_edostavka_webhook_state`).
- **Verify locally by emulating the carrier's request** from the documented schema (token gate, status + track, replay, unknown order and status, malformed bodies, the preserved legacy callback), then prove real delivery only through a one-path tunnel — [acceptance on the rig](acceptance-rig.md#real-deliveries-to-a-local-rig). A carrier cannot reach `localhost`.

`Delivery_Sync_Status` records the last delivery refresh; it does not schedule polling. The carrier plugin owns its cron or Action Scheduler schedule. Shipment creation state (remote id/tracking) and delivery status remain separate concerns; preserve in-flight order metadata during migration. See `woodev/shipping-method/order/class-delivery-sync-status.php` and `docs-internal/specs/2026-06-25-shipping-module-decisions.md` §12 and §19.

## Buyer emails and carrier documents

- Buyer status emails are provided by the framework through WooCommerce → Settings → Emails. **A carrier does
  nothing to get them**: the framework watches the order meta the provider declares as `status_meta_key` (and its own
  cancellation marker), and after the request's writes are in (`shutdown`) publishes
  `woodev_shipping_delivery_status_changed( $order, $previous, $current, $provider )` once per REAL change of the
  canonical state, whichever path wrote it — webhook handler, tracking sync, cron re-poll, manual edit. Declare
  `status_meta_key` and `status_map` on the provider and write the raw status with the order's meta API. Shipments
  already in flight when the framework starts watching are adopted silently (no email for an old status).
  `Delivery_Status_Events::notify( $order, $provider )` stays public for a carrier that keeps its status somewhere
  the watcher cannot see; it publishes nothing when the canonical state did not change. Each ready-made email is sent
  once per shipment (one flag per email, so «Передан в доставку» is not repeated across `created` → `in_transit`).
  Do not send carrier-owned buyer status emails. Carrier-specific template values can be added with the
  `woodev_shipping_delivery_email_placeholders` filter; the base set includes order number, tracking number/URL,
  carrier name, pickup point and delivery date (values are escaped in the HTML email).
- Carrier documents use the shared `Order\Document_Source` seam (#1134). Register a source against the carrier's orders-provider id with `Orders_Registry::register_document_source()`, declare `supports_label_printing` on the provider, return supported types from `get_document_types()`, and implement `get_document()` as a short request that returns `Document_Result::binary()`, `url()`, `pending($retry_after)`, or `failed($reason)`. Do not block while a carrier generates a file: return `pending` and let the merchant retry. The framework owns the REST download response, filename, authorization (capability + `X-WP-Nonce`), the in-page admin outcome («ещё готовится» / failure notices; a carrier link opens in a new tab) and the download meta flag; document bytes are fetched on demand and are not persisted. Fixture: `tests/_fixtures/woodev-realistic-shipping-plugin/includes/class-realistic-document-source.php`.
- **A document without a second click (#1191).** The client polls a `pending` answer on its own (30 s absolute deadline, `AbortSignal`, cancelled on `pagehide`) and downloads when ready, so `get_document()` must be idempotent and keep its carrier task between calls. "Not ready" is `pending`, never a failure: CDEK answers a print task for an order it has not accepted yet with `STATE_INVALID`, and the plugin turns that into `pending` (HTTP 202) while the order has no carrier number, and into a plain Russian failure with the raw reason in the log only once it has one (edostavka#8).
- **Bulk print (#1192) is optional**: implement `Order\Bulk_Document_Source` (`get_bulk_document_types()`, `get_bulk_document_limit()` ≥ 1, `get_bulk_document( $orders, $type )`); the route is `GET woodev/v1/shipping/orders/documents/{type}?ids=`. The framework checks each order exists, belongs to this carrier, has a carrier id and offers the type; a carrier that silently leaves an order out of the file (CDEK answers `READY` with a warning) returns `Document_Result::with_skipped()` and the merchant is told through the `X-Woodev-Skipped` header. Remember the task by the SET (a hash of the sorted carrier order ids), never by one order's meta, because the client re-asks with the same orders. CDEK: one task ≤ 100 orders → one PDF.
- **Copies, format and what the merchant prints are carrier fields** in the «Документы для печати» card of «Выгрузка заказов» (`get_export_section_setting_ids()`, card id `labels`): CDEK's receipt copies (default 2), barcode copies (default 1, `copy_count`) and barcode format A4–A7; its waybill is always A4 because `/v2/print/orders` has no format parameter (edostavka#30, s166).

## Carrier-neutral shipment facts (#1205)

Beside the delivery STATUS sits a seam for the facts that are not a status: the carrier's cost, the delivery date, a delivery
problem, a courier. Do not invent carrier-owned notes, flags and hooks for them. Fill a `Shipment_Facts` value object from
the carrier's OWN API read (never a webhook body) and hand it over with one call,
`Shipment_Facts_Events::record( $order, $provider, $facts )` (`woodev/shipping-method/order/class-shipment-facts*.php`;
compiled reference: "Shipment facts" in `docs-internal/wiki/architecture.md`).

- Every fact has three states: not reported (baseline untouched), reported as none (`with_cost( null )`,
  `with_issues( [] )` … — stored explicitly) and reported with a value (a cost of `0.0` is a value). The cost has COMPONENTS
  (`with_cost( ?float, string $currency, string $component = 'delivery' )` — CDEK reports `delivery` and `total`), each with
  its own baseline.
- The framework compares each fact with its stored baseline and acts once per REAL change: no baseline = silent
  initialisation. A change gives one order note and one neutral action — `woodev_shipping_carrier_cost_changed`,
  `woodev_shipping_delivery_date_changed`, `woodev_shipping_delivery_issue`, `woodev_shipping_courier_assigned` — and a cost
  change or an issue leaves ONE attention flag on the order row (`Shipment_Facts_Flag`). WooCommerce totals and shipping lines
  are NEVER rewritten. A carrier that never calls the seam sees nothing happen.
- **The lock covers `record()` only.** Keep your own per-order serialisation around "API read → carrier-only staging →
  `record()`", and propagate a `false` result (lock not granted, nothing applied) so the caller retries on its next pass.
- A carrier whose terminal states differ adds them through the filter `woodev_shipping_shipment_fact_final_states` (CDEK adds
  `failed`); the issue flag clears on a final canonical state, the cost flag does not.
- Keep legacy hooks alive as forwarders when the plugin had public ones (CDEK's `woodev_edostavka_*` forward to the neutral
  actions with their old arguments).

## Cancel, a parcel already on its way, and refusal (#1204)

- `Order_Automation::run_cancel()` queues a cancellation at the carrier when WooCommerce cancels or refunds the order. The
  WooCommerce cancel is NEVER blocked — the admin trusts the merchant and WC cannot reliably forbid a status change.
- A carrier that cannot delete a shipment past a certain point declares it on its `Abstract_Shipment_Handler`:
  `get_handed_over_statuses()` (canonical states) or, when the canonical state is too coarse (CDEK's `created` also covers an order
  already at the warehouse), the order-aware `is_handed_over( $order, $canonical )` reading the raw status meta — from stored data,
  never the API. For such an order the framework sends NO doomed request, writes a plain note and warns inline on the order-edit
  screen when "Cancelled" is picked.
- **«Оформить отказ»** (a return, normally PAID) is an explicit button only: `supports_refusal()` + `refuse( $order )`
  (+ `get_refusable_statuses()` / `is_refusable()`), `destructive` with a `confirm` that names the cost. The server refuses a
  request without the merchant's explicit yes (400 `woodev_shipping_orders_confirmation_required`), records a success once
  (`_woodev_shipment_refusal_requested`) and stops offering the action while the record matches. It is never run by automation,
  bulk, or a WooCommerce status change. CDEK: `POST /v2/orders/{uuid}/refusal`, any status before "delivered"/"not delivered";
  read the carrier's existing refusal BEFORE posting so a retry cannot pay twice (PR edostavka#34).
- **A status set FROM the carrier's "cancelled" news must not re-enter `Order_Automation`**, and canonical `cancelled` also comes
  from the framework's own marker (the merchant's act). The «Статус отменённого заказа» setting (`status_cancelled`, default
  `wc-cancelled`; `Cancelled_Order_Status::apply()`) guards per ORDER id and does nothing for a merchant-cancelled shipment.
  Gotcha [a status change made for the carrier's news](../../../../docs-internal/gotchas/a-status-change-made-for-the-carrier-s-news-must-not-reach-order-automation.md).

## Finding orders — `wc_get_orders()` drops `meta_query` on the posts store

On the legacy posts datastore `wc_get_orders()` silently DROPS the `meta_query` argument and returns a successful, UNFILTERED
result — every order of the shop, and a `limit`/`offset` pages through all of them. HPOS honours it. CDEK stepped on this in six
places (edostavka#46); one handler even matched the shop's newest order for ANY carrier uuid. WooCommerce 11.1.2 source:
`class-wc-data-store-wp.php:283–285`, `class-wc-order-data-store-cpt.php:1075–1083`. Gotcha
[wc_get_orders drops meta_query on the legacy CPT datastore](../../../../docs-internal/gotchas/wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore.md).

**Rule: never hand a `meta_query` to `wc_get_orders()` directly.** One exact key and value needs no helper — top-level
`meta_key` / `meta_value` / `meta_compare` work on both datastores. Anything richer (OR groups, `NOT EXISTS`, `NOT IN`,
numeric comparison) goes through a helper like the worked recipe `Cdek_Order_Query::get_orders( $args, $meta_query )`
(plugin `includes/orders/class-cdek-order-query.php`):

1. Branch on `Woodev_Plugin_Compatibility::is_hpos_enabled()`.
2. HPOS: pass `meta_query` as an ordinary argument.
3. Posts: pass the tree under a private query var and carry NO `meta_query` key at all (its presence trips the notice); translate
   it in the filter `woocommerce_order_data_store_cpt_get_orders_query` into the datastore's own `meta_query` (AND-joined with
   one the datastore built itself), so the conditions run in SQL before `limit`.
4. Hook the translator on the way in and unhook it on the way out of the OUTERMOST call only — queries may nest, and WordPress
   registers a callback once per priority.

Also: a `NOT IN` clause drops rows that have no such meta (only `NOT EXISTS` makes a LEFT JOIN) — gotcha
[NOT IN drops no-meta rows](../../../../docs-internal/gotchas/a-not-in-meta-query-silently-drops-rows-that-have-no-meta-at-all.md);
a negative clause OR-ed across carriers matches every order ([traps](traps.md)). **Measure every order query on BOTH datastores**:
the dev rig runs HPOS, the unit/integration environment runs the posts store, and a probe on one proves nothing about the other.

## Order actions, row flags and toolbar actions (the courier call)

A carrier-specific order operation (CDEK's courier call, a refusal, a re-print) is an ORDER ACTION, not a bespoke screen.
Compiled reference: "Shipping orders page" in `docs-internal/wiki/architecture.md`.

- **Per-order action** — an entry of the filter `woodev_shipping_order_actions`; the action set is declared ONCE and serves the
  orders-page column and the metabox. An action may declare `fields` (types `date`, `select`, `time_range`, `textarea`, optional
  `help`), an `icon` (Dashicons slug without `dashicons-`), and `destructive => true` with a `confirm` sentence. The values arrive
  validated as the `array $payload` argument of `woodev_shipping_perform_order_action` (422 `woodev_shipping_orders_invalid_payload`
  on a bad value). A bulk run skips an action that needs fields.
- **Row flags** — `woodev_shipping_order_row_flags` (`label`, `tone` ok|warn|error|info|muted, `title`, `icon`; at most 3 per row).
  The filter runs for EVERY row of every page: read meta and options only, never the carrier's API.
- **Toolbar action** — one dialog that runs ONE thing for SEVERAL orders: four filters (`woodev_shipping_orders_toolbar_actions`,
  `…_toolbar_dialog`, `woodev_shipping_perform_toolbar_action`, `…_perform_toolbar_row_action`); the action's `provider` is
  REQUIRED, the dialog has at most one form tab (with an `orders` multi-select, ≤ 100 options) and list tabs with row buttons, and
  the orders run sequentially (ten orders = ten carrier calls). Extra display lines: `woodev_shipping_order_metabox_fields` /
  `woodev_shipping_orders_preview_fields`.
- **Carrier rules learned on the courier call** (CDEK `/intakes`, edostavka#11/#17/#18/#20): offer days ONLY from the carrier's
  own availability endpoint (never invent a calendar), with a negative cache; the "one call per address per day" rule is the
  carrier's, not yours; after a cancel the carrier may refuse a re-call (CDEK sandbox: `ve_order_already_has_ci`, deterministic;
  production unknown) — a SOFT rule: remember the refusal, show a plain sentence, keep the others available; the carrier's `202`
  means nothing — an invalid intake becomes `INVALID` 1.5–3 s later, so read it back; read the carrier's data to decide who "needs
  a courier", and cap work before hydration (the newest 500 exports, 100 orders).

## Milestones

`Woodev_Lifecycle` can congratulate a merchant on first actions (`trigger_milestone( $id, $message, $since )` → `register_milestone_message()`),
but the wrapper in `woodev/class-lifecycle.php` (`generate_milestone_notice_message()`, ~l. 348) returns an EMPTY string when the
plugin has no `get_reviews_url()`, so a plugin without a review page shows no milestone at all, silently — and otherwise it prepends
a random English exclamation and a "leave a review / reach support" paragraph. Override `generate_milestone_notice_message()` in your
`Woodev_Lifecycle` subclass (it is `protected`) to show your own text, translated. Also: `trigger_milestone()` is not guarded against
repeats (each call rewrites the message), and its default `$since = '1.0.0'` means an UPDATED shop whose milestone version is already
higher gets none — pass the version that introduced the milestone. `register_milestone_message()` stops after more than three
dismissed ones. Framework card #1217 (open); CDEK's milestones for first export, print, courier call and delivery are plugin PR
edostavka#61 (open at the time of writing).
