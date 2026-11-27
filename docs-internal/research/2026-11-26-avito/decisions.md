# Avito plugin 1.0 — owner decisions (27.11.2026)

The owner answered [the thirteen questions](owner-questions.ru.md) on 27.11.2026: every one by its recommendation
(«всё по рекомендациям»; recorded on [#1986](https://github.com/kalbac/woodev-plugin-framework/issues/1986)). Each row
below is that recommendation in substance; the Russian original is the questions file. These answers are **final**: a
design or implementation session does not reopen them. Where another file of this folder disagrees — the estimate in
[the card plan](card-plan.md) included — **this file wins**. The design built on them:
[Avito plugin 1.0 design](../../specs/2026-11-28-avito-plugin-design.md).

## Product

| | Decision |
|---|---|
| Plugin | A new plugin on `woodev/marketplace/`; the first release is 1.0 |
| Order of work | After Wildberries 1.0 (owner, 26.11.2026, #1986; D12 below) |
| Name | Not chosen (D2). The design uses a neutral working name |
| Support letter | Prepared; the owner sends it before paying for a tariff (#1986, 26.11.2026) |
| Card | [#1986](https://github.com/kalbac/woodev-plugin-framework/issues/1986) |

## Decisions

| Id | Question | Decision |
|---|---|---|
| D1 | Scope of the first version | **Feed + load report with the link to the listing + stocks + Avito Delivery orders + prices.** A part the seller has no access to (tariff, subscription, company status) is **switched off with a clear reason**. No chats, promotion, reviews or statistics. |
| D2 | Name | **A letter to Avito support first** (API terms 2.7); the name, slug and icon are **not chosen before the answer**. Slug and identifiers cannot change after a release. |
| D3 | Delivery methods in orders | **Avito partners: pickup points, parcel lockers, the Yandex courier** — list, confirm, cancel, labels (pickup-point orders only), choice of the courier's time. The seller's own delivery (tracking number), own courier and self-pickup come in the next release: their orders are **visible** in the plugin, and actions on them are taken in the Avito cabinet. |
| D4 | Categories | **Goods sections only** (personal items, home and garden, electronics, hobbies, goods for animals, equipment, spare parts). No cars, real estate, jobs or services — other fields, rules and tariffs. The category fields come from Avito's directory; the plugin is checked on **2–3 categories and says so plainly**. |
| D5 | Listings the seller already has | **Linked.** If the `Id` of an earlier feed equals the product's SKU — automatically; the rest by hand, by choosing the product in the list of listings. The plugin takes a listing under management **only when the shop has every required field filled**, and adds **its own** feed link **without removing others** (Avito allows up to three). Description and photos are then replaced by the shop's data — **the merchant is warned**. |
| D6 | A product sells out or is withdrawn | **Out of stock → stock 0, the listing stays. Product deleted or unpublished → the listing is archived** (removed from the feed), **with a warning**. If the feed was built incompletely, the plugin **serves the previous version**. |
| D7 | Prices | **The price comes from the shop by markup rules: sent through the API at once, and the same price in the feed.** |
| D8 | Addresses and cities | **One default address per account, which a category and a product can replace. One product — one listing.** |
| D9 | Paid services | **The plugin does not spend money:** it fills no paid services; placement comes only from a bought package; the schedule of the feed load is set by the seller, or by the plugin **with the seller's confirmation**; the «Загрузить сейчас» button carries a **warning**. |
| D10 | Chats | **Not in the first version** (they need the «Максимальная» subscription and their own conversation interface). |
| D11 | Marking and documents | In 1.0 the **ТН ВЭД and ОКПД 2** codes and the document fields are **taken from product properties**; a **«Честный знак» code is entered in the order** (together with the same work for Wildberries). |
| D12 | Order against Wildberries | **Wildberries first**; the shared framework extensions are **designed for both** from the start. The Avito measurement may be made earlier: it is not in the way. |
| D13 | Whether to build at all | **Go on to the measurement and the specification, and confirm the scope of the first version after them** — by then it is known what really works on a cabinet. |

## Standing rules that bind the design

| Id | Rule |
|---|---|
| R1 | All logic that is not specific to one marketplace lives in the framework module `woodev/marketplace/`; the plugin keeps its adapter, API client and Avito-only rules. |
| R2 | Admin pages are PHP-driven React. |
| R3 | Tables reuse the mechanisms of «Заказы доставки»; nothing is invented beside them. |
| R4 | Adapter contracts change only through **new optional interfaces**; the released Ozon Seller 2.0.x keeps working. |
| R5 | The licence gate is the default. |
| R6 | A convenience the merchant can see ships in the current release, not in a «phase 2». |

## What the decisions changed against the research

| Research said | Decision |
|---|---|
| Open questions with a recommendation each | Every recommendation accepted (D1–D13) |
| The [card plan](card-plan.md): 2 phase-0, 7 framework and 9 plugin cards | Superseded by the card list of the design, which also reads the framework after the Wildberries cards that merged since |

## Related

- [Research index](README.md)
- [Owner questions](owner-questions.ru.md)
- [Avito plugin 1.0 design](../../specs/2026-11-28-avito-plugin-design.md)
- [Gap map](gap-map.md)
