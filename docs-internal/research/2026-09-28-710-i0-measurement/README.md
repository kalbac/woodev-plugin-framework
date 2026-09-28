# #962 — I0: what #710's order wizard needs to know before I1a / I1b / I2a

> Session 142 (28.09.2026), macOS laptop. **Measurement only** — no framework source was changed. Produced by a
> Sonnet 5 worker for card #962 against the spec
> [`2026-09-27-710-create-edit-order-design.md`](../../specs/2026-09-27-710-create-edit-order-design.md)
> (Increments row I0; sections D2, D4). Operator decisions O1–O14 are not reopened. Rig: WP 7.1, WooCommerce
> **11.1.0**, PHP 8.1.34, **HPOS on** (CPT was not measured), `main` `cc713eb` served from the primary checkout.
> Instrumentation was two throwaway mu-plugins written into the rig's `wp-content/mu-plugins/` (a hook logger and a
> zone/rate probe) and **removed afterwards**; the orders and carts this run created (ids 339–346 and a few
> temporary ones) were cancelled and deleted (product 12's `total_sales` went back to its start value, 15).
> Condensed logs are in [`logs/`](logs/); line numbers are as of `main` `cc713eb`.

## The three answers

1. **Marker writers.** Every real writer is **checkout-time** code in a v1 plugin, and each one writes a
   *different kind of value* at a *different hook* (table 1). Nothing in `woodev/`, `src/` or any fixture writes a
   marker at checkout — the two fixture carriers write `'1'` only from their **seeders**, so a real checkout order for a
   fixture carrier carries **no marker and would not appear on the orders page**. The marker's value is not
   ignorable: presence (`EXISTS`, used by the list query) and value (`'' !== (string) $value`, used by
   `resolve_provider_for_order()`) **disagree** for `''`/`false`, and an array value raises a PHP warning.
2. **Checkout hooks.** Classic checkout fires the full `woocommerce_checkout_*` sequence and all the framework's
   `woodev_shipping_{plugin_id}_checkout_*` hooks — **once per active carrier plugin, for every order**, whatever
   method was chosen. **Block checkout (Store API) fires none of it**: no `woocommerce_checkout_process`,
   `_posted_data`, `_create_order`, `_update_order_meta`, `_order_created`, `_order_processed`, and no `woodev_*` hook;
   the order ends with no framework meta at all (tables 2 and 3).
3. **Zone/method resolution.** `WC_Shipping_Zones::get_zone_matching_package()` → `get_shipping_methods( true )`,
   filtered by `method_ids`, works in an admin REST request with `WC()->session`, `WC()->cart` and `WC()->customer` all
   **`null`** (table 4). Rates do not: `should_send_cart_api_request()` is `false` (REST arm) and
   `get_rates_for_package()` returns `[]`; `WC()->shipping()->calculate_shipping_for_package()` **fatals** on the null
   session. Only a per-method call that bypasses the veto works (table 5).

## What contradicts or corrects the spec

| # | Spec says | Measured |
|---|---|---|
| 1 | **C4 / D4 create step:** the admin «New order» email is triggered explicitly once on create, because «WooCommerce does not send it for admin-created orders». | **Wrong for the `wc_create_order()` path.** `wc_create_order()` → `set_status( 'processing' )` → `save()` (and the same with `'on-hold'`) fires `woocommerce_order_status_pending_to_{processing,on-hold}_notification`, whose callbacks are `WC_Email_New_Order::trigger` **and** the customer email. An explicit extra trigger **double-sends**. `'pending'` fires none. Only statuses that do not run through a `pending_to_*` notification need the explicit trigger (log: [`admin-wc-create-order-then-processing.txt`](logs/admin-wc-create-order-then-processing.txt)). |
| 2 | **D2:** «call `calculate_shipping()` directly — or `WC()->shipping()->calculate_shipping_for_package()` and filter rates by `method_ids`». | Both are dead ends in an admin REST request. `calculate_shipping()` is `final` and returns at the veto; `calculate_shipping_for_package()` throws `Error: Call to a member function get() on null` because WC 11.1.0 calls `WC()->session->get()` unguarded (`class-wc-shipping.php:325`). Working path: zone → per-method, plus a **seam inside `Shipping_Method`** (table 5). |
| 3 | **Mine 1:** the veto is «`REST_REQUEST` … OR in admin after the cart loaded — either alone kills it», fix by «a context flag set by the calculator». | Both arms confirmed independently (table 4: admin REST hits the `REST_REQUEST` arm; admin-ajax hits the `is_admin() && did_action(…)` arm). But `should_send_cart_api_request()` is **`private`** (`class-shipping-method.php:840`), the caller `calculate_shipping()` is **`final`** (`:374`), and the `woodev_shipping_method_before_calculate_shipping` action fires at `:384`, **before** the veto call at `:386` — a listener cannot rescue it. The flag has to be a static/instance seam **in `Shipping_Method` itself**. |
| 4 | **D4 / table row «Framework order metas»:** written on `woocommerce_checkout_order_processed` by three handlers; «all THREE hooks — same order, same payloads». | True, but incomplete: the hooks are **per plugin id** (`woodev_shipping_woodev_realistic_shipping_…` vs `woodev_shipping_woodev-test-shipping-method_…` — underscores vs hyphens) and each active plugin's handlers run for **every** order, including free-shipping orders and another carrier's orders (table 2). The equivalence test must be per plugin, and must run with **two** plugins active. |
| 5 | **Reuse map:** the framework's order data is written only inside `woocommerce_checkout_order_processed` (classic). | Measured consequence not stated in the spec: on the **Store API** path nothing runs, so once #949 makes framework rates appear in the block checkout, a carrier order placed there gets **no framework meta and no marker** and is invisible on the page. Not this card's scope — flagged for the coordinator (open risks). |
| 6 | **D4:** fixtures' checkout should get «real checkout-parity writers, not the seeders' `"1"`». | Confirmed there is none. Extra: the provider-declared `pickup_point_meta_key` (`_woodev_test_shipping_pickup_point`, `_woodev_realistic_pickup_point`) is **never written** by a real checkout — only the un-prefixed field meta (`carrier_pickup_point`, `realistic_pickup_point`) holding the point **id**; `Pickup_Handler`'s full-point persistence is not wired in either fixture. |

## 1. Carrier-marker writers

Searched: `plugins-reference/` (all five entries), `tests/_fixtures/` (all eight), `tests/`, `woodev/`, `src/`.

| Writer | Key → value | Hook / moment | Notes |
|---|---|---|---|
| **v1 `woocommerce-edostavka`** `includes/class-wc-edostavka-checkout.php:928-930` | `_wc_edostavka_shipping` → **array**, one entry per shipping package: `[ 'tariff_data' => <array from WC_Edostavka_Tariffs_Data::get_tariff_by_code( $code )>, 'method_instance' => <WC_Shipping_Method OBJECT> ]` | `woocommerce_checkout_create_order`, prio 10 (`:28`, `:920`) — the order is **not yet saved**; runs only if `$data['edostavka_shipping']` is set. That key is injected by the `woocommerce_checkout_posted_data` filter (`:29`, `:675-678`) from each `shipping_method[]` entry `method:instance:code` whose method id is the plugin's | The value is **data derived from the posted rate**, not a flag. Same block also writes `_wc_edostavka_status` = `'NEW'` (`:931`) and, for stock/postamat tariffs, `_wc_edostavka_chosen_delivery_point` (`:944`). |
| **v1 `woocommerce-yandex-delivery`** `includes/class-checkout.php:671-697` | `_yandex_delivery_state_status` → `'NEW'` (the raw-status meta **is** the marker; v1's list scope is `includes/admin/class-order-list-table.php:353`) | `woocommerce_checkout_update_order_meta`, prio 10 (`:20`) — the order **is saved** (`$order->save()` inside); runs only if `$data['yandex_delivery']` is non-empty, built by the `woocommerce_checkout_posted_data` filter (`:18`, `:562-585`) from `shipping_method` + the request values `yandex_pickup_point*` | Also writes `_yandex_delivery_destination_station_id/_address` (pickup) and `_yandex_delivery_destination_interval_from/_to`. `_yandex_delivery_request_id` is written at export (`class-ajax.php:350`). |
| **v1 `woodev-russian-post`** `includes/classes/checkout.php:580` | `_wc_russian_post_is_russian_post` → `true` (stored `'1'`) | `woocommerce_checkout_create_order`, prio 10 (`:23`, `:567`); loops the order's **shipping items** whose method id starts with the plugin's id — value does **not** depend on posted data | v1 list scope: `includes/admin/order-list-table.php:437`. Status is a separate meta, `_wc_russian_post_status`. |
| fixture `woodev-test-shipping-method` | `_woodev_test_shipping_marker` → `'1'` | **Seeder only**: `class-test-orders-seeder.php:474` inside `seed_one()` (`wc_create_order()` + `update_meta_data()` + `save()`, no checkout hook) | Provider registered `woodev-test-shipping-method.php:852-855`. |
| fixture `woodev-realistic-shipping-plugin` | `_woodev_realistic_shipping_marker` → `'1'` | **Seeder only**: `includes/class-realistic-orders-seeder.php:378` | Provider registered `includes/class-realistic-shipping-plugin.php:68-71`. |
| `tests/integration` (`SecondCarrierOrdersProviderTest`, `OrdersRestTest`) | provider key → `'1'` | written directly by the test | Not a real writer. |
| fixtures `woodev-yandex-pilot-plugin`, `woodev-edostavka-pilot-plugin`, `woodev-realistic-payment-plugin`, `woodev-test-payment-gateway`, `woodev-test-plugin`, `woodev-entry-path-fixture`, `dadata`; `plugins-reference/woodev-vkredit`, `pochta-widget`; `woodev/`, `src/` | — | no marker writer, no `Orders_Provider` | `grep` for the three v1 keys and `marker_meta_key` |

**Measured on the rig:** a real classic checkout with the realistic pickup rate (order 340) and with the test rate
(order 341) ended with **no `_woodev_*_marker` meta** — only WooCommerce's own metas plus the field meta
(`realistic_pickup_point` / `carrier_pickup_point`) and `_woodev_popular_settlement_candidate`.

### The marker's value is load-bearing

[`logs/marker-value-probe.txt`](logs/marker-value-probe.txt) — one order per value, re-read after a cache flush:

| value written | `meta_exists()` (list query, `report_multiple_markers`) | `resolve_provider_for_order()` (REST row owner, metabox) |
|---|---|---|
| `'1'`, `true`, `'0'` | present | provider found |
| `''`, `false` | present | **`NULL`** — the list query shows the order, the row/metabox cannot name its carrier |
| array (v1 edostavka shape) | present | provider found, **PHP warning «Array to string conversion»** on every call |

`resolve_provider_for_order()` is called from `class-orders-controller.php:727`,
`class-shipping-admin-order.php:192` and `:463`. So `mark_order()` (I1b) must write a **non-empty scalar** — `'1'` is
the safe value — and the spec's «value and moment are the carrier's» is right only within that contract. Orders placed
by the real v1 edostavka plugin already carry the array shape; that is a migration question, not an I0 one.

## 2. Classic checkout — hook order (WC 11.1.0)

Logs: [`classic-checkout-free-shipping.txt`](logs/classic-checkout-free-shipping.txt),
[`classic-checkout-realistic-pickup.txt`](logs/classic-checkout-realistic-pickup.txt),
[`classic-checkout-test-shipping.txt`](logs/classic-checkout-test-shipping.txt). Driven with `curl` against
`/?wc-ajax=checkout` (cart cookie + the page's nonce), payment COD, RU / `МОСКВА` / Москва. Two carrier plugins were active
(`woodev-realistic-shipping-plugin`, `woodev-test-shipping-method`), so **every framework hook below appears twice**,
once per plugin.

| # | Hook (payload) | Framework callbacks on it |
|---|---|---|
| 1 | `wc_ajax_checkout` → `woocommerce_checkout_init` (`WC_Checkout`) | — |
| 2 | `woocommerce_checkout_process` () | `Pickup_Handler::handle_checkout_process`, `Checkout_Handler::handle_checkout_process` (per plugin; this is where «You have not chosen a pickup point.» is raised, via `wc_add_notice`) |
| 3 | `woocommerce_checkout_posted_data` (filter: `$data`) | none in `woodev/`; **v1 edostavka/Yandex inject their own keys here** |
| 4 | `woocommerce_after_checkout_validation` (`$data`, `$errors`) | none |
| 5 | `woocommerce_checkout_update_user_meta`, `woocommerce_create_order` (filter) | none |
| 6 | `woocommerce_checkout_create_order_line_item_object` / `_line_item` (`$item`, `$cart_key`, `$values`, `$order`) | none |
| 7 | `woocommerce_checkout_create_order_shipping_item` (`$item`, `$package_key`, `$package`, `$order`) — WC copies the rate's meta here | none. Framework fixture rates carry **no** rate meta (`meta_keys: []`); `free_shipping` carries `Items`. |
| 8 | `woocommerce_checkout_create_order` (`$order` **unsaved, id 0**, `$data`) — v1 edostavka / Russian Post marker writers | none |
| 9 | order saved → `woocommerce_new_order` (`$id`, `$order`, status `pending`) | none |
| 10 | `woocommerce_checkout_update_order_meta` (`$id`, `$data`) — v1 Yandex marker writer | none |
| 11 | `woocommerce_checkout_order_created` (`$order`) | none (`wc_reserve_stock_for_order` is WC's) |
| 12 | **`woocommerce_checkout_order_processed`** (`$id`, `$data`, `$order`; order still **`pending`**) | prio 10: `Pickup_Handler::handle_checkout_order_processed` and `Checkout_Handler::handle_checkout_order_processed`, **each registered twice** (one per plugin; the log shows the order P, C, C, P); prio 20: `Location_Provider_Registry::handle_checkout_order_processed_for_popular_settlements` |
| 13 | inside the prio-10 `Checkout_Handler`, per plugin, in this order: `woodev_shipping_{plugin_id}_checkout_field_saved` (`$order`, `$field_id`, `$value`) — **only for a meta-persisted field with a value** (the pickup point), once per such field → `…_checkout_data_saved` (`$order`, `$values`) → `…_checkout_processed` (`$order`, `$values`) | — |
| 14 | prio 20 saves `_woodev_popular_settlement_candidate` (one more order save) | — |
| 15 | `woocommerce_payment_complete_order_status` → `woocommerce_order_status_processing` → `…_pending_to_processing` (+ `_notification`: emails) → `woocommerce_order_status_changed` | — (status changes **after** every framework write) |

Payloads of the framework hooks (order 341, test plugin). For the same order the realistic plugin fired `data_saved` and
`processed` with `[]` and no `field_saved`; on order 340 it was the other way round — `field_saved( WC_Order,
'realistic_pickup_point', 'REAL-MSK-1' )`, then `data_saved`/`processed` with `[ realistic_pickup_point:'REAL-MSK-1' ]`:

```text
checkout_field_saved ( WC_Order, 'carrier_pickup_point', '019373a0ecd97151922149c5094feaf6' )
checkout_data_saved  ( WC_Order, [ billing_company:'', billing_address_2:'', carrier_pickup_point:'0193…',
                                   billing_state:'МОСКВА', shipping_state:'', billing_city:'Москва',
                                   shipping_city:'', billing_address_1:'ул Тверская 1', shipping_address_1:'' ] )
checkout_processed   ( WC_Order, <the same array> )
```

`data_saved` and `processed` carry the **same** array; the address entries in it are not written as separate order
meta — the order's meta-key list grew only by the point field. The order object passed to the hooks is the live
object, so at `field_saved` time its meta list already contains the point key.

Facts an I1a equivalence test needs:

- **Fan-out.** With N carrier plugins active, each order fires N `data_saved` + N `processed` (and each plugin's own
  `field_saved` when it has a value) — including a `free_shipping` order (log 1) and another carrier's order
  (order 340 also fired `woodev-test-shipping-method_checkout_data_saved`, payload = the address subset above).
- **Prefix.** The hook prefix is the plugin id verbatim: `woodev_realistic_shipping` (underscores) but
  `woodev-test-shipping-method` (hyphens).
- **Order of writes.** All framework metas are written while the order is `pending`, **before** the status transition
  that triggers stock and e-mails. The admin writer should keep that order (write, then `calculate_totals()`, then
  status), which is also what the D4 create sequence already says.
- **Side effect on unrelated orders.** `_woodev_popular_settlement_candidate` was written even for the
  `free_shipping` order (spec: skip for admin orders — agreed, and it is the only writer that needs the skip).
- **`_debug_log_source*` metas** on every order are WooCommerce 11.1's own place-order debug — not ours, ignore.

## 3. Block checkout (Store API) — main as-is

Log: [`store-api-checkout.txt`](logs/store-api-checkout.txt). Flow: `GET /wc/store/v1/cart` →
`POST cart/add-item` → `POST cart/update-customer` (RU / `МОСКВА`) → `POST /wc/store/v1/checkout` with the `Nonce`
header.

**Limit from `main`:** `cart/update-customer` returned exactly one rate, `free_shipping:1`; the framework's rates
(`woodev_test_shipping:3`, `woodev_realistic_pickup_shipping:5`), which the classic page offered for the same address,
are absent (they are what `fix/949-store-api-rates` addresses — `origin/fix/949-store-api-rates` exists, not measured
here, per the brief). So the run measured a **free-shipping order through the block flow**, not a framework-carrier
order. The hook list below does not depend on the chosen rate, so it holds for a carrier order too; what cannot be
observed on `main` is the rate meta, pickup selection in blocks (the REST `select` writes the session; there is no
hidden field), and any carrier-specific behaviour.

| # | Hook (payload) | Note |
|---|---|---|
| 1 | `woocommerce_store_api_disable_nonce_check`, `woocommerce_checkout_init` | |
| 2 | `woocommerce_store_api_checkout_update_customer_from_request` (`$customer`, `$request`) | |
| 3 | `woocommerce_checkout_create_order_line_item(_object)` / `…_shipping_item` | order argument is unsaved (id 0) |
| 4 | order saved as **`checkout-draft`**, `created_via = store-api`; `woocommerce_new_order_item` | **no `woocommerce_new_order` yet** |
| 5 | `woocommerce_store_api_checkout_order_created` (`$order`) → `…_update_order_meta` (`$order`) → `…_update_order_from_request` (`$order`, `$request`) | order still a draft |
| 6 | `woocommerce_checkout_validate_order_before_payment` (`$order`, `$errors`) | |
| 7 | draft → pending: `woocommerce_new_order` (`$id`, `$order`), `woocommerce_order_status_checkout-draft_to_pending` | |
| 8 | **`woocommerce_store_api_checkout_order_processed`** (`$order`) → `woocommerce_rest_checkout_process_payment_with_context` | the block-checkout analogue of `…_checkout_order_processed`; **no `$posted_data` argument** |
| 9 | payment → `woocommerce_order_status_processing` → `…_pending_to_processing_notification` → `woocommerce_order_status_changed` | |

**Never fired in this request** (checked against the whole log): `woocommerce_checkout_process`,
`woocommerce_checkout_posted_data`, `woocommerce_after_checkout_validation`, `woocommerce_checkout_create_order`,
`woocommerce_checkout_update_order_meta`, `woocommerce_checkout_order_created`,
`woocommerce_checkout_order_processed`, and every `woodev_*` hook. The finished order (342) had only WooCommerce's
metas (`_shipping_hash`, `_coupons_hash`, `_fees_hash`, `_taxes_hash`, `_*_address_index`, `is_vat_exempt`) — not even
`_woodev_popular_settlement_candidate`. The v1 plugins' writers (`…_create_order`, `…_update_order_meta`) are dead on
this path too. `woodev/` contains no Store API integration (grep).

## 4. Zone/method resolution and `should_send_cart_api_request()` — three contexts

Probe: a throwaway REST route `i0/v1/probe` run as the logged-in `admin` (cookie + `wp_rest` nonce, which is the
transport contract the spec chose), the same function from `wp eval`, and an `admin-ajax.php` action. Package built the
way WC builds one: `contents` (real `WC_Product` objects, product 12 ×1 @ 1000 + product 173 ×2 @ 2490), `contents_cost`,
`applied_coupons: []`, `user.ID: 0`, `destination`. Raw output: [`probe-zone-resolution-admin-rest.json`](logs/probe-zone-resolution-admin-rest.json),
[`…-cli-frontend-context.json`](logs/probe-zone-resolution-cli-frontend-context.json),
[`probe-admin-ajax-context.json`](logs/probe-admin-ajax-context.json).

| context | `REST_REQUEST` | `is_admin()` | `WC()->session` / `cart` / `customer` | `did_action( cart_loaded_from_session )` | `should_send_cart_api_request()` | `get_rates_for_package()` |
|---|---|---|---|---|---|---|
| **admin REST** (`edit_shop_orders`, the wizard's transport) | true | **false** | **`null` / `null` / `null`** | 0 | **false** (REST arm) | `[]` for every framework method, every destination |
| admin-ajax | false | true | present ×3 | **1** (before any `wc_load_cart()`) | **false** (admin arm) | `[]` |
| WP-CLI (WC treats it as frontend) | false | false | present ×3 | 1 | **true** | one rate per method (`cost "0"`, no meta — fixtures add none) |

So each arm of the veto kills the call on its own, and the wizard's REST route sits on the `REST_REQUEST` arm only:
`is_admin()` is false in a REST request.

**Zones for admin-built packages** (admin REST, six destinations; providers: `test_shipping` → `[woodev_test_shipping]`,
`realistic` → `[woodev_realistic_shipping, woodev_realistic_pickup_shipping]`):

| destination | matched zone | enabled instances (`get_shipping_methods( true )`) | after the `method_ids` filter |
|---|---|---|---|
| RU / `МОСКВА` / Москва | 1 «Russia» | `free_shipping:1`, `woodev_test_shipping:3`, `woodev_realistic_pickup_shipping:5` | `test_shipping` → `:3`; `realistic` → `:5` |
| RU / `САНКТ-ПЕТЕРБУРГ`; RU / state empty (Казань); RU / state = label «Москва» | 1 | same three | same |
| KZ / Алматы; US / CA | 0 «not covered» | `woodev_test_shipping:2`, `woodev_realistic_shipping:6` | `test_shipping` → `:2`; `realistic` → `:6` (courier, no pickup method in this zone) |

What the wizard can offer therefore **depends on the destination's zone** — the instance ids differ per zone and a
zone can omit a provider's pickup method. `get_shipping_methods( true )` and the unfiltered call returned the same
count (all instances on the rig are enabled; a disabled instance was not tested).

**State codes.** The zone probe cannot show a state-code effect: the rig's zone is country-only. Measured instead: WC's
Russian states are **87 codes that are the upper-cased region names** (`МОСКВА`, `САНКТ-ПЕТЕРБУРГ`,
`КРАСНОДАРСКИЙ КРАЙ`), the label is title-case («Москва»); the classic checkout **rejected** `billing_state = MOW`
(«Billing Регион (Location Provider) is not valid. Please enter one of the following: …» + the labels) and accepted
`МОСКВА`. The `Location_Record` → code mapping is still to be written in I2a; a state-restricted zone was not tested.

### Which rate path works in an admin REST request

| Path | Result in admin REST |
|---|---|
| `Shipping_Method::calculate_shipping( $package )` | returns at the veto (`:386`) — no rate |
| `get_rates_for_package( $package )` (WC public: availability + `calculate_shipping()`) | `[]` — same veto |
| `WC()->shipping()->calculate_shipping_for_package( $package )` | **`Error: Call to a member function get() on null`** (6/6 destinations) — `class-wc-shipping.php:325`, WC 11.1.0 |
| `calculate_rate( $package )` (protected, reached by reflection, i.e. with the veto bypassed) | **works with a null session/cart** — a `Shipping_Rate` (`id`, `label`, `cost`, `meta_data`) for both fixture methods |

For I2a: skip WC's aggregate (`calculate_shipping_for_package`) — it needs a session, and its session cache
(Mine 2) then never comes into play. Resolve the zone-instance methods, filter by `method_ids`, and call a **new public
seam on `Shipping_Method`** that lifts the REST/admin veto for the duration of one call (or calls `calculate_rate()`
directly with the same guards `calculate_shipping()` applies: `is_available_for_package`, `before_calculate`, the two rate
filters, `guard_rate_label`, `apply_rate_attributes`). `get_rates_for_package()` on a method returns
`WC_Shipping_Rate` objects whose `get_meta_data()` is available, so the rate meta can be copied onto the shipping item as
`WC_Checkout` does. **Not measured:** rate meta and customer-store reads (Mine 3) for a real carrier — the fixture
methods return `cost 0` with no meta and touch neither; that grep belongs to I2a on the real rate paths.

## What each increment can take from this

- **I1a (persistence core).** Byte-for-byte scope is **classic checkout only** (table 2). Equivalence test: two plugins
  active, assert per-plugin `field_saved` → `data_saved` → `processed` order and payloads, plus the prio-20 popular
  writer; one `free_shipping` order proves the fan-out. The Store API path has nothing to preserve (table 3). The admin
  path's `woodev_shipping_{prefix}_admin_order_saved` needs a prefix rule — the two existing prefixes are not uniform.
- **I1b (marker contract).** `mark_order()` must write a non-empty scalar (probe above). Real writers differ in moment
  (`create_order` unsaved vs `update_order_meta` saved) and in dependence on posted data (edostavka, Yandex) or on the
  order's shipping items (Russian Post) — the `$context` the spec lists (rate, point, carrier fields) covers all three.
  Fixture carriers need a checkout-time writer **and** the point meta the provider declares (contradiction 6).
- **I2a (rates).** Table 4 + the path table: session-less zone resolution works; a seam in `Shipping_Method` is the
  only missing piece; expect per-zone instance ids.

## Open risks and what was not measured

- **#949 is a hidden prerequisite for correctness, not only for rates:** merging it makes framework carrier orders
  placeable in the block checkout, where **no framework write runs** (contradiction 5). Worth a card or a line in #949.
- CPT datastore not measured (rig is HPOS); the marker probe and the create-order log ran on HPOS only.
- Real carrier rate code (Mine 3), state-restricted zones and disabled instances were not measured — fixtures only.
- E-mails do not leave the rig («Could not instantiate mail function» order notes): hook firing was measured, delivery
  was not.
- The classic runs used `curl` with a nonce from the page and the field names from the rendered form; the browser
  pickup modal (`pickup-mount.js`) was not exercised, only its POSTed hidden field.

## Related

- [../../specs/2026-09-27-710-create-edit-order-design.md](../../specs/2026-09-27-710-create-edit-order-design.md) — the spec this measures (D2, D4, Increments I0)
- [../../specs/2026-09-07-sp10-orders-page-design.md](../../specs/2026-09-07-sp10-orders-page-design.md) — M2, the marker-key row scope
- [../../gotcha-index/rig.md](../../gotcha-index/rig.md) — the rig gotchas read before measuring
- [../2026-09-27-935-id-narrowing/README.md](../2026-09-27-935-id-narrowing/README.md) — the previous research note in this format
- #962 — this card; #710 — the wizard; #949 — Store API rates (`fix/949-store-api-rates`); #872 — `Action_Result`
