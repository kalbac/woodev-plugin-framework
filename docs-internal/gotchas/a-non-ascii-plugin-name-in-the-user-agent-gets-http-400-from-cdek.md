# Gotcha: [api/http-headers] — A merchant-facing plugin name outside ASCII in the User-Agent gets HTTP 400 from CDEK
> Tags: api/http-headers, shipping/cdek | Session: s160

## What happens

The CDEK plugin was renamed «СДЭК для WooCommerce» (operator decision, s160). Every CDEK API call except
`/oauth/token` then answered **HTTP 400 «Bad Request»**: the sender-city search said «No matches found», and with a
saved sender city the CDEK methods vanished from both checkouts. Every unit test stayed green — they stub the transport.

## Root cause

`Woodev_API_Base::get_request_user_agent()` built the product token from `get_plugin_name()` with spaces dasherized,
so the header became `СДЭК-для-WooCommerce/2.3.0.0 (WordPress/7.1)`. A header value outside ASCII is not valid HTTP,
and CDEK's gateway rejects it. Measured on the test contour with the same token: no UA → 200, a Latin UA → 200, the
Cyrillic UA → 400; the token endpoint is not affected, which makes it look like an auth or data problem.

## Fix

❌ Use a merchant-facing (translatable, renameable) string as a protocol token.

✅ The product token never comes from the plugin name. Since s175 (#1248, operator decision 13.10.2026)
`get_user_agent_product()` returns `woodev-<get_id_dasherized()>` — `woodev-edostavka/2.3.0.0 (…)` — and uses the id
as is when it already contains `woodev` (case-insensitive); an empty id gives `woodev-plugin`. The s160 fix (name when
it is a valid RFC 9110 token, else the bare id) is superseded. A test double of the plugin must answer
`get_id_dasherized()`.

## Related

- `woodev/api/class-api-base.php` (`get_user_agent_product()`), `tests/unit/Api/ApiBaseUserAgentTest.php`
- [a-mocked-provider-proves-the-mock-not-the-contract](a-mocked-provider-proves-the-mock-not-the-contract.md)
