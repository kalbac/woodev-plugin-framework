# R3 — Russian Post tracking by token: measurement report (card kalbac/woodev-russian-post#3, s191, wave 0)

Scope: read-only. Otpravka: 3 GET requests (batch list, two batch shipment lists). Tracking: 15 GET requests (cap 20). No order created, nothing modified, no courier call, checkin, batch hand-over or payment. Leftovers: none (nothing created). Raw responses and per-request metadata: (kept outside the repo) (`*.meta.json` holds the masked request headers, status, timing and all response headers; `*.body` the raw body). Secret scan of that folder: 0 files contain a credential.

## Answer table

| # | Question | Result | Evidence |
|---|---|---|---|
| 1 | Does a 4xx count against the tracking limit? | **UNVERIFIED — not measurable with this token.** No response (200, 400 or 403) carries any rate-limit or quota header; the only extra header is `x-envoy-upstream-service-time`. No limit endpoint is documented (the tracking sibling pages were already read in L; none describes one). Observed 4xx: 400 `{"code":"1003"}` and 403 with empty body. Whether they are counted cannot be seen from the API. | `R3-raw/tracking-t08*`, `t09*`, `t14*`, `t15*` (meta.json, resp_headers) |
| 2 | Real daily limit, if exposed | **Not exposed** in headers or any endpoint. Docs still disagree (FAQ 5 000/day vs Terms 100/day, from L). Not settled; keep the conservative default of 100 (spec §10.3). | L report §1; this run shows no header |
| 3 | Status group codes returned | **Four codes, all observed:** `RECEPTION_EXPECTED` («Оформлено»), `IN_WAY` («В пути»), `READY_FOR_DELIVERY` («Готово к вручению»), `GIVING` («Вручено»). Only the S10 barcode (`RA644000001RU`) has a full history: it carries actual `IN_WAY` and `READY_FOR_DELIVERY` groups (`future: false`, dated events, 2015) and a predicted `GIVING` (`future: true`, no events). No `TO_SENDER` (return) group seen. The full published list is still not available. | `R3-raw/tracking-t01*`, `t10*` |
| 4 | Does the `Language` header or the `language` query param change the text? | **No, in every combination tried.** Domestic barcode: default, `language=EN`, `language=RU`, `Language: EN`, `Language: RU` → byte-identical bodies (2 113 bytes). S10 barcode: default vs `language=EN` → byte-identical (3 322 bytes). Titles and `humanStatus` stay Russian. Not tested: `Accept-Language`, or a barcode whose record is in another state. | `R3-raw/tracking-t01..t05*`, `t10*`, `t13*` |
| 5 | Response time | 15 calls, wall clock 0.055–0.272 s, median about 0.10 s; `x-envoy-upstream-service-time` 3–202 ms. Fast enough for the poller's per-barcode queue. | meta.json `elapsed_s` |

## Other findings (not asked, but they change the mapper)

- **A valid-format barcode that does not exist returns HTTP 200, not 404.** `00000000000000`: 200 with an object of nulls, `mailTypeText: "Не определено"`, `destinationCountryName: "Россия"`, and **`trackingItemOperationGroupStatuses: null`** (null, not an empty list). The mapper must treat a null list as "no data" (status `unknown`, no events) and must not crash on it. Evidence: `R3-raw/tracking-t08*`.
- **A bad format returns 400** `{"code":"1003", "desc":"... should be in S10 format"}` (`ABC123`, `12345`). This is the 400/1003 that L recorded; the non-existent numeric barcode is a different case.
- **Garbage token → HTTP 403 with an empty body** (confirms L's 403 for a wrong token). The token check and the Otpravka-token check (L) both fail the same way, so the plugin cannot tell "wrong token" from "token revoked" by body.
- The domestic test barcode `80103374008569` (from Otpravka batch 573) has only `RECEPTION_EXPECTED` with two real events; `IN_WAY`, `READY_FOR_DELIVERY` and `GIVING` are `future: true`, as §10.2 expects.

## Requests used

- Otpravka (3 of 3, all 200): `GET /1.0/batch?page=0&size=5` (5 batches, each 1 shipment, status CREATED), `GET /1.0/batch/573/shipment?page=0&size=5` → barcode `80103374008569`, `GET /1.0/batch/611/shipment?page=0&size=5` → barcode `80090886140316`.
- Tracking (15 of 20): t01 A default; t02 A `language=EN`; t03 A `Language: EN`; t04 A `language=RU`; t05 A `Language: RU`; t06 B default; t07 B `language=EN`; t08 `00000000000000`; t09 A with a garbage token; t10 S10 `RA644000001RU`; t11–t12 A repeated (timing); t13 S10 `language=EN`; t14 `ABC123`; t15 `12345`. Counter: `R3-raw/counter.json` → `{"otpravka": 3, "tracking": 15}`.

## Verdict

R3 answers the four questions that can be answered from the API: language does not change the text, the four group codes are the full set seen, response time is about 0.1 s, and the null-list case for unknown barcodes is now measured. It does **not** answer whether 4xx count against the quota or what the real daily limit is: the API exposes neither, so the default cap of 100/day stays until the operator confirms the limit with Russian Post (or a support answer). The poller design in spec §10.3 does not change; the mapper must handle `trackingItemOperationGroupStatuses: null`. Spec §18 U4 stays UNVERIFIED; U6 can be closed for the four codes seen, with the caveat that a full list is still not published.

## UNVERIFIED after R3

- U4: whether 4xx responses count against the quota; the real daily limit (5 000 or 100); any per-minute limit. No header or endpoint shows them.
- U6: the complete list of group codes (only four observed; no return group seen); `Accept-Language` not tested.
- Still open from L: whether the old SOAP `rtm34` answers (not tested, no tracking login); the tracking-token menu path in the cabinet.

## Not checked

- Quota behaviour across a day boundary, and what a quota-exhausted response looks like (would need the daily cap spent).
- Barcodes in a return or delivered state, so the `TO_SENDER` group and the `GIVING` actual form are still unseen on a real record.
- Whether a retried 403 counts (the same test would be needed on the same quota).
- Risk: the tracking token is the only source of the four codes above; two domestic barcodes and one S10 barcode is a small sample.

## Related

- [README](README.md) — the consolidated fact base
- [Russian Post v2 design spec](../../specs/2026-10-21-russian-post-v2-plugin-design.md)
