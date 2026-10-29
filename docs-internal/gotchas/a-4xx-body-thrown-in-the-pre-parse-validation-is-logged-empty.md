# Gotcha: [api/logging] — A 4xx body thrown in `do_pre_parse_response_validation()` is logged empty

> Tags: api, logging, response-validation | Session: s202 (card #1509, workaround in woodev-vkredit)

## What happens

When a plugin API class throws from `do_pre_parse_response_validation()` on a 4xx answer, the request/response log shows the
response with an empty body. The bank's refusal reason — the one thing support needs — is lost.

## Root cause

`Woodev_API_Base::get_sanitized_response_body()` logs only a response that was successfully parsed. A body rejected before
parsing never reaches it.

## Fix

❌ **Wrong** — debug a refusal from the framework log alone.

✅ **Correct** — until #1509 is fixed in the framework, override `get_sanitized_response_body()` in the plugin's API client
and return the raw body with personal data masked (woodev-vkredit#36 masks e-mail and phone numbers; free-text refusals echo
the buyer's data). Remove the override once the framework logs the raw rejected body.

## Related

- [api layer topic](../gotcha-index/api.md) — neighbouring API gotchas
- [Hosted payment notifications (plugin-author traps)](../../.ai/skills/woodev-plugin-core/traps.md#hosted-payment-notifications)
