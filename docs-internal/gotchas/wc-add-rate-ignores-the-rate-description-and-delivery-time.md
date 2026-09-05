# Gotcha: [woocommerce/shipping] — `add_rate()` silently ignores `description` and `delivery_time`, which `WC_Shipping_Rate` does support

> Tags: woocommerce, shipping, rates, store-api | Session: s117

## What happens

You want a shipping rate to carry a description — "Отправление со склада в Москве", a delivery
estimate, anything beyond the label. `WC_Shipping_Rate` has exactly the setter you need:

```php
$rate->set_description( $text );    // WooCommerce 9.2.0+
$rate->set_delivery_time( $text );  // WooCommerce 9.2.0+
```

So you pass the key through `add_rate()` the way you pass `meta_data`:

```php
$this->add_rate( [
	'id'          => $id,
	'label'       => $label,
	'cost'        => $cost,
	'description' => 'Отправление со склада в Москве',   // ❌ discarded, no message
] );
```

Nothing happens. No warning, no notice, no log line. `wp_parse_args()` keeps your key in the args
array, the `woocommerce_shipping_method_add_rate_args` filter sees it, and then `add_rate()` builds
the rate object and never looks at it again.

## Root cause

`WC_Shipping_Method::add_rate()` constructs the `WC_Shipping_Rate` itself and sets exactly seven
things on it — measured against WooCommerce 11.0.1:

```php
$rate = new WC_Shipping_Rate();
$rate->set_id( $args['id'] );
$rate->set_method_id( $this->id );
$rate->set_instance_id( $this->instance_id );
$rate->set_label( $args['label'] );
$rate->set_cost( $total_cost );
$rate->set_taxes( $taxes );
$rate->set_tax_status( $this->tax_status );
// + add_meta_data() per meta_data pair, + an "Items" meta row when a package was passed
```

`description` and `delivery_time` were added to `WC_Shipping_Rate` in 9.2.0 and to the Store API's
`CartShippingRateSchema`, but `add_rate()` was never taught about them. Its own default list is
eight keys and neither is in it: `id`, `label`, `cost`, `taxes`, `calc_tax`, `meta_data`, `package`,
`price_decimals`.

Two further facts that are easy to get wrong in the same breath:

- **`WC_Shipping_Method` has no `$description` property at all.** It has `$method_description`,
  which describes the method TYPE to the merchant on the settings screen — a different thing, in a
  different place, read by a different audience.
- **The CLASSIC checkout renders neither.** `templates/cart/cart-shipping.php` prints
  `wc_cart_totals_shipping_method_label( $method )` and fires `woocommerce_after_shipping_rate`,
  and that is all. The description surfaces through the Store API, i.e. on the BLOCK checkout. So
  "the customer will see it during checkout" is only half true, and merchant-facing copy that
  promises it flatly is wrong.

## Fix

Apply the attribute to the rate object AFTER `add_rate()` has created it. `$this->rates` is keyed
by the rate id, and the entry there is post-`woocommerce_shipping_method_add_rate`, so it is the
object WooCommerce will actually use:

```php
$this->add_rate( $rate->to_array() );

$wc_rate = $this->rates[ $rate->get_id() ] ?? null;

if ( $wc_rate instanceof \WC_Shipping_Rate && method_exists( $wc_rate, 'set_description' ) ) {
	$wc_rate->set_description( $description );
}
```

Probe the setter with `method_exists()` rather than assuming it: the framework supports WooCommerce
from 7.0, where neither setter exists. CI proves this matters — the `WP 6.4 / WC 8.5.1 / PHP 8.1`
job runs against a WooCommerce that predates both.

In this framework the whole thing lives in `Shipping_Method::apply_rate_attributes()`, and
`Shipping_Rate::POST_ADD_RATE_ATTRIBUTES` names the two keys that must be held back from
`to_array()` — emitting them into the `add_rate()` array would look like wiring while doing nothing.

## Related

- [stringifying-a-float-cost-lets-wc-format-decimal-destroy-it](stringifying-a-float-cost-lets-wc-format-decimal-destroy-it.md) — the other `add_rate()` trap found the same hour
- [every-fixture-omitting-an-optional-argument-leaves-a-branch-unexecuted](every-fixture-omitting-an-optional-argument-leaves-a-branch-unexecuted.md) — why no fixture caught the inert Description field
- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — why this had to be pinned by an integration test
- Cards #768, #766 · PR #769
