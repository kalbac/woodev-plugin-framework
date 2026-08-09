# The ymaps `lang` parameter picks units, not just labels — `en_US` gives miles

**Namespace:** `[shipping/pickup]` · **Discovered:** s47 (2026-08-01), designing the pickup-map locale rule

## The trap

The Yandex Maps JS API 2.1 loader takes `lang` in `language_REGION` form (ISO 639-1 + ISO 3166-1).
The obvious reading is that it selects the language of map labels. It does more than that:

> «Для регионов `RU`, `UA` и `TR` расстояние показывается в километрах, для `US` — в милях.»

So the **region half silently selects the unit system**. Picking `en_US` as the fallback for
unsupported site locales — which is the natural choice, since `en_US` is WordPress's own default —
switches the map to **miles** for a carrier that delivers in kilometres.

That alone is cosmetic. It stops being cosmetic when the surrounding UI computes its own distances:
a sidebar that renders "0.4 км" next to a map labelled in miles shows the customer two different
measurement systems on one screen.

## Accepted locale values

Six, per the Russian localisation page:

```
ru_RU   en_US   en_RU   ru_UA   uk_UA   tr_TR
```

Hyphenated spellings (`ru-RU`) are accepted only for backward compatibility and are not recommended.

⚠️ An English-language documentation page lists only four (`ru_RU`, `en_US`, `tr_TR`, `uk_UA`). The
two pages disagree; the Russian one is the more complete. **Verify on the rig before relying on
`en_RU` or `ru_UA`** — a rejected `lang` is not loud.

Note that `en_RU` exists precisely for this situation: English labels, Russian region, kilometres.

## The rule this project uses

```
site locale ∈ { ru_RU, en_US, en_RU, ru_UA, uk_UA, tr_TR }  →  use as-is
otherwise                                                    →  en_US
```

Operator's call (s47), taken with the miles consequence stated. `en_RU` as the fallback was proposed
and rejected in favour of a simpler rule. The consequence is therefore **known and accepted**, not an
oversight — do not "fix" it without asking.

## What the code must do about it

Derive the unit system for our own distance formatting from the **region** of the resolved locale,
not from the language and not from a separate constant. One source, so the two numbers on screen
cannot disagree:

```js
// ✅ region decides
formatDistance( 1240, 'en_RU' )  // '1.2 km'  — English words, metric
formatDistance( 1240, 'en_US' )  // '0.8 mi'  — English words, imperial
formatDistance( 1240, 'ru_RU' )  // '1.2 км'

// ❌ language decides — silently disagrees with the map underneath
```

Also emit the resolved locale as an explicit config field, not only baked into the script URL. Two
places computing it independently drift apart without anything failing.

## Related

- [[ymaps-camera-moves-are-async]] — the other "the API does more than it looks like" case on this feature
- [[../adr/010-yandex-maps-js-api-2-1-not-3-0.md]] — why the project is on 2.1 at all
- [[../archive/specs/2026-08-01-sp5-pickup-map-rework-design.md]] — D-12
