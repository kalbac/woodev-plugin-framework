
# Avito API map (as of 26.11.2026)

## Evidence labels

- **SPEC `slug`** — read in the official OpenAPI document of the developer portal, downloaded 26.11.2026 from
  `https://www.avito.ru/web/1/openapi/info/{slug}` (the data request behind
  `developers.avito.ru/api-catalog/{slug}/documentation`). Every row of a table is SPEC unless it says otherwise.
- **PAGE [Pn]** — read on an official Avito page, 26.11.2026; the list is in §10.
- **SECONDARY** — a third-party page.
- **UNVERIFIED** — not established; the reason is given.

No request was sent to `api.avito.ru`; nothing here was measured against a cabinet.

### How the sources were fetched

A plain HTTP client got `429` with an IP-restriction page from the portal and the autoload pages. The same public
pages loaded without a challenge in Orca's built-in browser, and were read there. A third-party copy of the specs
(`github.com/elchin92/avito-mcp`, last changed 09.06.2026) and the Context7 crawl `/websites/developers_avito_ru_api-catalog`
were found first; **neither is a source below** — the copy is older than the 28.10.2026 and 18.11.2026 spec changes.

### Spec files read

28 documents were downloaded. They are **not committed** (third-party); the first 16 hex characters of the SHA-256
of the downloaded JSON let a later reader see whether Avito changed it.

| Slug | Title | SHA-256 (prefix) | Last changelog entry |
|---|---|---|---|
| `auth` | Авторизация | `e6371ea9005f871c` | none |
| `autoload` | Автозагрузка (22 operations, 11 of them deprecated) | `edaa8f71d5d10286` | 28.10.2026 |
| `item` | Объявления | `6f287f4ef2df1c48` | 18.11.2026 |
| `stock-management` | Управление остатками | `de2c852e97b46bec` | none |
| `order-management` | Управление заказами | `625dc69a1efcf6c4` | none |
| `messenger` | Мессенджер | `ba477308fec7f2a8` | none |
| `user` | Информация о пользователе | `a4bde30e3218620f` | 08.11.2024 |
| `tariff-composition` | Тарифы | `efb979cf75e6ee96` | none |

The other 20 (ads, delivery for logistics partners, job, realty, transport and promotion products, …) were listed
or searched only.

## 1. Access

### 1.1 Authorization

| Fact | Where |
|---|---|
| Base URL `https://api.avito.ru/`; requests carry `Authorization: Bearer <token>`. | `auth` |
| **`client_credentials`** — for the seller's own account: `client_id` + `client_secret` from the cabinet, `POST /token` (form-encoded). The token lives **24 hours**; there is no refresh token. | `auth` |
| **`authorization_code`** — for an application acting for other users: register it at `developers.avito.ru/applications` (name, https redirect URI, description); user consent at `https://avito.ru/oauth?response_type=code&pro_users_flow=true&client_id=…&scope=…`; access token 24 h, refresh token 1 year. | `auth` |
| Application keys are reviewed, «обычно … до 2-х дней». | PAGE [P1] |
| The scope list of `authorization_code` has **no scope for stocks and none for orders**; `stock-management` and every `order-management` operation declare `ClientCredentials` only. | `auth`, `order-management`, `stock-management` |
| Cabinet path of the keys: кабинет Авито Pro → «Профиль и настройки» → «Интеграции и API». Several keys may exist; deleting one revokes it. | PAGE [P1] |
| `GET /core/v1/accounts/self` returns `id`, `name`, `email`, phones — the `user_id` of the item and messenger paths. | `user` |
| Employee accounts (account hierarchy): the item list returns nothing for an employee; messenger methods need the company account's key. | `item`, `messenger` |

**Consequence for a self-hosted plugin:** `client_credentials` with the merchant's own keys is the only route that
covers stocks and orders. UNVERIFIED: any read-only or scoped personal key — none is documented, and the terms treat
every action with the key, including ordering paid services, as the user's own (§1.3).

