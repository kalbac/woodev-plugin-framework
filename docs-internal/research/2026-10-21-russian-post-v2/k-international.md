# Task K: Russian Post international shipments, live on the TEST «Отправка» account (21.10.2026, s191)

Raw files: kept outside the repo (paths below are relative to it).
No credential value was printed or saved (a string scan of the raw dir for token/login/password/base64 key finds nothing; `settings-masked.json` is redacted). Nothing in any repo was edited.

## 0. Verdict

**International order create works on this account: YES** — for the three mail types the account exposes:
`POSTAL_PARCEL` (ORDINARY / WITH_DECLARED_VALUE), `EMS` (ORDINARY) and `SMALL_PACKET` (ORDERED), created through the ordinary
`PUT /2.0/user/backlog` (no separate international path) with `mail-direct` = numeric country code, a customs declaration and ТН ВЭД codes.
29 orders were created across 12 destinations (DE, US, CN, AE, BY, KZ, UZ, JP, OM, HR, SK, RS …), priced, edited, put in a batch, documents (CN23/CN22/E-1) fetched, undone and **all 29 deleted, 0 leftovers** (404 on every id; cleanup-proof below).
It is **not** "works everywhere with one payload": five payload rules differ by destination or are strict (§6) and a v2 plugin has to carry them.

Account caveat: the cabinet is Russian Post's own demo cabinet (agreement «ТЕСТОВЫЙ ЛК», CORPORATE_CLIENT). Nothing here proves a real merchant contract
(INN, org Latin name, intake office) enables the same products; `GET /1.0/settings` is the thing to read per account (§2).

Quota: **114 Otpravka requests** by this task (counter 387 at start → 490 displayed at the last read, lagging as G measured; 387 + 114 ≈ 501). Public tariff.pochta.ru calls (~190, free) and the spec/dictionary downloads are not counted.

## 1. Answer table

