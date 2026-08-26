# A mocked collaborator proves the mock, not the contract

**Namespace:** `[testing/unit]` · **Discovered:** s96 (2026-08-26), on round 1 of #551

## What happens

A unit test that mocks an external collaborator can only assert what the MOCK does. If the mock
encodes the very assumption the change rests on, the test passes precisely because the assumption
was never tested. The suite goes green over a feature that does not work at all.

## The worked example

#551 needed the region key for a settlement whose region came from the store's default locality.
The implementation asked the provider: `resolve_key( $ancestor_key )`, kept the answer only if its
`level()` was `region`, cached it, and never cached a throw. Six unit tests were written, **every
one of them falsified** (mutation applied, watched red, restored, watched green). `composer check`
green at 2826/6913/66. All 19 CI jobs green.

Measured on the rig against the LIVE provider:

```
get_customer_chain('RU') => settlement, region
  settlement key=test-cdek:44   ancestors=["test-cdek:r81"]
  region      key=test-cdek:r482  label=Галисия      <-- SPAIN
```

```
resolve_key('test-cdek:r81') => test-cdek:r482 | Галисия
resolve_key('test-cdek:r82') => test-cdek:r482 | Галисия     <-- same wrong answer for a different key
resolve_key('test-cdek:44')  => test-cdek:44   | Москва       (correct)
```

Shipping it would have been **worse than the bug it fixed**: `within=test-cdek:r482` scopes every
settlement search to Galicia and returns nothing at all.

The code was not wrong. The provider was — for REGION-level keys only (see
[the-fixture-docblock-asserted-an-api-parameter-that-does-not-exist](the-fixture-docblock-asserted-an-api-parameter-that-does-not-exist.md)).
The mock returned a well-formed region record, so nothing in the suite could see it.

## Why falsification did not save us

Falsifying a test proves the test watches its own production line. It says nothing about whether
the line is built on a true premise. Both disciplines are needed and neither substitutes for the
other:

| Question | Answered by |
|---|---|
| Does this test actually watch this code? | falsify it — break the line, watch it redden |
| Is the thing this code believes about a collaborator TRUE? | measure the real collaborator |

## ✅ Correct

- **Where our code meets someone else's contract — a provider, a carrier API, WooCommerce — a green
  unit suite is not sufficient evidence. Measure the real thing once.** A single `wp eval-file`
  probe against the rig took two minutes and was decisive.
- **Do not let the worker's own rig measurement be the only one.** Round 2 reported a correct
  rig result; the coordinator re-ran it with its OWN probe and only then believed it. Round 1 had
  also reported success.
- **Add the invariant as a guard in production code, not only in a test.** #551's fix carries:

  ```php
  // Never surface a region the settlement does not actually descend from,
  // regardless of which path produced it.
  return null !== $region && in_array( $region->key(), $ancestors, true ) ? $region : null;
  ```

  That one line rejects Галисия on its own, with no test and no working provider — it turns "the
  collaborator is honest" from an assumption into something the code checks.

## Related

- [the-fixture-docblock-asserted-an-api-parameter-that-does-not-exist](the-fixture-docblock-asserted-an-api-parameter-that-does-not-exist.md) — the provider defect this hid
- [a-worktree-silently-skips-five-contract-tests](a-worktree-silently-skips-five-contract-tests.md) — the other way a green suite is not the suite you think
- [the-cdek-fixture-credentials-are-not-the-option-they-look-like](the-cdek-fixture-credentials-are-not-the-option-they-look-like.md) — the other s95/s96 case of a measurement that measured the wrong thing
