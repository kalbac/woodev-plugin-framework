# Gotcha: [rig/fixtures] — the default-locality option stores a whole RECORD, not a key
> Tags: rig, location, options, default-locality | Session: s106

## What happens

`CURRENT-STATE.md`'s rig table lists the option as

| Option | Value |
|---|---|
| `woodev_location_default_locality_record` | `test-cdek:44` (Москва) |

which reads as "the option holds the key `test-cdek:44`". It does not. It holds the **entire
serialised `Location_Record`**, and `test-cdek:44` is merely the `key` field inside it:

```json
{"key":"test-cdek:44","provider_id":"test-cdek","level":"settlement","country":"RU",
 "region":{"name":"Москва","type":""},…,"raw":{…},"ancestors":["test-cdek:r81"]}
```

A probe that reads the option and hands it to something expecting a key gets a fatal whose message
is genuinely confusing, because the whole JSON blob is echoed back as the "key":

```
Uncaught InvalidArgumentException: …::resolve_key(): key "{"key":"test-cdek:44",…}"
belongs to provider "{"key"", not "test-cdek"
```

The parser split the JSON on `:` and decided the provider id was `{"key"`.

## Root cause

The option is written by the settings layer as a full record precisely so the default locality
survives a provider outage — resolving a key would need the provider to be reachable at the moment
the default is applied, which is exactly when it may not be. The doc table abbreviated it to its
most recognisable field, and the abbreviation reads as the value.

## Fix

❌ Wrong — treating the option as a key:

```php
$record = $provider->resolve_key( get_option( 'woodev_location_default_locality_record' ) );
```

✅ Correct — decode it and rebuild the record; no provider call is needed at all:

```php
$stored = get_option( 'woodev_location_default_locality_record', '' );
$array  = is_string( $stored ) ? json_decode( $stored, true ) : $stored;

if ( ! is_array( $array ) || ! isset( $array['key'] ) ) {
	return; // not configured, or not a record
}

$record = \Woodev\Framework\Shipping\Location\Location_Record::from_array( $array );
```

The same shape appears in the checkout boot config under `location.defaultLocality.record` — also a
full record, not a key.

## Related
- [the-geoip-default-locality-cannot-resolve-on-a-local-rig](the-geoip-default-locality-cannot-resolve-on-a-local-rig.md)
  — the other half of the default-locality machinery, and the other way it reads as broken
- [the-cdek-fixture-credentials-are-not-the-option-they-look-like](the-cdek-fixture-credentials-are-not-the-option-they-look-like.md)
  — the same lesson about this rig: read the option off the container, never off a doc
- [an-empty-domain-key-is-not-a-key](an-empty-domain-key-is-not-a-key.md) — why a key is parsed
  strictly enough to produce that error message
