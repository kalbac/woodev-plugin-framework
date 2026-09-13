# Gotcha: [rig/fixtures] — a fixture's synthetic timestamps anchored to a CONSTANT predate the orders they belong to
> Tags: rig, fixtures, testing, time | Session: s136

## What happens

The fixture carrier's tracking handler built a deterministic delivery history and derived every
event time from a fixed epoch:

```php
$time = 1720000000 + ( $index % 120 ) * HOUR_IN_SECONDS;   // 3 July 2024
```

Deterministic, reproducible, unit-tested — and on the rig it rendered a shipment history dated
**July 2024 inside an order created in September 2026**. The delivery finished two years before the
order existed. Nothing failed: no test asserts a relationship between an order's date and its
history, because the handler is only ever handed a tracking number.

It reads as a broken feature to the one person whose acceptance it was built for.

## Root cause

Determinism was the stated requirement, and a constant is the laziest way to get it. But the seeded
data it has to sit beside is **relative to now** — the seeder creates orders dated recently — so a
constant anchor drifts further from the data every month the project lives.

The framework's own signature makes the mistake easy: `get_history( string $tracking_number )`
receives no order, so the handler cannot anchor to the order's date even if it wanted to.

## Fix

Anchor to the clock and keep the SHAPE deterministic — which history, how many events, and the
offsets between them:

```php
// ❌ wrong — reproducible and permanently wrong
$time = 1720000000 + ( $index % 120 ) * HOUR_IN_SECONDS;

// ✅ correct — the shape is still a pure function of the tracking number,
//    only the anchor follows the clock, exactly as a real carrier's stamps do
$time = time() - 3 * DAY_IN_SECONDS + ( $index % 24 ) * HOUR_IN_SECONDS;
```

Events then land inside the last few days and never in the future. Two views minutes apart differ
by minutes, which is what a real carrier looks like anyway.

⚠ **The general rule:** a fixture's absolute times must be relative to `time()` whenever the data
they sit beside is. Reserve fixed epochs for values nothing is compared against.

## Related

- [the-rig-runs-the-live-yandex-point-source-so-a-fixture-change-may-never-reach-it](the-rig-runs-the-live-yandex-point-source-so-a-fixture-change-may-never-reach-it.md) — the other way a fixture's output does not match what the rig shows
- [a-fixtures-no-op-becomes-a-fatal-the-day-the-fixture-goes-live](a-fixtures-no-op-becomes-a-fatal-the-day-the-fixture-goes-live.md) — a fixture shortcut that only hurts later
- [a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there](a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there.md) — the other time-related rig trap
