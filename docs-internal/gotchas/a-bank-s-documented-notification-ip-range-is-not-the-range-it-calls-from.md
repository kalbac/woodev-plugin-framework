# Gotcha: [framework/payment-gateway] — A bank's documented notification IP range is not the range it calls from

> Tags: payment-gateway, hosted, webhook, ip-allowlist | Session: s202 (T-Bank, woodev-vkredit#37)

## What happens

A hosted-payment plugin that authenticates bank notifications by source IP (no API password configured) answers `403` to the
real bank. The bank keeps retrying: 17 hits in about 13 minutes on the T-Bank test shop. The order never leaves its waiting
status although the buyer paid.

## Root cause

T-Bank documents `91.194.226.0/23`, but both its demo and its live contour send notifications from `212.233.80.0/22`
(RIPE `RU-TINKOFF-20000828`, TBANK JSC; seen from `.81.225`, `.81.226`, `.83.224`, user agent Java-http-client). A range
copied from the integration PDF is a claim, not a measurement.

## Fix

❌ **Wrong** — trust only the documented range and call the gateway done after a rig test that fakes the source address.

✅ **Correct** — trust the documented range AND the one measured on a real notification (woodev-vkredit#37 added
`212.233.80.0/22`), and treat the first live notification as part of the acceptance. When a signature or an API password is
available, prefer it over the address; the address list is the fallback for shops that have not set a password.

## Related

- [a hosted gateway's default response flow](a-hosted-gateway-s-default-response-flow-fails-orders-it-does-not-understand.md) — the same notification path
- [Hosted payment notifications (plugin-author traps)](../../.ai/skills/woodev-plugin-core/traps.md#hosted-payment-notifications)