| # | Question | Result | Evidence |
|---|---|---|---|
| 1 | International mail types in the official spec | There is **no separate international `mail-type`**. International = the ordinary `mail-type` + `mail-direct` (numeric country code) + `transport-type` + `customs-declaration`. Enum `mail-type` has POSTAL_PARCEL, EMS, LETTER, BANDEROL, SMALL_PACKET, … ; `enums-product` lists the international product types `INTERNATIONAL_POSTAL_PARCEL_ORDINARY / _WITH_DECLARED_VALUE / _…_AND_CASH_ON_DELIVERY`, `INTERNATIONAL_EMS_ORDINARY`, `INTERNATIONAL_LETTER_ORDERED`, `INTERNATIONAL_SINGLE_LETTER_SIMPLE`, `INTERNATIONAL_BANDEROL_ORDERED`; small packet = `SMALL_PACKET_ORDERED` | `spec/views/enums-base-mail-type.html`, `enums-product.html` (spec copied from otpravka.pochta.ru) |
| 1b | Required fields (spec) | `mail-direct` (int, country list), `str-index-to` (string, alphanumeric foreign postcode, "for international"), `transport-type` ∈ SURFACE/AVIA/COMBINED/EXPRESS/STANDARD, `customs-declaration{currency (list), entries-type ∈ GIFT/DOCUMENT/SALE_OF_GOODS/COMMERCIAL_SAMPLE/OTHER, customs-entries[]{amount, description, tnved-code, value (kopecks per unit incl. VAT), weight (g), country-code (origin), trademark}, optional: ioss-code, invoice-number, license-/certificate-number, with-invoice/-license/-certificate, customs-code}`, EMS only: `with-documents`, `with-goods`. Recipient: `recipient-name`, `given-name`, `surname`, `tel-address`, `place-to/region-to/street-to/house-to` (all **Latin only**, see §6). No recipient passport/INN field exists for private persons; `customer-inn`/`supplier-inn` are only in fiscal-data/goods | `spec/intl-spec-extract.txt`, `spec/views/orders-creating_order_v2.html` |
| 1c | Size/weight limits | Per country in the public dictionary (below) — parcel `wmax` 20 000 g and 105×105×105 cm (`linemax` 200 = sum of sides), small packet 2 000 g and 60×30×30, EMS 30 000 g (a few countries 10 000 / 20 000 / 31 500). Order create enforces mass: `ILLEGAL_MASS_EXCESS` at 20 001 g parcel, 2 001 g packet, 30 001 g EMS; 20 000 g accepted. **Dimensions are NOT enforced** (a 110 cm side was accepted) | `order-matrix-4.json`, `order-matrix-5.json`, `dict-summary2.txt` |
| 2 | `GET /1.0/settings`: any international flag? | **No `international-enabled` flag.** Availability is per **intake office** (`available-shipping-points[].user-available-products` / `available-mail-types`). 109012 (Moscow) has parcel, EMS, letter, banderole international products + SMALL_PACKET; only 7 of the 27 points have any `INTERNATIONAL_*` product (e.g. 353090 Novorossiysk: EMS only; 404120/404127: parcel + EMS). Related fields: `declaration-enabled true`, `account-declaration-enabled false`, `org-name-international` (Latin organisation name for CN forms), `customs-code`, `ftsRegistryAllowed false`, `favoriteTnvedAllowed false`, `mmo-enabled/mmo-auto-enabled true` | `settings-masked.json` |
| 3 | Tariffs: POST /1.0/tariff vs public calculator, 7 destinations × 5 products | Otpravka **prices all 35 combinations** (no errors) for KZ, BY, AM, DE, US, CN, AE × {parcel AVIA, parcel SURFACE, EMS, small packet AVIA, small packet SURFACE}. Request: `{index-from, mail-type, mail-category, mass, mail-direct, transport-type}` (`entries-type` accepted but not needed). Response `total-rate`/`total-vat` (kopecks, VAT 22 %) and `avia-rate` or `ground-rate`, `delivery-time{min-days,max-days}` **full door-to-door estimate** (e.g. DE parcel avia 25–31 d, EMS 12–17 d, KZ EMS 9–14 d) | `tariff-matrix.json`, `tariff-<Country>-<product>.json` |
| 3b | Do they match the public calculator? | **Not with default parameters; yes with `service=28` («Корпоративный клиент»).** Public default prices are 0–16 % higher (1 kg avia parcel DE 3 487 vs 2 950 ₽ w/o VAT; EMS DE 4 624 vs 3 947). Adding `service=28` makes the public calculator equal to Otpravka in **33 of 35** pairs, to the kopeck; the 2 differences (Belarus and Armenia small packet SURFACE) are Otpravka returning the AVIA price 2 430 ₽ where public surface = 2 030 ₽. So the account's CORPORATE_CLIENT category is applied inside Otpravka and is NOT visible in the default public price (domestic prices matched exactly in G/V; international do not). Public parameter names: `country` (numeric), `weight` (g), `isavia` (0 surface / 1 avia-if-possible / 2 avia only), `service`; objects 4031 parcel, 4021 parcel with declared value (`sumoc`), 7031 EMS, 5011 small packet ordered; **5991 «мелкий пакет исходящий» cannot be priced (error 1202, only a delivery time)**; 5991/4031 `delivery` from the public calculator is **only the domestic leg** (4–5 days for every country) — useless as a door-to-door estimate | `tariff-matrix.json` (`public`, `public_isavia`, `public_svc28`), `pub/public-intl-1kg.json`, `tariff-obj-*.json` |
| 3c | Which return errors, and which codes | **No tariff error on the 7 test countries.** Errors appear for forbidden destinations: public `object` returns business error text «В <СТРАНА> запрещена доставка …[2.13]»; Otpravka order create returns `TARIFF_ERROR` with the same text (Poland, Ukraine). Public: `pay` null + `errors[]` code 1202 for unpriceable objects (5991, EMS to blocked countries). Parcel to UAE with declared value (4021) has no price (declared value not allowed to UAE: `oc 0`) | `order-matrix-3.json`, `pub/public-intl-1kg.json` |
| 4 | Order create (PUT /2.0/user/backlog) with customs declaration + ТН ВЭД | **Created** (HTTP 200 with `orders[]`): DE parcel ORDINARY, DE parcel WITH_DECLARED_VALUE (`insr-value`), DE parcel with ioss-code/invoice-number, DE EMS (ORDINARY and with `with-goods`), DE small packet (ORDERED), US, CN, AE (parcel; avia), BY, UZ, JP, OM, HR, SK, RS, KZ parcel (no customs declaration), KZ COD. The **barcode is returned at create**: parcel `CL123574305RU` (13-char S10, `CL#########RU`), EMS `EL076560243RU`, small packet `LS019020072RU` — not the 14-digit `80…` of domestic ЕКОМ-style orders | `order-matrix-1/2/3/4/5/6/8/9*.json`, `order-DE-parcel-entries.json`, `ledger.json` |
| 4b | Edit | `GET /1.0/backlog/{id}` returns the stored order incl. `customs-declaration{…, status:"NEW"}`, rates (`avia-rate` or `ground-rate`), `delivery-time`. `PUT /1.0/backlog/{id}` with the full body (mass 1 000→2 000, entry weight/value/amount) → 200 empty, re-priced 295 000 → 352 200 kopecks, barcode unchanged, customs entry updated | `order-DE-get.json`, `order-DE-edit.json`, `order-DE-get-after-edit.json` |
| 4c | Documents (CN22/CN23/F7) | Put 3 orders in a batch (`POST /1.0/user/shipment?sending-date=…` → **3 batches 814/815/816, one per mail type, `international:true`**, no mixing). Then `GET /1.0/forms/{id}/forms?sending-date=…` (any Accept) returns a PDF: **parcel 5 pages (page 1 = CN23 with ТН ВЭД, origin, weight, sender INN/КПП from the account)**, **EMS 3 pages (E-1 «Международное отправление» with CN23 embedded, two copies)**, **small packet 1 page (CN22 + address label with an «IOSS» reference line)**. `GET …/f7pdf` → **400 code 1019 «Системная ошибка формирования печатной формы»** for all three (F7 is domestic only). Before a batch: `GET /1.0/forms/backlog/{id}/forms` → **500 `Forbidden mail type`** for parcel and small packet, **500 code 1021** for EMS. Not run (forbidden): `checkin`, F103, the all-docs zip | `doc-DE-parcel-forms.pdf`, `doc-DE-ems-forms.pdf`, `doc-DE-packet-forms.pdf` (page-1 images in `png/`), `doc-*-f7.json` |
| 4d | Cleanup | Batches undone with `POST /1.0/user/backlog` (same barcode kept), then `DELETE /1.0/backlog`. **29 created, 29 deleted, 0 leftovers**: `GET /1.0/backlog/{id}` = 404 for every id, `GET /1.0/batch/814/815/816` = 404, `GET /1.0/batch` lists 13 batches (the 13 historical ones, last `612`) | `ledger.json`, `cleanup-delete*.json`, `verify-*.json` (29 files), `verify-batch-*.json`, `verify-batch-list.json` |
| 5 | Country table | Russian Post has **two** official references: (a) `https://tariff.pochta.ru/v2/dictionary/country` — JSON, **256 countries, 277 KB**, anonymous, per country per product (`mail`, `banderole`, `parcel`, `packet`, `ems`, `sack`, `secogram`) the flags `block` (surface), `avia.block`, `wmax`, `sizemax`, `oc` (declared value allowed, `oc-rate`, `oc-max`), `np` (cash on delivery), `serviceoff`, `declaration`; (b) the Otpravka spec page «Список стран» (`dictionary-countries`) — **255 rows**, code + Russian + English name, a static HTML page (no API endpoint for it; the only difference to (a) is code 906 «Вознесения остров», present in (a) only). Codes are the **ISO 3166-1 numeric** codes (643 = Russia, 276 = Germany …). Both have **zero duplicated codes** | `tariff-dict-country.json`, `countries-spec.json`, `dict-summary.txt` |
| 5b | v1's 8 duplicated codes | **Resolved: yes, completely** — v1's table has 248 entries and 8 numeric codes each given to two ISO countries; the official reference gives every country its own code: **784** AE=UAE but OM→**512**; **410** KR=Korea but KP→**408**; **534** SX=Sint Maarten (NL) but MF→**663**; **662** LC=Saint Lucia but RS→**688** Serbia; **760** SY=Syria but SK→**703** Slovakia; **729** SD=Sudan but SR→**740** Suriname; **258** PF=French Polynesia but HR→**191** Croatia; **140** CF=Central African Rep. but TD→**148** Chad. **Real effect measured:** a Slovak, Croatian or Serbian parcel sent with v1's code goes to Syria / French Polynesia / Saint Lucia (EMS to those = no price or other price; Oman via 784 prices as UAE 5 070 ₽ instead of Oman 4 828 ₽). Created order for Oman (512), Croatia (191), Slovakia (703), Serbia (688) were all accepted. v1 also carries 2 codes absent from the spec (`AX`→949, `SH`→906; spec: Åland = 248, St Helena = 654) and lacks 17 spec codes (e.g. Abkhazia 895, Kosovo 914, KNDR 408, Oman 512, Serbia 688, Slovakia 703, Croatia 191, Chad 148, Suriname 740, Åland 248, S. Ossetia 896). A v2 plugin should derive the code from the ISO numeric table, not a hand-typed list | `v1-countries.json`, `dup-pairs.txt`, `order-matrix-3.json` |
| 6 | What changes the v2 design | §6 below | — |

