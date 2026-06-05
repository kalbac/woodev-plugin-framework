# INVARIANTS — never-break installed-site data contracts (machine-checkable)

> Source of these patterns (do NOT invent — §5 of the implementation brief):
> - `CLAUDE.md` → "Backward Compatibility — clean-break policy" → the *Installed-site
>   data contracts* enumeration (the canonical never-break list).
> - `docs-internal/migration/edostavka-data-preservation-checklist.md` → the real exact
>   strings (option keys, method id, cron hooks, order-meta prefix, log source, REST ns…).
>
> Two tiers:
> 1. **contract_zones** — touching one means the change is in an *irreversible contract
>    zone*. The conductor requires a **blessed, mutation-verified guard** (see
>    `.autodev/GUARDS.md`) before it may auto-commit; otherwise it escalates.
> 2. **constitution** — touching one is **always a human decision**. The conductor never
>    auto-commits a diff that changes a constitution path, guard or no guard.
>
> `auto_guardable: false` zones (cron-payload shape, DB schema) cannot be proven by a
> mechanical mutation-recipe — they are human-only even if a test exists; never record
> them as "guarded" in GUARDS.md.

The block between the two markers below is the **single machine-readable source** parsed
by `tools/autodev/gate.ps1` and passed to `tools/autodev/invoke-critic.ps1`. Keep it
valid JSON. The prose around it is for humans; the JSON is authoritative for the gate.

