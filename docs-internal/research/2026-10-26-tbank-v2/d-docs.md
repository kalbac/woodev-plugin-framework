TBANKDOCS — T-Bank «Рассрочки и кредиты» API: primary-source read of the official documentation (research, 26.10.2026)

Scope: wave-0 task D. Read-only; no API method was called, no code, no commit. Accessed 26.10.2026 (all URLs below fetched that day).
Tags: **READ** = read on the official page named, **UNVERIFIED** = not stated there (reason given).

## 0. How the docs were reached (this closes the b-api-market.md "host unreachable" gap)

- `forma.tbank.ru/docs/online/*` is a **Docusaurus v2.4.0 static site** — every page is server-rendered HTML, no JS bundle needed. From this Mac (MEASURED) `curl -sk -A "<Chrome UA>" https://forma.tbank.ru/docs/online/<path>` returns 200 with full content; TLS verification is off because the Russian Trusted Root CA is missing here. (b-api-market.md measured TCP timeouts for the same host on the morning of 26.10; it answers now, so that was transient or network-dependent.)
- `/docs/api`, `/docs/online/api` → 403 (not pages; the real tree is `/docs/online/…`). `forma.tinkoff.ru/docs/…` → 302 (redirect to the new host). `tbank-online.com/docs/online` → 301. Plain `http://forma.tbank.ru/…` → no connection (000).
- Raw HTML + text dumps are in `/tmp/woodev-tbank-docs/` (outside the repo).
- Pages read (all under `https://forma.tbank.ru/docs/online/`): `` (glossary), `setup-types/api/description-api`, `setup-types/api/auth`, `setup-types/api/sell-start`, `setup-types/api/testing-api`, `setup-types/api/methods`, `settings/app-notifications`, `settings/auto-commit`, `settings/return-links`, `scheme/application` (an SVG diagram — text extracted from it), `scheme/tech-process`, `setup-types/service-setup`, `setup-types/script/parameters`, `setup-types/script/description-script`. Plus the bank's help page `https://www.tbank.ru/business/help/sales/loans/how-to-integrate/solve-problems/` (field limits, error explanations) and `…/how-to-integrate/api/` (cooling thresholds).
- The docs carry **no version number or "last updated" date** (UNVERIFIED — nothing on the pages).

## 1. Which methods need the API password

| Method | Password needed? | Evidence |
|---|---|---|
| `Create` (`POST /api/partners/v2/orders/create`) | **No.** Identified by `shopId` + `showcaseId` in the body only. | `setup-types/api/auth`: «Аутентификация используется только для методов Commit, Cancel и Info. Для методов Create и Create Demo не используется — происходит передача уникальных идентифицирующих параметров shopId и showcaseId.» The Create parameter table has no `Authorization` row (READ). |
| `CreateDemo` (`POST …/orders/create-demo`) | **No.** | same line; `setup-types/api/auth` § Тестирование: «Для создания тестовых заявок аутентификация не используется.» |
| `Info` (`GET …/orders/{orderNumber}/info`) | **Yes** — HTTP Basic. | `methods`: parameter table `Authorization header, required — Basic Auth`. |
| `Commit` (`POST …/{orderNumber}/commit`) | **Yes.** | same. |
| `Cancel` (`POST …/{orderNumber}/cancel`) | **Yes.** | same. |

- Credentials: `setup-types/api/auth`: «showcaseID — логин / пароль API — пароль»; example `mysupershowcase:123456` → base64. Demo orders: «Для методов Info, commit и cancel к showcaseId добавляется приставка demo-» (`demo-mysupershowcase:123456`) — the example uses the **same password**. READ. So even demo `Info` needs the password; whether the *demo* password is literally the live one is implied by the example, not stated outright (UNVERIFIED as a rule).
- The password cannot be read back: «Пароль API посмотреть не получится — мы не храним пароли в открытом виде. Но вы можете задать новый пароль в разделе «Магазины» → «Настройки API».» (`setup-types/api/auth`; `setup-types/api/sell-start`: «Кредитование в магазинах» → магазин → «Настройки API»). READ. Setting a new one needs the head's cabinet login, which the operator does not have.
- Rate limit: ≤ 25 requests/s, then HTTP 429 + `Retry-After` (`methods`). READ.

