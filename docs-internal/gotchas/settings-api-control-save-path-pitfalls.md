# gotcha: Settings-API save path — validate enums by key-or-value, coerce numbers, sanitize HTML

**Namespace:** `[php/settings-api]`
**Discovered:** s31 (2026-06-25, Codex critic pass on the Setup Wizard)

Three latent bugs in `Woodev_Setting`'s save path, surfaced when the wizard started
exercising the full range of control types (the unit tests + rig demo had only used
string enums and default numbers, so green CI + manual testing missed all three).

## 1. Enum options must be validated by KEY *or* VALUE — never the label alone

`Woodev_Setting::set_options()` drops an option whose value fails `validate_value()`
for the setting type. For an associative enum the array VALUE is the **display label**
(free text), not the submittable token. So an integer setting registered as
`[ 1 => 'One', 2 => 'Two' ]` had its labels `'One'`/`'Two'` fail `is_int()`, every
option dropped, and then `assert_valid_value()` saw `empty($this->options)` and
**accepted any integer** — a silent validation bypass.

A naive "is it a list or a map?" heuristic (`array_keys() === range(0, n-1)`) does NOT
fix it: zero-based integer keys `[ 0 => 'Zero', 1 => 'One' ]` are structurally
identical to a plain list and get misclassified again. Correct rule:

```php
// Keep the option when EITHER its key OR its value is type-valid.
if ( ! $this->validate_value( $key ) && ! $this->validate_value( $option ) ) {
    unset( $options[ $key ] );
}
```

## 2. Numeric values arrive as strings — coerce before strict validation

HTML number inputs (`<input type="number">`, wp `TextControl type="number"`) submit
their value as a **string** (`"5000"`). `validate_integer_value()` is a strict
`is_int()`, so the save silently failed (400) the moment a user edited a number field.
Coerce numeric strings to the setting's numeric type in `update_value()` before
validating — but do NOT truncate (`"5.5"` must stay invalid for an integer, not become
`5`):

```php
if ( self::TYPE_INTEGER === $this->type && is_numeric( $value ) && (float) (int) $value === (float) $value ) {
    return (int) $value;
}
if ( self::TYPE_FLOAT === $this->type && is_numeric( $value ) ) {
    return (float) $value;
}
```

## 3. Richtext (HTML-bearing) settings must be `wp_kses_post()`-sanitized on save

A `richtext` control submits raw HTML which is later re-rendered (stored XSS surface,
even if admin-capability-gated). The Setting type is `TYPE_STRING`, so `is_string()`
passes it through untouched. Sanitize in `update_value()` keyed on the control type
(the Setting holds its `Woodev_Control`), for both the scalar and `is_multi` branches:

```php
if ( is_string( $value ) ) {
    $control = $this->get_control();
    if ( $control instanceof Woodev_Control && Woodev_Control::TYPE_RICHTEXT === $control->get_type() ) {
        return wp_kses_post( $value ); // also strips javascript:/data: link protocols
    }
}
```

## Takeaway

When you add a new control type to the Settings API, walk the **whole save path**
(`update_value` → `assert_valid_value`/`validate_*` → `set_value` → persist) and ask:
does the submitted shape match what validation expects, and does anything HTML-bearing
get sanitized? Unit-test the non-happy-path shapes (zero-based enums, numeric strings,
script payloads) — the happy path will pass regardless.

## Related

- [gotchas/wp-scripts-css-enqueue-version-by-mtime.md](wp-scripts-css-enqueue-version-by-mtime.md) — sibling wizard gotcha
- [gotchas/codex-shell-sandbox-broken-windows.md](codex-shell-sandbox-broken-windows.md) — how the inline-bundle critic that found these was run
