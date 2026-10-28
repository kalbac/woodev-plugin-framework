# Gotcha: [framework/payment-gateway] — A hosted gateway must distinguish pending notifications from declines

> Tags: payment-gateway, hosted, webhook, ipn, security | Session: s199, updated s201 (#1480)

## What happens

Before #1480, the default Hosted flow failed unpaid orders for intermediate provider states and verification exceptions.
Every response lookup also incremented and saved `retry_count`, changing the provider-facing reference on every webhook.
The 2.0.16 fix separates these paths; plugins still have to identify intermediate states explicitly.

## Root cause

- `process_transaction_response()` treated everything outside approved/held/cancelled as declined.
- The request handler's exception catch marked the order failed before acknowledging an invalid response.
- `get_order_with_unique_transaction_ref()` combined reference hydration with starting a new payment attempt.

## Fix

❌ **Wrong** — return false for all original outcome predicates on a provider's `inprogress` state. That still means declined
for legacy response implementations. Do not start an attempt from webhook validation or order lookup.

✅ **Correct** — implement `Woodev\Framework\Payment_Gateway\Api\Pending_Notification_Response` (which extends the original
payment-notification interface) and add the predicate alongside the existing outcome methods:

```php
public function transaction_pending(): bool {
    return in_array( $this->status, [ 'new', 'inprogress' ], true );
}
```

The base acknowledges pending through the protected `do_transaction_pending()` extension point (IPN: HTTP 200;
redirect-back: the return URL), without marking the order paid, held or failed. Existing responses that implement only
`Woodev_Payment_Gateway_API_Payment_Notification_Response` default to not pending and preserve their decline behavior.
Authentication and validation exceptions are redacted and logged when debug logging is enabled, then take the existing
invalid-request path without failing the order. Subclasses remain responsible for authenticating the provider request.

`get_order()` now reconstructs `unique_transaction_ref` from the existing `retry_count` without writing meta. The hosted
redirect/form entry points and direct `process_payment()` call protected `start_payment_attempt()` exactly once per new
payment request. This keeps the existing meta key and suffix convention (`42`, `42-1`, …). Custom payment entry points
must call that helper when starting a new attempt; `get_order()` alone no longer consumes a reference. Order hydration
filters run before the new attempt is started, so build provider payloads after `start_payment_attempt()` returns.

T-Bank's custom handler also implements provider-specific authentication, lookup and application transitions; this base
fix does not replace those responsibilities or change that plugin. Its original workaround is documented in the design §10.

## Related

- [T-Bank v2 plugin design](../specs/2026-10-27-tbank-v2-plugin-design.md) — §10 documents the original workaround.
- [handler-extraction-must-preserve-override-chain](handler-extraction-must-preserve-override-chain.md) — preserve subclass dispatch.