**What is possible with live `shopId` + `showcaseId` and no password (by the docs):**
- `Create` (real application, goes to the bank — do not use for tests) and `CreateDemo` (test application, "не отправятся в банк") — both password-free. A demo application yields a `link` the operator can open and click through (demo flow, any SMS code).
- Receiving webhooks on a demo order, **if** a webhook address is configured; but the docs say a per-order `webhookURL` still needs the static address in the cabinet (see §2), which needs a cabinet login. UNVERIFIED whether a demo webhook is delivered to a per-order `webhookURL` with an empty cabinet field.
- NOT possible: `Info`, `Commit`, `Cancel` (live or demo) — so no status read-back, no connection check that reads state; only "Create returned `{id, link}`" is checkable. A wizard connection check without the password can therefore only prove `shopId`/`showcaseId`/`promoCode` are accepted by `create-demo`.

## 2. Webhook

| Item | Answer | Source / mark |
|---|---|---|
| Payload | JSON: `id` (= our `orderNumber`, string(64)), `status` (`signed\|canceled\|rejected\|approved`), `created_at`, `demo`, `committed`, `first_payment`, `order_amount`, `credit_amount`, `transfer_amount`, `product`, `term`, `monthly_payment`, `first_name`, `last_name`, `middle_name`, `phone`, `loan_number`, `email`, `appropriate_signing_types[]`, `signing_type`, `chosen_bank`, `expected_overdue_at`, `items[]` (name/quantity/price), `commit_cooldown{until, has_happened}` (optional). Personal data is **masked** ("Клиентская информация в вебхуках в маскированном виде": `"last_name": "И*****"`, `"phone": "+7912*****89"`). | `settings/app-notifications` § Структура. Parameters. READ |
| Authentication | **No signature / secret.** «Обязательно проверяйте подлинность…: Запросите состояние заявки через метод Info API… Проверьте IP-адрес. Уведомления банка приходят с маски сети 91.194.226.00/23. IP-адрес первого хоста — 91.194.226.1 и … последнего хоста — 91.194.227.254, хостов в сети 510». | `settings/app-notifications` § Подлинность. READ |
| Retry policy | Only this: «Ответ формата 2ХХ расценивается как удачно полученный вебхук. Если придет другой ответ — отправим вебхук повторно». **Retry count, intervals, timeout: not documented anywhere I read.** The glossary says only that webhooks «помогают не исчерпать попытки запросов». | `settings/app-notifications` § Ответ; glossary. READ for the quote; counts/intervals **UNVERIFIED (not in the docs)** — contradicts D12's "retry intervals exist — read the webhook section": the section says retries exist, not their schedule. |
| Fallback if no webhook | `Info` «если вебхук не пришел в течение часа после создания заявки» is the bank's own advice. | `methods` § Info. READ |
| Events | `Approved` (per bank), `Rejected` (per bank — «не означает, что по всей заявке пришел отказ»), `Canceled`, `Signed`, and `Signed` with `commit_cooldown.has_happened = true` (cooling ended; with auto-commit it fires only if the buyer did not cancel; a cancel during cooling sends `Canceled`). | `settings/app-notifications` § Вебхуки отправляются на события. READ |
| Statuses that trigger | `approved`, `rejected`, `canceled`, `signed` only. **No webhook status for expiry / `new` / `inprogress`.** | payload `status` enum. READ |
| Where the address comes from | Two ways, and **both are needed for the per-order one**: static address in the cabinet («Кредитование в магазинах» → магазин → «Редактировать» → «Уведомления» → «Адрес для HTTP-нотификаций», with a checkbox) and/or per-order `webhookURL` in Create: «Нужно передавать его при создании заявки в поле webhookURL. При этом в личном кабинете руководителя все равно должен быть настроен статичный адрес.» | READ. Answers README Q2: the cabinet address is mandatory even when `webhookURL` is sent; whether they must be *equal* is not stated (UNVERIFIED). |
| `orderNumber` | Required for webhooks: «Чтобы использовать вебхуки, при создании заявки передайте параметр orderNumber — именно он передается в вебхуках в поле id.» The help page adds that the cabinet's «Уведомления» → «По HTTP» tick must be set. | READ |
| Domain / HTTPS | «Домен уведомлений должен совпадать с доменом вашего сайта… Ваш сайт должен использовать защищенный HTTPS, решения с HTTP-протоколом мы не поддерживаем.» Help page: site link format `https://example.com`, other links start with the same domain (`https://example.com/success`). | `settings/app-notifications`; help `solve-problems` (§ «для ссылки некорректно указан домен»). READ |

