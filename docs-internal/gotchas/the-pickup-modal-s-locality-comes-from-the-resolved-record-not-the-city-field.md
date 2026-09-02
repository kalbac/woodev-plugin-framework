# The pickup modal's `locality` comes from the RESOLVED RECORD, not the city field

**Namespace:** `[rig/browser]` · **Measured:** s113 (02.09.2026), on the live rig, while trying to
reproduce #150 (the single-point city) through the interface.

## The trap

The pickup modal fetches points with a `locality` query parameter:

```text
GET /wp-json/woodev/v1/shipping/pickup/woodev-realistic-shipping/points?locality=Москва
→ 200, 3 points
```

It is natural to assume that string comes from the checkout's city input, so the obvious way to
drive the modal to another city is to type into `#billing_city` / `#shipping_city` and fire
`update_checkout`. **It does not work, and it fails in a way that looks like a broken carrier
rather than a wrong move.** Measured in the browser: after setting the city field by hand, the very
next request is

```text
GET /wp-json/woodev/v1/shipping/pickup/woodev-realistic-shipping/points
→ 200, 0 points
```

— the `locality` parameter is **gone entirely**, and the list renders empty with no error, no
console message and a perfectly healthy 200.

## Why

`locality` is derived from the location layer's RESOLVED RECORD (the region → settlement chain,
stored in `woodev_customer_location`), never from the raw text in the field. Typing a city the
active provider cannot resolve leaves the chain with no settlement record, and a handler with no
record sends no locality at all.

So an empty pickup list after a city change means "the location chain did not resolve", which is a
completely different diagnosis from "the point source returned nothing".

## Consequence when reproducing a locality-specific bug

You can only reach a city the ACTIVE location provider actually knows. On the standard rig that
provider is `test-cdek`, and it knows only Москва, Санкт-Петербург and a handful of
regions/districts. That is why #150's single-point city (Краснодар, which the realistic carrier's
point source does serve) is unreachable through the interface — card #741.

## ✅ How to check which of the two failed

Wrap `fetch`/`XMLHttpRequest.open` before opening the modal and read the URL back:

```js
window.__f = [];
const of = window.fetch;
window.fetch = function (...a) { window.__f.push(String(a[0])); return of.apply(this, a); };
```

- URL **carries** `locality=…` and the list is empty → the point SOURCE has nothing there.
- URL **omits** `locality` → the location CHAIN did not resolve; the source was never asked.

## Do not "fix" it by forging the option

`woodev_customer_location` stores a whole `Location_Record`, not a key — see
[the-default-locality-option-stores-a-whole-record-not-a-key.md](the-default-locality-option-stores-a-whole-record-not-a-key.md).
A hand-built record behaves unpredictably and proves nothing. Teach the fixture provider the city
instead.

## Related

- [the-default-locality-option-stores-a-whole-record-not-a-key.md](the-default-locality-option-stores-a-whole-record-not-a-key.md) — what that option really holds
- [rig-checkout-url-is-the-block-checkout.md](rig-checkout-url-is-the-block-checkout.md) — the other way the pickup layer looks broken when it is not
- [a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there.md](a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there.md) — poll and add a control, do not read on a timer