### 1.2 What needs a paid tariff, a subscription or a business account

The claim on card #1986 — «Остатки и заказы, по словам интеграторов, требуют платного тарифа Авито и
бизнес-аккаунта» — is **confirmed for stocks (and is stricter than claimed) and for orders (business account yes,
a paid subscription not stated)**. «Тариф» and
«подписка» are different things: a **tariff** is how a professional seller pays for listings (by views, by completed
deal, by placements, by calls); a **subscription** («Расширенная», «Максимальная», «Ультра») is a monthly tool
package on top of it.

| Capability | Tariff | Subscription | Business account | Evidence |
|---|---|---|---|---|
| API keys at all (so: items, prices, orders, reports) | **yes** | not stated (see conflict C1) | not stated | PAGE [P1], [P2], [P3] |
| Items API: list, read, price | yes (keys) | **no requirement stated** | not stated | PAGE [P1]; `item` states none |
| Autoload — goods and other non-realty, non-transport categories | yes | **«Расширенная» or «Максимальная»**; «Базовый» has no autoload | «в некоторых категориях» only legal entities / sole traders and/or INN-verified | PAGE [P4], [P5], [P6] |
| Autoload — «Недвижимость», «Транспорт» | yes | no | — | PAGE [P4] |
| Autoload and API — «Работа» | yes | **«Максимальная»** | INN verification required | PAGE [P1], [P4] |
| **Stock quantities** («Несколько штук в наличии» fed automatically) | yes | **«Расширенная» or «Максимальная»** | **company status required**, and autoload connected | PAGE [P7] |
| **Orders API** (Avito Delivery for business) | **yes** — «Базовый» is enough for delivery | not stated | **yes**: INN of a company or sole trader, wallet topped up from the company account; «управление заказами доступно только для B2C продавцов» | `order-management`; PAGE [P8] |
| Messenger API — «Товары», «Работа» | yes | **«Максимальная»** («Услуги»: «Расширенная» or «Максимальная») | — | `messenger`; PAGE [P1] |
| `GET /tariff/info/1`, `GET /api/1/partner/tariff/info` | transport / realty only | — | — | `tariff-composition` — **no API tells a goods seller's tariff** |

Not established:

- UNVERIFIED — what the API answers when the subscription is missing (error code, empty list, or silent success).
  The cabinet's load report says «отключена возможность Автозагрузки» when the tariff is «Базовый» or unpaid
  (PAGE [P6]); the stock method's behaviour is not documented.
- UNVERIFIED — prices of tariffs and subscriptions: the configurator needs a login; third-party figures disagree.
- UNVERIFIED — whether the tariff «с оплатой за успешную сделку» (still being tested, not offered to everyone,
  PAGE [P9]) opens the API. It requires ≥ 90 % of paid orders to be handed over through Avito Delivery or paid
  pickup, and seller-side cancellations count against it.
- If the tariff is paused or the subscription cancelled, autoload stops; published listings stay until their
  placement ends (PAGE [P4]). A view-based tariff with an empty advance hides listings from search (PAGE [P10]).

### 1.3 API terms (PAGE [P3])

| Clause | Content |
|---|---|
| 1.1 | Access starts «с момента регистрации Приложения через службу поддержки и получения API-Ключа». |
| 1.3 | Every action with a key, **including ordering paid services**, is the user's own action. |
| 1.5 | Access may be refused, among other things, «при отсутствии действующей услуги профессиональные инструменты по улучшенному тарифу». |
| 2.2 | The API is only for working with the user's own listing base. |
| 2.4 | Avito may change or end the API without notice. |
| 2.6 | Avito monitors all API activity. |
| **2.7** | **«Вам запрещено использовать в Приложении товарные знаки и фирменное наименование Авито, представляться официальным разработчиком Авито…»** — touches the product name, the menu title and the artwork. Legal reading UNVERIFIED. |

## 2. Rate limits

Per minute, as written in each operation; the specs do not say whether a limit is per key, per account or per IP.

