# A locality's display NAME is not an identifier — the same settlement answers «Москва» or «Moscow» depending on the account's locale

**Topic:** `[shipping/location]`
**Discovered:** s71 (13.08.2026), Task 15 rig pass

## The trap

Two rig fixtures matched a hardcoded Cyrillic string:

```php
❌ if ( 'Москва' === $query->get_locality() ) { … }
```

and returned nothing on the rig — because the DaData account the rig uses answers RU settlements with **English display names** (`Kazakhstan, gorod Almaty`, `Russia, oblast Moskovskaya, gorod Zhukovsky`). The locale seam wired in s70 derives `language` from `get_user_locale()`, and the rig's WordPress is English, so every label comes back transliterated.

The measured half that makes this safe to build on: **`fias_id` does not change with the language** (s70). Identity is stable; the display name is not.

```php
✅ $record = $query->get_record();          // identity, stable across locales
   $key    = $record ? $record->key() : ''; // provider_id:native_id
```

## Why it matters beyond the fixture

The framework itself is clean here — it treats `locality` as opaque end to end and never compares names. The hazard is for the first PLUGIN that maps a locality onto its carrier's own city dictionary: the obvious implementation compares `record->settlement()['name']` against the carrier's list, and it will work on a Russian-locale store and silently return nothing on an English-locale one. Same store, same customer, same city.

The same reasoning applies to a merchant switching the store language, and to a multilingual site: the transliteration changes, the identity does not.

## The general rule

A display name is a VIEW of a locality, produced for a human in a particular language. Anything that has to MATCH — a carrier city dictionary, a session map key, a cache key, a comparison against a stored choice — uses the key. Anything shown to a customer uses the name.

This is the same split the framework already enforces one layer down: [[an-empty-domain-key-is-not-a-key]] refuses a non-answer as a key, and [[derive-a-view-field-at-the-boundary-not-at-display-sites]] separates a view field from the value it derives from.

## Related

- [[an-empty-domain-key-is-not-a-key]]
- [[derive-a-view-field-at-the-boundary-not-at-display-sites]]
- [[built-on-both-sides-with-no-caller-in-the-middle]]
