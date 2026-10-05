# #1113: Pickup-dependent core address fields on the Checkout block

Measured 2026-10-05, s154. **Design only: no runtime implementation.** The task explicitly requires
this outcome if either half has no supported mechanism. The missing half is reactive client
visibility/requiredness for WooCommerce's **core** postcode and street fields. The setting remains
clamped on blocks; #1113 remains an implementation need, not a resolved bug.

## Evidence and boundary

Inspected the locally installed WooCommerce **11.1.0** source (version confirmed in
`woocommerce.php:6`) without starting or changing the rig, and fetched the tagged **9.9.0** and
**11.1.0** upstream form sources. The installed plugin directory is
`~/.wp-env/wp-env-woodev-plugin-framework-5fd870b7/woocommerce.latest-stable/`.
Paths below are relative to that directory unless identified as framework paths.

The [official removal guide](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/removing-checkout-fields/)
documents country-locale changes for core fields. Its conditional example concerns virtual carts;
it supplies no reactive shipping-method API. The guide also confirms that locale changes affect
both shipping and billing.

### Server: a supported seam exists, but the current framework does not use it for this setting

- `src/StoreApi/Schemas/V1/AbstractAddressSchema.php:177` validates request address values.
  At `:238`, postcode format validation runs only for a **non-empty** postcode. Schema
  `required => true` means the property must be supplied, not that its string must be non-empty
  (documented at `:38`). Removing a schema property is not the requiredness fix.
- `src/StoreApi/Utilities/ValidationUtils.php` handles state validation/formatting; it does not
  implement postcode requiredness.
