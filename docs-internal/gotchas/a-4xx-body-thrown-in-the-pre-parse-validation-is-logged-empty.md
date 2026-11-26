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

✅ **Correct** — from framework 2.1.28 (#1509) `get_sanitized_response_body()` logs the raw rejected body run through
`redact_secret_request_body()` when no response was parsed, so no override is needed. Secret-named keys are masked;
buyer personal data (e-mail, phone) echoed in a free-text refusal is NOT masked by that function. Plugins on an older
framework still need the override from woodev-vkredit#36, which masks those too, until they update.

## Related

- [api layer topic](../gotcha-index/api.md) — neighbouring API gotchas
- [Hosted payment notifications (plugin-author traps)](../../.ai/skills/woodev-plugin-core/traps.md#hosted-payment-notifications)