| Operation | Limit | Per request |
|---|---|---|
| `PUT /stock-management/1/stocks` | 100 / min | 200 lines |
| `POST /stock-management/1/info` | 100 / min | **10** (description; the schema says `maxItems: 500` — C4) |
| `GET /core/v1/items` | 25 / min | `per_page` < 100 |
| `GET /core/v1/accounts/{user_id}/items/{item_id}/` | 500 / min | 1 |
| `POST /core/v1/items/{item_id}/update_price` | 150 / min | 1 |
| `GET /order-management/1/orders` | 500 / min | 20 orders |
| `POST …/order/applyTransition`, `…/markings`, `…/setTrackingNumber`, `GET …/getCourierDeliveryRange`, `POST …/acceptReturnOrder` | 100 / min | markings: 50 |
| `POST …/order/setCourierDeliveryRange` | 200 / min in the text, limiter value 100 (C5) | 1 |
| `POST …/order/cncSetDetails` | 60 / min | 1 |
| `POST …/orders/labels` | 1000 / min | 100 in the title, schema `maxItems: 50` (C3) |
| `POST …/orders/labels/extended` | **1 / min** | 1000 in the title, schema 50 (C3) |
| `GET …/orders/labels/{taskID}/download` | 1000 / min | — |
| `POST /autoload/v1/upload` | **once an hour** | — |
| Autoload read methods, messenger, `/token`, `/accounts/self` | no number documented (`429` is listed for the v4 methods) | — |

`GET /core/v1/items` returns `X-RateLimit-Limit` / `X-RateLimit-Remaining` with its `429`.

## 3. Autoload — the only way to create and edit goods listings

The Items API has **no create, edit, archive or delete operation** for goods (§4). Creation and content go through a
file («фид»). The file format is **`formatVersion="3"`**; «v4» is the version of the **report API**, not of the feed.

### 3.1 Connection and delivery of the file

| Fact | Where |
|---|---|
| Formats XML, XLSX, CSV. | PAGE [P5] |
| Two ways: a link Avito downloads on a schedule, or a manual upload in the cabinet (also by e-mail). | PAGE [P11], [P12] |
| `GET/POST /autoload/v2/profile`: `autoload_enabled`, `report_email`, `schedule[]` (weekdays, hour slots 0–23 Moscow time, `rate` = listings per slot), `feeds_data[]` (name, http/https URL); `agreement` must be sent when the profile does not exist yet. | `autoload` |
| **Up to three feed links**; one schedule applies to all, and listings of the files are processed together. | PAGE [P11] |
| `POST /autoload/v1/upload` starts a load from the saved link, at most once an hour. **Publication limits of the schedule do not apply to it** — everything publishable gets published. Its answer documents no body (no upload id). | `autoload` |
| Processing is daily; under load it may take up to 24 hours longer. | PAGE [P5] |
| The file: ≤ 500 MB by link (manual: file + photo ZIP ≤ 100 MB); a template page states ≤ 50 000 listings; the report sample says only 100 000 listings across files are processed. | PAGE [P13], [P14]; `autoload` |
| Avito must reach the file directly: ports **80/443** only, no login, **no self-signed certificate**, `User-Agent: Mozilla/5.0 (compatible; Avitobot-Autoload;+https://autoload.avito.ru/format/)`, from `185.89.12.0/22`, `146.158.48.0/21`, `185.79.237.224/28`, `87.245.204.32/28`, `176.109.112.0/20`, `176.114.112.0/20`, `68.138.16.238` (C6). | PAGE [P13] |
| No XML Schema is published; a checker exists at `autoload.avito.ru/format/xmlcheck/`. | PAGE [P13] |

### 3.2 Format and common fields

Root `<Ads target="Avito.ru" formatVersion="3">`, one `<Ad>` per listing, UTF-8, parameter names case-sensitive
(PAGE [P14], the «Кошки» template, and [P15], the «Наушники» template).

† — required in this template.

