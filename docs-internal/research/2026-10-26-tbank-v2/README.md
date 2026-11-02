# T-Bank Instalments and Loans on framework v2 — consolidated fact base

> **Wave 0, 26.10.2026.** Three reports feed this file: [a-v1-inventory.md](a-v1-inventory.md) (A), [b-api-market.md](b-api-market.md)
> (B), [c-framework-map.md](c-framework-map.md) (C). It is the single place to read what v1 does, what the bank offers today, what
> framework v2 gives a payment plugin, where the reports disagree and how that was resolved, and what is still open. The
> operator-facing walk-through built on it is [brainstorm-agenda.ru.md](brainstorm-agenda.ru.md) (Russian). Where the coordinator
> spot-checked a claim against source, the check is marked **CHECKED** below.

**Context.** The next plugin to move to framework v2 is «Т-Банк — Рассрочки и кредиты» (`woodev-vkredit`, v1 **1.7.0**, sold on
woodev.ru via EDD). The v1 source exists only under `plugins-reference/woodev-vkredit/` (no GitHub repo yet); its bundled `woodev/`
is the OLD framework 1.3.3. The trigger is framework issue #1389 (live ticket 17079, 23.10.2026): a shop with CDEK v2 + Russian Post
v1 + T-Bank v1 gets the «outdated plugin» notice on both v1 plugins, and for T-Bank there is no v2 to update to — a shop that has
T-Bank cannot move to the v2 carriers. Payment-module scope debate: #639.

**Conventions.** **READ** = from code or an official page, nothing executed. **MEASURED** = a probe or a grep/count was run.
**CHECKED** = re-read by the coordinator in this synthesis. **UNVERIFIED** = not established (reason given). Credentials and account
ids are never copied. All paths are relative to `plugins-reference/woodev-vkredit/` (v1) or the framework repo root.

**Reading condition that colours everything about the bank API:** `forma.tbank.ru` / `forma.tinkoff.ru` (the API docs host) did not
answer from this Mac (DNS ok, TCP timed out — MEASURED by B). Every method/field/status table below comes from a **third-party
transcription** of the two official reference pages (`github.com/alewon/tbank-installment-go-client`, `doc.md`) plus the bank's own
help pages on `tbank.ru`. Nothing about bank behaviour is MEASURED against a real account.

## Reports

| File | Task | One line |
|---|---|---|
| [a-v1-inventory.md](a-v1-inventory.md) | A | v1 1.7.0 feature map, installed-site contracts, code red flags, Blocks status, candidate fate per surface (READ) |
| [b-api-market.md](b-api-market.md) | B | bank API today (secondary transcription + help pages), v1 endpoint audit, related products, market, 10 open questions |
| [c-framework-map.md](c-framework-map.md) | C | framework v2 payment-gateway capability map, Blocks gap, mixed-fleet mechanism, gap list G1–G8 |
| [e-d5-cancellation-history.md](e-d5-cancellation-history.md) | E | D5: anonymised FreeScout history 2023–2026 of T-Bank cancellation problems — three patterns |

## 1. Summary

1. **v1 is a small, hosted, redirect-style gateway.** One class `WC_Gateway_Tinkoff_KVK extends Woodev_Payment_Gateway_Hosted`, gateway id
   `wc_tinkoff_kvk`, two framework features out of 15, payment type `loans_transfer`, ~5 own PHP files. It creates an application
   at the bank, redirects the buyer to the bank's form, and learns the result from a webhook plus an authenticated `Info` re-fetch.
2. **It is the bank's de-facto official WooCommerce module.** The bank's CMS-modules page lists exactly one WooCommerce module — ours,
   paid, linking to woodev.ru (B, MEASURED 26.10.2026). 800+ installs, 4 000 / 7 000 / 15 000 ₽ for 1 / 2 / 5 sites. No competing
   maintained plugin exists; the real competitors are button-only snippets and manual links.
3. **The API v1 uses is still the documented one**, but the host moved `forma.tinkoff.ru` → `forma.tbank.ru`. Retirement date of the
   old host: not found. v2 should use the new host.
4. **v1 has six concrete defects to fix** (§5), two of them visible to every buyer or merchant: first/last name swapped in the bank
   form; and the webhook is looked up by WooCommerce order id instead of the order number sent (breaks with any sequential-order-number plugin).
