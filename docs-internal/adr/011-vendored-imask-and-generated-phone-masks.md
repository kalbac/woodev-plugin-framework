# ADR-011: Vendor IMask for input mechanics, generate the mask table from libphonenumber

**Status:** accepted

**Date:** 2026-08-31

## Context

Card #503 added a phone-input mask to the classic checkout. It shipped hand-rolled and
library-free, and the operator rejected it on the rig the same day: it masked `billing_phone` only,
and the formatting was unusable — typing `8` produced `+7 (8`, typing `8800` produced
`+7 (777) 008-8`. His verdict: *«Полная каша. Полем пользоваться не возможно.»*

He named the fork himself: take a maintained library, or rewrite ours properly. Two separate
questions fell out of that, and they have different answers.

**Question 1 — who owns the input mechanics?** Caret position after a backspace in the middle of a
number, overtyping a selection, paste, and re-formatting on a country change. This is where the
hand-rolled version failed, and it is not a one-off failure: it is the part of an input mask that
is genuinely hard and that every mask library exists to solve.

**Question 2 — who owns the per-country format?** The operator then sharpened the requirement: a
mask must match the country's real number structure, calling code AND national digit count, not
merely look tidy. His example of what is unacceptable: `+998(925)123-45-67`, wrong on both counts
for Uzbekistan.

That second requirement was already being violated. Measured 31.08.2026 against libphonenumber
metadata, eleven of our twelve hand-written templates were correct and one was not:

```
TM   '+993 # ######'  = 7 national digits
     real              = 8
```

A valid Turkmen number could not be entered at all. The table was typed by hand, reviewed, and
still wrong — which is the argument against hand-typing it, not against that particular typo.

Sizes, measured the same day (minified / gzip):

| | minified | gzip | supplies |
|---|---|---|---|
| IMask | 59 248 B | **15 800 B** | mechanics only; masks are ours |
| libphonenumber-js (min bundle) | 183 586 B | 44 476 B | per-country structure for ~240 countries |
| intl-tel-input + its utils | ~313 000 B | ~76 000 B | structure, plus a flag-dropdown UI we do not want |
| Inputmask | 254 735 B | 55 195 B | mechanics only — 3.5× IMask for the same job |

One more measurement decided against shipping libphonenumber at runtime. Its `AsYouType('RU')`
returns `8 (929) 600-80-90` nationally and `+7 929 600 80 90` internationally; the operator asked
for `+7 (929) 600-80-90`. Neither canonical form is what he wants, so its *formatting* would be
overridden anyway — only its *structure* is worth having.

## Decision

**Split the two concerns and pay for each at the cheapest point.**

1. **Vendor IMask for the mechanics.** `woodev/shipping-method/assets/js/vendor/imask.min.js`, an
   exact pinned version recorded in the file header and in the enqueue docblock. Not a CDN: a
   shop's checkout must not depend on a third-party host. Not a bundler: this layer serves its JS
   raw and gains no build step.
2. **Generate the country table at DEV time, from libphonenumber metadata.**
   `bin/generate-phone-masks.mjs` writes the table between markers in
   `class-phone-mask-patterns.php`. `npm run generate:phone-masks` rewrites it;
   `npm run lint:phone-masks` fails when the committed table is stale.
3. **libphonenumber-js is a `devDependency` and must stay one.** It must never be enqueued.
   Structure is decided at build time; the runtime carries the twelve-line result.
4. **Cosmetic overrides are declared, structure is not negotiable.** RU and KZ keep the bracket
   grouping the operator wrote his examples with. The generator refuses to run if an override
   changes the calling code or the national digit count.
5. **Only national digits become placeholders; the calling code stays literal.** The first draft of
   the generator masked the code too and emitted `+### ## ### ## ##`, which would have let `+375` be
   typed as `+123`.

## Consequences

- **Easier:** caret, paste and selection behaviour stop being ours to get wrong. Adding a country
  is one ISO code in the generator's list, not an authored template. A wrong digit count now fails
  a test instead of reaching a customer.
- **Accepted cost:** this is the **first vendored third-party runtime JS** in the framework. It
  carries an update burden — the pinned version has to be bumped deliberately, and nothing bumps it
  automatically. 15.8 KB gzip rides on the checkout, and only when a merchant turns the mask on
  (the script is not enqueued at all for the default «Не использовать», nor on the block checkout).
- **Guarded:** `PhoneMaskPatternsTest::test_every_template_matches_its_country_number_plan` pins
  every template against a committed fixture of number plans. It was watched RED against the
  original Turkmen template before being accepted. The fixture is committed rather than read from
  the library so the unit suite never depends on a devDependency being installed.
- **Not decided here:** whether other layers may vendor runtime JS. This ADR covers the checkout
  phone mask. A second case should be argued on its own evidence rather than by citing this one.

## Related

- [adr/009-map-provider-seam-source-not-library.md](009-map-provider-seam-source-not-library.md) — the other "library or our own" call in this codebase, decided the other way and for different reasons
- [sessions/s109.md](../sessions/s109.md) — the rig session that rejected the hand-rolled version and the measurements above
- `bin/generate-phone-masks.mjs` — the generator, whose header carries the operational detail
