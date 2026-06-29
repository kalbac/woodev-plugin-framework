# A `constant_name`-backed field must be masked even when the constant is UNDEFINED

**Namespace:** `[settings-api/secrets]`
**Session:** s38 (2026-06-30) — found by the Codex GPT-5.5 critic (HIGH), fixed + re-critic'd.

## The leak

SP-2's first masking implementation computed:

```php
$constant_managed = null !== $constant_name && defined( $constant_name );
$is_secret        = $setting->is_sensitive() || $constant_managed;   // ← bug
```

So a field that declares `constant_name` but whose constant is **not currently `defined()`** (and is not also flagged `sensitive`) had `is_secret = false` → `Field_Schema` emitted its **stored DB fallback value** to the browser. Scenario: an operator stored a key in the DB, later moved it to a `wp-config` `define()`, but the constant isn't loaded in some context (or a leftover value remains) → the secret ships to the client.

## Why it's a real bug, not theoretical

`Woodev_Setting::get_value()` falls back to the stored option when the constant is undefined. A field declaring `constant_name` is **secret-bearing by intent** — its stored fallback is just as sensitive as the constant. Masking must not depend on the constant being currently defined.

## Fix

Treat **any** field with a `constant_name` as secret, regardless of `defined()`:

```php
$has_constant     = null !== $constant_name;
$constant_managed = $has_constant && defined( $constant_name );   // drives the read-only wp-config note ONLY
$is_secret        = $setting->is_sensitive() || $has_constant;    // masking decision

// emit:
if ( $is_secret ) { $entry['sensitive'] = true; $entry['is_set'] = $is_set; }  // password mask in the UI
if ( $constant_managed ) { $entry['constant_managed'] = true; $entry['constant_name'] = $constant_name; } // read-only note
```

`constant_managed` is now **only** "the constant is currently defined" (→ read-only UI note); the **masking** decision is the broader `has_constant`. `ControlField` checks `constant_managed` before `sensitive`, so a defined constant renders read-only and an undefined one renders as a masked password field.

## Rule

Masking a secret is decided by **declared intent** (`sensitive` flag OR `constant_name` present), never by the runtime presence of the backing value. "Is the value currently sourced from a constant?" and "must this never be emitted?" are two different questions — don't conflate them.

## Related
- `[[settings-api-control-save-path-pitfalls]]` — the other class of "validation/secret path looked fine but leaked".
- SP-2: `woodev/settings-page/class-field-schema.php`.
