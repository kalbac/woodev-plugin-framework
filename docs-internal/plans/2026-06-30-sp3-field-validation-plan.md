# SP-3 Field Validation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `required` + `email`/`url`/`tel`/`number(min/max)` validation to the Settings API and both React surfaces (settings page + setup wizard), with a single PHP validator mirrored in JS, an atomic per-field REST error contract, and blur-first live-clear client UX.

**Architecture:** Format is driven by `controlType` (one source). A single `Woodev_Setting::get_validation_error()` is the server truth (required → format → range → legacy type/enum); the handler exposes `validate_values()` for the REST controller's atomic two-pass save. The client mirrors the rules in a pure `validateField()` used both for live per-field errors (local `touched` state in `ControlField`) and for the Save / «Продолжить» gate.

**Tech Stack:** PHP 7.4+ (WordPress, Brain Monkey/Mockery unit tests, WP integration tests), `@wordpress/scripts` React (classic + automatic JSX), SCSS.

**Spec:** `docs-internal/specs/2026-06-30-sp3-field-validation-design.md`

**Conventions for every task:**
- Edit existing PHP/JS source with the built-in `Edit` tool, NEVER Serena `replace_content` (gotcha `serena-replace-content-eol-flip`).
- `@since 2.0.2`, VERSION not bumped. Short arrays `[]`, Yoda conditions, type declarations + docblocks on new methods.
- Run unit tests: `./vendor/bin/phpunit --testsuite=Unit --filter <Name>` (PHPStan crashes locally on Windows — Linux CI is the gate).
- i18n: Russian source strings, no `_n()`.
- No new framework *classes* are added, so no `bin/generate-class-map.php` run is needed (only methods/consts on existing classes). If that changes, regenerate the class map.

---

## Task 1: Add `tel` + `url` control types

**Files:**
- Modify: `woodev/settings-api/class-control.php` (constants block ~line 46-55)
- Modify: `woodev/settings-api/abstract-class-settings.php:457-475` (`get_control_types()`)
- Test: `tests/unit/SettingsApi/SettingsControlTypesTest.php`

- [ ] **Step 1: Write failing tests**

Add to `SettingsControlTypesTest.php` (after `test_get_control_types_includes_multiselect`):

```php
	/**
	 * get_control_types() must include the new tel type.
	 *
	 * @return void
	 */
	public function test_get_control_types_includes_tel(): void {
		$this->assertContains( 'tel', $this->settings->get_control_types() );
	}

	/**
	 * get_control_types() must include the new url type.
	 *
	 * @return void
	 */
	public function test_get_control_types_includes_url(): void {
		$this->assertContains( 'url', $this->settings->get_control_types() );
	}
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/phpunit --filter SettingsControlTypesTest tests/unit/SettingsApi/SettingsControlTypesTest.php`
Expected: FAIL — `'tel'`/`'url'` not in the array.

- [ ] **Step 3: Add the constants**

In `class-control.php`, after `const TYPE_EMAIL = 'email';` (line ~22) add:

```php
		/** @var string the tel (phone) control type */
		const TYPE_TEL = 'tel';

		/** @var string the url control type */
		const TYPE_URL = 'url';
```

- [ ] **Step 4: Register them as valid control types**

In `abstract-class-settings.php` `get_control_types()`, add to the `$control_types` array (after `Woodev_Control::TYPE_EMAIL,`):

```php
			Woodev_Control::TYPE_TEL,
			Woodev_Control::TYPE_URL,
```

- [ ] **Step 5: Run to verify pass**

Run: `./vendor/bin/phpunit --filter SettingsControlTypesTest tests/unit/SettingsApi/SettingsControlTypesTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/settings-api/class-control.php woodev/settings-api/abstract-class-settings.php tests/unit/SettingsApi/SettingsControlTypesTest.php
git commit -m "feat(settings): add tel + url control types (SP-3)"
```

---

## Task 2: `required` flag on `Woodev_Setting`

**Files:**
- Modify: `woodev/settings-api/class-setting.php` (property block ~line 56-59; setters ~line 164-187)
- Modify: `woodev/settings-api/abstract-class-settings.php:77-94` (`register_setting()` args)
- Test: `tests/unit/SettingTest.php`

- [ ] **Step 1: Write failing test**

Add to `SettingTest.php`:

```php
	/**
	 * The required flag defaults to false and round-trips through its setter.
	 *
	 * @return void
	 */
	public function test_required_flag_defaults_false_and_roundtrips() {
		$setting = new \Woodev_Setting();
		$this->assertFalse( $setting->is_required() );
		$setting->set_required( true );
		$this->assertTrue( $setting->is_required() );
	}
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/phpunit --filter test_required_flag_defaults_false_and_roundtrips tests/unit/SettingTest.php`
Expected: FAIL — `Call to undefined method Woodev_Setting::is_required()`.

- [ ] **Step 3: Add the property + setters**

In `class-setting.php`, after the `$sensitive` property (line ~56) add:

```php
		/** @var bool whether this setting must be filled (validated client + server) */
		protected $required = false;
```

After `set_sensitive()` (line ~166) add:

```php
		/**
		 * Whether this setting is required (must be non-empty for requirable controls).
		 *
		 * @since 2.0.2
		 * @return bool
		 */
		public function is_required(): bool {
			return $this->required;
		}

		/**
		 * Sets the required flag.
		 *
		 * @since 2.0.2
		 * @param bool $value required flag.
		 * @return void
		 */
		public function set_required( bool $value ): void {
			$this->required = $value;
		}
```

- [ ] **Step 4: Wire the `required` arg into `register_setting()`**

In `abstract-class-settings.php` `register_setting()`, add `'required' => false,` to the `wp_parse_args` defaults (after `'sensitive' => false,`), and after the `set_sensitive()` call (line ~93) add:

```php
				$setting->set_required( (bool) $args['required'] );
```

- [ ] **Step 5: Run to verify pass**

Run: `./vendor/bin/phpunit --filter test_required_flag_defaults_false_and_roundtrips tests/unit/SettingTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/settings-api/class-setting.php woodev/settings-api/abstract-class-settings.php tests/unit/SettingTest.php
git commit -m "feat(settings): add required flag to Woodev_Setting (SP-3)"
```

---

## Task 3: `get_validation_error()` — the unified validator

**Files:**
- Modify: `woodev/settings-api/class-setting.php` (add the method + helpers; route `update_value()` through it)
- Test: `tests/unit/SettingsApi/SettingValidationTest.php` (NEW)

This is the server source of truth. Rule order: `required` → empty-optional short-circuit → format (email/url/tel/number-range by control type) → legacy `validate_{type}_value` → enum.

