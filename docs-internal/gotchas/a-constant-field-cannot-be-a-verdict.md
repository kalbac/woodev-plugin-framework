# A field that never varies cannot be a verdict

**Namespace:** `[shipping/pickup]`
**Found:** s58 (09.08.2026), on the live Russian Post source. Issue #233.

## Root cause

Issue #226 recorded the Pochta contract and stated, in as many words:

> `cashPayment` (bool) — **this IS** `accepts_cod`

So the fixture mapped it straight through:

```php
'accepts_cod' => isset( $raw_point['cashPayment'] ) ? (bool) $raw_point['cashPayment'] : null,
```

Live data refutes it. Measured across 12 real points:

| type | `cashPayment` | `cardPayment` |
|---|---|---|
| `russian_post` ×6 | `false` | `false` |
| `postamat` ×6 | `false` | `false` |

**Every single point, both types, both fields: `false`.** The consequence reached the customer:
every post office refused cash on delivery, which is backwards — offices take it, parcel lockers
do not. The operator, who sells through this carrier, spotted it immediately: «обычно наоборот».

The real rule lives in his own production plugin
(`plugins-reference/woodev-russian-post`, `includes/classes/checkout.php`):

```php
if ( 'cod' == $data['payment_method']
     && ( 'postamat' == $customer_delivery_point->get_type()
          || $customer_delivery_point->get_mail_type() == 'ECOM_MARKETPLACE' ) ) {
    // refuse
}
```

COD follows the point's **type**, not any payment flag the listing API exposes.

## The rule

**Before mapping a third-party field onto a customer-facing verdict, look at its SPREAD across
real records.** A field that is constant across every record you can see carries no information,
whatever its name promises. It cannot be the thing that distinguishes one point from another,
because it does not distinguish anything.

This is cheap to check — one loop over a page of live records — and it is the difference between
a verdict and a decoration.

Corollaries worth keeping:

- **A field NAME is a claim, not evidence.** `cashPayment` reads exactly like "accepts cash on
  delivery". It may well mean "this merchant's widget is configured to offer cash", or something
  else entirely; the name cannot tell you and the vendor's docs may not either.
- **A written contract is not a measurement.** The wording that caused this was in our own issue,
  confidently phrased, and it propagated into code and a docblock before anything checked it.
  Same family as [[an-invented-fixture-tests-your-assumptions-not-the-carrier]].
- **When a domain expert says the behaviour is backwards, believe the direction first.** He was
  right about the direction before either of us knew the mechanism.
- **Prefer the domain's own working implementation over an API field that looks convenient.** The
  production plugin already encoded the real rule and had done for years.

## ❌ Wrong

```php
// The API has a field with the right-sounding name, so use it.
'accepts_cod' => (bool) $raw_point['cashPayment'],
```

## ✅ Correct

```php
// COD IS DECIDED BY POINT TYPE, NOT BY `cashPayment` — see the file docblock's own
// CASH ON DELIVERY section for the measurement and for the authority (the operator's
// own production plugin) behind this rule.
'accepts_cod' => 'postamat' !== (string) ( $raw_point['type'] ?? '' ),
```

## Related

- [[an-invented-fixture-tests-your-assumptions-not-the-carrier]] — the sibling from the session
  before: a recorded contract is not a recorded payload.
- [[a-control-that-changes-the-subject-must-announce-it]] — the other half of the same report.
