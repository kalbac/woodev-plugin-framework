# #710 «Создать заказ» / «Редактировать» — admin order wizard design

> Written in s141 (27.09.2026), in English per `DOCS-SCHEMA.md`, from a brainstorm WITH the operator.
> Every decision marked **(operator)** is his and is not reopened by an implementing session; the
> rest are the coordinator's defaults, marked **(default)** — change one only with a measurement or
> his word. The code facts were measured the same day (research pass over `src/`, `woodev/`); line
> numbers are as of `main` `cc27bf0` and will drift.
>
> **Reviewed the same day by a Codex critic (gpt-5.6-terra): 2 blockers, 5 majors, 1 minor — all
> folded in below** (marked «(critic)»). Re-grep every line reference before relying on it.

## What this is

A wizard modal on the framework's shipping orders page (SP-10, `src/shipping-orders-page/`) that
lets a shop manager **create** a WooCommerce order with a framework carrier's delivery — tariff,
pickup point and all — and **edit** an existing one of our carriers' orders before it is exported.

Why it exists **(operator)**: it came from users of the plugins. Shops regularly have customers
who cannot place the order themselves, so the manager places it in the admin. WooCommerce's own
«Add order» cannot set a carrier's delivery (no tariff, no pickup point, no carrier fields), and
cannot fix a customer's mistake (wrong ПВЗ) before the order goes to the carrier.

## Operator decisions (27.09.2026)

