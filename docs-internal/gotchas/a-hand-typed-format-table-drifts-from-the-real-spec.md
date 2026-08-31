# Gotcha: [shipping/checkout] — a hand-typed per-country format table is wrong on arrival, and review does not catch it

**Namespace:** `[shipping/checkout]` · **Discovered:** s109 (31.08.2026)

## The trap

Card #503 shipped a twelve-country phone-mask table, typed by hand from knowledge of the formats.
It was written by one agent, reviewed by the coordinator, and read by the operator, who approved
the country list. Then it was checked against a real number-plan database:

```
TM   '+993 # ######'   = 7 national digits
     libphonenumber      8
```

**Eleven of twelve were right. One was wrong, and it made a whole country's numbers impossible to
enter** — the mask physically had no room for the eighth digit.

Nothing about the wrong row looked wrong. `+993 # ######` reads exactly as plausibly as
`+993 ## ######`. That is the point: a table of formats is a table of FACTS about the outside
world, and reading it carefully proves nothing, because the reader is checking it against the same
memory that produced it.

## Why it generalises past phone numbers

Any table that encodes an external specification has this shape — postal-code patterns, VAT-number
lengths, IBAN structures, per-country address orders, currency minor units. In every case:

- the values are facts someone else publishes and maintains;
- a wrong value is invisible to review;
- the failure lands on a customer in one country, so it is rare enough never to be reported.

The operator's framing of the requirement is the test to apply: the value must match the country's
real structure — **prefix AND length** — not merely look tidy.

## ❌ Wrong

```php
// Typed from knowledge. One of these is wrong and you cannot tell which.
'TJ' => '+992 ## ### ####',
'TM' => '+993 # ######',
'UZ' => '+998 ## ### ####',
```

## ✅ Correct

Derive the table from the published source at DEV time, commit the result, and pin it with a test.

```js
// bin/generate-phone-masks.mjs
const example   = getExampleNumber( iso, examples );
const formatted = new AsYouType( iso ).input( `+${ example.countryCallingCode }${ example.nationalNumber }` );
const head      = `+${ example.countryCallingCode }`;
// Only NATIONAL digits become placeholders — masking the calling code too emits `+### ## ###`,
// which lets `+375` be typed as `+123`.
const template  = head + formatted.slice( head.length ).replace( /[0-9]/g, '#' );
```

Three properties make this worth the script:

- **The generator refuses to run** when a declared cosmetic override changes the calling code or the
  digit count. Re-grouping `+7 ### ### ## ##` into `+7 (###) ###-##-##` is allowed; changing what
  the country accepts is not.
- **The source library stays a `devDependency`.** libphonenumber-js is 44.5 KB gzip of metadata for
  ~240 countries; shipping it to a browser to answer twelve questions is the wrong trade
  (ADR-011).
- **The test uses a COMMITTED fixture of expected lengths**, not a live library call, so the unit
  suite does not depend on a devDependency being installed — and a number plan changing under us
  fails loudly instead of silently re-deriving itself.

## How to prove the test works

Re-introduce the original bug and watch it go red. This was done before the test was accepted:

```
TM: template takes 7 national digits, the number plan is 8
```

A test written after the fix, never seen failing, would have passed just as happily against the
broken table.

## A trap inside the fix

`libphonenumber-js/examples.mobile.json` is **not JSON** — the package maps that specifier to
`examples.mobile.json.js`. Importing it with `with { type: 'json' }` dies with
`ERR_IMPORT_ATTRIBUTE_TYPE_INCOMPATIBLE`. Import it as an ordinary module.

## Related

- [../adr/011-vendored-imask-and-generated-phone-masks.md](../adr/011-vendored-imask-and-generated-phone-masks.md) — the decision this came out of, with the measured sizes
- [a-plausible-inference-written-as-fact-is-the-dangerous-one](a-plausible-inference-written-as-fact-is-the-dangerous-one.md) — the same disease at the level of a claim rather than a table
- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md) — green tests that pin our own belief instead of the outside world