<!-- BEGIN MACHINE-INVARIANTS -->
```json
{
  "version": 1,
  "updated": "2026-06-04",
  "contract_zones": [
    {
      "id": "option_keys",
      "why": "WP option keys / settings arrays must stay byte-for-byte (CLAUDE.md clean-break list).",
      "auto_guardable": true,
      "path_globs": [],
      "grep_patterns": ["get_option\\s*\\(", "update_option\\s*\\(", "add_option\\s*\\(", "delete_option\\s*\\(", "register_setting\\s*\\("],
      "exact_strings": ["woocommerce_edostavka_settings", "woocommerce_edostavka-integration_settings", "wc_edostavka_webhook_ids", "wc_edostavka_shipping_fee_payments", "wc_edostavka_upgraded_to_2_2_2_0", "woocommerce_yandex_delivery_settings"]
    },
    {
      "id": "license_and_updater",
      "why": "License key option names, activation state, instance IDs, and updater identity (EDD download id) are release-blocking.",
      "auto_guardable": true,
      "path_globs": ["woodev/licensing/**", "woodev/class-license*.php", "woodev/plugin-updater/**"],
      "grep_patterns": ["license_key", "instance_id", "activation", "download_id", "get_download_id"],
      "exact_strings": ["cdek_woocommerce_shipping_method_license_key", "216", "821"]
    },
    {
      "id": "shipping_method_id",
      "why": "WC shipping-method IDs + per-instance setting keys; merchants' shipping-zone rows key on method_id.",
      "auto_guardable": true,
      "path_globs": ["woodev/shipping-method/**"],
      "grep_patterns": ["\\$this->id\\s*=", "const\\s+METHOD_ID", "get_method_id\\s*\\(", "method_id", "woocommerce_shipping_methods"],
      "exact_strings": ["edostavka", "yandex_delivery_express", "yandex_delivery_other_day"]
    },
    {
      "id": "gateway_id",
      "why": "WC payment-gateway IDs are an installed-site contract. Real gateway ids are assigned inside woodev/payment-gateway/** (covered by path_globs); a generic \\$this->id= grep was removed because it false-tripped on every value object with an id field (e.g. shipping Warehouse) that lives outside the gateway path.",
      "auto_guardable": true,
      "path_globs": ["woodev/payment-gateway/**"],
      "grep_patterns": ["get_method_title\\s*\\(", "payment_gateways\\s*\\("],
      "exact_strings": []
    },
    {
      "id": "hooks",
      "why": "Public action/filter hook names are a contract other code subscribes to.",
      "auto_guardable": true,
      "path_globs": [],
      "grep_patterns": ["do_action\\s*\\(", "apply_filters\\s*\\("],
      "exact_strings": []
    },
    {
      "id": "cron",
      "why": "Scheduled cron hook names + recurrence + PAYLOAD SHAPE. Payload shape is NOT mechanically mutatable -> human-only.",
      "auto_guardable": false,
      "path_globs": ["woodev/class-lifecycle.php"],
      "grep_patterns": ["wp_schedule_event", "wp_schedule_single_event", "wp_next_scheduled", "wp_clear_scheduled_hook", "_cron"],
      "exact_strings": ["wc_edostavka_orders_update", "wc_edostavka_orders", "wc_yandex_update_order", "wc_yandex_orders_update"]
    },
    {
      "id": "rest",
      "why": "REST route namespaces are part of public URLs.",
      "auto_guardable": true,
      "path_globs": ["woodev/rest-api/**"],
      "grep_patterns": ["register_rest_route\\s*\\("],
      "exact_strings": ["wc/v3", "yandex-delivery"]
    },
    {
      "id": "ajax_actions",
      "why": "AJAX action names are wired to front-end JS; renaming breaks live checkout.",
      "auto_guardable": true,
      "path_globs": [],
      "grep_patterns": ["wp_ajax_", "wp_ajax_nopriv_"],
      "exact_strings": ["edostavka_get_deliverypoints", "edostavka_set_customer_location", "edostavka_set_delivery_point", "edostavka_order_action", "get_yandex_delivery_location_detect", "get_yandex_delivery_shipment_points", "set_yandex_delivery_pickup_point", "set_yandex_delivery_time_interval"]
    },
    {
      "id": "admin_page_slugs",
      "why": "Admin page slugs are bookmarked URLs and menu wiring.",
      "auto_guardable": true,
      "path_globs": [],
      "grep_patterns": ["add_menu_page\\s*\\(", "add_submenu_page\\s*\\(", "page=wc_"],
      "exact_strings": ["wc_edostavka_orders", "wc-yandex-orders"]
    },
    {
      "id": "log_source",
      "why": "Log source names are the handle merchants/support filter logs by.",
      "auto_guardable": true,
      "path_globs": [],
      "grep_patterns": ["'source'\\s*=>", "wc_get_logger", "->log\\s*\\("],
      "exact_strings": ["edostavka_orders", "yandex-delivery"]
    },
    {
      "id": "order_session_meta",
      "why": "Order/session meta keys and WC data stores hold live order data; renaming orphans it.",
      "auto_guardable": true,
      "path_globs": [],
      "grep_patterns": ["update_post_meta\\s*\\(", "get_post_meta\\s*\\(", "->update_meta_data\\s*\\(", "->get_meta\\s*\\("],
      "exact_strings": ["_wc_edostavka_", "edostavka_rate", "customer-location", "customer-location-session", "woocommerce_api_wc_edostavka_", "_yandex_delivery_", "chosen_yandex_pickup_point", "chosen_yandex_pickup_point_test", "chosen_yandex_time_interval"]
    },
    {
      "id": "db_schema",
      "why": "Custom DB tables/schemas. Schema diffs are NOT mechanically mutatable -> human-only.",
      "auto_guardable": false,
      "path_globs": [],
      "grep_patterns": ["CREATE TABLE", "dbDelta\\s*\\(", "\\$wpdb->prefix"],
      "exact_strings": ["wc_yandex_delivery_warehouses"]
    },
    {
      "id": "background_jobs",
      "why": "Background-job IDs are persisted in the queue across requests.",
      "auto_guardable": true,
      "path_globs": ["woodev/utilities/**"],
      "grep_patterns": ["get_async_request_action", "background_job", "->dispatch\\s*\\("],
      "exact_strings": []
    }
  ],
  "constitution": {
    "why": "Touching any of these is ALWAYS a human decision; the conductor never auto-commits such a diff.",
    "path_globs": [
      "PLANS.md",
      "CLAUDE.md",
      "AGENTS.md",
      "docs-internal/platform-v2-program-tracker.md",
      "docs-internal/platform-v2-execution-protocol.md",
      "docs-internal/migration/*data-preservation*",
      "**/*-policy.md",
      ".autodev/INVARIANTS.md",
      ".autodev/GOAL.md",
      ".autodev/GUARDS.md"
    ]
  }
}
```
<!-- END MACHINE-INVARIANTS -->

