# `preg_match( '//u', $s )` returns FALSE, not 0 — and escapes in a fixture get re-encoded

**Namespace:** `[testing/*]`
**Found:** s98 (27.08.2026), writing the tests for the #402 follow-up. Both halves bit in the same hour.

## Half one: the return value

Asserting "this fixture really is malformed UTF-8" is what makes a malformed-input test test
anything. The natural way to write it does not work:

```php
$this->assertSame( 0, preg_match( '//u', $payload ) );   // ❌ never true for the case you mean
```

`preg_match()` returns `1` for a match, `0` for no match, and **`false` on an error** — and an
invalid UTF-8 subject under `/u` is an *error*, not a non-match. So the assertion above fails on
exactly the input it is meant to accept, and "fixing" it by asserting `1` makes the control
vacuous in the other direction.

```php
$this->assertFalse( preg_match( '//u', $payload ) );     // ✅
```

## Half two: the fixture stops being malformed

Raw control bytes written as string escapes do not survive a round trip through most tooling.
Written through a script, `"A\x9bB"` came back out of the file as `41 c2 9b 42` — the byte
re-encoded as **valid UTF-8** (`U+009B`). Every case then exercised the Unicode branch and
silently stopped testing the byte branch it was written for.

Build the bytes instead of spelling them:

```php
'ansi CSI' => [ chr( 0xc3 ) . chr( 0x28 ) . 'A' . chr( 0x9b ) . 'B', chr( 0x9b ) ],
```

## Half three, while we are here

A blanket "no byte in `0x80-0x9f` survived" assertion fails on any Cyrillic message: UTF-8
continuation bytes live in `0x80-0xbf`. Assert on **the byte you injected**, not on a range.

## Related

- [a-sanitiser-that-leans-on-an-optional-extension](a-sanitiser-that-leans-on-an-optional-extension.md) — the defect these tests exist for
- [a-mutation-you-did-not-confirm-applied-proves-nothing](a-mutation-you-did-not-confirm-applied-proves-nothing.md) — same family: the check you think you ran did not run
