# Gotcha: [woocommerce/action-scheduler] — `as_has_scheduled_action()` also matches the action that is running right now
> Tags: woocommerce, action-scheduler, retry | Session: s145

## What happens
A retry chain on Action Scheduler (#954, PR #1008 round 1) guarded «do not schedule a second attempt if one is
already scheduled» with `as_has_scheduled_action( $hook, $args, $group )`. When attempt 2 failed INSIDE the
runner, the guard found attempt 2 itself, scheduled nothing and reported «scheduled». The chain died after two
attempts instead of five, with no order note. Every unit test passed — they mocked the scheduler.

## Root cause
`as_has_scheduled_action()` counts PENDING **and IN-PROGRESS** actions (Action Scheduler 4.0.0,
`functions.php` ~:406). Code that runs inside an action and asks «is one scheduled?» always sees itself.

## Fix
❌ wrong — inside the action's own callback:

```php
if ( ! as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
	as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP );
}
```

✅ correct — query PENDING only:

```php
$pending = as_get_scheduled_actions( [ 'hook' => self::HOOK, 'args' => $args, 'group' => self::GROUP,
	'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ], 'ids' );
if ( ! $pending ) { as_schedule_single_action( … ); }
```

Prove it with an integration test on the REAL runner: attempt N fails inside the runner → attempt N+1 is pending;
a full chain gives exactly the cap. A mocked scheduler cannot show this.

## Related
- [class-export-retry.php](../../woodev/shipping-method/order/class-export-retry.php) — the retry chain
- [a-form-inside-a-wc-order-metabox-closes-the-order-form.md](a-form-inside-a-wc-order-metabox-closes-the-order-form.md) — the other s145 trap unit tests could not see
