# Gotcha: [shipping/checkout] — The wizard's address policy replays `woocommerce_default_address_fields`, NOT `woocommerce_checkout_fields`
> Tags: checkout, address-policy, order-wizard, required | Session: s144 (#985, PR #993)

## What happens
A store that relaxes a required address field with the common snippet on `woocommerce_checkout_fields` (e.g. un-requiring
`billing_state` for RU) sees the checkout accept an order without it — while the order wizard (#985) still marks the field required and
the save refuses it with `field_required`. A relaxation made on `woocommerce_default_address_fields` IS honoured by both.

## Root cause
`Checkout_Field_Policy::address_rules()` builds the policy from the store «Fields» settings, the country locale and WooCommerce's
`get_address_fields()` (which applies `woocommerce_default_address_fields`), and deliberately does NOT run `woocommerce_checkout_fields`
(`class-checkout-field-policy.php:~615`): that filter needs a live checkout/cart context and also carries a carrier's own
`Checkout_Fields` declarations, which the wizard does not replay either (#990, operator's call).

## Fix
Nothing to change in code until #990 is decided. When a merchant reports «the wizard demands a field the checkout doesn't», look for a
`woocommerce_checkout_fields` relaxation first; moving it to `woocommerce_default_address_fields` makes both agree.

## Related
- [the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other](the-checkout-required-rule-has-two-halves-and-fixing-one-leaves-the-other.md) — the two halves of "required"
- [../gotcha-index/shipping-checkout.md](../gotcha-index/shipping-checkout.md) — topic index
