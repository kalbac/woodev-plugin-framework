TBANKB — T-Bank instalments/credit: API today and the market (research, 26.10.2026)

Scope: task B of the T-Bank wave 0. Research only; no code, no commit, no rig access. Accessed 26.10.2026.
Fact tags: **READ** (read in the source named), **MEASURED** (I ran a probe), **UNVERIFIED** (reason given).

## 0. Reading conditions — please read first

- `forma.tbank.ru` / `forma.tinkoff.ru` (the API documentation host) **did not answer from this Mac**: DNS resolves to `178.130.128.42` for both, but every TCP connect timed out (MEASURED, 4 pages × 30 s, then `forma.tbank.ru/` and `forma.tinkoff.ru/docs/...` once more). So I could NOT read the primary API reference pages (`.../setup-types/api/methods`, `description-api`, `testing-api`, `app-notifications`, `script/parameters`).
- What I used instead, in descending trust:
  1. **T-Bank's own help pages** on `www.tbank.ru/business/help/sales/loans/...` (reachable; TLS chain is the Russian Trusted Root CA, absent from this Mac's trust store, so I fetched them with `curl -k`, read-only GET, no credentials sent). Official, but prose, not an API reference.
  2. **A third-party transcription of the two official reference pages** — `github.com/alewon/tbank-installment-go-client` (`doc.md` states it was compiled from `forma.tbank.ru/docs/online/setup-types/api/methods` and `.../settings/app-notifications`; fetched over normal TLS). Method/field/status tables below come from it. It is a secondary source: tag **READ (secondary)**. It may lag the live docs; its own `doc.md` §4.3 flags that the official `Create` example contains `demoFlow` that the parameter table omits.
  3. WebSearch snippets of the official pages (titles/URLs only; marked UNVERIFIED where I rely on the snippet alone).
- Consequence: "last update / version" of the official integration docs is **UNVERIFIED** (page not reachable; the docs pages carry no version I could see). The operator, or any machine with a Russian IP, can re-read the six URLs in §1.3 in minutes to close this.

## 1. The product

### 1.1 Naming (READ)
- Current merchant-facing name: **«Рассрочка в Т-Банке»** (help centre, `tbank.ru/business/help/sales/loans/`); the API docs and the Go client call it «T-Рассрочка» / TCB (`id` = "ID заявки в TCB"). Older names that merchants still use: «Кредиты онлайн», «Купи в кредит», «Тинькофф Кредит», `forma.tinkoff.ru`. Our v1 title: «Рассрочки и кредиты от Т-Банк» (`woodev-vkredit.php:5`, 1.7.0). The shop cabinet lives at `business.tbank.ru/posbroker` (`includes/class-gateway.php:208,217,226`).
- It is a **consumer-credit broker**: the buyer fills one form on the bank's page; it fans out to partner banks (T-Bank, OTP Bank, MTS Bank per `chosen_bank` field); the buyer picks an approved offer and signs by SMS / Self-ID / in person; the merchant gets the money (sources: help-api page, Go `doc.md` §3.3, §6.5).

### 1.2 What the merchant signs up for (READ, help pages 26.10.2026)
- Connection is **free**; no commission on credits; **instalments carry a commission = the "скидка"** the service deducts from the payout (example on the terms page: 10 000 ₽ for 3 months at a 6 % discount → merchant receives 9 400 ₽). Exact discounts are given by phone/chat before connecting, and in the cabinet after. An optional "instalment with commission" product shifts cost to the buyer (overpayment shown before signing). `about-loans/terms/`.
- Merchant gets a **head's cabinet** (shops, managers, reports, HTTP/e-mail notifications, return links, auto-confirm) and per-shop identifiers: **ShopID** (company) and **ShowcaseID** (site; all shops of one legal entity share it per the cabinet help; the troubleshooting page says the opposite — "ShowcaseId — уникальный идентификатор магазина" — **inconsistent in the bank's own text**). API password is set under «Магазины» → «Настройки API» (v1 `class-gateway.php:226`).
- Payout: same day if the settlement account is in T-Bank, otherwise next business day (help-api page).
- Buyer eligibility on the terms page: age 21–65, ≥3 months official work in Russia, phone `+79…`; foreigners from a list of countries are excluded (terms page).
- Receipts: with **API integration the service issues the fiscal receipt automatically**; with every other integration the manager must issue it (theory page: "Выбивать чек … не нужно: система сделает это автоматически" is on the API page; "Доступно при интеграции по API"). This is a selling point of the API route that v1 already takes.