## 2. Destinations Russian Post accepts today (dictionary of 21.10.2026, version 3.21)

| Product | Countries open (surface or air) | Notes |
|---|---|---|
| Small parcel `parcel` («посылка») | **118 of 256** (36 by surface, +82 only by air) | 20 kg, 105 cm side, sum of sides 200 cm |
| Small packet `packet` | **113** (31 by surface) | 2 kg, 60×30×30 |
| EMS | **156** | 30 kg (31.5 / 20 / 10 kg in a few) |
| Letter / banderole | 171 | not in scope |
| **Every product blocked** | **84 countries**, among them Poland, Ukraine, Finland, Australia, Syria, Sudan, Paraguay, Guatemala, Libya, Yemen, Kosovo | an order to them returns `TARIFF_ERROR … запрещена доставка` |
| Parcel and EMS both possible | 112 | |

`block` means «not by surface»: USA, UAE parcels are surface-blocked but open by air (`avia.block 0`); the server **silently** turned `transport-type SURFACE` into AVIA for the USA order (stored `AVIA`, price 2 970 ₽). `transport-type` omitted → stored `SURFACE` (ground rate, 294 700 for DE vs 295 000 avia).

## 3. Orders measured (all deleted)

Created (barcodes are Russian Post test barcodes, shown for evidence only): DE parcel ordinary / declared value / ioss+invoice / 2 kg-edit / 20 000 g; DE EMS ×2; DE small packet; US, CN, AE, BY, UZ, JP, OM, HR, SK, RS parcels; KZ parcel (no declaration), KZ COD; currency USD, KZT; entries-type COMMERCIAL_SAMPLE and DOCUMENT. Rejected with exact codes (payload rules, not account switches):

