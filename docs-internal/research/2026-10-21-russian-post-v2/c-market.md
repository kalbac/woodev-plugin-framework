# Report C — Competing Russian Post integrations (market research)

> Part of the [Russian Post v2 fact base](README.md) (s191, 21.10.2026). Copied from the research run with credentials, account ids and
> local paths replaced by `<redacted>`. Raw response directories (`f-raw/`, `g-raw/`, `/private/tmp/...`) are NOT in the repo, so evidence-file
> references below point at files that exist only on the research machine. **Where [v-verify.md](v-verify.md) contradicts this report, v-verify wins** — see the README section «Contradictions resolved».

Read date for every source below: **21.10.2026** (unless a different date is stated). Method: wordpress.org plugin API, direct page fetches (curl), WebSearch. Search-engine summaries were treated as leads only; every fact below was read on the page named, or is marked UNVERIFIED.

## 0. Headline findings

1. **The WooCommerce market for Russian Post is thin and stale.** The only WooCommerce-native Russian Post plugins found are (a) a free wp.org plugin last updated **25.10.2021** (~1 000 installs), (b) its paid PRO add-on, now a **"Retired Product"** on woocommerce.com, and (c) a 2013-era paid Saphali plugin that calculates via the unofficial postcalc.ru. Everything else is a multi-carrier aggregator plugin that bolts Russian Post on as one of ~18 carriers.
2. **No Russian Post-first WooCommerce plugin ships today with the full chain** (official tariff API + pickup-point map + order export to Otpravka + labels/forms + tracking + status sync + COD) — except by assembling an aggregator (eShopLogistic) that does most of it behind a monthly SaaS fee.
3. **Block checkout** is explicitly supported only by eShopLogistic among the products found; every other WooCommerce product predates blocks.
4. Merchants' loudest complaints: *abandoned/outdated plugin*, *wrong prices vs. pochta.ru*, *no map of post offices*, *COD costs far more and is not priced separately*, *no support*.

## 1. WooCommerce plugins that ship via Russian Post

### 1.1 Product table

| # | Product | URL | Price | Last update | Traction | Notes |
|---|---|---|---|---|---|---|
| A | **Russian Post and EMS for WooCommerce** (free; Artem Komarov) | https://wordpress.org/plugins/russian-post-and-ems-for-woocommerce/ (API: https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=russian-post-and-ems-for-woocommerce) | free | **v1.3.8, 25.10.2021**; tested WP 5.8.18; requires PHP 7.0; added 2016-07-23 | ~1 000 active installs; rating 88 % (32 ratings: 26×5★, 1×4★, 1×3★, 4×1★); 0 open support threads | Official tariff.pochta.ru API; changelog 1.2.5 "moved to the official Russian Post API". |
| B | **Russian Post and EMS PRO for WooCommerce** (paid add-on to A) | https://woocommerce.com/document/russian-post-and-ems-pro-for-woocommerce/ ; product page https://woocommerce.com/products/russian-post-and-ems-pro-for-woocommerce/ | was sold on woocommerce.com; price not retrievable (page retired) | Product page now says: "we've retired this product" (read 21.10.2026) | wp.org review (undated): "Платный ПРО не обновлялся 2 года, а с позавчера убивает сайт при включении" | Requires A. See feature list §1.2. |
| C | **Saphali «Почта России и служба доставки EMS»** v2.5.8 | https://saphali.ru/woocommerce-plugins/woocommerce-pochta-rossii-and-ems-russian-post | **2 250 ₽** (read 21.10.2026) | published 20.10.2013; current-update date NOT read (UNVERIFIED) | "4.42 / 5 from 12 votes", 24 customer reviews, 13 521 page views | Uses **unofficial postcalc.ru** API; classic checkout only (2013 design); bundles a COD gateway. |
| D | **eShopLogistic Shipping Calculator** (WooCommerce module of SaaS eshoplogistic.ru) | https://wordpress.org/plugins/eshoplogisticru/ ; docs https://eshoplogistic.ru/moduli-dlja-cms/wordpress.html ; GitHub https://github.com/eshoplogistic/wordpress | Plugin free; SaaS **2 000 ₽/mo** (Basic: 2 500 req/day, 200 order exports/mo) or **5 000 ₽/mo** (Pro: 10 000 req/day, 5 000 exports/mo); 14-day trial (https://eshoplogistic.ru/prices.html) | **v3.1.64, 20.10.2026** (yesterday); tested WP 7.1.3; PHP 7.4+; added 2021-08-10 | 100 active installs; rating 92 % (5 ratings); 8 support threads | The only actively maintained one. 18+ carriers; Russian Post is one. Details §1.2. |
| E | **SafeRoute WooCommerce** (widget plugin for the SafeRoute aggregator) | https://wordpress.org/plugins/saferoute-woocommerce/ ; https://saferoute.ru/ | plugin free; SaaS price NOT confirmed (third-party claim of a free "Start" tier with 50 orders/mo seen in a search summary — UNVERIFIED; saferoute.ru/tariffs fetched but is JS-rendered) | **v3.5.1, 21.02.2025**; tested WP 6.2.14 | 40 active installs; rating 100 % (1 rating) | Russian Post office selection on the map via the SaferRoute widget (search summary; UNVERIFIED on the vendor page). wp.org shows the "not tested with latest 3 major releases" banner. |
| F | **Cash on Delivery of Russian Post or EMS for WooCommerce** (free, same author as A) | https://wordpress.org/plugins/cash-on-delivery-of-russian-post-or-ems-for-woocommerce/ | free | v1.4, **24.12.2017**; tested WP 4.9.34 | 70 installs; 1 rating | Basic COD tariffs only. Effectively dead. |
| G | **Shiptor "Логистическая платформа Shiptor для WooCommerce"** | https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=shiptor-woocommerce | — | **Closed 06.05.2022 (reason: guideline violation)** | — | Third-party mirror https://discover.commoninja.com/wordpress/plugin/shiptor-woocommerce still lists it; not downloadable from wp.org. |
| H | **Russian Post fulfilment for Dokan marketplace** | https://wordpress.org/plugins/russian-post-for-dokan-marketplace/ | free | v1.0.4, 14.12.2022 | 0 installs, 1 rating (20 %) | Fulfilment-only niche; ignore. |
| I | **GDEZAKAZI.RU** | https://wordpress.org/plugins/gdezakazi-ru/ | free | 16.09.2020 | 10 installs | Parcel tracking widget only. |