5. **The bank has no refund API.** `Commit` and `Cancel` exist and v1 calls neither. Refund is a manual bank transfer by the merchant.
6. **Framework v2 gives the engine but no Blocks payment bridge.** The hosted flow, response handling, HPOS, log masking, licensing,
   loader and wizard engine are there. No `AbstractPaymentMethodType` integration exists anywhere in the framework (G1). v1 has none either.
7. **No generic "payment settings on `woodev-settings`" adapter exists** (G3): shipping plugins register settings providers, no payment
   gateway has.
8. **#639 does not get new evidence from this port**: T-Bank v2 is the same gateway type (Hosted, loans). The entry condition
   (2–3 payment plugins of *different* types) is not met.

## 2. What v1 does (A, READ; CHECKED where marked)

| Surface | v1 behaviour |
|---|---|
| Identity | plugin/gateway id `wc_tinkoff_kvk`; `Woodev_TCB extends Woodev_Payment_Gateway_Plugin`; `register_plugin('1.3.3', …, 'backwards_compatible' => '1.2.1')`; HPOS declared; `countries = RU`, `currencies = EUR, RUB, USD` (`woodev-vkredit.php:28-33,53-79`; `includes/class-gateway.php:24-45`) **CHECKED** |
| Checkout | Woo creates the order, then POST `…/api/partners/v2/orders/create` (production) or `/create-demo` (test mode) → browser redirected to the bank's form URL (`includes/api/class-wc-tinkoff-api.php:18-57`). No iframe |
| Payload | `shopId`, `showcaseId`, `sum`, line items + shipping + fees + tax lines, `orderNumber = $order->get_order_number()`, billing contact, `failURL` (order pay URL), `successURL` (order received), `returnURL` (cancel URL), `webhookURL`, optional `promoCode` |
| Availability | hidden below 3 000 ₽ order total, and **hidden when the licence is not valid** (`class-gateway.php:183-196`) **CHECKED**. A dead-looking branch hides it on the `woocommerce_update_order_review` admin-ajax action (Woo's checkout refresh sends `wc-ajax=`, not `action=`, so this is probably never true on current Woo — UNVERIFIED at runtime) |
| Webhook | production only: `?wc-api=wc_gateway_tinkoff_kvk_ipn_<md5(json_encode([shop_id, showcase_id]))>` on `home_url('/')` (`class-gateway.php:47-72`) **CHECKED**. Body read from `php://input`. No signature check: required fields and amount are compared, and the status comes from an authenticated `GET …/{orderNumber}/info` (`api/class-wc-api-ipn-response.php:150-165,268-299`) |
| Status mapping | `signed` → paid (or `on-hold` while the optional «cooling period» is on and not over); `approved` → `on-hold`; `canceled`/`rejected` → cancelled; anything else → failure. Gateway allows paying an `on-hold` order (`class-gateway.php:74,89-95`) |
| Order card | shows seven keys: `status_message`, `first_payment`, `credit_amount`, `credit_product`, `credit_term`, `monthly_payment`, `signing_type` (`woodev-vkredit.php:118-159`). **MEASURED (grep):** only `credit_amount` and `first_payment` have a writer (`woodev/payment-gateway/class-payment-gateway-hosted.php:444-445`, `handlers/abstract-hosted-payment-handler.php:293-294`); no writer found for `credit_product`, `credit_term`, `monthly_payment`, `signing_type`, `status_message`. So most of the card's rows are probably always empty on real orders (runtime UNVERIFIED) |
| Product page | `includes/product.php`: a text notice at `woocommerce_single_product_summary` priority 25 when price ≥ 3 000: «payment ≈ price ÷ 19» (hard-coded, filterable). The JS button method exists but its hook is commented out (`product.php:11`). No cart widget |
| Promo codes | settings rows `{name, code, enabled}`; one enabled row → hidden input, several → radio buttons at checkout; chosen code goes to the bank as `promoCode` (`class-gateway.php:101-143`; `class-wc-tinkoff-api.php:59-62`) |
| Test mode | `/create-demo`; Basic-Auth username `demo-<showcaseId>` for info lookup; admins pick `demoFlow` and `initialStage` (`class-gateway.php:145-178`) |
| Refund / cancel / commit | gateway declares neither refunds nor voids; the API wrapper has only `create` and `get_info` |
| Admin | WooCommerce → Payments → this gateway: shop id, showcase id, API password (plain text input), cooling toggle, button label, promo rows, informer. No own admin page, REST route, AJAX action or cron (MEASURED by A) |
| Blocks | none. Only `supported_features => ['hpos' => true]`; the bundled framework requires an explicit `blocks.checkout === true` |
| Lifecycle | custom upgrade at 1.4.0 moved the legacy licence key `kupi_v_kredit_ot_tinkoff_license_key` into the framework licence option |

### 2.1 Installed-site contracts (A)

| Contract | Value | Status |
|---|---|---|
| Gateway / payment method id | `wc_tinkoff_kvk` — stored on every old order, so it **must not change** | READ |
| Settings option | `woocommerce_wc_tinkoff_kvk_settings` (keys `shop_id`, `showcase_id`, `password`, `environment`, `enable_cooling`, `order_button_text`, `promo_codes[]`, `single_page_informer`, + standard gateway fields) | READ; live options table UNVERIFIED |
| Licence / framework options | prefix `woodev_wc_tinkoff_kvk_…` | READ; full list not enumerated |
| Order meta | prefix `_wc_wc_tinkoff_kvk_` (note the doubled `wc`): `trans_id`, `trans_date`, `environment`, `promo_code`, `credit_amount`, `first_payment`; plus `_transaction_id` | READ |
| Webhook URL | built per shop from the credentials, sent to the bank **per order** as `webhookURL` | READ |
| Public hooks | `wc_tinkoff_order_fields_for_display_data`, `wc_tinkoff_api_create_request_params`, `wc_tinkoff_ipn_require_response_data`, `wc_gateway_wc_tinkoff_kvk_is_available`, `wc_payment_gateway_wc_tinkoff_kvk_form_fields`, and the typo-spelled `wc_geteway_tinkoff_kvk_loans_rate` / `wc_geteway_tinkoff_kvk_product_info` | READ; who uses them: UNVERIFIED |
| Product meta, transients, cron | none owned by the plugin | MEASURED |

## 3. What the bank offers today (B)

- **Product naming.** The merchant-facing name is now «Рассрочка в Т-Банке» (API docs: «T-Рассрочка» / TCB); older names merchants
  still use: «Кредиты онлайн», «Купи в кредит», «Тинькофф Кредит». It is a **consumer-credit broker**: one form, several partner banks
  (T-Bank, OTP Bank, MTS Bank), the buyer picks an approved offer and signs by SMS / Self-ID / in person.
- **Commercials (READ, bank help pages).** Connection is free; instalments carry a discount (example: 10 000 ₽ for 3 months at 6 % →
  merchant receives 9 400 ₽). Payout same day if the settlement account is at T-Bank, otherwise next business day. Buyer: 21–65 years,
  ≥ 3 months official work, `+79…` phone. **Fiscal receipt is issued by the service automatically only with the API integration.**
- **Methods (READ, secondary):** `Create` and `CreateDemo` (`POST /api/partners/v2/orders/create[-demo]`, idempotent per `orderNumber`+cart),
  `Commit` (confirms a signed order → payout), `Cancel` (irreversible; impossible after signing when auto-commit is on), `Info`
  (`GET …/{orderNumber}/info`), and a merchant webhook. Rate limit **25 req/s**, then 429 + `Retry-After`.
- **Statuses.** `new`, `inprogress`, `approved`, `signed`, `canceled` (by buyer), `rejected` (**all** banks refused). Flags: `committed`,
  `demo`, `commit_cooldown{until,has_happened}`, `product` (`credit|installment_credit`), `signing_type`, `chosen_bank`, `credit_amount`,
  `first_payment`, `transfer_amount`, `term`, `monthly_payment`, `loan_number`.
- **Webhook.** Optional per-order `webhookURL` **plus** a static address in the cabinet; the domain must equal the shop's registered
  domain, HTTPS only. Events: `Approved`, `Rejected` (sent **per bank** — not a final refusal), `Canceled`, `Signed`, and a second
  `Signed` when the cooling timer ends. **No signature.** The bank tells you to re-query `Info` and check the source network
  `91.194.226.0/23`. Retry policy: not documented in what B could read.
- **Cooling period and auto-commit.** Loans < 50 000 ₽: none. 50 000–199 999 ₽: 4 h. ≥ 200 000 ₽: 48 h. Set by law, not shortenable.
  Auto-commit is a cabinet switch for all shops of the company (said to be on by default — UNVERIFIED).
- **Limits.** `sum` > 0 and ≤ 1 000 000 ₽, ≤ 2 decimals, Σ item prices must equal `sum`; window 3 000–500 000 ₽ (above that only with
  the cabinet checkbox «Принимать первоначальный взнос»); `orderNumber` ≤ 64 chars, unique and mandatory in practice.
- **Demo.** `create-demo`; test orders never reach the bank; accepted `demoFlow` values differ by source (open question Q3).
- **Button / script (UNVERIFIED — page unreachable):** the bank offers a JS button for a product card or cart, not styleable; needs no
  order sync and issues no automatic receipt.
- **Neighbouring products.** «Долями» (BNPL, 4 payments, **no credit agreement**) has a **different API** (mutual-TLS client
  certificate) and an official free WP.org plugin (`dolyame-payment` 3.5.0, 25.08.2026, 600+ installs). «Т-Касса» (card acquiring) is a
  different contract and API. Both are out of this plugin. Same head's cabinet offers both, so a merchant may expect one plugin.

## 4. What framework v2 gives a payment plugin (C)

| Capability | Seam | Gap? |
|---|---|---|
| Plugin bootstrap | `Woodev_Loader::register()` + definition; payment subclass `Woodev_Payment_Gateway_Plugin` (`woodev/payment-gateway/class-payment-gateway-plugin.php:32,96-110`) | no payment-specific v2 example plugin in the repo |
| Gateway registration | gateway-id → class map, `woocommerce_payment_gateways` filter | none |
| Hosted redirect | `Woodev_Payment_Gateway_Hosted::process_payment()` / `get_payment_url()` (`class-payment-gateway-hosted.php:78-136`) | none |
| Return + webhook | WC-API action handler (`handlers/abstract-hosted-payment-handler.php:20-152`); the plugin REST class is system-status only | no generic signature/REST webhook seam; WC-API is enough for this protocol |
| Status machine | duplicate/fraud validation, `payment_complete()`, held/failure transitions (`handlers/abstract-payment-handler.php`) | callback-level idempotency partly adapter-defined |
| Refund / void | `process_refund()` and a void path in `class-payment-gateway.php:1548-1845` | **moot for T-Bank: no bank refund API** (B); do not declare the feature |
| Log masking | `woodev/api/class-api-base.php:2497-2654`; override `get_secret_header_names()` | none — but v1's raw IPN `to_string_safe()` must not be ported |
| Licensing | `Woodev_Plugin` / `Woodev_Lifecycle`; `is_license_valid()` (`woodev/licensing/class-plugin-license.php:1060`) | what a payment gateway does at checkout when the licence lapses is a product decision (agenda) |
| Setup wizard | engine + contract `docs-internal/specs/2026-10-08-setup-wizard-v2-contract.md` | none |
| HPOS | loader definition `supported_features`; `WC_Order` APIs | none; verify the callback lookup |
| **Cart/Checkout Blocks for payments** | **none** (no `AbstractPaymentMethodType`, no `registerPaymentMethod` anywhere under `woodev/` or `src/`) | **G1**. Woo's guide: server class on `woocommerce_blocks_payment_method_type_registration` + client `registerPaymentMethod`; legacy `process_payment()` is still called from the Checkout Block |
| **Payment settings on `woodev-settings`** | `Settings_Page_Registry` is wired for plugins (`woodev/class-plugin.php:313-321`); CDEK/Yandex v2 register shipping providers (`woodev/shipping-method/class-shipping-plugin.php:1362-1392`) | **G3**: no payment provider adapter; Woo's own gateway settings screen stays the default |

Mixed-fleet mechanism (C, READ; matches issue #1389): the v2 `Woodev_Plugin_Bootstrap` keeps a tombstone for the v1 `register_plugin()`
call (`woodev/bootstrap.php:100-141`) — the v1 plugin is recorded as incompatible and its init callback is never run; in the reverse
load order the v2 loader finds no `register_loader_definition()` and leaves the v2 plugin dormant. Load order decides which side is
disabled. The «update to the latest version» copy is impossible to follow for T-Bank until a v2 exists.

## 5. v1 defects (A + B; all READ unless marked)

| # | Defect | Evidence | Severity |
|---|---|---|---|
| D1 | **First/last name swapped** in the bank form (`lastName` ← billing first name, `firstName` ← billing last name) | `api/class-wc-tinkoff-api.php:50-51` **CHECKED** | every buyer sees a wrong pre-fill |
| D2 | **Webhook order lookup uses the payload `id` as a Woo order id** (`wc_get_order($this->response->id)`; `get_info($this->get_order_id())`), while the id the bank echoes is the `orderNumber` we sent (`get_order_number()`) | `class-wc-api-ipn-response.php:44-65,150-165` **CHECKED**; `class-wc-tinkoff-api.php:42` | breaks with sequential-order-number plugins (common in RU shops) |
| D3 | **Webhook URL exists only in production** (`if is_production_environment()` around the whole registration), so demo orders get an empty `webhookURL` and test mode never exercises the callback | `class-gateway.php:47-72` **CHECKED** | test mode cannot be a rehearsal |
| D4 | Promo name is printed raw into checkout label HTML; the informer setting is echoed unescaped | `class-gateway.php:133-135`; `product.php:21-27` | stored markup injection by an admin account (low probability, cheap to fix) |
| D5 | `credit_amount` / `first_payment` cast with `intval` (1234.99 → 1234) | `class-wc-api-ipn-response.php:194-213` **CHECKED** | display only |
| D6 | Raw IPN body returned by `to_string_safe()` into the debug log | `class-wc-api-ipn-response.php:302-308` | PII in logs when debug is on |
| D7 | Only a lower amount bound (3 000 ₽); nothing for > 500 000 ₽ / 1 000 000 ₽ or the down-payment switch | `class-gateway.php:189` **CHECKED** | bank error at the form instead of a hidden method |
| D8 | Informer divides by a hard-coded `19` — not a bank figure; division by zero if the filter returns 0 | `product.php:21-25` | misleading sales copy |
| D9 | Gateway declares `currencies = EUR, RUB, USD` with `countries = RU`; the service is RUB | `class-gateway.php:42` **CHECKED** | UNVERIFIED for foreign currency |
| D10 | Four of the seven order-card rows have no writer (§2) | grep | rows always empty |
| D11 | No webhook source check; the bank publishes a network (`91.194.226.0/23`) | B §2.3 | defence in depth only — `Info` re-fetch already authenticates the status |
| D12 | `Σ items = sum` is the bank's rule; a Woo cart with coupons, tax and fees may not satisfy it; v1 sends separate tax and shipping lines | B Q6 | UNVERIFIED — needs a real run |

## 6. Contradictions between reports and how they were resolved

| # | Contradiction | Resolution |
|---|---|---|
| 1 | **C's table speaks of "T-Bank signature verification"**; A and B say the callback has **no** signature. | A (code) and B (bank docs transcription) win: authentication = re-query `Info` + source-IP network check. The plugin must **not** invent a signature scheme. Wording in C's row is wrong. |
| 2 | **Refund/void:** C marks it UNVERIFIED ("if the API permits"); A says v1 has none; B says the API has none. | Resolved by B: no refund API; `Cancel` exists but only before signing (and not at all after signing with auto-commit); `Commit` exists. `FEATURE_REFUNDS` is not declared. |
| 3 | **Callback race:** A flags that a late `signed` after a cancel is dropped as a duplicate and the order is never recovered; B says the `Rejected` webhook fires **per bank** and is not final. | Both true, but they do not compound in v1: v1 never trusts the webhook status, it re-fetches `Info`, where `rejected` means *all* banks refused. So a per-bank `Rejected` webhook does not wrongly cancel an order in v1. The out-of-order duplicate-drop risk stays real but un-measured — design the v2 handler to be idempotent and re-read `Info`. |
| 4 | **Webhook registration:** A says the callback URL is fixed per cabinet (UNVERIFIED); B (secondary doc) says a per-order `webhookURL` **and** a static cabinet address are both required. | Not resolvable without a real cabinet → open question Q2. Safe design: keep the existing derived route answering AND send it per order. |
| 5 | **Blocks:** C: "T-Bank v1 advertises/uses classic checkout"; A: v1 declares only HPOS and has no Blocks integration, so the method is not offered on a Checkout Block page. | Consistent once read together: v1 never worked on Blocks. All 800+ installs therefore run the classic checkout. Whether *new* shops default to the block checkout is not checked here (my recollection that new WooCommerce installs default to it is UNVERIFIED). |
| 6 | **File counts:** C measured 86 files / 14 167 lines in `woodev/payment-gateway/`; #639 says 35 PHP files / 13 882 lines. | Different scope and date (C counts assets and docs). Not a conflict. |
| 7 | **Order-card fields:** A: "no writes to all seven display keys were found" (UNVERIFIED); coordinator grep: two have writers, five do not. | Coordinator grep wins (§2). |
| 8 | **Promo length:** bank help says ≤ 256, reference says ≤ 64 (B). | Unresolved; v2 should clamp at 64 (the stricter). |
| 9 | **ShowcaseId meaning:** cabinet help says one per legal entity, the troubleshooting page says per shop (B). | Unresolved in the bank's own text; the plugin asks for both ids and does not interpret them. |

## 7. Gap list (framework + plugin)

| ID | Gap | Source | Note |
|---|---|---|---|
| G1 | Payment Blocks integration (server class, JS registration, assets, tests) | C | framework work; reusable by every future payment plugin; v1 never had it |
| G2 | The T-Bank v2 port itself — the only thing that closes #1389's dead end | C | |
| G3 | No payment-gateway adapter for the React `woodev-settings` page | C | decision needed where credentials live |
| G4 | No v1→v2 option migration helper for a payment gateway | C | trivial if the option key and meta keys stay as they are |
| G5 | Callback idempotency, `Commit`/`Cancel` semantics, `Info` re-fetch cost | C+B | needs the real cabinet |
| G6 | HPOS-safe order lookup by `orderNumber` (fixes D2) | A+B | |
| G7 | Secret names (`Authorization` basic password) and body fields for the log mask; i18n catalogue for storefront strings | C | |
| G8 | #639 pruning / #117 trait extraction | C | stays held |

## 8. Open questions (only a real merchant account or a Russian IP settles them)

| # | Question | Why it matters |
|---|---|---|
| Q1 | Is `forma.tinkoff.ru` still accepted for `create` and `info`; is there a switch-off date? | v2 will use `forma.tbank.ru` anyway; matters for the update notes |
| Q2 | Must the cabinet's static webhook URL match the per-order `webhookURL`? Does a merchant have to paste a URL into the cabinet? | settings page and wizard content |
| Q3 | Which `demoFlow` / `initialStage` values does `create-demo` accept today? | whether test mode can rehearse rejected/cancelled |
| Q4 | Webhook retries, timeout, what counts as delivered | idempotency and "stuck order" design |
| Q5 | Is `Create` really unauthenticated; which Basic-Auth username does `Info` need on a production order (`showcaseId` vs `shopId`)? | v1 and the Go client use `showcaseId` |
| Q6 | Does a coupon + tax + multi-quantity cart satisfy `Σ items = sum`? | D12 |
| Q7 | Is auto-commit on by default? | decides whether any Commit/Cancel UI is needed |
| Q8 | Are `credit_amount` / `monthly_payment` known before signing (a real figure for the product card)? | product-page widget |
| Q9 | What does the JS button/script page expose (calculator)? | product-page widget |
| Q10 | Who uses the v1 public hooks (incl. the typo-spelled ones)? | whether aliases are needed |

## 9. Worst-case automatic traffic (for the "no spam" rule)

All bank traffic in v1 is event-driven: one `create` per checkout attempt, one `info` per received webhook. No cron, no polling, no
calls to woodev.ru beyond the framework licence/updater (not enumerated here). **v1 worst case = one `info` call per webhook; a
hostile or buggy sender can force one outbound `info` per request** — the `91.194.226.0/23` check and an order-number lookup *before*
the `info` call cut that. v2 must add no polling; if a "stuck order" re-check is ever added it needs a per-order cap and backoff.
(Design constraint, not a measurement.)

---

Skipped / not checked: the primary bank API and button/script pages (host unreachable — all tables are from a third-party transcription
plus help pages), webhook retry policy, `Create` auth, production behaviour of any kind, block-checkout runtime on a real shop,
EDD download slug/id for the new release, who uses the v1 hooks. Risk: if the live reference differs from the transcription, §3
needs a re-read before the spec is frozen.
