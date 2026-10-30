# Ozon Delivery — live write measurement (30.10.2026, s203)

One real order on the operator's delivery cabinet, approved by him («делай замер»). Cabinet balance 0 ₽ at the time.
Raw responses were kept in the session scratchpad and transcribed here; the label PDF is not committed.

| Step | Request | Result |
|---|---|---|
| Quote | `order/checkout`, 1 posting 300 g, 150×100×50 mm, declared 100 ₽, pickup point 5627 (Vyborg) | 200: delivery **101.00 ₽**, insurance 10.00 ₽, 6 days |
| Create | `order/create` with `Idempotency-Key: <uuid>` | 200: order `PE-P33E49P59E43-7EBP`, posting `18799YB60P46-077B`, status `created` |
| Same key again (+2 s) | identical body + same `Idempotency-Key` | 200: **the same order and posting numbers** — no second order (Q-9 answered: honoured) |
| Label before approve | `posting/label` | 400 `posting_incorrect_state`, «Этикетка доступна только после подтверждения постинга к отгрузке и до завершения приёмки.» |
| Approve | `posting/approve` | 200 with an empty body `[]`; status `forming` after 3 s and 13 s, `ready_for_shipping` after ~22 s. **No `NotEnoughBalance` on a 0 ₽ balance**; the cabinet showed no debt afterwards |
| Label after approve | `posting/label` | 200 `application/pdf`, `Content-Disposition: attachment; filename=posting_<n>_label.pdf`, 23 KB, 1 page (Q-4 answered: synchronous PDF bytes) |
| Status history | `posting/status-history` | newest first: `ready_for_shipping`, `forming`, `created` |
| Cancel | `posting/cancel` | 200 `[]`; still `ready_for_shipping` after 3 s, `canceled` after 18 s — **asynchronous** |
| Returns after cancel | `return/search` | empty: a posting cancelled **before handover** produces no return |
| Shipment methods | `shipment-method/search` | **403** with the August key (scope missing; new keys carry it) |

## `check-client` (Q-8)

| Phone | `can_be_delivered` |
|---|---|
| +79000000000 | true |
| +70000000000 | true |
| 89000000000 | true (format is lenient) |
| +79990000001 | **false** |
| +79012345678 | **false** |
| +7900000000 (10 digits) | false |

Not a «registered at Ozon» check: an all-zero number passes and ordinary-looking numbers are refused. Treat `false` as a
real, common outcome.

## New key, scopes and sandbox (30.10.2026, later the same night)

- A token carries **only the scopes requested** in the token call. A key issued with every box ticked still answers 403 on
  `shipment-method/search` when the request asks for the five August scopes only.
- Requesting a scope the key does not have fails the **whole** token call: 400 `invalid_scope`, «scope
  'delivery-api.arrival-pass' is not approved». The operator's new key had everything except `delivery-api.arrival-pass`.
  Scope names (from the OpenAPI): `delivery-api.{delivery,delivery-point,order,posting,return,shipment-method,dropoff-point,
  return-point,container,arrival-pass,all}`.
- With `delivery-api.shipment-method`: `shipment-method/search` 200 — id `1020005027823300`, name «Тестовый метод доставки
  до ПВЗ», status `active`, type `dropoff` with `dropoff_point_id`. A settings dropdown is possible.
- `dropoff-point/search` requires a `filters` object (400 without it).
- **Sandbox**: `https://api-seller-sandbox.ozon.ru/oauth/token` with the shared client_id and the sandbox secret → 200,
  scope `delivery-api.Admin` (after the same testcookie 307 as production). Every `api-delivery.ozon.ru` path tried
  (`delivery-point/list`, `delivery/check-client`, `posting/search`) → **404** there; Seller-API sandbox paths →
  403 «no access for this service» or validation errors; the sandbox token on the production delivery host → 401.
  **The sandbox does not emulate the Ozon Delivery API this plugin uses.**

## Related

- `decisions.md`, `api-diff.md`, `report.md`
