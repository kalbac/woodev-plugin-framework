## Holistic Integration Review

### Findings

| Severity | Location | Runtime issue |
|---|---|---|
| **High** | [class-shipping-plugin.php:118](D:/Projects/woodev_framework/woodev/shipping-method/class-shipping-plugin.php:118), [class-framework-resolver.php:574](D:/Projects/woodev_framework/woodev/class-framework-resolver.php:574) | **Most S1 subsystem classes are never loaded.** The resolver loads only `class-shipping-plugin.php`; its `includes()` omits pickup models, AJAX, checkout handlers, order handlers, admin classes, REST controllers, and warehouse stores. Because production loading is include-based, host plugins referencing these classes will fatal unless they manually include framework internals. |
| **High** | [class-shipping-plugin.php:156](D:/Projects/woodev_framework/woodev/shipping-method/class-shipping-plugin.php:156), [class-shipping-plugin.php:754](D:/Projects/woodev_framework/woodev/shipping-method/class-shipping-plugin.php:754) | **The keystone does not wire the assembled module.** `add_hooks()` registers only shipping methods, integration, and system status. `get_checkout_handler()`, `get_ajax_handler()`, and `get_shipping_admin()` are never called anywhere. Therefore checkout injection/process/enqueue/render, AJAX `register()`, admin handlers/pages, webhook registration, shipment handlers, and tracking handlers remain inert. |
| **High** | [class-shipping-ajax.php:176](D:/Projects/woodev_framework/woodev/shipping-method/ajax/class-shipping-ajax.php:176), [class-checkout-handler.php:188](D:/Projects/woodev_framework/woodev/shipping-method/checkout/class-checkout-handler.php:188), [class-shipping-order-handler.php:34](D:/Projects/woodev_framework/woodev/shipping-method/order/class-shipping-order-handler.php:34) | **There is no `Pickup_Selection` → `Shipping_Order_Handler` handoff.** AJAX stores the point in session, but checkout persistence writes only the hidden field value directly under the field ID. Nothing reads the session point and writes the plugin-supplied logical order-meta fields. Shipment/admin code therefore cannot reliably receive the chosen pickup-point data. |
| **High** | [pickup-map.js:199](D:/Projects/woodev_framework/woodev/shipping-method/assets/js/frontend/pickup-map.js:199), [pickup-map.js:232](D:/Projects/woodev_framework/woodev/shipping-method/assets/js/frontend/pickup-map.js:232), [class-shipping-ajax.php:187](D:/Projects/woodev_framework/woodev/shipping-method/ajax/class-shipping-ajax.php:187) | **WordPress JSON errors are treated as successful selections.** `wp_send_json_error()` normally returns HTTP 200, so `$.post()` resolves. `handleSelect()` then updates the hidden field and closes the modal even when `Shipping_AJAX::handle_set()` rejected the request and stored nothing in session. |
| **Medium** | [pickup-map.js:225](D:/Projects/woodev_framework/woodev/shipping-method/assets/js/frontend/pickup-map.js:225), [class-shipping-ajax.php:234](D:/Projects/woodev_framework/woodev/shipping-method/ajax/class-shipping-ajax.php:234), [class-pickup-selection.php:65](D:/Projects/woodev_framework/woodev/shipping-method/pickup/class-pickup-selection.php:65) | **The default selection round-trip loses the complete pickup point.** Search returns rich `Pickup_Point::to_array()` records, but selection posts only `point_id` plus nonexistent `point.meta`; the base AJAX handler rebuilds a code-only point. Session prefill after reload loses name, address, coordinates, hours, and raw carrier data. |
| **High** | [class-warehouse.php:32](D:/Projects/woodev_framework/woodev/shipping-method/pickup/class-warehouse.php:32), [abstract-warehouses-controller.php:397](D:/Projects/woodev_framework/woodev/shipping-method/rest-api/abstract-warehouses-controller.php:397), [class-abstract-warehouse-store.php:171](D:/Projects/woodev_framework/woodev/shipping-method/pickup/class-abstract-warehouse-store.php:171) | **Warehouse REST conflates carrier ID with storage row ID.** `Warehouse::$id` is carrier-unique, while REST routes treat `id` as the database row ID and overwrite the model ID with it during updates. Depending on the concrete store mapping, updates can target the wrong row, insert duplicates, or replace the carrier identifier. |
| **Medium** | [class-shipping-rest-api.php:65](D:/Projects/woodev_framework/woodev/shipping-method/rest-api/class-shipping-rest-api.php:65), [class-shipping-rest-api.php:81](D:/Projects/woodev_framework/woodev/shipping-method/rest-api/class-shipping-rest-api.php:81), [class-shipping-plugin.php:81](D:/Projects/woodev_framework/woodev/shipping-method/class-shipping-plugin.php:81) | **REST controllers are unreachable by default and the namespace is derived.** `Shipping_Plugin` always constructs the base `Shipping_REST_API`; its controller list is always empty. Additionally, the REST namespace is derived from the plugin ID instead of being supplied explicitly. |