| Case | Result |
|---|---|
| Customs entries for **Kazakhstan, Armenia, Kyrgyzstan** | `UNEXPECTED_CUSTOMS_ENTRIES «Указание вложений не поддерживается для данного РПО»` — the declaration must be **omitted** (KZ without it: created). **Belarus and Uzbekistan accept entries** (created). Dictionary flag `declaration:3` does NOT predict it (Belarus has no flag, accepted) — the rule is a hidden destination table |
| Missing declaration for a destination that needs it | `EMPTY_CUSTOMS_ENTRIES`; missing ТН ВЭД `EMPTY_CUSTOMS_ENTRY_TNVED_CODE`; ТН ВЭД `ABC` `ILLEGAL_CUSTOMS_ENTRY_TNVED_CODE` (format check only measured; a well-formed non-existing code was not tried); missing `currency` `EMPTY_CURRENCY`; unknown currency `XYZ` `ILLEGAL_CURRENCY`; missing `entries-type` `EMPTY_ENTRIES_TYPE`; `GIFT` to DE parcel `ILLEGAL_ENTRIES_TYPE` (SALE_OF_GOODS, COMMERCIAL_SAMPLE, DOCUMENT accepted) |
| Destination fields | missing postcode `EMPTY_INDEX_TO`; missing `mail-direct` `EMPTY_MAIL_DIRECT`; code 999 `ILLEGAL_MAIL_DIRECT`; code 643 with a foreign postcode `ILLEGAL_INDEX_TO` |
| **Cyrillic** name / street / city | `ILLEGAL_RECIPIENT_NAME_INTL`, `ILLEGAL_STREET_TO_INTL`, `ILLEGAL_PLACE_TO_INTL` — «only Latin letters» → transliteration is mandatory (v1 does it with `str_convert`) |
| Entry **description** | `DESCRIPTION_TOO_SHORT` (details `200`) for «Books», «Booksx», «Bookset8», «Bookset10!», «Red books» (≤ 10 chars); accepted «Cotton t-shirts» (15), «Bookshelves12» (13), «Cotton t-shirt» (14) → the minimum is 11–13 characters. Product titles like «Mug» need padding |
| Entries weight | sum of entry weights > order mass → `ENTRIES_SUM_WEIGHT_EXCEEDS_ORDER_MASS` |
| Forbidden destinations | Poland (616), Ukraine (804): `TARIFF_ERROR` «запрещена доставка» (a **business error at HTTP 200**, like domestic) |
| COD (international `WITH_DECLARED_VALUE_AND_CASH_ON_DELIVERY`) | **DE: `ILLEGAL_MAIL_CATEGORY`**; **AM: `TARIFF_ERROR` «невозможно отправить наложенным платежом»**; **KZ: created**; BY: `TARIFF_ERROR` «сумма наложенного платежа 26 942-60 руб не должна превышать ОЦ 1 000-00 руб» for `payment`=`insr-value`=100 000 kopecks → the COD amount for Belarus appears to be converted from the destination currency (**inferred, not measured further**) |
| Foreign small-packet case | the `ioss-code` (`IM1234567890`, fake) and `invoice-number` were accepted and stored; no format check observed |

