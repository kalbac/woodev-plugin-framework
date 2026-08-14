# Two hook registrations in a reference can mean two OPTIONS, not two outputs

**Namespace:** `[shipping/pickup]`
**Found:** s73 (14.08.2026), by the operator on the rig. Issue #323; shipped by #274 item 3 / PR #308.

## Root cause

Issue #274 item 3 argued that the framework's pickup trigger should be drawable in two places,
and cited the Yandex reference plugin as evidence: it registers the button under **two**
actions.

```php
// includes/class-checkout.php:12-13 — the two registrations the card counted
add_action( 'woocommerce_review_order_after_shipping', [ $this, 'add_delivery_points_button' ] );
add_action( 'woocommerce_after_shipping_rate',         [ $this, 'after_shipping_rate' ], 10, 2 );
```

Two registrations were read as "this plugin draws the button in two places". The **guard inside
each handler** says otherwise:

```php
// includes/class-checkout.php:200 — first statement of the handler
public function add_delivery_points_button() {

    if ( 'under_methods' !== wc_yandex_shipping()->get_integration_option( 'widget_position', 'under_methods' ) ) {
        return;                                       // ← not my position: draw nothing
    }

// includes/class-checkout.php:334 — inside after_shipping_rate(), whose own job is wider
// (it also renders the rate's commission meta), the button is gated on the OTHER value
if ( ( is_checkout() && ! is_cart() ) && 'inside_method' == wc_yandex_shipping()->get_integration_option( 'widget_position', 'under_methods' ) ) {
```

`widget_position` is a store **setting** with exactly two values
(`includes/class-integration.php:175`, `type => select`, `default => under_methods`, «Расположение
кнопки/списка», options «Под общим списком всех методов доставки» / inside the method). The two
hooks exist because the plugin supports two **positions**; on any given request exactly one of them
produces a button. Почта РФ has the identical shape under `map_button_place`.

Note that the second hook is not even a button handler — `after_shipping_rate()` renders rate meta
and the button is one branch deep inside it. Counting registrations does not survive contact with
the file.

**None of the three reference plugins ever shows the customer two buttons.** The framework did:
with the default `[ 'review', 'rate' ]` and a short methods list, both anchors land in the same
table cell a few pixels apart, and the customer sees «Выбрать пункт выдачи» twice, one under the
other.

## ❌ Wrong

Counting registration sites and stopping there.

> Yandex registers the button on two hooks → the button belongs in two places → default to both.

The premise is a real, verifiable fact about the reference. The conclusion does not follow from
it, and nothing downstream re-checked, because the fact was true.

## ✅ Correct

Read the **body** of every handler a reference registers, far enough to find what gates its
output. Registration answers "where CAN this run"; the guard answers "when DOES it". A settings
read is the tell that the sites are alternatives rather than a set — and it is not reliably the
first line: here one handler opens with it and the other buries it ~60 lines in, past unrelated
work, because drawing the button is only part of that handler's job.

Cross-check with the outcome: if two registrations really did mean two outputs, a customer of
that reference would see the control twice. Nobody ships that. When your reading of a reference
implies a defect the reference does not have, the reading is wrong.

## Cost

Shipped to `main` in PR #308 and found by the operator on the rig, not by tests. The layer was
not under-tested: five jest cases pinned the placement anchors and seven PHPUnit tests pinned the
resolver — and the one named after the default,
`test_pickup_slot_placements_default_to_both_review_and_rate`, asserted **both** placements,
faithfully pinning the wrong decision. No test can catch this class of error, because the code did
exactly what it was designed to do; the design was wrong.

Fixed in #323: the default is `[ 'rate' ]` alone, placement moved from domain to framework, and
the `woodev_pickup_slot_placements` filter keeps the both-at-once configuration reachable.

## Related

- [[../wiki/pickup-trigger-placement-and-text.md]] — the resulting contract: framework owns WHERE, plugin owns the TEXT
- [[a-constant-field-cannot-be-a-verdict.md]] — the same family: a card stated a fact about a third-party contract, the fact was recorded correctly, and the mapping built on it was still wrong
- [[an-invented-fixture-tests-your-assumptions-not-the-carrier.md]] — a recorded CONTRACT is not a recorded PAYLOAD
- [[../GOTCHAS.md]] — `[shipping/pickup]`
