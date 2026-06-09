# Capability-Gated Feature Seam

> **Reference pattern (not a hard mandate).** Where it applies and is justified, build
> optional behaviour this way. The `payment-gateway` module is the gold-standard
> exemplar (`PLANS.md` §3.2 names it the architectural reference for shipping); the
> `shipping-method` box-packing seam (s3, 2026-06-09) is the newest instance.
> Decision record: [ADR-006](../adr/006-capability-gated-feature-seam.md).

## What it is

Put **optional** feature behaviour directly inside a method the flow is **guaranteed to
call**, run it **only** when the object declares the matching capability, and delegate the
domain-specific work to a **named seam**. When the capability is not declared, the branch is
inert — zero cost, zero behaviour change.

It is the Template Method pattern fused with an explicit capability vocabulary and an
inert-by-default contract. The base owns *orchestration*; the concrete class owns
*specifics* through the seam.

## The five properties

A construct is a Capability-Gated Feature Seam when all of these hold:

1. **Guaranteed invocation point.** The logic lives in a method the flow always calls
   (a WC/WP hook like `payment_fields()`, `process_refund()`, `init_form_fields()`, or a
   framework template like `Shipping_Method::calculate_rate()`), not a helper a subclass
   must remember to call.
2. **Opt-in via a capability flag.** The extra behaviour runs only when declared — via
   `supports( self::FEATURE_* )` (ideally through a named predicate wrapper, see below).
3. **Base owns orchestration; subclass owns specifics.** The guaranteed method is a thin
   dispatcher that branches on the capability and delegates the real work to a seam.
4. **Inert-by-default.** Not declaring the capability is the zero-cost identity path —
   nothing runs, nothing changes.
5. **Structurally protected where it matters.** When bypassing the wiring would be a bug,
   make the guaranteed method `final` so the guarantee cannot be silently overridden away.

## Canonical examples (payment-gateway — the reference)

### Capability vocabulary
`class-payment-gateway.php` declares a `FEATURE_*` constant per capability
(`FEATURE_TOKENIZATION`, `FEATURE_REFUNDS`, `FEATURE_VOIDS`, `FEATURE_PAYMENT_FORM`,
`FEATURE_CREDIT_CARD_CHARGE`/`_AUTHORIZATION`/`_CAPTURE`, `FEATURE_CARD_TYPES`,
`FEATURE_ADD_PAYMENT_METHOD`, `FEATURE_CUSTOMER_ID`, …) plus the
`add_support()`/`remove_support()`/`set_supports()` machinery over WooCommerce's
`supports()`. The plugin class declares its own scope of features
(`Woodev_Payment_Gateway_Plugin::FEATURE_CAPTURE_CHARGE`, `FEATURE_MY_PAYMENT_METHODS`).

### Guaranteed method gated by a predicate → delegates to a seam
```php
// Woodev_Payment_Gateway::payment_fields() — always called at checkout.
public function payment_fields() {
    if ( $this->supports_payment_form() ) {        // capability predicate
        $this->get_payment_form_instance()->render(); // seam (handler object)
    } else {
        parent::payment_fields();                  // inert-by-default
    }
}
```

```php
// Woodev_Payment_Gateway::init_form_fields() — always called in the constructor.
if ( $this->supports_tokenization() ) {
    $this->form_fields = $this->add_tokenization_form_fields( $this->form_fields ); // seam (named method)
}
if ( $this->supports( self::FEATURE_DETAILED_CUSTOMER_DECLINE_MESSAGES ) ) {
    $this->form_fields['enable_customer_decline_messages'] = [ /* ... */ ];
}
```

```php
// Woodev_Payment_Gateway::process_refund() — guaranteed WC refund flow.
if ( $this->supports_voids() && ! $this->get_capture_handler()->is_order_captured( $order ) ) {
    return $this->process_void( $order );          // capability changes the path
}
$response = $this->get_api()->refund( $order );    // seam (injected API interface)
// ... then the overridable stub seam:
$this->add_payment_gateway_refund_data( $order, $response ); // empty by default; subclass overrides
```

