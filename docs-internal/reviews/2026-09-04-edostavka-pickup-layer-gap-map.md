# Edostavka pickup/checkout layer — gap map for the step-2 decision

> Written 04.09.2026 (s115, overnight) for the one decision that blocks BOTH remaining cards of the
> pilot's first pass (`edostavka#2` and `edostavka#3`). It does not choose — it measures, so the
> choice is made on facts. Source of the framework side: `woodev/shipping-method/{checkout,pickup,
> location}`. Source of the plugin side: `D:/Projects/wordpress/woocommerce-edostavka`.

## The decision this feeds

`WD_Edostavka_Shipping` extends `WC_Shipping_Method` directly and the plugin carries its **own,
complete** pickup + location implementation. The framework carries another one, ten times larger.
Both work. The question is whether the pilot replaces the plugin's with the framework's, or keeps
the plugin's and limits the migration to the method/plugin base.

## Size, measured

| | lines |
|---|---|
| Plugin: `class-wc-edostavka-checkout.php` | 1 269 |
| Plugin: `class-wc-edostavka-ajax.php` | 894 |
| Plugin: own DaData client (`api/class-wc-edostavka-dadata-*.php`) | 3 classes |
| **Plugin total, the part in scope** | **~2 163 + DaData** |
| Framework: `shipping-method/{pickup,location,map}` | **21 404** |
| Framework: `Shipping_Method` + `Shipping_Plugin` base | 2 084 |

## What the plugin's checkout class actually does — all 22 methods, bucketed

| Responsibility | Plugin methods | Framework counterpart | Verdict |
|---|---|---|---|
| Pickup button + map template + assets | `add_delivery_points_button`, `add_map_template`, `enqueue_scripts` | `Pickup_Handler`, `pickup/` (map settings, point sources, selection) | **Covered, and far more so** |
| Address fields, locale formats, notices | `default_address_fields`, `localisation_address_formats`, `add_notice_before_checkout_filed` | `Checkout_Fields`, `Checkout_Field_Policy`, `Checkout_Field_Environment`, `Field` | **Covered** |
| Posting, validation, order creation, customer | `checkout_posted_data`, `validate_checkout`, `checkout_create_order`, `created_customer` | `Checkout_Handler`, `Pickup_Selection`, `Address_Target`, `Customer_Location_Store` | **Covered** |
| Cart weight/dimensions/packages | `get_cart_contents_weight`, `get_cart_goods_package`, `get_cart_items_packages`, `get_cart_contents_dimensions`, `cart_shipping_packages` | box-packer (`Woodev_Packer*`) — **the plugin already uses it** | Already shared |
| Rate extra info, review fragments | `shipping_rate_additional_information`, `update_order_review_fragments`, `handle_woocommerce_checkout_update_order_review` | partly `Checkout_Handler`; fragments are the plugin's own | **Partial** |
| Order-table details, delivery statuses | `add_details_after_order_table`, `add_delivery_order_statuses` | none | **Plugin-only, domain** |
| Filtering available payment gateways | `get_available_payment_gateways` | none (#713 added COD/insurance FLAGS only, deliberately no logic) | **Plugin-only, domain** |

## What each side has that the other does not

**Only the framework** — this is what the migration would BUY:

- A provider-agnostic location layer: registry, adapters, fallback between providers, `Location_Record`, `Locality_Key`, scope narrowing, resolution cache.
- Popular settlements: store, verifier, verification records, admin tools.
- The pickup map layer as a product: point sources behind an interface, selection scope, constraint checker, address target resolution, map settings.
- Everything ~100 sessions of rig work hardened — the two-carrier arrangement, the checkout field policy, phone masks, the required-rule halves.

**Only the plugin** — this is what a naive replacement would LOSE:

- Delivery statuses and the order-table detail block (domain).
- Payment-gateway filtering tied to the delivery choice (domain — COD).
- Its own DaData client, which is not the same thing as the framework's `dadata` location provider: the plugin uses it for its own cascade.
- 117 references to `region_code`/`city_code` — a full region→city cascade of its own.

## Contracts this touches — the part that decides the price

| Contract | Plugin today | Framework | Consequence |
|---|---|---|---|
| Customer location storage | data stores `customer-location`, `customer-location-session` | ONE key `woodev_customer_location`, session + user meta (`class-customer-location-store.php:69`) | **Keys differ.** Moving means saved customer locations do not carry over. ⚠ The migration checklist rates these "Preserve **or reset intentionally**" — so a deliberate reset is allowed, unlike the settings option. |
| Settings option | `woocommerce_edostavka_settings` (one array, `WC_Integration`) | `Shipping_Integration` keeps the same WooCommerce model | **Safe** — `Shipping_Integration` extends `WC_Integration`. The framework's own `Woodev_Abstract_Settings` (one option per setting, `woodev_{id}_{setting}`) is a DIFFERENT subsystem and must NOT be used here — it would break a release-blocking contract. |
| `method_id`, email ids, order meta | see the checklist | unchanged by this decision | Not at risk from step 2 itself |

## The options, with what each costs

1. **Full move onto the framework layer.** Buys everything in "only the framework" above and makes
   the pilot answer the question it was created to answer. Costs: rewriting ~2 163 lines plus the
   DaData client, re-implementing the two domain-only responsibilities on framework seams, and a
   deliberate reset of saved customer locations. Highest risk, highest information.
2. **Base only — `Shipping_Plugin` + `Shipping_Method` + `Shipping_Integration`, keep the plugin's
   own pickup/location code.** Unblocks `edostavka#3` too, keeps all contracts, and is a much
   smaller change. Costs: the pilot then does NOT test the pickup layer against a real plugin,
   which was a stated reason for running it.
3. **Base now, pickup layer as a separate, later card.** Sequences 2 and 1. Slowest to the answer,
   but each step stays reversible and the operator sees a working plugin between them.

⚠ Option 2 and 3 both still require `Shipping_Plugin`, because `Shipping_Integration` demands it
(`class-shipping-integration.php:53,143,470`). There is no variant that touches nothing.

## Related

- [`edostavka#2`](https://github.com/kalbac/woocommerce-edostavka/issues/2) — the card this decides
- [`edostavka#3`](https://github.com/kalbac/woocommerce-edostavka/issues/3) — blocked by the same decision
- [migration/edostavka-data-preservation-checklist.md](../migration/edostavka-data-preservation-checklist.md) — the contracts quoted above
- [sessions/s115.md](../sessions/s115.md) — how the pilot got here
