# Gotcha: [i18n/catalogue] — Rule 1 has TWO halves; shipping the English msgid alone is a storefront regression
> Tags: i18n, gettext, storefront, po, mo, regression | Session: s104

## What happens

Operator rule 1 (#567) reads: **a storefront-visible string's msgid is ENGLISH, and the Russian
arrives from the catalogue.** It is easy to implement only the first half — rewrite `'м'` as
`__( 'm', … )`, tick the rule off, ship.

The worked case is #646. `pickup-geo.js` had its own two-locale table hardcoded:

```js
var smallWord = 'ru' === lang ? 'м' : 'm';
var largeWord = 'ru' === lang ? 'км' : 'km';
```

That is wrong for the reason the card says — a second localisation system bypassing the catalogue.
But it was **producing the correct Russian**. Replacing it with an English msgid and no `.po` entry
moves a ru_RU storefront from `м`/`км` to `m`/`km` beside every pickup point. The unit tests stay
green (they assert the plumbing, not the catalogue), CI stays green, and the shopper reads English.

This is not the same situation as a **fallback** string. PR #645 rewrote three literals in
`woodev-modal.js` and `checkout-field-classic.js` into English; those are only reached when PHP
failed to supply a label, so English there is a degradation path, not the normal render.

## Root cause

The rule names a source (the msgid) and a channel (the catalogue). A change that opens the source
without filling the channel is not half-done, it is a regression — because the previous code was, by
accident, the channel.

The deferred `.pot`/`.mo` regeneration (#567's remainder, operator 29.08.2026) is about **strings
that were already English**. It is not a licence to add new untranslated ones on the storefront path.

## Fix

❌ Wrong — the msgid changes, nothing fills the channel:

```php
'distanceMeters' => __( 'm', 'woodev-plugin-framework' ),
```

✅ Correct — context for a short ambiguous string, and the catalogue entry shipped with it:

```php
'distanceMeters' => _x( 'm', 'distance unit abbreviation: metres', 'woodev-plugin-framework' ),
```

```po
#: shipping-method/pickup/class-pickup-handler.php:1290
msgctxt "distance unit abbreviation: metres"
msgid "m"
msgstr "м"
```

Then recompile — see [the-mo-is-reproducible-from-the-po](the-mo-is-reproducible-from-the-po.md)
for why that is a safe, reviewable diff rather than an opaque binary blob.

**A one- or two-letter msgid needs `_x()` with a context**, not `__()`. `m` alone is unresolvable
for a translator and collides with any other short string in the domain. The repo already does this
in `class-helper.php` and `blocks-handler.php`.

## How it was caught

Not by tests, and not by CI — by the Codex critic reading the diff against the rule. Both the
critic and the coordinator reached it independently, which is the shape of a defect that a green
suite structurally cannot see: nothing in the test tree asserts what a Russian shopper reads.

## Related

- [the-mo-is-reproducible-from-the-po](the-mo-is-reproducible-from-the-po.md) — how to ship the catalogue half safely
- [classify-an-i18n-string-by-its-render-path-not-its-file-path](classify-an-i18n-string-by-its-render-path-not-its-file-path.md) — which rule applies to which string
- `AGENTS.md` → Conventions → Translatable strings — the four rules
