# To sample a provider's settlements for a region, scope the LIST — `suggest()` by name returns homonyms

**Namespace:** `[shipping/location]`
**Found:** s92 (25.08.2026).

## The trap

Seeding the popular-settlements table for the regions «Москва» and «Санкт-Петербург», s92 called
`suggest( 'Троицк', Location_Scope::for_country( 'RU', 'settlement' ) )` and enrolled `$hits[0]`.
That row landed with region **Мордовия** — there are many Троицк's, and the provider ranks by its
own relevance, not by the region you had in mind.

Worse, the same method produced a **false conclusion about the data**: «Зеленоград» and «Щербинка»
come back under «Московская область» in the CDEK classifier, so name-searching made the region
«Москва» look like it contains only Москва itself.

Measured with the right tool, region «Москва» (`test-cdek:r81`) carries **288** settlements —
Внуково, Москва, Бутово, Митино, Новокосино, Рублёво…

## ✅ How to be right

```php
$regions = $provider->list_localities( Location_Scope::for_country( 'RU', 'region' ) );
$region  = /* find by label */;
$items   = $provider->list_localities( Location_Scope::within( $region, 'settlement' ) );
```

`Location_Scope::within( $parent_record, $level )` returns the region's list itself, so any sample
size can be taken without guessing names. `suggest()` is for what a CUSTOMER typed; it is the wrong
tool for enumerating a region.

Note the two key spaces are different: regions are `test-cdek:r81`, settlements `test-cdek:44`.

## Related

- [a-locality-display-name-is-not-an-identifier](a-locality-display-name-is-not-an-identifier.md)
- [a-derived-ancestor-is-not-the-one-the-customer-picked](a-derived-ancestor-is-not-the-one-the-customer-picked.md)