## 3. Statuses and lifetime

| Status (Info/Commit/Cancel response) | Meaning (official text) | Terminal? |
|---|---|---|
| `new` | «заявка создана, покупатель еще не подтвердил свои данные кодом из СМС» | intermediate |
| `inprogress` | «покупатель подтвердил свои данные вводом кода из СМС… и находится на шаге ввода данных паспорта либо ожидает одобрения заявки хотя бы от одного банка» | intermediate |
| `approved` | «заявка одобрена хотя бы одним банком» | intermediate (buyer must still sign) |
| `signed` | «заявка подписана покупателем при помощи СМС-кода, Self id или на встрече» | terminal for the buyer; money flows after `committed=true` (see `committed`, cooling) |
| `canceled` | «заявка отменена покупателем» (seller-side Cancel also makes the application inactive: glossary «Отмена») | terminal |
| `rejected` | «по заявке пришел отказ от всех банков» (in Info; the *webhook* `Rejected` is per bank) | terminal |

Terminal/intermediate classification is mine (the docs list the enum without calling any state "final"; the glossary says a cancelled application «становится неактивной»). `methods` (Info/Commit/Cancel tables). READ. The status enum has **no** `expired`/`overdue` value.

Lifetime:
- `expected_overdue_at` — «Дата и время окончания срока действия заявки» (required field of every Info/webhook body). In the docs' own webhook example `created_at 2022-11-10T09:03:48Z` → `expected_overdue_at 2022-11-24T09:04:55Z`, i.e. **+14 days**. The application diagram (`scheme/application`) has the nodes «Прошло 14 дней» after «Выбор банка» and «Если номер не подтвержден за 24 часа заявка удалится» at the SMS step. READ — but the docs **never state in prose** that an unfinished application lives 14 days; the 14-day figure is inferred from the example and the diagram label (treat as READ-indirect). Commit window: «Метод должен быть вызван в течение 14 дней после подписания» (`methods` § Commit). READ.
- Unconfirmed phone: deleted after 24 h (diagram text). READ (diagram).
- **Does the bank send a webhook on expiry? Not documented.** The webhook status enum has no expiry value, and no page says an expiry event exists. UNVERIFIED (absence in the docs). The demo `initialStage` list does include `wait-offer-timeout — истек срок ожидания`, which shows an "offer waiting timed out" state exists in the buyer UI, but its API/webhook effect is not described. This bears on I5a (72 h default) — the bank-side lifetime to design against is `expected_overdue_at` from the Info body, with 14 days as the documented-by-example figure.

## 4. Demo mode

