# Gotcha: [shipping/pickup] — `Request_Purpose::RATES` without `is_confirmation()` is ALSO true inside `calculate_rate()`, not only on the public card view

> Tags: request-purpose, pickup, remote-call, checkout | Session: s207 (31.10.2026, Ozon PR #62 round 2)

## What happens

A pickup source wanted to fetch a point's schedule from the carrier only when a customer opens the point's card. It decided that
with `Woodev_API_Request_Purpose::current() === RATES && ! is_confirmation()`. The condition is also true while a **rate is being
calculated**: a cart update re-rates, the rate method looks the selected point up, and the source made a live `delivery-point/info`
call inside the checkout request (up to 8 s of the checkout budget, a slot of the daily call budget, a cached failure if the
carrier was slow). A second path, `nearest_to()`, did the same with no point selected.

## Root cause

`Woodev_Shipping_Method::calculate_rate()` wraps all of `rate_package()` in `Request_Purpose::run( RATES, … )`, and the card view
runs under `run_at_checkout()`, which is also `RATES`. The purpose says «a customer is waiting», not WHICH request. The
`is_confirmation()` mark only separates showing a choice from accepting it — it is not a «card view» flag.

## Fix

❌ Wrong — a purpose test as the only gate for a remote fetch:

```php
if ( Woodev_API_Request_Purpose::RATES === Woodev_API_Request_Purpose::current() && ! Woodev_API_Request_Purpose::is_confirmation() ) {
    $schedule = $this->fetch_schedule( $point_id ); // fires in calculate_rate() too
}
```

✅ Correct — give the rate path its own entry that never fetches, and keep the purpose test only in the public method the
framework's controller calls:

```php
$details = $source->fetch_details_for_rate( $point_id );   // rate method: schedule disabled, no remote call
$details = $source->fetch_details( $point_id );            // public card view: may fetch (cache, daily cap, licence check first)
```

Test the rate method from inside a `RATES` scope and assert zero carrier calls.

## Related

- [card-renders-from-a-snapshot-the-writers-never-touch](card-renders-from-a-snapshot-the-writers-never-touch.md) — the other half of the pickup card: what it renders from, as opposed to when it may call the carrier
