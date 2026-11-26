# Wildberries Seller 1.0 — phase-0 measurement

Card [#1995](https://github.com/kalbac/woodev-plugin-framework/issues/1995) (M0a): items 1–11a of
[§3.1 of the design](../../specs/2026-11-26-wildberries-seller-plugin-design.md), on a live seller cabinet, with no
orders. No token, seller name, buyer data or cabinet identifier is written here: ids of cards, warehouses and supplies
are shown as placeholders.

## M0a — planned writes (listed before anything was sent)

Every request that changes the cabinet, in the order planned. Each is sent **once** and a refusal is not retried.
There is no `DELETE` and no trash request in this plan. Test cards are counted by `nmID`: three at most (A, B-black,
B-white).

| # | Request | What it leaves in the cabinet |
|---|---|---|
| W1 | `POST /content/v2/barcodes` `{count: 4}` | four reserved barcodes |
| W2 | `POST /content/v2/cards/upload` — card A (`WOODEV-TEST-A`) with one deliberately invalid field: six colour values where the subject allows five | a failed-card entry in the error list; if the field is accepted, card A itself |
| W3 | `POST /content/v2/cards/upload` — card A corrected (only if W2 created no card) | test card A, titled as a test |
| W4 | `POST /content/v2/cards/upload` — group B: two colours (`WOODEV-TEST-B-BLACK`, `WOODEV-TEST-B-WHITE`), two sizes each, barcodes from W1 | two test cards merged into one group |
| W5 | `POST /content/v3/media/save` — card A, two placeholder pictures (900×1200, `dummyimage.com`) | photos on card A |
| W6 | `POST /content/v2/cards/update` — card A, description only, every other field sent back as read | a changed description |
| W7 | `POST /content/v2/cards/update` — card A, `vendorCode` → `WOODEV-TEST-A2` | card A renamed, if accepted |
| W8 | `POST /api/v2/upload/task` — prices and discounts of the three test cards (rounding) | a price on each test card, an upload in the price history |
| W9 | `POST /api/v2/upload/task` — one test card, a price about ten times lower | the card in price quarantine, or the low price |
| W10 | `POST /api/v2/upload/task` — the same card, a normal price again (only if W9 was quarantined) | a normal price; quarantine released |
| W11 | `POST /api/v2/upload/task/size` — only if a test card reports `editableSizePrice: true` | per-size prices on that card |
| W12 | `PUT /api/v3/stocks/{warehouseId}` — test-card sizes, `amount: 0` | stock records with amount 0 |
| W13 | `PUT /api/v3/stocks/{warehouseId}` — the wrong-field-name probe: `chrtID` instead of `chrtId`, `amount: 0` | nothing beyond W12 (amount is 0 either way) |
| W14 | `POST /api/v3/supplies` — one supply named as a test, no orders added, never delivered | one empty supply that cannot be removed under the rules |
| W15 | added after W9 was refused as more than twice lower, listed before sending: `POST /api/v2/upload/task` — the same card, 1000 → 600 (1.67 times lower), to observe the quarantine itself | the card in price quarantine until W10, or the low price |

## Related

- [Wildberries Seller 1.0 plugin design](../../specs/2026-11-26-wildberries-seller-plugin-design.md)
- [API map](api-map.md)
- [Owner decisions](decisions.md)
