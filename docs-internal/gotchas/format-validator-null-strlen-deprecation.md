# Format validators must guard non-string input (is_email/strpos on null → PHP 8.1 deprecation)

> Namespace: `[settings-api/validation]` · Discovered s39 (2026-06-30, SP-3) · Found by the Codex critic + a risky integration test.

## Symptom

An integration test that registered a `TYPE_EMAIL` (or `TYPE_URL`) setting **without a `default`** printed:

```
Deprecated: strlen(): Passing null to parameter #1 ($string) of type string is deprecated in wp-includes/formatting.php on line 3557
```

PHPUnit flagged it **risky** ("test printed unexpected output"). On the CI integration matrix this becomes a **red** on the WP/PHP combos that treat deprecation output as a failure (same class as `integration-test-global-admin-hooks-output-and-submenu-accumulation`).

## Root cause

`Woodev_Abstract_Settings::register_setting()` runs `wp_parse_args` with `'default' => null`, then calls `Woodev_Setting::set_default( null )`. `set_default()` used to call `validate_value()` on the default to decide whether to keep it — which for an email type runs `validate_email_value(null)` → `is_email(null)` → `strlen(null)`. Same for url types: `validate_url_value(null)` → `is_valid_url(null)` → `strpos(null, …)`. PHP 8.1 deprecates passing null to these string functions.

The fixture's `manager_email` did NOT trigger it only because it was registered with `'default' => ''` (`is_email('')` is `strlen('')` = 0, no null).

A second, security-relevant variant: a crafted request can POST a non-string (e.g. an **array**) to a scalar email field; a non-empty array passes `is_empty_value()` and reaches `is_email( array )` → PHP 8 **TypeError** (`strlen` on array) → an ugly 500 instead of a clean 400 validation error.

## Fix (s39)

Three layers, all in `woodev/settings-api/class-setting.php`:

1. **Guard the validators against non-string** — the real root fix:
   - `validate_email_value()` → `return is_string( $value ) && (bool) is_email( $value );`
   - `is_valid_url()` → first line `if ( ! is_string( $url ) ) { return false; }` (covers both `validate_url_value()` and the `get_validation_error()` url branch).
   - In `get_validation_error()`'s email branch: `if ( ! is_string( $value ) || ! is_email( $value ) )` (so a crafted array can't reach `is_email`).
2. **`set_default()` short-circuits null** — `if ( null === $value ) { $this->default = null; return; }` as the first line, so a "no default supplied" never reaches a format validator.
3. (`validate_string_value`/`integer`/`float`/`boolean` are already null-safe — `is_string`/`is_int`/`is_float`/`is_bool` accept null without deprecation. `is_valid_tel` already guarded `! is_string && ! is_numeric`.)

## Rule going forward

Any validator that calls a PHP string function (`strlen`, `strpos`, `preg_match`, `is_email`, `filter_var`) on its argument MUST guard `is_string()` (or `is_scalar`) first — the value can be null (no default) or a crafted non-scalar (REST payload). Never assume a validator receives a string.

## Related

- [[settings-api-control-save-path-pitfalls]] — the enum/kses/number-coercion save-path pitfalls (sibling validation concerns).
- [[integration-test-global-admin-hooks-output-and-submenu-accumulation]] — why printed output during an integration test goes red on part of the matrix.
- `docs-internal/specs/2026-06-30-sp3-field-validation-design.md` — SP-3 validation model.