### 1.3 What the buyer sees (READ)
Order placed in shop → redirected to the bank's form (pre-filled with name/phone/e-mail) → decisions from banks "within 2 minutes" → buyer chooses a bank → signs (SMS/Self-ID/meeting) → **cooling period** if the loan is ≥ 50 000 ₽ → merchant confirms (or auto-commit) → payout. Return links: success / fail / cancel (`successURL`, `failURL`, `returnURL`).

Official integration documentation URLs (primary; **not reachable from this Mac, UNVERIFIED content**):
- API how-to: `forma.tbank.ru/docs/online/setup-types/api/description-api`, `.../sell-start`, `.../testing-api`, `.../methods`
- Notifications: `forma.tbank.ru/docs/online/settings/app-notifications`
- Button/script: `forma.tbank.ru/docs/online/setup-types/script/parameters`
- Application scheme / auto-commit: `forma.tinkoff.ru/docs/online/scheme/application`, `.../settings/auto-commit` (linked from the T-Bank help page, still on the OLD domain — MEASURED in `help-api.html` hrefs).
- Help-centre integration hub (reachable, READ): `tbank.ru/business/help/sales/loans/how-to-integrate/` (+ `/api/`, `/button/`, `/theory/`, `/solve-problems/`).

### 1.4 Demo / test mode (READ, secondary + v1)
- Separate endpoint `…/orders/create-demo`; **test orders never reach the bank**; same `shopId`/`showcaseId`/`promoCode`; Basic-Auth username for `Info/Commit/Cancel` on demo orders is **`demo-<showcaseId>`** (Go client `authUsername()`; our v1 does the same, `includes/api/class-wc-tinkoff-api.php:150-154`).
- `demoFlow` is accepted by `create-demo` (value set differs by source — see open question Q3). The testing page, per WebSearch snippet: "позволяют создать заявку, подтвердить, отменить её и получить актуальный статус" (UNVERIFIED, snippet only).

## 2. API surface

Base URL today: `https://forma.tbank.ru` (Go client `client.go:17`, `doc.md` §4.2). Rate limit **25 req/s**, then HTTP 429 + `Retry-After` (`doc.md` §2.1). Auth: HTTP Basic (`showcaseId` : API password) on `Commit`/`Cancel`/`Info`; `Create` is called unauthenticated by the Go client (`client.go`: `useAuth=false`) although the doc text for `Create` lists a 401 — inconsistent, UNVERIFIED.

### 2.1 Methods (READ, secondary — Go `doc.md` §4–§8)

| Method | HTTP | Notes |
|---|---|---|
| Create | `POST /api/partners/v2/orders/create` | Body: `shopId`, `showcaseId`, `sum`, `items[]` required; `orderNumber`, `promoCode` (default `default`), `webhookURL`, `successURL`, `failURL`, `returnURL`, `values.contact{fio{lastName,firstName,middleName},mobilePhone,email}`. Response `{id, link}`. **Idempotent**: same `orderNumber` + same cart returns the existing link; same number + different cart → error ("заказ с таким номером уже существует", help solve-problems). |
| CreateDemo | `POST /api/partners/v2/orders/create-demo` | Same body (+ `demoFlow`, `initialStage`?). |
| Commit | `POST /api/partners/v2/orders/{orderNumber}/commit` | Confirms a signed order → payout. Blocked during cooling. |
| Cancel | `POST /api/partners/v2/orders/{orderNumber}/cancel` | Irreversible. **Impossible after signing if auto-commit is on** → refund route instead (`doc.md` §6.3). |
| Info | `GET /api/partners/v2/orders/{orderNumber}/info` | Returns the full order body. |
| Webhook | merchant URL | see §2.3. |

