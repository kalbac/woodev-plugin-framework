# A default provider without its key switches the whole location layer off — and its `required` field blocks the other providers' save

**Namespace:** `[shipping/location]`
**Found:** s195 (23.10.2026), support tickets 17071 / 17081 (CDEK 2.2.5.5 → 2.3.0.4, no DaData token). Card #1424.

## What happens

The `active_provider` setting defaults to `dadata` (`Location_Provider_Registry::DEFAULT_PROVIDER_ID`). A shop migrated from
v1 holds no DaData token, so DaData `is_configured()` is `false`, `get_active_provider()` used to return that unconfigured
provider, `Location_Service::resolve_provider_for_level()` returned `null` at every level, no customer location record was
written, and EVERY v2 carrier (CDEK, Russian Post, Yandex) hid all its methods — they all read that one record.

The documented way out («Провайдер локаций» = СДЭК) could not be saved either: the token field is declared
`'required' => true`, and its `show_if` keeps it visible under every provider that cannot serve `address` (CDEK cannot —
DaData is the address fallback). A `required` setting is validated whenever it is submitted/visible, and the client blocks Save
(`validateField()`), so «Обязательное поле.» fired under the CDEK provider.

## ❌ Wrong

```php
// 1. the default provider resolved even when it cannot work
$provider = $this->providers[ $active_id ] ?? $this->providers[ self::DEFAULT_PROVIDER_ID ] ?? null;
// 2. a provider-level requirement registered as an unconditional setting requirement
'token' => [ 'required' => true, /* … */ 'show_if' => [ 'operator' => 'in', /* dadata + non-address providers */ ] ],
```

## ✅ Correct

- Resolve the provider in force at READ time: an explicit registered non-default choice wins; an unset/unknown id or an
  unconfigured DaData resolves to DaData if configured, else the first configured plugin provider in registration order
  (`Location_Provider_Registry::resolve_effective_provider()`). Never write an option on a frontend request.
- Keep `'required' => true` in the provider's `get_settings_fields()` (the abstract `is_configured()` reads it) — the registry
  tags it `required_for_provider` and registers the setting as NOT required; `Location_Settings::validate_values()` demands it
  only while that provider is the one the save moves to. The admin select shows the provider in force
  (`Location_Settings::get_display_value()`).
- A plugin provider MUST answer `is_configured()` honestly: a wrong `true` hijacks a shop that has DaData, a wrong `false`
  leaves the fallback to nothing.

## Related

- [a-multi-carrier-rig-hides-a-plugin-that-never-opened-the-location-layer](a-multi-carrier-rig-hides-a-plugin-that-never-opened-the-location-layer.md) — the same fleet-wide layer
- [a-level-served-can-come-from-the-fallback-not-the-active-provider](a-level-served-can-come-from-the-fallback-not-the-active-provider.md) — the per-level D15 fallback chain this sits in front of
- [settings-sensitive-secret-empty-skip-is-client-side](settings-sensitive-secret-empty-skip-is-client-side.md) — why an untouched empty secret is never sent