| Question | Answer | Source / mark |
|---|---|---|
| Endpoint | `POST https://forma.tbank.ru/api/partners/v2/orders/create-demo`; «Тестовые заявки не отправятся в банк, но вы сможете посмотреть, как работает заполнение и что увидят ваши покупатели». | `setup-types/api/testing-api`. READ |
| Keys | «Для создания тестовой заявки используйте shopId, showcaseId и promocode, которые получили на этапе подготовки» — **the same live identifiers, no separate demo showcase**. So demo with live keys creates no real application (READ, by the page's own statement; not MEASURED — I called nothing). | READ |
| `demoFlow` (table says `enum, required`) | `sms` (sign by SMS), `ses` (Self ID), `appointment` (sign at a bank meeting), `appointment-reject` (refusal at the meeting), `reject` (refusal). | `testing-api`. READ |
| `initialStage` (optional) | `filling`, `wait-offer`, `offers-all`, `wait-offer-timeout`, `kvk-signing-sms`, `ses-documents`, `appointment`, `mts-add-additional-info`, `mts-documents`, `mts-svyaznoy-appointment`, `otp-signing-sms`, `otp-documents`, `rencredit-signing-sms`, `kvk-issued`, `mts-issued`, `otp-issued`, `canceled`, `rejected`. | `testing-api`. READ |
| Prefix | Only on the auth login for `Info/Commit/Cancel` on demo orders: `demo-<showcaseId>:<password>` (the Create/CreateDemo calls take no auth). Order numbers have no demo prefix in the docs. | `setup-types/api/auth` § Тестирование; `testing-api` § Другие методы. READ |
| How a demo ends | Fill the form, get approval → `Info` `status='approved'`; sign with **any** SMS code → `status='signed'`; refusal → `rejected`. «Также вы можете проверить работу вебхуков.» Response flag `demo: true` is in every body. | `testing-api`; `methods`. READ |
| Cancelled-path rehearsal | `initialStage: canceled` / `rejected` exist, and `reject` / `appointment-reject` flows → so the test mode **can** rehearse rejected and cancelled. | READ (answers README Q3) |

## 5. Create request

| Field | Rule | Source / mark |
|---|---|---|
| Required | `shopId` string(50), `showcaseId` string(50), `sum` number, `items[]`. | `methods` § Create. READ |
| Optional | `orderNumber` string(64) «Обязательный, если используются вебхуки и методы API Commit, Cancel и Info»; `promoCode` string(64) default `default`; `webhookURL`; `successURL`; `failURL`; `returnURL`; `values.contact{fio{lastName,firstName,middleName}, mobilePhone, email}` (RF phone «в любом формате»). | `methods`. READ |
| Item | `name` string(255) req; `quantity` **integer** req; `price` number req — «Цена одной единицы товара»; `category` string(255); `vendorCode` string(64). | `methods` (Item table). READ |
| Help-page limits (differ from the reference — see §8) | `shopId`/`showcaseId` ≤ 50; `promoCode` **≤ 256**; `items.name` **≤ 256**; `items.category` ≤ 256; `items.vendorCode` ≤ 64; `orderNumber` ≤ 64 and «Уникальный для каждого заказа»; `quantity` > 0. | help `…/how-to-integrate/solve-problems/` § «Как правильно заполнять…». READ |
| Precision / ranges | `sum` and `items.price`: «больше нуля, но меньше или равно 1 000 000. Две цифры после запятой или меньше.» Decimal separator is a dot; comma or letters in price → error («18 999,7 → 18 999.7»). | help. READ |
| Sum rule | Help: «Сумма всех items.price должна быть равна значению в поле sum». Reference: `price` = «Цена одной единицы товара» with an integer `quantity`. **The two texts never say whether it is Σprice or Σ(price×quantity).** **UNVERIFIED** (ambiguous in the primary source) — cheapest settling: a `create-demo` call (password-free) with a 2-unit item; and the operator's support experience says the match must be exact to the kopeck (D12). | READ (ambiguity) |
| Sum-mismatch rejection | **The exact error text/code for a sum mismatch is NOT in the docs.** The FAQ heading «Как исправить недопустимое значение суммы заказа?» is explained only as an *amount-limit* problem («итоговая сумма заказа не вписывается в лимит рассрочки или кредита»), plus a tip that a non-numeric price makes the sum unreadable. Generic status codes: 400 invalid format, 422 business-logic. So "недопустимое значение суммы" is the title of a limit/format FAQ, not documented as the mismatch message. UNVERIFIED. |
| `promoCode` semantics | «Идентификатор продукта: кредит или рассрочка. По умолчанию default.» Formed as `installment_a_b_c_d`; base credit code `default` «позволяет оформить кредит на срок от 3 до 36 месяцев. Также он будет использован при неверном указании другого промокода». | Glossary. READ. **Contradicts b-api-market.md §2.5** which says a wrong code gives "не найдено доступных кредитных продуктов" — the glossary says a wrong code silently falls back to `default`; the help page has an error "не найдено доступных кредитных продуктов" for a different case (no product available for the shop). Both exist; which applies when is UNVERIFIED. |
| `orderNumber` uniqueness / repeat Create | Same number + same composition → **no new application, the old link is returned** («Если вы случайно вызовете этот метод с одинаковым составом заказа, включая его номер… новая заявка не будет создана. Вам вернется ссылка на уже созданную заявку», `methods`; glossary «Повторная заявка»). Same number + **different** composition → «система покажет ошибку… Вторая заявка не будет создана» (help; the exact error string is not given beyond the FAQ title «Что делать, если заказ с таким номером уже существует?» — treat that FAQ title as the message, tagged UNVERIFIED as an API string). Different number → always a new application. A repeat order with the same number and same items cannot get a second credit (help example, 2 chairs). | READ |
| Success / fail / cancel URLs | `successURL` (signed), `failURL` (bank refusal), `returnURL` (buyer cancels). They only drive a «Вернуться на сайт магазина» button on the final screens. Domain must equal the site domain; HTTPS only; if not sent, the cabinet's «Success URL/Fail URL» are used. | `settings/return-links`. READ |
| `values` | Only prefill; optional. | `methods`. READ |

## 6. Amount limits, down payment, currencies

| Item | Answer | Source / mark |
|---|---|---|
| Credit body | «Тело кредита должно составлять от 3000 до 500 000 ₽». | glossary; help. READ |
| Instalment | 3 000 – 500 000 ₽ **after** the instalment discount: «Для рассрочки: От 3 000 до 500 000 ₽ с учетом скидки по рассрочке» (3 200 ₽ at 10 % → 2 880 ₽ → rejected). With a 5 % discount the product price window is 3 158 – 526 315 ₽. | help; glossary. READ |
| Above 500 000 ₽ | «Если стоимость товара больше 500 000 ₽, кредит все равно можно оформить, но… сумма сверх лимита идет в первоначальный взнос» (525 000 ₽ → credit 500 000 + down payment 25 000). Needs the cabinet tick «Принимать первоначальный взнос от клиента» (one switch for credits and instalments); without it: «На этом сайте недоступно внесение первоначального взноса». | help. READ. Note the help text states this for *credits*; whether the over-limit mechanism also applies to instalments is not clearly stated (the checkbox "applies to both") — UNVERIFIED. |
| `sum` ceiling | ≤ 1 000 000 (and each `items.price` ≤ 1 000 000), > 0. | help. READ |
| Floor | `sum` > 0 is the only API-level floor; the 3 000 ₽ floor is the credit body, enforced as an error at Create. | help. READ |
| Cooling-off | Credit/instalment < 50 000 ₽: none; 50 000 – 199 999 ₽: 4 h; ≥ 200 000 ₽: 48 h. | help `…/how-to-integrate/api/`. READ |
| Currencies | **Nothing in the API docs names a currency field or any currency but ₽.** `sum` is a bare number. RUB-only is the reading; foreign currency UNVERIFIED (not mentioned). | READ (absence) |

## 7. `Info` response (confirmed names)

All of the following are in the `methods` § Info table (identical for Commit and Cancel responses). READ.

`id` (= orderNumber), `status`, `created_at`, `demo`, `committed` (boolean: confirmed), `first_payment` (down payment), `order_amount`, `credit_amount`, `transfer_amount` (what the bank sends the partner), `product` (`credit` | `installment_credit`), `term` (months, int32), `monthly_payment`, `first_name`, `last_name`, `middle_name`, `phone`, `loan_number`, `email`, `appropriate_signing_types[]` (`bank`, `sms`, `ses`), `signing_type`, `chosen_bank` ("ОТП Банк, Т-Банк или МТС Банк"), `expected_overdue_at`, `items[]`, `commit_cooldown{until}` (webhook adds `has_happened`).

Only `id`, `status`, `created_at`, `demo`, `committed`, `order_amount`, `appropriate_signing_types`, `expected_overdue_at` are marked required; **all money/term fields are optional** — present only once the buyer has a chosen offer. Whether `monthly_payment`/`credit_amount` exist before signing is not stated (UNVERIFIED; README Q8). The `Info` body contains **unmasked** personal data (names, phone, e-mail) — the webhook body masks it; the docs do not say the Info body is masked. Note for logging.

Cooling-off in Info: `commit_cooldown.until` (ISO 8601), present only for cooled applications; `Commit` during cooling returns **422**.

## 8. Corrections / additions to b-api-market.md

1. **§0/§2 "primary pages unreachable"** — now read; the Docusaurus pages are plain HTML (see §0 above).
2. **§2.1 / "Create may be unauthenticated … the doc text for Create lists a 401 — inconsistent"** — settled: the docs state outright that Create and CreateDemo use no Basic auth; the 401 line belongs to the shared status list for the group, not to Create.
3. **Q5 (username `showcaseId` vs `shopId`)** — settled: `showcaseId` is the Basic login, `demo-<showcaseId>` for demo orders.
4. **§2.5 field limits** — the b-api-market.md "reference 64 / 255" values are right for the *reference* (`promoCode` string(64), `items.name` string(255)); the *help page* says 256/256. The two official sources disagree; use the stricter (64 / 255) unless a bank test says otherwise.
5. **§2.5 promoCode** — the glossary says an invalid promo code falls back to `default` rather than erroring; the "не найдено доступных кредитных продуктов" error is for another condition. Don't promise an error on a wrong code.
6. **§2.3 `Rejected` webhook** — confirmed (per bank). b-api-market.md's "retries not documented" is **confirmed**: only "non-2XX ⇒ resend" is stated, no count or interval.
7. **§2.2 status list / "webhook status only takes signed|canceled|rejected|approved"** — confirmed. The Go client's extra flag list is correct, plus the reference marks `transfer_amount`, `first_payment`, `credit_amount`, `term`, `monthly_payment`, `product`, `loan_number`, `signing_type`, `chosen_bank` as optional.
8. **§1.4 / §2.8-7 `demoFlow` enum** — the Go client (`sms|bank|ses`) is **wrong/outdated**; the official enum is `sms | ses | appointment | appointment-reject | reject`. v1's `appointment`, `reject`, `appointment-reject` are valid, and v1's `initialStage` values (`filling`, `wait-offer`, `kvk-issued`, …) **are** documented (full list in §4). README Q3 is closed.
9. **§1.4 "Basic-Auth username `demo-<showcaseId>`"** — confirmed.
10. **§2.7 / base URL** — the docs use `https://forma.tbank.ru/api/partners/v2/…` in every example; `forma.tinkoff.ru/docs` now 302-redirects (the docs, at least), consistent with D12's "old API host still works" (the API itself was not probed).
11. **§2.1 Create "Same number + different cart → error 'заказ с таким номером уже существует'"** — matches the help FAQ title; the reference page only describes the same-cart case. The API's exact error string is not documented.
12. **§2.4 cooling** — confirmed (4 h / 48 h). The docs say «Если сумма больше 50 000 ₽» (help) — the boundary at exactly 50 000 is ambiguous; the 50 000 – 199 999 band is explicit.
13. **Statuses** — Info/Commit/Cancel bodies in the reference contain **no** `expired` status; `Cancel` on an auto-committed signed application is refused in practice («вы не сможете отменить заявку. Придется оформлять возврат»), `422` is the documented code for "action not allowed in the current state".
14. **New facts b-api-market.md lacked:** `Commit` must be called within **14 days** of signing; `Info` should be called after every webhook **and** if no webhook arrives within an hour of creation; the cabinet's static webhook address is mandatory even with a per-order `webhookURL`; the webhook body is PII-masked while Info's is not stated to be; the script button page lists `promo-code-select` items as `text`/`value` string(64).

## Skipped / not checked

No API method was called (including password-free `create-demo`), so the sum-rule ambiguity (Σprice vs Σprice×qty), the exact sum-mismatch error string, 4xx bodies, webhook retry count/intervals, expiry behaviour and whether the old host still serves the API are **not MEASURED**. Not read: `setup-types/cms`, `constructor`, `no-integration`, `script/integration|update|testing-script|sell-start`, `settings/report-notifications` (not relevant to the API route); the PDF terms were not opened. Risk: the docs show no version/date, so a later change to the reference is undetectable from here.
