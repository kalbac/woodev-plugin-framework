TRACKE — carrier tracking links checked

# Task E — carrier tracking links of the v1 tracking plugin

Checks dated 04.11.2026 (DD.MM.YYYY). Sandbox HTTP checks used curl with a browser User-Agent and dummy number `1234567890`. Web research used WebSearch (standard mode) only. No forms were submitted and nothing was logged into.

## Input correction

The brief says each v1 URL pattern uses `%1$s` (tracking number), `%2$s` (postcode), `%3$s` (country) and `%4$s` (order id). The actual map in `includes/class-order-tracking-actions.php`, `get_providers()` (lines 79–91), uses **only `%1$s`** in all nine formats. v1 therefore never sends postcode, country or order id into a tracking URL.

## Method limits

- **Unreachable from the sandbox (connect timeout, curl 000):** pecom.ru, shiptor.ru, sberlogistics.ru, nrg-tk.ru, 5post.ru, magnitpost.ru, europochta.by, baikal-service.com. Their pages could not be fetched at all, so their HTTP results are UNVERIFIED. This is a sandbox/network limit, not proof the sites are down.
- **SPA pages:** pochta.ru and cdek.ru return HTTP 200 and echo the number in the HTML, but the tracking result itself is rendered by JavaScript. Whether the page shows real data for the number is UNVERIFIED.
- Web search results were mostly aggregator sites (parcelsapp, ordertracker, ship24, parcelmonitor, trackingmore). They are not primary sources; anything resting on them is marked.

## Table A — the 9 v1 providers

| # | Name in v1 | v1 URL format | Status of company (2026) | HTTP result of v1 URL (dummy number) | Current URL pattern | Recommendation |
|---|---|---|---|---|---|---|
| 1 | Russian Post | `https://www.pochta.ru/tracking?barcode=%1$s` | Operating. Russian state postal operator. No rebrand found. | 200, final URL unchanged, page body echoes the number (SPA, result UNVERIFIED) | Same pattern still served (`barcode=`). Result rendering UNVERIFIED without JS. | **keep** (check rendering in a browser before release) |
| 2 | CDEK | `https://www.cdek.ru/ru/tracking?order_id=%1$s` | Operating. Search result (single, secondary source) says 2026 data migration from Novosibirsk to Moscow and a change of general director; no closure or merger found. | 200, unchanged URL, body echoes the number (SPA, result UNVERIFIED). Note: parameter is `order_id`, and CDEK's own tracking is by CDEK order/track number. | `order_id=` still served; whether it accepts a CDEK track number (vs. order UUID) UNVERIFIED. | **update URL** — verify which parameter/value CDEK's tracker accepts (a track number), or keep and document as order-id only |
| 3 | PEC (ПЭК) | `https://pecom.ru/services-are/order-status/?code=%1$s` | No closure, merger or rebrand found in one search; the only 2026 item is a customer-service release. Registry check not done. | UNVERIFIED — connect timeout from sandbox | not established | **UNVERIFIED** — check in a browser before deciding; keep until checked |
| 4 | Shiptor | `https://shiptor.ru/tracking?tracking=%1$s` | UNVERIFIED. No evidence of closure found; one aggregator says SberLogistics numbers are now tracked at Shiptor (secondary, undated). | UNVERIFIED — connect timeout from sandbox | not established | **UNVERIFIED** — keep until checked in a browser |
| 5 | SBER Logistics (СберЛогистика) | `https://sberlogistics.ru/tracking?number=%1$s` | UNVERIFIED. Aggregator (undated, secondary) says SberLogistics operates with Shiptor; no primary source found. | UNVERIFIED — connect timeout from sandbox | not established | **UNVERIFIED** — likely candidate to **drop or fold into Shiptor**, but needs a primary source |
| 6 | Delovie linii (Деловые линии) | `https://dellin.ru/tracker/?rwID=%1$s` | Operating (company page on dellin.ru returns 200). No closure found. | HEAD: 200 with redirect to `www.dellin.ru/tracker/?rwID=…`. GET on the same URL: **401** (body 265 bytes). Inconsistent; UNVERIFIED. | `tracker/?rwID=` not re-confirmed as working. | **update URL / UNVERIFIED** — the GET 401 suggests the link may not be public; check in a browser |
| 7 | DPD | `https://www.dpd.ru/ols/trace2/standard.do2?orderNum=%1$s` | Operating (dpd.ru returns 200). No closure found. | 200, unchanged URL, body 1837 bytes, contains the number. Body is very small — likely a stub or error page, so UNVERIFIED. | not re-confirmed | **UNVERIFIED** — verify in a browser; URL is a legacy `.do2` form and may be replaced |
| 8 | Boxberry | `https://boxberry.ru/tracking-page?id=%1$s` | Acquired by Yandex (single secondary source, Apr 2025 deal; Yandex announced on 16.04.2025). Brand now lives under the Yandex Delivery site. | 200 after redirect to `https://dostavka.yandex.ru/boxberry/` (the old tracking page is gone; the number is dropped). | Not established; no tracking-by-number URL on the redirect target was found. | **drop** the old URL (it no longer takes the number). Re-examine under Yandex Delivery (see candidates). |
| 9 | TC Energy (ТК Энергия) | `https://nrg-tk.ru/client/tracking/#%1$s` | UNVERIFIED — connect timeout from sandbox; no registry or news check done. | UNVERIFIED | Fragment-based (`#`) pattern is never sent to the server, so it can only work with client-side JS. | **UNVERIFIED** — likely **drop** unless the page is confirmed live |

