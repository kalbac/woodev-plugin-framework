---
id: s5-p2-presentation-gating
title: Gate license-page block + admin nags + WC form-wrap + plugin action-link on is_need_license() (presentation only; anti-pirate preserved)
phase: S3.1 Licensing — is_need_license safe-scaffold
type: feature
touches_contract_zone: false
writes_guard: false
file_set:
  - woodev/licensing/class-plugin-license.php
  - woodev/class-plugin.php
  - woodev/class-woocommerce-plugin.php
  - woodev/licensing/class-woocommerce-license-settings.php
  - tests/unit/LicenseNeedLicenseFlagTest.php
depends_on:
  - s5-p1-need-license-flag-and-seam
contract_zones_touched: []
needs_guard: no
acceptance:
  - composer check green (PHPCS, PHPStan 0 errors, all unit tests pass)
  - "when is_need_license() === false: notices() adds no admin notice; plugin_row_license_missing() prints no 'enter valid license' sentence; the WC settings form-wrap is not emitted; the 'license' plugin action-link is not added; do_license_fields() renders a 'Лицензия не требуется' block instead of the input/verify/deactivate controls"
  - "when is_need_license() === true (default): all five sites behave exactly as before (byte-for-byte)"
  - "ANTI-PIRATE: is_license_valid()/is_active() remain unaffected by is_need_license() (a false flag with no verified claim still yields the paid-license outcome)"
  - "no installed-site contract changed; the <form> + settings_fields('woodev_license_fields_group') structure in license_page() is untouched"
---

# Task

Wire the L1 flag from s5-p1 into the **five presentation sites** so a license-free
plugin has a clean license page and no nags — **without** touching enforcement.
**Read first:** spec §3.1 (the consumer table) and plan Task s5-p2 (exact code).
Use Serena to read each current body before editing.

## Sites to gate

1. **`Woodev_Plugins_License::notices()`** — add at the very top:
```php
if ( ! $this->plugin->is_need_license() ) {
    return;
}
```

2. **`Woodev_Plugins_License::plugin_row_license_missing()`** — add `$this->plugin->is_need_license() &&` to the condition guarding the "Enter valid license key for automatic updates." sentence (leave the version-upgrade-notice branch below it untouched):
```php
if ( $this->plugin->is_need_license() && ( ! $this->woodev_license || 'valid' !== $this->woodev_license->license ) ) {
    echo '&nbsp;&nbsp;<strong><a href="' . $this->get_license_settings_url() . '" style="color: #aa0000;">' . __( 'Enter valid license key for automatic updates.', 'woodev-plugin-framework' ) . '</a></strong>';
}
```

3. **`Woodev_Plugin::plugin_action_links()`** — gate the `license` branch (~line 686):
```php
if ( $this->is_need_license() && $this->get_license_instance()->get_license_settings_url() ) {
    $license_text              = $this->get_license_instance()->is_license_valid() ? 'Лицензия' : 'Указать лицензию';
    $custom_actions['license'] = sprintf( '<a href="%s">%s</a>', $this->get_license_instance()->get_license_settings_url(), esc_html( $license_text ) );
}
```

4. **`Woodev_Woocommerce_Plugin::add_class_form_wrap_start()` and `_end()`** — add `$this->is_need_license() &&` to both conditions:
```php
if ( $this->is_need_license() && $this->is_plugin_settings() && ! $this->get_license_instance()->is_license_valid() ) {
```

5. **`Woodev_Woocommerce_License_Settings::do_license_fields()`** — at the very top, render the "not required" block and return before any input/verify/deactivate output:
```php
if ( ! $this->plugin->is_need_license() ) {
    echo '<div class="license-item license-not-required">';
    printf( '<p>%s</p>', esc_html__( 'Лицензия для этого плагина не требуется.', 'woodev-plugin-framework' ) );
    echo '</div>';

    return;
}
```

## `tests/unit/LicenseNeedLicenseFlagTest.php`
- default `is_need_license()` true; an override subclass returns false;
- `notices()`: with a plugin whose `is_need_license()` is false, the admin-notice handler's `add_admin_notice()` is **never** called (Mockery `shouldNotReceive`);
- **anti-pirate:** with `is_need_license()` false and status `expired`/empty verified claim, `is_license_valid()` and `is_active()` are still false.
See plan Task s5-p2 steps 1-4 for exact assertions + how to build the plugin/license stubs.

## What NOT to change
- Do NOT alter the `<form>`, `settings_fields( 'woodev_license_fields_group' )`,
  `do_settings_sections( 'woodev_licenses_page' )`, or the nonce in
  `Woodev_Admin_Pages::license_page()` / `register_license_settings()`.
- Do NOT gate enforcement (`is_license_valid()`/`is_active()`) on `is_need_license()`.
- Do NOT change option keys, slug, EDD action, or hooks.
- No deprecation shims (ADR-005).

## Verification
- `composer check` green.
- Tests prove all five sites honor the flag AND the anti-pirate invariant holds.

## Reference
- Spec: `docs-internal/platform-v2-s3-licensing-need-license-spec.md` (§3.1, §6)
- Plan: `docs-internal/platform-v2-s3-licensing-need-license-plan.md` (Task s5-p2)
