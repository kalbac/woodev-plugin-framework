# Pickup trigger: where it is drawn, and what it says

## Overview

The checkout trigger — the «Выбрать пункт выдачи» button that opens the pickup picker — is
split across two owners on purpose:

| Question | Owner | Seam |
|---|---|---|
| **WHERE** the button is drawn | the **framework** | `Checkout_Config::resolve_pickup_slot_placements()`, filter `woodev_pickup_slot_placements` |
| **WHAT** the button says | the **carrier plugin** | filter `woodev_pickup_map_i18n`, keys `trigger` / `triggerChange` |

That split is issue #323's decision. Before it, placement was effectively a domain choice and
the framework's default drew the button in two places at once, so a customer saw two identical
buttons a few pixels apart.

## Where the button is drawn

Two anchors exist, both mounted by `placeSlots()` in
`woodev/shipping-method/assets/js/frontend/checkout-field-classic.js`:

| Placement | DOM position | Mirrors |
|---|---|---|
| `'rate'` | INSIDE the selected rate's own `<li>`, under its label | `woocommerce_after_shipping_rate` |
| `'review'` | right after the `<ul id="shipping_method">`, in the same `<td>` | `woocommerce_review_order_after_shipping` |

**The framework default is `[ 'rate' ]` alone.** Precedence, lowest to highest:

1. the framework default (`[ 'rate' ]`);
2. a framework-level store setting — **not built yet**, deliberately: it belongs to the
   carrier-settings screen being designed separately, and a one-off toggle would have to be
   redone against it;
3. the `woodev_pickup_slot_placements` filter, last.

```php
// A store that wants the pre-#323 position — under the whole methods list.
add_filter( 'woodev_pickup_slot_placements', static function () {
	return [ 'review' ];
} );

// Both at once. Reachable, never a default: this is what #323 removed as a default.
add_filter( 'woodev_pickup_slot_placements', static function () {
	return [ 'rate', 'review' ];
} );

// "I draw my own trigger — mount nothing." An EXPLICIT empty array, and the one case that
// also gets no fallback anchor.
add_filter( 'woodev_pickup_slot_placements', static function () {
	return [];
} );
```

