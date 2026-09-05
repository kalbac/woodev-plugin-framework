# Gotcha: [php/money] — a remaining-capacity bound computed by raw float subtraction refuses the final cent
> Tags: php, money, float, payment-gateway, capture | Session: s120

## What happens

A guard that bounds a requested amount against *what is left* — `maximum − already_used` — looks
obviously correct and passes every test written with round numbers. Then a merchant who has
partially captured **9.99** of a **10.00** authorization tries to take the remaining **0.01** and is
refused, with a message telling them that one cent exceeds a remainder that prints as one cent. They
can never capture the rest.

Measured, in plain PHP:

```
$remaining = 10.00 - (float) "9.99";   // 0.00999999999999978684
$amount    = (float) "0.01";           // 0.01000000000000000021
$amount > $remaining                   // true  → refused
```

The subtraction is the only new thing in the guard, and it is what breaks it. Both operands are
exact-looking two-decimal money; their *difference* is not representable in binary floating point,
and it lands just below the cent rather than on it.

## Root cause

Neither `10.00` nor `9.99` is exact in IEEE-754 binary, so their difference carries the accumulated
error of both. The comparison then happens at full double precision, where "one cent" and "one cent
minus 2×10⁻¹⁷" are different numbers.

Round numbers hide it completely: `100 − 60 = 40` is exact, so a test suite built on 60/40/50
against 100 — which is what the first two rounds of #781 had — passes with the defect present. The
failure needs operands whose decimal parts do not sum to a representable value, which is most real
money.

## Fix

❌ Wrong — the difference is compared at double precision:

```php
$capture_remaining = $this->get_order_capture_maximum( $order ) - (float) $this->get_gateway()->get_order_meta( $order, 'capture_total' );

if ( (float) $order->capture->amount > $capture_remaining ) {
	throw new Woodev_Payment_Gateway_Exception( $message, 400 );
}
```

✅ Correct — the difference is re-formatted to the same precision as the values it is compared with:

```php
$capture_remaining = (float) Woodev_Helper::number_format( $this->get_order_capture_maximum( $order ) - (float) $this->get_gateway()->get_order_meta( $order, 'capture_total' ) );
```

**Round to the PIPELINE's precision, not to the vendor's.** In this framework the whole capture path
is already two-decimal by construction: `Woodev_Payment_Gateway::get_order_for_capture()` formats
`$order->capture->amount` with `Woodev_Helper::number_format()`, and
`Capture_Handler::do_capture_success()` stores `capture_total` with it. Reaching for
`wc_get_price_decimals()` instead would let the guard disagree with the value it guards — which is
the same class of bug one layer up.

## The test that proves it

A boundary test written with round numbers does not discriminate. This one does, and it was run in
both directions before the fix was accepted:

```php
$order  = $this->make_capturable_order( 10.0 );
$first  = $this->handler->perform_capture( $order, 9.99 );   // ok, capture_total = '9.99'
$second = $this->handler->perform_capture( $order, 0.01 );

$this->assertTrue( $second['success'], 'the remaining cent must be capturable' );
```

| against | result |
|---|---|
| raw subtraction | ❌ `Failed asserting that false is true` |
| formatted difference | ✅ `capture_total` reaches `10.00` |

`tests/unit/PaymentGatewayCaptureAmountBoundsTest.php` →
`test_the_final_cent_of_a_partially_captured_authorization_is_capturable`.

## How it was caught

By a Codex critic pass over the finished PR, then verified by the coordinator's own measurement
before being acted on — the standing rule, and it paid twice in this card: the coordinator's earlier
measurement had found the accumulation gap the critic did not, and the critic found this one the
coordinator did not. Neither pass alone would have closed #781.

## Related

- [stringifying-a-float-cost-lets-wc-format-decimal-destroy-it](stringifying-a-float-cost-lets-wc-format-decimal-destroy-it.md) — the other money-formatting trap in this codebase
- `docs-internal/AGENT-RULES.md` → Rule 0 — why the capture amount pipeline's own convention wins over a vendor helper
