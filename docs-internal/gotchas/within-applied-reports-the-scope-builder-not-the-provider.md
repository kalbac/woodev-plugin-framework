# Gotcha: [shipping/location] — `within_applied` reports what the scope BUILDER decided, not what the provider honoured
> Tags: location, suggest, scope, observability | Session: s78 | Fixed: s106 (#358)

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

**FIXED (s106, #358).** A third, INDEPENDENT field — `scope_narrowing` — now carries what the
PROVIDER did with the parent, on both `/suggest` and `/list`. `within_status`/`within_applied`
are UNTOUCHED — no new value, no new meaning, exactly the same "read independently, never
derive one from the other" discipline `perform_suggest()`'s own docblock already states for
`within_status` vs. `within_applied` (a third stage needs a third independent field, not a new
value bolted onto an existing one).

`scope_narrowing` is one of `Location_Provider::NARROWING_EXACT` (the parent's own native
id/key was used), `NARROWING_DEGRADED` (narrowed by locale-dependent NAME components only —
weaker, can be silently defeated by a name mismatch), `NARROWING_NONE` (a parent was given but
could not be used at all — the silently-broken case above, now visible),
`NARROWING_UNREPORTED` (the provider never called {@see Location_Scope::report_narrowing()} —
the honest default for a third-party provider unaware of the contract, NEVER read as "did not
narrow"), or `NARROWING_NOT_APPLICABLE` (no parent to narrow by at all — computed by the
controller, never reported by a provider).

The provider reports itself via `Location_Scope::report_narrowing()`, on a scope the controller
stamps per-call via `Location_Scope::for_provider()` immediately before invoking `suggest()`/
`list_localities()` — a fresh, unfilled slot each call, because a D15 fallback chain can hand
the SAME scope to more than one provider, and a shared slot would recreate the "whose verdict is
this?" ambiguity this fix exists to remove. `report_narrowing()` never throws — an unknown
verdict, a second call, a call on a parentless scope, or a call on a scope never stamped via
`for_provider()` is a silent no-op plus a `_doing_it_wrong()` notice, because a provider's own
bookkeeping mistake here must read as a programming-error notice, never as a false "the provider
is down" (`Location_Controller` catches any `\Throwable` from `suggest()`/`list_localities()`
as an upstream 502).

`Dadata_Provider::build_locations_constraint()` and the CDEK/List rig fixtures'
`region_code_from_scope()`/`region_native_id_from_scope()` are the reference implementations —
see each for which branch reports which verdict. Both measured scenarios at the top of this
gotcha now read differently: DaData's cross-provider degrade-to-text-constraint case reports
`degraded`, the CDEK fixture's country-wide `suggest_settlements()` fallback reports `none` —
**neither provider's BEHAVIOUR changed**, only its self-report. Deliberately: banning the
cross-provider case would have broken the one that actually works.

## Related

- [an-empty-domain-key-is-not-a-key](an-empty-domain-key-is-not-a-key.md) — the same discipline
  from the other end: refusing to answer beats a plausible wrong answer
- [a-cross-provider-within-is-handed-over-as-components](a-cross-provider-within-is-handed-over-as-components.md)
  — why a foreign parent works at all, and why it works only by accident of the handover shape
- [a-level-served-can-come-from-the-fallback-not-the-active-provider](a-level-served-can-come-from-the-fallback-not-the-active-provider.md)
  — the other place where "who actually answered" is invisible from the outside
