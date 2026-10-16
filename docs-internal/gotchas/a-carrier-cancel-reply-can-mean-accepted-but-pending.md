# Gotcha: [shipping/cancel] — a carrier «cancel» reply can mean accepted-but-pending; only a completed cancel may run the destructive success path

> Tags: shipping, cancel, carrier-api, state | Session: s184

## What happens

Yandex `request/cancel` answers `CREATED` (accepted, not yet performed), `SUCCESS`, `ERROR`, or something malformed. Only a
completed cancel may clear the stored request id and run the framework's destructive success path; treating `CREATED` (accepted,
still pending) as done loses the handle to a request that is still live (yandex#108).

## Root cause

«The call returned 200» is a transport fact. Whether the shipment is actually cancelled is a domain fact in the body, and some
carriers cancel asynchronously.

## Fix

❌ `if ( ! is_wp_error( $reply ) ) { $this->clear_request_id( $order ); }`

✅ Only the carrier's explicit completed state (`SUCCESS`) clears the id and reaches the framework's success path. `ERROR`,
`CREATED` and malformed bodies are FAILURES (keep the id, record the failure). When the status poller later sees `CANCELLED`,
it clears the failure marker (`Carrier_Cancel::FAILED_META` in the Yandex plugin) and finishes the job.

## Related

- [a-status-change-made-for-the-carrier-s-news-must-not-reach-order-automation](a-status-change-made-for-the-carrier-s-news-must-not-reach-order-automation.md) — the other half of the cancel flow
- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