### The newest instance (shipping-method, s3)
```php
// Shipping_Method::calculate_rate() — final, called by final calculate_shipping().
final protected function calculate_rate( array $package ): ?Shipping_Rate {
    $packed = $this->supports( self::FEATURE_BOX_PACKING ) // capability gate
        ? $this->pack_package( $package )                 // seam (named method)
        : null;                                           // inert-by-default
    return $this->rate_package( $package, $packed );      // seam (abstract method)
}
```

## The seam is a *family*, not one fixed shape

Property #3 says "delegate to a seam" — the seam's form varies, and all are valid:

| Seam form | Example |
|-----------|---------|
| Abstract method | `Shipping_Method::rate_package()`; gateway `get_api()` |
| Empty-stub overridable hook | `add_payment_gateway_refund_data()` (no-op default), `maybe_void_instead_of_refund()` (returns false) |
| Handler object (factory) | `get_payment_form_instance()`, `get_payment_tokens_handler()`, `get_capture_handler()` |
| Injected API interface | `get_api()->refund()`, `->credit_card_charge()`, `->tokenize_payment_method()` |

The invariant is constant: **the base never makes a domain decision it cannot make
correctly.** (See the shipping rule: the framework packs, but never sums per-parcel
prices — that is the carrier's tariff decision. Gotcha
[[shipping-rate-no-parcel-sum]].)

## Conventions that make it read well

- **Wrap `supports( self::FEATURE_X )` in a named predicate** (`supports_tokenization()`,
  `supports_refunds()`) when the capability is checked in more than one place. One point of
  change, a self-documenting capability surface, and guaranteed methods read as a list of
  intent-named gates. (The s3 shipping seam uses the raw `supports( self::FEATURE_BOX_PACKING )`
  at its single call site — acceptable, but a `supports_box_packing()` predicate is the
  convention if it grows a second caller.)
- **Keep the guaranteed method a thin orchestrator.** Each feature's logic goes in its own
  protected method (`pack_package()`, `add_tokenization_form_fields()`), so the hot path is a
  list of gated delegations, not stacked inline blocks. This is what keeps a large class
  (`class-payment-gateway.php`, ~2378 lines) from becoming a god-method despite its size.
- **Declare the capability at the right scope.** Per-instance behaviour → gate on the
  gateway/method; behaviour that applies to every gateway in a plugin (e.g. capture-charge
  admin UI) → gate on the plugin (`Woodev_Payment_Gateway_Plugin::supports_capture_charge()`).

## Two valid gate placements

1. **At the guaranteed method** — `payment_fields()` checks `supports_payment_form()`
   inline. Right when the feature is a short branch in an existing flow.
2. **Inside an always-constructed handler that self-gates** — the payment-tokens handler is
   always instantiated, but only registers its hooks when `supports_tokenization()`. Right
   when the feature is a cohesive subsystem with its own hooks/lifecycle. Still the pattern —
   the gate just lives one level down, in the handler's own guaranteed entry (its
   constructor/registration).

## When NOT to use it

- **Unconditional behaviour** — if it must always run, no flag; just put it in the
  guaranteed method.
- **A standalone subsystem with an independent lifecycle** (REST routes, cron, a webhook
  endpoint) — prefer a dedicated class + registration over a branch in a hot-path method,
  or use placement #2 (self-gating handler).
- **Don't gate with ad-hoc booleans or scattered option reads.** Use the `supports()`
  capability vocabulary so the surface is discoverable and uniform. A `$is_x` constructor
  flag that the base branches on is the anti-pattern (see also
  [v2 Extension-Point Pattern](v2-extension-point-pattern.md), which rejects
  `$is_payment_gateway`-style flags on the base).

## Related
- [ADR-006: Capability-Gated Feature Seam](../adr/006-capability-gated-feature-seam.md) — the decision
- [v2 Extension-Point Pattern](v2-extension-point-pattern.md) — sibling pattern; hook ownership follows the class, no flavour flags on the base
- `docs-internal/platform-v2-s3-shipping-rate-packing-spec.md` — the shipping box-packing instance
- [[shipping-rate-no-parcel-sum]] — the "base owns orchestration, not domain decisions" rule, in gotcha form
- Source exemplars: `woodev/payment-gateway/class-payment-gateway.php`, `class-payment-gateway-direct.php`, `class-payment-gateway-plugin.php`
