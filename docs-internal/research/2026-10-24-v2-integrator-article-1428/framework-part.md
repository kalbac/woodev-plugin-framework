# Framework-only part of #1428 (for the public framework docs, English)

Operator decision (card comment 24.10.2026): framework-only matters → `website/` (English); plugin matters → woodev.ru (Russian drafts `draft-*.ru.md`).
Checked against `website/src/content/docs/reference/hooks-and-filters.md` (329 lines), `core/migrating-from-v1.md`, `shipping/orders-export-documents-and-tracking.mdx`, `shipping/methods-and-rates.md`. I did not edit `website/`.

## 1. Framework hooks that replaced plugin hooks — already documented

Every replacement used by the plugins' declared maps (CDEK `Plugin::DEPRECATED_HOOK_REPLACEMENTS`, v2.3.0.7 includes/class-plugin.php:1229-1270) exists as a row in hooks-and-filters.md:

| Framework hook | Row in hooks-and-filters.md |
|---|---|
| `woodev_shipping_method_calculated_rate` | line 147 |
| `woodev_shipping_method_after_calculate_shipping` | line 150 |
| `woodev_shipping_rate_additional_info` | line 160 |
| `woodev_shipping_order_export_requested`, `woodev_shipping_order_exported` | lines 250, 252 |
| `woodev_shipping_delivery_status_changed`, `woodev_shipping_delivery_status_resolved` | lines 265-266 |
| `woodev_customer_location_saved` | line 184 |
| `woodev_shipping_plugin_settings_{id}_form_fields` | line 94 |
| `woodev_{api_id}_http_request_args` (Yandex's `woodev_yandex_delivery_http_request_args`) | line 106 |

Nothing to add there. The canonical status set (`pending, created, in_transit, ready_for_pickup, delivered, returning, returned, failed, cancelled, unknown`, framework woodev/shipping-method/order/class-delivery-status.php:40-75) is documented in the orders page (status_map example) — not re-verified line by line.

## 2. Gaps (framework-only)

A. `core/migrating-from-v1.md` ("Data on installed sites must survive", line ~35) says a shipping method ID must never change, because a new ID empties every zone. The three carrier plugins did change ids
(CDEK 1→3, Yandex 1→2 + one dropped, Russian Post 2→4) by rewriting zone rows with the same instance id. The docs give no recipe for that, and no word on what to do with old orders.
Proposed addition (new subsection after the table, before "The version option and a live site"):

> ### When a method ID has to change
>
> Avoid it. If a method must be split or renamed, do all of this in one idempotent, journalled migration:
>
> - Rewrite the `method_id` column of the shipping zone rows and keep the `instance_id`, so zones and instance numbers survive. Copy the instance settings to the option name of the new ID (`woocommerce_{method_id}_{instance_id}_settings`); leave the old option for a rollback.
> - Rewrite the gateway settings that store method IDs, such as `enable_for_methods` of cash on delivery: a bare old ID becomes every new ID, `old:12` becomes `new:12`. Never leave the list empty, because an empty list means "every method".
> - Decide what happens to old orders. Their shipping lines still hold the old ID. Either leave them and declare the old ID in the `method_ids` of your `Orders_Provider`, so the orders page still recognises them; or rewrite the lines in a background job and keep the original ID in a hidden line meta for a rollback. Say which one you did in your release notes.
> - WooCommerce builds `woocommerce_shipping_{method_id}_is_available` from the ID, so code hooked to the old ID stops matching. List it in `get_deprecated_hooks()` or the release notes.

Evidence for the recipe: CDEK v2:includes/lifecycle/class-cdek-v1-migration.php (`split_instances`, `rewrite_gateway_methods`), Yandex v2:includes/lifecycle/class-yandex-v1-planner.php:181-209, Russian Post v2:includes/order/class-russian-post-order-migration-writer.php:108-127.

B. `shipping/orders-export-documents-and-tracking.mdx` line 152, row `method_ids`: "The WooCommerce shipping method ids of this carrier, courier and pickup together." Does not say that historical ids may (and, for plugins that left old orders alone, must) be listed.
Proposed replacement text: "The WooCommerce shipping method ids of this carrier, courier and pickup together. Also list ids that only old orders carry, such as an id from a previous version of your plugin, or the orders page will not recognise those orders." Evidence: Yandex v2:includes/orders/class-yandex-order-actions.php:56 and its comment; CDEK `SHIPPING_METHOD_IDS` (class-plugin.php:72). Registry check "registered ⊆ declared" per Yandex gotcha `v1-order-lines-keep-the-other-day-method-id.md` (not re-verified in framework code).

C. `get_deprecated_hooks()` is documented (migrating-from-v1.md:141-236) with `map`/`replacement`. One practical sentence is missing: when the arguments of old and new hook differ, use `'map' => false` and keep the replacement only as a pointer (CDEK does exactly this, class-plugin.php:1285-1290: `'removed' => true, 'replacement' => $replacement ?? false, 'map' => false`). Proposed addition after the `map` row: "Use `'map' => false` when the new hook's arguments are not the old hook's: the notice then names the replacement, but old callbacks are not called with wrong arguments."
Also: two of the three plugins (Yandex v2.0.8, Russian Post v2.0.7) declare **no** map, so the merchants' developers get no notice. That is a plugin matter (not for `website/`).

D. Nothing framework-level about order meta keys, cron names or REST/AJAX: those are plugin matters, covered in the drafts.

## 3. Not framework docs (plugin docs on woodev.ru)
method ids per plugin, which hooks each plugin dropped, `woodev_edostavka_*` forwarders, Russian Post's order-line rewrite, Yandex's dropped `express` method.