### Flow Verdicts

1. **Checkout pickup:** Not sound. The `id`/`code`, action-map, nonce, and `point_id` names align, but wiring is absent, failed AJAX selections are accepted by JS, and the stored point loses its full shape. Selecting the shipping-method radio also only shows a button; it does not directly open the modal.
2. **Search:** The successful-response shape is consistent: `data.points` → `normalizeResponse()` → `Pickup_Point::to_array()` includes the required `id` alias. AJAX failures silently become an empty point list.
3. **Order lifecycle:** Not sound end-to-end because session selection never reaches the order handler. Once manually injected, shipment/admin meta access is consistent. The retry payload is correct: `['data' => [$order_id]]`.
4. **Wiring:** Not sound. The composition root neither loads nor registers most S1 subsystems.
5. **Admin/REST:** Admin classes correctly use injected handlers internally, but are never assembled. Pickup REST correctly delegates to the supplied source. Warehouse REST has the storage-ID/carrier-ID break above.
---
_GPT-5.5 (codex) holistic integration review, 2026-06-07. Independent pass over the assembled S1 module after per-task review. Headline finding (incomplete wiring) verified by hand: `class-shipping-plugin.php` includes() omits the subsystem files and add_hooks() never calls get_checkout_handler()/get_ajax_handler()/get_shipping_admin()._

---

## Remediation status (2026-06-08, operator: fix-via-loop)

| Finding | Severity | Status |
|---|---|---|
| Subsystems never loaded; keystone doesn't wire the module | High | ✅ FIXED `93a5be5` (R1): includes() loads all committed subsystem files; lifecycle registers ajax/checkout/admin/webhook via the null-guarded accessors |
| No Pickup_Selection -> Shipping_Order_Handler handoff | High | ✅ FIXED `93a5be5` (R1): pickup order's chosen point persisted to order meta via the plugin-supplied key map |
| JS treats wp_send_json_error (HTTP 200) as success | High | ✅ FIXED `07fa015` (R2): JS branches on response.success for both set-point and search |
| Warehouse REST carrier-id vs storage-row-id conflation | High | ⏸ DEFERRED to the React rework (rest-warehouses; not committed) |
| Default selection loses full point shape | Medium | ◻ By-design (minimal base; carriers override build_point_from_request); optional future enhancement |
| REST controllers unreachable / namespace "derived" | Medium | ◻ Accepted: get_namespace() = $plugin->get_id_dasherized() is the operator-BLESSED pattern (GUARDS.md); base controller list empty by design (host registers its controllers) |

R1's wiring fix iterated under the adversarial critic across 4 rounds — each caught a distinct REAL
bug that composer-green + per-task review had missed (PHP-7.4 `?->`, data-loss field-unset, pre-save
hook timing, unscoped pickup-field validation); 5th re-critic CLEAN. This is the holistic-review +
re-critic-own-fixes discipline working as intended.
