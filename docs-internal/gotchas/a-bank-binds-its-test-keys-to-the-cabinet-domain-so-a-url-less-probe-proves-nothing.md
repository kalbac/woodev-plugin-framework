# Gotcha: [framework/payment-gateway] — A bank binds its test keys to the cabinet's site domain, so a URL-less probe proves nothing

> Tags: payment-gateway, hosted, test-keys, acceptance | Session: s202 (T-Bank)

## What happens

`create` / `create-demo` answers HTTP 400 as soon as `successURL`, `failURL` or `returnURL` point at a domain other than the one
registered in the bank's cabinet. The T-Bank test keys are bound to `pochta.wootest.ru`, so the same keys fail on any other rig
or demo site.

## Root cause

The bank validates the return URLs against the cabinet's site domain. A probe that sends no URLs (R1 in the T-Bank
acceptance) passes, so it cannot show that the keys work on the shop's domain.

## Fix

❌ **Wrong** — take a green URL-less request as proof the keys work, or ask the bank's support (answers take years).

✅ **Correct** — run the acceptance on the domain the keys are bound to, send the real return URLs, and say in the brief
which domain a key belongs to. Ask the bank for cabinet access or a domain change (pochta.wootest.ru → wootest.ru) when the
shop moves.

## Related

- [a bank's documented notification IP range](a-bank-s-documented-notification-ip-range-is-not-the-range-it-calls-from.md) — the other measurement-only fact about the same bank
- [Hosted payment notifications (plugin-author traps)](../../.ai/skills/woodev-plugin-core/traps.md#hosted-payment-notifications)
