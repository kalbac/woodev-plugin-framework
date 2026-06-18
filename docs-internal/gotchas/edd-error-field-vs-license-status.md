# EDD reports activation failures via `error`, not `license` — but only TOKEN errors

**Topic:** `[licensing/*]` · Discovered s20 (2026-06-18), live operator testing. Updated s21 (token-guard fix).

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

## ⚠️ s21 trap — the `error` field can be FREE TEXT, not a machine token

Some stores put a **localized human sentence** in `error` for a plain bad key, NOT a machine
code. Observed on the woodev.ru store: a non-existent key returns
`{ "license": "invalid", "error": "Неверно указан лицензионный ключ." }`. The naïve override
(below) then returned that whole sentence as the "status" → it matched **no** presentation group
→ fell through to the JS `unknown` fallback → badge «Неизвестный статус», message «Без лицензии…»,
and (because `unknown` had `changeKey:false`) the user was **stranded** with no way to enter a
different key. Same class of bug as the revoked-key stranding fixed in #67.

## ✅ Correct (s21 — guard the override to machine tokens only)

```php
// Woodev_License::get_display_status() — presentation-only, prefers a specific error TOKEN.
public function get_display_status(): string {
    $license = (string) $this->license;
    $error   = (string) $this->error;
    // Override ONLY when `error` is a machine status code (e.g. no_activations_left,
    // site_inactive). A free-text/localized error keeps `license`, so a plain bad key
    // resolves to 'invalid' → the editable «bad-key» group + the right message.
    if ( '' !== $error && in_array( $license, array( '', 'invalid' ), true )
        && 1 === preg_match( '/^[a-z][a-z0-9_]*$/', $error ) ) {
        return $error; // e.g. 'no_activations_left', 'site_inactive'
    }
    return $license;
}
```

Use the display status in **presentation only** (`get_state()` status/`status_label`/
`message_variant`, and `Woodev_License_Messages::build_message()`'s switch). **Do NOT** mutate
`license` or route enforcement through it: `is_active()` / `is_invalid()` / `has_status()` must
keep reading the raw `license` so the anti-pirate invariant is unchanged.

**Defense in depth (s21):** the JS `card-state.js` `unknown` fallback now also sets
`changeKey: true`, so even a genuinely-unforeseen status can never strand the user.

## Related

- `woodev/licensing/class-license-store.php` — `Woodev_License::get_display_status()` + `get()`
- `woodev/licensing/class-plugin-license.php` — `get_state()`, `get_message_variant()`
- `woodev/licensing/class-license-messages.php` — `build_message()`
- [[license-need-vs-required]] — the other licensing presentation-vs-enforcement split
- [[edd-sl-get-version-serialized-sections]] — another EDD SL wire-shape gotcha
