# Gotcha: [framework/contracts] — a cross-provider `within` is handed over as COMPONENTS, never as a key
> Tags: location-provider, scoping, multi-provider | Session: s76

## What happens

With two providers live at once — CDEK holding the settlement, DaData answering the address level —
the address search leaves the browser carrying another provider's key:

```
/location/suggest?q=Тверская&level=address&country=RU&within=test-cdek%3A44
```

A `Locality_Key` is `provider_id:native_id`, so this looks like handing DaData an identifier it
cannot possibly resolve. It works anyway, and the suggestions come back correctly narrowed
(measured on the rig, 16.08.2026: labels came back city-relative, `ulitsa Tverskaya`, rather than
country-wide `Russia, Moscow city, ulitsa Tverskaya`).

Knowing WHY matters, because the wrong mental model ("`within` is compared as an opaque string")
leads to inventing a key-translation layer that is not needed.

## Root cause

`within` never reaches a provider as a key at all.

`Location_Controller::build_scope()` resolves it against the customer's own persisted chain
(`Location_Service::get_customer_chain()`), matching it to a REAL `Location_Record` — here the CDEK
settlement record. It then hands `Location_Scope::within( $record, $level )` to whichever provider
serves that level. DaData's own `build_locations_constraint()` reads
`Location_Scope::parent_components()` — the record's region/settlement NAMES — not its key.

So the handover between providers happens through **components**, and each provider only ever sees
identifiers from its own namespace. A provider that CAN use a native id (its own record's key)
prefers `parent_record()`; one looking at a foreign record falls back to the components that
`Location_Scope` guarantees are always present.

Two consequences worth holding on to:

1. Keys never mix. The settlement stays `test-cdek:44`, the address suggestions come back
   `dadata:…`, and neither provider is asked to parse the other's namespace.
2. The handover is only as good as the NAMES. Component names are locale-dependent (gotcha
   `a-locality-display-name-is-not-an-identifier`) — the rig has CDEK answering Cyrillic «Москва»
   while the DaData account speaks English, and the constraint still resolved. That is a measured
   data point about DaData's tolerance, not a guarantee for the next provider.

## Fix

Nothing to fix — this is the contract. What to avoid:

❌ Wrong — assuming a foreign `within` needs translating, and building a key map for it.

❌ Wrong — assuming a foreign `within` is silently dropped, and adding a "re-scope by hand" path.

✅ Correct — when a provider is handed a scope, read `parent_record()` first (it may be one of your
own records, with an exact native id) and fall back to `parent_components()` otherwise. A parent
record whose key belongs to another provider must be recognised as foreign and refused, not read as
an opaque string:

```php
[ $provider_id, $native_id ] = Locality_Key::parse( $parent_record->key() );

if ( self::PROVIDER_ID !== $provider_id ) {
	return null; // foreign — fall through to components, never guess
}
```

## Related

- [a-locality-display-name-is-not-an-identifier](a-locality-display-name-is-not-an-identifier.md) — why the component handover is not free
- [a-level-served-can-come-from-the-fallback-not-the-active-provider](a-level-served-can-come-from-the-fallback-not-the-active-provider.md) — how two providers end up serving one cascade in the first place
- [an-empty-domain-key-is-not-a-key](an-empty-domain-key-is-not-a-key.md) — the refusal discipline this builds on
