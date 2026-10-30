# Ozon Delivery v2 plugin — brainstorm decisions (s203, 30.10.2026)

Binding input for the design spec. Each line is the operator's answer in s203; the evidence is in this folder.

| # | Question | Decision |
|---|---|---|
| 1 | Wait for the framework? | **No.** Build on framework 2.0.16; the SP-5 blocker of August is obsolete (`report.md`). |
| 2 | First-release delivery types | **Pickup points + parcel lockers + courier** (option «б»). Courier needs address-level coordinates and a persisted export snapshot (`report.md` G-7). |
| 3 | One order = 1..100 postings | **The plugin owns the postings** (option «а»): one row per order in the framework orders table with an honest aggregate status (e.g. partially delivered), the posting list / statuses / labels / cancel live in the plugin's own order UI. No framework multi-shipment change. |
| 4 | Returns | **In the first release** (option «а»): return status, current placement, return barcode on demand (it expires — never stored). |
| 5 | Live write measurement | **Done 30.10.2026** — `write-measurement.md`. |
| 6 | Phone-dependent availability (`check-client`) | **Ask the phone first** (option «а»): Ozon is not offered (or shown as «укажите телефон для расчёта») until the phone is entered; re-rate on phone change; re-check at placement. A refusal is a real case on real-looking numbers — the buyer must see why Ozon disappeared. Classic and block checkout. |
| 7 | Credentials | Alive. A new key with ALL scopes is being issued by the operator (the old one lacks the new `shipment-method` scope → 403). |
| 8 | Containers, arrival passes, drop-off point management | **Out of the first release.** Revisit if the first real handover shows a shipment method cannot be handed over without a container. |
| — | Admin «create order» wizard for Ozon | Not in the first release unless the spec finds a plugin-side way; the shared wizard does not pass the buyer phone (`report.md` §2 item 1). |
| — | Sandbox | `https://api-seller-sandbox.ozon.ru` emulates the **Seller API** «Ozon Доставка для продавцов» methods (`/v2/delivery/checkout`, `/v2/order/create`, `/v1/delivery/point/list`, `/v1/sandbox/posting/status/change`…), not the `api-delivery.ozon.ru` methods this plugin uses. Whether the sandbox secret also works for the delivery API is to be measured once the operator enters the new key. |

## Related

- `report.md` — framework gap refresh against 2.0.16
- `api-diff.md` — Ozon API spec 30.10.2026 vs the August reference
- `write-measurement.md` — the live create/approve/label/cancel run
