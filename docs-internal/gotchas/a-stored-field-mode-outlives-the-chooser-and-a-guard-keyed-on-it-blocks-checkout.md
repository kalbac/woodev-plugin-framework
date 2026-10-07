# Gotcha: [shipping/location] A stored field mode outlives the chooser — a server guard keyed on it alone blocks the whole checkout
> Tags: location, checkout, guard, ajax-select2 | Session: s159

## What happens
`Checkout_Handler::guard_custom_settlement()` (#531) rejected a typed city with no selected record whenever custom settlements were disallowed and the stored settlement mode was `ajax-select2` — even with no provider token. There `Checkout_Config::build()` omits the `location` block, the buyer has plain inputs and no way to pick a record, and the guard runs on EVERY classic checkout submit, so the whole checkout (any carrier, free shipping, pickup) was blocked. It became reachable the moment a carrier declared `Field::source_location('settlement')` (CDEK, #1148), because that gave the guard a field to inspect.

## Root cause
The stored mode (`field_mode_settlement`) is a saved merchant setting; it survives a removed/failed token and a country the provider does not cover. "Is the chooser shown" is a different question, answered by `Location_Service::is_active()` plus `provider_for_level()` per country — the same data `Checkout_Config` publishes. A server check that demands a picked record must ask the second question, not the first.

## Fix
❌ wrong — trust the stored mode:

```php
if ( MODE_AJAX_SELECT2 !== $service->get_field_mode_settlement() ) { return true; }
// ... demands a record, although the buyer cannot pick one
```

✅ correct — also stand down when the chooser cannot operate (one predicate, `Location_Service::is_level_chooser_available( $level, $country )`):

```php
if ( ! $service->is_level_chooser_available( Location_Record::LEVEL_SETTLEMENT, $country ) ) { return true; }
```

Any new server-side check that requires a chooser pick must go through that predicate.

## Related
- [a-level-served-can-come-from-the-fallback-not-the-active-provider](a-level-served-can-come-from-the-fallback-not-the-active-provider.md) — a level's availability is per provider chain and country, not per stored setting
- [the-three-location-field-modes-and-their-russian-labels](the-three-location-field-modes-and-their-russian-labels.md) — what `ajax-select2` is