Not found (searched, 21.10.2026): a WooCommerce plugin from **ApiShip** (no wp.org plugin with that name; apiship.ru integrations page fetched, no WooCommerce/WordPress mention — page may be JS-rendered, so UNVERIFIED); a WooCommerce plugin from **PBRF** («Почтовый бланк РФ») is reported by one search summary but could not be confirmed (UNVERIFIED — see §2.5). No commercial Russian vendor selling a Russian-Post-first WooCommerce plugin with a pickup-point map was found.

### 1.2 Feature lists as stated by the vendors

**A (free) — from wp.org description, https://wordpress.org/plugins/russian-post-and-ems-for-woocommerce/ :** cost by cart weight and value; delivery time; prices per delivery type; email with track number; fixed extra cost; multi-currency (1.2); inland + international tariffs, declared-value and non-declared-value methods, corporate-client methods, extra-service codes (1.2.5/1.2.6); tax option; weight limit; request logging; EKOM packaging fix (1.2.6); "Письмо" (letter) added 1.2.9; ground/air choice (1.2.9); cache clearing + EKOM price update (1.3.2); free-shipping options (1.3.4); automated tracking for PRO (1.3.5); COD fix (1.3.5). **No pickup map, no export, no labels in the free plugin.**

**B (PRO) — https://woocommerce.com/document/russian-post-and-ems-pro-for-woocommerce/ :** one-click order sync to the Russian Post dashboard (address normalisation + verification, then create shipment; shipment id written to order notes; can be re-sent); EKOM method with a synced DB of delivery points; **Yandex Maps API key for a map of pickup points**; VAT rate for goods list; automatic track number fetch + order-status change from tracking; COD: recalculates price "as EMS with declared value and COD service" when the built-in WooCommerce COD gateway is chosen; two dashboards for two shipment types; multiple OPS; shipping-class options; postcode auto-select; bundled DB of Russian regions/cities. Needs the UTP number filled in Russian Post account for COD + API sync.

**C (Saphali) — https://saphali.ru/woocommerce-plugins/woocommerce-pochta-rossii-and-ems-russian-post :** calc via postcalc.ru for Post and EMS, inland and abroad; simple/registered/valuable parcels and wrappers, 1st-class, EMS courier, avia; COD commission ("Киберпочта"); delivery terms; hide selected services; max shipping price cap; default weight (100 g) configurable; handling surcharge fixed/percent; sender city required; currency conversion (Yahoo FX) for non-ruble shops; COD gateway included; auto-split of overweight orders. No pickup map, no export, no labels.