- [ ] **Step 1: Write the failing test file**

Create `tests/unit/SettingsApi/SettingValidationTest.php`:

```php
<?php
/**
 * Tests for Woodev_Setting::get_validation_error() — required, format, range.
 *
 * @package Woodev\Tests\Unit\SettingsApi
 */

namespace Woodev\Tests\Unit\SettingsApi;

use Brain\Monkey\Functions;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-setting.php';

/**
 * @covers \Woodev_Setting::get_validation_error
 */
class SettingValidationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return is_string( $email ) && (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
			}
		);
	}

	/**
	 * Builds a setting with a control of the given type.
	 *
	 * @param string $type         setting type.
	 * @param string $control_type control type.
	 * @param bool   $required     required flag.
	 * @param array  $range        optional [min,max].
	 * @return \Woodev_Setting
	 */
	private function make( string $type, string $control_type, bool $required = false, array $range = [] ): \Woodev_Setting {
		$setting = new \Woodev_Setting();
		$setting->set_id( 'f' );
		$setting->set_type( $type );
		$setting->set_required( $required );

		$control = new \Woodev_Control();
		$control->set_type( $control_type );
		if ( isset( $range[0] ) ) {
			$control->set_min( $range[0] );
		}
		if ( isset( $range[1] ) ) {
			$control->set_max( $range[1] );
		}
		$setting->set_control( $control );

		return $setting;
	}

	public function test_required_empty_returns_message(): void {
		$setting = $this->make( 'string', 'text', true );
		$this->assertSame( 'Обязательное поле.', $setting->get_validation_error( '' ) );
		$this->assertSame( 'Обязательное поле.', $setting->get_validation_error( '   ' ) );
	}

	public function test_optional_empty_is_valid(): void {
		$setting = $this->make( 'string', 'text', false );
		$this->assertNull( $setting->get_validation_error( '' ) );
	}

	public function test_required_is_noop_for_toggle_and_range(): void {
		$this->assertNull( $this->make( 'boolean', 'toggle', true )->get_validation_error( false ) );
		$this->assertNull( $this->make( 'integer', 'range', true )->get_validation_error( 0 ) );
	}

	public function test_email_format(): void {
		$setting = $this->make( 'email', 'email' );
		$this->assertNull( $setting->get_validation_error( 'a@b.com' ) );
		$this->assertSame( 'Введите корректный email.', $setting->get_validation_error( 'nope' ) );
	}

	public function test_url_format(): void {
		$setting = $this->make( 'string', 'url' );
		$this->assertNull( $setting->get_validation_error( 'https://woodev.ru' ) );
		$this->assertSame(
			'Введите корректный URL (с http:// или https://).',
			$setting->get_validation_error( 'woodev.ru' )
		);
	}

	public function test_tel_format(): void {
		$setting = $this->make( 'string', 'tel' );
		$this->assertNull( $setting->get_validation_error( '+7 (999) 123-45-67' ) );
		$this->assertSame( 'Введите корректный номер телефона.', $setting->get_validation_error( 'abc' ) );
		$this->assertSame( 'Введите корректный номер телефона.', $setting->get_validation_error( '12' ) );
	}

	public function test_number_range(): void {
		$setting = $this->make( 'integer', 'number', false, [ 0, 100 ] );
		$this->assertNull( $setting->get_validation_error( '50' ) );
		$this->assertSame( 'Значение не меньше 0.', $setting->get_validation_error( '-1' ) );
		$this->assertSame( 'Значение не больше 100.', $setting->get_validation_error( '101' ) );
		$this->assertSame( 'Введите число.', $setting->get_validation_error( 'x' ) );
	}
}
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/phpunit tests/unit/SettingsApi/SettingValidationTest.php`
Expected: FAIL — `Call to undefined method Woodev_Setting::get_validation_error()`.

- [ ] **Step 3: Implement `get_validation_error()` + helpers**

In `class-setting.php`, replace the body of `update_value()` (lines ~308-332) so both branches route through the new validator:

```php
		public function update_value( $value ) {

			if ( $this->is_is_multi() ) {

				$elements = array_map(
					function ( $element ) {
						return $this->sanitize_value( $this->coerce_value( $element ) );
					},
					array_values( (array) $value )
				);

				foreach ( $elements as $element ) {
					$error = $this->get_validation_error( $element );
					if ( null !== $error ) {
						throw new Woodev_Plugin_Exception( $error, 400 );
					}
				}

				$this->set_value( $elements );

			} else {

				$value = $this->sanitize_value( $this->coerce_value( $value ) );

				$error = $this->get_validation_error( $value );
				if ( null !== $error ) {
					throw new Woodev_Plugin_Exception( $error, 400 );
				}

				$this->set_value( $value );
			}
		}
```

Then add the new method + helpers immediately before `validate_value()` (line ~416):