| Field | Rule (goods template «Наушники», PAGE [P15]) |
|---|---|
| `Id` † | Seller's own id, ≤ 100 characters, **must never change** — a changed `Id` publishes a new listing and may be blocked as a duplicate. |
| `AvitoId` | Number of an already published listing; the way to adopt it without a duplicate. |
| `Address` † / `Latitude`+`Longitude` / `SellerAddressID` | Required per listing; ≤ 256 characters. |
| `Title` † | ≤ 50 characters; no price or contacts. |
| `Description` † | ≤ 7500 characters; for tariff listings HTML `p, br, strong, em, ul, ol, li` inside CDATA. |
| `Images` † | `<Image url="…"/>` or `name="…"` (ZIP); **JPEG or PNG**, ≤ 25 MB, **at most 10**; an unchanged URL is **not downloaded again**. |
| `Price` † | Rubles, integer. |
| `Category` †, `GoodsType` †, `AdType` †, `Condition` † | Values from lists; further parameters depend on the category. |
| `CountryExpedition` † | «Этот элемент стал обязательным с 15.11.2026». |
| `DateBegin`, `DateEnd` | Publication window; a past `DateEnd` closes the listing. |
| `ListingFee` | `Package` (default) / `PackageBBL` / `BBL` (C2): whether a single paid placement may be bought from the wallet. |
| `AdStatus`, `Promo…` | **Paid promotion services**, billed from the wallet. |
| `Delivery` | `Выключена`, `ПВЗ`, `Курьер`, `Постамат`, `Свой курьер`, own partners (СДЭК, Деловые Линии, ПЭК, Почта России), `Самовывоз с онлайн-оплатой`. |
| `WeightForDelivery` (kg), `Length/Height/WidthForDelivery` (cm), `ReturnPolicy`, `DeliverySubsidy` | Delivery parameters. |
| `TNVED`, `OKPD2`, certificate / declaration / registration fields, `CodeGTIN` | Optional in the schema, but «для продавцов с проверенными реквизитами» ТН ВЭД and ОКПД 2 are required, and some new goods need documents or marking — otherwise **delivery and paid pickup stay off** (the platform-economy law). |
| `MultiItem` | New goods: joins listings of one product into a multi-listing (default «Да»). |

There is **no stock parameter** in the «Наушники» XML template. Stock goes through the stock API (§5) or a separate
stock file with a `Stock` column (PAGE [P7]).

### 3.3 Categories and fields — machine-readable

| Fact | Where |
|---|---|
| `GET /autoload/v1/user-docs/tree` — nested tree `{name, slug, nested[]}`; `If-Modified-Since` supported. | `autoload` |
| `GET /autoload/v1/user-docs/node/{node_slug}/fields` — per field: tag, label, required, `field_type` input/select/checkbox, data type, values inline or by `values_link_json` / `values_link_xml`, `values_range_v2`, default, warnings and **dependencies** (visible / required / hidden, and/or pairs); `feed_format` xml/excel. | `autoload` |
| The public tree behind the template pages has **3086 leaf nodes** in 10 root sections (counted on `www.avito.ru/web/1/autoload/user-docs/tree`, 26.11.2026). | PAGE [P16] |

### 3.4 Create, update, close, link existing

