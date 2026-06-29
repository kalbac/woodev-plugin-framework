# SP-2 — Secrets + Auth Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the secret-masking hole in the settings page and add a universal, self-contained authorization-block contract (plugin-implemented test/connect callback).

**Architecture:** Three orthogonal additions on top of the SP-1 settings page: (1) field-level `sensitive` + `constant_name` flags on `Woodev_Setting`, honored by `Field_Schema` (never emit the secret) and the handler get/update paths (constant precedence + skip-write); (2) a connection block — a `Settings_Section` flagged `is_connection`, rendered as a self-contained card; (3) a per-`connection_id` test/status seam (two optional interfaces + a `Woodev_Connection_Result` VO + a REST action route). All carrier auth behavior stays in the plugin.

**Tech Stack:** PHP 7.4+ (WordPress Coding Standards, short arrays), Brain Monkey + Mockery (unit), WP test library (integration), `@wordpress/scripts` React (settings-page bundle), Serena-indexed PHP — but edit existing source with the built-in `Edit` tool (EOL-flip gotcha `serena-replace-content-eol-flip`).

**Design spec:** `docs-internal/specs/2026-06-29-sp2-secrets-auth-design.md`

**Cross-cutting (apply throughout):**
- `@since 2.0.2`; do **not** bump `Woodev_Plugin::VERSION`.
- No `_n()` (Russian is source — gotcha `russian-source-i18n-plural-n`).
- After adding any new framework class file, run `php bin/generate-class-map.php` (Task 9) — no Composer in shipped plugins.
- Edit existing `.php`/`.js`/`.scss` with built-in `Edit`/`Write`, never Serena `replace_content` (EOL flip).
- Commit built assets (assets-parity CI); CSS versioned by `filemtime`; LF in `assets/build`.
- Local gates: `composer phpcs`, `composer test:unit`, `npm run build`. PHPStan = Linux CI (local Windows segfault is environmental).
- Branch: `feat/sp2-secrets-auth`. Work on that branch; PR at the end.

---

## Task 0: Create the feature branch

- [ ] **Step 1: Branch from up-to-date main**

```bash
git checkout main && git pull --ff-only
git checkout -b feat/sp2-secrets-auth
```

---

## Task 1: `Woodev_Setting` — `sensitive` + `constant_name` flags

**Files:**
- Modify: `woodev/settings-api/class-setting.php`
- Test: `tests/unit/SettingTest.php` (create if absent)

- [ ] **Step 1: Write the failing test**

Add to `tests/unit/SettingTest.php`:

```php
public function test_sensitive_flag_defaults_false_and_roundtrips() {
    $setting = new \Woodev_Setting();
    $this->assertFalse( $setting->is_sensitive() );
    $setting->set_sensitive( true );
    $this->assertTrue( $setting->is_sensitive() );
}

public function test_constant_name_defaults_null_and_roundtrips() {
    $setting = new \Woodev_Setting();
    $this->assertNull( $setting->get_constant_name() );
    $setting->set_constant_name( 'MY_CARRIER_KEY' );
    $this->assertSame( 'MY_CARRIER_KEY', $setting->get_constant_name() );
}

public function test_get_value_prefers_a_defined_constant_over_stored() {
    if ( ! defined( 'WOODEV_TEST_SECRET_CONST' ) ) {
        define( 'WOODEV_TEST_SECRET_CONST', 'from-config' );
    }
    $setting = new \Woodev_Setting();
    $setting->set_id( 'api_key' );
    $setting->set_type( \Woodev_Setting::TYPE_STRING );
    $setting->set_value( 'from-db' );
    $setting->set_constant_name( 'WOODEV_TEST_SECRET_CONST' );
    $this->assertSame( 'from-config', $setting->get_value() );
}

public function test_get_value_returns_stored_when_constant_undefined() {
    $setting = new \Woodev_Setting();
    $setting->set_id( 'api_key' );
    $setting->set_type( \Woodev_Setting::TYPE_STRING );
    $setting->set_value( 'from-db' );
    $setting->set_constant_name( 'WOODEV_UNDEFINED_CONST_XYZ' );
    $this->assertSame( 'from-db', $setting->get_value() );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SettingTest.php --filter sensitive`
Expected: FAIL — `Call to undefined method Woodev_Setting::is_sensitive()`.

- [ ] **Step 3: Add properties, getters, setters**

In `class-setting.php`, after the `$control` property (line ~52) add:

```php
		/** @var bool whether this setting holds a secret (masked in transport) */
		protected $sensitive = false;

		/** @var string|null name of a PHP constant that, when defined, supplies the value (kept out of the DB) */
		protected $constant_name = null;
```

After `get_control()` add:

```php
		/**
		 * Whether this setting holds a secret value (masked in the schema).
		 *
		 * @since 2.0.2
		 * @return bool
		 */
		public function is_sensitive(): bool {
			return $this->sensitive;
		}

		/**
		 * Sets the sensitive flag.
		 *
		 * @since 2.0.2
		 * @param bool $value sensitive flag.
		 * @return void
		 */
		public function set_sensitive( bool $value ): void {
			$this->sensitive = $value;
		}

		/**
		 * The PHP constant name backing this setting, or null.
		 *
		 * @since 2.0.2
		 * @return string|null
		 */
		public function get_constant_name(): ?string {
			return $this->constant_name;
		}

		/**
		 * Sets the backing constant name.
		 *
		 * @since 2.0.2
		 * @param string|null $value constant name.
		 * @return void
		 */
		public function set_constant_name( ?string $value ): void {
			$this->constant_name = ( null === $value || '' === $value ) ? null : $value;
		}
```

- [ ] **Step 4: Add constant precedence to `get_value()`**

Replace the body of `get_value()` (lines ~123-125):

```php
		public function get_value() {
			if ( null !== $this->constant_name && defined( $this->constant_name ) ) {
				return constant( $this->constant_name );
			}

			return $this->value;
		}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/unit/SettingTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/settings-api/class-setting.php tests/unit/SettingTest.php
git commit -m "feat(settings): add sensitive + constant_name flags to Woodev_Setting"
```

