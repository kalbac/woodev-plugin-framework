
# Ozon and Wildberries: differences that change the merchant flow

Wildberries facts: [API map](api-map.md). Ozon facts are what Ozon Seller 2.0 does on the framework module
([design](../../wiki/marketplace-admin.md), [importer spec](../../specs/2026-11-14-marketplace-card-importer.md));
Ozon's API was not re-read for this research.

| Topic | Ozon Seller 2.0 | Wildberries | What the merchant notices |
|---|---|---|---|
| Connection | Client ID + API key | One token, 180 days, chosen type and categories | Paste one field; wrong type or a missing category must be explained; renew twice a year |
| Card model | One card per sellable variant; article per variation | `nmID` per colour/variant; sizes (`chrtID`) inside; article per `nmID`; groups (`imtID`) ≤ 30 | A colour × size product becomes several cards; "the article of a variation" does not exist |
| Identity of a unit | Article | Barcode, or article + size | Matching existing cards should use barcodes |
| Barcodes | Secondary | Every size has one; WB generates them if absent | The shop needs a place for them (WooCommerce GTIN) |
| Categories | Category tree + type, many dictionaries | Parent → subcategory; few dictionaries, required and limited-count characteristics | Mapping is shorter to pick, more free values to fill |
| Card limits | — | Title ≤ 60; description limit by category; dimensions in cm, weight in kg; photos ≥ 700×900; creation quota | Long shop titles need a rule; photos may be refused wholesale |
| Documents | — | Certificates validated up to 3 days; marking confirmation | A card may exist but wait for or fail document checks |
| Creating a card | Submit, get a task number, read the result | Submit, no number; up to 30 min; errors in a separate list; photos sent afterwards | Longer "in progress", a second step for photos |
| Editing a card | Re-send the item | Full overwrite of the card | An export from the shop can erase edits made in the cabinet |
| Price | Price, old price, minimum price, per article; answered per item | Integer price + discount %, per colour card (per size only in some categories); applied later; **quarantine** on a sharp drop | Different prices on sizes may be impossible; a large markdown silently waits in quarantine unless surfaced |
| Stock | Per article per warehouse; reserved quantity readable | Per size per seller warehouse; amount only; a warehouse serves one scheme | Choose the warehouse per scheme |
| Order shape | A posting with several products and quantities | One assembly order per unit; a cart shares an id | The plugin must merge units into one shop order |
| Statuses | One status | Seller status and WB status | Wording must combine them |
| Buyer data | Not given on FBS | FBS none (except Turkey cross-border); DBS / pickup give name and a relay phone; DBW address | DBS orders carry personal data into the shop |
| New orders | Push plus polling | Polling only | A delay of up to the poll interval; the buyer can cancel in the first hour |
| Hand-over (FBS) | Pack and print the posting label | **Supply**: collect orders, print stickers, choose date and shipping point, boxes for pickup points, deliver, print the supply QR | A new daily screen; without it the order cannot leave `new` from the shop |
| Label | PDF per posting, prepared asynchronously | PNG / SVG / ZPL sticker 58×40 per unit, immediately, only once in a supply | Printing is a sticker sheet or a label printer |
| Marking codes | — in our scope | Required identifiers block the delivery of the whole supply | Codes must be entered (or scanned) before shipping |
| Cancel | With a reason | No reason; per unit; only before hand-over | Simpler, but one shop order may be cancelled in part |
| Returns | Ozon's own flow | Buyer claims to approve or reject within days; goods return to a pickup point with an expiry | A deadline-driven inbox we do not have |
| Schemes | FBS (as built) | FBS, DBS, DBW, pickup, FBW — each with its own warehouse and flow | The plugin must say which it supports |
| Testing | No test orders; self-purchase forbidden (operator, 24.11.2026) | A sandbox with test tokens and emulated buyer actions (hosts for content, prices, marketplace, feedbacks) | Better acceptance before a real shop — if the sandbox proves faithful (UNVERIFIED) |
| Rate limits | Per method group | Per category and account; 4xx costs ten; Base token unusable | Large catalogues export slowly: 10 create requests a minute, up to 100 groups each |

## The FBS day, as the merchant would live it

```text
Ozon:  order arrives → pack → «Этикетка» (PDF) → hand over
WB:    order appears (poll) → add to supply → [enter marking codes] → print stickers
       → choose date + shipping point → [boxes, if a pickup point] → «Передать в доставку»
       → print supply QR → hand over
```

## Related

- [Research index](README.md)
- [API map](api-map.md)
- [Gap map](gap-map.md)
- [Owner questions](owner-questions.ru.md)