**There is no refund method.** Refund = the merchant bank-transfers the payout amount to the buyer's credit-account requisites shown in the manager cabinet («Оформить возврат»), 2–3 (up to 7) business days; instalment commission is not refunded (help `lend/return/`, READ). So `WC_Payment_Gateway::process_refund()` cannot be automated against this API — only a note/instruction can be given.

`orderNumber` is mandatory in practice: without it HTTP notifications and `Commit/Cancel/Info` do not work (help solve-problems; `doc.md` §4.3).

### 2.2 Statuses (READ, secondary — `doc.md` §3.1, §8.7.2)
`new` (created, SMS not confirmed) · `inprogress` (SMS confirmed; passport step / waiting for ≥1 bank) · `approved` (≥1 bank approved) · `signed` · `canceled` (by buyer) · `rejected` (**all** banks refused — applies to Info/Commit/Cancel responses). Webhook `status` only takes `signed|canceled|rejected|approved`. Extra flags: `committed`, `demo`, `commit_cooldown{until,has_happened}`, `product` (`credit|installment_credit`), `signing_type`, `chosen_bank`, `credit_amount`, `first_payment`, `transfer_amount`, `term`, `monthly_payment`, `loan_number`, `expected_overdue_at`.

### 2.3 Webhook (READ, secondary — `doc.md` §8)
- Optional per-order `webhookURL` **plus** a static address in the cabinet (both must exist); **domain must equal the shop's registered domain; HTTPS only** (also in help solve-problems).
- Events: `Approved`, `Rejected` (**sent per bank** — a `Rejected` webhook is not a final refusal), `Canceled`, `Signed`, and `Signed` with `commit_cooldown.has_happened=true` (cooling over). With cooling the `Signed` webhook comes first, then a second after the timer; if auto-commit is on the second fires only if the buyer did not cancel.
- **Authentication = no signature.** The bank says to (a) re-query `Info` and (b) check the source IP: network **`91.194.226.0/23`** (hosts .226.1–.227.254). Retries: **not documented** in what I could read (UNVERIFIED).
- Payload = the Info body, `id` = our `orderNumber`.

### 2.4 Cooling period and auto-commit (READ, help `lend/online-application/`, `theory`)
Loans < 50 000 ₽: no cooling. 50 000–199 999 ₽: **4 h**; ≥ 200 000 ₽: **48 h** (some banks slightly longer; the timer is authoritative). Not shortenable ("установлены законом"). Auto-commit is a cabinet switch for all shops of the company; orders < 50 000 ₽ commit on signing, larger ones after the timer. Do not ship goods while the timer runs (the buyer may cancel through the bank).

### 2.5 Limits and field rules (READ, help `solve-problems/`)
- `sum`: > 0 and ≤ **1 000 000**, ≤ 2 decimals; **Σ items.price must equal `sum`** (the page says price; the reference says price is "цена одной единицы" so effectively Σ price×qty — ambiguity noted).
- Credit/instalment window **3 000 – 500 000 ₽** (instalment: after the instalment discount); credit above 500 000 ₽ still possible with the excess taken as a down payment — requires the cabinet checkbox «Принимать первоначальный взнос» (applies to both products). Error «На этом сайте недоступно внесение первоначального взноса» otherwise.
- Lengths: `shopId`/`showcaseId` ≤ 50; `orderNumber` ≤ 64 and unique; `promoCode` ≤ 256 (help) vs ≤ 64 (reference) — discrepancy; `items.name` ≤ 256 (help) vs 255 (reference); `vendorCode` ≤ 64; `category` ≤ 256.
- `promoCode` identifies the product (e.g. `0_0_6` = 0/0/6 instalment); a wrong code → "не найдено доступных кредитных продуктов"; recommended fallback: enable the base product (`default`, credit 3–24 months).
- Wrong `shopId/showcaseId` pair → "не найдены настройки для комбинации" (typical: test IDs left in production).

