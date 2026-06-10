# `is_need_license` (S3.1 safe-scaffold) Implementation Plan

> **For agentic workers:** This plan is executed via the project **autodev-loop** (worker subagent writes the diff → adversarial critic reviews every contract-adjacent diff AND any in-place fix before commit, no self-certify → holistic critic pass at the end). Atomic task files derived from this plan live in `.autodev/queue/pending/s5-p1..p3`. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Add the per-plugin `is_need_license()` presentation flag and a conservative server-authority enforcement seam, with outage-grace hardening — all byte-for-byte back-compatible, no live server needed.

**Architecture:** Two layers. L1 = `Woodev_Plugin::is_need_license()` (default `true`) gates **presentation only** (license-page block + nags). L2 = `Woodev_Plugins_License::is_license_required()` (default `true`) is the **enforcement** authority that `is_active()`/`is_license_valid()` consult; until signed server claims exist it always returns `true`, so behavior is unchanged. The local L1 flag never influences L2 (anti-pirate invariant).

**Tech Stack:** PHP 7.4+, WordPress/WooCommerce, Brain Monkey + Mockery unit tests, PHPCS (WPCS), PHPStan level 3.

**Spec:** `docs-internal/platform-v2-s3-licensing-need-license-spec.md`. Full signing design (Ed25519 claim) is **deferred** to a later cross-repo session and is NOT implemented here.

---

## File Structure

| File | Responsibility | Change |
|------|----------------|--------|
| `woodev/class-plugin.php` | base plugin; adds `is_need_license()`; gates the `license` action-link branch | modify |
| `woodev/licensing/class-plugin-license.php` | license engine; adds `is_license_required()`; routes `is_license_valid()`/`is_active()` through it; gates `notices()` + `plugin_row_license_missing()` on `is_need_license()` | modify |
| `woodev/class-woocommerce-plugin.php` | WC subclass; gates `add_class_form_wrap_start()/_end()` on `is_need_license()` | modify |
| `woodev/licensing/class-woocommerce-license-settings.php` | license field renderer; renders "license not required" block when `is_need_license()===false` | modify |
| `woodev/handlers/class-cron-handler.php` | weekly license check; outage-grace hardening | modify |
| `tests/unit/LicenseNeedLicenseFlagTest.php` | L1 flag default/override + presentation gating + anti-pirate invariant | create |
| `tests/unit/LicenseRequiredSeamTest.php` | L2 seam default-true + `is_license_valid()`/`is_active()` byte-for-byte | create |
| `tests/unit/LicenseOutageGraceTest.php` | weekly check tolerates transport failure (no error, no relock) | create |

---

## Task s5-p1: L1 flag + L2 enforcement seam (no behavior change)

**Files:**
- Modify: `woodev/class-plugin.php` (add `is_need_license()`)
- Modify: `woodev/licensing/class-plugin-license.php` (`is_license_required()`, route `is_license_valid()`/`is_active()`)
- Test: `tests/unit/LicenseRequiredSeamTest.php`

- [ ] **Step 1: Write failing test — seam defaults preserve current behavior**

```php
// tests/unit/LicenseRequiredSeamTest.php  (Woodev\Tests\Unit namespace, extends the unit TestCase)
public function test_is_license_required_defaults_true(): void {
    $license = $this->make_license(); // newInstanceWithoutConstructor + injected Woodev_License stub
    $this->assertTrue( $license->is_license_required() );
}

public function test_is_license_valid_unchanged_when_license_required(): void {
    $license = $this->make_license_with_status( 'valid', 'KEY-123' );
    $this->assertTrue( $license->is_license_valid() );

    $invalid = $this->make_license_with_status( 'expired', 'KEY-123' );
    $this->assertFalse( $invalid->is_license_valid() );
}

public function test_is_active_unchanged_when_license_required(): void {
    $this->assertTrue( $this->make_license_with_status( 'valid', 'KEY' )->is_active() );
    $this->assertFalse( $this->make_license_with_status( 'expired', 'KEY' )->is_active() );
}
```

- [ ] **Step 2: Run it — expect FAIL** (`is_license_required` undefined)

Run: `./vendor/bin/phpunit tests/unit/LicenseRequiredSeamTest.php`
Expected: FAIL — `Error: Call to undefined method ...::is_license_required()`

- [ ] **Step 3: Add `is_need_license()` to `Woodev_Plugin`**

In `woodev/class-plugin.php`, near the capability getters (e.g. just after `get_download_id()` or alongside `is_beta_allowed()`):

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

- [ ] **Step 4: Add `is_license_required()` + route the two helpers in `Woodev_Plugins_License`**

