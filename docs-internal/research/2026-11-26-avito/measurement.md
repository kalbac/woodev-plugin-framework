# Avito API read-only measurement (28.11.2026)

Question: can the plugin **detect** that a seller lacks access to a method from the answer alone, and what do the
answers say about `client_credentials`, token lifetime and rate limits? Source of the claims: the probe below, the
[API map](api-map.md) and [spec input](spec-input.md) for the open question Q-2.

## Method and limits of the probe

- One owner profile (a real Avito account, not a test account), one moment: 28.11.2026.
- **Read-only.** Only `GET` requests and the `client_credentials` `POST /token`. No business write was sent: no feed
  upload, no price, stock, item or order action, no message, no paid service. The stock read is documented only as
  `POST /stock-management/1/info`, so it is **not** measured (see the table). The price-update and messenger paths
  were not called.
- 23 HTTP requests in three short passes, 1.5 s apart. Three tokens were issued (one per pass). No `429` was seen.
- Profile identifiers, listing identifiers, titles, URLs, addresses, phones and e-mails were **not** recorded here;
  bodies were reduced to their shape (keys, counts, enum values) while the probe ran.

## Results by method

| # | Method | Status | What the answer says | Can the plugin detect missing access from it? |
|---|---|---|---|---|
| 1 | `POST /token` (client_credentials) | **200** | Token issued; `expires_in` = **86 400 s (24 h)**; `token_type` Bearer; no `scope` field in the answer; no refresh token (as documented). | Not as a tariff check. The token is issued without any tariff or subscription check on this profile, so "a token exists" proves nothing about items, autoload or orders. |
| 2 | `GET /core/v1/accounts/self` | **200** | The profile is readable with the token: id, name, e-mail, phones, profile link (values not recorded). | Yes for "the key works": a 200 here is a sound health check and gives the `user_id` that the item-info path needs. |
| 3 | `GET /core/v1/items` (default filter) | **200, 0 items** | Empty list. | **No, not by itself.** The default filter hides `old` listings: see row 3a. An empty answer is not evidence of "no listings" or "no access". |
| 3a | `GET /core/v1/items?status=…` for `active`, `old`, `removed`, `blocked`, `rejected` | 200 each; `active` 0, **`old` 5**, the rest 0 | Five expired listings exist on the profile. Each row carries `id`, `title`, `price`, `status`, `category`, `url`, `address`. | Partly. The plugin must query each status it cares about; a 200 with an empty list under the default filter means "this filter is empty", not "no access". |
| 4 | `GET /core/v1/accounts/{user_id}/items/{item_id}/` (one `old` listing) | **200** | Returns `status` (`old`), `start_time`, `finish_time`, `vas` (empty list here), `url`. | Yes for a known id: a 200 with the item is a clear "readable". A refusal would show as a status here; not seen. |
| 5 | `GET /autoload/v2/profile` | **403** | Body `{"error":{"message":"Получение профиля недоступно."}}` ("Retrieving the profile is unavailable."). No code, no reason. | **Yes as a refusal, no as a cause.** The 403 is clearly "autoload profile not available to this account", but the same answer could mean no subscription, no profile yet, or a wrong account type. Only consistent with the docs' claim that autoload needs «Расширенная»; not proof of it. |
| 6 | `GET /autoload/v4/uploads` | **401** | Body `{"error":{"code":401,"message":"Forbidden"}}`. | **Yes as a refusal, but the status is misleading**: a 401 on one endpoint while the same token gets 200 on other endpoints. The plugin must not read 401 as "bad token". |
| 7 | `GET /autoload/v4/uploads/current` | **401** | Same body as row 6. | Same as row 6. The docs say `404` when there is no upload; here the answer is 401 before any question of "no upload" arises, so "no upload" and "no access" cannot be told apart. |
| 8 | `GET /autoload/v4/uploads/last_successful` | **401** | Same body as row 6. | Same as row 6. |
| 9 | `GET /autoload/v4/uploads/current/items` | **401** | Same body as row 6. | Same as row 6. |
| 10 | `GET /autoload/v4/uploads/last_successful/items` | **401** | Same body as row 6. | Same as row 6. The autoload report is therefore not readable on this profile at all. |
| 11 | `GET /autoload/v1/user-docs/tree` | **200** | Category tree (10 top-level categories, nested). | Not an access signal: this is documentation, readable without the subscription. Useful for the category step, not for detection. |
| 12 | `GET /order-management/1/orders?limit=5` | **403** | Body `{"code":403,"message":"API работает только с Авито Доставкой для бизнеса. Чтобы её включить, нужно указать реквизиты компании и настроить тариф. Подробнее: avito.ru/general/dostavka"}` ("The API works only with Avito Delivery for business. To enable it, company details are needed and the tariff must be set up."). | **Yes, and it names the cause in words.** Only one refusal of this method was seen. The body has a numeric `code` that repeats the status, so a plugin can show the message, but a code-based test is not possible. Matching the Russian text would be fragile. |
| 13 | `GET /tariff/info/1` | **404** | Body `{"errors":["Тариф не найден."]}` ("Tariff not found."). | **No.** The docs say this method is for transport and realty only; a goods seller gets 404 whatever the tariff. It says nothing about the goods tariff. |
| 14 | Stock read: `POST /stock-management/1/info` | **not measured** | The only stock read in the spec is a POST; the rule of this probe allows GET only. | Open. Q-2 stays open for stocks. A stock answer needs a separate, explicitly approved POST read. |
| 15 | Subscription info | **no endpoint** | Nothing in the spec states a goods seller's subscription (the API map §1.2 says the same). | Not possible from the API. |
| 16 | Messenger, orders detail, labels | **not called** | Reading chats exposes buyer messages; orders detail and labels are out of the read-only scope. | Open. |

