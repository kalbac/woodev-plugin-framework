# The operator's production Yandex Delivery account cannot create requests — test export on the test contour

> Namespace: `shipping/*` — added session 174 (2026-10-12).

## The trap

The production token in `.wp-env.override.json` (`WOODEV_YANDEX_PROD_TOKEN`) belongs to the operator's
**test-only** account. Read calls work (`location/detect`, `pickup-points/list`, `pricing-calculator`,
`offers/info`, even `offers/create`), so the account looks fully usable. Every call that books a
request — `offers/confirm`, `request/create` — returns HTTP 404:

```json
{"code": "invalid_payment_method", "message": "Ваш корпоративный счёт больше не активен"}
```

Reactivation needs a ≥1000 RUB top-up from a bank account; the operator will not do it for tests (s174).

## ✅ Correct

- Price, geo and offer measurements: production token (live data, the test contour serves Moscow only).
- Creating / confirming / cancelling requests: the **test contour** (`https://b2b.taxi.tst.yandex.net`,
  public token `WOODEV_YANDEX_TEST_TOKEN`, Moscow addresses) or a stub server in CI.
- Do not read a 404 `invalid_payment_method` as a body defect — the same bodies pass `offers/create`.

## Related

- [Export-path measurement](../research/2026-10-12-yandex-export-path/README.md)
- [Yandex v2 spec](../specs/2026-10-12-yandex-v2-plugin-design.md) — §8.6 acceptance contour
