---
id: s5-p3-outage-grace-hardening
title: Weekly license check tolerates Woodev server outage — never throws, never relocks a previously-valid license
phase: S3.1 Licensing — is_need_license safe-scaffold
type: fix
touches_contract_zone: false
writes_guard: false
file_set:
  - woodev/handlers/class-cron-handler.php
  - tests/unit/LicenseOutageGraceTest.php
depends_on: []
contract_zones_touched: []
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0 errors, all unit tests pass)
  - "weekly_license_check() keeps running regardless of is_need_license() (NOT gated on the flag)"
  - "a transport failure inside validate_license() is swallowed — weekly_license_check() does not throw, emit a warning, or relock; last-known-good license state is retained"
  - "the existing empty( license_key ) early-return and the wp_doing_cron()/woodev_settings guards are preserved"
  - "no installed-site contract changed; cron hook name + recurrence untouched"
---

# Task

Harden `Woodev_Cron_Handler::weekly_license_check()` against Woodev server downtime
(spec §3.2). The server has gone down before; a failed validation must not error out
or relock a valid license. **Read first:** spec §3.2 and plan Task s5-p3. Use Serena to
read the current `weekly_license_check()` body.

The current method early-returns on `! wp_doing_cron()`, on `$_POST['woodev_settings']`,
and on empty license key, then calls `validate_license( $license_key )`. Wrap that call:

```php
$license_key = $this->plugin->get_license_instance()->get_license();

if ( empty( $license_key ) ) {
    return;
}

try {
    $this->plugin->get_license_instance()->validate_license( $license_key );
} catch ( \Throwable $e ) {
    // Server unreachable / transport failure: keep last-known-good license state.
    // Never error out and never relock a previously-valid license on a failed check.
    return;
}
```

> `validate_license()`/`dispatch()` already only write stored state on a successful
> response, so last-known-good is retained; the try/catch guarantees no exception bubbles
> out of the cron callback even if a future change makes `validate_license()` throw.

## `tests/unit/LicenseOutageGraceTest.php`
- Stub a license instance whose `get_license()` returns a key and whose
  `validate_license()` **throws**; stub `wp_doing_cron()` true and no `$_POST['woodev_settings']`.
- Assert `weekly_license_check()` does **not** throw (the test reaches its end / a
  post-call assertion).
See plan Task s5-p3 steps 1-3 for the exact stub wiring.

## What NOT to change
- Do NOT gate the cron on `is_need_license()` — it must keep running (a keyless free
  plugin is already a natural no-op via the `empty( $license_key )` guard). This explicitly
  corrects an earlier wrong design note that said "do not run when is_need_license is false".
- Do NOT change the cron hook name, recurrence, or the `validate_license()` signature.
- Do NOT auto-unlock on failure; only retain prior state.
- No deprecation shims (ADR-005).

## Verification
- `composer check` green.
- Test proves a thrown `validate_license()` does not bubble out of `weekly_license_check()`.

## Reference
- Spec: `docs-internal/platform-v2-s3-licensing-need-license-spec.md` (§3.2)
- Plan: `docs-internal/platform-v2-s3-licensing-need-license-plan.md` (Task s5-p3)
