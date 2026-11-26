
# Wildberries Seller 1.0 — owner decisions (26.11.2026)

The owner answered [the eleven questions](owner-questions.ru.md) on 26.11.2026. These answers are **final**: a design
or implementation session does not reopen them. Where another file of this folder disagrees — the recommendations in
the questions file, the estimate in [the card plan](card-plan.md) — **this file wins**. The design built on them:
[Wildberries Seller 1.0 plugin design](../../specs/2026-11-26-wildberries-seller-plugin-design.md).

## Product

| | Decision |
|---|---|
| Name and version | A **new** plugin; the first release is **1.0.0**. The research wrote «2.0» because it copied Ozon Seller's numbering — that wording is corrected in this folder. |
| Card | [#1670](https://github.com/kalbac/woodev-plugin-framework/issues/1670) (build it, despite the weak demand measurement) |

## Decisions

| Id | Question | Decision |
|---|---|---|
| D1 | Sales schemes | **FBS and DBS in 1.0.** A DBS order becomes an ordinary WooCommerce order with a shipping address, so that our delivery plugins (CDEK, Yandex) can ship it. **Before the DBS flow is designed, phase 0 checks two risks:** (1) Wildberries gives a relay phone with an extension instead of the buyer's number — will CDEK and Yandex accept it for the recipient, and do their SMS and courier calls reach the buyer; (2) «received» may need the buyer's confirmation code — how that works when a carrier, not the seller, hands the parcel over. ~~Stated fallback: if either check fails, DBS moves to the next release.~~ (superseded by the note that follows) **Owner's note of the same day (answer to tension T1 of the design): no real test order is possible — Wildberries forbids self-purchases and the test cabinet cannot take orders. DBS STAYS in 1.0.** The two checks are made from the API documentation, simulated requests and fixtures, and the Wildberries sandbox where it has test orders; what that cannot prove is a stated known residual risk, **not** the move-to-next-release fallback. DBW, in-store pickup and FBW are not in 1.0. |
| D2 | Product cards | Link existing Wildberries cards, import Wildberries → WooCommerce, and create Wildberries cards from WooCommerce products. An edit on Wildberries changes **only the plugin's own fields**: read the card first, change only ours. No delete, no trash. |
| D3 | FBS supplies | A new page «Поставки» with the minimal full cycle: create, add orders, stickers, pickup point and date, cargo places, hand over, QR. No warehouse passes, no auto-returns. |
| D4 | Marking | In 1.0 a «Честный знак» code field in the order (typed or scanned) and a clear «what is missing» list in the supply. Other code kinds come in the next release, but the plugin **names** what is missing. Phase 0 checks whether those codes can be entered in the Wildberries cabinet for an order that is run from the shop; if they cannot, the plugin description says so. |
| D5 | Token | The seller's **personal token**. No question to Wildberries support: a site with our plugin is not a «service». |
| D6 | Testing | **The live cabinet may be used directly, writes included** (a friend's Wildberries shop, dormant for about two years). Hard rules, repeated in every brief: **never delete anything** in the cabinet; **stocks on the live cabinet stay 0**, except short checks on the owner's explicit word. The tokens are kept in `/Users/maksimmartirosov/Projects/woocommerce-wildberries-seller/.env` (gitignored): `WB_LIVE_TOKEN` — the live personal token, `WB_TEST_TOKEN` — the sandbox one (coordinator's update of the same day; first the place was the owner's project `open-warehouse-saas`). A brief names the file and the variable and never prints a value. **Owner's note of the same day: no real orders on the live cabinet** — FBS and DBS order and supply flows are exercised with the sandbox test token. The cabinet's standing with Wildberries may make it refuse some write calls; phase 0 records which are refused. |
| D7 | Size prices | Send per-size prices where Wildberries allows them. Where it does not and the shop's sizes have different prices — do not send, and show the reason at the product. |
| D8 | Warehouses | As in Ozon Seller: a default warehouse per account and an own warehouse per product; one product feeds one warehouse. On Wildberries one warehouse serves one scheme, so FBS and DBS have their own warehouses. |
| D9 | Menu | Товары, Заказы (FBS and DBS together, with a filter by scheme), Поставки, Сопоставление, Инструменты, Диагностика, Настройки; the shared marketplace «Аналитика» as it is. No pages for reviews, questions, advertising, finance or returns. |
| D10 | Orders | One WooCommerce order per buyer cart per warehouse; the cancellation of one unit shows as its own line. |
| D11 | Returns | Not in 1.0 — card [#1991](https://github.com/kalbac/woodev-plugin-framework/issues/1991). |
| D12 | Article (vendorCode) change — tension T6 | **Owner, 27.11.2026.** In 1.0 the article changes **only in the Wildberries cabinet**; the plugin follows the change (§5.1 of the design). A rename from the shop is **not** in 1.0 and comes later — card [#2055](https://github.com/kalbac/woodev-plugin-framework/issues/2055) («Wildberries после 1.0: смена артикула из магазина»). |
| D13 | DBS relay phone extension — tension T7 | **Owner, 27.11.2026.** In 1.0 the plugin does **not** pass the relay number's extension to CDEK or Yandex; the manager sees a warning when the number needs one (§7.3 of the design). Passing the extension to the carriers comes later, on the plugin side (owner of the delivery plugins: `bot:Plugins`). No card exists for that yet. |

## Standing rules that bind the design

| Id | Rule |
|---|---|
| R1 | All logic that is not specific to one marketplace lives in the framework module `woodev/marketplace/`, in the Shipping / Payment pattern; the plugin keeps its adapter, API client and Wildberries-only rules. |
| R2 | Admin pages are PHP-driven React. |
| R3 | Tables reuse the mechanisms of «Заказы доставки»; nothing is invented beside them. |
| R4 | The licence gate is the default. |
| R5 | A convenience the merchant can see ships in the current release, not in a «phase 2». |

**Owner's exception, 26.11.2026 (answer to tension T2):** the standing rule «delivery code is not touched» (s224; B3 of the marketplace module design) is lifted for **one card** — WD1, the «Оформить доставку» entry of the delivery order wizard for an existing order — so that a DBS order can be shipped with CDEK or Yandex. Every other Wildberries card keeps the rule.

## What the decisions changed against the research

| Research said | Decision |
|---|---|
| «2.0», «after 2.0» | 1.0.0, «after 1.0» |
| FBS only; DBS in the next release (Q1 ★) | FBS and DBS in 1.0, unconditionally (the «two real-order checks» condition was superseded the same day — D1 note) |
| Ask Wildberries support whether a paid plugin is a «service» (Q5 ★) | No question is asked |
| Writes with a Test token in the sandbox; a real cabinet read-only (Q6 ★) | The live cabinet, writes included, under the two hard rules; the sandbox stays available for emulated orders |
| Supplies as a plugin page ([card plan](card-plan.md), WP8) | The page is built in the framework module (R1); the plugin supplies the Wildberries half |

## Related

- [Research index](README.md)
- [Owner questions](owner-questions.ru.md)
- [Wildberries Seller 1.0 plugin design](../../specs/2026-11-26-wildberries-seller-plugin-design.md)
- [Gap map](gap-map.md)
