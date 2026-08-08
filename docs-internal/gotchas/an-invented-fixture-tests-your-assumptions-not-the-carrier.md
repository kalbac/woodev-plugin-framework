# An invented fixture tests your assumptions, not the carrier

**Namespace:** `[testing/unit]`
**Found:** s57 (08.08.2026), building the live Russian Post point source (#226).

## Root cause

Issue #226 recorded the Pochta contract carefully — the three endpoints, both silent traps, the
pagination shape, a table of detail fields. What it recorded about `geo` and `address` was their
**top-level key names**, never their contents. That gap is easy to miss, because the card reads as
exhaustive.

So the implementation was written against a *plausible* record shape (partly reverse-engineered
from the vendored widget bundle, which was the right instinct — see the reference-first rule) and
the test fixture was hand-written to match. Everything passed. 22 green tests over a record no
carrier has ever sent.

Taking one live capture and pasting it into the fixture verbatim broke four assertions immediately
and corrected **six** distinct wrong assumptions:

| Assumed | Actually |
|---|---|
| `place: "Москва"` | `"г. Москва"` — carries its own prefix |
| `street: "Новокосинская"` | `"ш. Энтузиастов"` — **already carries its type** |
| `deliveryPointIndex: 111673` (int) | `"111543"` (string), and `990537` for postamats — a **pseudo-index**, not a postal code |
| `address.id` ≈ the point id | **differs** (62170 vs 62257) on real records |
| `workTime: ["Пн-Пт 08:00-20:00"]` | `["пн, выходной", "вт, открыто: 10:00 - 19:00", …]` — a different format entirely |
| `cardPayment: true` | `false` — the fixture asserted a payment label the real point does not have |

Each one is individually small. Each one is a live bug:

- A composer that prepends a street type produces **"ул. ш. Энтузиастов"** — and a bare-street
  fixture can never catch it, because there is nothing to double up.
- `address.id` and `id` **coincide on some records and not others**, so a mapper reading the wrong
  one looks correct on whichever record it was first tested against and silently mis-keys the rest.
  Every detail fetch for those points then returns the 4-byte empty-success body.
- Treating `deliveryPointIndex` as a postal code is wrong for **a third of the points on the map**
  (34 of 113 in the measured bbox were postamats).

## ❌ Wrong

```php
// Hand-written to match what the API is believed to return.
private function sparse_record(): array {
    return [
        'id'      => 26600,
        'address' => [ 'place' => 'Москва', 'street' => 'Новокосинская', 'house' => '17' ],
        // ... the null-valued slots omitted, because they "don't matter"
    ];
}
```

The omitted slots are exactly the ones that matter: they are where the shape's surprises live.

## ✅ Correct

Paste the raw capture in **verbatim**, nulls and all, and say in a comment that it is verbatim and
when it was taken:

```php
// VERBATIM from a live capture, 08.08.2026 (`POST /api/pvz`, central-Moscow bbox). Kept
// byte-faithful on purpose, including the parts a hand-written fixture gets wrong and
// therefore stops testing: `place` carries its own "г. " prefix, `street` already carries its
// type, `deliveryPointIndex` is a STRING not an int, the null slots are present rather than
// absent, and `address.id` (62170) DIFFERS from the point `id` (62257).
```

Keep a copy in the class's own file docblock too, so the next person does not have to re-derive
the shape from a minified bundle or re-run a call they may no longer have credentials for.

## The rule

**A recorded contract is not a recorded payload.** "The response has `geo` and `address`" and "here
is a response" are different artefacts, and only the second one tests anything. When a card,
docblock or handoff describes a third-party shape, check whether it contains **actual bytes**; if it
names fields instead, one live capture is the cheapest work available — here it took one `curl` and
corrected six assumptions.

Reverse-engineering a vendored bundle is a good *fallback* and it got the hard part (the `[lng,
lat]` order) right. It is not a substitute for a capture, and it should be labelled as inference
until one is taken.

## Related

- [[ymaps-objectmanager-properties-are-plain]] — the s49 sibling, where a fixture poorer than
  production hid two of four map defects. This one is the same lesson for a fixture that is not
  poorer, merely *invented*.
- [[public-repo-third-party-credentials]] — why the capture goes in but the credential does not.
- [[card-renders-from-a-snapshot-the-writers-never-touch]] — the other s57 gotcha.
