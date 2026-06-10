---
id: s5-p1-need-license-flag-and-seam
title: Add Woodev_Plugin::is_need_license() (presentation flag) + Woodev_Plugins_License::is_license_required() enforcement seam; route is_license_valid()/is_active() through it (default-true, no behavior change)
phase: S3.1 Licensing — is_need_license safe-scaffold
type: feature
touches_contract_zone: false
writes_guard: false
file_set:
  - woodev/class-plugin.php
  - woodev/licensing/class-plugin-license.php
  - tests/unit/LicenseRequiredSeamTest.php
depends_on: []
contract_zones_touched: []
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0 errors, all unit tests pass)
  - "Woodev_Plugin gains public is_need_license(): bool returning true (overridable)"
  - "Woodev_Plugins_License gains public is_license_required() returning true"
  - "is_license_valid() and is_active() short-circuit to true when ! is_license_required(); with the default-true seam their outcome is byte-for-byte the current behavior"
  - "the local is_need_license() flag has NO influence on is_license_valid()/is_active() (anti-pirate)"
  - "no installed-site contract changed (option keys, settings group, slug, nonce, EDD action, hooks) — additive methods only"
---

# Task

Implement the two-layer flag scaffold from the S3.1 spec. **Read first:**
`docs-internal/platform-v2-s3-licensing-need-license-spec.md` (§2, §3.1, §3.3) and
`docs-internal/platform-v2-s3-licensing-need-license-plan.md` (Task s5-p1) — the plan
carries the exact code and TDD steps. Use Serena (`find_symbol`) to read the current
bodies before editing.

## 1. `woodev/class-plugin.php` — add the L1 presentation flag

Add near the capability getters (e.g. alongside `is_beta_allowed()` / after `get_download_id()`):

```php
/**
 * Whether this plugin requires a license to operate.
 *
 * Presentation hint only — controls how the license page renders and whether
 * "enter your license" nags appear. NEVER used to gate features or updates;
 * the authority on that is the server-signed claim consulted by
 * {@see Woodev_Plugins_License::is_license_required()}. A plugin shipped
 * without a license overrides this to return false.
 *
 * @since 2.0.0
 *
 * @return bool
 */
public function is_need_license(): bool {
    return true;
}
```

## 2. `woodev/licensing/class-plugin-license.php` — add the L2 enforcement seam

Add near `is_license_valid()`:

```php
/**
 * Authoritative answer to whether this product requires a valid license.
 *
 * Returns true unless a VERIFIED server claim says it is license-free. Until
 * signed claims are issued (S3.1 spec §4) this always returns true, so
 * enforcement is byte-for-byte unchanged. The local Woodev_Plugin::is_need_license()
 * flag does NOT influence this method (anti-pirate).
 *
 * @since 2.0.0
 *
 * @return bool
 */
public function is_license_required() {
    return true;
}
```

Route the two helpers through it (no-op while the default is true):

```php
public function is_license_valid() {
    if ( ! $this->is_license_required() ) {
        return true;
    }

    return ! empty( $this->license_key ) && $this->has_status( 'valid' );
}

public function is_active() {
    if ( ! $this->is_license_required() ) {
        return true;
    }

    return ! in_array(
        true,
        array(
            $this->is_expired(),
            $this->is_disabled(),
            $this->is_invalid(),
        ),
        true
    );
}
```

## 3. `tests/unit/LicenseRequiredSeamTest.php`

Cover: `is_license_required()` defaults true; `is_license_valid()` true for status `valid` + non-empty key, false for `expired`; `is_active()` true for `valid`, false for `expired`. Build the license via `newInstanceWithoutConstructor()` and inject a `Woodev_License` stub (set `license`/`key` via reflection or a settable stub), matching the existing licensing unit-test scaffolding in the repo. See plan Task s5-p1 steps 1-5 for the exact assertions.

## What NOT to change
- Do NOT read any stored option in `is_license_required()` yet — the signed-claim
  verification is **deferred** (spec §4). A bare unsigned option would be a tamper vector.
- Do NOT gate `is_license_valid()`/`is_active()` on `is_need_license()` — only on
  `is_license_required()`. The local flag must never unlock enforcement.
- Do NOT touch option keys, settings group `woodev_license_fields_group`, slug
  `woodev-licenses`, nonce, EDD `edd_action`, or hooks. Additive methods only.
- No deprecation shims (clean-break internal API, ADR-005).

## Verification
- `composer check` green (PHPCS, PHPStan 0, unit tests).
- New tests prove default-true seam keeps `is_license_valid()`/`is_active()` identical to
  current behavior.

## Reference
- Spec: `docs-internal/platform-v2-s3-licensing-need-license-spec.md`
- Plan: `docs-internal/platform-v2-s3-licensing-need-license-plan.md` (Task s5-p1)
