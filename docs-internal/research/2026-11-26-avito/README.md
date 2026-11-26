
# Avito ↔ WooCommerce plugin — API research, 26.11.2026

Decision researched (operator, 26.11.2026, [#1986](https://github.com/kalbac/woodev-plugin-framework/issues/1986)):
Avito is the candidate plugin after Wildberries; study the API now. Research only: no code changed, no request sent
to `api.avito.ru`.

## Files

| File | Content |
|---|---|
| [api-map.md](api-map.md) | Auth, paid access, rate limits, autoload feed and report, items, stocks, delivery orders, messenger, sandbox; conflicts between official pages |
| [gap-map.md](gap-map.md) | Each capability against `woodev/marketplace/` with file:line; differences from Ozon and WB |
| [access-needed.ru.md](access-needed.ru.md) | What the owner has to set up before measurement, in Russian, step by step |
| [owner-questions.ru.md](owner-questions.ru.md) | 13 v1 scope questions, Russian, each with a recommendation |
| [card-plan.md](card-plan.md) | Estimated framework and plugin cards |

## Verdict

Feasible, on the existing module, with **a publication model the module does not have**. Avito goods listings cannot
be created or edited by API: the shop publishes a feed file that Avito downloads, and learns the result from a
report. Stock, price and delivery orders are ordinary API calls. Accounts, jobs, prices, order writing, labels and
the pages carry over; four v1 paths need framework changes first.

## Top findings

1. **Paid access is real, and split by feature** ([API map §1.2](api-map.md#12-what-needs-a-paid-tariff-a-subscription-or-a-business-account)).
   Keys need a tariff. Autoload in goods needs the «Расширенная» or «Максимальная» subscription. Stock quantities
   need that subscription, company status and autoload. Delivery orders need a company or sole trader with delivery
   for business, on any tariff. Chats in goods need «Максимальная». No stated subscription for reading listings or
   writing prices.
2. **No create, edit, stop or delete call** for goods. The feed (`formatVersion="3"`; XML, XLSX, CSV; up to three
   links) is the full list: a missing ad is archived. «v4» is the report API; v2/v3 reports end 08.03.2027.
3. **The feed can spend the seller's money** (paid placement and promotion fields), and the API load trigger ignores
   the schedule's publication limits. Money safety belongs in the design.
4. **Four framework blockers:** feed publication, results without a task number, stock without a warehouse (today
   nothing would be sent), linking hand-published listings by their Avito number. Seven items are shared with the
   Wildberries plan.
5. **Orders are poll-only**, cancel has no reasons, required actions have deadlines that cancel the order, labels
   (PDF) exist for pickup-point orders only, buyer data only for the seller's own delivery.
6. **No seller sandbox** and no documented read-only key: measurement happens on a real cabinet with real listings.
7. **API terms 2.7 forbid Avito's trademarks and name in an application.** Settle the product name before any
   identifier is fixed.
8. Official pages disagree on eight points, including which edits survive a feed reload — to be measured.

## Method and evidence

- 28 official OpenAPI documents from the developer portal and 30-odd Avito pages (help, rules, autoload
  documentation, category templates and tree), 26.11.2026; list and checksums in the API map. Facts without another
  label come from the specs.
- A plain HTTP fetch of the portal was refused (`429`, IP restriction); the same public pages were read in Orca's
  built-in browser, which loaded them without a challenge. No login, no captcha.
- Framework code at `157c4bd9`, through Serena activated on this worktree and grep. Read: the shape of the WB
  research, the first third of the marketplace wiki (design, base class, domain layer, persistence, settings), card
  #1986 with comments. The rest of the wiki was not read.
- Demand and competitor figures come from the comments on #1986 and were not re-checked.

## Not verified

- Anything by execution: no API request, no feed load, no framework test, no rig. Every «fits» in the gap map is a
  reading of the contract.
- What the API answers without the subscription; tariff and subscription prices; whether the «за успешную сделку»
  tariff opens the API; whether support offers test cabinets; whether a seller may order their own listing.
- Stock semantics (reserved units, the «Несколько штук» switch), which of API edit and feed wins, whether order
  `items[].id` is the feed `Id`, label download before readiness, how long processing takes.
- Legal reading of API terms 1.1 («регистрации Приложения через службу поддержки») and 2.7 for a self-hosted paid
  plugin.
- Category specifics beyond two templates («Кошки», «Наушники»): per-category required fields, documents, photo
  limits, stock parameters in XLSX templates.
- Whether account-wide defaults for adapter fields already exist in mappings or settings (gap map row 5); the
  fake-warehouse workaround (row 13).
- The existing third-party generators (formats, ids, migration path).

## Related

- [Marketplace module design](../../wiki/marketplace-admin.md)
- [ADR-013](../../adr/013-marketplace-layer-and-react-admin-in-the-framework.md)
- [Wildberries Seller research](../2026-11-26-wb-seller/README.md) — the shape followed here
