# Russian Post v2 — operator decisions from the brainstorm (21.10.2026, s191)

> Walked through [brainstorm-agenda.ru.md](brainstorm-agenda.ru.md) item by item with the operator on 21.10.2026. Where a decision
> differs from the agenda's recommendation, the decision wins. Facts behind each item: [README.md](README.md).

- **1.** Widget cabinet: drop it entirely; a migration screen carries over what the old options hold (sender index, default
  dimensions/weight) and explains the Otpravka keys. Otpravka needs a contract as a legal entity / ИП / самозанятый;
  private persons cannot use it. Number of widget-only shops: unknown.
- **2.** Points: NO full passport download (operator rejects it). Live through the non-public widget backend, server-side via the
  framework viewport strategy with a short (minutes) cache; never browser-direct. Closed check on selection via
  GET /api/pvz/{id} (closed / temporaryClosed). pochta.ru find-from-rectangle to be measured later as a possible better
  source. No fallback source now; keep the source behind the framework Point_Source interface.
- **3.** Tariffs: like CDEK — one shipping method per destination kind: courier, post office, and a separate postamat method if
  postamats use their own tariffs. Each method instance has exactly ONE tariff chosen by the merchant; on checkout one
  method = one concrete tariff (choice comes from the merchant adding several instances). Partner ПВЗ and «Доставка плюс»
  (54020): operator knows nothing about them, not committed — decide later by measurement.
- **4.** Prices: calculate through Otpravka POST /1.0/tariff (merchant keys → likely their contract), cache by route/weight/
  tariff/declared value, own quota counter, fall back to the public calculator when Otpravka fails or the quota is near.
  The daily quota (10 000 on the test account) can be raised by the merchant's personal manager — say so in docs/notices.
  Use GET /1.0/settings (account flags: which mail types are enabled, e.g. deliveryPlusAllowed; ИНН; return address;
  238 KB → cache) to offer only the tariffs enabled for the merchant, and GET /1.0/user-shipping-points (27 points on the
  test account, each with operator-postcode) for the sender point picker in the wizard/settings. Contract prices stay
  unmeasured (no merchant with a contract available).
- **5.** Address: checkout uses only the framework Location layer (DaData key once, shared). Russian Post clean/address runs at
  order export; the manager sees an «address doubtful» flag. Trust only the quality/validation codes.
- **6.** Export: order create + tracking number + Ф7 label printed from the admin (one order). Batches, Ф103/«checkin», courier
  call — later, after a check on a real account.
- **7.** Tracking: port tracking onto the framework's polling mechanism, active orders in turn. **Updated 21.10.2026 by task L
  ([l-new-api.md](l-new-api.md)):** use the NEW REST tracking `GET postapi.pochta.ru/tracking/api/external/v1/info` with
  `Authorization: ApiKey <token>` (a separate ~1 050-char token from the cabinet settings, legal entities only; the operator has
  one; verified live). One barcode per call, ~5 000 requests/day per the FAQ (the old terms page says 100; unverified). No SOAP,
  no tracking login/password, no batch SOAP in release 1. Poller: queue, per-barcode interval, backoff and a hard daily cap
  well under the limit (state worst-case requests/day). Declare the token secret for log masking. Without a token tracking is
  visibly off (or only the public tracking link), never a silent drop.
- **8.** Old-site data: NEW method ids, no permanent legacy code. A one-time background migration (batched, restartable, progress
  visible in the admin) rewrites shipping zones to the new methods (CDEK pattern), rewrites old orders' shipping-line method
  ids and converts order meta to the v2 format (the two serialized-object metas to plain arrays). Losing a zone method is
  acceptable; losing order data is not. The migration is removed a few versions later. Check at implementation how the
  CDEK migration handled old orders and reuse it.
- **9.** Credentials: password-type fields; declare Authorization and X-User-Authorization secret so the framework masks them in
  logs; never send the admin an e-mail with a credential link. NO encryption at rest (operator: overkill, not our duty;
  the framework's masking is enough) — no card.
- **10.** Block checkout: stays as decided — give what the framework gives, no completeness guarantee, release not held.
- **11.** International: shops that ship abroad exist (Russian Post once had the best cross-border offer and many shops installed
  the plugin for it); how many remain is unknown. (Decision on scope: see the follow-up below.)
- **11 (scope).** Decide by measurement: a worker measures international on the TEST Otpravka account (tariffs to several
  countries, international order create with customs declaration + ТН ВЭД, CN22/CN23 documents). If orders create →
  international is in release 1 with the country table rebuilt from Russian Post's own reference (not v1's table, which has
  8 duplicated numeric codes). If the account refuses → release without it and say so in the update notes. Either way the
  migration keeps old international orders' data.
- **12.** Wizard: short — Otpravka keys with a connection check → sender point picked from user-shipping-points → default weight/
  dimensions → the tariffs this account can use, explaining why one is unavailable. Plus the v1 migration screen (topic 1).
  The v1 six-step wizard that mirrors the settings screen is not ported. The tracking token is an OPTIONAL last step (skip =
  tracking off), checked with one call on an invalid barcode (403 = bad token, 400/1003 = token OK); it never blocks export.
- **13.** Coexistence: nothing more to do. Merchants are warned (Telegram channel + documentation). The EDD folder is
  `woodev-russian-post`, so CDEK v2 (`woocommerce-edostavka`) and Yandex v2 (`woocommerce-yandex-delivery`) load first and
  Russian Post v1 is quarantined (gone from checkout, admin notice only). The answer is speed: do not delay the v2 release.

- **11 (outcome).** Task K measured international order create as WORKING on the test account ([k-international.md](k-international.md)):
  international is in release 1, country codes from the official ISO-numeric dictionary.

## Still open (measurement, not opinion)

- «Доставка плюс» (54020) and partner ПВЗ: order create is disabled on the test account; measure before deciding.
- The pochta.ru `postoffices.find-from-rectangle` endpoint as a point source with closed flags in the listing.
- Contract prices through `POST /1.0/tariff`: needs a merchant with a real contract.

## Related

- [brainstorm-agenda.ru.md](brainstorm-agenda.ru.md)
- [README.md](README.md)