| Fact | Where |
|---|---|
| The file is the full list: removing a listing from it **archives** the listing; putting the same `Id` back reactivates it. | PAGE [P11], [P17] |
| An empty file is not processed; to close everything give past `DateEnd`s or `<Ad><Id>STOP</Id></Ad>`. | PAGE [P13], [P17] |
| A listing similar to a closed one is refused until the old one is deleted (cabinet only) and 30 days have passed since its publication. | PAGE [P11] |
| Adopting hand-published listings: put `AvitoId` into the file **before** the first load, or let Avito auto-match (first load, > 10 active listings; unclear pairs wait for the seller's choice in the report; errors are possible). | PAGE [P4], [P13] |
| Changing an `Id` of a published listing republishes it. | PAGE [P13] |
| `GET /autoload/v2/items/avito_ids?query=` (file ids → Avito ids) and `…/ad_ids?query=` (the reverse). | `autoload` |
| Listings published by autoload «можно управлять только через файл»; profile edits are reverted at the next load — see conflict C7. | PAGE [P11], [P17] |

### 3.5 Report of a load (v4)

| Method | Returns |
|---|---|
| `GET /autoload/v4/uploads` | History: `upload_id`, `status` (`processing`, `success`, `success_warning`, `error`), `source` (`Email`, `Url`, `Web`, `OpenAPI`), `started_at`, feed copies, upload-level `events`, a three-level statistics tree; `perPage` ≤ 50. |
| `…/uploads/current`, `…/uploads/last_successful` | One upload: the latest that processed at least one ad / the latest that processed all. `404` when none. |
| `…/current/items`, `…/last_successful/items` | Per listing: `ad_id` (file id), `avito_id`, `avito_status` (`active`, `old`, `blocked`, `rejected`, `archived`, `removed`), `avito_date_end`, `url`, `feed_name`, report `section`, `messages[]` (`error`, `warning`, `alarm`, `info`, with code, title, text); filter `query` by file ids, `perPage` ≤ 100. |

Per-listing detail exists **only** for the current and the last successful upload. The v2/v3 report methods are
deprecated: less history since 08.09.2026, `410 Gone` from **08.03.2027**. The load history no longer carries
charges (`fee_info`) or `finished_at`.

## 4. Item management API

| Operation | Use |
|---|---|
| `GET /core/v1/items` | Own listings: `id`, `title`, `price`, `status` (`active`, `removed`, `old`, `blocked`, `rejected`), `category`, `url`, `address`; filters `status`, `category`, `updatedAtFrom`, `query` (new 18.11.2026). **No seller id, SKU, stock or photos.** |
| `GET /core/v1/accounts/{user_id}/items/{item_id}/` | Status, `autoload_item_id`, dates, applied paid services. |
| `POST /core/v1/items/{item_id}/update_price` | One price; categories «Товары, Запчасти, Авто, Недвижимость (кроме краткосрочной)». |
| `…/vas`, `…/vas_packages`, `/core/v2/items/{id}/vas/`, `/stats/…` | Paid promotion and statistics. |

**Absent:** create, edit content, publish, stop, archive, delete, upload photos (messenger photos are a separate
thing). Stopping a listing from the shop = removing it from the feed (or a past `DateEnd`); «out of stock» = stock 0.

## 5. Stocks («остатки»)

| Fact | Where |
|---|---|
| Keyed by `item_id` = **Avito listing number**; `external_id` is optional and echoed back. No warehouse anywhere. | `stock-management` |
| `quantity` 0…999 999; per-line `success` / `errors`. | `stock-management` |
| `info` returns `quantity` — «количество с подачи или редактирования **минус бронь**» —, `is_multiple`, `is_unlimited`, `is_out_of_stock`; the reserved amount itself is not returned; `strong_consistency` skips the cache. | `stock-management` |
| With an exact quantity Avito lowers it when a buyer orders and restores it on cancel or return; at 0 the «Купить с доставкой» button is replaced by «Временно нет в наличии», and the listing stays. | PAGE [P18] |
| With «Несколько штук» **off** a listing takes one order, shows «Товар зарезервирован», and goes to the archive after payout. | PAGE [P18] |
| Quantities are not offered in «Животные», «Водный транспорт», «Готовый бизнес»; mobile phones only new ones. | PAGE [P7], [P18] |
| A file alternative exists: `Date`, `AvitoId` or the autoload `ID`, `Stock` (`*` = unlimited), ≤ 40 MB, by link or by hand, applied within an hour. | PAGE [P7] |

UNVERIFIED: whether writing a quantity through the API switches «Несколько штук» on; whether the written number
includes reserved units.

## 6. Avito Delivery orders

### 6.1 What can be read

| Fact | Where |
|---|---|
| `GET /order-management/1/orders`: `ids` (≤ 20), `statuses`, `dateFrom` (creation, Unix time), `page`, `limit` ≤ 20; answer `hasMore`, `orders[]`. | `order-management` |
| Statuses: `on_confirmation`, `ready_to_ship`, `in_transit`, `delivered`, `closed`, `canceled`, `on_return`, `in_dispute`; `returnPolicy.returnStatus`: `in_transit`, `ready_to_pickup`, `self_return`. | `order-management` |
| Delivery types: `pvz`, `postamat`, `courier` (Yandex courier), `dbs` (seller's partner), `rdbs` (seller's courier), `cnc` (pickup). The response enum of `serviceType` lists only `pvz`, `dbs`, `rdbs` (C8). | `order-management` |
| Order: `id` (used by the API), `marketplaceId` (number shown to people), `status`, `createdAt`, `updatedAt`, `items[]` (`avitoId`, `id` — «ID товара в базе продавца», `title`, `count`, `chatId`, `location`, prices with commission and discounts), `prices` (price, delivery, discount, commission, total to receive), `delivery` (type, service, tracking and dispatch numbers, DBS pickup point), `schedules` (`confirmTill`, `shipTill`, `setTrackingNumberTill`, …), `availableActions[]` (`name`, `required`). | `order-management` |
| Buyer name and phone **only for DBS and RDBS, after payment**; the buyer's address only for RDBS. | `order-management` |
| Orders can be listed by date only for 6 months after creation or 3 months after closing. | `order-management` |
| No order webhook exists in this spec: new orders are found by polling. | `order-management` |

### 6.2 Actions

| Action | Method | Types |
|---|---|---|
| Confirm / cancel | `applyTransition` `confirm` / `reject` — **no cancellation reasons** | confirm: pvz, dbs, rdbs, postamat; cancel: all |
| Confirm shipment / delivery | `applyTransition` `perform` (rdbs) / `receive` (rdbs, cnc, with a buyer code) | — |
| «Честный знак» codes | `markings` — **before confirming**; ≤ 10 codes per item; an empty list withdraws | all |
| Tracking number | `setTrackingNumber` (errors `incorrect_number`, `already_set`) | dbs (required) |
| Courier interval | `getCourierDeliveryRange` → `setCourierDeliveryRange` (this **confirms** the order) | courier |
| Pickup preparation | `cncSetDetails` (booking period, address, comment) | cnc (and courier, per the table) |
| Return post office | `acceptReturnOrder`; ignored → auto-accepted after 2 weeks | courier |
| Check a pickup code | `checkConfirmationCode` | pickup points |
| Labels | `orders/labels` → `taskID` → `download` (PDF; `standard` A6 or `thermal` 58×40) | **pickup-point orders only**; no task-status method |

Required actions have deadlines; missing one **cancels the order automatically** (`order-management`). For delivery
by Avito partners the seller confirms within one working day, and may extend the shipping date by 4 days in the
cabinet (PAGE [P19]).

### 6.3 Enabling delivery for business (PAGE [P8])

Confirm company data (INN, tax system) → top up the wallet from the company's account → pay a tariff («Базовый»,
«Расширенный» or «Максимальный») → «Авито Доставка» section (cabinet, computer only) → partners and/or own
delivery. Own delivery is also open to verified self-employed sellers and tariff holders and needs more than 35 %
of orders to be shipped (PAGE [P20], [P8]). Partner delivery: goods up to 20 kg and 150 cm on the landing page,
payout weekly to the bank account (PAGE [P19], [P21]).

