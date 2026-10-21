# R1 — Ф7 label for an order outside a batch (card kalbac/woodev-russian-post#1)

Run: 21.10.2026, TEST Otpravka cabinet, 43 Otpravka requests (budget 60). Raw dumps: (kept outside the repo) (`calls.log` = every request; `ledger.json` = ids; PNG page-1 renders next to the PDFs). No credential value was printed or saved. Auth headers are never written to the dumps.

## Verdict

**Ф7 without a batch: NO** for every product the plugin needs (ONLINE_PARCEL → address, ONLINE_PARCEL COMBINED → postamat, PARCEL_CLASS_1; also ONLINE_COURIER and POSTAL_PARCEL). No documented or guessed route returned a label for an order that is only in «Новые». The only exception measured is **EMS**: `GET /1.0/forms/backlog/{id}/forms` returned a 1-page label (E-1 form) straight from «Новые».
**Spec §8.6 decision: take branch 2** — offer the label button only when the order is already in a batch (detect via `GET /1.0/shipment/search?query=<barcode>`); no implicit single-order batch. Branch 1 does not apply (EMS is the one case; see "design note").

## Answer table

Orders (all created with `PUT /2.0/user/backlog`, recipient surname `TestovR1`, order-num `R1-<x>-<ts>`, distinct house/phone so no MMO grouping): a = ONLINE_PARCEL ORDINARY → address (NSK 630099), h = ONLINE_PARCEL COMBINED_ORDINARY → postamat 917825 (`ecom-data` + `dimension-type:S`), c = PARCEL_CLASS_1, e = ONLINE_COURIER, d = EMS, b = POSTAL_PARCEL. A BANDEROL create was refused (`UNAVAILABLE_MAIL_TYPE`, account switch) — untested.

### Orders in «Новые» (no batch)