## 4. Other measured facts

- Barcodes: parcel `CL…RU`, EMS `EL…RU`, small packet `LS…RU` — returned at create, retained across batch and undo.
- Batching: one batch per mail type (`international:true`, `transport-type` carried); three mail types did not merge (unlike domestic COMBINED).
- Automatic MMO grouping did not trigger (all recipients distinct); not re-measured.
- Server leniency: the KZ parcel without any declaration was accepted; `order-num` not unique (as G); recipient/sender INN/КПП and Latin org name on forms come from the account settings, not from the order.
- Lifecycle cost per order: create 1 (many per request), batch 1, forms 1, undo 1, delete 1; verification GETs not needed in production.

## 5. UNVERIFIED (and why)

- Pages 2–5 of the parcel PDF (only page 1 rendered; macOS `sips` renders page 1) and what the EMS 3rd page is.
- F103 / the all-docs zip / `checkin` / physical hand-over, and whether the customs data is transmitted to customs ("ЭЛЕКТРОННЫЕ ДАННЫЕ" is printed on the forms) — forbidden operations.
- Real acceptance at a post office, real delivery times (the `delivery-time` is an estimate), real customs outcome for the t-shirt/ТН ВЭД combinations, whether a **non-existing but well-formed ТН ВЭД** is rejected.
- Armenia/Kyrgyzstan plain parcels without declaration (only KZ was created; AM/KG were rejected only with entries); Belarus COD currency semantics; value-in-kopecks unit when `currency` ≠ RUB (stored as given, no conversion observed); `ioss-code` validity rules; recipient-country-specific rules (EU VAT/IOSS thresholds).
- Contract pricing (`dogovor=`) — still the test cabinet.
- Letter/banderole international orders (products exist; not requested).
- Whether a real merchant account gets the same international products at its intake office (here only 109012/404120/404127/344961 have the full set).

