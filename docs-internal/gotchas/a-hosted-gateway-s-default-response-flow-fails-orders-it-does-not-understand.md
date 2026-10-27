# Gotcha: [framework/payment-gateway] — A hosted gateway's default response flow fails orders it does not understand

> Tags: payment-gateway, hosted, webhook, ipn, security | Session: s199

## What happens

A hosted payment plugin that keeps `Woodev_Payment_Gateway_Hosted::handle_transaction_response_request()` turns every
notification it cannot classify into a **failed order**. A provider's intermediate status (T-Bank's `new`, `inprogress`), a
notification whose validation throws, or a forged POST that names a real pending order all end in `mark_order_as_failed()`.
And every webhook writes a meta row, because the default flow calls the gateway's `get_order()`.

## Root cause

- `process_transaction_response()` knows three outcomes — approved/held, cancelled, and **everything else → failure**
  (`woodev/payment-gateway/class-payment-gateway-hosted.php` lines ~516–552: `do_transaction_failed_result()` +
  `do_transaction_failed()`). A provider state that means "nothing happened yet" has no branch.
- The `catch ( Woodev_Payment_Gateway_Exception )` of `handle_transaction_response_request()` (~lines 387–406) calls
  `mark_order_as_failed()` whenever the order still needs payment — so an *unauthenticated* request that makes validation throw
  (wrong amount, missing field) fails the buyer's order.
- `get_order_from_response()` → `get_order()` → `get_order_with_unique_transaction_ref()`
  (`woodev/payment-gateway/class-payment-gateway.php` ~line 2005) increments and saves `retry_count` on **every** call, so the
  `unique_transaction_ref` changes per call and is useless as a stable provider-side order number.

T-Bank v1 1.7.0 inherited all three (its webhook carried no signature; the status came from an `Info` re-query, and an `Info`
error or an intermediate status went down the failure path).

## Fix

❌ **Wrong** — implement only `get_transaction_response()` and the response's `transaction_*()` predicates, and let the base
class decide:

```php
protected function get_transaction_response( $request_response_data ) {
    return new My_IPN_Response( file_get_contents( 'php://input' ), $this ); // base flow: unknown → failed
}
```

✅ **Correct** — override the entry point, own the outcome of every state, and never let an exception reach the base catch:

```php
public function handle_transaction_response_request() {
    $result = $this->webhook_handler->handle( file_get_contents( 'php://input' ), $_SERVER['REMOTE_ADDR'] ?? '' );
    status_header( $result->http_status() ); // 200 handled/ignored, 503 = provider should retry
    exit;
}
```

Inside, map each provider state explicitly (intermediate → no change), look the order up with `wc_get_order()` / an HPOS meta
query instead of the gateway's `get_order()`, and store the provider order number yourself. Design reference: the T-Bank v2
spec §10.

## Related

- [T-Bank v2 plugin design](../specs/2026-10-27-tbank-v2-plugin-design.md) — §10 is the handler built around this trap.
- [handler-extraction-must-preserve-override-chain](handler-extraction-must-preserve-override-chain.md) — the other override trap in the same module.