### Rate-limit headers seen

Only the `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers were present (no `Retry-After` and no `429`):

| Method group | `X-RateLimit-Limit` | Notes |
|---|---|---|
| `GET /core/v1/items` | 25 / min | Remaining fell 25 → 18 over the three passes; matches the API map §2. |
| `GET /core/v1/accounts/{user_id}/items/{item_id}/` | 500 / min | As documented. |
| `GET /autoload/v4/…` (all six calls) | **10 / min** | Also sent on the 401 answers, so refusals count. Remaining fell 10 → 6 in the first pass. The API map gave no number for autoload reads. |
| `GET /order-management/1/orders` | 500 / min | Sent on the 403 answer. |
| `GET /tariff/info/1` | 100 / min | Sent on the 404 answer. |

The counters look separate per method group: between the items calls of the first and second pass (25 → 24) the autoload counter moved by five, and the items counter moved by one.

## Answers to the three questions

1. **client_credentials without a paid tariff or subscription.** On this profile the token request succeeds (200) and
   gives a 24-hour token. The probe cannot test a profile *without* a tariff: the only profile is the owner's, and its
   tariff and subscription state is not visible to the API. What the 200 does show is that the token step is not
   where access is refused: the refusals come per method (rows 5–13).
2. **Access detection per method.** Detectable as a refusal: autoload (403 on the profile, 401 on the reports),
   orders (403 with a readable cause). Not detectable as a cause: the autoload refusals, which carry no reason. Not
   detectable at all: the goods tariff (no endpoint), stock (not measured), and an empty items list (ambiguous with the
   default filter).
3. **Rate limits.** Above. The autoload reads are the tight ones (10 / min) and count refusals too.

## What this changes for the spec

- **Q-2 (can D1 be implemented: how does the plugin learn what a seller lacks?)** — partly answered. The plugin can
  learn that a *method* is refused, from the status and body of a read it has to make anyway. It cannot learn *why*
  from the autoload refusals, and it cannot read the tariff or the subscription. A "lacks access" state must be a
  per-method refusal flag with the merchant's text, not a tariff inference.
- **Autoload refusals (403/401) must not be read as a bad key.** The same token returned 200 on items and account. A
  plugin that treats 401 as "reconnect the key" would send merchants to the wrong fix.
- **Empty lists are not evidence.** The items list needs explicit status filters before a plugin decides "no listings".
- **The refusal texts are Russian and not stable.** Show them to the merchant; do not branch on their wording.

## Not checked

- A profile **without** a paid tariff or subscription, or a second profile. Only the owner's profile was probed, so the
  "refusal means no subscription" reading is an inference, not a measured pair.
- Stock reads (POST only, excluded by the GET-only rule), price, feed upload and report-by-id behaviour, order detail,
  labels, messenger.
- Whether the 401 on the autoload reports is a missing subscription or a missing upload history: the profile's
  feed state is unknown to the probe.
- Whether the 5 `old` listings came from autoload or from manual publishing; the probe did not check.

## Related

- [API map](api-map.md) — the sources for the limits and the paid-access claims
- [Spec input](spec-input.md) — open question Q-2 that this measurement answers in part
- [Access needed](access-needed.ru.md) — the owner-side setup that a clean measurement would still need
- [Research index](README.md)