### 2.6 Button / script (UNVERIFIED — page unreachable)
The bank offers a JS button («Оформить в рассрочку» / «Купить в кредит») for a product card or cart; size and text changeable, **not styleable** (help `how-to-integrate/button/`, READ). Parameter page (WebSearch snippet only): `promoCode` default `"default"`, `promo-code-select` overrides it. The button route needs no order sync and issues no auto-receipt (theory page). v1 does not use it: it only prints a text informer (`includes/product.php:19-27`; `add_button()` is commented out at line 11).

### 2.7 v1 endpoint audit — "endpoint v1 calls → still documented? changed? replaced by?"

| v1 call (file:line) | Documented today? | Changed? | Replaced by / action |
|---|---|---|---|
| `POST https://forma.tinkoff.ru/api/partners/v2/orders/create` (`api/class-wc-tinkoff-api.php:18`, `api/class-wc-api-request.php:12`) | Yes, `v2/orders/create` | **Host moved** `forma.tinkoff.ru` → `forma.tbank.ru`. Old host still resolves (same IP, MEASURED) and the bank's help page still links old-domain docs; **retirement date: not found** (UNVERIFIED). | Use `forma.tbank.ru`; keep the old host out of v2. |
| `POST …/orders/create-demo` (`class-wc-api-request.php:14`) | Yes | none | keep |
| `GET …/orders/{n}/info` with Basic `demo-<showcase>:<pwd>` (`class-wc-tinkoff-api.php:146-155`, `class-wc-api-request.php:22`) | Yes | none | keep; `{n}` must be the **orderNumber** (see v1 defects) |
| `Commit` | Yes | **v1 never calls it** | v2: needed when auto-commit is off |
| `Cancel` | Yes | **v1 never calls it** | v2: cancel an unsigned application when the WC order is cancelled |
| Webhook handler (`class-gateway.php:47-72`, `api/class-wc-api-ipn-response.php`) | Yes | no IP check; relies on `Info` (which is what the bank requires) | add the `91.194.226.0/23` check as defence in depth |
| Refund | **No API exists** | n/a | manual process only |

### 2.8 v1 defects visible while reading (for the spec, not for this report to fix)
All READ in `plugins-reference/woodev-vkredit/`:
1. **First/last name swapped**: `'lastName' => billing_first_name`, `'firstName' => billing_last_name` (`api/class-wc-tinkoff-api.php:50-51`). The form is pre-filled with the wrong way round.
2. **Webhook is registered only in production**: `$this->transaction_response_handler_url` is set inside `if ( $this->is_production_environment() )` (`class-gateway.php:47-54`), so demo orders get `webhookURL = ''/null` and test mode never exercises the callback.
3. **Order lookup by `id` as a WC order ID**: webhook `id` is the `orderNumber` we sent (`$order->get_order_number()`, `class-wc-tinkoff-api.php:41`), but the handler does `wc_get_order( $this->response->id )` (`class-wc-api-ipn-response.php:60-64`) and `get_info( $this->get_order_id() )` (line 159). With any sequential-order-number plugin (very common in RU shops) the order is not found / the wrong order is hit.
4. **Only a lower amount bound** (3 000 ₽, `class-gateway.php:189`, `product.php:21,35`); no 500 000 ₽ / 1 000 000 ₽ handling, no down-payment awareness.
5. Gateway claims `currencies = EUR, RUB, USD` (`class-gateway.php:42`); the service is RUB (UNVERIFIED for foreign currency — nothing in the docs mentions it).
6. Informer divides by a hard-coded `19` (`product.php:23`, field help text `class-gateway.php:252`) — a made-up "minimum payment", not a bank figure.
7. `demoFlow` options `appointment`, `reject`, `appointment-reject` (`class-gateway.php:155-160`) vs the Go client enum `sms|bank|ses`; `initialStage` options (`:170-176`) appear in none of the sources I could read — see Q3.

