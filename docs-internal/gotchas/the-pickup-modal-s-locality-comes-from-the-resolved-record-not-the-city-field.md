# The pickup modal sends NO `locality` unless region and settlement AGREE

**Namespace:** `[rig/browser]` · **Measured:** s113 (02–03.09.2026), on the live rig, first while
failing to reach #150's single-point city and then while actually reaching it.

> ⚠ **This file's first version (02.09.2026) blamed the wrong thing** — it said the locality comes
> from the resolved record "not the city field", and concluded that the `test-cdek` provider did not
> know Краснодар. Both halves were wrong: the provider is a client of the live CDEK test contour and
> knows every city in it, and setting the record alone changes nothing. The real cause is below.
> Card #741, filed on that wrong premise, is closed as `not planned`.

## The trap

The pickup modal fetches points with a `locality` query parameter:

```text
GET /wp-json/woodev/v1/shipping/pickup/woodev-realistic-shipping/points?locality=Москва
→ 200, 3 points
```

Change the checkout's city and the parameter can vanish entirely:

```text
GET /wp-json/woodev/v1/shipping/pickup/woodev-realistic-shipping/points
→ 200, 0 points
```

No error, no console message, an empty list on a perfectly healthy 200 — which reads as "this
carrier has nothing here" when the truth is "nobody ever asked".

## Why — the region field gates it, and its values are UPPERCASE

`locality` is produced by the location chain, and the chain only resolves when the **region and the
settlement agree**. The region field is a `<select>` whose option VALUES are upper-case
(`МОСКВА`, `КРАСНОДАРСКИЙ КРАЙ`) while its labels are title-case (`Москва`, `Краснодарский край`).

Every way of half-setting that pair produces the silent empty result:

- typing a new city while the region still holds the old one → mismatch → no locality
- writing `billing_state` as `'Москва'` instead of `'МОСКВА'` → **the select renders EMPTY**
  (nothing matches the option value) → no locality
- calling `Location_Service::set_customer_record()` alone → the fields still drive the request, so
  the locality does not change at all

Setting a matching pair works first time:

```text
billing_state = КРАСНОДАРСКИЙ КРАЙ   billing_city = Краснодар
→ ?locality=Краснодар → 1 point
```

## ✅ How to tell the two failures apart

Wrap `fetch` before opening the modal and read the URL back:

```js
window.__f = [];
const of = window.fetch;
window.fetch = function (...a) { window.__f.push(String(a[0])); return of.apply(this, a); };
```

- URL **carries** `locality=…` and the list is empty → the point SOURCE really has nothing there.
- URL **omits** `locality` → the region/settlement pair does not resolve; the source was never
  asked. Check `#billing_state`'s rendered value FIRST — an empty select is the tell.

## Restoring the rig after such a measurement

Back up `woodev_customer_location` **and** `billing_state`/`billing_city` before writing any of
them, and verify the restore by reopening the modal and seeing the expected row count — not by
comparing the record alone. A stale WooCommerce SESSION can also hold the old pair after the user
meta is restored; the record matching its backup byte-for-byte does **not** prove the checkout is
back.

## Related

- [the-default-locality-option-stores-a-whole-record-not-a-key.md](the-default-locality-option-stores-a-whole-record-not-a-key.md) — what that option really holds
- [rig-checkout-url-is-the-block-checkout.md](rig-checkout-url-is-the-block-checkout.md) — the other way the pickup layer looks broken when it is not
- [a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there.md](a-rig-measurement-on-a-timer-invents-a-defect-that-is-not-there.md) — poll and add a control, do not read on a timer
