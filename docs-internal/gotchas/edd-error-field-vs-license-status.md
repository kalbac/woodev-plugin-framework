# EDD reports activation failures via `error`, not `license`

**Topic:** `[licensing/*]` · Discovered s20 (2026-06-18), live operator testing.

## Problem

On an **activation** failure, EDD Software Licensing returns the precise reason in the
response **`error`** field while leaving **`license`** at the generic `'invalid'` (or `''`).
The classic example: activating a key that has reached its site/activation limit returns

```json
{ "success": false, "license": "invalid", "error": "no_activations_left", ... }
```

The framework's presentation historically derived status from `license` only, so a
limit-reached (or `site_inactive`) license rendered as **«Неверный ключ»** — factually wrong
(the key is fine; the limit/site is the issue).

`Woodev_License::get()` already captures `error` (it's in `$allowed_keys`) and even back-fills
`error = license` when `error` is null and the status is non-valid — but nothing read `error`
back out for display.

## ❌ Wrong

```php
// Status keyed off the generic token only — limit/site failures look like a bad key.
$status = (string) $this->woodev_license->license; // 'invalid'
```

## ✅ Correct

```php
// Woodev_License::get_display_status() — presentation-only, prefers the specific error token.
public function get_display_status(): string {
    $license = (string) $this->license;
    $error   = (string) $this->error;
    if ( '' !== $error && in_array( $license, array( '', 'invalid' ), true ) ) {
        return $error; // e.g. 'no_activations_left', 'site_inactive'
    }
    return $license;
}
```

Use the display status in **presentation only** (`get_state()` status/`status_label`/
`message_variant`, and `Woodev_License_Messages::build_message()`'s switch). **Do NOT** mutate
`license` or route enforcement through it: `is_active()` / `is_invalid()` / `has_status()` must
keep reading the raw `license` so the anti-pirate invariant is unchanged.

## Related

- `woodev/licensing/class-license-store.php` — `Woodev_License::get_display_status()` + `get()`
- `woodev/licensing/class-plugin-license.php` — `get_state()`, `get_message_variant()`
- `woodev/licensing/class-license-messages.php` — `build_message()`
- [[license-need-vs-required]] — the other licensing presentation-vs-enforcement split
- [[edd-sl-get-version-serialized-sections]] — another EDD SL wire-shape gotcha
