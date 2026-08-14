# `Functions\expect( 'f' )->once()->with( X )` does not reject a SECOND call with different arguments

**Namespace:** `[testing/unit]` · **Discovered:** s72 (2026-08-14), re-review of the fix commit on PR #316 (#299)

## The trap

Brain Monkey / Mockery read `->once()->with( X )` as *"the call matching `X` happened exactly once"*.
A further call to the same function with **different** arguments matches a different expectation set,
and nothing fails. So this test:

```php
Functions\expect( 'wc_add_notice' )
    ->once()
    ->with( 'Укажите значение поля «Пункт выдачи».', 'error' );
```

passes whether the code emits **one** notice or **two**, as long as one of them is that string.

## What it cost

PR #316's headline change was deduplication — one message to the buyer instead of two. The test named
`test_exact_match_dedupes_required_and_backstop_into_one_notice` stayed green when the dedup guard was
mutated to `if ( true )`, i.e. with the feature removed. The whole file stayed green. The PR's own
mutation report listed a different mutation (the message wording), which reddened the same test for an
unrelated reason — the literal string — and read as coverage.

The production code was correct. Nothing defended it.

## The tell

**A test whose NAME contains a count or an exclusivity claim — "into one notice", "fires alone",
"only", "exactly", "never also" — cannot be implemented with `->with()` alone.** `->with()` is a
filter, not a census. Whenever the assertion is about what did NOT happen, `->with()` is the wrong
tool by construction.

## ✅ Correct — capture and assert the whole list

```php
$notices = [];

Functions\when( 'wc_add_notice' )->alias(
    static function ( $message, $type = 'success' ) use ( &$notices ): void {
        $notices[] = [ $message, $type ];
    }
);

$handler->validate( $state );

self::assertCount( 1, $notices );                                  // the actual claim
self::assertSame( 'Укажите значение поля «Пункт выдачи».', $notices[0][0] );
```

A cheaper partial fix, when you only need the count: add a bare
`Functions\expect( 'wc_add_notice' )->once()` **alongside** the `->with()` one — the unfiltered
expectation does count every call.

## How to check your own suite

Grep for tests whose names promise a count and whose bodies only use `->with()`:

```bash
grep -rn "function test_.*\(alone\|only\|one_\|exactly\|never\)" tests/unit/ | cut -d: -f1-2
```

Then mutate the guard the test is named after and confirm it actually reddens. **Mutating the helper
a test calls proves the helper is exercised; only mutating the branch the test NAMES proves the
test defends it** — the s72 addendum to `built-on-both-sides-with-no-caller-in-the-middle` is the
same lesson from the other direction.

## Related

- [[built-on-both-sides-with-no-caller-in-the-middle]] — s72 addendum: the caller exists and nothing
  pins it; the suite passes with the one fix line reverted
- [[mutation-sweep-branch-only-false-confidence]] — the other "a green run proves less than it looks"
- `tests/unit/Shipping/Checkout/CheckoutHandlerValidateTest.php`
