# Gotcha: [api/error-boundary] A refusal sent as 5xx can reach the browser with a replaced body, and `apiFetch` hides the status

**Discovered:** card #1979 (27.11.2026), seen on a shop behind a bot filter.

## What happens

The orders page showed only «Не удалось выполнить действие.» although the server sends the carrier's reason in every
branch. The replacement of the body on the way was not measured; it is the only path found that leaves the answer
without a `message`.

## Root cause

- An ordinary carrier refusal was answered `502`. That is a gateway status: reverse proxies and bot filters in front of
  a shop may replace the body of a 5xx with their own.
- With the default `parse: true`, `apiFetch` rejects with the parsed body only. The HTTP status is lost, so the client
  cannot say that the answer was not the plugin's. A non-JSON body becomes the generic «The response is not a valid
  JSON response.».
- With `parse: false` it rejects with the raw `Response`, but then it no longer renews an expired nonce: the check
  reads `error.code`, which a `Response` does not have.

## Fix

```php
// ❌ the carrier said no, answered as a broken gateway
return new \WP_Error( 'my_carrier_refused', $reason, [ 'status' => 502 ] );

// ✅ a 4xx for a refusal; 502 only when the call threw or answered nothing usable
return new \WP_Error( 'my_carrier_refused', $reason, [ 'status' => Orders_Controller::CARRIER_REFUSAL_STATUS ] );
```

On the client, read an action's answer raw and name the status when there is no message
(`actionRequest()` in `src/shipping-orders-page/rest.ts`). On `rest_cookie_invalid_nonce` it repeats the request with
the default parsing, so `apiFetch` can renew the nonce.

## Related

- [a-carrier-refusal-is-not-an-api-exception](a-carrier-refusal-is-not-an-api-exception.md) — where a refusal is told from a failure on the carrier side
- [wordpress-api-fetch-appends-locale-user-so-a-strict-query-validator-rejects-every-call](wordpress-api-fetch-appends-locale-user-so-a-strict-query-validator-rejects-every-call.md) — another `apiFetch` default
