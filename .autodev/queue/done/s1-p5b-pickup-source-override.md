---
id: s1-p5b-pickup-source-override
title: Wire get_pickup_point_source() override into Shipping_Method_Pickup
phase: P5 Method enhancement
type: build
touches_contract_zone: true
writes_guard: false
file_set:
  - woodev/shipping-method/class-shipping-method-pickup.php
depends_on: [s1-p5-method-enhance]
contract_zones_touched: [shipping_method_id]
needs_guard: no
acceptance:
  - composer phpstan green
  - unit test (Brain Monkey): a pickup method's get_pickup_point_source() returns its get_point_source()
---

# Task

Follow-up split from `s1-p5-method-enhance` (operator approved the TOO_BIG split, 2026-06-06).
The base `Shipping_Method::get_pickup_point_source(): ?Pickup_Point_Source` null-default accessor
landed in commit b80081c, but it is an INERT seam: `Shipping_Method_Pickup` already declares
`abstract protected function get_point_source(): Pickup_Point_Source` yet does NOT override the new
public accessor, so every pickup instance returns null and the seam cannot fulfil its purpose
(reach a pickup method's normalizing `Pickup_Point_Source` from shared subsystems).

Add the one-line override to `Shipping_Method_Pickup`:

```php
public function get_pickup_point_source(): ?Pickup_Point_Source {
    return $this->get_point_source();
}
```

(Add the `use Woodev\Framework\Shipping\Pickup\Pickup_Point_Source;` import if not already present.)

`shipping_method_id` zone is path-glob match only — no `$this->id =`, `method_id`, or
`woocommerce_shipping_methods` line is touched; no contract string changes. Additive.

<!-- committed: e5a9e98 -->