**D (eShopLogistic) — https://eshoplogistic.ru/moduli-dlja-cms/wordpress.html (verbatim feature list):** cost/term in cart with single PVZ map widget or per-carrier methods; widget on product page (popup or embedded); **"поддержка классического и блочного режима чекаута WooCommerce"**; weight/dimensions from product cards or defaults; link payment types to delivery methods; **export of orders to carrier dashboards from the WooCommerce admin** (for Russian Post among CDEK, DPD, Yandex, 5Post, Delovye Linii, PEC, Baikal); edit package count/params before export; **sync of statuses**; **print shipping labels** from admin; track number; prepaid and COD orders; delete request in carrier dashboard; flexible price/term correction rules (by payment type, delivery type, destination, order sum, qty, weight). Stated limits: needs product weight; needs Country + City fields in checkout; **does not work** with step-by-step checkout, select2 on the City field, ≥2 shipping zones with the same region, or masked/range postcodes; recommends a fallback non-plugin method for outages.

**E (SafeRoute) — https://wordpress.org/plugins/saferoute-woocommerce/ :** widget with all carriers in one place; Russian Post office on the map. wp.org support (undated) lists missing features after the author's replies: "не хватает показа в админке адреса который выбрал клиент; не хватает оповещения если при передаче что-то пошло не так; неправильно передаёт стоимость заказа / стоимость доставки" — https://wordpress.org/support/topic/%d0%b1%d0%be%d0%bb%d1%8c%d1%88%d0%b5-%d0%bd%d0%b8%d0%ba%d0%b0%d0%ba%d0%b8%d1%85-%d0%be%d0%b1%d0%bd%d0%be%d0%b2%d0%bb%d0%b5%d0%bd%d0%b8%d0%b9/

## 2. Other platforms — what is table stakes

### 2.1 1C-Bitrix (marketplace.1c-bitrix.ru)

| Module | URL | Data (read 21.10.2026) |
|---|---|---|
| eDost "Расчет доставки (Почта, СДЭК, ПЭК …)" | https://marketplace.1c-bitrix.ru/solutions/edost.delivery/ | v2.6.5, updated 28.06.2026, published 2010, ">1000 installs", paid SaaS (first month free for "Магазин"). Features: Russian Post calc for **200 000 settlements**, own checkout template with **single map of PVZ for all carriers**, insurance, **COD markups and limits shown in order**, CIS countries, international. 167 discussion threads, 33 reviews. |
| eShopLogistic | https://marketplace.1c-bitrix.ru/solutions/eshoplogistic.delivery/ | Same SaaS as the WooCommerce plugin. Cart + product-card widgets, order export to carrier cabinets, correction rules. |
| ApiShip 2 | https://marketplace.1c-bitrix.ru/solutions/ipol.apiship2v/ | "Free integrator", own contracts and keys; courier + PVZ map; **order hand-off to carrier cabinet after admin edit/confirm, print receipts and courier transfer acts, status sync with Bitrix**; picks cheapest tariff automatically; 40+ carriers incl. Russian Post. |
| MetaShip | https://marketplace.1c-bitrix.ru/solutions/metaship.logistic/ | v1.0.42, updated 16.10.2026, 50–99 installs, 0 reviews. Two schemes: own contracts, or aggregator contract (Russian Post + CDEK). Map PVZ widget; order hand-off after admin confirm; **receipts and transfer acts**; status sync. |
| Softpodkluch "Почта России + EMS" | https://softpodkluch.ru/bitrix/russianpost/ | Calc via **three switchable APIs: tariff.pochta.ru, postcalc.ru, otpravka.pochta.ru**; options list (declared value ₽1, inventory, notification of delivery, SMS, fragile, "own hands" …); default weight/dimensions; rounding; COD; price UNVERIFIED. |
| Sotbit "Расчет доставки (Почта России/EMS)" 1.5.0 | https://marketplace.1c-bitrix.ru/blog/the-calculation-of-shipping-russia-postemsversion-150-the-world-in-our/ | postcalc-based; very old (blog post is years old; date not read). |

Official Russian Post CMS-plugins page, https://cms.pochta.ru/ (JS-rendered, could not be read). A search summary of it (UNVERIFIED) says the official free modules require a contract, list 1C-Bitrix, and do not list WooCommerce or OpenCart; the Habr post quoted in §3 corroborates that Russian Post has no official WooCommerce plugin ("Почта РФ не планирует создавать плагин для woocommerce"). A third-party contractor prices "Setting up CDEK and Russian Post on Bitrix" at from 18 000 ₽ (https://hrustalev.com/services/nastroyka-gotovogo-sayta/nastroyka-sdek-i-pochty-rossii-na-sayte-1s-bitriks/ — from search listing, not opened).