---

## Task 2: `register_setting` threading + handler skip-write under constant

**Files:**
- Modify: `woodev/settings-api/abstract-class-settings.php:60-110` (register_setting) and `:267-286` (update_value)
- Test: `tests/unit/SettingsApiSecretsTest.php` (create) — or extend an existing abstract-settings test

- [ ] **Step 1: Write the failing test**

`tests/unit/SettingsApiSecretsTest.php` defines a tiny concrete handler and asserts threading + skip-write. Follow the existing unit test pattern (see `tests/unit/SettingsPageRegistryTest.php` for fixture-handler style):

```php
public function test_register_setting_threads_sensitive_and_constant_name() {
    $handler = $this->make_handler( function ( $h ) {
        $h->register_setting( 'token', \Woodev_Setting::TYPE_STRING, [
            'name'          => 'Токен',
            'sensitive'     => true,
            'constant_name' => 'WOODEV_TOKEN_CONST',
        ] );
    } );

    $setting = $handler->get_setting( 'token' );
    $this->assertTrue( $setting->is_sensitive() );
    $this->assertSame( 'WOODEV_TOKEN_CONST', $setting->get_constant_name() );
}

public function test_update_value_skips_write_when_constant_defined() {
    if ( ! defined( 'WOODEV_SKIP_CONST' ) ) {
        define( 'WOODEV_SKIP_CONST', 'locked' );
    }
    $saved = [];
    $handler = $this->make_handler( function ( $h ) {
        $h->register_setting( 'token', \Woodev_Setting::TYPE_STRING, [
            'name'          => 'Токен',
            'sensitive'     => true,
            'constant_name' => 'WOODEV_SKIP_CONST',
        ] );
    }, $saved );

    $handler->update_value( 'token', 'attempt-overwrite' );

    // save() must NOT have been invoked for a constant-backed setting.
    $this->assertSame( [], $saved, 'constant-backed setting must not be persisted' );
    $this->assertSame( 'locked', $handler->get_value( 'token' ) );
}
```

> `make_handler()` is a small helper: an anonymous subclass of `\Woodev_Abstract_Settings` whose `register_settings()` runs the closure and whose `save()` records calls into `$saved`. Model it on the handler stubs already used in `tests/unit/SettingsPageRegistryTest.php`.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SettingsApiSecretsTest.php`
Expected: FAIL — `sensitive`/`constant_name` not threaded; `save()` still called.

- [ ] **Step 3: Thread the args in `register_setting`**

In `abstract-class-settings.php`, extend the `wp_parse_args` defaults (line ~79-85):

```php
				$args = wp_parse_args(
					$args,
					[
						'name'          => '',
						'description'   => '',
						'is_multi'      => false,
						'options'       => [],
						'default'       => null,
						'sensitive'     => false,
						'constant_name' => null,
					]
				);
```

After `$setting->set_is_multi( $args['is_multi'] );` (line ~90) add:

```php
				$setting->set_sensitive( (bool) $args['sensitive'] );
				$setting->set_constant_name( null !== $args['constant_name'] ? (string) $args['constant_name'] : null );
```

- [ ] **Step 4: Skip-write in handler `update_value`**

In `update_value()` (line ~274), immediately after the `if ( ! $setting )` guard and before `$setting->update_value( $value );` add:

```php
			// A constant-backed setting is code-managed (wp-config); never persist
			// it to the DB. The user cannot edit it, so an inbound value is ignored.
			$constant = $setting->get_constant_name();
			if ( null !== $constant && defined( $constant ) ) {
				return;
			}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/unit/SettingsApiSecretsTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add woodev/settings-api/abstract-class-settings.php tests/unit/SettingsApiSecretsTest.php