## 6. Anything that changes the v2 design

1. **International is a payload variant of POSTAL_PARCEL / EMS / SMALL_PACKET**, not a new endpoint or mail type; the product choice for the customer needs the dictionary (open/closed per country, weight limit, `oc`/`np`).
2. **Destination rule table.** Customs declaration is required for most countries but forbidden for at least KZ, AM, KG (and probably other EAEU/neighbours not tested); no machine-readable flag exists. The plugin needs a configured list plus a one-retry fallback on `UNEXPECTED_CUSTOMS_ENTRIES` / `EMPTY_CUSTOMS_ENTRIES`.
3. **Transliteration** of name, city, street, house, region and the 11+ char product description are mandatory; WooCommerce address fields are free text, so validation at checkout is worthwhile; product ТН ВЭД (10 digits) and country of origin per product are required (v1 only has a TNVED meta, fixes origin to 643 and `entries-type` to SALE_OF_GOODS; it truncates the description to 57 chars and sends the declaration for every foreign country, which fails for KZ/AM/KG).
4. **Country codes:** ISO numeric from the official dictionary (not v1's 248-row table with 8 collisions); refresh the allow/deny matrix from `tariff.pochta.ru/v2/dictionary/country` (277 KB, anonymous, free) — 84 countries are closed and the list changes (it carries per-country dates, e.g. 20260929–20260930 entries).
5. **Price source:** use `POST /1.0/tariff` (carries the account category and full delivery estimate); the public calculator equals it only with `service=28` and gives only a domestic-leg delivery time; small packet cannot be priced publicly. Public default prices exceed corporate by up to 16 %.
6. **VAT / IOSS / passport:** `ioss-code` exists in the declaration (accepted unvalidated; the packet label prints an «IOSS» line) so EU shops can enter their IOSS number; no recipient passport/ИНН field for private persons; `customer-inn`/`supplier-inn` are only for fiscal goods lines. Sender INN, КПП and the Latin organisation name (`org-name-international`) are account settings — the setup wizard must check them.
7. **Documents:** CN22/CN23/E-1 come from `GET /1.0/forms/{id}/forms` **only after the order is in a batch**; F7 does not exist for international. The cancel path is the same as domestic (undo then delete).
8. **COD:** only some countries (KZ yes; DE and AM no; BY needs payment ≤ declared value). Treat international COD as off by default.
9. **Per-intake-office availability:** international products depend on the office (7 of 27 here) — read `user-available-products` and hide the feature when absent.
10. **Weight/size:** enforce 20 / 2 / 30 kg (per-country `wmax`) and the 105 cm / 200 cm sum rule ourselves — the API checks only mass.
11. **Block checkout/UX:** the customer must be shown a Latin-script requirement and a delivery estimate range (17–33 days parcel, 9–18 EMS here), not the domestic 4–5 days the public calculator returns.

## 7. Quota

`GET /1.0/settings/limit`: start 387 → end display 490 (lagging) → my own count 114 requests (calls.log has the 114 lines). Public tariff/dictionary calls and the spec downloads cost nothing.

## 8. Index of evidence files (all in `k-raw/`)

`calls.log` (114 request lines, no secrets) · `limit-start.json`, `limit-end.json`, `limit-final.json` · `settings-masked.json` · `spec/views/*.html`, `spec/intl-spec-extract.txt` · `countries-spec.json`, `tariff-dict-country.json`, `dict-summary*.txt`, `v1-countries.json`, `dup-pairs.txt` · `tariff-matrix.json` + `tariff-*.json`, `pub/public-intl-1kg.json`, `tariff-obj-*.json` · `order-*.json` (create/get/edit/matrix) · `batch-create-intl.json`, `batch-undo-intl.json` · `doc-DE-*-forms.pdf`, `png/*.png` · `ledger.json`, `cleanup-delete*.json`, `verify-*.json`, `cleanup-proof.json`.

## Related

- [README](README.md) — the consolidated fact base
- [decisions.md](decisions.md) — item 11