In `woodev/licensing/class-plugin-license.php`, add (near `is_license_valid()`):

```php
/**
 * Authoritative answer to whether this product requires a valid license.
 *
 * Returns true unless a VERIFIED server claim says it is license-free. Until
 * signed claims are issued (see the S3.1 spec §4) this always returns true,
 * so enforcement is byte-for-byte unchanged. The local Woodev_Plugin::is_need_license()
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

Change `is_license_valid()` to short-circuit on a verified-free product (no-op while the default is true):

```php
public function is_license_valid() {
    if ( ! $this->is_license_required() ) {
        return true;
    }

    return ! empty( $this->license_key ) && $this->has_status( 'valid' );
}
```

Change `is_active()` likewise:

```php
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

- [ ] **Step 5: Run tests — expect PASS**

Run: `./vendor/bin/phpunit tests/unit/LicenseRequiredSeamTest.php`
Expected: PASS (3 tests). Then `composer check` green.

- [ ] **Step 6: Commit** (`feat(s3): is_need_license() flag + is_license_required() enforcement seam (default-true, no behavior change)`)

---

## Task s5-p2: L1 presentation gating across the 5 consumer sites

**Files:**
- Modify: `woodev/licensing/class-plugin-license.php` (`notices()`, `plugin_row_license_missing()`)
- Modify: `woodev/class-plugin.php` (`plugin_action_links()` license branch)
- Modify: `woodev/class-woocommerce-plugin.php` (`add_class_form_wrap_start()/_end()`)
- Modify: `woodev/licensing/class-woocommerce-license-settings.php` (`do_license_fields()`)
- Test: `tests/unit/LicenseNeedLicenseFlagTest.php`

- [ ] **Step 1: Write failing tests — each site honors the flag + anti-pirate invariant**

```php
// tests/unit/LicenseNeedLicenseFlagTest.php
public function test_default_is_need_license_true(): void {
    $this->assertTrue( $this->make_plugin()->is_need_license() );
}

public function test_override_is_need_license_false(): void {
    $this->assertFalse( $this->make_plugin_overriding_need_license( false )->is_need_license() );
}

public function test_notices_suppressed_when_license_not_needed(): void {
    // plugin->is_need_license() === false → get_admin_notice_handler()->add_admin_notice() is NEVER called
    $handler = \Mockery::mock();
    $handler->shouldNotReceive( 'add_admin_notice' );
    $license = $this->make_license_for_plugin_not_needing_license( $handler, 'KEY', 'expired' );
    $license->notices();
}

public function test_anti_pirate_flag_does_not_validate_license(): void {
    // is_need_license() === false but no verified claim → is_license_valid() still false for a non-valid status
    $license = $this->make_license_for_plugin_not_needing_license( null, 'KEY', 'expired' );
    $this->assertFalse( $license->is_license_valid() );
    $this->assertFalse( $license->is_active() );
}
```

- [ ] **Step 2: Run — expect FAIL** (notices still calls add_admin_notice; gating absent)

Run: `./vendor/bin/phpunit tests/unit/LicenseNeedLicenseFlagTest.php`
Expected: FAIL on `test_notices_suppressed_when_license_not_needed` (and the others until gating lands).

- [ ] **Step 3a: Gate `notices()`** — add at the top of `Woodev_Plugins_License::notices()`, before the existing `empty( $this->license_key )` check:

```php
if ( ! $this->plugin->is_need_license() ) {
    return;
}
```

- [ ] **Step 3b: Gate `plugin_row_license_missing()`** — wrap only the "enter valid license" sentence (keep the version-upgrade-notice branch intact):

```php
if ( $this->plugin->is_need_license() && ( ! $this->woodev_license || 'valid' !== $this->woodev_license->license ) ) {
    echo '&nbsp;&nbsp;<strong><a href="' . $this->get_license_settings_url() . '" style="color: #aa0000;">' . __( 'Enter valid license key for automatic updates.', 'woodev-plugin-framework' ) . '</a></strong>';
}
```

- [ ] **Step 3c: Gate the action-link** — in `Woodev_Plugin::plugin_action_links()`, wrap the `license` branch (~line 686):

```php
if ( $this->is_need_license() && $this->get_license_instance()->get_license_settings_url() ) {
    $license_text              = $this->get_license_instance()->is_license_valid() ? 'Лицензия' : 'Указать лицензию';
    $custom_actions['license'] = sprintf( '<a href="%s">%s</a>', $this->get_license_instance()->get_license_settings_url(), esc_html( $license_text ) );
}
```

- [ ] **Step 3d: Gate the WC form-wrap** — in `Woodev_Woocommerce_Plugin::add_class_form_wrap_start()` and `_end()`, add `$this->is_need_license()` to the condition:

```php
public function add_class_form_wrap_start() {
    if ( $this->is_need_license() && $this->is_plugin_settings() && ! $this->get_license_instance()->is_license_valid() ) {
        echo '<div class="woodev-licence-need">';
    }
}
public function add_class_form_wrap_end() {
    if ( $this->is_need_license() && $this->is_plugin_settings() && ! $this->get_license_instance()->is_license_valid() ) {
        echo '</div><!-- .woodev-licence-need end-->';
    }
}
```

- [ ] **Step 3e: License-page block** — at the top of `Woodev_Woocommerce_License_Settings::do_license_fields()`, branch to the "not required" message and return before rendering the input/verify/deactivate controls:

```php
if ( ! $this->plugin->is_need_license() ) {
    echo '<div class="license-item license-not-required">';
    printf(
        '<p>%s</p>',
        esc_html__( 'Лицензия для этого плагина не требуется.', 'woodev-plugin-framework' )
    );
    echo '</div>';

    return;
}
```

(The surrounding `<form>` + `settings_fields( 'woodev_license_fields_group' )` in `Woodev_Admin_Pages::license_page()` is untouched — the Settings API round-trips harmlessly with no registered field value to change.)

- [ ] **Step 4: Run tests — expect PASS**, then `composer check` green.

Run: `./vendor/bin/phpunit tests/unit/LicenseNeedLicenseFlagTest.php`

- [ ] **Step 5: Commit** (`feat(s3): gate license-page block + nags + form-wrap + action-link on is_need_license()`)

---

## Task s5-p3: outage-grace hardening (server-down tolerance)

**Files:**
- Modify: `woodev/handlers/class-cron-handler.php` (`weekly_license_check()`)
- Test: `tests/unit/LicenseOutageGraceTest.php`

- [ ] **Step 1: Write failing test — a transport failure does not throw or relock**

```php
// tests/unit/LicenseOutageGraceTest.php
public function test_weekly_check_swallows_transport_failure(): void {
    // validate_license() throws/fails internally → weekly_license_check() must not bubble it
    $license = \Mockery::mock();
    $license->shouldReceive( 'get_license' )->andReturn( 'KEY-123' );
    $license->shouldReceive( 'validate_license' )->andThrow( new \Exception( 'server down' ) );

    $handler = $this->make_cron_handler_for_license( $license );

    // wp_doing_cron() stubbed true, no $_POST['woodev_settings']
    $handler->weekly_license_check(); // must NOT throw
    $this->assertTrue( true ); // reached only if no exception bubbled
}
```

- [ ] **Step 2: Run — expect FAIL** (exception bubbles out of `weekly_license_check()`)

Run: `./vendor/bin/phpunit tests/unit/LicenseOutageGraceTest.php`
Expected: FAIL — uncaught `Exception: server down`.

- [ ] **Step 3: Harden `weekly_license_check()`** — wrap the validate call so a transport failure is swallowed (state is already left untouched because `validate_license()`/`dispatch()` only write on a successful response):

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

(Do NOT add `is_need_license()` gating here — the cron must keep running; a keyless free plugin is already a no-op via the `empty( $license_key )` guard above.)

- [ ] **Step 4: Run tests — expect PASS**, then `composer check` green.

- [ ] **Step 5: Commit** (`fix(s3): weekly license check tolerates server outage (no throw, no relock)`)

---

## Self-review (against the spec)

- Spec §3.1 (L1 method + 5 consumers) → s5-p1 (method) + s5-p2 (5 sites: notices, plugin_row, action-link, form-wrap, do_license_fields). ✓
- Spec §3.2 (outage-grace, cron keeps running) → s5-p3. ✓ (explicitly does NOT gate the cron on the flag — corrects the earlier wrong draft)
- Spec §3.3 (conservative `is_license_required()` seam, byte-for-byte) → s5-p1. ✓
- Spec §6 anti-pirate test (`is_license_valid()`/`is_active()` unaffected by `is_need_license()`) → s5-p2 `test_anti_pirate_flag_does_not_validate_license` + s5-p1 seam tests. ✓
- Spec §4 (signing) → intentionally NOT in this plan (deferred). ✓
- Contracts (§5): no option key / slug / nonce / group / hook / EDD-action changed; only additive methods. ✓

## Related
- Spec: [platform-v2-s3-licensing-need-license-spec.md](platform-v2-s3-licensing-need-license-spec.md)
- Tracker: [platform-v2-program-tracker.md](platform-v2-program-tracker.md)
- Autodev tasks: `.autodev/queue/pending/s5-p1-*.md`, `s5-p2-*.md`, `s5-p3-*.md`