git commit -m "feat(settings): thread sensitive/constant_name in register_setting; skip-write constant-backed settings"
```

---

## Task 3: `Field_Schema` — mask sensitive + constant-backed fields

**Files:**
- Modify: `woodev/settings-page/class-field-schema.php:22-62`
- Test: `tests/unit/FieldSchemaTest.php` (create if absent)

- [ ] **Step 1: Write the failing test**

```php
public function test_sensitive_field_is_masked_with_is_set_flag() {
    $handler = $this->make_handler( function ( $h ) {
        $h->register_setting( 'token', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Токен', 'sensitive' => true ] );
        $h->get_setting( 'token' )->set_value( 's3cr3t' );
    } );

    $schema = \Woodev\Framework\Settings\Field_Schema::from_handler( $handler, [ 'token' ] );

    $this->assertSame( '', $schema['token']['value'], 'secret must not be emitted' );
    $this->assertTrue( $schema['token']['sensitive'] );
    $this->assertTrue( $schema['token']['is_set'] );
}

public function test_unset_sensitive_field_reports_is_set_false() {
    $handler = $this->make_handler( function ( $h ) {
        $h->register_setting( 'token', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Токен', 'sensitive' => true, 'default' => '' ] );
    } );

    $schema = \Woodev\Framework\Settings\Field_Schema::from_handler( $handler, [ 'token' ] );

    $this->assertSame( '', $schema['token']['value'] );
    $this->assertFalse( $schema['token']['is_set'] );
}

public function test_constant_backed_field_is_masked_and_read_only() {
    if ( ! defined( 'WOODEV_FS_CONST' ) ) {
        define( 'WOODEV_FS_CONST', 'from-config' );
    }
    $handler = $this->make_handler( function ( $h ) {
        $h->register_setting( 'token', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Токен', 'sensitive' => true, 'constant_name' => 'WOODEV_FS_CONST' ] );
    } );

    $schema = \Woodev\Framework\Settings\Field_Schema::from_handler( $handler, [ 'token' ] );

    $this->assertSame( '', $schema['token']['value'], 'constant value must not be emitted' );
    $this->assertTrue( $schema['token']['constant_managed'] );
    $this->assertSame( 'WOODEV_FS_CONST', $schema['token']['constant_name'] );
    $this->assertTrue( $schema['token']['is_set'] );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/FieldSchemaTest.php`
Expected: FAIL — `value` still holds the secret; `sensitive`/`is_set`/`constant_managed` keys absent.

- [ ] **Step 3: Mask in `from_handler`**

In `class-field-schema.php`, inside the `foreach` (after `$control = $setting->get_control();`, before building `$entry`), compute masking:

```php
			$constant_name    = $setting->get_constant_name();
			$constant_managed = null !== $constant_name && defined( $constant_name );
			$is_secret        = $setting->is_sensitive() || $constant_managed;
			$stored           = $handler->get_value( $setting->get_id() );
			$is_set           = '' !== (string) ( is_array( $stored ) ? implode( '', $stored ) : $stored );
```

Change the `'value'` line and add masking keys in `$entry`:

```php
			$entry = [
				'type'        => $setting->get_type(),
				'name'        => $setting->get_name(),
				'options'     => $setting->get_options(),
				'value'       => $is_secret ? '' : $stored,
				'is_multi'    => $setting->is_is_multi(),
				'controlType' => $control ? $control->get_type() : null,
				'description' => $control && $control->get_description() ? $control->get_description() : $setting->get_description(),
				'tooltip'     => $control ? $control->get_tooltip() : '',
			];

			if ( $setting->is_sensitive() ) {
				$entry['sensitive'] = true;
			}
			if ( $is_secret ) {
				$entry['is_set'] = $is_set;
			}
			if ( $constant_managed ) {
				$entry['constant_managed'] = true;
				$entry['constant_name']    = $constant_name;
			}
```

> Keep the existing min/max/step block unchanged.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/unit/FieldSchemaTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/settings-page/class-field-schema.php tests/unit/FieldSchemaTest.php
git commit -m "feat(settings): mask sensitive + constant-backed fields in Field_Schema"
```

---

## Task 4: `Woodev_Connection_Result` value object

**Files:**
- Create: `woodev/settings-api/class-connection-result.php`
- Test: `tests/unit/ConnectionResultTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_success_factory() {
    $r = \Woodev_Connection_Result::success( 'Подключено' );
    $this->assertTrue( $r->is_success() );
    $this->assertSame( 'Подключено', $r->get_message() );
}

public function test_failure_factory() {
    $r = \Woodev_Connection_Result::failure( 'Неверный токен' );
    $this->assertFalse( $r->is_success() );
    $this->assertSame( 'Неверный токен', $r->get_message() );
}

public function test_to_array_shape() {
    $r = \Woodev_Connection_Result::failure( 'X' );
    $this->assertSame( [ 'success' => false, 'message' => 'X' ], $r->to_array() );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/ConnectionResultTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create the VO**

`woodev/settings-api/class-connection-result.php`:

```php
<?php
/**
 * Connection test/handshake result value object.
 *
 * @package Woodev\Framework\Settings
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Connection_Result' ) ) :

	/**
	 * Immutable result of a connection test or handshake (the plugin produces it;
	 * the framework only transports it to the React block).
	 *
	 * @since 2.0.2
	 */
	final class Woodev_Connection_Result {

		/** @var bool */
		private $success;

		/** @var string */
		private $message;

		/**
		 * @since 2.0.2
		 * @param bool   $success whether the connection succeeded.
		 * @param string $message human-readable message (Russian source).
		 */
		private function __construct( bool $success, string $message ) {
			$this->success = $success;
			$this->message = $message;
		}

		/**
		 * @since 2.0.2
		 * @param string $message optional message.
		 * @return self
		 */
		public static function success( string $message = '' ): self {
			return new self( true, $message );
		}

		/**
		 * @since 2.0.2
		 * @param string $message failure message.
		 * @return self
		 */
		public static function failure( string $message ): self {
			return new self( false, $message );
		}

		/**
		 * @since 2.0.2
		 * @return bool
		 */
		public function is_success(): bool {
			return $this->success;
		}

		/**
		 * @since 2.0.2
		 * @return string
		 */
		public function get_message(): string {
			return $this->message;
		}

		/**
		 * REST payload shape.
		 *
		 * @since 2.0.2
		 * @return array{success:bool,message:string}
		 */
		public function to_array(): array {
			return [
				'success' => $this->success,
				'message' => $this->message,
			];
		}
	}

endif;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/unit/ConnectionResultTest.php`
Expected: PASS (the unit bootstrap loads framework classes via the Composer classmap; class-map regen for production is Task 9).

- [ ] **Step 5: Commit**

```bash
git add woodev/settings-api/class-connection-result.php tests/unit/ConnectionResultTest.php
git commit -m "feat(settings): add Woodev_Connection_Result value object"
```

---

## Task 5: Connection seam interfaces

**Files:**
- Create: `woodev/settings-page/interface-connection-test.php`
- Create: `woodev/settings-page/interface-connection-status.php`
- Test: `tests/unit/ConnectionInterfacesTest.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_a_handler_can_implement_the_test_interface() {
    $handler = new class {
        public function test_connection( string $connection_id, array $values ): \Woodev_Connection_Result {
            return 'ok' === ( $values['token'] ?? '' )
                ? \Woodev_Connection_Result::success( 'ok' )
                : \Woodev_Connection_Result::failure( 'bad' );
        }
    };
    // The anonymous class is structurally compatible; assert the interface exists
    // and an explicit implementor satisfies instanceof.
    $this->assertTrue( interface_exists( 'Woodev_Settings_Connection_Test' ) );
    $this->assertTrue( interface_exists( 'Woodev_Settings_Connection_Status' ) );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/ConnectionInterfacesTest.php`
Expected: FAIL — `interface_exists` returns false.

- [ ] **Step 3: Create the interfaces**

`woodev/settings-page/interface-connection-test.php`:

```php
<?php
/**
 * Connection-test seam for a settings handler.
 *
 * @package Woodev\Framework\Settings
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'Woodev_Settings_Connection_Test' ) ) :

	/**
	 * A settings handler implements this to provide the per-connection-block test/
	 * connect action. ALL carrier behavior (token exchange, header building, GUID
	 * handshake, the API call) lives in the implementation — the framework only
	 * renders the button, plumbs REST, and transports the result.
	 *
	 * @since 2.0.2
	 */
	interface Woodev_Settings_Connection_Test {

		/**
		 * Tests / performs the connection for one block.
		 *
		 * @since 2.0.2
		 * @param string               $connection_id the connection section id.
		 * @param array<string,mixed>  $values        merged field values (POSTed ∪ stored).
		 * @return \Woodev_Connection_Result
		 */
		public function test_connection( string $connection_id, array $values ): \Woodev_Connection_Result;
	}

endif;
```

`woodev/settings-page/interface-connection-status.php`:

```php
<?php
/**
 * Optional persistent connection-status seam for a settings handler.
 *
 * @package Woodev\Framework\Settings
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'Woodev_Settings_Connection_Status' ) ) :

	/**
	 * A settings handler optionally implements this to drive an on-load status
	 * badge. The framework stores nothing — the plugin caches as it sees fit.
	 *
	 * @since 2.0.2
	 */
	interface Woodev_Settings_Connection_Status {

		/**
		 * Current known status for one block, or null if unknown / not applicable.
		 *
		 * @since 2.0.2
		 * @param string $connection_id the connection section id.
		 * @return \Woodev_Connection_Result|null
		 */
		public function get_connection_status( string $connection_id ): ?\Woodev_Connection_Result;
	}

endif;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/unit/ConnectionInterfacesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/settings-page/interface-connection-test.php woodev/settings-page/interface-connection-status.php tests/unit/ConnectionInterfacesTest.php
git commit -m "feat(settings): add connection test/status seam interfaces"
```

---

## Task 6: `Settings_Section` — `is_connection` + `action_label`

**Files:**
- Modify: `woodev/settings-page/class-settings-section.php`
- Test: `tests/unit/SettingsSectionTest.php` (create if absent)

- [ ] **Step 1: Write the failing test**

```php
public function test_section_defaults_to_non_connection() {
    $s = \Woodev\Framework\Settings\Settings_Section::create( 'general', 'Общие', [ 'a' ] );
    $this->assertFalse( $s->is_connection() );
    $this->assertSame( '', $s->get_action_label() );
}

public function test_connection_section_carries_action_label() {
    $s = \Woodev\Framework\Settings\Settings_Section::create(
        'api', 'Подключение', [ 'token' ], 'Креды API.', true, 'Проверить'
    );
    $this->assertTrue( $s->is_connection() );
    $this->assertSame( 'Проверить', $s->get_action_label() );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SettingsSectionTest.php`
Expected: FAIL — `is_connection()` undefined / `create()` arity.

- [ ] **Step 3: Extend the class**

In `class-settings-section.php`: add two properties after `$description`:

```php
		/** @var bool whether this section is a self-contained connection block */
		private bool $is_connection = false;

		/** @var string label for the block's primary action button (e.g. «Проверить»/«Подключить») */
		private string $action_label = '';
```

Update `__construct` to accept and assign them, and update `create()` to pass them through. The resulting `create()` signature:

```php
		public static function create(
			string $id,
			string $label,
			array $setting_ids,
			string $description = '',
			bool $is_connection = false,
			string $action_label = ''
		): self {
			$section                = new self();
			$section->id            = $id;
			$section->label         = $label;
			$section->setting_ids   = $setting_ids;
			$section->description   = $description;
			$section->is_connection = $is_connection;
			$section->action_label  = $action_label;

			return $section;
		}
```

> Match the existing `__construct`/`create` style in the file (the snippet above mirrors the SP-1 shape: assign the new fields the same way the existing ones are assigned). Add the two getters:

```php
		/**
		 * @since 2.0.2
		 * @return bool
		 */
		public function is_connection(): bool {
			return $this->is_connection;
		}

		/**
		 * @since 2.0.2
		 * @return string
		 */
		public function get_action_label(): string {
			return $this->action_label;
		}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/unit/SettingsSectionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/settings-page/class-settings-section.php tests/unit/SettingsSectionTest.php
git commit -m "feat(settings): add is_connection + action_label to Settings_Section"
```

---

## Task 7: Registry `build_sections` — connection metadata + status

**Files:**
- Modify: `woodev/settings-page/class-settings-page-registry.php:170-184` (build_sections)
- Test: `tests/unit/SettingsPageRegistryTest.php` (extend)

- [ ] **Step 1: Write the failing test**

Extend `SettingsPageRegistryTest.php`:

```php
public function test_build_sections_marks_connection_and_action_label() {
    // Provider with a connection section + a handler implementing the test seam.
    $handler  = $this->make_connection_handler();   // implements Woodev_Settings_Connection_Test
    $provider = \Woodev\Framework\Settings\Settings_Provider::create(
        'carrier', 'Перевозчик', $handler,
        [ \Woodev\Framework\Settings\Settings_Section::create( 'api', 'Подключение', [ 'token' ], '', true, 'Проверить' ) ]
    );

    $registry = \Woodev\Framework\Settings\Settings_Page_Registry::instance();
    $sections = $this->call_private( $registry, 'build_sections', [ $provider ] );

    $this->assertTrue( $sections[0]['is_connection'] );
    $this->assertSame( 'Проверить', $sections[0]['action_label'] );
    $this->assertTrue( $sections[0]['supports_test'] );
}

public function test_build_sections_includes_status_when_handler_provides_one() {
    $handler  = $this->make_connection_handler_with_status();  // also implements _Connection_Status
    $provider = \Woodev\Framework\Settings\Settings_Provider::create(
        'carrier', 'Перевозчик', $handler,
        [ \Woodev\Framework\Settings\Settings_Section::create( 'api', 'Подключение', [ 'token' ], '', true, 'Проверить' ) ]
    );

    $registry = \Woodev\Framework\Settings\Settings_Page_Registry::instance();
    $sections = $this->call_private( $registry, 'build_sections', [ $provider ] );

    $this->assertSame( [ 'success' => true, 'message' => 'Подключено' ], $sections[0]['status'] );
}
```

> `make_connection_handler()` / `_with_status()` are local helpers: anonymous `\Woodev_Abstract_Settings` subclasses that also `implements \Woodev_Settings_Connection_Test` (and `_Connection_Status`). `call_private()` is the reflection helper already used in the suite (guard `setAccessible` with `PHP_VERSION_ID < 80100` — gotcha `reflection-setaccessible-version-guard`).

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SettingsPageRegistryTest.php --filter connection`
Expected: FAIL — `is_connection`/`action_label`/`supports_test`/`status` keys absent.

- [ ] **Step 3: Extend `build_sections`**

Replace the section-array build inside `build_sections()` with:

```php
		foreach ( $provider->get_sections() as $section ) {
			$entry = [
				'id'          => $section->get_id(),
				'label'       => $section->get_label(),
				'description' => $section->get_description(),
				'fields'      => Field_Schema::from_handler( $handler, $section->get_setting_ids() ),
			];

			if ( $section->is_connection() ) {
				$entry['is_connection'] = true;
				$entry['action_label']  = $section->get_action_label();
				$entry['supports_test'] = $handler instanceof \Woodev_Settings_Connection_Test;

				if ( $handler instanceof \Woodev_Settings_Connection_Status ) {
					$status = $handler->get_connection_status( $section->get_id() );
					if ( null !== $status ) {
						$entry['status'] = $status->to_array();
					}
				}
			}

			$sections[] = $entry;
		}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/unit/SettingsPageRegistryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add woodev/settings-page/class-settings-page-registry.php tests/unit/SettingsPageRegistryTest.php
git commit -m "feat(settings): surface connection metadata + status in the tab schema"
```

---

## Task 8: REST — connection-test action route

**Files:**
- Modify: `woodev/rest-api/controllers/class-rest-api-settings-page.php`
- Test: `tests/integration/SettingsPageRestTest.php` (extend)

- [ ] **Step 1: Write the failing test**

Extend `SettingsPageRestTest.php` (integration — real WP REST). Register a provider whose handler implements the test seam and a connection section with a `sensitive` `token`:

```php
public function test_test_connection_merges_stored_secret_for_untouched_field() {
    // The provider's handler returns success only when it receives token === 'good'.
    // Pre-store 'good'; POST an EMPTY body → the route must merge the stored secret.
    $this->seed_provider_with_connection( 'good' );

    $request = new WP_REST_Request( 'POST', '/woodev/v1/settings/carrier/connection/api/test' );
    $request->set_param( 'values', [] ); // untouched: nothing typed
    $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

    $response = rest_get_server()->dispatch( $request );
    $this->assertSame( 200, $response->get_status() );
    $this->assertTrue( $response->get_data()['success'] );
}

public function test_test_connection_uses_posted_value_over_stored() {
    $this->seed_provider_with_connection( 'good' ); // stored is good
    $request = new WP_REST_Request( 'POST', '/woodev/v1/settings/carrier/connection/api/test' );
    $request->set_param( 'values', [ 'token' => 'bad' ] ); // user typed a wrong new token
    $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

    $response = rest_get_server()->dispatch( $request );
    $this->assertFalse( $response->get_data()['success'] );
}

public function test_test_connection_requires_capability() {
    $this->seed_provider_with_connection( 'good' );
    wp_set_current_user( $this->factory->user->create( [ 'role' => 'subscriber' ] ) );
    $request = new WP_REST_Request( 'POST', '/woodev/v1/settings/carrier/connection/api/test' );
    $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
    $response = rest_get_server()->dispatch( $request );
    $this->assertSame( 403, $response->get_status() );
}
```

> Reuse the existing `SettingsPageRestTest` harness for provider registration + admin user. `seed_provider_with_connection( $stored )` registers a provider whose handler implements `Woodev_Settings_Connection_Test` (success iff `$values['token'] === 'good'`) and stores `$stored` into the `token` option. The 403 case uses a subscriber (admin gate); for a WC-cap provider an EDITOR is the right "lacks-cap-but-reaches-admin" role — gotcha `wc-blocks-subscriber-wp-admin-403-test`.

- [ ] **Step 2: Run test to verify it fails**

Run integration (see CLAUDE.md / spec for the wp-env command):
`MSYS_NO_PATHCONV=1 npx wp-env run tests-cli env TEST_SUITE=integration php /var/www/html/woodev-framework/vendor/bin/phpunit --configuration /var/www/html/woodev-framework/phpunit.xml --testsuite=Integration --filter test_connection`
Expected: FAIL — route 404 (not registered).

- [ ] **Step 3: Register the route**

In `register_routes()`, after the `/settings/(?P<provider_id>[\w-]+)` route add:

```php
			register_rest_route(
				$base,
				'/settings/(?P<provider_id>[\w-]+)/connection/(?P<connection_id>[\w-]+)/test',
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'test_connection' ],
					'permission_callback' => [ $this, 'save_permissions_check' ],
				]
			);
```

- [ ] **Step 4: Implement `test_connection()`**

Add the method (mirrors `save()` scoping + the seam call + the stored-secret merge):

```php
		/**
		 * Runs a connection block's test/connect action through the plugin callback.
		 *
		 * Merges the POSTed (unsaved) values with the stored values for the block's
		 * declared setting ids, so an untouched (masked) secret still reaches the
		 * plugin's test. The plugin owns all auth behavior; the framework only
		 * transports the Woodev_Connection_Result.
		 *
		 * @internal
		 * @since 2.0.2
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function test_connection( $request ) {
			$provider_id   = (string) $request->get_param( 'provider_id' );
			$connection_id = (string) $request->get_param( 'connection_id' );
			$provider      = $this->registry->get_provider( $provider_id );

			if ( null === $provider ) {
				return new WP_Error(
					'woodev_settings_unknown_provider',
					__( 'Неизвестная вкладка настроек.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			$handler = $provider->get_handler();

			if ( ! $handler instanceof \Woodev_Settings_Connection_Test ) {
				return new WP_Error(
					'woodev_settings_no_connection_test',
					__( 'Проверка подключения для этого раздела недоступна.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			// Find the connection section and its declared setting ids.
			$section_ids = null;
			foreach ( $provider->get_sections() as $section ) {
				if ( $section->get_id() === $connection_id && $section->is_connection() ) {
					$section_ids = $section->get_setting_ids();
					break;
				}
			}

			if ( null === $section_ids ) {
				return new WP_Error(
					'woodev_settings_unknown_connection',
					__( 'Неизвестный блок подключения.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			$posted = (array) $request->get_param( 'values' );
			$posted = array_intersect_key( $posted, array_flip( $section_ids ) );

			// Merge: POSTed value wins; otherwise fall back to the stored value so an
			// untouched (masked) secret is still available to the test.
			$merged = [];
			foreach ( $section_ids as $setting_id ) {
				if ( array_key_exists( $setting_id, $posted ) && '' !== (string) $posted[ $setting_id ] ) {
					$merged[ $setting_id ] = $posted[ $setting_id ];
				} else {
					try {
						$merged[ $setting_id ] = $handler->get_value( $setting_id );
					} catch ( \Woodev_Plugin_Exception $e ) {
						$merged[ $setting_id ] = null;
					}
				}
			}

			try {
				$result = $handler->test_connection( $connection_id, $merged );
			} catch ( \Throwable $e ) {
				error_log( sprintf( '[woodev] connection test failed for %s/%s: %s', $provider_id, $connection_id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for an unexpected callback failure.
				return new WP_Error(
					'woodev_settings_connection_error',
					__( 'Ошибка при проверке подключения.', 'woodev-plugin-framework' ),
					[ 'status' => 500 ]
				);
			}

			return rest_ensure_response( $result->to_array() );
		}
```

- [ ] **Step 5: Run tests to verify they pass**

Run the integration filter again.
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add woodev/rest-api/controllers/class-rest-api-settings-page.php tests/integration/SettingsPageRestTest.php
git commit -m "feat(settings): add connection-test REST action with stored-secret merge"
```

---

## Task 9: Regenerate the class map

**Files:**
- Modify: `woodev/class-map.php` (generated)

- [ ] **Step 1: Regenerate**

Run: `php bin/generate-class-map.php`

- [ ] **Step 2: Verify the new classes/interfaces are present**

Run: `git diff woodev/class-map.php`
Expected: new entries for `Woodev_Connection_Result`, `Woodev_Settings_Connection_Test`, `Woodev_Settings_Connection_Status`.

- [ ] **Step 3: Run the completeness test**

Run: `./vendor/bin/phpunit tests/unit/ClassMapCompletenessTest.php` (or the file's actual name — see `tests/unit`)
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add woodev/class-map.php
git commit -m "chore(settings): regenerate class map for SP-2 classes"
```

---

## Task 10: React — `ControlField` sensitive + constant-managed rendering

**Files:**
- Modify: `src/components/control-field.js`
- (No JS unit harness in repo — verified by build + rig; logic kept simple.)

- [ ] **Step 1: Extend `PasswordControl` to support masking placeholder + clear**

Replace `PasswordControl` with a version accepting `isSet` + `onClear`:

```js
function PasswordControl( { value, onChange, isSet, onClear } ) {
	const [ show, setShow ] = useState( false );

	return createElement(
		'div',
		{ className: 'woodev-field__password' },
		createElement( TextControl, {
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
			type: show ? 'text' : 'password',
			value: value ?? '',
			placeholder: isSet ? '•••••• (сохранено)' : '',
			onChange,
		} ),
		createElement(
			'button',
			{
				type: 'button',
				className: 'woodev-field__password-toggle',
				onClick: () => setShow( ( s ) => ! s ),
				'aria-label': show ? 'Скрыть' : 'Показать',
				'aria-pressed': show,
			},
			createElement(
				'svg',
				{ width: 18, height: 18, viewBox: '0 0 24 24', fill: 'none', 'aria-hidden': true },
				show
					? createElement( 'path', { d: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7zm10 3a3 3 0 100-6 3 3 0 000 6zM3 3l18 18', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' } )
					: createElement( 'path', { d: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7zm10 3a3 3 0 100-6 3 3 0 000 6z', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' } )
			)
		),
		isSet && onClear &&
			createElement(
				'button',
				{ type: 'button', className: 'woodev-field__password-clear', onClick: onClear },
				'Очистить'
			)
	);
}
```

- [ ] **Step 2: Branch on `constant_managed` + `sensitive` at the top of `ControlField`**

In `ControlField`, before `const control = resolveControl( schema );` add:

```js
	// A wp-config-backed secret is read-only and never editable here.
	if ( schema.constant_managed ) {
		return withAnatomy(
			schema,
			createElement(
				'div',
				{ className: 'woodev-field__constant' },
				createElement( 'code', null, schema.constant_name ),
				createElement(
					'span',
					{ className: 'woodev-field__constant-note' },
					' — задано в wp-config.php'
				)
			)
		);
	}

	// A sensitive value is masked: empty input + "saved" placeholder + clear.
	if ( schema.sensitive ) {
		return withAnatomy(
			schema,
			createElement( PasswordControl, {
				value: value ?? '',
				isSet: !! schema.is_set,
				onChange,
				onClear: () => onChange( '' ),
			} )
		);
	}
```

> `onChange('')` paired with the clear button is the explicit-wipe path (spec §1). Note: `SectionView` passes `value={ values[id] ?? schema.value }`; because a sensitive `schema.value` is `''`, an untouched field's value stays `''` (not sent on save → preserved).

- [ ] **Step 3: Build to verify it compiles**

Run: `npm run build`
Expected: settings-page bundle compiles, no errors.

- [ ] **Step 4: Commit**

```bash
git add src/components/control-field.js woodev/assets/build/
git commit -m "feat(settings): mask sensitive + render constant-managed fields in ControlField"
```

---

## Task 11: React — connection block (card + action + status) + `rest.js`

**Files:**
- Create: `src/settings-page/connection-block.js`
- Modify: `src/settings-page/section-view.js`
- Modify: `src/settings-page/rest.js`
- Modify: `src/settings-page/style.scss`

- [ ] **Step 1: Add `testConnection` to `rest.js`**

Mirror the existing `saveTab` (same nonce/headers). Add:

```js
export function testConnection( providerId, connectionId, values ) {
	return apiFetch( {
		path: `${ root }/${ providerId }/connection/${ connectionId }/test`,
		method: 'POST',
		data: { values },
	} );
}
```

> Use the same `apiFetch`/`root`/nonce wiring already present in `rest.js` (read the file; match `saveTab`). `root` is the `woodev/v1/settings` base.

- [ ] **Step 2: Create `connection-block.js`**

```js
/**
 * Self-contained connection block: credential fields + a primary action button
 * (test/connect) + ephemeral result + optional on-load status badge.
 *
 * @package woodev-plugin-framework
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import ControlField from '../components/control-field';
import { testConnection } from './rest';

export default function ConnectionBlock( { providerId, section, values, onFieldChange } ) {
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( section.status || null );

	const run = () => {
		setBusy( true );
		setResult( null );
		testConnection( providerId, section.id, values )
			.then( ( res ) => setResult( res ) )
			.catch( ( err ) =>
				setResult( { success: false, message: ( err && err.message ) || __( 'Ошибка проверки.', 'woodev-plugin-framework' ) } )
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="woodev-connection">
			{ section.description && (
				<p className="woodev-connection__desc">{ section.description }</p>
			) }
			{ Object.keys( section.fields ).map( ( settingId ) => (
				<ControlField
					key={ settingId }
					schema={ section.fields[ settingId ] }
					value={ values[ settingId ] ?? section.fields[ settingId ].value }
					onChange={ ( next ) => onFieldChange( settingId, next ) }
				/>
			) ) }
			<div className="woodev-connection__action">
				{ section.supports_test && (
					<Button variant="secondary" isBusy={ busy } disabled={ busy } onClick={ run }>
						{ section.action_label || __( 'Проверить подключение', 'woodev-plugin-framework' ) }
					</Button>
				) }
				{ result && (
					<span
						className={ `woodev-connection__result is-${ result.success ? 'ok' : 'error' }` }
					>
						{ result.message }
					</span>
				) }
			</div>
		</div>
	);
}
```

- [ ] **Step 3: Route connection sections through `ConnectionBlock` in `section-view.js`**

Replace `section-view.js` body so a connection section renders the block (it needs `providerId`, threaded from `app.js`):

```js
import ControlField from '../components/control-field';
import ConnectionBlock from './connection-block';

export default function SectionView( { providerId, section, values, onFieldChange } ) {
	if ( ! section ) {
		return null;
	}

	if ( section.is_connection ) {
		return (
			<ConnectionBlock
				providerId={ providerId }
				section={ section }
				values={ values }
				onFieldChange={ onFieldChange }
			/>
		);
	}

	return (
		<div className="woodev-settings__section">
			{ section.description && (
				<p className="woodev-settings__section-desc">{ section.description }</p>
			) }
			{ Object.keys( section.fields ).map( ( settingId ) => (
				<ControlField
					key={ settingId }
					schema={ section.fields[ settingId ] }
					value={ values[ settingId ] ?? section.fields[ settingId ].value }
					onChange={ ( next ) => onFieldChange( settingId, next ) }
				/>
			) ) }
		</div>
	);
}
```

- [ ] **Step 4: Pass `providerId` into `SectionView` from `app.js`**

In `app.js` `renderSection`, update the `<SectionView … />` usage to pass `providerId={ tab.id }`:

```js
					<SectionView
						providerId={ tab.id }
						section={ section }
						values={ values }
						onFieldChange={ ( settingId, value ) =>
							onFieldChange( tab.id, settingId, value )
						}
					/>
```

- [ ] **Step 5: Add styles**

Append to `src/settings-page/style.scss` (use kit tokens via the existing `@use`; if the file does not yet `@use` tokens, add `@use '../components/tokens' as wd;` at the top):

```scss
.woodev-connection {
	border: 1px solid wd.$border;
	border-radius: wd.$radius;
	padding: wd.$gap-lg;
	background: wd.$surface;

	&__desc {
		margin: 0 0 wd.$gap;
		color: wd.$muted;
	}

	&__action {
		display: flex;
		align-items: center;
		gap: wd.$gap;
		margin-top: wd.$gap;
	}

	&__result {
		font-size: 13px;

		&.is-ok { color: wd.$ok; }
		&.is-error { color: wd.$error; }
	}
}

.woodev-field__constant {
	display: inline-flex;
	align-items: center;
	gap: wd.$gap-xs;

	code {
		background: wd.$accent-soft;
		padding: 2px 6px;
		border-radius: wd.$radius-sm;
	}

	&-note { color: wd.$muted; }
}

.woodev-field__password-clear {
	appearance: none;
	background: none;
	border: 0;
	margin-left: wd.$gap-sm;
	color: wd.$muted;
	cursor: pointer;
	font-size: 12px;

	&:hover { color: wd.$accent-strong; }
}
```

- [ ] **Step 6: Build**

Run: `npm run build`
Expected: settings-page bundle compiles cleanly.

- [ ] **Step 7: Commit**

```bash
git add src/settings-page/ woodev/assets/build/
git commit -m "feat(settings): self-contained connection block (test/connect + status) in React"
```

---

## Task 12: Fixture — connection + handshake blocks on «Карьер»

**Files:**
- Modify: `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php`

- [ ] **Step 1: Add connection settings + a sensitive/constant field to the handler**

In `Woodev_Test_Settings::register_settings()` (after the existing «misc» block, ~line 234) add:

```php
			// Connection block fields (SP-2 fixture).
			$this->register_setting( 'conn_login', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Логин' ] );
			$this->register_setting( 'conn_password', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Пароль', 'sensitive' => true ] );
			$this->register_setting( 'conn_token', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Токен', 'sensitive' => true, 'constant_name' => 'WOODEV_DEMO_TOKEN' ] );

			$this->register_control( 'conn_login', \Woodev_Control::TYPE_TEXT );
			$this->register_control( 'conn_password', \Woodev_Control::TYPE_PASSWORD );
			$this->register_control( 'conn_token', \Woodev_Control::TYPE_PASSWORD );
```

- [ ] **Step 2: Implement the seam on the handler class**

Change the `Woodev_Test_Settings` class declaration to implement both interfaces, and add the methods:

```php
	class Woodev_Test_Settings extends \Woodev_Abstract_Settings implements \Woodev_Settings_Connection_Test, \Woodev_Settings_Connection_Status {

		// … existing register_settings() …

		public function test_connection( string $connection_id, array $values ): \Woodev_Connection_Result {
			if ( 'api' === $connection_id ) {
				return ( ( $values['conn_login'] ?? '' ) !== '' )
					? \Woodev_Connection_Result::success( 'Подключение успешно.' )
					: \Woodev_Connection_Result::failure( 'Укажите логин.' );
			}

			// 'widget' handshake block (no input fields): always "connects".
			return \Woodev_Connection_Result::success( 'Экземпляр зарегистрирован (GUID получен).' );
		}

		public function get_connection_status( string $connection_id ): ?\Woodev_Connection_Result {
			return null; // demo: no persisted status.
		}
	}
```

- [ ] **Step 3: Declare the connection + handshake sections in the provider**

In `get_settings_providers()`, add two sections after the existing «misc» section (~line 334):

```php
					\Woodev\Framework\Settings\Settings_Section::create(
						'api', 'Подключение', [ 'conn_login', 'conn_password', 'conn_token' ],
						'Учётные данные для доступа к API перевозчика.', true, 'Проверить подключение'
					),
					\Woodev\Framework\Settings\Settings_Section::create(
						'widget', 'Виджет ЛК', [],
						'Регистрация экземпляра магазина в личном кабинете перевозчика.', true, 'Подключить'
					),
```

- [ ] **Step 4: Run the unit + integration suites**

Run: `composer test:unit`
Then the integration filter for settings (wp-env command as in Task 8).
Expected: PASS (incl. the Task 7/8 tests that lean on this fixture).

- [ ] **Step 5: Commit**

```bash
git add tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php
git commit -m "test(settings): add connection + handshake blocks to the Карьер fixture"
```

---

## Task 13: Full verification + PR

- [ ] **Step 1: Local gates**

```bash
composer phpcs
composer test:unit
npm run build
git status   # built assets committed, working tree clean
```
Expected: phpcs clean; unit green; build clean; no uncommitted build drift.

- [ ] **Step 2: Independent critic pass (Codex GPT-5.5, inline bundle)**

Bundle the spec + diffs + the touched source (keep < ~30KB; drop scss). Run the inline-bundle critic (gotcha `codex-shell-sandbox-broken-windows`). Triage findings with the operator; re-critic own in-place fixes before committing them.

- [ ] **Step 3: Push + open PR**

```bash
git push -u origin feat/sp2-secrets-auth
gh pr create --title "feat(settings): secrets masking + connection auth contract (SP-2)" --body "<summary>"
```

- [ ] **Step 4: Verify every CI job = pass + state CLEAN, then operator rig-review on :8888**

The settings React surface (connection card, masked fields, constant-managed note, «Проверить»/«Подключить») is a **visual** change — do not merge without the operator's rig approval. After approval: `gh pr merge <N> --squash --delete-branch` (never `--auto`).

---

## Self-Review

**Spec coverage:**
- Masking (`sensitive`) → Tasks 1, 3, 10. Save preserve-on-unchanged → automatic via dirty-tracking (REST `save()` only writes sent fields, Task 8 reference) + clear affordance (Task 10). ✓
- `constant_name` (precedence, skip-write, read-only UI, always-masked) → Tasks 1, 2, 3, 10. ✓
- Connection block (is_connection, 1..N, free-form + handshake-no-fields) → Tasks 6, 7, 11, 12. ✓
- Seam (test + status interfaces, `connection_id` routing, `Woodev_Connection_Result`) → Tasks 4, 5, 7, 8. ✓
- REST test route + stored-secret merge → Task 8. ✓
- Frontend (sensitive control, constant note, connection card) → Tasks 10, 11. ✓
- Testing + fixture → Tasks 1–8 (unit/integration) + Task 12 (fixture). ✓
- Cross-cutting (class-map, no `_n()`, assets, `@since 2.0.2`) → Task 9 + header + throughout. ✓
- Scope exclusions (at-rest encryption, presets, carrier logic, stateful connected) → none implemented (correct). ✓

**Placeholder scan:** No "TBD"/"implement later". Helper functions (`make_handler`, `seed_provider_with_connection`, `call_private`) are described with their behavior + the existing patterns to model on; `rest.js` `root`/`apiFetch` wiring is "match the existing `saveTab`" because the file already defines it (read at execution). These are grounded references, not placeholders.

**Type consistency:** `is_sensitive()`/`set_sensitive()`, `get_constant_name()`/`set_constant_name()`, `is_connection()`/`get_action_label()`, `test_connection(string,array):Woodev_Connection_Result`, `get_connection_status(string):?Woodev_Connection_Result`, `Woodev_Connection_Result::success/failure/is_success/get_message/to_array`, schema keys `sensitive`/`is_set`/`constant_managed`/`constant_name`/`is_connection`/`action_label`/`supports_test`/`status` — used consistently across PHP (Tasks 1–8) and React (Tasks 10–11). ✓