## 3. Related T-Bank products merchants may confuse (READ)

- **«Долями» (BNPL, "Dolyame")** — pay in 4 parts every 2 weeks, **no credit agreement**, merchant gets 100 % next day, fixed-percent commission (terms page). **Different service and different API** (`dolyame.ru/partners`, mutual-TLS client certificate; the official WP.org plugin `dolyame-payment` says so). Contract is separate, but the *same head's cabinet* offers both when adding a shop ("Выберите Рассрочку … и дополнительно Долями", online-store help page) — so a merchant may expect one plugin to cover both. Scope: **out of this plugin** — a separate gateway; candidate for its own plugin (issue #639 debates scope; not re-read here). Official WP.org module exists: v3.5.0, updated 25.08.2026, 600+ active installs, 0 ratings, WooCommerce ≥ 3.7 (MEASURED via `api.wordpress.org`).
- **Т-Касса / internet acquiring (card payments, T-Pay, SBP)** — API at `securepay.tinkoff.ru/v2` (token-signed requests, per WebSearch snippet on an unrelated PHP client; UNVERIFIED for current host). Different contract and product. Tangents: Т-Банк can add «Рассрочка» **inside the acquiring payment form** ("интеграция через эквайринг … обратитесь к персональному менеджеру", help hub) — this is a manager-driven route that does not use our plugin at all; and the bank lists **no** official WooCommerce module for acquiring (WebSearch: none found). Scope: **out**; it would be a payment gateway plugin, a different category.
- **Paylate** (partner bank, credits 3 000–100 000 ₽ with ≥10 % down payment) is only a lender inside the same form; no separate integration.

## 4. Market

- **Official T-Bank WooCommerce module (MEASURED, `tbank.ru/business/loans/integration/cms/WooCommerce/`, 26.10.2026):** the bank's own CMS-modules page for "Wordpress / WooCommerce" lists exactly one module — **developer «WooDev» (that is us)**, "Для работы модуля требуется версия WooCommerce 3.9 и выше", "Модуль платный — узнать цену и оплатить можно на сайте разработчика", button «Скачать модуль» → `woodev.ru/downloads/tinkoff-kupi-v-kredit/`. Other CMS listed in the hub: Bitrix, GetCourse, Tilda. **So the plugin is the bank's de-facto official WooCommerce integration; there is no first-party free module.**
- **Our listing (MEASURED, `woodev.ru/downloads/tinkoff-kupi-v-kredit`):** 4 000 ₽ / 1 site, 7 000 ₽ / 2, 15 000 ₽ / 5 (1 year of updates + support); rating 5, **800+ installs**, version 1.7.0; the page header says "Обновлён 1 июня 2026" while the changelog dates 1.7.0 at 18.10.2025 (inconsistent, cosmetic). Badge «Верифицирован командой Т-Банк». Feature list: hosted form redirect, webhook status sync, credit parameters in order card, product-page informer, several promo codes selectable at checkout, line-item/shipping/fee/tax breakdown, cooling period, demo scenarios, HPOS.
- **Third-party / free:**
  - `tinkoff-credit` ("Tinkoff Credit for WooCommerce" by "Maxim", button-only, no order sync, orders not shown in WC): **closed on WordPress.org 14.04.2022 (author request), permanent** (MEASURED via `api.wordpress.org`). Still referenced in 2017–2018 Habr threads; a risk that merchants or freelancers still install a copy.
  - WP.org search for `tinkoff`, `tbank`, `т-банк`, `рассрочка` returned **no active T-Bank instalment plugin**; the only T-Bank-family result is `dolyame-payment` (different product) and an abandoned «Безопасные переводы Тинькофф» (secure-deal marketplace payouts, v1.0.1, 10.2022, 40 installs).
  - Paid OpenCart module «Т-Банк кредитование и рассрочка API» exists on opencart.club (not WooCommerce; shows the market for an API-based module in other CMS).
  - Freelance integrators sell "T-Bank payment integration" for 5 000–15 000 ₽ (blog estimates, not product prices).
