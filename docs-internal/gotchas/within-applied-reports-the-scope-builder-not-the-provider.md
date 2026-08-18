# Gotcha: [shipping/location] — `within_applied` reports what the scope BUILDER decided, not what the provider honoured
> Tags: location, suggest, scope, observability | Session: s78

## What happens

`GET /location/suggest` answers with `within_applied`. It reads as "the search was narrowed to
the parent you asked for". It is not that. It is `true` whenever `build_scope()` managed to turn
the `within` key into a `Location_Scope` — regardless of whether the provider then used it,
partially degraded it, or discarded it outright.

Measured on the rig (s78), a chain record from an inactive provider (`test-cdek:44`) handed to
DaData: the response reported `within_applied: true` while DaData had silently dropped the
record's key and `raw` payload and fallen back to a plain TEXT constraint on the region/city
names. In the CDEK fixture's `suggest_settlements()` the same shape reports `within_applied:
true` while the search runs country-wide, completely unnarrowed.

So the one field an integrator would reach for to detect "my scope was ignored" is exactly the
field that cannot detect it.

## Root cause

```php
// class-location-controller.php — perform_suggest()
'within_applied' => $scope->has_parent(),
```

`$scope` is the object `build_scope()` returned. `has_parent()` answers "does this scope carry a
parent record", which is a fact about the CONTROLLER's own work. Honouring the scope happens
later and elsewhere — inside the provider, which is free to use the parent, degrade it, or
ignore it, and reports nothing either way:

- `Dadata_Provider::build_locations_constraint()` uses `raw()` FIAS ids only when
  `self::PROVIDER_ID === $parent->provider_id()`; a foreign parent silently degrades to
  `region`/`city` name text.
- The CDEK fixture's `region_code_from_scope()` returns `null` on a foreign prefix; `list_localities()`
  then returns `[]`, but `suggest_settlements()` simply SKIPS its filter and answers country-wide.

The two honest states — "narrowed" and "asked to narrow but could not" — are collapsed into one.

## Fix

❌ Wrong — treating the flag as a provider verdict:

```php
// "the provider scoped my search"  — it says no such thing
if ( $body['within_applied'] ) { /* trust the results are inside the parent */ }
```

✅ Correct — until the contract is fixed, the flag answers only the controller's half; prove
narrowing by MEASURING against a control in the same run:

```
GET /location/suggest?level=address&country=RU&q=Тверская&within=<key>   -> 8 results
GET /location/suggest?level=address&country=RU&q=Тверская                -> 10 results   (CONTROL)
```

A differing result set is evidence of narrowing. An identical one means the scope was dropped —
whatever `within_applied` claims.

The real repair is for the provider seam to report back what it did with the parent, so
"narrowed" and "could not narrow" stop sharing a value. Tracked with #333.

## Related

- [an-empty-domain-key-is-not-a-key](an-empty-domain-key-is-not-a-key.md) — the same discipline
  from the other end: refusing to answer beats a plausible wrong answer
- [a-cross-provider-within-is-handed-over-as-components](a-cross-provider-within-is-handed-over-as-components.md)
  — why a foreign parent works at all, and why it works only by accident of the handover shape
- [a-level-served-can-come-from-the-fallback-not-the-active-provider](a-level-served-can-come-from-the-fallback-not-the-active-provider.md)
  — the other place where "who actually answered" is invisible from the outside