## Why the exact strings (provenance)

| Contract | Exact value | Source line |
|---|---|---|
| Shipping method ID | `edostavka` | checklist §Installed-Site Identity / §WooCommerce Method Contracts |
| Primary settings option | `woocommerce_edostavka_settings` | checklist §Options And Settings |
| Legacy settings option | `woocommerce_edostavka-integration_settings` | checklist §Legacy Migration Maps |
| License key option (legacy) | `cdek_woocommerce_shipping_method_license_key` | checklist §Licensing And Updater State |
| EDD download ID | `216` | checklist §Installed-Site Identity |
| Webhook IDs option | `wc_edostavka_webhook_ids` | checklist §Options And Settings |
| Migration flag | `wc_edostavka_upgraded_to_2_2_2_0` | checklist §Options And Settings |
| Cron hook / schedule | `wc_edostavka_orders_update` / `wc_edostavka_orders` | checklist §Scheduled Work |
| Order meta prefix | `_wc_edostavka_` | checklist §Stored Data Schemas |
| Shipping-item meta | `edostavka_rate` | checklist §Stored Data Schemas |
| Data stores | `customer-location`, `customer-location-session` | checklist §Checkout And Frontend State |
| REST namespace | `wc/v3` | checklist §Web And Admin Surface |
| AJAX actions | `edostavka_*` family | checklist §Web And Admin Surface |
| Admin page slug | `wc_edostavka_orders` | checklist §Web And Admin Surface |
| Log source | `edostavka_orders` | checklist §Operational Surface |

### Yandex (S1 second pilot — added 2026-06-04)

| Contract | Exact value | Source line |
|---|---|---|
| Shipping method IDs | `yandex_delivery_express`, `yandex_delivery_other_day` | yandex checklist §Installed-Site Identity |
| Primary settings option | `woocommerce_yandex_delivery_settings` | yandex checklist §Options And Settings |
| EDD download ID | `821` | operator-supplied 2026-06-04 |
| Cron hooks | `wc_yandex_update_order` (single, payload `['order_id'=>int,'slim'=>bool]`), `wc_yandex_orders_update` (recurring) | yandex checklist §Scheduled Work (auto_guardable:false) |
| REST namespace | `yandex-delivery` | yandex checklist §Web And Admin Surface |
| Frontend AJAX | `get_yandex_delivery_location_detect`, `get_yandex_delivery_shipment_points`, `set_yandex_delivery_pickup_point`, `set_yandex_delivery_time_interval` | yandex checklist §Web And Admin Surface |
| Admin page slug | `wc-yandex-orders` | yandex checklist §Web And Admin Surface |
| Log source | `yandex-delivery` | yandex checklist §Operational Surface |
| Order/session meta | `_yandex_delivery_`, `chosen_yandex_pickup_point(_test)`, `chosen_yandex_time_interval` | yandex checklist §Stored Data / Checkout State |
| Warehouse table | `wc_yandex_delivery_warehouses` | yandex checklist §Stored Data Schemas (auto_guardable:false) |

## Related
- `.autodev/GUARDS.md` — which of these zones are mutation-verified-guarded (autonomous).
- `docs-internal/migration/edostavka-data-preservation-checklist.md` — the authoritative checklist.
- `docs-internal/migration/yandex-data-preservation-checklist.md` — S1 second-pilot contracts.
- `CLAUDE.md` → "Backward Compatibility — clean-break policy" — the never-break enumeration.
