
# Wildberries Seller plugin for WooCommerce — research, 26.11.2026

Decision researched (operator, 26.11.2026, [#1670](https://github.com/kalbac/woodev-plugin-framework/issues/1670)):
build a Wildberries plugin for WooCommerce sellers, the same kind as Ozon Seller 2.0 on `woodev/marketplace/`.
The research itself changed no code and sent no request to any Wildberries API host; the follow-up
measurement cards did — the sandbox results (M0b, #1996) are in [measurement.md](measurement.md).

## Files

| File | Content |
|---|---|
| [api-map.md](api-map.md) | WB Seller API: tokens, limits, cards, prices, stocks, orders (FBS/DBS/DBW/pickup/FBW), supplies, returns, finance, polling |
| [gap-map.md](gap-map.md) | Each capability against `woodev/marketplace/` with file:line; Ozon assumptions in the module |
| [ozon-vs-wb.md](ozon-vs-wb.md) | Differences that change the merchant flow |
| [owner-questions.ru.md](owner-questions.ru.md) | 11 questions for the owner, Russian, each with a recommendation |
| [card-plan.md](card-plan.md) | Estimated framework and plugin cards (superseded by the design spec) |
| [decisions.md](decisions.md) | The owner's answers of 26.11.2026 (D1–D11) — final; they win over every recommendation here |
| [measurement.md](measurement.md) | Phase-0 measurement log: M0b sandbox (#1996, done 26.11.2026) — flows, checks, fidelity gaps, write ledger; M0a live cabinet (#1995) appends here |
| [fixtures/m0b/](fixtures/m0b/) | 85 sandbox request/response pairs (test data only, token redacted) — samples for the plugin's tests |
| [sandbox-environment-2026-11-26.json](sandbox-environment-2026-11-26.json) | The official sandbox OpenAPI spec, extracted from the portal page (no yaml download exists); sha256 in measurement.md |

## Outcome

The owner answered the questions on 26.11.2026: [decisions](decisions.md). The plugin is new and its first release is
**1.0.0**; FBS and DBS are both in it. Design:
[Wildberries Seller 1.0 plugin design](../../specs/2026-11-26-wildberries-seller-plugin-design.md).

## Verdict

Feasible on the existing module; **not** "write an adapter and ship". Accounts, jobs, mappings, links, stock sync,
grouped orders, pages, diagnostics and licensing carry over. Four first-release paths cannot work without framework changes, and
the FBS hand-over (supplies) is a new screen.

## Top findings

1. **Asynchronous price results are declared but never read.** WB applies prices later; the module logs a pending
   receipt and counts it as unanswered, and nothing calls `Price_Pusher::settle()` (G 18).
2. **Card export assumes a task number.** WB card creation returns none; today that is a failure and a retry (G 11).
   Photos, sent after the card exists, are plugin work on the existing `card_created` hook (G 12).
3. **The unit of sale differs.** The module links one product to one card with a unique article; WB sells a size
   (`chrtID`) inside a colour card (`nmID`) whose article and usually price are shared by all sizes (G 7–10, G 19).
4. **FBS needs supplies.** Stickers (PNG/SVG/ZPL, not PDF) exist only after an order is in a supply; delivery needs a
   shipping point, date and any required marking codes. Nothing in the module covers it; cancel also assumes reasons,
   which WB does not have (G 24–28).
5. **Access terms shape the product.** One 180-day token, no client id, no webhooks in the official specs; a Base
   token is unusably slow, so a Personal (or catalogue Service) token is required. Unlike Ozon, WB has a sandbox —
   where marking codes are optional, so it cannot prove that a missing code blocks a supply.

Import caution: a WB barcode is any string and a size may have several; writing it into WooCommerce's GTIN field would
silently change it (G 8–9).

Also: the mapping page says «категориям Ozon» to every marketplace (gap map, A1).

## Method and evidence

- Official OpenAPI files of all 13 categories, downloaded from `dev.wildberries.ru` 26.11.2026 (checksums in the
  API map). Facts without a label in the API map come from them.
- Framework code at `59a0ecf9`, through Serena activated on this worktree and grep; the marketplace wiki (design
  sections, domain layer, card plan — the middle «as built» sections were not read), the three marketplace specs
  (headers, decisions and targeted searches — not read in full), cards #1670 and #1675 with comments, and the folder
  `research/2026-10-30-ozon-gap-refresh/`. That folder researched **Ozon Delivery** (the carrier), not Ozon Seller;
  only its shape was reused.
- Web search only for release notes, webhooks, token terms and stock behaviour; labelled SECONDARY or UNVERIFIED.

## Not verified

- By execution: the research itself sent no WB request; **M0b (#1996) since ran the sandbox flows** —
  [measurement.md](measurement.md) holds the results and fixtures. No live-cabinet request yet (M0a, #1995),
  no framework test, no rig. Every "fits" in the gap map is still a reading of the contract.
- WB's HTML pages: the portal answers HTTP 498 to curl and embedded browsers, but a fresh headed full Chrome
  passes — the sandbox spec, the DBS knowledge-base article and the FBS-marking instruction were read that way
  ([gotcha](../../gotchas/wb-portal-498-passes-in-a-fresh-headed-chrome.md)). Release notes, the business-solutions
  catalogue and the agreements were not read.
- Whether a self-hosted paid plugin may use a Personal token; whether a Test token needs a seller account.
- Whether WB lowers seller-warehouse stock on an order **in production** (the sandbox does, and does not restore it
  on cancel — measurement.md); whether `vendorCode` can be changed; real subcategory requirements (the sandbox
  returns zero required characteristics — a fidelity gap, not the production answer).
- Assembly deadlines and penalties (not in the specs).
- Adapter-only workarounds in the gap map rows 9 and 22 were not tried.
- Marking: the production refusal for a missing code and code validation (the sandbox validates nothing and never
  requires a code — measured); the exact cabinet steps for entering codes (the official article's accordion body
  did not expand under automation). What the sandbox did show is in measurement.md.
- Demand: not researched here (the operator decided to build; #1670).

## Related

- [Owner decisions](decisions.md)
- [Wildberries Seller 1.0 plugin design](../../specs/2026-11-26-wildberries-seller-plugin-design.md)
- [Marketplace module design](../../wiki/marketplace-admin.md)
- [ADR-013](../../adr/013-marketplace-layer-and-react-admin-in-the-framework.md)
- [Ozon Delivery gap refresh, 30.10.2026](../2026-10-30-ozon-gap-refresh/report.md) — the research shape followed here
- [WB delivery research, 04.11.2026](../2026-11-04-wb-delivery/README.md) — a different question (delivery for third-party shops)
