# Address lookup needs `ymaps.suggest()`, not `geocode()` — and `value`, not `displayName`

**Namespace:** `[shipping/pickup]` · **Discovered:** s51 (2026-08-05), operator typed the exact address
"Чертановская 66к1" into the search field.

## What happened

Yandex.Delivery's own widget, searching the same string, returns **"Чертановская улица, 66к1"**. Ours
returned **"Russian Federation, Moscow, Serpukhovsko-Timiryazevskaya Line, Chertanovskaya metro
station"** — a metro station, in full postal form, for a query that named a street and a house number.
Two independent bugs stacked on top of each other.

## Cause 1 — the wrong service

A **geocoder** ranks POIs against addresses and will happily return a metro station ahead of the street
it sits under. `ymaps.suggest()` is the *address-completion* service and returns address-shaped
results — it is what the reference widget uses for its search box (`noSuggestPanel: false` keeps
ymaps' own suggest panel alive there).

A previous session had removed `suggest()` from this codebase entirely, on the grounds that the Russian
Post bundle's widget uses none. That's true of that one bundle — but the operator's stated reference
for search behaviour here is Yandex.Delivery, and Yandex.Delivery keeps `suggest()`.

## Cause 2 — the wrong field

Once results come back — from either service — this codebase displayed `properties.get('text')`, which
returns the full postal form including country. Even a `suggest()` result has a field that is wrong to
display, for a non-obvious reason (see below).

## The measured shape of `ymaps.suggest()`

Not documented anywhere obvious; worth pinning here. A live query for "Чертановская 66к1" returns
items shaped like:

```js
{
    type: "geo",
    displayName: "66к1, Чертановская улица, Москва, Россия",   // REVERSED — house number first, country included
    value:       "Россия, Москва, Чертановская улица, 66к1 ",  // broad → narrow, note the trailing space
    hl: [ /* highlight ranges */ ],
}
```

`displayName` reads as the field to show a human, and is the WRONG one — it is reversed (house before
street) and still carries the country. The reference's displayed string is `value` with the leading
`Россия, Москва, ` (country/locality) prefix trimmed off; the FULL `value` — untrimmed — is what must
be kept for the follow-up `geocode()` call, because trimming it there breaks resolution for addresses
outside the loaded area (see [[bounding-the-address-resolve-breaks-the-normal-case]]).

`suggest()` also honours `boundedBy`/`strictBounds`, verified live — same as `geocode()`.

## The fix

```js
// ❌ WRONG
const results = await ymaps.geocode( query );
const label = feature.properties.get( 'text' );

// ✅ RIGHT
const results = await ymaps.suggest( query, { boundedBy, strictBounds: true } );
const label = item.value.replace( /^Россия,\s*Москва,\s*/, '' ); // display only
// item.value (untrimmed) is what gets passed to resolveAddress()
```

## Why no test saw it

ymaps is mocked in unit tests, and the mock never modelled `suggest()`'s actual shape (reversed
`displayName`, trailing space on `value`) because nobody had queried the real service to know it. A
mock that returns whatever the code expects cannot disagree with the code.

## Related

- [[bounding-the-address-resolve-breaks-the-normal-case]] — why `value` must stay untrimmed and why the
  *third* Yandex call in this same feature must NOT be bounded the same way as this one
- [[ymaps-html-icon-layout-needs-iconshape]] — same family: an ymaps API shape nothing but a live query reveals
- [[ymaps-locale-region-drives-units]] — another case of an ymaps parameter with undocumented reach
