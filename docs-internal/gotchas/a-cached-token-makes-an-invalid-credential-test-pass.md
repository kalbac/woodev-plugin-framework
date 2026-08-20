# gotcha: a cached token makes an invalid-credential test pass

**Namespace:** `[testing/measurement]`
**Discovered:** s82 (2026-08-20)

## What happens

To answer "what does the merchant see when the API keys are wrong?", the obvious move is to write
garbage into the credential fields and look. That measurement is **invalid by default**, and it
fails in the direction that hides the bug: everything keeps working.

Measured on the rig in s82. CDEK's fixture provider was given deliberately wrong
`cdek_client_id` / `cdek_client_secret`, the page was reloaded, and the location picker happily
returned «Москва, Россия» — real, correctly-shaped results. The obvious conclusion ("wrong keys are
handled gracefully") was wrong twice over: nothing was handled, and nothing had been tested.

The provider caches its OAuth token:

```php
private const TOKEN_TRANSIENT = 'woodev_test_cdek_token';
…
set_transient( self::TOKEN_TRANSIENT, $token, $ttl );
```

The token issued under the OLD, correct keys was still alive. Every request rode it. The new keys
were never presented to the carrier at all, so the failure path was never entered.

After `wp transient delete woodev_test_cdek_token`, the same probe answered «Ничего не найдено» —
and that answer is what exposed the actual defect (a failed request is indistinguishable from an
empty match set; issue #405).

## Why this is not specific to CDEK

Every carrier API worth integrating issues a token with a TTL, and every sane client caches it.
Yandex, Pochta and DaData clients in this fleet all cache something. So the trap is structural:
**a credential test that does not invalidate the cache tests the cache, not the credential.**

It is also silent in the worst way. A test that fails loudly gets investigated; this one passes and
reports "handled correctly", which is exactly the sentence nobody re-checks.

## ❌ Wrong

```bash
# set garbage keys, reload, look
wp option update <carrier settings> '…garbage…'
# → results still come back → "wrong keys are handled"
```

## ✅ Correct

```bash
wp option update <carrier settings> '…garbage…'
wp transient delete woodev_test_cdek_token     # and any list/region caches the layer keeps
# only NOW is the next request an actual test of the new credentials
```

Before trusting a credential measurement, ask what the client caches — the token, and often the
enumerated data too (this provider also caches `woodev_test_cdek_regions_<country>`). Clear all of
it, or the second half of the answer is stale as well.

## The general rule

This is the credential-shaped instance of a rule this repo has already paid for twice:
**a measurement you did not confirm reached the code under test proves nothing.** The other two
instances are a mutation that never applied, and a gate measured where it could not fire.

## Related

- [[a-mutation-you-did-not-confirm-applied-proves-nothing]] — same rule, mutation-testing shape
- [[measure-a-gate-where-the-gate-can-actually-fire]] — same rule, precondition shape
- [[perl-multiline-mutation-silently-misses-crlf-files]] — a silent no-op that read as evidence
