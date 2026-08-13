# WooCommerce renders a `<label>` for hidden checkout fields — only `checkbox` is excluded

**Namespace:** `[shipping/checkout]` · **Discovered:** s72 (2026-08-14), adversarial review of PR #316 (#299)

## The false belief

"The field is `type => hidden`, so whatever we put in its `label` is invisible."

Two people in a row wrote that down — once as a docblock rationale in `Checkout_Handler::inject()`,
once as a comment in the rig fixture asserting it as fact — and both were wrong.

## What `woocommerce_form_field()` actually does

Read in WooCommerce 10.4.3, `includes/wc-template-functions.php`:

| Line | Behavior |
|---|---|
| `case 'hidden':` | produces a **non-empty** `<input type="hidden">` string |
| `if ( ! empty( $field ) )` | therefore true for hidden fields |
| `if ( $args['label'] && 'checkbox' !== $args['type'] )` | emits `<label>` — **only checkbox is excluded** |
| container | the ordinary `<p class="form-row" id="{id}_field">`; only the states-less `state` case gets a `display: none` wrapper |

For hidden fields WC drops the `for` attribute and nothing else. So:

```html
<!-- what a non-empty label on a hidden field produces -->
<p class="form-row" id="carrier_pickup_point_field">
  <label class="">Пункт выдачи</label>
  <span class="woocommerce-input-wrapper"><input type="hidden" id="carrier_pickup_point" …></span>
</p>
```

An orphan caption with nothing under it — and in the wrong place, because the field's real control
(the "Выбрать пункт выдачи" button) is mounted into its own slot next to the shipping methods.

## Why this bit us

#299 asked for human wording in the pickup field's validation messages. The natural-looking fix was
"give the field a label so both our message and WooCommerce's own read nicely". Two independent
mistakes rode on that:

1. **A blank label is the CORRECT state for this field**, not an oversight — the visible control is a
   button. Filling the label to fix a *message* changes what is *rendered*.
2. **WooCommerce's own message never appears anyway.** `inject()` forces WC's `required` flag to a
   static `false` whenever the descriptor's `required` is a condition-spec array, which the pickup
   preset always uses. So the label was being handed over to improve a message that cannot fire.

Net effect of the "fix" would have been: zero message improvement, one new visible caption on every
checkout of every pickup plugin.

## The rule

**Separate the label you RENDER from the label you put in MESSAGES.** A field descriptor needs both,
and they are not the same field. Give messages their own `error_label` and leave the visual `label`
empty when the control is not the input itself. Never hand a messages-label to
`woocommerce_checkout_fields` "because it's hidden anyway".

## How to check it in five seconds

Do not reason about it — grep WooCommerce's own source for the label condition:

```bash
grep -n "!== \$args\['type'\]" /path/to/woocommerce/includes/wc-template-functions.php
```

If `hidden` is not in that condition, hidden fields get labels.

## Related

- [[a-locality-display-name-is-not-an-identifier]] — the other "this string is for humans, that one
  is not" confusion in the same layer
- `woodev/shipping-method/checkout/class-checkout-handler.php` → `inject()`, `message_label()`
- `woodev/shipping-method/checkout/presets/class-pickup-field.php` → `create()` seeds `error_label`
