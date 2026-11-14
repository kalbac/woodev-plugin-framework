# Gotcha: [testing/fakes] — a unit fake that accepts what the real dependency rejects hides a failure that hits every production run

> Tags: testing, fakes, action-scheduler, queue | Session: s230 (12.11.2026, PR #1757)

## What happens

The account-deletion queue cleaner called Action Scheduler's `query_actions( $args, 'ids' )`. The unit fake of the AS store accepted
`'ids'`, so every unit test passed. Real Action Scheduler 4.1.0 accepts only `'select'` and `'count'` for that argument, so every
production deletion would have failed. The critic found it by reading the dependency; no test could.

## Root cause

A fake written from what the code under test asks for, instead of from what the real dependency allows, encodes the author's
assumption — and the assumption and the implementation agree with each other, not with reality.

## Fix

❌ Wrong — a permissive fake:

```php
public function query_actions( array $args, string $format = 'select' ) {
    return $format === 'ids' ? $this->ids( $args ) : $this->rows( $args );   // accepts anything
}
```

✅ Correct:

- The fake rejects every input the real class rejects: `throw new InvalidArgumentException()` for any format other than `'select'` /
  `'count'` (copy the check from the vendored source).
- One integration test runs the queue cleanup against the REAL Action Scheduler store (the framework suite loads it).
- A critic of a queue change greps the real dependency's signature for each call the fake answers.

## Related

- [as-has-scheduled-action-matches-the-running-action](as-has-scheduled-action-matches-the-running-action.md) — another place where real Action Scheduler behaves unlike its assumed contract
- [a-records-page-can-pass-every-test-while-its-extensions-are-never-mounted](a-records-page-can-pass-every-test-while-its-extensions-are-never-mounted.md) — the same family: green tests, broken production path