```php
		/**
		 * Returns a human-readable validation error for the given input, or null when valid.
		 *
		 * Single server source of truth for SP-3 validation. Rule order: required →
		 * empty-optional short-circuit → format (by control type) → legacy type check →
		 * enum (options). Mirrored client-side in src/components/validate.js — keep both
		 * in sync (the rule table lives in the SP-3 design spec §4).
		 *
		 * @since 2.0.2
		 * @param mixed $value the raw input value.
		 * @return string|null error message (Russian) or null when valid.
		 */
		public function get_validation_error( $value ): ?string {

			$value        = $this->coerce_value( $value );
			$control_type = $this->control instanceof Woodev_Control ? $this->control->get_type() : null;

			if ( $this->required && self::is_requirable( $control_type ) && self::is_empty_value( $control_type, $value ) ) {
				return 'Обязательное поле.';
			}

			if ( self::is_empty_value( $control_type, $value ) ) {
				return null;
			}

			switch ( $control_type ) {

				case Woodev_Control::TYPE_EMAIL:
					if ( ! is_email( $value ) ) {
						return 'Введите корректный email.';
					}
					break;

				case Woodev_Control::TYPE_URL:
					if ( ! self::is_valid_url( $value ) ) {
						return 'Введите корректный URL (с http:// или https://).';
					}
					break;

				case Woodev_Control::TYPE_TEL:
					if ( ! self::is_valid_tel( $value ) ) {
						return 'Введите корректный номер телефона.';
					}
					break;

				case Woodev_Control::TYPE_NUMBER:
				case Woodev_Control::TYPE_RANGE:
					if ( ! is_numeric( $value ) ) {
						return 'Введите число.';
					}
					$min = $this->control->get_min();
					$max = $this->control->get_max();
					if ( null !== $min && (float) $value < $min ) {
						return sprintf( 'Значение не меньше %s.', self::format_number( $min ) );
					}
					if ( null !== $max && (float) $value > $max ) {
						return sprintf( 'Значение не больше %s.', self::format_number( $max ) );
					}
					break;
			}

			// Legacy type validity (string/url/email/integer/float/boolean).
			if ( ! $this->validate_value( $value ) ) {
				return sprintf( 'Недопустимое значение для типа %s.', $this->type );
			}

			// Enum: accept an option KEY (assoc map) or VALUE (plain list).
			if ( ! empty( $this->options )
				&& ! ( is_scalar( $value ) && array_key_exists( $value, $this->options ) )
				&& ! in_array( $value, $this->options, true ) ) {

				return sprintf(
					'Значение должно быть одним из: %s.',
					Woodev_Helper::list_array_items( $this->options, 'or' )
				);
			}

			return null;
		}

		/**
		 * Whether a `required` flag applies to a given control type.
		 *
		 * Toggle/checkbox/range always carry a value, so requiring them is a no-op.
		 *
		 * @since 2.0.2
		 * @param string|null $control_type control type.
		 * @return bool
		 */
		public static function is_requirable( ?string $control_type ): bool {
			return ! in_array(
				$control_type,
				[ Woodev_Control::TYPE_TOGGLE, Woodev_Control::TYPE_CHECKBOX, Woodev_Control::TYPE_RANGE ],
				true
			);
		}

		/**
		 * Whether a value counts as "empty" for the given control type.
		 *
		 * @since 2.0.2
		 * @param string|null $control_type control type.
		 * @param mixed       $value        value to inspect.
		 * @return bool
		 */
		private static function is_empty_value( ?string $control_type, $value ): bool {

			if ( is_array( $value ) ) {
				return 0 === count( $value );
			}

			if ( in_array( $control_type, [ Woodev_Control::TYPE_SELECT, Woodev_Control::TYPE_RADIO ], true ) ) {
				return '' === (string) $value;
			}

			return '' === trim( (string) $value );
		}

		/**
		 * Permissive phone validator: allowed chars only, at least 5 digits.
		 *
		 * @since 2.0.2
		 * @param mixed $value value to validate.
		 * @return bool
		 */
		private static function is_valid_tel( $value ): bool {

			if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
				return false;
			}

			$value = (string) $value;

			if ( ! preg_match( '/^[\d\s\-\(\)\+]+$/', $value ) ) {
				return false;
			}

			return strlen( (string) preg_replace( '/\D/', '', $value ) ) >= 5;
		}

		/**
		 * Formats a numeric bound without a trailing ".0" for whole numbers.
		 *
		 * @since 2.0.2
		 * @param float $number bound to format.
		 * @return string
		 */
		private static function format_number( float $number ): string {
			return floor( $number ) === $number ? (string) (int) $number : (string) $number;
		}
```

> Note: `is_valid_url()` already exists (private static, line ~455). Reuse it as-is.

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/phpunit tests/unit/SettingsApi/SettingValidationTest.php`
Expected: PASS (all cases).

- [ ] **Step 5: Run the existing setting/enum tests (no regression)**

Run: `./vendor/bin/phpunit tests/unit/SettingsApi/SettingUpdateValueTest.php tests/unit/SettingTest.php`
Expected: PASS — enum key/value acceptance + is_multi per-element still work (they now route through `get_validation_error`).

- [ ] **Step 6: Commit**

```bash
git add woodev/settings-api/class-setting.php tests/unit/SettingsApi/SettingValidationTest.php
git commit -m "feat(settings): unified get_validation_error() (required+format+range) (SP-3)"
```

---

## Task 4: `Field_Schema` emits `required`

**Files:**
- Modify: `woodev/settings-page/class-field-schema.php:50-59` (the `$entry` array)
- Test: `tests/unit/FieldSchemaTest.php`

- [ ] **Step 1: Write failing test**

Add to `FieldSchemaTest.php` (mirror an existing test's handler-mock style):

```php
	/**
	 * from_handler() emits the required flag from the setting.
	 *
	 * @return void
	 */
	public function test_from_handler_emits_required_flag(): void {
		$setting = $this->makeSetting( 'phone', 'string', [ 'controlType' => 'tel' ] );
		$setting->set_required( true );

		$handler = $this->makeHandler( [ $setting ] );
		$schema  = \Woodev\Framework\Settings\Field_Schema::from_handler( $handler );

		$this->assertTrue( $schema['phone']['required'] );
	}
```

> If `FieldSchemaTest.php` lacks `makeSetting`/`makeHandler` helpers, follow the existing per-test construction pattern in that file (build a real `Woodev_Setting` + a stub handler returning it from `get_settings()`/`get_value()`), and assert `$schema['phone']['required'] === true`.

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/phpunit --filter test_from_handler_emits_required_flag tests/unit/FieldSchemaTest.php`
Expected: FAIL — `required` key absent (Undefined array key).

- [ ] **Step 3: Emit `required` in the schema entry**

In `class-field-schema.php`, add to the `$entry` array (after `'tooltip' => ...,`):

