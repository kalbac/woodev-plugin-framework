# Gotcha: [woocommerce/action-scheduler] — `as_schedule_single_action( …, $unique = true )` from INSIDE the running action schedules nothing, and so does any call before `action_scheduler_init`

> Tags: action-scheduler, chain, unique | Session: s204 (Ozon PR #28, W3 catalogue sync; measured against Action Scheduler 4.1.0 source)

## What happens

A catalogue sync that continues itself in chunks queued its successor from the callback of the running action with
`unique = true`. The chain never went past the first chunk and a failed chunk was never retried — silently, no error, every
mocked unit test green. A second form of the same silence: scheduling before Action Scheduler has initialised (a call from
`plugins_loaded` or the plugin constructor) does nothing at all.

## Root cause

- Action Scheduler's `unique` check treats a **pending OR in-progress** action with the same hook, group and args as a
  duplicate. The running action is itself in progress, so the successor is discarded as «already scheduled» (the same
  family as `as_has_scheduled_action()` matching the running action).
- The scheduling functions need the store: before `action_scheduler_init` they have none and return without queueing.

## Fix

❌ Wrong — in the action's own callback, or in the constructor:

```php
as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP, true );
```

✅ Correct — a non-unique schedule, guarded by a check for a **pending** action only (see the related gotcha), made on or
after `action_scheduler_init`; and a test that stubs `as_schedule_single_action` and asserts the `$unique` argument and the
moment of the call for the next-chunk and retry paths. A chain is safest when the successor is queued at the START of the run.

## Related

- [as-has-scheduled-action-matches-the-running-action](as-has-scheduled-action-matches-the-running-action.md) — the same self-collision through `as_has_scheduled_action()`
- Plugin-author skill: `.ai/skills/woodev-shipping-plugin/references/traps.md` («Background work on Action Scheduler»)