| # | Decision |
|---|---|
| O1 | **Scenarios, all three:** phone/messenger order; a shipment without a sale (reshipment, replacement, sample, gift); an order from another channel (marketplace, social, offline) shipped through our carrier. |
| O2 | **Two entry points, one wizard.** «Создать заказ» — one button at the top of the page, shared by all carriers (the page is shared, #694). «Редактировать» — a new row action in the «Действия» column. |
| O3 | **Edit scope: only our carriers' orders** (rows of this page), never WooCommerce orders in general. |
| O4 | **Edit: everything, until export.** Same steps as create; allowed only while the order is NOT exported to the carrier and NOT in a final status. After export, editing is closed (cancel the export first). |
| O5 | **Wizard, not a long form** — associative steps, like our setup wizard. |
| O6 | **Step order: Customer → Address → Items → Delivery → Payment.** The customer prefills the address; delivery comes after both address and items because rates depend on both. |
| O7 | **Payment:** the manager picks a payment method and the order status. No money moves. |
| O8 | **Prices are editable:** item price and the delivery price can be overridden by the manager. No coupons in v1 **(default — not asked; coupons were an explicit third option he did not pick)**. |
| O9 | **After «Создать»:** WooCommerce emails as from checkout; a checkbox «сразу выгрузить перевозчику»; the modal closes and the manager stays on the page, the new order appears in the table. |
| O10 | **Pickup point: list AND map inside the Delivery step** — not a second modal on top. |
| O11 | **New customer: guest by default + checkbox «создать аккаунт»** (WooCommerce creates the user and sends its email). |
| O12 | **Delivery methods: only framework carriers' methods.** An order with a foreign method would not appear on this page; such orders are made with WooCommerce's own editor. |
| O13 | **Carrier fields: yes, in the Delivery step.** A carrier plugin declares its own export fields in PHP (declared value, package dimensions, extra services…); the framework renders them under the chosen tariff and saves them to the order. No JS in the plugin (Rule 9, `AGENT-RULES.md`). |
| O14 | **Editing a PAID order that changes its total:** warn on the Payment step («было X, стало Y»), add a private order note about the edit. Refund / extra payment stays with WooCommerce's own tools. |

Coordinator defaults (not asked; each is WooCommerce's own behaviour or a safety floor):

| # | Default |
|---|---|
| C1 | Capability: `edit_shop_orders` for both create and edit (WooCommerce's own order-edit cap). REST routes check it. |
| C2 | Stock: WooCommerce's status transitions do it (`wc_maybe_reduce_stock_levels` on processing/completed/on-hold) — never by hand. |
| C3 | Closing a modal with unsaved input asks for confirmation. No drafts are persisted. |
| C4 | Emails: customer emails follow WooCommerce's status transitions; the admin «New order» email is sent once on create by WooCommerce's own `pending_to_*_notification` transition — the editor issues no explicit trigger, which would double-send (#962 I0, contradiction 1). No «New order» on edit: the editor mutes it, for that order only, while its own status transition runs (#968 r3). |
| C5 | Wording: admin-facing Russian msgids (AGENTS.md → Translatable strings), no jargon (Rule 10c). Labels short (Rule 10a). |

## What exists and what does not — the reuse map (measured 27.09.2026)

| Step / piece | Verdict | Evidence |
|---|---|---|
| Modal shell | **reuse** `@wordpress/components` `Modal`, the `OrderPreviewModal` pattern: the page owns fetch + cache, the modal receives data | `src/shipping-orders-page/app.tsx:42`, `:804`, `:2279` |
| Stepper | **reuse the look** of `src/setup-wizard/stepper.js` (WC-style progress line); lift it into `src/components/` rather than copy | `src/setup-wizard/stepper.js` |
| Address (country → region → НП → address → postcode) | **adapt** `src/components/location-picker-field.js` (#376): make `level`, `within`, REST root props; stop reading `window.woodevSettings`; add region + address levels and a settlement → postcode fill. **Never** `location-cascade.js` — it is welded to `billing_*`/`shipping_*` DOM ids and `update_checkout` | `location-cascade.js:169`, `:48-56`, `:60`; picker docblock `:1-75`, `:227` |
| Location REST | **reuse** stateless `GET woodev/v1/location/suggest` / `/location/list`; ⛔ **never** `/location/select` or `/location/forget` from admin — they write the ADMIN's own `Customer_Location_Store` | `class-location-controller.php:282`, `:406`; `class-customer-location-store.php:352-362` |
| Items | **build** with WC `Search` autocompleter `'products'` (+ `'variations'`) from `wc-components` (already a page dependency) | `filters.ts:557` notes Search needs its autocompleter |
| Customer | **build** with WC `Search` `'customers'` + a small create form; guest by default (O11) | — |
| Rates for an arbitrary package | **build** a service + REST route (nothing returns rates for an address today) | see D2 |
| Pickup points | **adapt**: an admin mount over `WoodevPickupDataSource` + `pickup-panels.js` + map providers, rendered INSIDE the step (O10). `pickup-mount.js` is checkout-bound and is not used | `pickup-datasource.js`, `pickup-panels.js`, `map-provider-*.js`; `pickup-mount.js:1721`, `:3117`, `:3382` |
| Points REST | **adapt**: `get_points_data()` runs cart/session callables (weight, payment method, location) — an admin variant must take them explicitly | `class-pickup-controller.php:858`; wiring `class-pickup-handler.php:2014-2023` |
| Create / update order | **build** (the framework creates no orders outside fixtures) | fixtures' seeders `class-test-orders-seeder.php:459-502` show the shape |
| Framework order metas | **refactor**: today written ONLY on `woocommerce_checkout_order_processed` by three handlers; extract a writer both paths call | see D4 |
| Carrier marker meta | **new explicit contract (critic, BLOCKER)**: `Orders_Provider` exposes only the KEY; the VALUE and the moment are the carrier's — edostavka writes `_wc_edostavka_shipping` from `$data['edostavka_shipping']`, the Yandex plugin uses its raw-status marker, fixtures only write `"1"` from their seeders. Never inferred | `class-orders-provider.php:336`; SP-10 spec M2; `plugins-reference/` |

## D1. The wizard

Five steps (O6) + a result state. The step indicator is clickable back to any completed step;
forward only through «Далее», which validates the step (server-side on the steps that need it).

```text
① Покупатель  search existing (name/email/phone) · or «Новый»: имя, фамилия, телефон, email
              ☐ создать аккаунт (O11)
② Адрес       country → region → НП → street/house/flat → postcode; prefilled from ①'s
              shipping (else billing) address; same field policy as checkout (required/hidden
              per the carrier's settings — Checkout_Field_Policy)
③ Товары      search product/variation · qty · price (editable, O8) · remove; subtotal
④ Доставка    carriers' rates for ②+③ (only ours, O12), grouped by carrier; pick one;
              pickup tariff → list + map inside the step (O10); delivery price editable (O8);
              carrier's own fields for the chosen tariff (O13)
⑤ Оплата      payment method · order status · totals (items + delivery) ·
              edit of a paid order: «было X, стало Y» warning (O14) ·
              ☐ сразу выгрузить перевозчику (O9, create only) · [Создать] / [Сохранить]
```

Result: modal closes, the page's existing `actionNotice` slot reports «Заказ №N создан» (or the
export error, see D6), the table refetches (O9).

Edit mode = the same wizard opened from the row action with all steps prefilled from the order;
the primary button reads «Сохранить».

## D2. Rates for an admin-built package

New service `Admin_Rate_Calculator` (namespace `Woodev\Framework\Shipping\Admin\Orders`) + route
`POST woodev/v1/shipping/orders/rates` (`edit_shop_orders`).

- Build a WC package: `contents` (product, qty, `line_total` at the EDITED price), `contents_cost`,
  `destination` (country, state, city, postcode, address, address_2), `applied_coupons: []`,
  `user.ID` (the chosen customer or 0), plus the framework's location record for the destination
  (see below).
- Compute ONLY our carriers' methods (O12): for each `Orders_Provider` (`method_ids`), resolve the
  methods available in the destination's shipping zone and call their `calculate_shipping()`
  directly — or `WC()->shipping()->calculate_shipping_for_package()` and filter rates by
  `method_ids`. Pick whichever keeps rate meta intact; measure both on the rig.
- ⚠ **Mine 1:** `Shipping_Method::should_send_cart_api_request()` (`class-shipping-method.php:840-845`)
  vetoes a call when `REST_REQUEST` is defined **OR** (critic) in admin after the cart loaded — either
  alone kills it — so the route gets NO rates today. Add an explicit, narrow override (a context flag
  set by the calculator for the duration of its call), not a global relaxation.
- ⚠ **Mine 1b (critic):** the calculator must resolve the actual ZONE-INSTANCE methods for the
  destination (`WC_Shipping_Zones::get_zone_matching_package()` → `get_shipping_methods( true )`,
  filtered by the provider's `method_ids`), without initializing `WC()->session`/`WC()->cart` (both are
  null in REST) — and the package destination uses WooCommerce STATE CODES, not the location
  record's label (map the record to the code the checkout would have posted).
- ⚠ **Mine 2:** rates are cached per package hash in `WC()->session` when a session exists — make the
  calculator's package unique or bypass the cache.
- ⚠ **Mine 3:** carrier rate code may read the destination's `Location_Record` from the CUSTOMER
  STORE (the admin's own!) instead of the package. The calculator passes the record explicitly
  (`Location_Service::resolve_for( $plugin, ?Location_Record )`, `class-location-service.php:1722`)
  and the implementing session greps every rate path for customer-store reads.
- Response per rate: carrier id + label, rate id, label, cost, delivery-time meta, `is_pickup`, the
  rate's meta (to be copied onto the shipping item on save, as `WC_Checkout` does,
  `class-shipping-rate.php:352-380`).

## D3. Pickup points in admin

Routes `GET woodev/v1/shipping/orders/pickup/{plugin}/points` AND `…/points/{id}` (`edit_shop_orders`)
that build the same `Point_Query` / point detail as the public routes but take **explicit** `weight`
(from ③), `payment_method` (from ⑤ if already chosen, else unknown → points are not filtered by COD,
and ⑤ re-validates), the chosen method, and the destination `Location_Record` — never the
cart/session callables (`class-pickup-handler.php:2014-2023`). (critic, BLOCKER) The public detail
route is NOT reused: `Pickup_Controller::get_point_data()` runs the same callables, so list and detail
eligibility would disagree.

Client: a React host component that instantiates `WoodevPickupDataSource` (with the admin route
root) and the panels + a map provider into a DOM node INSIDE step ④ — no `WoodevModal`, no second
modal (O10). Its `select()` is NOT used (it POSTs the session); selection is held in wizard state.
Load the provider scripts on the orders page (they are registered framework-wide).

Checkout parity: a pickup tariff without a chosen point blocks «Далее» (spec
`2026-06-25-shipping-module-decisions.md` A2).

## D4. Writing the order — one writer for checkout and admin

Today the framework's order data is written only inside `woocommerce_checkout_order_processed`
(classic checkout), by three handlers:

1. `Checkout_Handler::handle_checkout_order_processed` (`class-checkout-handler.php:1287`) →
   `process( $posted, $order )` (`:2322`) → sanitize / validate / `drop_stale_pickup_values` →
   `save()` (`:2204`) → `persist_field` → `update_order_meta`; errors via `wc_add_notice` (`:2375`) —
   needs `WC()->session`. ⚠ (critic) it also fires `…checkout_field_saved` and `…checkout_data_saved`
   with their own ordering and payloads, not only `…checkout_processed`.
2. `Pickup_Handler::handle_checkout_order_processed` → `Shipping_Order_Handler::store_pickup_point()`
   (`class-pickup-handler.php:3249`, `class-shipping-order-handler.php:119`).
3. `Location_Provider_Registry` popular-settlement candidate meta (`:726`, `:898`) — from the
   VISITOR's chain; **skip for admin orders** (the admin is not the buyer).

Design: extract an `Order_Shipping_Writer` (or equivalent) that takes an explicit input DTO
(destination fields, location record, rate + meta, pickup point, carrier fields) and writes
everything; checkout calls it with data from `$_POST`/session, the admin route with the wizard's
payload. Validation returns errors as data (a list), and only the checkout caller turns them into
`wc_add_notice`. Checkout behaviour must stay byte-for-byte (installed-site contract: meta keys and
ALL THREE hooks — `woodev_shipping_{prefix}_checkout_field_saved`, `…_checkout_data_saved`,
`…_checkout_processed` — same order, same payloads) — prove it with the existing checkout tests plus
an equivalence test (same input → same metas through both callers). (critic) The three hooks stay
CHECKOUT-ONLY: the admin path fires its own `woodev_shipping_{prefix}_admin_order_saved` (new, named
in the PR), never the checkout ones — plugins listening to checkout hooks must not see admin saves.
The admin path validates its DTO separately (no `$_POST`, no session, no notices); only the
persistence core is shared.

**Carrier marker — explicit contract, NO default (critic, BLOCKER).** Each `Orders_Provider` must
supply the marker writer itself — e.g. a required `mark_order( WC_Order $order, array $context ): void`
(context = chosen rate, pickup point, carrier fields) — because the value and its source differ per
carrier (edostavka: `_wc_edostavka_shipping` from checkout data; Yandex: its raw-status marker). A
provider without it → the wizard does not offer that carrier (and says why in a log line). BEFORE I1:
measure every real writer — `plugins-reference/` carrier plugins and every fixture — and record the
value each writes; the fixture carriers get real checkout-parity writers, not the seeders' `"1"`.
Test against the real plugin paths.

Create route `POST woodev/v1/shipping/orders` and update route `PUT woodev/v1/shipping/orders/{id}`
(`edit_shop_orders`), both through one service. (critic) Transport contract: cookie auth with the
existing `wp_rest` nonce the page bootstrap already injects (`window.woodevShippingOrders.nonce`,
`rest.ts` → `apiFetch`); declared responses — 401 not logged in, 403 lacking `edit_shop_orders`, 404
unknown order, 409 not editable / exported meanwhile, 422 validation errors as data; object-level
checks on every `{id}` route (the order is a row of this page, D5).

- create: `wc_create_order()` (+ `customer_id` or guest; `wc_create_new_customer()` when O11's box
  is ticked) → addresses → line items at the edited prices → one `WC_Order_Item_Shipping` from the
  chosen rate (cost overridden if edited, rate meta copied) → payment method → writer (D4) →
  marker → `calculate_totals()` → status (WC's own transition sends «New order» once
  and does stock, C2/C4; muted on update).
- update: same, replacing items/shipping item/addresses/metas in place; never touches a final
  status; refuses when exported (D5); O14 warning is computed client-side from the loaded total and
  the note is added server-side.
- Load route `GET woodev/v1/shipping/orders/{id}/edit` returns the wizard's prefill.

## D5. «Редактировать» — gating

The row action appears and the update route accepts only when ALL hold:

- the order is a row of this page (has a carrier marker, O3);
- not exported: the carrier-order-id meta (the one the «Новые» tab / `is_exported` filter reads,
  #841) is absent;
- the order status is not final (`completed`, `cancelled`, `refunded`, `failed`) and the delivery is
  not finished. (critic) `Delivery_Status` has NO final-state API — define ONE named editable-state
  policy (a method, e.g. `Order_Actions::is_editable()`), aligned with `Order_Actions`' explicit
  retired set, used by both the row action and the route; test stale export/status races on HPOS
  and CPT.

The route re-checks server-side (never trust the row). Stale-row race: the route re-reads and
refuses with a clear message if the order was exported meanwhile.

## D6. «Сразу выгрузить перевозчику»

Create only. After the order is saved, call the same export path the row action uses
(`Order_Actions`). **Depends on #872** (operator 27.09.2026: `export()` returns an `Action_Result`
with the carrier's message) — so a failed export reports «Заказ №N создан, но не выгружен: <текст
перевозчика>» and the order stays (never rolled back). Build D6 after #872.

**As built (#974, I8).** Step ⑤ draws the box in create mode only (never on an edit, O4), offered only in the
statuses the row action offers export in (`exportableStatuses` in the page bootstrap = `Order_Actions::EXPORTABLE_STATUSES`;
under any other the box is disabled and cleared). A ticked box sends the top-level `export_now` beside the order —
NOT part of the payload the validator reads. `POST /shipping/orders` saves the order first, then
`Order_Editor::export_created()` runs the export through `Order_Actions` — the same gate (`is_offered()`) and the
same performer (`Order_Actions::perform()`, moved out of `Orders_Controller::dispatch_action()` unchanged) as the row
action — and the `201` body gains `export: { success, message }`. The `message` is the whole sentence the page shows:
«Заказ №N создан. …» or «Заказ №N создан, но не выгружен. СДЭК: <carrier text>»; a failure is data beside the
order, never an error response, and the order is neither rolled back nor moved. The carrier text is composed by
`Action_Result::merchant_message()` and is for the merchant only — it goes into the response and nowhere else. A
carrier call that throws is logged and answered with a generic sentence; a status the export is not offered in is
refused with `Order_Actions::unavailable_reason()`. The page reports a refused export as an error notice.

## D7. Carrier fields (O13)

A carrier declares its export fields in PHP through the framework's existing typed-field vocabulary
(the Settings API field types — reuse, do not invent a new schema), per method/tariff, with defaults
from its settings. The rates response (D2) carries the field definitions for each rate; the wizard
renders them under the chosen tariff with the same React field renderers the settings page uses
(Rule 9: PHP-driven, no plugin JS); values are validated server-side by the carrier's declaration
and stored as order meta through the writer (D4). Fixture carriers get one or two example fields so
the path is exercised end to end.

**As built (#973, I7).** The declaration is an optional `order_fields` argument of
`Orders_Provider::create()` — `fn( array $context ): array` returning `field id => definition`, asked once
per tariff (`context`: `provider_id`, `method_id`, `instance_id`, `rate_id`, `is_pickup`, and the
zone-instance `method` for defaults from its settings). A definition is the Settings API's own vocabulary
(`register_setting()` + `register_control()` arguments: `type`, `control`, `name`, `description`,
`options`, `default`, `required`, `validate`, `show_if`, `min` / `max` / `step`, `tooltip`, `placeholder`)
plus one framework key, `meta_key` — the order-meta key the value is stored under, which is the carrier's
contract with its own export. `Carrier_Field_Set` turns it into a real `Woodev_Abstract_Settings` handler that
stores nothing, so the definitions (`Field_Schema`), the check (`Woodev_Setting::update_value()`, `show_if`)
and the persistence (`Woodev_Order_Compatibility`, one meta per field, a boolean as `yes` / `no`) are the
settings page's own code. The rates response carries `order_fields` (a LIST, in declaration order) on every
rate of the method; step ④ draws them with `ControlField` under the chosen tariff; an untouched field is its
declared default on both sides (the state holds only what the manager set); the payload validator reads ONLY
declared ids and reports problems on `carrier_fields.{id}`; `Order_Editor::persist()` stores them after the
marker and cleans up the fields of a tariff an edit replaced; the load route reads them back. Not done, on
purpose: carrier fields on the CLASSIC / Store API checkout (D7 is the wizard's) and any new hook — the
existing `…_admin_order_saved` already carries `carrier_fields`.

## Increments (each a card, each ends green; UI ones end with «готово, смотри риг»)

Split per the critic (each small enough for one worker round; the project caps at 2–3 rounds).

| # | Scope | Needs |
|---|---|---|
| I0 | **Measurement only:** every real marker writer (plugins-reference + fixtures) and its value; which checkout hooks fire in which order with which payloads; the zone/method resolution for an admin package on the rig | — |
| I1a | D4 persistence-core extraction, checkout byte-for-byte (all three hooks), equivalence test | I0 |
| I1b | Carrier marker contract (`mark_order`) + real writers in fixture carriers | I0 |
| I2a | D2 rate calculator + route (mines 1, 1b, 2, 3), rates grouped by provider | I0 |
| I2b | D3 admin points list + detail routes (explicit context) | — |
| I3 | Create/update/load service + routes (transport contract, D5 policy), integration tests on both datastores | I1a, I1b |
| I4a | Stepper lift into `src/components/` + generalized location picker (props, levels, postcode fill) | — |
| I4b | Wizard shell + steps ①–③ (customer, address, items) against I3's load/validate | I3, I4a |
| I5a | Step ④: rates list, pickup list + map inside the step, editable delivery price | I2a, I2b, I4b |
| I5b | Step ⑤ + submit: payment/status/totals, create — **operator's rig acceptance** | I5a |
| I6 | «Редактировать» row action + edit mode + O14 warning/note — **rig acceptance** | I5b |
| I7 | D7 carrier fields | I5b |
| I8 | D6 immediate export | I5b, **#872** |

Rig verification per the project rules: the coordinator's Playwright probe BEFORE handing the
operator the rig; the integration suite in the primary checkout (both datastores).

## What this does NOT do

- WooCommerce orders in general (O3); foreign shipping methods (O12); coupons (O8 default).
- Refunds or extra charges on edit (O14) — WooCommerce's own tools.
- Editing after export (O4) — cancel the export first.
- Shipments/SP-7 — the wizard creates a WooCommerce order, not a shipment (#710 s119 retarget).

## Related

- #710 — the card (operator's sketch 01.09.2026, s119 retarget)
- [2026-09-07-sp10-orders-page-design.md](2026-09-07-sp10-orders-page-design.md) — the page this lives on
- [2026-06-25-shipping-module-decisions.md](2026-06-25-shipping-module-decisions.md) — A2 (pickup blocks the order)
- #872 — `Action_Result` (D6 depends on it); #376 — the React location picker adapted in D1