Notes on Table A:
- Dates: all checks 04.11.2026. The Boxberry merger date is from a secondary source dated 2025.
- Only pochta.ru and cdek.ru returned a usable 200 for the number-in-URL test, and both are SPAs.

## Table B — candidates

| Name | URL pattern or «form only» | Source | Recommendation |
|---|---|---|---|
| Яндекс Доставка (Yandex Delivery) | **none found.** Customers get an SMS link with a UUID (no order-number pattern). Official API documentation exists at yandex.ru/dev/logistics; the only public page is `dostavka.yandex.ru`. Form-only at best — UNVERIFIED. | WebSearch (Alice answers and Habr comment, unreliable); yandex.ru/dev/logistics (listed, not fetched) | **skip** for number-in-URL until a documented pattern is confirmed; a UUID-based link is not something v1 can build from the tracking number |
| Ozon (Ozon Доставка / Ozon logistics) | `tracking.ozon-dostavka.ru` is named as the tracking site (aggregator and Ship24 mention; not checked on the site). Pattern with number in the URL **UNVERIFIED**. | WebSearch (aggregators: parcelsapp, ordertracker, ship24, trackingmore) | **UNVERIFIED** — check `tracking.ozon-dostavka.ru` in a browser before adding |
| 5Post | **form only** (search result says tracking is in the mobile app or on 5post.ru; no URL with the number found). | WebSearch (parcelmonitor, ordertracker, track123 — secondary) | **skip** — no number-in-URL pattern established |
| Магнит Пост (Magnit Post) | **none found.** No primary source; aggregator page suggests the Magnit app. | WebSearch (generic results only; no Magnit Post source) | **skip** — UNVERIFIED |
| Wildberries (WB Track) | **form only / app**. Order status is in the app («Доставки») or on the site («Заказы»). WB Track is a parcel-sending service between people, not a tracking page. A `track.wildberries.ru` address appears only in an unsourced snippet. | WebSearch (rozetked, expert.ru, retail.ru 14.01.2026 and 03.06.2026 — news only) | **skip** for number-in-URL; WB Track is a sending service, not carrier tracking |
| ДПД / Деловые линии | DPD: already in v1 (row 7). Delovie linii: already in v1 (row 6). Both are **present**, so no new row needed. | — | **no add** (already covered; see A7 and A6) |
| КИТ (ТК КИТ, `ООО КИТТК`) | UNVERIFIED — no tracking page found; company listed as active on 22.07.2026 (RBC Companies, secondary). | WebSearch (companies.rbc.ru) | **skip** until a tracking URL is confirmed |
| Байкал Сервис | UNVERIFIED — aggregator (ordertracker) lists tracking; no primary URL pattern confirmed. Company described as operating in 2026 (retail.ru press releases). | WebSearch (ordertracker.com, retail.ru) | **UNVERIFIED** — check `baikal-service.com` in a browser (unreachable from sandbox) |
| Почта Казахстана (KazPost) | `https://www.post.kz/` with a «Найти посылку» search field. Form only, no number-in-URL pattern found. Note: `www.post.kz` redirects (301) to `http://post.kz/` in the sandbox. | WebSearch (finance.kz, ship24); curl 301 | **skip** — form only |
| Европочта (Europochta, Belarus) | **form only** per secondary aggregator; official site (`europochta.by`) unreachable from sandbox. | WebSearch (ordertracker, parcelmonitor); curl timeout | **skip** — form only, UNVERIFIED |
| Белпочта (Belpost, Belarus) | **form only**: enter the tracking number on belpost.by. belpost.by returned 200 in the sandbox. No number-in-URL pattern found. | WebSearch (ship24, ordertracker — secondary); curl 200 on belpost.by root | **skip** — form only |

## Recommendations summary

- **Keep:** Russian Post (A1) — after a browser check of rendering.
- **Update URL:** CDEK (A2) — verify the parameter/value; Delovie linii (A6) — GET returns 401, check in a browser.
- **Drop:** Boxberry (A8) — the old URL redirects to Yandex Delivery and no longer takes the number. TC Energy (A9) — likely drop unless confirmed live (UNVERIFIED).
- **Unverified (decide after a browser check):** PEC (A3), Shiptor (A4), SberLogistics (A5), DPD (A7), TC Energy (A9).
- **Add candidates:** none confirmed as number-in-URL in this pass. Ozon (`tracking.ozon-dostavka.ru`) is the only one worth checking next; it is UNVERIFIED.

## Not checked

- No browser (Chrome) check: the SPA pages (Russian Post, CDEK) and the 401 on Delovie linii were not confirmed in a real browser, and sandbox timeouts blocked the rest.
- No registry check (ЕГРЮЛ, egrul.nalog.ru) for PEC, Shiptor, SberLogistics, TC Energy, КИТ, Байкал Сервис.
- The v1 plugin's behaviour with the tracking number is not tested; only the URL format was read.
- Most candidate facts rest on aggregator sites, not on carrier primary pages.
