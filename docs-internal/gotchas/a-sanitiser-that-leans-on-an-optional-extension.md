# A sanitiser that leans on an optional extension is not a sanitiser

**Namespace:** `[php/*]`
**Found:** s98 (27.08.2026), on the merged #402 fix, by a critic pass — and the critic's own report was corrected by measuring it.

## The trap

`Woodev_Script_Handler::sanitize_log_field()` strips control characters, then caps the result.
Its malformed-UTF-8 branch used `/[\x00-\x1f\x7f]+/` — stopping short of C1 (`\x80-\x9f`), which
the main `/u` pattern does cover. So on that branch a raw `\x9b` (single-byte ANSI CSI) was never
replaced.

The critic reported that the byte therefore reached the log. **Driving the whole function says
otherwise:**

```
in  = c328419b42        // \xC3 \x28 A \x9b B — malformed UTF-8 carrying a raw CSI
out = 3f28413f42        // through the REAL merged function, WITH mbstring
```

`mb_substr()` at the end rewrites invalid UTF-8 to `?`. The gap was real and **masked by an
optional extension doing sanitising work nobody asked it for.**

`ext-mbstring` is **not** in this package's `composer.json` requirements. On an install without
it, the old byte-wise `substr()` fallback did no rewriting and the byte survived — and the same
`substr()` cut multi-byte characters in half (499 ASCII + `я` → 500 bytes ending in a lone
`0xd1`, invalid UTF-8 written straight into the log).

## The general shape

Two lessons, and the second is the transferable one:

1. **A security function must be correct on its own,** never because some later step happens to
   clean up after it. The later step is not part of the contract, and here it was not even part
   of the install.
2. **Probing a helper in isolation and probing the function are different measurements.** The
   critic's isolated probe of the two regexes was right about the regexes and wrong about the
   outcome. Neither reading is complete: isolation found the defect, end-to-end found that it
   was masked, and only both together explain why it matters (no mbstring → unmasked).

## ❌ Wrong

```php
// "the cap will tidy up whatever the regex missed"
return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 500 ) : substr( $value, 0, 500 );
```

## ✅ Correct

Both patterns cover the same range, and the cap is UTF-8-safe without mbstring:

```php
$stripped = preg_replace( '/[\x00-\x1f\x7f-\x9f]+/u', ' ', $value );
$value    = null !== $stripped ? $stripped : (string) preg_replace( '/[\x00-\x1f\x7f-\x9f]+/', ' ', $value );
…
if ( preg_match( '/^.{0,' . (int) $max . '}/us', $value, $m ) ) { return $m[0]; }
```

## Related

- [the-local-php-is-four-versions-above-the-ci-floor](the-local-php-is-four-versions-above-the-ci-floor.md) — the same family: the environment you measure in is not the environment that runs
- [preg-match-u-returns-false-not-zero-on-invalid-utf8](preg-match-u-returns-false-not-zero-on-invalid-utf8.md) — the trap in testing this one
