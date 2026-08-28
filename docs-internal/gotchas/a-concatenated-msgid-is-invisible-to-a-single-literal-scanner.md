# Gotcha: [i18n/scanning] — A concatenated msgid is invisible to a single-literal scanner
> Tags: i18n, testing, measurement | Session: s103

## What happens

You tokenise `woodev/` to inventory gettext calls, and — sensibly — only count a call whose first
argument you can actually read: exactly one `T_CONSTANT_ENCAPSED_STRING`. Anything else (a
`sprintf()`, a variable, a ternary) is not a literal msgid and is skipped.

The rule quietly skips one shape that IS a literal msgid:

```php
__(
    'В этом пункте выдачи недоступна оплата при получении.'
    . ' Выберите другой пункт или другой способ оплаты.',
    'woodev-plugin-framework'
);
```

PHP folds that at parse time, `wp i18n make-pot` emits it as one msgid, and gettext looks it up as
one string. Two independent measurements on **#567** — s102's and the s103 re-measurement that was
checking s102 — both reported **305** cyrillic msgids and both missed it. It surfaced only because a
test failed on a string the rewrite had not touched.

There are exactly **two** such calls in `woodev/` today. One is an admin settings description; the
other is `Constraint_Checker`'s cash-on-delivery reason, which reaches the shopper through
`wc_add_notice()`. So the miss rate was 1 in 305 — and it was the customer-facing one.

## Root cause

The framework wraps at 120 characters by hand (`Generic.Files.LineLength` is a suppressed warning,
not a gate — see `phpcs.xml`), so a message long enough to name both a problem and its fix does not
fit on one line at depth. Concatenation is the idiomatic way out, and the codebase's own comment at
that site says exactly that. The scanner's "one token" rule was written against the common case and
never met the idiom.

## Fix

❌ Wrong — the argument must be one token:

```php
if ( null === $argument || 1 !== count( $argument ) ) {
    continue;
}
```

✅ Correct — accept a pure literal concatenation, which is what gettext itself resolves:

```php
// literal ( '.' literal )+ and nothing else
$parts = [];
foreach ( $argument as $i => $piece ) {
    if ( 0 === $i % 2 ) {
        if ( ! is_array( $piece ) || T_CONSTANT_ENCAPSED_STRING !== $piece[0] ) {
            $parts = [];
            break;
        }
        $parts[] = substr( $piece[1], 1, -1 );
        continue;
    }
    if ( '.' !== $piece ) {
        $parts = [];
        break;
    }
}
$msgid = implode( '', $parts );
```

✅ Better — do not hand-roll the inventory at all when a real extractor is reachable. `wp i18n
make-pot` in the rig container knows every shape gettext knows, and diffing its output against your
own scan is what exposes the gap:

```bash
docker exec -w /var/www/html/woodev-framework <cli> \
  wp i18n make-pot woodev /tmp/probe.pot --domain=woodev-plugin-framework
```

**The general rule:** when you write a scanner whose skip-branch is "I cannot read this", count what
it skipped and look at it. A silent `continue` is where the finding you needed goes. This is the
same shape as `TextDomainConsistencyTest`'s own history — it too computed a case and `continue`d
past it, and 26 defects accumulated behind that one line until #444 made it report instead.

## Related

- [classify-an-i18n-string-by-its-render-path-not-its-file-path](classify-an-i18n-string-by-its-render-path-not-its-file-path.md) — the other #567 measurement gap, found in the same pass
- [comparing-a-po-against-a-compiled-mo-by-bare-msgid-undercounts](comparing-a-po-against-a-compiled-mo-by-bare-msgid-undercounts.md) — the s100 catalogue measurement that was also wrong on its first pass
- [phpcs-does-not-enforce-line-length](phpcs-does-not-enforce-line-length.md) — why the 120-char wrap is a hand convention here, which is what makes the concatenation idiomatic
- [a-mutation-you-did-not-confirm-applied-proves-nothing](a-mutation-you-did-not-confirm-applied-proves-nothing.md) — same family: the silent no-op that reads as a result
