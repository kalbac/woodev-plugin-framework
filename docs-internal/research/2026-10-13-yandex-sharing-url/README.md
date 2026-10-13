# Yandex Delivery documents and sharing URL measurement

## Status

- **Contour:** `https://b2b.taxi.tst.yandex.net`, using the public test token stored in the v1 plugin source. The token value is not reproduced here.
- **Date:** 2026-10-13.
- **Requests:** A synthetic Moscow `offers/create` call with `info.referral_source = "woodev"`, followed by a read-only `request/info` lookup for a deliberately nonexistent request ID. No personal data was used.
- **Results:** Both calls returned HTTP 200, `Content-Length: 0`, no `Content-Type` header, and an empty body. `offers/create` returned no offers or identifiers.
- **Safety:** No offer was confirmed, no request was created, and no `request/*` document call was made because there was no request ID.

## Findings

- `sharing_url` location is **unverified**: neither response contained a body.
- `request/generate-labels` and `request/get-handover-act` response content type and body encoding are **unverified**: neither endpoint was called because a real request ID was unavailable.
- The Y10b implementation only passes a response through as a downloadable document when its bytes begin with `%PDF-`; empty bodies and carrier error replies become framework document errors.
- The test-contour empty `offers/create` response is recorded in the plugin test fixture `tests/fixtures/yandex-documents/empty-response.json` (HTTP metadata only; no token or personal data).

## Follow-up

Repeat on the test contour once `offers/create` returns its offer payload. Then confirm one synthetic Moscow offer, record the redacted `sharing_url` shape, read `request/info`, and inspect each PDF endpoint's status, content type, and first bytes. Never use a production `y0_` token or production host for confirmation or any `request/*` call.

## 2026-10-13 addendum (Y14)

The empty `200` replies recorded above were **not** caused by the request. The test host answers `200` with an empty body for every path, token and header combination, including a nonexistent path and a missing or invalid token, while the production host returns a normal `401` JSON for a bad token. See `../2026-10-13-yandex-test-contour-acceptance/README.md` for the probes. Still unverified live: the `sharing_url` location, the `generate-labels` / `get-handover-act` content type and body encoding.

Per Yandex's published reference (not a live measurement): `sharing_url` is a field of the `request/info` 200 response and is **not** in the `offers/confirm` 200 response (which carries only `request_id`), so the poller back-fill from `request/info` is the documented path. `request/generate-labels` and `request/get-handover-act` answer `200` with `application/pdf`, binary (no base64 envelope), which matches the raw-`%PDF-` source; no error body is documented for either.

## Related

- Spec: [`../../specs/2026-10-12-yandex-v2-plugin-design.md`](../../specs/2026-10-12-yandex-v2-plugin-design.md) §9.3 (documents, `sharing_url`).
- Y14 acceptance: [`../2026-10-13-yandex-test-contour-acceptance/README.md`](../2026-10-13-yandex-test-contour-acceptance/README.md) (the test contour is silent; docs-based shapes).
