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

## Operator answers to the spec's open questions (s203, 30.10.2026, «всё по рекомендациям, кроме п.1»)

These close O1–O8 of the spec and the coordinator's list; the overnight session treats them as binding.

| # | Topic | Decision |
|---|---|---|
| O1 | Product / version | **2.0.0 of the existing EDD product 58107** (the old «Ozon доставка» 1.0.3, a different API, dead for 4+ years, no real users). Keep ONLY the old **plugin folder name, main (entry) file name and download_id**. No migration from 1.0.3, no preserved option keys or shipping method ids. Old buyers can simply renew their licence. The overnight session reads the folder/main-file names from the 1.0.3 ZIP (operator link in the private Ozon repo notes) |
| O2 | EDD product | Existing download **58107**; nothing is published on it without the operator's word |
| O3 | Return barcode without an in-force licence | Allowed — explicit exception to the licence gate |
| O4 | Labels for several parcels | One «Этикетка» link per parcel in the order panel; no merged PDF |
| O5 | Write round R2 | Allowed: up to **10 real orders** on the operator's TEST cabinet overnight, each cancelled before handover |
| O6 | First real return R3 | After the first real handover (the balance top-up is in transit, up to 3 days); until then returns are built from the spec + fixtures |
| O7 | Pickup price before a point is chosen | Price for the nearest point, re-quoted after the choice |
| O8 | Error reporting | Yes — the GlitchTip project already exists (DSN in the private Ozon repo) |
| — | Postings per order | One posting per packed box; no manual split in the first release |
| — | Aggregate status | In transit while any posting is moving; delivered when all are terminal and at least one delivered (cancelled ones do not block); cancelled when all cancelled; a «частично» marker in the order panel |
| — | Declared value / insurance | Declared value = goods subtotal; insurance included in the buyer's price, not a separate line |
| — | Export | Manual button or automatic on order status (setting); create + approve as one action; cancel from admin until handover |
| — | Courier | Offered only with address-level coordinates (DaData); otherwise courier is hidden, pickup stays |
| — | Admin create-order wizard | Not in the first release; a framework card after the release |
| — | Settings | Own tab on the Woodev settings page: keys, shipment method dropdown (manual id when the key lacks `delivery-api.shipment-method`), «Проверить подключение» |
| — | Containers / arrival passes / drop-off management | Out of the first release |

## Related

- `report.md` — framework gap refresh against 2.0.16
- `api-diff.md` — Ozon API spec 30.10.2026 vs the August reference
- `write-measurement.md` — the live create/approve/label/cancel run