- **What v2 must beat** (from the above): (a) nobody competes today, so the real competitors are *button-only snippets* and *manual link/QR sales*; the winning pitch is the receipt automation + order sync of the API route; (b) fix the defects in §2.8 (names, test-mode webhook, sequential order numbers, >500 000 ₽ / down payment); (c) Commit/Cancel handling and a refund instruction; (d) a product-page button/informer using real bank figures instead of ÷19; (e) block-checkout support (not assessed here).
- **Merchant complaints: none found** in independent sources (searches in Russian for the plugin name, "Т-Банк рассрочка WooCommerce отзывы"); the only reviews are the vendor's own rating. Bank-side pain points are visible in the bank's own troubleshooting page (§2.5): wrong shop/showcase pair, wrong `promoCode`, duplicate `orderNumber`, non-HTTPS/domain mismatch, down-payment switch.

## 5. Open questions for the operator (only a real merchant account settles them)

1. **Domain deadline:** is `forma.tinkoff.ru` still accepted for `create`/`info`, and is there a published switch-off date? (Docs unreachable here; no deadline found.) Safe default: v2 uses `forma.tbank.ru`.
2. **Is `Create` really unauthenticated, and does `Info` on a *production* order need `showcaseId` or `shopId` as the Basic-Auth username?** v1 and the Go client use `showcaseId`; the docs text also lists a 401 on `Create`.
3. **Demo scenarios:** which `demoFlow` values does `create-demo` accept today — `sms|bank|ses` (Go client) or `sms|appointment|reject|appointment-reject` (v1)? And where do v1's `initialStage` values (`filling`, `wait-offer`, `kvk-issued`, …) come from? A demo account would settle both in one run; it also decides whether the v2 test mode can cover "rejected" and "cancelled" paths.
4. **Webhook retries and timeout:** how many retries, what interval, what HTTP response counts as delivered? (Not in the secondary transcription.) Determines the idempotency and "stuck order" design.
5. **Does the cabinet's static webhook URL have to match the per-order `webhookURL` exactly?** (`doc.md` says both must be set.) Affects the settings page: do we ask the merchant to paste a URL?
6. **`Σ items.price == sum` rule with WooCommerce rounding/fees/tax/coupons:** the bank rejects mismatches ("недопустимое значение суммы"). A real run with a discounted, taxed, multi-quantity cart is needed; v1 sends a separate "tax" line and "shipping" line.
7. **Down payment and > 500 000 ₽:** does the merchant want to allow it? (cabinet checkbox, applies to credits and instalments together) — and should the plugin hide the method above a configurable maximum?
8. **Auto-commit on by default?** The help says it is enabled for all shops; if so `Commit` is rarely needed and Cancel after signing is impossible. Confirm on the real cabinet, because it changes whether v2 ships Commit/Cancel UI at all.
9. **Is `credit_amount`/`monthly_payment` ever known before signing** (to show a real figure on the product card)? The button/script page may expose a calculator; I could not read it.
10. **Dolyame:** does the operator want it as a sibling plugin later? (Out of this plugin; same cabinet, different API.)

---
Skipped / not checked: the primary API reference and button/script pages on `forma.tbank.ru` (host unreachable from this Mac — all method/field tables come from a third-party transcription plus the bank's help pages); webhook retry policy and `Create` auth; block-checkout behaviour; no real shop account, so nothing about production behaviour is MEASURED. Risk: if the live reference differs from the transcription, §2.1–§2.3 need a re-read before the spec is frozen. I used `curl -k` for read-only GETs of `www.tbank.ru` help pages (no credentials sent); the Russian Trusted Root CA is not in this Mac's trust store.
