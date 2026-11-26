
# Avito: rough card list (estimate)

Filing briefs for the coordinator, **not filed cards**. Written against the recommendations of
[the owner questions](owner-questions.ru.md). S/M/L are relative size; model tiers follow the project rule and every
card gets a critic of another lineage. Delivery code is not touched. «G n» is a row of [the gap map](gap-map.md);
«WF n» is a card of the [WB card plan](../2026-11-26-wb-seller/card-plan.md).

## Phase 0 — measure, then design

| Id | Size | Depends on | Scope · acceptance |
|---|---|---|---|
| M0 — Measurement on a test cabinet if support gives one, else a real one | M | [access](access-needed.ru.md) | A feed of 2–3 ads on a public URL (profile, schedule, `upload`, fetch addresses), report v4 timing and message shapes, `avito_id` mapping, adopt a hand-published ad by `AvitoId`, a second feed beside an existing one; stock write/read (reserved, the «several items» switch, refusal without subscription); price by API then a reload (conflict C7); category fields of two categories; with separate permission one order: statuses, `items[].id`, labels, errors. Accept: every UNVERIFIED line marked proven or still open; money spent reported. |
| S0 — Design spec | M | M0, owner answers | Feed snapshot lifecycle and its safety rules, adoption, money safety, price/stock precedence, order actions, categories offered. Accept: critic pass; cards re-estimated. |

## Framework (`woodev-plugin-framework`)

| Id | Size | Depends on | Overlap | Scope · acceptance |
|---|---|---|---|---|
| AF1 — Feed publication mode | L | S0 | new | G 6. Stored item per link, streamed snapshot, tokenised public URL, rebuild scheduling, fetch log, previous snapshot served when a build fails, an empty or suddenly shrunk snapshot is held for the merchant. Accept: fake adapter; 10 000 ads within memory limits; API-push adapters unchanged. |
| AF2 — Results without a task | M–L | AF1 | extends WF2 | G 7–8. Results read by article from reports; schedule-driven polling and long deadlines. Accept: late, partial and replaced reports. |
| AF3 — Marketplace without warehouses | S–M | — | new | G 13. Capability; no warehouse selects or reasons; stock keyed without a warehouse. Accept: Ozon behaviour unchanged. |
| AF4 — Conditional fields | M | S0 | new | G 4–5. Visible / required / hidden rules in `Field_Definition`, the mapping screen and the validator; account-level defaults if missing. |
| AF5 — Safe adoption of linked listings | S | AF1 | new | G 10a. The existing manual link stays; feed inclusion waits until required fields are filled, a seam to warn or refuse at link time, no first stock/price send before adoption. A typed-number input only if M0 shows the list is not enough. |
| AF6 — Order actions | L | S0 | **= WF6** + forms | G 19–20. Cancel without reasons; adapter-declared actions, including ones with input (tracking number, courier interval, codes) and deadlines shown on the order. |
| AF7 — Capability names, wording, dead contracts | S | AF6 | **= WF8**, part of G 12 | G 12, 26, 27. Export and linking not tied to import; nouns from the descriptor. |

## Plugin (new repository, name after Q2)

| Id | Size | Depends on | Scope · acceptance |
|---|---|---|---|
| AP1 — Skeleton and connection | M | S0 | Adapter, API client, token, errors, buckets, account check, plain explanations of what the tariff does not allow, diagnostics, licence. |
| AP2 — Categories, mappings, validation | L | AF4, AP1 | Tree and fields cache, value lists, common ad fields, address, delivery and document fields, photo rules and WebP conversion. |
| AP3 — Feed and autoload profile | L | AF1, AP2 | Ad XML, profile read-merge-write, schedule with confirmation, no paid options, upload button with a warning, host checks (public HTTPS, blocked bots). |
| AP4 — Load report and listing states | M | AF2, AP3 | Uploads, per-ad messages in the products table, `avito_id` collection. |
| AP5 — Existing listings | M | AF5, AP3 | Resolver (ids for hand-published ads), linking on the existing screen (needs AF7, or the declared-capability workaround of G 12), adoption. |
| AP6 — Stock and prices | M | AF3, AP4 | Writers, readers, formula from M0, subscription refusal. |
| AP7 — Delivery orders | L | AF6, AP5 | Poll, mapping, accounting, confirm/reject, courier slot, deadlines. |
| AP8 — Labels and «Честный знак» | M | AP7 | PDF A6 / thermal; codes before confirm. |
| AP9 — Acceptance and release materials | M | all | Rig and cabinet acceptance with an honest «not proven» list, merchant docs, release package. |

## Order and size

```text
M0 → S0 → AP1
AP1 + AF4 → AP2
AP2 + AF1 → AP3
AP3 + AF2 → AP4
AP3 + AF5 → AP5
AP4 + AF3 → AP6
AP5 + AF6 → AP7 → AP8 → AP9
AF7 after AF6
```

2 phase-0 cards, 7 framework cards (2 L, 2 M or M–L, 3 S or S–M), 9 plugin cards (3 L, 6 M). If Wildberries ships
first, AF6 and AF7 shrink to their Avito additions; AF2 starts from WB's work.

## Related

- [Research index](README.md)
- [Gap map](gap-map.md)
- [Marketplace module design](../../wiki/marketplace-admin.md)
