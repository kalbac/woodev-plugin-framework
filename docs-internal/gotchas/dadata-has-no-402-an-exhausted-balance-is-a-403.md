# gotcha: DaData has no 402 — an exhausted balance is a 403, indistinguishable by status from a bad key

**Namespace:** `[shipping/location]`
**Discovered:** s148 (2026-10-02), card #956

## What happened

The #956 brief told the worker to detect «out of money» by an HTTP 402 (or 429). Both guesses were wrong.
DaData's official response-code tables (dadata.ru/api/suggest/address/ and dadata.ru/api/clean/address/) say:

| Status | Meaning |
|---|---|
| 401 | no API key sent |
| **403** | unknown key, **unconfirmed e-mail**, **daily request limit exhausted**, **insufficient funds — top up the balance** |
| 429 | more than 20 requests/s per IP, or too many new connections per minute — a transient throttle |

No 402 is documented anywhere, and the 403 body format is not documented, so the status alone cannot tell the
causes apart. A Codex critic re-checked the same two pages and agreed.

## ❌ Wrong

```php
if ( 402 === $code ) { // never happens
    $this->record_out_of_money();
}
if ( 429 === $code ) { // a per-second throttle, not a quota signal
    $this->record_out_of_money();
}
```

## ✅ Correct

Treat a suggestions-host **403** as «DaData refuses this store» and name ALL its causes to the merchant (top up /
confirm the e-mail / check the keys). Ignore 429. If the exact cause matters, ask
`GET https://dadata.ru/api/v2/profile/balance` — that needs the Clean-API secret, which many stores do not have
(card #1060).

The framework's state lives in `Dadata_Api_Client` (`OPTION_ACCESS_DENIED`, set on the first suggestions 403,
cleared on the next suggestions 2xx) and the notice in `Shipping_Plugin::add_location_provider_access_denied_notice()`.

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
- [dadata-collapses-region-and-settlement-into-one-key](dadata-collapses-region-and-settlement-into-one-key.md) — the other DaData contract surprise
