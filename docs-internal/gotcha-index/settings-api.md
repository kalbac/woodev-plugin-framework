# Gotcha index — [settings-api/*] Settings API

> One line per gotcha in this topic; the detail is in the linked file. Map of every topic:
> [../GOTCHAS.md](../GOTCHAS.md). Format and the write protocol: `DOCS-SCHEMA.md` → "GOTCHAS.md Format".

- [settings-api/save-path] **Settings-API save path — validate enums by key-or-value, coerce numbers, sanitize HTML.** → [settings-api-control-save-path-pitfalls](../gotchas/settings-api-control-save-path-pitfalls.md) (s31)
- [settings-api/secrets] **A `constant_name`-backed field must be masked even when the constant is UNDEFINED.** → [mask-constant-backed-field-even-when-constant-undefined](../gotchas/mask-constant-backed-field-even-when-constant-undefined.md) (s38)
- [settings-api/validation] **Format validators must guard non-string input (is_email/strpos on null → PHP 8.1 deprecation).** → [format-validator-null-strlen-deprecation](../gotchas/format-validator-null-strlen-deprecation.md) (s39)
- [settings-api/secrets] **Settings sensitive secret: the "don't overwrite with empty" guard is CLIENT-side, not server.** → [settings-sensitive-secret-empty-skip-is-client-side](../gotchas/settings-sensitive-secret-empty-skip-is-client-side.md) (s41)
- [settings-api/caching] **`Woodev_Setting::get_value()` returns a cached property, so `update_option()` mid-request is invisible to it.** → [woodev-setting-get-value-is-cached-not-a-live-option-read](../gotchas/woodev-setting-get-value-is-cached-not-a-live-option-read.md) (s71)
- [settings-api/rendering] **A setting can carry a `'name'` label and never render: `register_control()` is the only proof it is on screen — `'name'` declares storage, and `get_owned_setting_ids()` omits four options that DO render.** → [a-registered-setting-without-a-control-never-renders](../gotchas/a-registered-setting-without-a-control-never-renders.md) (s108)
- [settings-api/section-empty-ids] **A settings section declaring NO setting ids renders the WHOLE handler, because `get_settings( [] )` means "all".** → [section-empty-setting-ids-renders-all-fields](../gotchas/section-empty-setting-ids-renders-all-fields.md) (s79)

## Related

- [../GOTCHAS.md](../GOTCHAS.md) — the topic map