Only `'review'` and `'rate'` ever reach the browser, in that order, each at most once. A
**non-array** return is malformed and reaches the browser as `null`, which makes the client
apply its own mixed-fleet default (`[ 'review' ]` — the pre-#274 behaviour, for a field whose
plugin bundles an older framework copy). A **well-formed empty array** is a decision and is
honoured (issue #308 item 2). The two must never collapse into one value.

### The mandatory fallback

If a non-empty placement list resolves to **no** anchor in the DOM, `placeSlots()` mounts
`'review'` anyway. Stock WooCommerce always renders the `<li>` (with a single shipping method
it only swaps the input type, `radio` → `hidden`), so this guards against a **theme** that
overrode `templates/cart/cart-shipping.php` — without it, such a theme silently carries away
the customer's only way to pick a point, because `placeSlot()` returns quietly when its anchor
is missing.

The explicitly-empty list is excluded from the fallback: it is a decision, not a failed resolve.

### The set of mounted slots is maintained, not just grown

`placeSlots()` runs again on every shipping-method change and on every `updated_checkout`, and
the resolved set can differ between passes — a page whose `'rate'` anchor was unresolvable at
boot (two rates, neither chosen yet) resolves it the moment the customer picks a method. So
after placing, `pruneSlots()` removes the field's slots that are **not** in the set that just
mounted; otherwise the fallback slot and the real one would both be on screen, which is #323's
own symptom.

Two properties of that prune matter to plugin authors:

- **It only ever touches anchors the framework created** — those whose `id` is
  `woodev-pickup-slot-{fieldId}-{placement}`. A plugin that returned `[]` and renders its own
  `[data-woodev-pickup-slot]` anchor keeps it; `[]` means "the framework adds nothing", never
  "the framework deletes yours".
- **Slot contents move, they are not destroyed.** By the time a placement can change, the slot
  holds the mounted trigger and possibly the chosen-point address block, and `pickup-mount.js`
  re-mounts only on `updated_checkout` — so the children are transferred into the surviving
  slot (when it is empty) before the stale node goes.

### Multi-package carts

`templates/cart/cart-shipping.php` is included **once per package**, so a multi-package checkout
has several `<ul id="shipping_method">` blocks (duplicate ids — WooCommerce's own doing) and one
checked radio per package. Both anchor resolutions therefore take `.first()`: the `'rate'` slot
goes beside the first checked rate, which is the same one `selectedShippingMethod()` reads.
Without that, jQuery's `.append()` clones the slot into every package and the customer gets one
button per package.

### Where the chosen-address block goes

`pickup-mount.js` mounts the «Выбранный пункт выдачи: …» block into exactly **one** slot per
field (issue #308 item 4). `resolveAddressSlot()` prefers the slot whose placement equals
`ADDRESS_PLACEMENT` (`'review'`) and otherwise takes the first mounted slot. Under the #323
default there is only one slot, so the second branch is the ordinary path.

## What the button says

The framework never guesses carrier vocabulary. Every customer-facing string of the pickup
layer goes through one filter — `woodev_pickup_map_i18n` in
`Pickup_Handler::get_js_config()` (`woodev/shipping-method/pickup/class-pickup-handler.php`) —
and the trigger's two states are just two keys in that map:

| Key | Default | When |
|---|---|---|
| `trigger` | «Выбрать пункт выдачи» | nothing chosen yet |
| `triggerChange` | «Выбрать другой пункт выдачи» | a point is already chosen |

```php
add_filter(
	'woodev_pickup_map_i18n',
	static function ( array $strings, string $plugin_id ): array {
		if ( 'russian-post' !== $plugin_id ) {
			return $strings;
		}

		// Почта РФ has no «пункты выдачи» — it has отделения.
		$strings['trigger']       = __( 'Выбрать отделение', 'woodev-russian-post' );
		$strings['triggerChange'] = __( 'Выбрать другое отделение', 'woodev-russian-post' );

		return $strings;
	},
	10,
	2
);
```

The same filter carries `emptyLocality`, `chosenPointAddress`, the error strings and the
`aria-label` context strings — one string system, not two. A plugin overrides only the keys it
disagrees with.

### …and what the checkout says when nothing was chosen

That filter covers the browser. The **server-side** validation message is a separate string,
because it is produced by `Checkout_Handler` from the §8 field descriptor, not by the picker:

| Case | Message |
|---|---|
| ordinary typed field, blank | «Укажите значение поля «%s».» |
| `is_pickup_slot` field, blank | «Вы не выбрали пункт выдачи заказов.» |
| the shipping method requires a point but the field's own condition did not match | «Для этого способа доставки нужно выбрать пункт выдачи заказов.» |

The pickup wording is separate because a button-driven field has no value to specify — telling
the customer to fill in a field sends them looking for an input that is not on the page (#327,
found on the rig). A plugin replaces the whole sentence, both paths at once:

```php
Field::create( 'russian_post_office' )
    ->mark_pickup_slot()
    ->set_required_message( __( 'Вы не выбрали отделение Почты России.', 'woodev-russian-post' ) );
```

`set_error_label()` is not enough for this: substituting «Отделение» into a sentence built around
the word «поле» still describes an input.

## Why placement is not the plugin's call

A store may run СДЭК and Почта РФ side by side. If each plugin picked its own placement, the
same checkout would show one carrier's button inside the rate and the other's under the list.
The button's position is a property of **the checkout**, not of a carrier — so it is one rule
for every carrier that delivers to pickup points, set by the framework.

The two reference plugins keep placement in **plugin** settings (Yandex `widget_position`,
Почта РФ `map_button_place`, both defaulting to "under the list"). This framework deliberately
diverges on both counts: the default is "under the rate", and the choice lives at framework
level.

## Related

- [[../gotchas/two-hook-registrations-can-mean-two-options-not-two-outputs.md]] — how #274 item 3 misread the Yandex reference and shipped the double button
- [[../gotchas/a-capability-flag-that-removes-a-ui-layer-silences-every-branch-that-reported-through-it.md]] — the other pickup seam where a framework/domain ownership line matters
- [[capability-gated-feature-seam.md]] — the general shape of an optional, plugin-supplied behaviour
- [[../GOTCHAS.md]] — `[shipping/pickup]` and `[shipping/checkout]` sections
