# Gotcha: [shipping/location] — a served level can come from the FALLBACK provider, not the active one
> Tags: location-provider, arbitration, D15 | Session: s76

## What happens

The store's active location provider is CDEK, which serves `region` and `settlement` and has no
street data at all. The checkout config still reports:

```json
"levels": { "RU": { "region": true, "settlement": true, "address": true } }
```

Expecting `address: false` here — "the active provider does not do addresses, so the layer does not
either" — is wrong, and the whole test scenario built on that expectation (#343 scenario A) does not
reproduce.

## Root cause

`levels[country][level]` describes what **the layer** can answer, not what the active provider can.
D15's chain walk (`Location_Service::provider_for_level()`) resolves a provider PER LEVEL: chosen
provider first, then the bundled fallback. With CDEK active and DaData still configured, the address
level is served — by DaData — and the layer answers honestly that it is.

That is the design working, not a leak. The consequence to remember is that **"the active provider
lacks level X" and "level X is unserved" are different statements**, and only the second one is what
`levels` reports.

## Fix

To reach a genuinely unserved level, remove every provider that serves it — or pick a country where
none does.

❌ Wrong — switching the active provider and expecting the level to go dark:

```bash
wp option update woodev_location_active_provider test-cdek
# levels.RU.address is STILL true — DaData is configured and answers that level
```

✅ Correct — either unconfigure the fallback:

```bash
wp config delete WOODEV_TEST_DADATA_TOKEN --type=constant
wp option delete woodev_location_token          # the seeder re-seeds from the constant, so remove both
# levels.RU.address -> false
```

✅ …or use a country no configured provider serves at that level (measured on the rig: DaData has
street data for RU/BY/KZ/UZ only, so `AM`, `AZ`, `KG`, `TJ`, `TM` already report
`address: false` with DaData active and configured).

Note the seeder interaction in the first form: `Woodev_Test_Credential_Seeder::maybe_seed()` writes
the option back from the wp-config constant whenever the option is empty, so deleting only the
option restores it on the very next page load.

## Related

- [one-identity-two-roles-one-must-refuse-the-other-must-fall-back](one-identity-two-roles-one-must-refuse-the-other-must-fall-back.md) — the same "who answers this" question one layer down
- [an-invented-fixture-tests-your-assumptions-not-the-carrier](an-invented-fixture-tests-your-assumptions-not-the-carrier.md) — why this was found against a live second provider rather than a fixture
- [a-capability-flag-that-removes-a-ui-layer-silences-every-branch-that-reported-through-it](a-capability-flag-that-removes-a-ui-layer-silences-every-branch-that-reported-through-it.md) — a neighbouring "the flag does not mean what it looks like" trap