```php
				'required'    => $setting->is_required(),
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/phpunit --filter test_from_handler_emits_required_flag tests/unit/FieldSchemaTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full FieldSchema suite (no regression)**

Run: `./vendor/bin/phpunit tests/unit/FieldSchemaTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/settings-page/class-field-schema.php tests/unit/FieldSchemaTest.php
git commit -m "feat(settings): Field_Schema emits required flag (SP-3)"
```

---

## Task 5: Atomic REST save with per-field error map

**Files:**
- Modify: `woodev/settings-api/abstract-class-settings.php` (add `validate_values()` after `update_value()`)
- Modify: `woodev/rest-api/controllers/class-rest-api-settings-page.php:144-196` (`save()`)
- Test (unit): `tests/unit/SettingsRestControllerTest.php` (rewrite the save tests)
- Test (integration): `tests/integration/SettingsPageRestTest.php` (add an invalid-field case)

### 5a — handler `validate_values()`

- [ ] **Step 1: Write failing unit test**

Add to `tests/unit/SettingsApi/SettingValidationTest.php`:

```php
	public function test_handler_validate_values_collects_field_errors(): void {
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( array $args, array $defaults ): array {
				return array_merge( $defaults, $args );
			}
		);

		require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/abstract-class-settings.php';

		$handler = new class( 'test' ) extends \Woodev_Abstract_Settings {
			protected function register_settings() {
				$this->register_setting( 'email', 'email', [ 'required' => true ] );
				$this->register_control( 'email', 'email' );
				$this->register_setting( 'name', 'string', [] );
				$this->register_control( 'name', 'text' );
			}
		};

		$errors = $handler->validate_values( [ 'email' => 'nope', 'name' => 'ok' ] );

		$this->assertSame( [ 'email' => 'Введите корректный email.' ], $errors );
	}
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/phpunit --filter test_handler_validate_values_collects_field_errors tests/unit/SettingsApi/SettingValidationTest.php`
Expected: FAIL — `Call to undefined method ...::validate_values()`.

- [ ] **Step 3: Implement `validate_values()`**

In `abstract-class-settings.php`, after `update_value()` (line ~298) add:

```php
		/**
		 * Validates a map of setting_id => value, returning a map of field errors.
		 *
		 * Read-only: nothing is persisted. Unknown ids and code-managed
		 * (defined-constant) settings are skipped (they cannot be edited). Mirrors
		 * update_value()'s constant guard so the two passes agree.
		 *
		 * @since 2.0.2
		 * @param array<string,mixed> $values setting_id => value.
		 * @return array<string,string> setting_id => error message (empty when all valid).
		 */
		public function validate_values( array $values ): array {

			$errors = [];

			foreach ( $values as $setting_id => $value ) {

				$setting = $this->get_setting( (string) $setting_id );

				if ( ! $setting ) {
					continue;
				}

				$constant = $setting->get_constant_name();
				if ( null !== $constant && defined( $constant ) ) {
					continue;
				}

				$error = $setting->get_validation_error( $value );
				if ( null !== $error ) {
					$errors[ $setting_id ] = $error;
				}
			}

			return $errors;
		}
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/phpunit --filter test_handler_validate_values_collects_field_errors tests/unit/SettingsApi/SettingValidationTest.php`
Expected: PASS.

### 5b — controller atomic two-pass

- [ ] **Step 5: Rewrite the controller save unit tests**

Open `tests/unit/SettingsRestControllerTest.php`. The current tests mock `update_value` directly for the save path. Replace the save-path tests (the ones around lines 95-125 that drive `update_value`) with the atomic contract. The mocked handler must now answer `validate_values()` first:

```php
	public function test_save_returns_error_map_when_validation_fails(): void {
		$handler = \Mockery::mock( \Woodev_Abstract_Settings::class )->makePartial();
		$handler->shouldReceive( 'validate_values' )
			->once()
			->andReturn( [ 'mode' => 'invalid mode' ] );
		$handler->shouldNotReceive( 'update_value' );

		// ... build provider/registry returning $handler for 'test' (reuse this file's existing harness) ...

		$request = $this->makeRequest( 'test', [ 'api_key' => 'good', 'mode' => 'bad' ] );
		$response = $this->controller->save( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'woodev_settings_invalid', $response->get_error_code() );
		$data = $response->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertSame( [ 'mode' => 'invalid mode' ], $data['errors'] );
	}

	public function test_save_persists_all_when_valid(): void {
		$handler = \Mockery::mock( \Woodev_Abstract_Settings::class )->makePartial();
		$handler->shouldReceive( 'validate_values' )->once()->andReturn( [] );
		$handler->shouldReceive( 'update_value' )->once()->with( 'api_key', 'good' );
		$handler->shouldReceive( 'update_value' )->once()->with( 'mode', 'live' );

		// ... provider/registry harness as above ...

		$request  = $this->makeRequest( 'test', [ 'api_key' => 'good', 'mode' => 'live' ] );
		$response = $this->controller->save( $request );

		$this->assertSame( [ 'saved' => true, 'provider' => 'test' ], $this->responseData( $response ) );
	}
