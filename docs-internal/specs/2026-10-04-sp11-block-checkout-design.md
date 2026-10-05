# SP-11 — Checkout Blocks location and pickup adapter

**Status:** DECIDED — the operator settled D-1…D-7 on 04.10.2026 (#1086). Implementation follows the slices in
«Decomposition» (#1087–#1091).

## Operator decisions (04.10.2026, #1086)

| Fork | Decision |
|---|---|
| D-1 | **A** — a separate «locality» chooser next to the address form writes native City/State; native fields stay editable. No overlay on the core City input. |
| D-2 | **A** — forced inner blocks; the merchant does nothing. |
| D-3 | **B** — shared pickup-selection service; Blocks call it through Store API `cart/extensions`, classic keeps the current REST route unchanged. |
| D-4 | **A** — extract a neutral map host from the storefront runtime; classic and Blocks share it. |
| D-5 | **A** — Blocks adapter from WC 9.9, newer hooks feature-detected; classic stays at WC ≥ 7.0. |
| D-6 | **A** — one delivery chain (package 0); another package needing its own point → clear error, order refused. |
| D-7 | **A** — pickup selection only inside Checkout Blocks; an express-payment order on a pickup rate without a point is refused server-side with an actionable error; an address change in the Cart block clears the point. |
| msgid | The Russian storefront msgid «Пункт выдачи не указан.» (pickup REST) becomes an English msgid in C-2a (#1088). |

The option analysis below is kept as the record of why.

**Scope:** #1078, the release minimum in shipping-module decisions §11: city suggestions, region,
pickup-point selection with a map modal, session and order persistence, and classic checkout parity.
No production implementation is included in this draft.

## Evidence baseline

Inspected on 2026-10-04: framework `main` base `0fa5a78b`, WooCommerce **11.1.0** on the Mac rig
(`woocommerce.php:6`). All `WC:` references below are relative to:

```text
/Users/maksimmartirosov/.wp-env/wp-env-woodev-plugin-framework-5fd870b7/woocommerce.latest-stable/
```

`FW:` references are relative to this repository. Built WooCommerce JS is the installed evidence:
line numbers refer to the actual minified file, followed by a searchable symbol where useful.
There are no checkout source maps in this installation; do not present these references as TypeScript
source locations. Re-find symbols before implementation because bundle lines change between releases.
Documentation establishes supported extension routes; installed source establishes 11.1 behavior.
No browser acceptance or shared-database integration tests were run during this reconnaissance.

`DOCS-SCHEMA.md` specifies language and live relative-link rules but no dedicated spec template.
This document follows those rules, carries explicit draft status, and ends with Related links.

## Findings

### 1. Additional Checkout Fields API

| Question | Finding and installed-source evidence |
|---|---|
| Entry point and timing | `woocommerce_register_additional_checkout_field()` delegates to `CheckoutFields`; it defers until `woocommerce_blocks_loaded` if necessary (`WC:src/Blocks/Domain/Services/functions.php:13–30`). Register on `woocommerce_init` or later; early registration warns (`WC:src/Blocks/Domain/Services/CheckoutFields.php:191–198`). |
| Field types | Only `text`, `select`, `checkbox` (`CheckoutFields.php:40`, rejection at `:432–441`). No button, map, custom React renderer, hidden-input type, or remote suggestion-source option. Attributes are a small allow-list plus `aria-*` / `data-*` (`:596–629`); attributes do not register an autocomplete component. |
| Placement | Locations are `address`, `contact`, `order`; groups are billing/shipping/other (`CheckoutFields.php:43–47`, `:90–97`, `:410–418`, `:972–1004`). Address fields join both address forms; Store API serializes them in `billing_address` / `shipping_address`, while contact/order fields use `additional_fields` (`WC:src/StoreApi/Routes/V1/Checkout.php:246–263`; `WC:src/StoreApi/Schemas/V1/CheckoutSchema.php:349–352`). Address registration is not a shipping-only field override. |
| Replace core city/state? | **No.** Registration requires `namespace/name` (`CheckoutFields.php:391–395`), stores a separate additional-field descriptor (`:230–232`), and rejects duplicate IDs (`:425–429`). Core address keys and descriptors remain separate (`:648–809`). Core rendering special-cases country/state/address; city remains a text control (`WC:assets/client/blocks/checkout.js:15`, search `"state"===t.key`, then `ValidatedTextInput`). Adding `woodev/city` cannot replace `city`. |
| Sanitization/validation | Per-field `sanitize_callback` / `validate_callback` defaults (`CheckoutFields.php:216–218`), global `woocommerce_sanitize_additional_field` (`:843–870`), `woocommerce_validate_additional_field` with `WP_Error` (`:897–929`), and grouped `woocommerce_blocks_validate_location_{location}_fields` (`:1058–1075`). Conditional JSON-schema required/hidden/validation rules are processed at `:464–489`; literal `hidden: true` is explicitly unsupported (`:457–460`) and does not make `is_hidden_field()` true (`:270–278`). |
| Order/customer/session | Grouped meta keys are `_wc_billing/{id}`, `_wc_shipping/{id}`, `_wc_other/{id}` (`:61–83`, `:1188–1242`, `:1515–1526`). Customer values are synced with order values (`:1335–1362`). The allow-list hook includes registered keys (`:103–107`, `:126–137`); the customer session store saves permitted meta in its `customer` snapshot (`WC:includes/data-stores/class-wc-customer-data-store-session.php:80–96`, `:231–259`). Thus session support exists, but it uses WC's keys, not our carrier keys. |
| Display/edit surfaces | Admin billing/shipping/contact/order field integrations (`WC:src/Blocks/Domain/Services/CheckoutFieldsAdmin.php:31–35`, `:89–158`); order details and My Account view/edit/save integrations (`CheckoutFieldsFrontend.php:33–47`, `:195–274`); emails render other and address fields (`WC:includes/class-wc-emails.php:856–857`, `:908`). `show_in_order_confirmation` defaults true (`CheckoutFields.php:215`), with confirmation filtering at `:1393–1416`. |

Paths abbreviated as `CheckoutFields.php`, `CheckoutFieldsFrontend.php`, and `CheckoutFieldsAdmin.php`
in this subsection are all under `WC:src/Blocks/Domain/Services/`.
The [official Additional Checkout Fields guide](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/additional-checkout-fields/)
documents the address/contact/order model. We should use it for genuinely additional simple fields,
not for the city/region takeover or the pickup picker. Translating every existing descriptor into it
would silently change installed order-meta keys unless explicitly mapped back through our writer.

### 2. Address autocomplete: model to copy, not a City extension hook

The mandatory [official provider guide](https://developer.woocommerce.com/docs/features/address-autocomplete/)
describes a PHP provider plus a JS provider: matching IDs, country support, asynchronous search,
suggestion IDs/labels, and detail selection returning WooCommerce address fields, including city/state.
Its design is useful for keeping suggestion display separate from selected identity.

Installed 11.1 details:

- PHP extends `WC_Address_Provider`, whose contract is `id`, `name`, optional `branding_html`
  (`WC:includes/abstracts/abstract-wc-address-provider.php:18–39`).
  `woocommerce_address_providers` accepts class names or instances, validates class/properties and
  deduplicates IDs (`WC:src/Internal/AddressProvider/AddressProviderController.php:58–125`).
- JS registers with `window.wc.addressAutocomplete.registerAddressAutocompleteProvider`.
  It requires `id`, `canSearch`, `search`, `select`, verifies server registration and rejects duplicates
  (`WC:assets/js/frontend/utils/address-autocomplete-common.js:51–115`), then notifies the checkout
  store (`:118–132`). PHP alone does not implement search or selection.
- The checkout publishes providers only when its autocomplete setting permits them
  (`WC:src/Blocks/BlockTypes/Checkout.php:420–438`). The built renderer selects a provider by country,
  ignores short queries, and has a request sequence guard
  (`WC:assets/client/blocks/checkout.js:8`, search `function ks`, `t.search(f,l)`).
- Selecting details dispatches `setShippingAddress` / `setBillingAddress`, mirroring the address
  according to checkout flags. A provider **can fill city and state** as part of that address
  (`checkout.js:8`, search `s.select(t,l)` and `h(c),o&&u(c)`).
- The actual autocomplete control is chosen inside the **Address 1** renderer `ws`
  (`checkout.js:8`, `l=a.length>0?bs:zt.ValidatedTextInput`). State uses a state control; City uses
  ordinary text rendering (`checkout.js:15`). There is no provider option selecting `city` as
  the input that triggers searches. In this installed Blocks build, postcode is also ordinary
  text/validation, not a second provider-trigger control (`checkout.js:14–15`). The earlier
  “Address/Postcode” description must not be read as a verified 11.1 City attachment API.
- The guide describes three `search` arguments; the installed Blocks call passes query/country.
  The installed `select` call also passes country. An adapter must tolerate these call-site details
  rather than depend on an address-type argument always being supplied.
- Provider availability is gated by `woocommerce_address_autocomplete_enabled` (default `no`;
  `WC:src/Blocks/BlockTypes/Checkout.php:421–438`). Framework code cannot rely on this merchant
  setting being enabled. A provider-based minimum must either provide its own supported trigger
  or treat the framework's locality chooser as the reliable path.

**Conclusion:** reusing the provider API alone does not meet City-triggered suggestions. D-1 is a
real product/API fork. There is no supported per-core-field component replacement found in this
11.1 renderer. This is narrower than claiming WooCommerce can never add such an API.

Our active location layer can suppress *all* WC address providers when it covers every selling
country (`FW:woodev/shipping-method/checkout/class-checkout-handler.php:502–521`). A new provider
would otherwise disappear under that existing filter. Keep existing arbitration; do not add a
second competing suggestion owner or silently undo suppression.

### 3. Store API extension transport and persistence

| Mechanism | Verified role and evidence |
|---|---|
| Endpoint schema/data | `woocommerce_store_api_register_endpoint_data()` delegates to `ExtendSchema` (`WC:src/StoreApi/functions.php:21–27`). Allowed endpoints include cart and checkout; registration takes `endpoint`, `namespace`, `schema_callback`, `data_callback`, `schema_type` (`WC:src/StoreApi/Schemas/ExtendSchema.php:26–32`, `:85–125`). Extension payload key is **`extensions`** (`WC:src/StoreApi/Schemas/V1/AbstractSchema.php:41`); checkout includes the extended schema (`CheckoutSchema.php:206`). |
| Live cart mutation | `woocommerce_store_api_register_update_callback()` (`functions.php:42–48`) registers one callback per namespace (`ExtendSchema.php:140–158`). `POST /wc/store/v1/cart/extensions` accepts `{ namespace, data }` (`WC:src/StoreApi/Routes/V1/CartExtensions.php:38–65`). The schema invokes the callback, recalculates totals and returns the full cart (`WC:src/StoreApi/Schemas/V1/CartExtensionsSchema.php:65–89`). It does not automatically save arbitrary values to session. |
| JS cart refresh | `extensionCartUpdate` is exported by blocks-checkout (`WC:assets/client/blocks/wc-cart-checkout-base-frontend.js:41`). The cart action sends that namespace/data request and receives cart data; dirty address preservation is explicit (`wc-blocks-data.js:5`, `gi=e=>`, `overwriteDirtyCustomerData`). Avoid forcing old server addresses over a user's newer local edits. |
| Final checkout data | Store action `setExtensionData(namespace, object, replace=false)` merges extension data (`wc-blocks-data.js:5`, `ei=`, checkout reducer). The inner-block helper instead offers `setExtensionData(namespace, key, value)` (`wc-cart-checkout-base-frontend.js:36`, search `setExtensionData:a` and `getExtensionData`). These are different signatures. Checkout processing reads `getExtensionData()` and sends `extensions` with the request (`same file:36`, search `extensionData:t.getExtensionData()` and `extensions:{...v}`). Neither setter is a server/session write. |
| Live checkout interaction | PUT/PATCH can update an existing pending/failed order, or update cart/customer/session state when no draft order exists. WC defers creating a draft until POST/place-order (`WC:src/StoreApi/Routes/V1/Checkout.php:352–460`; retry eligibility is `WC:src/StoreApi/Utilities/DraftOrderTrait.php:53–69`). On the no-order path, `woocommerce_store_api_checkout_update_draft($request)` fires after request validation and live session update; it was added in 10.8.0 (`Checkout.php:437–455`). Feature-detect this hook; use it to reconcile session-bound live state, not to save an order. |
| Request-to-order hook | On the order-backed PUT/PATCH path and final POST, `woocommerce_store_api_checkout_update_order_from_request($order,$request)` runs after address/payment/additional-field processing and before the order save (`WC:src/StoreApi/Utilities/CheckoutTrait.php:176–253`). Read `$request['extensions'][namespace]` there and persist/reconcile order data; this is distinct from the deferred-draft hook. Do not treat every invocation as order placement. |
| Payment gate and final writer | `woocommerce_checkout_validate_order_before_payment($order,$errors)` gathers `WP_Error` and throws a 400 on errors (`WC:src/StoreApi/Utilities/OrderController.php:190–216`). The completed-processing hook is `woocommerce_store_api_checkout_order_processed($order)` (`WC:src/StoreApi/Routes/V1/Checkout.php:677–690`). Run final validation before payment, then let the existing completed-processing writer persist full point/order data. |
| Request authority | Store cart routes check nonce/token authority and issue refreshed headers (`WC:src/StoreApi/Routes/V1/AbstractCartRoute.php:117`, `:156–159`, `:226–305`). Our `woodev/v1` writes use `wp_rest` nonce checks. These are separate mechanisms; never substitute one header for the other. |

`CheckoutSchema.php` above means `WC:src/StoreApi/Schemas/V1/CheckoutSchema.php`;
the two named JS files are under `WC:assets/client/blocks/`.

Proposed namespace: one framework namespace `woodev-shipping`, with data keyed by plugin ID and field
ID so simultaneously active carriers cannot overwrite one callback or one another's state. This is
a **new transport namespace**, not a replacement for existing REST names, session keys or order meta.
Register server schema/update/checkout hooks on requests that do not render blocks too; see §6.

D-3 recommends a Store API selection callback backed by a shared selection service. It must resolve
the owning handler, verify field/rate ownership, fetch point details, run the existing constraint and
domain-filter pipeline, and call existing selection persistence. Client-provided full point arrays,
weight or carrier identity are not authoritative. Cart weight and selected rate come from the server;
the declared payment choice must be checked against the available gateway before evaluating COD.

Cart response `extensions['woodev-shipping']` should expose a compact confirmed snapshot: owning
plugin/field, point ID, locality key, selected rate ID, display summary and selection verdict, without
credentials. Checkout extension data echoes that confirmation. At final POST, reject a non-empty
echo that disagrees with the authoritative session or current shipping line; do not “repair” session
from an unconfirmed ID. An empty/missing echo can use the existing session path for other clients.
Explicit clears must be representable and must clear the matching client validation state.

C-2a server transport contract (#1088): `Store_Api_Pickup` owns the single `woodev-shipping`
cart schema and update callback for every carrier. Both update commands and checkout echoes use
`pickup[plugin_id][field_id]`. A command contains `point_id` and optional `payment_method`, or
`clear: true`; only server-resolved rates, destination and cart weight are authoritative.
The cart snapshot contains `plugin_id`, `field_id`, `point_id`, `locality`, full `rate_id`, `summary`
and `selection` (the classic verdict/corrected-point/close/refresh advice). The destination fingerprint
stays server-side inside the existing scoped selection entry. A cleared snapshot is `null`.
Confirmation shares the classic 15/minute selection quota, so switching transport cannot bypass it.
Other slices must extend this registration owner rather than replace its callback or schema.
C-1 uses core address synchronization and registers no competing framework Store API namespace.

C-2b client contract (#1089), an extension of that same owner: the cart data gains a sibling key
`owner` — `{ plugin_id, field_id, rate_id, locality }` for the field that owns the cart's chosen
rate, `null` for any other rate. It is cart output only (absent from the checkout echo schema).
The pickup button renders from `owner` and the snapshot alone, and only while both name the rate
selected in `wc/store/cart` right now; it never infers ownership from a label or a method list.
`locality` is the key the owner's points are addressed by AND the one a confirmation is made
against (`''` → no resolved locality: the client opens no dialog and shows the «choose your locality
from the suggestions» hint — never the typed city, #1110). The echo carries the snapshot's identity keys for the owning field and `null` for
every other field. The storefront map session is `pickup-session.js`, opened with a per-surface
host; the Blocks host sends no `clear` command on a rate switch (the snapshot's rate scoping
already clears it).

Three rules added by the C-2b critic round (#1089):

- **Gateway id.** The command's `payment_method` is the active registration's `paymentMethodId`,
  resolved through WooCommerce's public payment registry — never the registration's name.
- **Live payment context.** The Blocks host adds `payment_method` (the same gateway id) to every
  `/points` and `/points/{id}` request. The server honours it only when it names a gateway the
  store offers right now, and otherwise answers from the session as before; the classic checkout
  sends nothing. The verdict stays advisory — confirmation and pre-payment validation re-check.
- **`pickup_replace_address`.** Honoured on the server, inside the confirmation's own request: the
  shipping street line and postcode move to the point's (values the point has; billing too only
  when the store ships to the billing address), rates are recalculated, and the confirmation is
  remembered under the NEW destination — a browser-side rewrite afterwards would drop it. The
  snapshot's `destination` names the moved fields and the client takes them into the native
  address under the same checkout gate, mirroring billing only where it is the same address. A
  move the chosen rate does not survive is undone. The city is never replaced: it is the
  customer's confirmed locality, and a record the native city no longer names is stale.

Rules added by C-3 (#1090), from replaying the multi-request flows against WooCommerce 11.1:

- **A retry's point is the order's.** `…_order_processed` fires on every `POST`, retries included,
  and the first attempt has emptied the session's memory. The pickup handler then contributes the
  point the order already carries (for the method it owns) instead of leaving the field absent —
  an absent field is sanitised to `''` and written over the stored id. The full point is not
  re-fetched or re-written on a retry. (Reached only after the pre-payment validation accepted
  the retry — next rule.)
- **«The session's draft» is the order WooCommerce would reuse** (`checkout-draft`, or
  pending/failed with the cart's hash) — never any order the session still names by id.
- **A retry is accepted on the confirmation the order was placed with, never on the bare id in its
  meta** (critic round 1). The final writer keeps that confirmation when it empties the memory —
  in the session, under the scope's own key with a `_placed` suffix, for the one order the session
  may retry; no order-meta key is added. A retry passes only while the order still carries that
  point and the cart still stands on the confirmation's FULL rate id, destination fingerprint and
  locality. Anything else — a street or postcode edit, another instance of the same method, no
  kept confirmation at all (an order placed before this rule shipped) — is refused like a missing
  point.
- **A reused retry order follows its rate and its destination.** On
  `…_update_order_from_request` a field drops the point the previous attempt stored once its
  carrier no longer owns the order's first shipping line, or once the confirmation above no longer
  matches on that checkout mutation — the order's point and placed confirmation are dropped for
  good, as a checkout mutation drops a live confirmation that moved: going back after this POST does
  not restore it, a new confirmation does. Before any POST, the #1101 cart snapshot is read-only:
  changing the rate/address hides it, but returning to the original state restores it because
  `validate_order` accepts that same state. A handler without a `Selection_Scope` is exempt. A clear
  with nothing to clear writes nothing.
- **Client.** Confirmation commands leave one at a time. A confirmation answering after the shopper
  left the rate it was asked for (or after the block unmounted) rejects as superseded and moves no
  address; that is decided from the block's own latch, not the cart store, which the late reply
  has just overwritten. The same holds on the SAME rate when the reply would move the street line
  or postcode and those fields are no longer what they were when the point was asked for (the
  shopper dismissed the dialog and typed their own): the shopper's edit stays, core pushes it, and
  the server drops the confirmation made for the point's address. An earlier queued confirmation's
  own move is not an edit.
- **Client, the reply's own move is not an edit (C-4, #1091).** Core takes the reply's addresses
  into the cart store BEFORE `extensionCartUpdate()` resolves whenever the shopper has no unsaved
  edit, so «the fields are no longer what they were» is judged against the address as asked WITH
  the reply's destination taken into it — also as an earlier queued confirmation left it. Without
  this every first confirmation in a `pickup_replace_address` store was rejected as superseded
  (gotcha `core-takes-a-cart-extension-reply-s-addresses-into-the-store-before-your-code-sees-it`).
- **Client, corrected point.** A reply whose snapshot names another point than the one asked for
  is this command's confirmation when the verdict carries the corrected point (`selection.point`):
  it is accepted, its destination is taken by the rules above, and the echo names the corrected id.
- **Retry cart snapshot (#1101):** after a failed payment, Checkout Blocks re-reads cart state on
  WooCommerce's public `onCheckoutFail` event. With empty session memory, the snapshot falls back to
  the session's reusable draft/retry order only when its saved point and placed confirmation still
  match the current full rate id, method instance, locality and shipping destination; the chosen rate
  must still be available, and another handler-owned pickup rate in an extra package disables this
  fallback. Otherwise it stays `null` and the shopper chooses again. A live session selection
  remains authoritative. The restored snapshot re-binds identity only; carrier/payment compatibility
  is still decided by `validate_order` on Place order. A rate/address change hides the point only
  while changed: returning to the original values before any checkout POST restores the snapshot,
  which `validate_order` accepts.

Important existing code, not new work to recreate:

- #949 is **closed**; the REST rate guard has already been fixed. Re-verify rates after address
  changes, but do not file another implementation of that fix.
- `FW:woodev/shipping-method/checkout/class-checkout-handler.php:304–309`, `:1965–1979`,
  `:2106–2137` already wire Store API persistence, required-point validation and the session-data
  contributor. `persist_values()` at `:3208–3240` shares the carrier-marker and custom-field writer,
  skips native address properties, and removes stale pickup values.
- The current Store API pre-payment guard checks **missing point IDs**, not the full COD/weight
  re-check (`Checkout_Handler::pickup_point_errors()`, `:2066–2088`). Classic pickup validation calls
  `validate_posted_point()` on its own checkout-process hook
  (`FW:woodev/shipping-method/pickup/class-pickup-handler.php:1949`, `:3502–3507`). The adapter must
  provide equivalent final Store API constraint validation; do not mistake the presence guard for it.
  Preserve the existing filtered re-check-outage policy (`class-pickup-handler.php:1860–1875`);
  whether a carrier outage permits refinement checks is not a new Blocks-specific decision.
- `FW:woodev/shipping-method/pickup/class-pickup-handler.php:1951–1952`, `:3574–3614` already
  contribute remembered point IDs and persist full points at priority 20 after field persistence at
  priority 10. It **clears remembered selections before `persist_full_point()`**, not after a confirmed
  carrier re-fetch; inspect the actual code rather than its higher-level description.
  That writer can log and return on lookup failure (`:3644–3666`). D-3 must not introduce another
  clear or another final writer; retry/payment-failure tests must cover this existing lifecycle.
- **Named risk — payment retry bypasses the current required-point guard (owner: C-2a server).**
  `Checkout_Handler::pickup_point_errors()` returns no errors unless the order is `checkout-draft`
  (`FW:woodev/shipping-method/checkout/class-checkout-handler.php:2066–2068`), while WC permits
  pending/failed order retries (`WC:src/StoreApi/Utilities/DraftOrderTrait.php:53–69`). The existing
  Store API writer clears remembered selections before re-fetching the full point
  (`FW:woodev/shipping-method/pickup/class-pickup-handler.php:3574–3580`). A failed-payment retry can
  therefore have no session point and bypass the presence guard. The new validator must check the
  current required point and constraints for retryable orders without keying on `checkout-draft`.
- The framework's current Store API method reader returns the first shipping line
  (`FW:woodev/shipping-method/checkout/class-checkout-handler.php:2142–2160`). Multi-package
  incompatibility therefore needs new server validation; a UI warning alone cannot prevent an order.

### 4. Rendering pickup UI and the core Local pickup feature

**Supported route in 11.1:** a registered Checkout inner block, using WordPress block registration
plus `registerCheckoutBlock({metadata, component, force})` on the frontend. The
[official field-block guide](https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/extend-store-api-add-custom-fields/)
uses `woocommerce/checkout-shipping-methods-block` as parent. Inspecting source avoids a mistaken
claim that this parent is only the delivery/pickup toggle: that toggle is singular
`checkout-shipping-method-block`; the rates section is plural `checkout-shipping-methods-block`.

- Valid areas, including shipping/billing address, fields, shipping methods and pickup locations,
  are enumerated by `innerBlockAreas`; parent validity is checked and components registered in
  `WC:assets/client/blocks/wc-cart-checkout-base-frontend.js:42` (`ce`, `ue`, `pe`).
- `force` is explicit or derived from `metadata.attributes.lock.default.remove` (`same file:42`).
  Missing forced children are rendered once per parent when absent from saved children (`:1`,
  search `force:t`, `!a.includes(e)`, `${e}_forced_${r}`). Thus a supported frontend route exists
  without rewriting checkout page content. It still requires a mounted core parent.
- Editor shipping methods exposes the inner-block area (`WC:assets/client/blocks/checkout.js:32`,
  `innerBlockAreas.SHIPPING_METHODS`); frontend shipping methods renders rates followed by children
  (`WC:assets/client/blocks/checkout-frontend.js:3`, `st=`).
  Shipping/billing address similarly append children after the address form (`same file:3`, `Ke=`,
  `ze=`). Parent names are declared in `WC:assets/client/blocks/inner-blocks/checkout-shipping-methods-block/block.json:1–27`.
- `ExperimentalOrderShippingPackages` and `ExperimentalOrderMeta` remain **experimental** exports
  (`wc-cart-checkout-base-frontend.js:41`). They render near shipping packages and in order summary
  (`checkout.js:32`, `checkout-frontend.js:5`). They do not provide a stable core City renderer hook.
  A SlotFill may be convenient but is not the recommended stable route for the minimum.
- Core Local pickup is its own pickup-options inner block, gated by `prefersCollection` and enabled
  Local pickup (`checkout-frontend.js:3`, `ve=`). It renders radio options derived from actual rates,
  with `pickup_location`, `pickup_address`, `pickup_details` rate metadata (`:2`, `Ce`-adjacent UI).
  Core warns when packages choose different pickup locations (`checkout.js:32`). This is a UI model,
  **not** a carrier PVZ extension registry. Do not rename carrier rates to `pickup_location` or take
  over the global collect/deliver mode: that changes address/tax behavior beyond choosing a point.

Recommended parents: locality controls under shipping and billing address, subject to D-1;
pickup button/summary under shipping methods, visible only for the owning selected carrier rate.
The map modal is opened by that component and destroyed on close/unmount; it is not an additional
checkout field. D-2 decides automatic versus merchant-managed insertion.

### 5. Checkout stores and rate recalculation

Use `@wordpress/data` and the public `wcBlocksData` store exports, not DOM inspection:

| Store | Adapter use and installed evidence |
|---|---|
| `wc/store/cart` | Read `getShippingRates()` and each package's `shipping_rates[].selected`, preserving `rate_id`, `method_id`, `instance_id` and package ID; read `getCustomerData()` or `getCartData().shippingAddress/billingAddress`. Exported selectors and camelCase customer shape: `WC:assets/client/blocks/wc-blocks-data.js:1`, `ae=`, `ie=`. Core selection receives a server response: `:5`, `selectShippingRate` / `Di`. |
| Address actions | `setShippingAddress` and `setBillingAddress` update local state (`wc-blocks-data.js:5`, `function Gi`, `function Vi`). Its subscriber compares dirty properties, detects country/state/postcode/city, and submits `updateCustomerData` to `/cart/update-customer` (`:4`, `ms=[...]`; `:5`, `Zi`, `Ji`, `wi`). A DOM `change` or classic `update_checkout` is not this transport. |
| `wc/store/checkout` | Read processing status and shipping-as-billing flags; use the public extension-data action described above. Core provider selection mirrors both addresses according to flags (`checkout.js:8`). Avoid calling internal processing actions; the exported `disableCheckoutFor` can wrap asynchronous confirmation (`wc-blocks-data.js:5`, `Na=`) rather than manually incrementing counters. |
| `wc/store/validation` | `setValidationErrors({[id]:{message,hidden}})`, `clearValidationError(id)`, `getValidationError(id)`. Errors are keyed and **any** remaining key contributes to `hasValidationErrors()` (`wc-blocks-data.js:3`, `zt`, `Wt`, `tr`). `hidden` controls display, not whether the error blocks. Checkout processing consults that selector (`wc-cart-checkout-base-frontend.js:36`); checkout exposes hidden errors after an attempted submit (`checkout-frontend.js:5`). |

Address writes should merge with the current full address, preserve names/address/phone, and mirror
only when the corresponding core flag requires it. Do not overwrite the other address unconditionally.
Once a confirmed locality exists, set core City/State, await the core address-sync completion, then
refresh provider-dependent rates through `extensionCartUpdate` if the locality key changed without
changing address text. Core address sync already handles ordinary text changes; avoid a second fetch
for every keystroke. Serialization must prevent the refreshed cart from returning the previous city.

When country/state/city meaningfully changes, invalidate the active point immediately, clear its
checkout echo and require reconfirmation when appropriate; retain only the existing scoped selection
memory that the domain allows. Compare values, not object references or repeat render events.
Re-opened map requests use the resolved locality **key**, not the city's display name.

Validation IDs must include owning plugin/field and supported package scope. Missing required point,
failed confirmation, or an address update still in flight must not enable submission with an old point.
Clear only the adapter's own errors on method switch, successful selection or unmount; never clear the
whole validation store. The server's existing pre-payment guard remains authoritative against bypass.
A rendered error must provide an accessible way to reach the picker, not just a disabled button.

### 6. Loading scripts and separating server registration

Implement `Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface`: `get_name()`,
`initialize()`, frontend/editor handles, script data (`WC:src/Blocks/Integrations/IntegrationInterface.php:9–41`).
Register through `woocommerce_blocks_checkout_block_registration`; the registry constructs that hook
from its identifier, calls `initialize()`, deduplicates integration names, aggregates script handles and
publishes `{name}_data` (`IntegrationRegistry.php:50–74`, `:133–180`).
The checkout block supplies these handles as dependencies and publishes integration data
(`WC:src/Blocks/BlockTypes/AbstractBlock.php:140`, `:199–208`, `:507`). Our integration registers our
scripts; it must not subclass WooCommerce's internal `AbstractBlock`.

Use one framework integration per page and a list of all active carrier configs, preserving source
ownership. New React files are TypeScript. Asset dependencies must resolve the WooCommerce script
handles actually installed; verify generated `.asset.php` and runtime `window.wc` exports rather than
assuming an npm package exists. Keep editor handles lightweight; never publish a visitor nonce or
session selection as cacheable editor data.

**Server callbacks should register independently of rendering.** In WC 11.1, `AbstractBlock::render_callback()`
skips `register_block_type_assets()` and `enqueue_assets()` during REST requests
(`WC:src/Blocks/BlockTypes/AbstractBlock.php:97`); this source does not establish that block
registration itself is skipped. Register Store API callbacks on `woocommerce_blocks_loaded` / the
appropriate server initialization independently of `IntegrationInterface::initialize()` and render
callbacks, so REST requests do not depend on frontend asset lifecycle. No global opt-in to register
every core block on REST is needed.
Our classic `enqueue_assets()` currently checks `is_checkout()` and still enqueues classic scripts
there (`FW:woodev/shipping-method/checkout/class-checkout-handler.php:702–732`): implementation needs
an explicit surface guard so a Blocks checkout does not boot the classic DOM adapter alongside React.
Cart and My Account remain on their existing classic paths.

## Mapping the existing implementation

| Classic piece and evidence | Blocks mechanism / reusable part |
|---|---|
| Field descriptors/config/policy: `FW:woodev/shipping-method/checkout/class-checkout-handler.php:2221`, `:2642`, `:3208`; `class-checkout-field-policy.php:259`, `:456`, `:628` | Reuse declaration, condition, sanitization and writer rules. A new surface adapter evaluates ownership/renderability for Blocks. Country-locale policy already affects Blocks; classic-only `woocommerce_checkout_fields` policy does not. Audit pickup-dependent hide/required settings on both client and server. Do not promise wholesale §8 field parity in this minimum. |
| DOM/store binding: `FW:woodev/shipping-method/assets/js/frontend/checkout-field-classic.js`; `checkout-field-store.js:175–199`, `:218–272` | Reuse pure descriptor/condition evaluation and one owning store instance if required by shared components. WC cart store owns core address/rate truth; the framework store holds custom-field values/conditions, with explicit one-way projection to avoid loops. DOM renderer, classic Place Order gate and fragment reattachment are replaced. |
| Location cascade/suggestions: `FW:woodev/shipping-method/assets/js/frontend/location-cascade.js:816`, `:1699–1744`; REST `class-location-controller.php:278–375`, `:1242–1413` | Reuse `Location_Service`, provider arbitration, neutral records, scoped suggest/select/list/current operations, acceptance rules and customer store. Replace jQuery/selectWoo renderer with controlled React suggestion controls under D-1. Keep selection sequence/country guards; a parent response cannot discard a queued child pick. |
| Region source: `FW:woodev/shipping-method/checkout/class-checkout-handler.php:561–610`; `location/class-location-service.php:3211` | Core Blocks region control reads country states. Reuse final `woocommerce_states` options and their ownership. Map selected region to an actual final option value, not a DaData key or guessed region code. Where no options exist use core text plus the controlled region chooser; a failed match must not write an invalid state code. |
| Pickup trigger/modal: `FW:woodev/shipping-method/assets/js/frontend/pickup-mount.js` | Stable inner block button/summary and a React modal host. Its large DOM/address/hidden-input closure is not reusable unchanged. Apply confirmed `replaceAddress` through cart address actions and honor close/refresh decisions. |
| Map/data/list runtime: `FW:woodev/shipping-method/assets/js/frontend/pickup-datasource.js`, `pickup-panels.js`, `pickup-geo.js`; React bridge `FW:src/shipping-orders-page/order-wizard/pickup-map.tsx:40–92`, `pickup-session.ts:183–220`, `:456–457` | Reuse map provider implementations, panels, point fetching, viewport behavior and cleanup. The existing React bridge is a useful lifecycle model, **not a reusable checkout modal**: its admin session uses manager chrome and reports locally without `/select` (`pickup-session.ts:13–35`). D-4 decides extraction versus a new host; no admin endpoint or manager labels in storefront. |
| Confirmation/session: `FW:woodev/shipping-method/rest-api/class-pickup-controller.php:555–742`; `pickup/class-pickup-handler.php:3075`; `pickup/class-pickup-selection.php:126–142` | D-3 adapts the shared confirm/filter/action/remember service to Store API. Reuse plugin-specific `Selection_Scope` and `(locality,type)` selection memory; add no parallel session selection store. Corrected point, denied verdict, `replaceAddress`, close and refresh flags survive transport changes. |
| Order persistence: `FW:woodev/shipping-method/checkout/class-checkout-handler.php:1965–1979`, `:3208–3240`, `:4017`; `pickup/class-pickup-handler.php:3574–3666` | Already works through Store API hooks when session contains a confirmed point. Keep field IDs, full-point key maps, marker hooks and HPOS writer. Native city/state stay WC properties. Add consistency checks, not a second writer or `_wc_*` replacement for installed carrier meta. |
| Cart/My Account (#331/#332): `FW:woodev/shipping-method/checkout/class-checkout-handler.php:929–969`, `:1207–1236`, `:1304–1340` | Preserve these surfaces. #332 persists only a posted settlement record that actually names the saved city/country and passes provider acceptance; reuse that **invariant**, not its form/hidden-input transport. A manual Blocks city edit must similarly discard incompatible provider provenance while preserving the actual address text. |

Paths beginning `pickup/`, `location/`, or `rest-api/` in this table are under
`FW:woodev/shipping-method/`; unqualified field-policy/config files are in its `checkout/` directory.
`Customer_Location_Store` uses existing `woodev_customer_location` in session/user meta
(`FW:woodev/shipping-method/location/class-customer-location-store.php:69`, `:352–375`).
Do not fork that data contract for Blocks. The current chain has one delivery locality, so independent
billing/shipping chooser state must only persist the effective delivery chain; D-6 constrains scope.

## Proposed architecture and interaction

One framework Blocks integration registers three thin frontend surfaces: shipping locality chooser,
billing locality chooser when effective, and carrier pickup selector. They consume the same PHP
declarations and location/pickup services as classic. There is no JS implementation in individual
carrier plugins. Below is the recommended design, conditional on approval of D-1 through D-7.

1. **Load/hydrate:** publish effective field/source policies and active carrier configs; read WC's
   address/rate state and current framework chain/selection. Prefill only a chain matching the actual
   country/delivery city. A stale provider record degrades to editable address text, not a fabricated ID.
2. **Find a locality:** region/city React controls query the existing location REST service and honor
   active/fallback provider, scope narrowing and custom-settlement policy. A confirmed selection
   persists through existing location selection, then writes bare city component and valid core state
   value into the effective WC address. A sequential queue prevents older selections/address syncs
   from overwriting newer choices. Display labels are not identity keys.
3. **Refresh rates:** core synchronizes address changes. An extension refresh handles provider-key-only
   changes after that sync. Re-read selected full rate ID and ownership; do not infer a carrier from a
   label or truncate the rate ID before server ownership checks.
4. **Pick a point:** owned pickup method reveals the button. Modal opens with locality key, current
   cart/payment constraints and reusable storefront map/list runtime. D-3 confirms via Store API;
   only a server-allowed point becomes selected. Update the summary/checkout echo from the returned
   confirmed snapshot; address replacement goes through WC stores, never selectors for hidden inputs.
5. **Place order:** own client validation must be clear. POST echo must match current session/rate/
   address. Existing server pre-payment validation blocks absence; the adapter adds final destination,
   method, payment and weight checks through the shared selection service before payment.
   Existing Store API writers preserve order fields/full point/marker and cleanup behavior. Failed confirmation
   keeps the modal actionable and does not install a point as selected.

The minimum must honor existing `region_field=remove`, address/postcode policies, custom settlement
permission and fallback providers. Do not reopen those settled settings as new decisions. If a Blocks
renderer cannot represent a configured mode, make that a visible implementation gap and resolve it
before declaring parity; do not silently force all stores to a different mode.

## Decisions for the operator (analysis; settled above)

### D-1 — Must suggestions attach to the native City input?

- **A — Supported locality chooser adjacent to the core address form (recommended).** A React child
  provides region/city search and writes the native WC City/State values. Native fields remain editable
  and authoritative; where native region options already exist, reuse that region selector. This meets
  sourced city/region values without private hooks, but a “Find city” helper is an additional control
  and appears after the form rather than inside the core City input. It requires operator acceptance
  of that exact interaction; do not call it native-field takeover.
- **B — Bind a suggestion overlay to core City/State DOM.** Closest classic UX, but ownership,
  controlled React input, focus, address-card mode and rerenders depend on private markup. It needs
  a tightly bounded compatibility layer and per-WC-version browser tests. No supported seam was found.
- **C — Wait for/contribute a native field-renderer extension to WC.** Clean eventual API, but an
  external dependency delays the release minimum. A WC Address-1 provider alone does not meet it.
- **D — Address-1 autocomplete provider as the minimum; locality chooser as an enhancement.**
  This uses WC's supported provider API to fill City/State when a shopper searches Address 1, with
  our chooser available for direct locality selection. It reuses WC's address synchronization, but
  does not put suggestions in the City control. WC only publishes providers when
  `woocommerce_address_autocomplete_enabled` is enabled (`WC:src/Blocks/BlockTypes/Checkout.php:421–438`;
  default is `no`), so the framework cannot depend on a third-party provider or merchant setting;
  the framework must register its own provider and still decide whether the chooser is required for
  guaranteed coverage. This is the smallest supported minimum if the operator accepts Address-1 as
  the trigger.

If “same City input” is mandatory, A and D are not accepted completion paths. The operator must
choose B with its maintenance cost or C with its release consequence. Do not hide core City via
locale/CSS to simulate replacement: hidden address keys can be cleared by WC (`wc-blocks-data.js:4`, `hs`).

### D-2 — Automatic UI or merchant-managed block insertion?

- **A — Forced inner blocks (recommended).** Set explicit `force: true`, supply normal editor block
  registration and supported parents. Existing pages get the minimum without content migration;
  mounted-parent absence still needs an actionable compatibility notice. No duplicate rendering
  when the saved child already exists. Operator cannot remove a feature required for its carrier.
- **B — Optional inner blocks inserted by the merchant.** More layout control, but an enabled pickup
  carrier may become impossible to order until its block is inserted. Requires onboarding and a
  missing-block check, making the release minimum dependent on page editing.
- **C — Experimental SlotFill auto-mount.** Convenient shipping-package placement, but deliberately
  experimental WC exports become our release dependency. Keep only as a separately approved fallback.

### D-3 — Pickup confirmation transport

- **A — Keep current `woodev/v1/shipping/pickup/{plugin}/select` plus a Store API refresh.** Least server change;
  already remembers selections. Two nonce lifecycles and possibly stale Blocks payment context need
  explicit bridging; response refresh adds a request. Checkout echo is still useful for consistency.
- **B — Shared selection service, Store API callback for Blocks (recommended).** Extract confirmation
  without changing classic REST contracts, call it from `/cart/extensions`, expose confirmed cart
  state, and echo it at checkout. One cart mutation/refresh path and authoritative rate context;
  larger initial refactor, with both transports pinned to the existing filters/actions/rate limits.
  REST confirmation must retain its existing reply format; Store API callback returns cart state.
- **C — Send point ID only in final checkout `extensions`.** Fewer requests, but no confirmed session
  selection before submit, poor reload/restore behavior and delayed carrier rejection. Does not meet
  the existing confirmed-point interaction or required session contract without additional work.

### D-4 — Pickup map host reuse

- **A — Extract a neutral session/host from the existing storefront runtime (recommended).** Supply
  context, confirmation, address-write, close and refresh callbacks; reuse providers/panels and both
  classic and Blocks tests. Preserves shopper chrome, but refactoring `pickup-mount.js` needs review.
- **B — Adapt the admin React session into another storefront host.** Faster proof of map mounting,
  but it currently has manager mode and no confirmation write. Share low-level lifecycle utilities,
  otherwise two assembly paths need maintenance and parity tests.
- **C — Rewrite the picker as a new React map.** Best long-term React ownership, much larger scope
  and unnecessary risk to clustering, embedded carriers, mobile controls and selection rules.

### D-5 — Blocks compatibility floor

- **A — 9.9 as the initial adapter floor (recommended).** The framework already feature-detects
  `woocommerce_checkout_validate_order_before_payment` by checking the actual controller method,
  documented in its source as available since WC 9.9
  (`FW:woodev/shipping-method/checkout/class-checkout-handler.php:2032–2043`). Keep classic checkout
  on the existing advertised WC ≥7.0 range. Feature-detect each Blocks API; specifically,
  `woocommerce_store_api_checkout_update_draft` is available since 10.8 and must be optional below
  that version. Verify earlier forced-inner-block behavior before claiming support.
- **B — Full advertised WC ≥7.0 Blocks coverage in this effort.** The repository advertises WC ≥7.0
  (`AGENTS.md`, Tech stack); this option preserves that range for Blocks too, but requires source/API
  comparisons and browser fixtures for older validation hooks, draft semantics, and forced-inner-block
  behavior. Changing the global minimum is not implied by this option.
- **C — 11.1 as the initial adapter floor.** Only WC 11.1 was directly inspected for the Blocks
  renderer, forced children, and provider control. This narrows Blocks support most, while classic
  checkout remains on the current floor. It reduces compatibility work but excludes supported stores
  from the new Blocks adapter unless they switch to classic checkout.

If the release promises Blocks at every currently supported WC version, B is required. A and C need
an explicit Blocks-only compatibility floor; neither should force an existing store to classic checkout.

### D-6 — Shipping-package and address-chain scope

- **A — Preserve today's single delivery chain / package-0 carrier contract (recommended for minimum).**
  UI reads all packages but owns only the supported package. Multiple active carriers remain supported
  through namespaced configs. If another package needs an independent framework point, show a clear
  incompatibility and prevent an order with a missing point; do not silently reuse package 0.
- **B — Independent points/records per package now.** Proper general multi-package support, but
  existing order readers/persistence use the first shipping line
  (`FW:woodev/shipping-method/checkout/class-checkout-handler.php:2150–2160`), and session selection is
  scoped by locality/type, not package. This is a broader data-model change needing its own design.

Independent billing address text is preserved in either option. The framework location chain remains
the effective delivery destination; choosing a billing locality must not overwrite a separate shipping
destination. When shipping follows billing, project that one effective chain into both WC addresses.

### D-7 — Express payments, Cart block shipping calculator, and session ownership

- **A — Keep pickup selection inside Checkout Blocks; block unsupported express paths (recommended
  for the release minimum).** Express-payment buttons can bypass checkout inner blocks, so server-side
  validation must reject pickup orders without a confirmed point and provide a clear actionable error.
  The Cart block shipping calculator is outside this slice; if it changes the address, it must clear
  or invalidate the existing point before checkout. Bind framework state to the Store API cart/session
  authority and explicitly test guest, logged-in, and cart-token requests; do not assume the cookie and
  cart token identify the same session.
- **B — Add pickup selection and address-change invalidation to express-payment and Cart block flows
  now.** Provides a complete path across surfaces, but expands the release slice to cart and payment
  integrations with separate UI and browser acceptance.
- **C — Defer those surfaces without blocking them.** Keep them usable only when the server guard can
  prove the selected point and current address/rate are valid; otherwise refuse the order with a
  surfaced error. This is the narrowest implementation, but leaves an express-payment or Cart change
  without an in-surface recovery flow.

For guest and headless clients, decide explicitly whether cart-token authority, cookie-backed customer
session, or their verified linkage owns `woodev_customer_location`; never let a request token silently
select another shopper's location state. Recommendation: use the server-resolved active Store API
session as authority, then verify token/cookie parity in the D-7 acceptance matrix.

## Proposed cards — ordered, independently mergeable vertical slices

No issues are created by this draft; the coordinator owns filing them after decisions.
Each slice includes its own tests and operational acceptance. Later slices depend on earlier ones,
but a merged slice must leave both checkout surfaces usable within the declared scope.

| Order | Proposed card title | Deliverable and acceptance |
|---|---|---|
| C-1 | SP-11: город и регион с подсказками в Checkout Blocks | First vertical slice: integration/assets, supported locality UI chosen in D-1/D-2, existing location API persistence, valid core City/State mapping, guest/account hydrate, **core address store synchronization only** (no framework `/cart/extensions` namespace dependency), and manual-edit invalidation. End-to-end delivery order with changed city/region stores native address and recalculates rates; classic/cart/My Account stay usable. Include region removed, real WC region codes, provider absence, fallback, same-key federal-city cases, and English storefront msgids in the source/catalogue and built-bundle `lint:js-i18n`. No pickup UI dependency. |
| C-2a | SP-11: серверная проверка ПВЗ для Store API | Server slice: shared selection/validation service, `/cart/extensions` namespace update callback and confirmed cart snapshot; final pre-payment validation for point presence, current rate/address, COD and weight; multi-package guard for unsupported independent package points; failed-payment retry validation that does not key on `checkout-draft`; deferred-draft reconciliation using `woocommerce_store_api_checkout_update_draft` when available, with order-backed echo/persistence through `woocommerce_store_api_checkout_update_order_from_request` and final writer at `woocommerce_store_api_checkout_order_processed`. Acceptance: **fail payment → retry → an order without a point is refused**; COD/weight and unsupported package cases are refused server-side. All user-visible errors use English msgids present in the catalogue and pass `lint:js-i18n` against the built bundle. C-2a is the explicit exception to “ship consuming UI with infrastructure”: it is dormant-safe, registers only its server API, and has no checkout-facing behavior until C-2b consumes it. |
| C-2b | SP-11: кнопка и модал ПВЗ в Checkout Blocks | Client slice: extract a reusable storefront session/host under D-4, add owned-rate button and accessible React map modal, consume C-2a's extension callback and snapshot, and provide reload restore/clear behavior. Browser acceptance covers successful confirmation, failed confirmation recovery, address/method changes, and English source msgids/catalogue/build `lint:js-i18n`. Keep the classic host usable. |
| C-3 | SP-11: синхронизация черновика и смена адреса, тарифа и оплаты без устаревшего ПВЗ | Exercise deferred-draft and order-backed PUT/PATCH flows: feature-detect the 10.8 `woocommerce_store_api_checkout_update_draft` hook for no-order live session reconciliation; use `woocommerce_store_api_checkout_update_order_from_request` for an existing order; use `woocommerce_checkout_validate_order_before_payment` before each payment attempt and `woocommerce_store_api_checkout_order_processed` only after order processing. Cover rapid updates, corrected points/address replacement, method-instance/carrier switches, retries and stale responses; final checkout echo checks and scoped clears; no double persistence/clear or cross-carrier errors. Deterministic delayed-response tests plus browser failure/retry coverage, including the C-2a payment retry acceptance. |
| C-4 | SP-11: приёмка блочного чекаута и границы совместимости | Verify D-5/D-6/D-7 coverage with actual supported fixtures, customized saved layouts, force deduplication, missing-parent detection, multiple active plugins, core Local pickup, separate billing, virtual carts, mobile/theme/keyboard dialog behavior, express-payment and Cart block scope, guest/logged-in/cart-token session ownership, and classic regressions. Include a forced-block matrix: shipping methods parent hidden when `!showShippingMethods` (including core Local pickup), shipping address hidden when `!showShippingFields`, billing address hidden when `!showBillingFields && !useBillingAsShipping`; locality UI is effective for billing exactly when `showBillingFields || useBillingAsShipping`. Confirm native address/custom meta/full point/marker and session cleanup; record supported WC surface. Additional version/package implementation belongs here only if selected in D-5/D-6/D-7, otherwise explicitly defer it through the coordinator's board. |

C-1 and C-2b browser acceptance should accompany their merges; C-4 is the combined compatibility
matrix, not permission to merge unverified earlier work. Public docs remain frozen per operator policy.

## Verification and remaining uncertainties

Implementation gates: `composer check`, `npm run test:js`, typecheck, asset parity, relevant i18n
checks, and `npm run lint:docs`. New storefront React/TS strings and Store API checkout errors are
rendered to shoppers: use English msgids, include translations in the catalogue, build the bundles,
then run `npm run lint:js-i18n` against the built bundle. The classic pickup controller currently
contains the Russian storefront msgid `Пункт выдачи не указан.`
(`FW:woodev/shipping-method/rest-api/class-pickup-controller.php:587`); whether C-2a should migrate
that existing response too remains an open question. Use meaningful unit/Jest tests around new shared confirmation logic,
store synchronization, request ordering and validation; browser acceptance must exercise real WC stores.
Schedule Store API integration tests on an isolated rig/database, not concurrent workers' shared DB.

Mandatory live acceptance includes RU Moscow (region/settlement same identity), another region with
homonymous cities, a country with native state codes, provider outage/manual city behavior, guest and
logged-in reload, pickup→delivery→pickup, stale lookup responses, payment retry, and a mobile modal.
Check email/admin/My Account for native address and existing carrier data, not only REST success.

Unresolved by source reading: exact approved City interaction/provider dependency (D-1); forced-block
placement in this rig's saved layouts; backwards compatibility below the selected D-5 floor; express
payment/Cart block scope and cart-token session ownership (D-7); and runtime payment-context
synchronization during pickup confirmation. These need decisions or browser measurements, not guessed
APIs. Core Local pickup coexistence and policy hiding also need live coverage.

Historical gotchas can describe fixes as “in flight” after they merged. The source and closed #949
take precedence. Do not reopen #949/#963/#964/#966 or #332 as missing features from stale prose.
The source-observed cleanup-before-re-fetch lifecycle is recorded here as an acceptance risk; any
change to its retry behavior belongs on a coordinator-filed card after a reproducible failure.

## Supported WooCommerce surface (C-4 measured)

Measured 05.10.2026 (s153, #1091) in a real browser against the macOS rig: WordPress 7.1,
WooCommerce **11.1.0**, Storefront, `en_US`, two carrier plugins (one on a live point source, one on
a static fixture), `pickup_replace_address` on. «Measured» below means a scripted Chromium pass that
placed real orders; nothing here is inferred from source unless it says so.

**Versions.** The adapter is exercised on 11.1 only. Its floor is **9.9**, by feature detection, and
below the floor it is dormant rather than broken — source reading plus the unit tests named here,
since an older WooCommerce cannot be installed on the rig:

| Detected | Since | On 11.1 | Without it |
|---|---|---|---|
| `WC_VERSION ≥ 9.9`, `OrderController::perform_custom_order_validation`, `woocommerce_store_api_register_update_callback()` / `…_endpoint_data()` | 9.9 | all present | `Store_Api_Pickup::register()` registers nothing: no `woodev-shipping` cart data, so the bundle renders no button (`test_adapter_is_dormant_without_the_wc_99_payment_gate`; jest «shows nothing when the server published no extension data») |
| `Routes\V1\Checkout::build_draft_route_response` → `woocommerce_store_api_checkout_update_draft` | 10.8 | present, hooked | not hooked; the order-backed `…_update_order_from_request` reconciles instead (`test_wc_99_registers_without_the_newer_deferred_draft_hook`) |
| `Blocks\Integrations\IntegrationInterface` | Blocks | present | no chooser, no button; the server still refuses a pickup order without a point (`test_the_integration_is_not_registered_when_woocommerce_blocks_is_absent`, both blocks) |
| `WooCommerce::is_store_api_request()` | 9.0 | present | rule (c) of the stale-record gate does not apply |
| client: `registerCheckoutBlock`, `extensionCartUpdate`, `disableCheckoutFor`, `updateCustomerData`, `core/notices` actions | — | all present | no block registered / the confirmation rejects (`…_transport_missing`) / the work runs ungated / no rate refresh / the error stays inline only — each has a jest case |

**Forced-block matrix** (`showShippingFields = !forcedBilling && needsShipping && !prefersCollection`,
`showShippingMethods = needsShipping && !prefersCollection`, `showBillingFields = !needsShipping ||
!useShippingAsBilling || prefersCollection`, read from 11.1):

| State | Shipping address + chooser | Shipping methods + pickup button | Billing address |
|---|---|---|---|
| default (same address) | shown, 1 chooser | shown; button under an owned rate only | hidden |
| separate billing address | shown, 1 chooser | as above | shown, no chooser — never written by the chooser or a confirmation |
| core Local pickup chosen (`prefersCollection`) | unmounted with its chooser | unmounted with the button; the field's error, notice and echo are withdrawn | shown |
| virtual-only cart | absent | absent; an earlier cart's point does not leak | shown |
| ship to billing only (`forcedBillingAddress`) | absent — **and so is the chooser** | shown; pickup works, the point's address moves the billing address | shown — it IS the delivery address, with native fields only |

So the locality UI is effective for the SHIPPING address form only. In a ship-to-billing-only store
the billing form is the delivery address and gets no chooser — the one cell of the matrix that does
not hold; deferred to a card rather than built here (a second chooser is a UI decision, cf. #1098).

**Also measured, and holding:** two carriers (each button under its own rate, a point of one never
shown or echoed under the other); pickup → delivery → pickup restores the point for the same rate
and destination; reload restores it (guest and logged-in, including WooCommerce's collapsed address
card); a homonymous city in another region moves the native State and the locality key; a hand
edit of City and the chooser's × both send `/forget`; a country the provider chain does not serve
shows no chooser and leaves the native fields — native State codes included — alone; orders carry
the same keys as a classic order of that carrier (the point id under the field id, the carrier
marker, the native address) and show on the admin order screen (carrier metabox) and in My Account;
the session's selection is emptied after the order; failed payment → retry keeps the point, and a
retry after a street edit, a reload or a switch to a courier rate behaves as the C-3 rules say; the
classic checkout still places a pickup order and `npm run test:e2e` is 7/7.

**D-6, D-7 as measured.** A second package on a framework pickup rate: the confirm command and the
order are both refused with «Pickup points for multiple shipping packages are not supported…»; the
same cart with the second package on another rate is placed. An order posted without the block
(what an express-payment client or a script does) on a pickup rate with no point — or echoing a
point that was never confirmed, or one confirmed before the Cart block changed the address — is
refused with «…Please choose a pickup point on the checkout page before paying.»; the Cart block's
address change empties the snapshot. A Cart-Token client and the cookie session it was issued from
are ONE session (same point, either can confirm or place); a stranger's token touches nothing.

**Boundaries — known, not supported in this minimum** (each is a proposed card of the C-4 report):

- **A hand-typed city with no chosen locality cannot get a pickup point — and now says so (#1110).**
  The points request is not a Store API request, so it lists the points of the store's default
  locality whatever City says; the confirmation IS one and sees no locality. The block no longer
  opens a dialog while `owner.locality` is `''`: it shows «Choose your locality from the suggestions
  to see pickup points.» under the button (also as the order's validation error), the server's
  confirmation and pre-payment validation refuse with the same words instead of the generic one. An
  EMPTY city keeps the store's default locality (it arrives as the owner's key).
  The owner is authoritative only once the cart has answered the address (`address-lifecycle.ts`):
  core writes a form edit into its store at once and pushes it later, so `getCartData()` never
  differs from the form and the lifecycle is followed instead. While an edit is unanswered — a push
  scheduled (a bounded 2.5 s window after the last edit; core's 1.5 s debounce is not observable) or
  in flight (`isCustomerDataUpdating`) — the button is inert and says «Loading pickup points…».
  An edit whose push failed, was aborted or never went out leaves the owner stale: the locality then
  counts as unresolved (the «choose your locality» hint, the order refused with the same words)
  until a later reply answers. An open dialog is destroyed when `owner.locality` changes or empties
  — a late reply included.
- **A locality can only be chosen with the cookie session.** `woodev/v1/location/*` is `wp_rest`
  nonce + cookie; a Cart-Token-only (headless) client cannot choose one, hence cannot confirm a
  point either. Decided in #1110: not a scenario of this bundle (it runs on the WordPress-rendered
  checkout page, cookie + `Nonce`); a headless client would also need its own point picker. A
  Store API locality command is a follow-up, never a loosened REST nonce.
- **`address_field` / `postcode_field = hide_for_pickup` are classic-only.** On the block checkout a
  pickup order still demands the native Postcode, which a point without one does not supply.
  Operator decision s154 (#1113, variant A): it stays required there — neither 9.9 nor 11.1 has a
  supported way to relax a core field for pickup only. What such a point does do is CLEAR the
  postcode a previous point wrote (of any field — the customer may have come from another carrier's
  point): the confirmation whose recorded destination is still the current one proves whose postcode
  it is, and the snapshot then names `postcode: ''`. A postcode the customer typed since is kept.
- **WooCommerce's own persisted cart is PAINTED before the server's (#1111, fixed s154 — the flash
  remains).** Core (9.9.0 and 11.1 alike) keeps the cart in `localStorage.storeApiCartData` and, when
  `storeApiCartHash` equals the `woocommerce_cart_hash` cookie, starts the cart store from it and
  finishes `getCartData` WITHOUT applying the page's preload or fetching (`wc-blocks-data.js`, `Wi()`
  and the `load` listener). The hash covers the cart's items and total only, so the copy persisted
  before an order matches the same product added again: old rate selected, «Chosen pickup point: …»,
  order refused. The bundle now asks for the cart while it is evaluated (`resolveCartFromServer()`),
  which queues core's resolver before `load` — the store ends on the page's preloaded cart, with no
  request. Measured on the rig: 0 of 8 stale loads (4 of 8 without it). What is left is core's own
  first paint from the persisted copy: the old rate and point are on screen for 5–40 ms (≈ 250–300 ms
  at 6× CPU throttle) before the server's cart replaces them. A form edit that never reached the
  server no longer survives a reload, as on any first visit.
- **A core parent missing from the saved page** renders without our forced children (gotcha
  `a-forced-inner-block-does-not-render-inside-a-parent-woocommerce-forced-in`); no notice yet.
- **The carrier marker is write-only**: a retry order re-placed on another carrier's rate keeps the
  first carrier's marker (the point itself is dropped).
- **The dialog's list is mouse- and touch-only.** Focus is trapped, Escape returns it to the button,
  but a list row cannot be reached or chosen from the keyboard — the shared runtime, classic too.
- Core Local pickup needs the store's checkout page to hold the Checkout block; express payment was
  exercised as a direct Store API client (the rig has no express gateway).

## Related

- [Shipping module decisions §11](2026-06-25-shipping-module-decisions.md) — mandatory minimum and shared-adapter contract.
- [Architecture](../wiki/architecture.md) — field/location/order persistence seams and data contracts.
- [Location chain design](2026-08-15-location-chain-design.md) — identity, level ownership and one customer chain.
- [Admin order wizard design](2026-09-27-710-create-edit-order-design.md) — existing React pickup host context.
- [Docs schema](../DOCS-SCHEMA.md) — internal language and link requirements.
- [Blocks country locale](../gotchas/block-checkout-reads-country-locale-not-checkout-fields.md) — core field policy path.
- [Store API versus classic hooks](../gotchas/the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks.md) — historical gap; fixes verified in current source.
- [Required rule on both sides](../gotchas/the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other.md) — client/server gate parity.
- [Queued selections](../gotchas/a-shared-select-queue-narrows-a-level-its-response-never-named.md) — late responses cannot erase newer picks.
- [Detached lookup sequencing](../gotchas/a-detach-that-only-unbinds-still-writes-through-whatever-was-in-flight.md) — lifecycle is not selection order.
- [Federal-city identity](../gotchas/dadata-collapses-region-and-settlement-into-one-key.md) — use reflexive scope membership.
- [Pickup locality identity](../gotchas/a-pickup-handler-built-without-its-plugin-silently-addresses-by-name.md) — carrier receives locality keys and resolved records.
- [Mobile picker checks](../gotchas/mobile-inline-min-width-and-floating-control-stacking.md) — browser-only modal regressions.
- [#1078](https://github.com/kalbac/woodev-plugin-framework/issues/1078), [#949](https://github.com/kalbac/woodev-plugin-framework/issues/949), [#332](https://github.com/kalbac/woodev-plugin-framework/issues/332) — current umbrella and completed prerequisite work.