## 7. Messenger and webhooks

| Fact | Where |
|---|---|
| Chats v2, messages v3, send text (≤ 1000 characters) and images, delete, read, blacklist, voice files. | `messenger` |
| `POST /messenger/v3/webhook {url}` subscribes, `…/subscriptions` lists, `…/webhook/unsubscribe` removes; the endpoint must answer 200 within **2 seconds**. | `messenger` |
| The payload carries message, chat, author, receiver and `item_id`. **No signature or secret is documented** — the URL is the only credential. | `messenger` |
| Access level: §1.2. | `messenger` |
| Webhooks found elsewhere: job applications, car-service bookings, digital goods orders. **None for goods orders, listings, stocks or autoload.** | search of all 28 specs |

## 8. Sandbox and test accounts

- **No sandbox for seller APIs is documented.** The only «sandbox» operations belong to the delivery spec for
  **logistics partners** («Методы API для партнеров для работы с API Логистики») and to the advertising spec; a
  search of the other specs and of Avito help found none.
- UNVERIFIED: whether support issues test cabinets on request; whether a seller may order their own listing to
  create a test order.

## 9. Official sources that disagree

| Id | Disagreement |
|---|---|
| C1 | API access: «пользователям с подключенным тарифом» ([P1]) and «один из трёх тарифов — Базовый, Расширенный или Максимальный» ([P2]) against «профессиональные инструменты по улучшенному тарифу» ([P3] 1.5, [P5] 6). |
| C2 | `ListingFee`: `Package` / `PackageBBL` / `BBL` ([P15]) against `Package` / `PackageSingle` / `Single` ([P17]). |
| C3 | Labels per request: 100 and 1000 in titles, `maxItems: 50` in both schemas. |
| C4 | Stock read: 10 ids in the text, `maxItems: 500` in the schema. |
| C5 | `setCourierDeliveryRange`: 200 / min in the text, limiter 100. |
| C6 | Downloader addresses: seven entries in the FAQ ([P13]), two in the report-error article ([P6]). |
| C7 | After an API or cabinet edit: «отредактированные параметры сохранятся», unless the listing also changed in the file ([P11]); against «изменения через профиль … будут отменены» at the next load ([P17]). |
| C8 | `serviceType` enum lacks `courier`, `cnc`, `postamat` is only in the text. |

