# Gotcha: [shipping/checkout] — The wording of a checkout error says WHO rejected the order, and a required field can be enforced without ever being rendered
> Tags: shipping, checkout, validation, diagnosis | Session: s110

## What happens

A checkout is blocked by an error naming a field that is **not on the page**. The operator reports
the message; a first reading assumes the field must be there somewhere, and the hunt starts in the
wrong place — in rendering, in CSS, in the client adapter — while the cause is in validation.

Worse, the error can name the field by OUR label while WooCommerce's own settings have removed that
field from the form entirely.

## Root cause

Two independent facts.

**1. Two different layers emit required-field errors, and their wording differs.** This is the
fastest way to tell them apart, and it costs one grep:

| message | who |
|---|---|
| `… is a required field.` | WooCommerce (`WC_Checkout::validate_checkout()`) |
| `Please fill in the “…” field.` | **us** — `Checkout_Handler::required_message()` |
| `You have not chosen a pickup point.` | **us** — the pickup backstop in `Checkout_Handler::validate()` |
| `This shipping method requires you to choose a pickup point.` | **us** — same backstop, other branch |

**2. Rendering and validation walk different sets.** `Checkout_Handler::inject()` deliberately
SKIPS fields carrying a `takeover_condition` (they are "owned entirely by the CLIENT"), while
`validate()` iterates `effective_fields()` — every declared descriptor — and enforces `required` on
all of them. A field that is skipped for rendering and enforced for validation cannot be filled by
anyone. See card #708.

## Fix

✅ Read the wording first, then look in the right layer:

```bash
grep -rn "Please fill in" woodev/          # ours  -> Checkout_Handler::validate()
grep -rn "is a required field" woodev/     # none  -> WooCommerce's own validator
```

✅ When the error names a field you cannot see, ask whether it is RENDERED at all before assuming it
is hidden. The two are different states and the fix differs:

```php
$rendered  = WC()->checkout()->get_checkout_fields( 'billing' );   // what the customer can fill
$effective = /* Checkout_Handler::effective_fields() */;           // what validate() enforces
```

A field present in the second and absent from the first is an unfillable required field.

❌ Do not conclude from an error message that the customer can see the field. In s110 that inference
was written onto a card and into the rig wiki as if it were an observation; the operator had only
ever reported the ERROR. He had to correct it.

## Related

- [a-source-asserting-test-breaks-on-mechanical-reformatting](a-source-asserting-test-breaks-on-mechanical-reformatting.md) — the other s110 case where the symptom pointed at the wrong layer
- [rig-checkout-url-is-the-block-checkout](rig-checkout-url-is-the-block-checkout.md) — another "looks broken, is actually the wrong surface" trap on the same page