| Question / route | Order | HTTP | Content-Type | Result | Evidence |
|---|---|---|---|---|---|
| `GET /1.0/forms/backlog/{id}/forms?sending-date=` (Accept */*) | a ONLINE_PARCEL | 500 | application/json | `{"code":"1002","desc":"Forbidden mail type","sub-code":"INTERNAL_ERROR"}` | `a-forms-backlog+date.json` |
| same, no `sending-date` | a | 500 | json | same | `a-forms-backlog-nodate.json` |
| same, `&print-type=THERMO` | a | 500 | json | same | `aV-backlog-forms-thermo.json` |
| same, `Accept: application/pdf` | a | 403 | json | `1007 Access Denied` (the Accept trap from G: not a real permission error) | `aV-backlog-forms-pdf-accept.json` |
| same (+date, no date) | h postamat COMBINED | 500 ×2 | json | `Forbidden mail type` | `h-forms-backlog+date.json`, `…nodate.json` |
| same (+date, no date) | c PARCEL_CLASS_1 | 500 ×2 | json | `Forbidden mail type` | `c-forms-backlog+date.json`, `…nodate.json` |
| same | e ONLINE_COURIER | 500 | json | `Forbidden mail type` (docs claim Ф7п for курьер-онлайн here — not true on this account) | `e-forms-backlog.json` |
| same | b POSTAL_PARCEL | 500 | json | `Forbidden mail type` | `b-forms-backlog.json` |
| same | **d EMS** | **200** | **`application/zip; charset=UTF-8` but the body is a plain PDF** (`%PDF-1.4`, iText) | **93 907 B, 1 page**: page 1 = thermal 100×150-style label «EMS ВНУТРИРОССИЙСКОЕ» with barcode EF…RU, sender block, recipient block, index 630099, order-num `R1-d-…` at the top-left; the date stamp is empty (no «23.10.2026» line) | `d-forms-backlog.pdf`, `d-forms-backlog-p1.png` |
| `GET /1.0/forms/{id}/f7pdf` (with and without `sending-date`) | a, h, c | 404 ×6 | json | `Instance ComplexOrderV2 not found for params: <id>` — f7pdf looks the order up in the shipment (batch) store, not in the backlog | `a-f7pdf+date.json`, `h-…`, `c-…` |
| `GET /1.0/forms/{id}/forms?sending-date=` (the "shipments" route) | a, h | 404 ×2 | json | `ComplexOrderV2 not found` (same reason) | `aV-shipments-forms-nobatch.json`, `hV-…` |
| `GET /1.0/forms/backlog/{id}/f7pdf` (guessed variant, not in the docs) | a | 500 | json | `No endpoint GET /1.0/forms/backlog/<id>/f7pdf.` | `aV-backlog-f7pdf.json` |

The docs list exactly three label routes (read from the Strapi articles, `support-article-business-otpravka-api-generate-{forms-backlog,forms-shipments,f7-f22}`): `forms/backlog/{id}/forms`, `forms/{id}/forms`, `forms/{id}/f7pdf` (+ `f112pdf`, `easy-return-pdf/{barcode}`, batch forms). All were tried; no other "forms/backlog" variant exists in the spec.

### For comparison: the same orders IN a batch

`POST /1.0/user/shipment?sending-date=2026-10-23` with [a, h, d] → one batch **818** (`mail-type COMBINED`, `combined-batch-mail-types:["EMS"]`, no errors).

| Route | Order | HTTP | Content-Type | Result | Evidence |
|---|---|---|---|---|---|
| `GET /1.0/forms/{id}/f7pdf?sending-date&print-type=THERMO` | a ONLINE_PARCEL | 200 | application/pdf | **94 866 B, 1 page**: «ПОСЫЛКА ОНЛАЙН», barcode 802234 26 45248 2, sender, recipient + address, 630099; stamp «г. Москва 23.10.2026 109012» | `B-a-f7-thermo.pdf/.png` |
| same | h postamat | 200 | application/pdf | **95 079 B, 1 page**: «⦿ Комбинированная», «ПОСЫЛКА ОНЛАЙН», recipient = «АПС 917825 … ул. Геодезическая, д. 23», big «ДОСТАВКА В ПОЧТОМАТ» | `B-h-f7-thermo.pdf/.png` |
| `GET /1.0/forms/{id}/forms?sending-date=` | a | 200 | application/pdf | 94 866 B, 1 page — **byte-for-byte the size of the f7pdf output**, so `forms` == `f7pdf` for ONLINE_PARCEL | `B-a-forms-inbatch.pdf` |
| same | d EMS | 200 | application/pdf | 93 926 B, 1 page, same layout as the backlog EMS label **plus the date «23.10.2026»** in the stamp | `B-d-forms-inbatch.pdf/.png` |
| `GET /1.0/forms/backlog/{id}/forms` after batching | a, d | 404 ×2 | json | `Instance BacklogTuple not found` — the backlog route stops working once the order leaves «Новые» | `B-a-forms-backlog-inbatch.json`, `B-d-…` |

### Cleanup (proved)

`POST /1.0/user/backlog` [a, h, d] → 200, orders back in «Новые», batch 818 vanished (`GET /1.0/batch/818` → 404). `DELETE /1.0/backlog` [a, c, h, e, d, b] → 200 with all six ids. `GET /1.0/backlog/{id}` → **404 for all six** (`verify-*.json`). 0 leftovers; no historical batch touched; no `checkin`, no hand-over, no courier call. All six orders and the temporary batch are gone.

## Design note for §8.6 (what this means)

1. **Ф7 for ONLINE_PARCEL / COMBINED postamat / PARCEL_CLASS_1 exists only after the order is in a batch** (`f7pdf` and `forms` both work then, both thermo and paper per G). `Document_Source::get_document_types()` must return `f7` only for orders found in a batch; otherwise no `f7` entry (not a disabled button that errors).
2. **EMS is the exception**: its label is available from «Новые» through `forms/backlog/{id}/forms`. If the plugin ships EMS in release 1 it may offer the label earlier; the same label after batching carries the sending date. Worth one line in the spec, not a separate path for the other products.
3. **Client must not trust Content-Type**: the EMS backlog response is labelled `application/zip` but is a PDF. Sniff `%PDF` (the framework's `Document_Result` should accept by magic bytes).
4. The error text for the backlog route is `500 Forbidden mail type` (a 5xx for a business "not available") — map it to «label not available before the order is in a batch», never to a retryable carrier failure.
5. The «not in a batch» branch of the UI needs the merchant to batch in the cabinet; detection by `GET /1.0/shipment/search?query=<barcode>` was NOT re-measured here (G covers it).

## UNVERIFIED

- Why only EMS passes: the docs say ф7п for бандероль and курьер-онлайн also pass; measured 500 for ONLINE_COURIER and POSTAL_PARCEL. Possibly an account/product switch of this test cabinet, possibly docs drift — cannot tell. A production cabinet could behave differently; one re-run on a real account would settle it.
- `forms/backlog` for PAPER print-type (only THERMO tried on the backlog route; it failed identically for non-EMS).
- BANDEROL (create refused by the account), international products (K covers: 500 `Forbidden mail type` before batch), ECOM/ППВЗ products (not on this account).
- Whether `sending-date` in the past/future changes the backlog-route outcome for non-EMS (tried: with date, without date — same 500).

## Requests used

43 of 60 Otpravka requests (3+12 initial create/route matrix, 5 variants, 4 extra creates + 3 backlog calls, 1 batch + 6 comparison docs, undo 1 + batch check 1 + delete 1 + 6 verifies). See `r1-raw/calls.log`.

## Related

- [README](README.md) — the consolidated fact base
- [Russian Post v2 design spec](../../specs/2026-10-21-russian-post-v2-plugin-design.md)