- `src/StoreApi/Utilities/OrderController.php:469` validates address presence from country locale.
  WC 11.1 skips optional or hidden fields (`:486`).
  [WC 9.9's corresponding loop](https://github.com/woocommerce/woocommerce/blob/9.9.0/plugins/woocommerce/src/StoreApi/Utilities/OrderController.php#L471)
  skips **optional** fields only: a future contribution must set `required = false`, not rely
  solely on `hidden = true`.
- `includes/class-wc-countries.php:896` caches the filtered locale on the countries object. A
  request-dependent contribution must be established before the first read after the Store API
  has loaded the cart/chosen methods; an order-processed hook is too late.
- Framework `Checkout_Field_Policy::filter_country_locale()` at
  `woodev/shipping-method/checkout/class-checkout-field-policy.php:259` currently contributes
  unconditional removal/order only. Its pickup relaxation is in
  `checkout_fields_contribution():555`, hooked through `woocommerce_checkout_fields`, which
  Store API checkout does not consume. `Checkout_Field_Settings::effective():202` also clamps the
  stored pickup-hide values to `show` on blocks.

The least invasive **server candidate** is the existing country-locale filter with an early,
Store-API-scoped `required = false` contribution under the existing pickup predicate.
`Checkout_Field_Policy::any_pickup_method_chosen():733` and
`Checkout_Handler::chosen_method_matches():3108` are the existing condition: **any chosen method**
matches a registered pickup id, including its `:instance` suffix. A future implementation must
reuse that condition rather than inventing a point-confirmed/first-package/all-packages rule.
This candidate was not implemented because the client half is unavailable through a supported seam.

### Client: static core definitions, no supported reactive override found

The source paths below are relative to `plugins/woocommerce/client/blocks/` in the WooCommerce
repository; the relevant behavior is present in both inspected tags.

- [`prepare-form-fields.ts:74` (11.1)](https://github.com/woocommerce/woocommerce/blob/11.1.0/plugins/woocommerce/client/blocks/assets/js/base/components/cart-checkout/form/prepare-form-fields.ts#L74)
  copies the country locale into a **module-level** `countryAddressFields` map, then merges it with
  default fields at `:91`. It takes country, not shipping method. Neither it nor `use-form-fields.ts`
  invokes a checkout registry filter to transform the core field definition.
  [9.9 equivalent](https://github.com/woocommerce/woocommerce/blob/9.9.0/plugins/woocommerce/client/blocks/assets/js/base/components/cart-checkout/form/prepare-form-fields.ts#L70).
- [`use-form-fields.ts:46` (11.1)](https://github.com/woocommerce/woocommerce/blob/11.1.0/plugins/woocommerce/client/blocks/assets/js/base/components/cart-checkout/form/use-form-fields.ts#L46)
  evaluates conditional JSON schemas on **defaultFields**. This machinery exists, but the public
  additional-field registration API cannot replace the core `postcode` or `address_1` defaults:
  `src/Blocks/Domain/Services/CheckoutFields.php:391` requires a namespaced `namespace/name` id;
  `get_core_fields():669` supplies the core booleans without a customization filter.
- [`use-form-validation.ts:218` (11.1)](https://github.com/woocommerce/woocommerce/blob/11.1.0/plugins/woocommerce/client/blocks/assets/js/base/components/cart-checkout/form/use-form-validation.ts#L218)
  skips hidden fields and empty optional fields; otherwise it performs postcode validation and
  returns the error at `:244`. An empty required postcode remains an error even if another
  extension visually hides its input.
- [`form.tsx:194` (11.1)](https://github.com/woocommerce/woocommerce/blob/11.1.0/plugins/woocommerce/client/blocks/assets/js/base/components/cart-checkout/form/form.tsx#L194)
  clears values of hidden fields. A future supported hide mechanism must also account for this
  behavior: it must not silently erase the point address after adoption.

## Alternatives rejected

| Mechanism | Why it does not fulfill this contract |
|---|---|
| `woocommerce_checkout_fields` | Classic only; neither block form nor Store API presence validation consumes it. |
| Conditional `woocommerce_get_country_locale` alone | Can affect server requiredness per request, but the browser retains the initial module-level locale; changing methods does not republish it. A page initially loaded with pickup could also retain hidden fields after switching to courier. |
| `registerCheckoutFilters` / experimental predecessor | A registry works only where core calls `applyCheckoutFilter`; the inspected form pipeline has no field-definition call site. The [documented filters](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/filters-in-cart-and-checkout/) provide no core address required/hidden filter. |
| Additional Checkout Fields API, including conditional schemas | Reactive for registered additional fields; namespaced registration cannot replace the core postcode/street fields. A second postcode field would not relax the original. |
| `woocommerce_blocks_validate_location_address_fields` / checkout validation actions | Additional-field validation receives its own fresh error object (`CheckoutFields.php:1057`); core locale presence errors already live in the controller's separate object. The before-payment custom validation action is reached after core address validation. These hooks can add errors, not override this core rule. |
| Asset registry `defaultFields` override | `CheckoutFields.php:114` owns this key. `src/Blocks/Assets/AssetDataRegistry.php:300,424` disallows replacing an existing key. Preempting core registration with a hand-built field map would depend on internal lifecycle/order and replace WooCommerce-owned data. |
| CSS/DOM hide, changing `required` DOM attributes, clearing WooCommerce validation errors | React's field definitions and validators remain authoritative; a validation-store subscriber would fight core revalidation and hide failures rather than change the policy. |
| Mutating `wcSettings`/internal stores or replacing WC services/components | Not a supported field-policy contract; the locale is already copied, and such patches depend on WC internals. Explicitly excluded by the task. |
| Native local-pickup `collectable` / `prefersCollection` flow | [`use-checkout-address.ts`](https://github.com/woocommerce/woocommerce/blob/9.9.0/plugins/woocommerce/client/blocks/assets/js/base/context/hooks/use-checkout-address.ts#L136) hides the whole shipping form and shows billing for collection. It changes delivery mode/rate handling and still requires the billing postcode. It is not selective postcode/street hiding under the framework's pickup predicate. |

## The stale postcode is a separate, verified source path

`woodev/shipping-method/pickup/class-pickup-handler.php:1926`
(`store_api_replacement_address()`) deliberately filters out empty point values. Its comment
states that a point without a postcode must not blank a required native field. Then
`src/checkout-blocks/pickup-stores.ts:456` (`movedDestination()`) independently discards empty
strings. `wc-stores.ts:148` (`adoptDestination()`) already accepts an explicitly supplied empty
postcode. Thus changing only the latter would not fix the server destination, confirmation
fingerprint, and client adoption together.

Once the field policy has a supported client seam, the tail change needs:

1. Server provenance: clear a previously adopted postcode when the current destination still
   matches that adoption; preserve a postcode the shopper has since typed. Publish the explicit
   empty postcode in the destination and bind the confirmation to the recalculated address.
2. Client acceptance of the explicit empty destination string, with the existing
   `movableAddress()` / `asMovedTo()` / superseded-reply guards intact. Those guards distinguish
   edits **during** a confirmation, not whether a postcode already present **before** the click
   originally came from the shopper. Do not assume they provide persistent adoption provenance.
3. Server tests for same-rate point-with-postcode → point-without-postcode, customer edits,
   replacement off, separate billing, and rollback when the chosen rate disappears; Jest tests
   for empty adoption, sequential confirmations, dirty/clean cart replies and late edits.
4. Pickup → courier → pickup field visibility/requiredness tests and Store API requiredness
   tests with the existing predicate; demonstrate a new test RED before implementation.

No tail fix was shipped independently: it would still leave the order blocked by the required
empty core postcode, and the brief explicitly says to stop at design when one half is unsupported.

**Update, same day (s154):** the operator chose variant A — the postcode stays required on blocks —
and asked for the tail alone. Shipped: `Store_Api_Pickup::replace_destination()` names an empty
postcode when a remembered confirmation of ANY field left the current destination with a postcode
(`Pickup_Selection::recall_moved_destination()`), and `movedDestination()` takes that explicit empty
string. Measured on the rig: live-carrier point (117279) → static-fixture point → postcode empty on
both sides; a postcode typed in between is kept and the order places.

## Result and continuation

No PHP/TS/runtime settings changes, bundles, catalogue entries or visible UI changes. Baseline
gates are recorded in the worker report; they establish repository health, not feature acceptance.
No new behavior test or RED proof is claimed for this design-only result.

The implementation remains open. Revisit when WooCommerce exposes a supported reactive core-field
definition/requiredness API, or when the operator explicitly chooses a different checkout policy
(for example, globally removing postcode, which is already offered on blocks and also affects
courier and billing). Do not unlock `hide_for_pickup` merely because one half can be implemented.

## Related

- [#1113](https://github.com/kalbac/woodev-plugin-framework/issues/1113) — implementation requirement.
- [Country-locale gotcha](../gotchas/block-checkout-reads-country-locale-not-checkout-fields.md) — which seam reaches blocks and the limit of its static data.
- [Server/client requiredness gotcha](../gotchas/the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other.md) — both halves must be demonstrably complete.
- [REST checkout hooks gotcha](../gotchas/the-block-checkout-is-a-rest-request-and-fires-none-of-the-classic-checkout-hooks.md) — classic hooks cannot implement Store API parity.