## 10. Pages read (26.11.2026)

| Id | Page |
|---|---|
| P1 | <https://support.avito.ru/sections/1806?articleId=9666> — API для бизнеса |
| P2 | <https://www.avito.ru/business/tools/api> |
| P3 | <https://www.avito.ru/legal/pro_tools/public-api> — rules of the public API |
| P4 | <https://support.avito.ru/sections/1804?articleId=9660> — connect autoload |
| P5 | <https://www.avito.ru/legal/pro_tools/autoload> — autoload rules |
| P6 | <https://support.avito.ru/sections/1807?articleId=9670> — reports and errors |
| P7 | <https://support.avito.ru/articles/2678> — stock management |
| P8 | <https://support.avito.ru/sections/1824?articleId=9758> — enable delivery |
| P9 | <https://support.avito.ru/articles/2095>, <https://support.avito.ru/sections/1703?articleId=9045> — tariffs |
| P10 | <https://support.avito.ru/sections/1710?articleId=9091> — view-based tariff |
| P11 | <https://www.avito.ru/autoload/documentation> — autoload instruction |
| P12 | <https://support.avito.ru/sections/1807?articleId=9670> — how a report is made per upload method |
| P13 | <https://www.avito.ru/autoload/documentation/faq> |
| P14 | <https://www.avito.ru/autoload/documentation/templates/66940?fileFormat=xml> |
| P15 | <https://www.avito.ru/autoload/documentation/templates/97234?fileFormat=xml> |
| P16 | <https://www.avito.ru/autoload/documentation/templates> |
| P17 | <https://support.avito.ru/sections/1801?articleId=9645> — work with listings |
| P18 | <https://support.avito.ru/sections/139?articleId=2664> — «Указать остаток товара» |
| P19 | <https://support.avito.ru/articles/4538> — delivery for business |
| P20 | <https://support.avito.ru/articles/4539> — delivery by the seller |
| P21 | <https://www.avito.ru/general/dostavka/> |

## Related

- [Research index](README.md)
- [Gap map](gap-map.md)
- [Access needed from the owner](access-needed.ru.md)