```

> Reuse the file's existing request/registry/provider construction helpers (the file already builds these for the prior save tests — keep that scaffolding, only the assertions/mocks for the save path change). Keep any `array_intersect_key` allow-list test by having the section declare `['api_key','mode']`.

- [ ] **Step 6: Run to verify failure**

Run: `./vendor/bin/phpunit tests/unit/SettingsRestControllerTest.php`
Expected: FAIL — controller still calls `update_value` first / does not call `validate_values`.

- [ ] **Step 7: Implement the atomic two-pass `save()`**

In `class-rest-api-settings-page.php`, replace the `foreach ( $values as ... )` block (lines ~168-188) with:

```php
			// Pass 1 — validate everything; persist nothing on any failure.
			$errors = $handler->validate_values( $values );

			if ( ! empty( $errors ) ) {
				return new WP_Error(
					'woodev_settings_invalid',
					__( 'Проверьте правильность заполнения полей.', 'woodev-plugin-framework' ),
					[
						'status' => 400,
						'errors' => $errors,
					]
				);
			}

			// Pass 2 — persist. A throw here is unexpected (already validated) → 500.
			foreach ( $values as $setting_id => $value ) {
				try {
					$handler->update_value( (string) $setting_id, $value );
				} catch ( \Throwable $e ) {
					error_log( sprintf( '[woodev] settings save failed on "%s": %s', $setting_id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for an unexpected persistence failure.
					return new WP_Error(
						'woodev_settings_server_error',
						__( 'Внутренняя ошибка сервера. Попробуйте ещё раз.', 'woodev-plugin-framework' ),
						[ 'status' => 500 ]
					);
				}
			}
```

- [ ] **Step 8: Run to verify pass**

Run: `./vendor/bin/phpunit tests/unit/SettingsRestControllerTest.php`
Expected: PASS.

### 5c — integration coverage

- [ ] **Step 9: Add an integration test for the atomic error map**

Add to `tests/integration/SettingsPageRestTest.php` a test that POSTs an invalid required/email field to a provider whose handler declares it, asserting: HTTP 400, body `code === 'woodev_settings_invalid'`, `data.errors` contains the field, and the stored option is unchanged (nothing persisted). Follow the file's existing REST-dispatch pattern (`rest_do_request` / `WP_REST_Request`).

```php
	public function test_save_rejects_invalid_field_and_persists_nothing(): void {
		// Register a provider whose handler has a required email control (reuse the
		// fixture handler or a local Testable handler as the other tests in this file do).
		$request = new \WP_REST_Request( 'POST', '/woodev/v1/settings/test' );
		$request->set_param( 'values', [ 'manager_email' => 'not-an-email' ] );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'woodev_settings_invalid', $data['code'] );
		$this->assertArrayHasKey( 'manager_email', $data['data']['errors'] );
		// Stored value untouched:
		$this->assertNotSame( 'not-an-email', get_option( 'woodev_test_manager_email' ) );
	}
```

- [ ] **Step 10: Run integration (foreground)**

Run: `MSYS_NO_PATHCONV=1 npx wp-env run tests-cli env TEST_SUITE=integration php /var/www/html/woodev-framework/vendor/bin/phpunit --configuration /var/www/html/woodev-framework/phpunit.xml --testsuite=Integration --filter SettingsPageRestTest`
Expected: PASS. (Run foreground — background wp-env runs get "killed" intermittently.)

- [ ] **Step 11: Commit**

```bash
git add woodev/settings-api/abstract-class-settings.php woodev/rest-api/controllers/class-rest-api-settings-page.php tests/unit/SettingsRestControllerTest.php tests/unit/SettingsApi/SettingValidationTest.php tests/integration/SettingsPageRestTest.php
git commit -m "feat(settings): atomic REST save with per-field error map (SP-3)"
```

---

## Task 6: JS `validateField()` — the client mirror

**Files:**
- Create: `src/components/validate.js`

No JS test runner exists in this project (no `test` script / jest config); correctness is verified by mirroring the PHP rule table (spec §4) exactly, the build, and rig verification. This module is a faithful port of `Woodev_Setting::get_validation_error()`.

- [ ] **Step 1: Create the module**

```js
/**
 * UI-kit — client-side field validation (mirror of the PHP server validator).
 *
 * Faithful port of Woodev_Setting::get_validation_error() — KEEP IN SYNC with
 * woodev/settings-api/class-setting.php. The authoritative rule table lives in
 * the SP-3 design spec §4. The server is the authoritative gate; this only
 * drives client UX (live errors + Save/«Продолжить» gating).
 *
 * @package woodev-plugin-framework
 */

/**
 * Whether a `required` flag applies to a control type (toggle/checkbox/range
 * always carry a value, so requiring them is a no-op).
 *
 * @param {string} controlType resolved control type.
 * @return {boolean} true when required is meaningful.
 */
export function isRequirable( controlType ) {
	return ! [ 'toggle', 'checkbox', 'range' ].includes( controlType );
}

/**
 * Whether a value counts as empty for the given control type.
 *
 * @param {string} controlType resolved control type.
 * @param {*}      value       value to inspect.
 * @return {boolean} true when empty.
 */
export function isEmpty( controlType, value ) {
	if ( Array.isArray( value ) ) {
		return 0 === value.length;
	}
	if ( [ 'select', 'radio' ].includes( controlType ) ) {
		return '' === String( value ?? '' );
	}
	return '' === String( value ?? '' ).trim();
}

/**
 * Permissive phone check: allowed chars only, at least 5 digits.
 *
 * @param {*} value value to validate.
 * @return {boolean} valid.
 */
function isValidTel( value ) {
	const s = String( value );
	if ( ! /^[\d\s\-()+]+$/.test( s ) ) {
		return false;
	}
	return s.replace( /\D/g, '' ).length >= 5;
}

/**
 * Validates a URL: http(s):// prefix + parseable.
 *
 * @param {*} value value to validate.
 * @return {boolean} valid.
 */
function isValidUrl( value ) {
	const s = String( value );
	if ( ! /^https?:\/\//.test( s ) ) {
		return false;
	}
	try {
		// eslint-disable-next-line no-new
		new URL( s );
		return true;
	} catch ( e ) {
		return false;
	}
}

/**
 * Loose email check approximating WP is_email().
 *
 * @param {*} value value to validate.
 * @return {boolean} valid.
 */
function isValidEmail( value ) {
	return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( String( value ) );
}

/**
 * Resolves the control kind from controlType, then legacy inference (mirrors
 * control-field.js resolveControl so validation keys on the same kind).
 *
 * @param {Object} schema field schema slice.
 * @return {string} control kind.
 */
function resolveKind( schema ) {
	if ( schema.controlType ) {
		return schema.controlType;
	}
	if ( 'boolean' === schema.type ) {
		return 'toggle';
	}
	if ( schema.is_multi ) {
		return 'multiselect';
	}
	if ( schema.options && Object.keys( schema.options ).length ) {
		return 'select';
	}
	return 'text';
}

/**
 * Returns a validation error message for a field value, or null when valid.
 *
 * @param {Object} schema field schema slice (controlType, required, min, max…).
 * @param {*}      value  current value.
 * @return {string|null} error message (Russian) or null.
 */
export function validateField( schema, value ) {
	const kind = resolveKind( schema );

	if ( schema.required && isRequirable( kind ) && isEmpty( kind, value ) ) {
		return 'Обязательное поле.';
	}

	if ( isEmpty( kind, value ) ) {
		return null;
	}

	switch ( kind ) {
		case 'email':
			if ( ! isValidEmail( value ) ) {
				return 'Введите корректный email.';
			}
			break;
		case 'url':
			if ( ! isValidUrl( value ) ) {
				return 'Введите корректный URL (с http:// или https://).';
			}
			break;
		case 'tel':
			if ( ! isValidTel( value ) ) {
				return 'Введите корректный номер телефона.';
			}
			break;
		case 'number':
		case 'range': {
			const n = Number( value );
			if ( '' === String( value ).trim() || Number.isNaN( n ) ) {
				return 'Введите число.';
			}
			if ( null != schema.min && n < schema.min ) {
				return `Значение не меньше ${ schema.min }.`;
			}
			if ( null != schema.max && n > schema.max ) {
				return `Значение не больше ${ schema.max }.`;
			}
			break;
		}
		default:
			break;
	}

	return null;
}

/**
 * Validates a whole section's fields against the current values.
 *
 * @param {Object} fields section.fields (settingId => schema).
 * @param {Object} values current edited values (settingId => value).
 * @return {Object} settingId => error message (only invalid fields).
 */
export function validateFields( fields, values ) {
	const errors = {};
	Object.keys( fields || {} ).forEach( ( id ) => {
		const schema = fields[ id ];
		const value = values[ id ] ?? schema.value;
		const error = validateField( schema, value );
		if ( error ) {
			errors[ id ] = error;
		}
	} );
	return errors;
}
```

- [ ] **Step 2: Commit**

```bash
git add src/components/validate.js
git commit -m "feat(settings): client validateField() mirror of PHP validator (SP-3)"
```

---

## Task 7: `FieldRow` — error display + `<abbr>` marker

**Files:**
- Modify: `src/components/field-row.js`

- [ ] **Step 1: Add the `error` prop + render**

Replace `field-row.js` with:

```jsx
/**
 * Woodev UI-kit — field anatomy row.
 *
 * Renders the shared field anatomy used across surfaces:
 *   [ label (+ required marker + tooltip icon) ] / [ control + description + error ]
 *
 * Default layout is vertical (label above control); a surface may override
 * `.woodev-field` to a horizontal grid (settings does). The tooltip uses the
 * native wp `Tooltip` (rendered in a portal via Popover) so long text is never
 * clipped at the viewport edge.
 *
 * Authored in JSX (automatic runtime — WP 6.6+).
 *
 * @package woodev-plugin-framework
 */

import { Tooltip } from '@wordpress/components';
import { InfoIcon } from './icons';

/**
 * @param {Object}    props               component props.
 * @param {string}    [props.label]       field label.
 * @param {boolean}   [props.required]    show the required marker.
 * @param {string}    [props.tooltip]     tooltip text.
 * @param {string}    [props.description] help text under the control.
 * @param {string}    [props.error]       validation error message (red, under control).
 * @param {*}         props.children      the control element(s).
 * @return {JSX.Element} the field row.
 */
export default function FieldRow( { label, required, tooltip, description, error, children } ) {
	return (
		<div className={ `woodev-field${ error ? ' woodev-field--error' : '' }` }>
			{ label && (
				<div className="woodev-field__label">
					{ label }
					{ required && (
						<abbr className="woodev-field__req" title="Обязательное поле">
							*
						</abbr>
					) }
					{ tooltip && (
						<Tooltip text={ tooltip } placement="top">
							<span
								className="woodev-field__tip"
								tabIndex={ 0 }
								role="img"
								aria-label={ tooltip }
							>
								<InfoIcon />
							</span>
						</Tooltip>
					) }
				</div>
			) }
			<div className="woodev-field__control">
				{ children }
				{ description && (
					<div className="woodev-field__desc">{ description }</div>
				) }
				{ error && (
					<div className="woodev-field__error" role="alert">
						{ error }
					</div>
				) }
			</div>
		</div>
	);
}
```

- [ ] **Step 2: Commit**

```bash
git add src/components/field-row.js
git commit -m "feat(ui-kit): FieldRow error display + abbr required marker (SP-3)"
```

---

## Task 8: `ControlField` — touched state + blur/live-clear wiring

**Files:**
- Modify: `src/components/control-field.js`

The component gains: a local `touched` flag, an `error` derived from `validateField` shown only when `touched` (or when forced by the parent via a `showErrors` prop on Save), live-clear on input once errored, and blur handling. Pass `error` + `aria-invalid` to the rendered control and `error` to `withAnatomy`/`FieldRow`. Url/tel render as native inputs (extend the input-type allow-list).

- [ ] **Step 1: Import the validator + element hooks**

At the top of `control-field.js`, change the element import and add the validator:

```js
import { createElement, useState } from '@wordpress/element';
import { validateField, isRequirable } from './validate';
```

(`useState` is already imported — keep one import line.)

- [ ] **Step 2: Thread `error` through `withAnatomy`**

Replace `withAnatomy` so it forwards an `error`:

```js
function withAnatomy( schema, control, error ) {
	return createElement(
		FieldRow,
		{
			label: schema.name,
			required: schema.required && isRequirable( resolveControl( schema ) ),
			tooltip: schema.tooltip,
			description: schema.description,
			error,
		},
		control
	);
}
```

- [ ] **Step 3: Add validation state to `ControlField` + wire blur/live-clear**

Change the `ControlField` signature to accept `showErrors` (forced reveal on Save) and compute the error:

```js
export default function ControlField( { schema, value, onChange, showErrors } ) {
	const [ touched, setTouched ] = useState( false );

	// Constant-managed / sensitive branches are unchanged (no validation) —
	// keep them exactly as before, returning early.

	const error = ( touched || showErrors ) ? validateField( schema, value ) : null;
	const onBlur = () => setTouched( true );
```

Then for **text-like controls** (the `email`/`number`/`date`/`text` default case, plus new `url`/`tel`), pass `type`, `onBlur`, `aria-invalid`, and the error to `FieldRow`. Replace the default case with:

```js
		case 'email':
		case 'url':
		case 'tel':
		case 'number':
		case 'date':
		case 'text':
		default: {
			const type = [ 'email', 'url', 'tel', 'number', 'date' ].includes( control ) ? control : 'text';
			const input = createElement( TextControl, {
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				type,
				value: value ?? '',
				onChange,
				onBlur,
				'aria-invalid': !! error,
			} );

			return withAnatomy(
				schema,
				suffix
					? createElement(
						'div',
						{ className: 'woodev-field__input-row' },
						input,
						createElement( 'span', { className: 'woodev-field__suffix' }, suffix )
					)
					: input,
				error
			);
		}
```

- [ ] **Step 4: Pass `error` on the other validatable controls**

For `textarea`, `select`, `radio`, `richtext`, `multiselect`, `color` — add `error` as the third arg to their `withAnatomy(...)` calls, and add `onBlur` to `TextareaControl`. (Toggle/checkbox and range are non-requirable and skip error display — leave them unchanged.) For `select`/`multiselect` (SelectField) and `radio` (RadioControl), trigger `setTouched(true)` inside their `onChange` wrappers so leaving an emptied required select reveals the error:

```js
			// select example:
			createElement( SelectField, {
				value: value ?? schema.value ?? '',
				options: normalizeOptions( schema.options ),
				onChange: ( next ) => { setTouched( true ); onChange( next ?? '' ); },
			} ),
			...
			error
```

- [ ] **Step 5: Build + visually verify the bundles compile**

Run: `npm run build:settings && npm run build:setup`
Expected: builds succeed, no errors.

- [ ] **Step 6: Commit**

```bash
git add src/components/control-field.js
git commit -m "feat(ui-kit): ControlField blur-first live-clear validation wiring (SP-3)"
```

---

## Task 9: Settings page — Save gate + map server errors

**Files:**
- Modify: `src/settings-page/app.js`
- Modify: `src/settings-page/section-view.js` (thread `showErrors` + per-field server errors into `ControlField`)

- [ ] **Step 1: section-view — accept + forward `showErrors` and `serverErrors`**

In `section-view.js`, extend the signature and pass to `ControlField`:

```jsx
export default function SectionView( { providerId, section, values, onFieldChange, showErrors, serverErrors } ) {
	// ... unchanged connection-block branch ...
	return (
		<div className="woodev-settings__section">
			{ section.description && (
				<p className="woodev-settings__section-desc">{ section.description }</p>
			) }
			{ Object.keys( section.fields ).map( ( settingId ) => (
				<ControlField
					key={ settingId }
					schema={ { ...section.fields[ settingId ], serverError: ( serverErrors || {} )[ settingId ] } }
					value={ values[ settingId ] ?? section.fields[ settingId ].value }
					onChange={ ( next ) => onFieldChange( settingId, next ) }
					showErrors={ showErrors }
				/>
			) ) }
		</div>
	);
}
```

> In `control-field.js`, give a server error precedence when present: `const error = schema.serverError || ( ( touched || showErrors ) ? validateField( schema, value ) : null );` (add `serverError` handling in the same line edited in Task 8 Step 3).

- [ ] **Step 2: app.js — validate-all on Save, reveal errors, block submit, map server errors**

In `app.js`:

1. Add state: `const [ showErrors, setShowErrors ] = useState( {} ); const [ fieldErrors, setFieldErrors ] = useState( {} );` and import `validateFields` from `../components/validate`.

2. In `onSave`, before calling `saveTab`, compute the active tab's section validity and block on failure:

```js
	const onSave = ( providerId, tab ) => {
		// Gather this tab's fields across sections.
		const allFields = {};
		tab.sections.forEach( ( s ) => Object.assign( allFields, s.fields || {} ) );
		const merged = {};
		Object.keys( allFields ).forEach( ( id ) => {
			merged[ id ] = ( edits[ providerId ] || {} )[ id ] ?? allFields[ id ].value;
		} );

		const clientErrors = validateFields( allFields, merged );
		if ( Object.keys( clientErrors ).length > 0 ) {
			setShowErrors( ( p ) => ( { ...p, [ providerId ]: true } ) );
			setFieldErrors( ( p ) => ( { ...p, [ providerId ]: {} } ) );
			return; // block REST
		}

		setSaving( providerId );
		setSaveError( '' );
		setSaved( '' );
		setFieldErrors( ( p ) => ( { ...p, [ providerId ]: {} } ) );

		saveTab( providerId, edits[ providerId ] || {} )
			.then( () => {
				// ... unchanged success path ...
			} )
			.catch( ( err ) => {
				setSaving( '' );
				const map = err && err.data && err.data.errors ? err.data.errors : null;
				if ( map ) {
					setFieldErrors( ( p ) => ( { ...p, [ providerId ]: map } ) );
					setShowErrors( ( p ) => ( { ...p, [ providerId ]: true } ) );
				}
				const message = ( err && err.message ) ||
					__( 'Не удалось сохранить настройки.', 'woodev-plugin-framework' );
				setSaveError( message );
				dispatch( noticesStore ).createErrorNotice( message, { type: 'snackbar' } );
			} );
	};
```

3. In `renderSection`, pass the new props to `SectionView` and the tab to `onSave`:

```jsx
			<SectionView
				key={ `${ tab.id }:${ section.id }` }
				providerId={ tab.id }
				section={ section }
				values={ values }
				onFieldChange={ ( settingId, value ) => onFieldChange( tab.id, settingId, value ) }
				showErrors={ !! showErrors[ tab.id ] }
				serverErrors={ ( fieldErrors[ tab.id ] || {} ) }
			/>
			...
			onClick={ () => onSave( tab.id, tab ) }
```

4. In `onFieldChange`, clear that field's stale server error so live-clear works after a server rejection:

```js
	const onFieldChange = ( providerId, settingId, value ) => {
		setSaved( '' );
		setFieldErrors( ( prev ) => {
			const tabErrs = { ...( prev[ providerId ] || {} ) };
			delete tabErrs[ settingId ];
			return { ...prev, [ providerId ]: tabErrs };
		} );
		setEdits( ( prev ) => ( {
			...prev,
			[ providerId ]: { ...( prev[ providerId ] || {} ), [ settingId ]: value },
		} ) );
	};
```

> Keep the Save button enabled when there are changes (do NOT also disable on invalid — the click reveals errors). Optionally scroll to the first `.woodev-field--error` after `setShowErrors` using a `useEffect` keyed on `showErrors[tab.id]`.

- [ ] **Step 3: Build**

Run: `npm run build:settings`
Expected: success.

- [ ] **Step 4: Commit**

```bash
git add src/settings-page/app.js src/settings-page/section-view.js src/components/control-field.js
git commit -m "feat(settings): Save gate + per-field server error mapping (SP-3)"
```

---

## Task 10: Setup wizard — gate «Продолжить» + map server errors

**Files:**
- Modify: `src/setup-wizard/app.js`
- Modify: `src/setup-wizard/step-view.js` (thread `showErrors` + `serverErrors` into `ControlField`)

- [ ] **Step 1: step-view — forward `showErrors` + `serverErrors`**

In `step-view.js`, accept `showErrors`/`serverErrors` in `StepView` and pass through `renderFields` to each `ControlField` (mirror Task 9 Step 1: `schema={ { ...schema, serverError: (serverErrors||{})[id] } }` and `showErrors`).

- [ ] **Step 2: app.js — gate the current step on «Продолжить»**

In `setup-wizard/app.js`:

1. Import `validateFields` from `../components/validate`; add `const [ showErrors, setShowErrors ] = useState( false ); const [ fieldErrors, setFieldErrors ] = useState( {} );`.

2. In `goNext`, validate the current settings step before saving:

```js
	async function goNext() {
		setError( null );
		if ( isSettings ) {
			const stepValues = {};
			Object.keys( step.fields || {} ).forEach( ( id ) => {
				stepValues[ id ] = ( values[ step.id ] || {} )[ id ] ?? step.fields[ id ].value;
			} );
			const clientErrors = validateFields( step.fields, stepValues );
			if ( Object.keys( clientErrors ).length > 0 ) {
				setShowErrors( true );
				return; // block advance
			}
		}
		setBusy( true );
		try {
			if ( isSettings ) {
				await saveStep( step.id, values[ step.id ] || {} );
			}
			setShowErrors( false );
			setFieldErrors( {} );
			setIndex( index + 1 );
		} catch ( e ) {
			const map = e && e.data && e.data.errors ? e.data.errors : null;
			if ( map ) {
				setFieldErrors( map );
				setShowErrors( true );
			}
			setError( e.message || __( 'Что-то пошло не так. Попробуйте ещё раз.', 'woodev-plugin-framework' ) );
		} finally {
			setBusy( false );
		}
	}
```

3. Reset `showErrors`/`fieldErrors` in `goTo` (already resets `error`); pass `showErrors`/`serverErrors={ fieldErrors }` into the `StepView` element.

> «Пропустить» (`skipStep`) is unchanged — it bypasses validation by design (D7).

- [ ] **Step 3: Build**

Run: `npm run build:setup`
Expected: success.

- [ ] **Step 4: Commit**

```bash
git add src/setup-wizard/app.js src/setup-wizard/step-view.js
git commit -m "feat(setup-wizard): gate Продолжить on step validation (SP-3)"
```

---

## Task 11: SCSS — error styles (both surfaces)

**Files:**
- Modify: `src/components/_field.scss` (shared field error)
- Modify: `src/setup-wizard` step SCSS if the wizard does not consume `_field.scss` for the error (verify; add `.woodev-field__error` there if needed)

- [ ] **Step 1: Add error styles to the shared field partial**

In `src/components/_field.scss` add (using existing token vars — confirm the error token name in `tokens.scss`, e.g. `$error`):

```scss
.woodev-field__req {
	color: $error;
	text-decoration: none;
	margin-left: 2px;
}

.woodev-field__error {
	color: $error;
	font-size: 12px;
	margin-top: 4px;
}

.woodev-field--error {
	.components-text-control__input,
	input[type='text'],
	input[type='email'],
	input[type='url'],
	input[type='tel'],
	input[type='number'],
	input[type='date'] {
		border-color: $error;
		box-shadow: 0 0 0 1px $error;
	}
}
```

- [ ] **Step 2: Build both bundles + verify CSS emitted**

Run: `npm run build:settings && npm run build:setup`
Expected: success; `woodev/assets/build/settings-page/style-index.css` contains `woodev-field__error`.

- [ ] **Step 3: Commit (built assets included — assets-parity CI)**

```bash
git add src/components/_field.scss woodev/assets/build
git commit -m "style(ui-kit): field validation error styling (SP-3)"
```

---

## Task 12: Fixture demo + full build + rig verification

**Files:**
- Modify: `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php` (the «Карьер» `Woodev_Test_Settings` registration, lines ~203-243 + `get_settings_providers()`)

- [ ] **Step 1: Add validation demo fields to the «Карьер» fixture**

In `Woodev_Test_Settings::register_settings()`:
- Mark `api_key` required: change line ~203 to `[ 'name' => 'API-ключ', 'default' => '', 'required' => true ]`.
- Mark `manager_email` required: line ~226 → `[ 'name' => 'E-mail менеджера', 'default' => '', 'required' => true ]`.
- Give `max_weight` a range so number validation shows: change its control (line ~221) to `$this->register_control( 'max_weight', \Woodev_Control::TYPE_NUMBER, [ 'min' => 1, 'max' => 1000 ] );`.
- Add a tel + url demo field to the «Прочее» section. After line ~229 add settings:

```php
			$this->register_setting( 'support_phone', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Телефон поддержки', 'default' => '', 'required' => true ] );
			$this->register_setting( 'tracking_url', \Woodev_Setting::TYPE_STRING, [ 'name' => 'URL отслеживания', 'default' => '' ] );
```

and controls after line ~234:

```php
			$this->register_control( 'support_phone', \Woodev_Control::TYPE_TEL );
			$this->register_control( 'tracking_url', \Woodev_Control::TYPE_URL );
```

- Add the two new ids to the `misc` section in `get_settings_providers()` (line ~373):

```php
						\Woodev\Framework\Settings\Settings_Section::create( 'misc', 'Прочее', [ 'manager_email', 'secret', 'brand_color', 'start_date', 'support_phone', 'tracking_url' ] ),
```

- [ ] **Step 2: Full build**

Run: `npm run build`
Expected: all five bundles build.

- [ ] **Step 3: Full unit suite + phpcs**

Run: `./vendor/bin/phpunit --testsuite=Unit`
Run: `composer phpcs`
Expected: green (count grows by the new tests). Fix any phpcs nits (short arrays, Yoda, alignment).

- [ ] **Step 4: Integration (foreground)**

Run: `MSYS_NO_PATHCONV=1 npx wp-env run tests-cli env TEST_SUITE=integration php /var/www/html/woodev-framework/vendor/bin/phpunit --configuration /var/www/html/woodev-framework/phpunit.xml --testsuite=Integration`
Expected: green.

- [ ] **Step 5: Rig verification on `:8888`** (operator)

Start wp-env if down: `npx wp-env start` (PowerShell). Visit `Woodev → Настройки → Карьер`. Verify:
- `*` markers on required fields (api_key, manager_email, support_phone) — rendered as `<abbr>`.
- Blur an empty required field → "Обязательное поле." appears; type → clears live.
- Bad email/url/tel → format error on blur; fixing clears live.
- `max_weight` outside 1–1000 → range error.
- Click «Сохранить» with an error → all errors revealed, REST not sent (check Network tab), Save not spinning.
- Fix all → save succeeds (snackbar).
- Wizard (`?page=...setup`): a required-empty step blocks «Продолжить» with errors; «Пропустить» still advances.

- [ ] **Step 6: Commit + push branch**

```bash
git add tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php woodev/assets/build
git commit -m "test(fixture): SP-3 validation demo fields on Карьер (SP-3)"
git push -u origin feat/sp3-field-validation
```

---

## Task 13: Codex critic + PR + merge

- [ ] **Step 1: Codex GPT-5.5 critic** on the security-/logic-critical diffs (inline bundle ≤~12KB — split if larger; gotcha `codex-shell-sandbox-broken-windows`). Focus: `get_validation_error()` order + the atomic REST contract + the JS/PHP mirror parity. Re-critic own fixes before commit (`feedback_recritic_own_fixes`).

- [ ] **Step 2: Open PR**, wait for **every CI job pass + state CLEAN** (Docker-Hub 502 on integration = transient → `gh run rerun --failed`).

- [ ] **Step 3:** After operator rig-approval → `gh pr merge <N> --squash --delete-branch` (never `--auto`).

---

## Self-Review (spec coverage)

- D1 flag model → Tasks 1 (tel+url), 2 (required), 4 (emit). ✅
- D2 single validator + JS mirror → Tasks 3 (PHP), 6 (JS). ✅
- D3 required semantics per control → Task 3 `is_requirable`/`is_empty_value` + tests. ✅
- D4 format validators (email/url/tel/number min-max; step = UI hint) → Task 3 (no step enforcement). ✅
- D5 atomic REST error map → Task 5. ✅
- D6 client wiring (blur-first/live-clear, Save gate) → Tasks 7, 8, 9. ✅
- D7 wizard gating → Task 10. ✅
- D8 known property (client gates untouched-empty) → realized by Task 9 Save-gate validating all fields incl. untouched. ✅
- Verification (§8) → Tasks 3/4/5 tests + Task 12 rig. ✅

No placeholders; method/prop names consistent across tasks (`get_validation_error`, `validate_values`, `validateField`, `validateFields`, `isRequirable`, `showErrors`, `serverError`/`serverErrors`, `fieldErrors`).
