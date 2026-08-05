# Bounding the address geocode by the pickup-point area breaks the normal case — self-inflicted, s51

**Namespace:** `[shipping/pickup]` · **Discovered:** s51 (2026-08-05). This one is on me: I introduced
the bug earlier in the same session I found it, while "fixing" something that wasn't broken. This
codebase's gotchas record incidents honestly, so: honestly.

## The three calls, and what each one is for

The pickup search feature reaches Yandex three separate times:

1. `ymaps.suggest()` — offers address candidates as the customer types
2. `geocode()` behind the search control — offers candidates for the plain search box
3. `resolveAddress()`'s `geocode()` — resolves the ONE suggestion the customer already clicked

The first two are correctly bounded to the loaded pickup-point area (`strictBounds: true`) — that's
what stops a Moscow buyer from being offered a same-named street in Tolyatti (see
[[ymaps-control-options-must-be-nested]] for the sibling bug on the search control's own geocoder).

## The mistake

I looked at the third call, `resolveAddress()`, saw it was unbounded, called that a gap, and bounded it
the same way as the other two. It felt like consistency. It was wrong.

**The customer's own address is routinely OUTSIDE the area the pickup points cover — that is the whole
reason they are searching for it.** A buyer south of Moscow searching for pickup points near their own
home is not looking for an address inside the point cluster; they're looking for points *near an
address that isn't there*.

## Measured live

Fixture pickup points sit in central Moscow. The searched address was ~14 km south. With
`resolveAddress()` bounded by `strictBounds: true` against the point cluster, `geocode()` returned zero
hits. `resolveAddress()` then silently returned — no camera move, no error message, no fallback,
nothing observable at all. The customer clicks a suggestion from the list and the map simply does not
react, as if the click never registered.

## The rule

**Bound the calls that OFFER candidates. Do not bound the call that RESOLVES an already-chosen one.**
By the time `resolveAddress()` runs, there is nothing left to disambiguate — the picked string already
carries its own country/locality prefix (see [[ymaps-suggest-not-geocode-for-address-lists]] for why
that untrimmed `value` string matters here specifically). Bounding it doesn't narrow an ambiguous
choice; it just makes correct, already-made choices fail whenever the customer's address falls outside
the point coverage — which, again, is the normal case for this feature, not an edge case.

```js
// ❌ WRONG — narrows a choice that was already made
async function resolveAddress( fullValue ) {
    return ymaps.geocode( fullValue, { boundedBy: pointsBounds, strictBounds: true } );
}

// ✅ RIGHT — nothing left to disambiguate, don't constrain it
async function resolveAddress( fullValue ) {
    return ymaps.geocode( fullValue );
}
```

## A second, independent lesson from the same incident

An empty geocode result must produce a **visible** outcome — a message, a no-op state the customer can
see, anything — never an early `return`. Silence is indistinguishable from a dead click, and it cost
real debugging time here to tell "the map isn't updating" apart from "the click never fired" apart from
"the request came back empty and nothing said so."

## Related

- [[ymaps-suggest-not-geocode-for-address-lists]] — the sibling fix in the same feature: which call to
  use, and which field of its result carries the string this file's `resolveAddress()` consumes
- [[ymaps-control-options-must-be-nested]] — the OTHER bounded-geocoder bug in this feature (the search
  control's own default geocoder, unbounded in the opposite direction — worldwide instead of over-narrowed)
- [[ymaps-camera-moves-are-async]] — same feature area, same "a dropped/mishandled async step produces
  total silence, not an error" shape