### 2.2 InSales

* **«Отправка.Почта.ру» app (3rd party "Yooogi")**, **1 000 ₽/month** — https://www.insales.ru/collection/apps-kurierskie-sluzhby/product/pochta-rossii-otpravka (read 21.10.2026). The richest feature list of any competitor:
  rates for 10 tariffs (Посылка, Посылка 1 класса, Посылка онлайн, Курьер онлайн, EMS, Международные …); price rules per region/city/country (percent/absolute markup); **insurance base choice** (goods vs goods+delivery); default weight/dimensions or from InSales; **hide tariffs beyond weight/dimension limits**; include SMS-notification and "Fragile" in price; extra packaging weight with **box rules by weight band**; add days to terms; order-status mapping from Post status; **create/delete/move BATCHES, waybills, waybill documents from the order widget**; auto-assign new orders to existing batches by RPO category; print labels and batch transfer docs; change shipment date per batch; **buyer-reliability check** (Post's list of recipients who do not collect parcels, shown before dispatch); order data normalisation (name, phone, address); track number auto-written into an InSales order field. Stated limits: needs a contract with Russian Post; tariff list differs per contract; **no reverse sync with Otpravka "due to limit on API requests (new clients: 200)"**.
* Official inSales items exist ("Посылка Почтой России — автоматический расчёт стоимости", "EMS Почта России", "inSales Доставка" aggregator where Russian Post is connected on request via support) — listed in search results, https://www.insales.ru/collection/doc-delivery/product/posylka-pochtoy-rossii-avtomaticheskiy-raschet-stoimosti (not opened; details UNVERIFIED).

### 2.3 OpenCart / ocStore

* **«Почта России для OpenCart»**, liveopencart.ru — **515 purchases, avg 4.7 from 74 reviews**, price ≈ 2 600 ₽ (search summary; page price not re-read): https://liveopencart.ru/opencart-moduli-shablony/moduli/dostavka/pochta-rossii-dlya-opencart (read 21.10.2026). Features: nearly all tariffs incl. new **ЕКОМ Маркетплейс**; **3 switchable APIs** (tariff.pochta.ru / postcalc.ru / otpravka.pochta.ru) + delivery.pochta.ru for terms; up to 10 delivery methods each with own tariff list, title, icon, surcharge and packaging; **pickup-box (почтомат) map for "Посылка Онлайн"**; surcharges on weight/dimensions/price/time by region, items, price, weight; **cheapest-fitting packaging picked automatically**; **bundled COD payment module with commission**; "pay delivery online" option.
* Forum modules at opencartforum.com (search listing only; pages not fully read): export to otpravka.pochta.ru with ЕКОМ + PVZ pick-list and tracking after export (https://opencartforum.com/topic/148108-modul-pochta-rossii-vygruzka-zakazov-v-otpravkapochtaru-podderzhka/); print postal forms 113/117 for COD (https://opencartforum.com/topic/49436-podderzhka-rp-cod-formy-113-i-117-nalozhennyy-platezh-pochta-rossii/); a note that postcalc.ru free access was cut to ≤5 shipments from 01.06.2020 (prodelo.biz, search listing; UNVERIFIED).

### 2.4 Webasyst / Shop-Script (for contrast only)

"Вся Почта России" and "Почта России (Webasyst)" — https://www.webasyst.ru/store/plugin/shipping/allpr/ ; print forms 7-п, 107, 112ЭП, 113, 116; COD amount shown to buyer including fees. The "Почта России (Webasyst)" plugin is, per a search summary, unmaintained since 2020 (UNVERIFIED).

### 2.5 Tilda, MoySklad, 1C

* Tilda: no Russian-Post-first widget found; eShopLogistic and Yandex Delivery modules exist for Tilda (https://yandex.ru/support/delivery-profile/ru/modules/tilda).
* MoySklad app "Russian Post": tariffs from order/shipment, track number back to buyer (https://www.moysklad.ru/integratsii/apps/russian-post/).
* Official extension for 1C:Enterprise: address check, labels/forms, tracking, calc (https://buh.ru/articles/kak-v-1s-nastroit-integratsiyu-s-servisom-otpravki-pisem-i-posylok-pochty-rossii.html).
* PBRF («Почтовый бланк РФ») prints Ф.116, Ф.107, Ф.112эп, Ф.7 labels as a paid WooCommerce extension per one search summary; site https://pbrf.ru/ was fetched but the WooCommerce claim was not confirmed (UNVERIFIED).

### 2.6 What the other platforms treat as table stakes (synthesis of §2.1–2.4)

1. Choice among **several APIs/tariff sources** (official public, official contract-based Otpravka, unofficial postcalc) — Bitrix/Softpodkluch and OpenCart/liveopencart both do this.
2. **ЕКОМ + pickup-point/postomat map** (eDost, MetaShip, eShopLogistic, liveopencart).
3. **Declared value / insurance and COD pricing** treated explicitly (eDost "COD markups and limits"; InSales app "insurance base"; liveopencart COD commission).
4. **Weight/dimension defaults, packaging weight/boxes, max-limits that hide tariffs** (InSales app, Softpodkluch, liveopencart).
5. **Markup rules by region/city/product/weight/price** (all of them).
6. **Order export to Otpravka (batches/waybills), labels and forms (F7, 112ep, 113), tracking and status mapping** — present on InSales app, MetaShip, ApiShip, eShopLogistic, OpenCart forum modules; absent from every maintained WooCommerce Russian-Post-first plugin.
7. Optional extras: buyer-reliability pre-check, address normalisation, delivery-terms surcharge days (InSales app).

## 3. Merchant voice — complaints, requests, what breaks

Quotes are short and verbatim from the cited pages (all read 21.10.2026). wp.org thread dates were not exposed in the fetched HTML, so ages are given only where the page states them.

**Plugin A (free) — wp.org reviews/support:**

| Theme | Quote | Link |
|---|---|---|
| Abandonment | «Бесплатный плагин не обновляется под текущие реалии Почты России. Платный ПРО не обновлялся 2 года, а с позавчера убивает сайт при включении.» | https://wordpress.org/support/topic/%d0%be%d0%b1%d0%bd%d0%be%d0%b2%d0%bb%d0%b5%d0%bd%d0%b8%d0%b5-%d0%b1%d1%83%d0%b4%d0%b5%d1%82/ |
| Abandonment | «Плагин устарел, планируются ли обновления?» | https://wordpress.org/support/topic/%d0%ba%d0%be%d0%b3%d0%b4%d0%b0-%d0%be%d0%b1%d0%bd%d0%be%d0%b2%d0%bb%d0%b5%d0%bd%d0%b8%d0%b5-2/ |
| Prices differ from pochta.ru | «Результат расчета плагина не идёт с расчетом на сайте почты России … На сайте ПР ограничения 20 и 31 кг. а в плагине 10кг. Нельзя отключить упаковку. У нас своя упаковка, зачем плагин принудительно вынуждает выбирать коробку?» | https://wordpress.org/support/topic/%d0%bd%d0%b0-%d0%bf%d1%80%d0%b0%d0%ba%d1%82%d0%b8%d0%ba%d0%b5-%d0%bd%d0%b5-%d0%bf%d1%80%d0%b8%d0%bc%d0%b5%d0%bd%d0%b8%d0%bc%d0%be/ |
| Prices differ | «доставка из Питера более-менее правдоподобна только в Москву. В Кировск разница в 40%, в Дербент … почти в 2 раза. Но бесплатной альтернативы нет» | https://wordpress.org/support/topic/%d1%81%d1%87%d0%b8%d1%82%d0%b0%d0%b5%d1%82-%d0%ba%d1%80%d0%b8%d0%b2%d0%be/ |
| Standard vs non-standard parcel | Resolved thread: zone "Type" must be "посылка нестандартная (50к)", not "стандартная (10к)", because "официальный сайт Почты России пользуется именно этим вариантом" | https://wordpress.org/support/topic/%d0%b2%d1%81%d0%b5-%d1%80%d0%b0%d0%b1%d0%be%d1%82%d0%b0%d0%b5%d1%82-%d0%ba%d0%be%d1%80%d1%80%d0%b5%d0%ba%d1%82%d0%bd%d0%be/ |
| Weight not summed | «плагин не считает суммарный вес всех товаров и соответственно не изменяется стоимость доставки … для магазинов где покупать могут и одну единицу товара и сто единиц, плагин не подходит» | https://wordpress.org/support/topic/%d1%81%d0%bb%d0%b0%d0%b1%d0%be/ |
| COD priced separately | «Хотелось бы увидеть отдельный расчет стоимости для тех кто хочет оплатить сразу (онлайн), и для наложенного платежа, т.к. он выходит в разы дороже. + всякие опции с описью» | https://wordpress.org/support/topic/%d0%be%d1%82%d0%bb%d0%b8%d1%87%d0%bd%d1%8b%d0%b9-%d0%bf%d0%bb%d0%b0%d0%b3%d0%b8%d0%bd-845/ |
| Pickup map | «Скажите есть ли в платной версии выбор пункта доставки на карте, как в виджете почты россии?» | https://wordpress.org/support/topic/%d0%be%d1%82%d0%bb%d0%b8%d1%87%d0%bd%d1%8b%d0%b9-%d0%bf%d0%bb%d0%b0%d0%b3%d0%b8%d0%bd-770/ |
| International | «Платная версия ничего не предлагает для тех которых отправляют посылки заграницы» | https://wordpress.org/support/topic/%d1%80%d0%b0%d0%b1%d0%be%d1%82%d0%b0%d0%b5%d1%82-52/ |
| Data loss on uninstall | «При попытки удалить плагин рушится база заказов. Тоже самое происходит при попытки отключить плагин.» | https://wordpress.org/support/topic/%d0%bf%d1%80%d0%b8-%d1%83%d0%b4%d0%b0%d0%bb%d0%b5%d0%bd%d0%b8%d0%b5-%d0%bf%d0%bb%d0%b0%d0%b3%d0%b8%d0%bd%d0%b0-%d1%80%d1%83%d1%88%d0%b8%d1%82%d1%81%d1%8f-%d0%b1%d0%b0%d0%b7%d0%b0-%d0%b7%d0%b0%d0%ba/ |
| Positive | «Делает свою работу: расчитать стоимость доставки с почтой и отправлять с помощью одной кнопки ссылку на отслеживание заказа клиентам» (the use case people actually want from the free plugin) | https://wordpress.org/support/topic/%d1%80%d0%b0%d0%b1%d0%be%d1%82%d0%b0%d0%b5%d1%82-52/ |

**eShopLogistic (D):**

| Theme | Quote | Link |
|---|---|---|
| No support | «Поддержки нет никакой совершенно. На запросы не отвечают. На звонки не отвечают.» | https://wordpress.org/support/topic/%d0%bd%d0%b5-%d1%81%d0%be%d0%b2%d0%b5%d1%82%d1%83%d1%8e-2/ |
| Positive support | «Поддержка сервиса на высоте, всегда помогают в самый короткий срок» | https://wordpress.org/support/topic/%d0%bb%d1%83%d1%87%d1%88%d0%b0%d1%8f-%d0%ba%d0%bb%d0%b8%d0%b5%d0%bd%d1%82%d0%be%d0%be%d1%80%d0%b8%d0%b5%d0%bd%d1%82%d0%b8%d1%80%d0%be%d0%b2%d0%b0%d0%bd%d0%bd%d0%be%d1%81%d1%82%d1%8c-%d0%bd%d0%b0/ |
| Zone postcodes break it | «нужно было убрать индексы из настроек зон доставки, просто удалить» (also in vendor's limits list) | https://wordpress.org/support/topic/c-wp-6-0-%d1%80%d0%b0%d0%b1%d0%be%d1%82%d0%b0%d0%b5%d1%82/ |
| Checkout/pay flow and request quota | «Фактически он на checkout не работает. Получается только виджет, но при этом оплату картами онлайн сразу сделать нельзя? … их 1500 на тарифе, то на каждой странице товара клиент будет делать эти запросы» | https://wordpress.org/support/topic/%d1%80%d0%b0%d0%b1%d0%be%d1%82%d0%b0-%d0%bf%d0%bb%d0%b0%d0%b3%d0%b8%d0%bd%d0%b0-%d0%bd%d0%b0-%d1%81%d1%82%d1%80%d0%b0%d0%bd%d0%b8%d1%86%d0%b5-checkout/ |
| Transparency | Changelog/update info missing; description linked to a dead domain | https://wordpress.org/support/topic/how-to-receive-information-about-updates/ |

**SafeRoute (E):** «WordPress6.1 не совместим с SafeRout. Что делать?» — https://wordpress.org/support/topic/wordpress6-1-%d0%bd%d0%b5-%d1%81%d0%be%d0%b2%d0%bc%d0%b5%d1%81%d1%82%d0%b8%d0%bc-%d1%81-saferout/

**Habr Q&A (demand for a Russian Post plugin / widget):**

* 26.06.2023 — «Почта РФ не планирует создавать плагин для woocommerce, но мы проанализировали поведение пользователей, из-за всех этих заполнений полей бесконечных и ошибок в городах и адресах многие сливаются … Проще было бы, чтоб покупатель мог нажать на кнопку выбрать отделение и открылась карта» — https://qna.habr.com/q/1289552
* 22.01.2023 — merchant struggling with the official Russian Post widget: it requires a "Callback функции" name for returning the address to the shop system — https://qna.habr.com/q/1244260
* 30.06.2021 — recommended answer to "auto-calc for CDEK/Russian Post in WooCommerce" is just the free plugin A — https://qna.habr.com/q/1012661

**Other platforms:**

* Bitrix eDost, developer thread 29.07.2026: user "Сайт https://edost.ru/ не работает. Как без него всё сделать?" — SaaS dependency/availability risk (developer replies site is up) — https://marketplace.1c-bitrix.ru/solutions/edost.delivery/
* InSales app: documented limit "Приложение не имеет обратной синхронизации с Отправкой из-за ограничения на количество запросов к их API (у новых клиентов - 200 запросов)" — https://www.insales.ru/collection/apps-kurierskie-sluzhby/product/pochta-rossii-otpravka
* Older (undated) Habr Q&A complaint, via search summary only (UNVERIFIED): "Родной api почты возвращает совсем не те цены и сроки, к тому же есть ограничения по кол-ву запросов, ТП не работает." (qna.habr.com, URL not recorded).

**Pattern across sources:** (1) price mismatch vs pochta.ru calculator (parcel size class, packaging, weight limits, COD uplift); (2) pickup-point selection is wanted but the official widget is hard to wire into WooCommerce; (3) COD is expensive and must be priced and explained separately; (4) abandoned plugins; (5) aggregator plugins add SaaS quotas, outage dependency and checkout-compatibility restrictions.

## 4. Feature matrix

Legend: ✔ stated by vendor/page · ◐ partial / limited · ✘ not offered / not stated · ? could not verify

Columns: **A** = free wp.org plugin (+F COD); **B** = A + PRO add-on (retired); **C** = Saphali; **D** = eShopLogistic (WooCommerce); **E** = SafeRoute; **Ref-OC** = liveopencart module (best OpenCart); **Ref-IS** = InSales «Отправка.Почта.ру» app (best order-handling reference); **Ref-BX** = MetaShip/ApiShip (Bitrix).

| Feature | A | B (A+PRO) | C Saphali | D eShopLogistic | E SafeRoute | Ref-OC | Ref-IS | Ref-BX |
|---|---|---|---|---|---|---|---|---|
| Live rates, official API | ✔ tariff.pochta.ru | ✔ | ✘ (unofficial postcalc.ru) | ✔ (via aggregator, own contract) | ✔ (via aggregator) | ✔ 3 switchable APIs | ✔ Otpravka | ✔ |
| Delivery term shown | ✔ | ✔ | ✔ | ✔ | ? | ✔ (delivery.pochta.ru) | ✔ (+ add days) | ✔ |
| Pickup point (PVZ/post office) map | ✘ | ◐ EKOM points + Yandex Maps key | ✘ | ✔ single map for all carriers | ✔ (search summary) | ◐ postomat map for "Посылка Онлайн" | ✘ stated | ✔ |
| ЕКОМ tariff | ◐ (EKOM packaging/validation) | ✔ | ✘ | ? | ? | ✔ | ? | ? |
| Declared value / insurance base option | ✔ | ✔ | ✔ | ? | ? | ◐ | ✔ choose base | ◐ |
| COD (наложенный платёж) pricing | ◐ (fix in 1.3.5) | ✔ recalcs on COD gateway | ✔ + bundled gateway, min/max order sum | ✔ COD orders | ? | ✔ + bundled gateway + commission | ✔ COD sums tracked | ✔ |
| Hide tariffs beyond weight/size limit | ◐ weight limit only | ✔ | ◐ price cap only | ? | ? | ✔ | ✔ | ◐ |
| Weight/dims defaults + packaging weight/boxes | ◐ | ◐ | ◐ default weight only | ✔ | ? | ✔ cheapest fitting box | ✔ box-by-weight-band | ✔ |
| Markup/correction rules | ✔ fixed extra | ◐ | ✔ fixed/% | ✔ rich rules | ? | ✔ by region/items/price/weight | ✔ | ✔ |
| Order export to Otpravka / carrier cabinet | ✘ | ✔ with address normalisation | ✘ | ✔ | ✔ | ◐ (separate forum module) | ✔ batches/waybills | ✔ |
| Labels / forms (F7, 112ep, 113) | ✘ | ✘ stated | ✘ | ✔ labels only | ? | ◐ (forum module) | ✔ labels + transfer docs | ✔ receipts + acts |
| Track number to buyer / email | ✔ | ✔ + auto-fetch | ✘ stated | ✔ | ◐ | ✔ | ✔ into custom order field | ✔ |
| Status sync → order status | ✘ | ✔ | ✘ | ✔ | ◐ | ✔ | ✔ configurable mapping | ✔ |
| Batches (партии) / handover to OPS | ✘ | ✘ | ✘ | ✘ stated | ✘ | ✘ | ✔ | ✔ acts |
| Address normalisation / postcode auto-pick | ✘ | ✔ | ✘ | ◐ (city-list based) | ? | ? | ✔ | ? |
| International shipping | ✔ | ◐ (reviewer: nothing new for abroad) | ✔ | ◐ CIS via CDEK | ? | ✔ | ✔ | ◐ |
| Multi-currency | ✔ | ? | ✔ | ✘ | ? | ? | n/a | n/a |
| Block checkout support | ✘ | ✘ | ✘ | ✔ stated | ✘ (last tested WP 6.2) | n/a | n/a | n/a |
| Buyer-reliability pre-check | ✘ | ✘ | ✘ | ✘ | ✘ | ✘ | ✔ | ✘ |
| Maintained (update < 12 mo) | ✘ 2021 | ✘ retired | ? (published 2013, update date unread) | ✔ 20.10.2026 | ✘ 02.2025 | ✔ (74 reviews; author inactive >1 mo) | ✔ | ✔ |
| Cost to merchant | free | paid (retired) | 2 250 ₽ once | 2 000–5 000 ₽/mo SaaS | SaaS (price UNVERIFIED) | ≈2 600 ₽ once (UNVERIFIED re-read) | 1 000 ₽/mo | SaaS / contract |

## 5. Features nobody does well (gaps)

Derived from §3–§4; each row cites the evidence.

1. **Honest price parity with pochta.ru.** Every WooCommerce plugin is blamed for mismatches (parcel standard vs non-standard class, forced packaging, weight limits 10/20/31 kg, COD uplift). Nobody explains *why* the number differs or offers a "compare with pochta.ru" diagnostic. (§3 table, plugin A.)
2. **Separate online-prepaid vs COD price shown to the buyer** at checkout before choosing payment — requested explicitly; only PRO recomputes on COD gateway selection, and PRO is retired.
3. **Pickup-point/post-office map inside WooCommerce checkout that survives both classic and block checkout and does not depend on a SaaS.** Only eShopLogistic (SaaS, quota) offers blocks; PRO used a Yandex Maps key on classic only.
4. **Full order lifecycle inside WooCommerce admin for Russian Post specifically**: Otpravka export, batches (партии), F7/112ep/113 forms, transfer act to OPS, reverse sync of status. InSales app does it best, WooCommerce has nothing equivalent (eShopLogistic has labels but no batches/forms).
5. **Buyer-reliability pre-check and address normalisation before dispatch** — only the InSales app does it.
6. **Graceful degradation / outage behaviour**: eShopLogistic itself advises a fallback method because "нулевой баланс, сетевая ошибка" returns no options; request-quota anxiety is a recorded complaint (1 500 on an old plan). No product shows cached last-known rates or flags API unavailability to the merchant clearly.
7. **Clean uninstall/deactivate** — one report of orders DB breaking on uninstall (plugin A).
8. **International / CIS shipments with correct customs and COD limits** — PRO is reported as weak there; no plugin covers Russian Post "export COD" to AM/KZ/MD/BY (feature launched by Russian Post, retail.ru 19.08.2024 per a search summary, UNVERIFIED).
9. **Multi-warehouse / multiple sender OPS and two-contract setups** — only PRO claimed "several OPS points / two dashboards"; no maintained product does.
10. **Transparent changelog and data-handling for SaaS-mediated plugins** — complaint of missing update information and dead links (eShopLogistic thread above).

## 6. Not checked / UNVERIFIED (summary)

* SafeRoute SaaS prices and whether its widget really offers Russian Post office map (vendor page JS-rendered).
* Saphali current version date/update log and customer reviews; whether it still works with current WooCommerce.
* Whether PBRF sells a WooCommerce extension.
* cms.pochta.ru (official modules page) — JS-rendered; contents known only through a search summary.
* opencartforum.com, InSales official "Посылка Почтой России" items, Webasyst plugins: only listings read, not full module pages.
* Active installs/ratings for plugins not on wp.org (all non-wp.org figures are what the vendor page shows).
* Telegram chats and 1C-Bitrix review threads beyond the eDost front page — not mined; deeper complaint mining would need those.
* Price of the retired PRO add-on (page retired).

## Related

* [README.md](README.md) — consolidated fact base and the resolved contradictions.
* [brainstorm-agenda.ru.md](brainstorm-agenda.ru.md) — the operator's brainstorm agenda built on these reports.
