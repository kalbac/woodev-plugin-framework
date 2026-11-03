WBDEL — Wildberries delivery for third-party shops

Card: kalbac/woodev-plugin-framework#1576 · Research date: 04.11.2026 · Method: web search only (no page-by-page fetch of WB's own pages; the search tool returns summaries). Labels: **SOURCED** = a specific link, date given; **SECONDARY** = aggregator/unofficial/blog; **UNVERIFIED** = reason given.

## Verdict (short)

**No delivery service for external (non-WB-seller) shops was found. Verdict: does not exist (as far as public sources show).** WB logistics exists only for (a) WB marketplace sellers (FBW/FBS/DBS), (b) consumers sending person-to-person parcels (WB Track), (c) B2B buyers purchasing on WB. Absence of evidence is not proof: WB's own pages and the dev portal were not read directly (see "Not established").

## 1. WB logistics products in 2026

| Product | What it does / who for | Status | Source |
|---|---|---|---|
| **WB Track** | Parcel sending between WB pickup points (PVZ), consumer app flow (Сервисы → WB Track) or via PVZ manager. From 150 ₽, from 1 day (page text); earlier press example ~315 ₽ Moscow→St Petersburg; max 25 kg per one secondary source. | Pilot from 10.03.2025 (100 Moscow PVZ); nationwide from 14.01.2026 | **SOURCED** — [retail.ru, 14.01.2026](https://www.retail.ru/news/rvb-zapustila-otpravku-posylok-mezhdu-punktami-vydachi-wildberries-po-vsey-rossi-14-yanvarya-2026-273364/), [CNews, 10.03.2025](https://www.cnews.ru/news/top/2025-03-10_v_rossiyu_prishel_moshchnyj_konkurent), [track.wildberries.ru](https://track.wildberries.ru/). Price/weight: **SECONDARY** ([brobank.ru](https://brobank.ru/otpravit-posylku-cherez-vajldberriz/)); may be stale |
| **FBW / FBS / DBS / C&C** (seller schemes) | Seller ships to WB warehouse (FBW), assembles itself and hands over (FBS), delivers itself (DBS), self-pickup. For WB marketplace sellers only. | General availability | **SECONDARY** (seller-API integration vendors, e.g. [Bitrix module](https://marketplace.1c-bitrix.ru/solutions/arturgolubev.wildberries/)); WB seller docs not read directly |
| **«Wildberries для бизнеса» (B2B buying)** | Legal entities/IPs buy on WB: pay from account, price lock, VAT; FBW/FBS orders go via PVZ, optional courier to company address. Buyer-side, not a shipping service. | Test mode since Nov 2024; B2B pickup Feb 2026 | **SOURCED** — [rb.ru](https://rb.ru/news/wildberries-zapuskaet-model/), [CNews, 16.02.2026](https://www.cnews.ru/news/line/2026-02-16_wildberries_zapustila_servis_samovyvoza), [ecomhub](https://ecomhub.ru/wildberries-b2b-courier-delivery-russia-fbw-fbs-dbs-business-orders-fast-shipping/) (SECONDARY) |
| **Грузовая доставка для бизнеса** (RWB) | Bulky-goods delivery to door for companies/IPs buying on WB. Buyer-side. | Launched 05.12.2025 | **SOURCED** — [CNews, 05.12.2025](https://www.cnews.ru/news/line/2025-12-05_rvb_zapuskaet_gruzovuyu_dostavku), [Forbes](https://www.forbes.ru/novosti-kompaniy/551414-rvb-zapuskaet-gruzovuu-dostavku-dla-biznesa) |
| **Partner hubs / Ситимобил, Грузовичкоф acquisitions / VkusVill express** | Internal WB/RWB logistics expansion, not services for outside shops. | 2026 | **SECONDARY** ([new-retail.ru](https://new-retail.ru/novosti/retail/wildberries_rasshiryaet_logistiku_za_schyet_partnyerskikh_khabov/), [logistics.ru](https://logistics.ru/internet-torgovlya-i-fulfilment-upravlenie-logistikoy-i-kompaniey-transportirovka/rwb-sobiraet), [comnews, 27.04.2026](https://www.comnews.ru/content/244982/2026-04-27/2026-w18/1008/zaberite-vash-zakaz-wildberries-vyshel-rynok-dostavki-edy)) |
| PVZ franchise / network programme (30+ points) | Opening WB pickup points; WB Track to be available there. | Recent | **SECONDARY** ([secrets.tbank.ru](https://secrets.tbank.ru/novosti/pvz-dlya-biznesa-wb/)) |

No product named «WB Доставка», «Wildberries Логистика» or «доставка для бизнеса» as a shipping service for non-marketplace shops surfaced in any search. **UNVERIFIED** (negative result from search summaries only; WB's own business pages not read).

## 2. Offer for external shops (own site, not a WB seller)

**None found.** Searches for WB logistics for outside online shops, D2C brands, or "доставка заказов не с маркетплейса" returned only seller-scheme and consumer-parcel material.

- WB Track: no legal-entity tier, corporate account or contract mentioned in any result — **UNVERIFIED** (negative; [ppc.world](https://ppc.world/news/wildberries-raskatal-na-vseh-novuyu-uslugu-otpravku-posylok-mezhdu-punktami-vydachi/), [sostav.ru](https://www.sostav.ru/publication/rvb-zapustila-servis-otpravki-posylok-mezhdu-pvz-wildberries-po-vsej-rossii-80952.html) describe consumer flow only).
- Connection terms (legal entity, contract, pilot vs GA): not applicable / **UNVERIFIED** — nothing to connect to was found.
- Regions: Russia only for WB Track (nationwide since 14.01.2026, **SOURCED** retail.ru above).

## 3. API

- WB has a public seller API portal: <https://dev.wildberries.ru> (changelog <https://dev.wildberries.ru/en/release-notes>, entries into March 2026 on seller-account users and FBS/DBS sections). **SOURCED** (via search result; 2026). It covers products, prices, FBS/DBS orders, FBW supplies, stickers — i.e. seller operations. **SECONDARY** for the breakdown ([deeone.dev](https://deeone.dev/blog/wildberries-api-etl-grabli-2026.html), [neurounit.ai](https://neurounit.ai/blog/avtomatizaciya-processov-s-api-wildberries/)).
- **No public API for WB Track or for external shipments found** (no price calculation, shipment creation, PVZ list for outside shops, labels, webhooks). **UNVERIFIED** (negative; dev portal sections not read directly).
- Tracking page: <https://track.wildberries.ru/> exists for WB Track; whether a number-in-URL deep link works was **not established**. A third-party aggregator ([ordertracker.com](https://www.ordertracker.com/couriers/wildberries)) offers lookup — **SECONDARY**.

## 4. Tariffs and coverage

- WB Track tariff: from 150 ₽ / from 1 day (page text, **SOURCED** track.wildberries.ru via search summary); route examples and 25 kg limit **SECONDARY**; no official tariff table read. **UNVERIFIED** for current prices.
- Pickup points: "30 000+" per track.wildberries.ru (**SOURCED**, via search summary, date not captured); ~28 000 in an earlier source (**SECONDARY**).
- Countries: WB Track — Russia only in all sources found. KZ/BY/AM/KG/UZ coverage for any shipment service: **UNVERIFIED**, not covered by sources.

## 5. Existing integrations

- **CDEK ↔ WB:** CDEK offers WB sellers FBS/DBS delivery (WB seller using CDEK PVZ), and the CDEK integrations page lists WB supply and FBS plus aggregators incl. ApiShip. This is CDEK serving WB sellers, **not** WB delivering for outside shops. Sources: [hi-tech.mail.ru](https://hi-tech.mail.ru/news/126882-wildberries-smozhet-otpravlyat-posylki-sdekom/) (old, Moscow region only), [cdek.delivery/integracii](https://cdek.delivery/integracii) — **SECONDARY/SOURCED** mixed; dates not captured.
- **ApiShip** (price calc, tariffs, PVZ list, create order, labels, tracking): no evidence it has WB as a delivery provider. **UNVERIFIED**. Source: [apiship.ru](https://apiship.ru/apiship) (SECONDARY).
- **Shiptor, RetailCRM, Достависта, СберЛогистика, Мойсклад:** nothing found offering WB delivery. **UNVERIFIED** (no hits).
- **CMS modules:** all found modules sync a shop *to* the WB marketplace (products, stock, FBS/DBS orders, stickers): Bitrix ([arturgolubev](https://marketplace.1c-bitrix.ru/solutions/arturgolubev.wildberries/), [dobrosite.exportwb](https://marketplace.1c-bitrix.ru/solutions/dobrosite.exportwb)), Webasyst ([store plugin](https://www.webasyst.ru/store/plugin/shop/ww/)), AdvantShop, InSales — **SECONDARY**. None offers "pick a WB PVZ at checkout".
- **WooCommerce:** **WPWB** by WordPlace ([docs](https://wordplace.ru/product/wpwb/docs/)) — WooCommerce ↔ WB marketplace sync (products, prices, orders, reviews); PVZ handover not implemented per its docs. Price not captured. **SECONDARY**. No WooCommerce plugin offering WB pickup-point delivery for own-site orders was found.

## 6. Verdict for the operator

**Does not exist** for external shops (state at 04.11.2026, based on public search). WB's logistics is closed to the WB marketplace (seller schemes) and, separately, a consumer C2C parcel service (WB Track) with no found API, business tier or contract path.

Feasibility of a WooCommerce plugin: **not feasible today** — nothing to integrate against (no public shipment API, no way for a non-seller legal entity to buy the service, no aggregator exposing it). Unofficial automation of the consumer app would be fragile and likely against terms; not recommended.

Re-check triggers: a WB/RWB press release about «доставка для интернет-магазинов»/D2C; a `track`/shipments section appearing in the dev.wildberries.ru changelog; ApiShip/Shiptor/CDEK adding WB as a provider.

## Not established

- WB's own business/seller help pages and the full dev.wildberries.ru section list were not read directly; all negative claims rest on search summaries — a direct check (or a call to WB business support) is the one step that could overturn the verdict.
- Current official WB Track tariff table; whether a number-in-URL tracking link works; PVZ count date; WPWB price; dates of the CDEK/WB articles; any KZ/BY/AM/KG/UZ offering.
