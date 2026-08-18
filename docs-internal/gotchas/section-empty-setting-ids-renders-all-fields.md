# A settings section declaring NO setting ids renders the WHOLE handler, not zero fields

**Namespace:** `[settings-api/section-empty-ids]`
**Session:** s79 (18.08.2026) — found by a rig probe (`wp eval-file`, dumping `Settings_Page_Registry::instance()->get_tabs()`) during Task 4 of the shipping-settings-v2 plan (issue #362).

## What happens

A `Settings_Section` created with an intentionally empty `$setting_ids` array — a deliberate stub
for a section whose handler isn't implemented yet, or a connection-only block that has no field
list of its own — renders **every field the handler owns**, not the empty set its declaration
implies.

Measured on the rig, shipping tab (Task 4 in flight — `Поля`/`Карта` are deliberate empty stubs
Tasks 5/8 fill in later):

```
TAB id=shipping sections=3
   SECTION id=location fields=active_provider,field_mode,default_locality_policy,default_locality_record,default_locality_needs_repick,cdek_client_id,cdek_client_secret
   SECTION id=fields  fields=active_provider,field_mode,default_locality_policy,default_locality_record,default_locality_needs_repick,cdek_client_id,cdek_client_secret   ← declared []
   SECTION id=map     fields=active_provider,field_mode,default_locality_policy,default_locality_record,default_locality_needs_repick,cdek_client_id,cdek_client_secret   ← declared []
```

Same symptom pre-exists on `main`, unrelated to the branch in flight: the test fixture's
connection-only `widget` section (`Settings_Section::create( 'widget', 'Виджет ЛК', [], … )`,
`tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php:407`) renders all 20 fields of the
`quarry` tab instead of none.

## Root cause

`Woodev_Abstract_Settings::get_settings( array $ids = [] )` implements the convention **"an
empty `$ids` means ALL settings"** — correct when the caller has no declared subset at all (e.g.
`Setup_Wizard::get_field_schema()` building a whole-tab schema, which calls
`Field_Schema::from_handler( $handler )` with no second argument on purpose).
`Composite_Settings_Handler::get_settings()` forwards the same convention to its children.

`Settings_Page_Registry::build_sections()` passed a **section's declared id list** straight into
that same seam:

```php
'fields' => Field_Schema::from_handler( $handler, $section->get_setting_ids() ),
```

A section's `get_setting_ids()` is a **declaration** of which fields it owns, not an optional
filter — "I haven't declared any fields yet" and "give me every field, unfiltered" are different
statements that collapsed onto the same PHP value (`[]`) at this one call site.

## Fix

`build_sections()` is the only caller that knows the difference — treat an empty declared list as
zero fields, short-circuiting before it reaches the handler's "empty = all" convention:

```php
❌ 'fields' => Field_Schema::from_handler( $handler, $section->get_setting_ids() ),

✅ $setting_ids = $section->get_setting_ids();
   'fields'      => empty( $setting_ids ) ? [] : Field_Schema::from_handler( $handler, $setting_ids ),
```

`Woodev_Abstract_Settings::get_settings()` and `Field_Schema::from_handler()` were left
untouched — the setup wizard's whole-tab schema call (and any other caller that legitimately
wants "all settings") still gets it via a bare `from_handler( $handler )`.

## Rule

`get_settings( [] ) === "all settings"` is a fine convention for a caller with no subset to
declare. It becomes a bug the moment a **declaration** (a section's own, possibly-not-yet-filled
id list) is forwarded into that same parameter — the absence of a filter and a declaration of
"nothing" are the same value at that seam, and only the caller holding the declaration can tell
them apart. Check every intermediate caller that forwards a "may be empty" list into a
"empty = all" API before assuming the emptiness means what the caller intended.

## Related
- [[mask-constant-backed-field-even-when-constant-undefined]] — same family: a masking/filtering
  decision computed one field too early, before the caller that actually knows the answer.
- SP-2 / Task 4: `woodev/settings-page/class-settings-page-registry.php` (`build_sections()`),
  `woodev/settings-page/class-field-schema.php` (`from_handler()`), `woodev/settings-api/abstract-class-settings.php` (`get_settings()`).
