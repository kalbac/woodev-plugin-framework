# Conditional / dependent fields (show_if) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a plugin declare `show_if` on a setting so a field is shown only when other fields hold certain values; a hidden field never blocks Save / «Продолжить» and is not persisted.

**Architecture:** A new per-field `show_if` attribute (array or callback → array) carries a flat WP_Query-style condition group (`relation` + `{setting, operator, value}`). A single pure evaluator, mirrored PHP↔JS, resolves visibility. The server strips hidden fields from a submitted values map (`filter_visible_values()`) at the top of both REST save paths; both React surfaces filter hidden fields from render and from their client validation gate.

**Tech Stack:** PHP 7.4+ (Woodev Settings API), React via `@wordpress/element`/`@wordpress/scripts`, Brain Monkey + Mockery (unit), PHPUnit.

**Spec:** `docs-internal/specs/2026-07-05-conditional-fields-design.md`. `@since 2.0.2` — VERSION not bumped.

**Global rules (from AGENTS.md + gotchas):**
- Edit existing `.php`/`.js` with the built-in `Edit` tool, NOT Serena `replace_content` (EOL flip — gotcha `serena-replace-content-eol-flip`).
- Short array syntax `[]`, Yoda conditions, type declarations, docblocks with `@since 2.0.2`.
- Russian source strings; never `_n()` (gotcha `russian-source-i18n-plural-n`).
- All work on branch `feat/conditional-fields` (already created). Never commit to `main`.
- PHPStan crashes locally on Windows (`phpstan-windows-parallel-worker-segfault`) — rely on Linux CI; run `composer phpcs` + `composer test:unit` locally each task.
- No new class files → class-map regen NOT needed. Confirm at Task 10.

---

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `woodev/settings-api/class-setting.php` | `evaluate_conditions()` (pure static), `$show_if` + `set_show_if()`/`get_show_if_conditions()`/`is_visible()` | T1, T2 |
| `woodev/settings-api/abstract-class-settings.php` | `register_setting()` wires `show_if`; `filter_visible_values()` + `effective_condition_values()` | T2, T3 |
| `woodev/settings-page/class-field-schema.php` | Emit `show_if` into the field schema | T4 |
| `woodev/rest-api/controllers/class-rest-api-settings-page.php` | Strip hidden before validate/persist | T5 |
| `woodev/rest-api/controllers/class-rest-api-setup.php` | Strip hidden before per-field save | T5 |
| `src/components/validate.js` | `evaluateConditions()` + `isFieldVisible()` (JS mirror) | T6 |
| `src/settings-page/section-view.js`, `src/settings-page/app.js` | Filter hidden on render + save gate | T7 |
| `src/setup-wizard/step-view.js`, `src/setup-wizard/app.js` | Filter hidden on render + save gate | T8 |
| `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php` | «Карьер» show_if demo | T9 |
| `tests/unit/SettingsApi/ConditionalFieldsTest.php` | Unit tests for the evaluator + resolver + filter | T1–T3 |
| `docs-internal/adr/`, `docs-internal/FUTURE-BACKLOG.md` | ADR + deferred backlog | T10 |

---

## Task 1: `Woodev_Setting::evaluate_conditions()` — the pure evaluator

**Files:**
- Modify: `woodev/settings-api/class-setting.php` (add a static method near the other static validators, e.g. after `format_number()` ~line 651)
- Test: `tests/unit/SettingsApi/ConditionalFieldsTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/unit/SettingsApi/ConditionalFieldsTest.php`:

```php
<?php
/**
 * Tests for conditional-field visibility: Woodev_Setting::evaluate_conditions(),
 * show_if resolution, and Woodev_Abstract_Settings::filter_visible_values().
 *
 * @package Woodev\Tests\Unit\SettingsApi
 */

namespace Woodev\Tests\Unit\SettingsApi;

use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-helper.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-setting.php';

/**
 * @covers \Woodev_Setting::evaluate_conditions
 */
class ConditionalFieldsTest extends TestCase {

	public function test_empty_conditions_are_visible(): void {
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( [], [ 'mode' => 'test' ] ) );
	}

	public function test_bare_single_condition_equals(): void {
		$c = [ 'setting' => 'mode', 'value' => 'live' ];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'live' ] ) );
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'test' ] ) );
	}

	public function test_default_operator_is_equals(): void {
		$c = [ [ 'setting' => 'mode', 'value' => 'live' ] ];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'live' ] ) );
	}

	public function test_not_equals_matches_empty_controlling(): void {
		$c = [ [ 'setting' => 'mode', 'operator' => '!=', 'value' => 'test' ] ];
		// unset controlling value is the empty string → '' !== 'test' → visible.
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $c, [] ) );
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'test' ] ) );
	}

	public function test_in_and_not_in(): void {
		$in = [ [ 'setting' => 'm', 'operator' => 'in', 'value' => [ 'a', 'b' ] ] ];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $in, [ 'm' => 'b' ] ) );
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $in, [ 'm' => 'c' ] ) );

		$not = [ [ 'setting' => 'm', 'operator' => 'not_in', 'value' => [ 'a', 'b' ] ] ];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $not, [ 'm' => 'c' ] ) );
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $not, [ 'm' => 'a' ] ) );
	}

	public function test_relation_and_requires_all(): void {
		$c = [
			'relation' => 'AND',
			[ 'setting' => 'mode', 'value' => 'live' ],
			[ 'setting' => 'auth', 'value' => 'key' ],
		];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'live', 'auth' => 'key' ] ) );
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'live', 'auth' => 'oauth' ] ) );
	}

	public function test_relation_or_requires_any(): void {
		$c = [
			'relation' => 'OR',
			[ 'setting' => 'mode', 'value' => 'live' ],
			[ 'setting' => 'auth', 'value' => 'key' ],
		];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'test', 'auth' => 'key' ] ) );
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $c, [ 'mode' => 'test', 'auth' => 'oauth' ] ) );
	}

	public function test_string_comparison_of_int_keys(): void {
		// enum option keys can be zero-based ints; comparison is by string.
		$c = [ [ 'setting' => 'n', 'value' => 0 ] ];
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $c, [ 'n' => '0' ] ) );
		$this->assertTrue( \Woodev_Setting::evaluate_conditions( $c, [ 'n' => 0 ] ) );
	}

	public function test_unknown_operator_fails_closed(): void {
		$c = [ [ 'setting' => 'm', 'operator' => 'regex', 'value' => '.*' ] ];
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $c, [ 'm' => 'anything' ] ) );
	}

	public function test_array_controlling_value_coerces_to_empty(): void {
		// controlling fields are scalar in v1; an array value is treated as empty.
		$c = [ [ 'setting' => 'm', 'value' => 'x' ] ];
		$this->assertFalse( \Woodev_Setting::evaluate_conditions( $c, [ 'm' => [ 'x' ] ] ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SettingsApi/ConditionalFieldsTest.php`
Expected: FAIL — `Call to undefined method Woodev_Setting::evaluate_conditions()`.

- [ ] **Step 3: Implement `evaluate_conditions()`**

In `woodev/settings-api/class-setting.php`, add after `format_number()` (before `validate_value()`):

```php
		/**
		 * Evaluates a show_if condition group against a map of current field values.
		 *
		 * Pure + total: an empty group is visible; every value is compared as a string
		 * (so PHP and the JS mirror agree, and enum option keys round-trip). An unset or
		 * non-scalar controlling value is the empty string — no special-casing.
		 *
		 * Mirrored in src/components/validate.js::evaluateConditions — KEEP IN SYNC.
		 * Rule table: conditional-fields design spec §5.
		 *
		 * @since 2.0.2
		 * @param array<string,mixed> $conditions show_if group (relation + members), or one bare condition.
		 * @param array<string,mixed> $values     controlling setting_id => current value.
		 * @return bool true when the field should be visible.
		 */
		public static function evaluate_conditions( array $conditions, array $values ): bool {

			if ( empty( $conditions ) ) {
				return true;
			}

			// Sugar: a single bare condition (has a 'setting' key) is a one-condition group.
			if ( isset( $conditions['setting'] ) ) {
				$conditions = [ $conditions ];
			}

			$relation = isset( $conditions['relation'] ) ? strtoupper( (string) $conditions['relation'] ) : 'AND';
			$members  = array_filter( $conditions, 'is_array' );

			if ( empty( $members ) ) {
				return true;
			}

			foreach ( $members as $condition ) {

				$setting_id = (string) ( $condition['setting'] ?? '' );
				$operator   = (string) ( $condition['operator'] ?? '=' );
				$target     = $condition['value'] ?? '';
				$raw        = $values[ $setting_id ] ?? '';
				$current    = is_scalar( $raw ) ? (string) $raw : '';

				switch ( $operator ) {
					case '=':
						$match = ( $current === (string) $target );
						break;
					case '!=':
						$match = ( $current !== (string) $target );
						break;
					case 'in':
						$match = in_array( $current, array_map( 'strval', (array) $target ), true );
						break;
					case 'not_in':
						$match = ! in_array( $current, array_map( 'strval', (array) $target ), true );
						break;
					default:
						$match = false; // unknown operator → fail-closed.
						break;
				}

				if ( 'OR' === $relation && $match ) {
					return true;
				}

				if ( 'AND' === $relation && ! $match ) {
					return false;
				}
			}

			return 'AND' === $relation;
		}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SettingsApi/ConditionalFieldsTest.php`
Expected: PASS (11 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/settings-api/class-setting.php tests/unit/SettingsApi/ConditionalFieldsTest.php
git commit -m "feat(settings): show_if condition evaluator (conditional fields)"
```

---

## Task 2: `show_if` declaration — property, resolver, `register_setting` wiring

**Files:**
- Modify: `woodev/settings-api/class-setting.php` (add `$show_if` property + 3 methods)
- Modify: `woodev/settings-api/abstract-class-settings.php:83-107` (wire the arg)
- Test: `tests/unit/SettingsApi/ConditionalFieldsTest.php` (append)

- [ ] **Step 1: Write the failing test**

Append to `ConditionalFieldsTest.php`:

```php
	public function test_show_if_accepts_array_directly(): void {
		$setting = new \Woodev_Setting();
		$setting->set_id( 'live_key' );
		$setting->set_show_if( [ 'setting' => 'mode', 'value' => 'live' ] );

		$this->assertSame( [ 'setting' => 'mode', 'value' => 'live' ], $setting->get_show_if_conditions() );
		$this->assertTrue( $setting->is_visible( [ 'mode' => 'live' ] ) );
		$this->assertFalse( $setting->is_visible( [ 'mode' => 'test' ] ) );
	}

	public function test_show_if_accepts_callback_receiving_field_id(): void {
		$setting = new \Woodev_Setting();
		$setting->set_id( 'rate' );
		$setting->set_show_if(
			static function ( string $field_id ): array {
				return 'rate' === $field_id
					? [ 'setting' => 'calc_type', 'value' => 'fixed' ]
					: [];
			}
		);

		$this->assertSame( [ 'setting' => 'calc_type', 'value' => 'fixed' ], $setting->get_show_if_conditions() );
		$this->assertTrue( $setting->is_visible( [ 'calc_type' => 'fixed' ] ) );
	}

	public function test_show_if_defaults_to_empty_visible(): void {
		$setting = new \Woodev_Setting();
		$setting->set_id( 'x' );
		$this->assertSame( [], $setting->get_show_if_conditions() );
		$this->assertTrue( $setting->is_visible( [] ) );
	}

	public function test_show_if_rejects_non_array_non_callable(): void {
		$setting = new \Woodev_Setting();
		$setting->set_id( 'x' );
		$setting->set_show_if( 'nonsense' );
		$this->assertSame( [], $setting->get_show_if_conditions() );
	}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SettingsApi/ConditionalFieldsTest.php`
Expected: FAIL — `Call to undefined method Woodev_Setting::set_show_if()`.

- [ ] **Step 3: Add the property + methods**

In `woodev/settings-api/class-setting.php`, add the property after `$validate_message` (~line 67):

```php
		/** @var array|callable show_if condition group, or a callback returning one. Default [] = always visible. */
		private $show_if = [];
```

Add the methods after `set_validate_message()` (~line 267):

```php
		/**
		 * Sets the show_if visibility conditions: a condition-group array, or a
		 * callback `fn( string $field_id ): array` returning one (resolved once at
		 * schema-build time — it does NOT see live form values). Anything else = [].
		 *
		 * @since 2.0.2
		 * @param array|callable $show_if conditions or a callback returning them.
		 * @return void
		 */
		public function set_show_if( $show_if ): void {
			$this->show_if = ( is_array( $show_if ) || is_callable( $show_if ) ) ? $show_if : [];
		}

		/**
		 * Resolves the show_if conditions to a plain array (calling the callback if set).
		 *
		 * @since 2.0.2
		 * @return array<string,mixed> the condition group ([] = always visible).
		 */
		public function get_show_if_conditions(): array {
			if ( is_callable( $this->show_if ) ) {
				$resolved = call_user_func( $this->show_if, $this->id );
				return is_array( $resolved ) ? $resolved : [];
			}

			return is_array( $this->show_if ) ? $this->show_if : [];
		}

		/**
		 * Whether this field is visible given a map of current controlling values.
		 *
		 * @since 2.0.2
		 * @param array<string,mixed> $values controlling setting_id => current value.
		 * @return bool
		 */
		public function is_visible( array $values ): bool {
			return self::evaluate_conditions( $this->get_show_if_conditions(), $values );
		}
```

- [ ] **Step 4: Wire the `register_setting` arg**

In `woodev/settings-api/abstract-class-settings.php`, add `'show_if' => []` to the `wp_parse_args` defaults (after `'validate_message' => ''`):

```php
						'validate_message' => '',
						'show_if'          => [],
```

And apply it after the `validate` block (after the `if ( is_callable( $args['validate'] ) )` block):

```php
				$setting->set_show_if( $args['show_if'] );
```

- [ ] **Step 5: Run to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SettingsApi/ConditionalFieldsTest.php`
Expected: PASS (15 tests).

- [ ] **Step 6: Commit**

```bash
git add woodev/settings-api/class-setting.php woodev/settings-api/abstract-class-settings.php tests/unit/SettingsApi/ConditionalFieldsTest.php
git commit -m "feat(settings): show_if attribute (array|callback) on Woodev_Setting"
```

---

## Task 3: `filter_visible_values()` — server-side hidden-field strip

**Files:**
- Modify: `woodev/settings-api/abstract-class-settings.php` (add 2 methods after `validate_values()` ~line 368)
- Test: `tests/unit/SettingsApi/ConditionalFieldsTest.php` (append)

- [ ] **Step 1: Write the failing test**

Append to `ConditionalFieldsTest.php`. This exercises a concrete handler subclass so `get_setting()`/`get_value()` work:

```php
	/**
	 * Builds a handler with two settings: a plain `mode` and a `live_key` that is
	 * visible only when mode = live. `get_value()` returns the given stored map.
	 *
	 * @param array $stored setting_id => stored value.
	 * @return \Woodev_Abstract_Settings
	 */
	private function make_handler( array $stored ): \Woodev_Abstract_Settings {
		require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/register-settings/class-register-settings.php';
		require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/abstract-class-settings.php';

		return new class( $stored ) extends \Woodev_Abstract_Settings {
			private array $stored;
			public function __construct( array $stored ) {
				$this->stored = $stored;
				parent::__construct( 'cond_test' );
			}
			protected function register_settings() {
				$this->register_setting( 'mode', \Woodev_Setting::TYPE_STRING, [ 'options' => [ 'test' => 'T', 'live' => 'L' ], 'default' => 'test' ] );
				$this->register_setting( 'live_key', \Woodev_Setting::TYPE_STRING, [ 'required' => true, 'show_if' => [ 'setting' => 'mode', 'value' => 'live' ] ] );
			}
			public function get_value( $id, $default = null ) {
				return $this->stored[ $id ] ?? $default;
			}
		};
	}

	public function test_filter_strips_hidden_field(): void {
		$handler = $this->make_handler( [ 'mode' => 'test' ] );
		// live_key is hidden (mode=test) → stripped even though submitted empty.
		$result = $handler->filter_visible_values( [ 'mode' => 'test', 'live_key' => '' ] );
		$this->assertArrayNotHasKey( 'live_key', $result );
		$this->assertArrayHasKey( 'mode', $result );
	}

	public function test_filter_keeps_visible_field(): void {
		$handler = $this->make_handler( [ 'mode' => 'live' ] );
		$result  = $handler->filter_visible_values( [ 'mode' => 'live', 'live_key' => 'abc' ] );
		$this->assertArrayHasKey( 'live_key', $result );
	}

	public function test_filter_uses_stored_when_controller_not_submitted(): void {
		// mode is NOT in the submitted map → resolve against stored (mode=live) → keep.
		$handler = $this->make_handler( [ 'mode' => 'live' ] );
		$result  = $handler->filter_visible_values( [ 'live_key' => 'abc' ] );
		$this->assertArrayHasKey( 'live_key', $result );
	}

	public function test_filter_passes_through_unconditional_fields(): void {
		$handler = $this->make_handler( [ 'mode' => 'test' ] );
		$result  = $handler->filter_visible_values( [ 'mode' => 'test' ] );
		$this->assertSame( [ 'mode' => 'test' ], $result );
	}
```

> Note: `Woodev_Abstract_Settings::__construct()` calls `register_settings()`. Confirm the parent constructor signature (`__construct( $id )`) while implementing; if it differs, adjust the anonymous class accordingly.

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SettingsApi/ConditionalFieldsTest.php`
Expected: FAIL — `Call to undefined method …::filter_visible_values()`.

- [ ] **Step 3: Implement the methods**

In `woodev/settings-api/abstract-class-settings.php`, add after `validate_values()`:

```php
		/**
		 * Removes fields hidden by their show_if conditions from a submitted values map.
		 *
		 * Called at the top of both REST save paths so a hidden field is neither
		 * validated nor persisted. Visibility resolves against the EFFECTIVE controlling
		 * value (submitted if present, else the stored value) so the server agrees with
		 * the client (which merges edits over stored/default values). Unknown ids and
		 * unconditional fields pass through unchanged.
		 *
		 * @since 2.0.2
		 * @param array<string,mixed> $values submitted setting_id => value.
		 * @return array<string,mixed> the submitted map with hidden fields removed.
		 */
		public function filter_visible_values( array $values ): array {

			foreach ( array_keys( $values ) as $setting_id ) {

				$setting = $this->get_setting( (string) $setting_id );

				if ( ! $setting ) {
					continue;
				}

				$conditions = $setting->get_show_if_conditions();

				if ( empty( $conditions ) ) {
					continue;
				}

				if ( ! Woodev_Setting::evaluate_conditions( $conditions, $this->effective_condition_values( $conditions, $values ) ) ) {
					unset( $values[ $setting_id ] );
				}
			}

			return $values;
		}

		/**
		 * Builds the controlling-value map a condition group needs: for each referenced
		 * controlling setting, the submitted value if present, else the stored value.
		 *
		 * @since 2.0.2
		 * @param array<string,mixed> $conditions the condition group.
		 * @param array<string,mixed> $submitted  the submitted values map.
		 * @return array<string,mixed> controlling setting_id => effective value.
		 */
		private function effective_condition_values( array $conditions, array $submitted ): array {

			$group  = isset( $conditions['setting'] ) ? [ $conditions ] : $conditions;
			$result = [];

			foreach ( $group as $condition ) {

				if ( ! is_array( $condition ) || ! isset( $condition['setting'] ) ) {
					continue;
				}

				$id            = (string) $condition['setting'];
				$result[ $id ] = array_key_exists( $id, $submitted ) ? $submitted[ $id ] : $this->get_value( $id );
			}

			return $result;
		}
```

- [ ] **Step 4: Run to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SettingsApi/ConditionalFieldsTest.php`
Expected: PASS (19 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/settings-api/abstract-class-settings.php tests/unit/SettingsApi/ConditionalFieldsTest.php
git commit -m "feat(settings): filter_visible_values() strips hidden fields server-side"
```

---

## Task 4: `Field_Schema` emits `show_if`

**Files:**
- Modify: `woodev/settings-page/class-field-schema.php` (inside the per-setting loop, after the `server_validated` block)
- Test: `tests/unit/SettingsPageRegistryTest.php` OR a new focused test — reuse the existing `Field_Schema` test if present. Add a test asserting `show_if` is emitted only when non-empty.

- [ ] **Step 1: Write the failing test**

Add to `tests/unit/SettingsApi/ConditionalFieldsTest.php`:

```php
	public function test_field_schema_emits_show_if_only_when_present(): void {
		require_once dirname( __DIR__, 3 ) . '/woodev/settings-page/class-field-schema.php';

		$handler = $this->make_handler( [ 'mode' => 'live' ] );
		$schema  = \Woodev\Framework\Settings\Field_Schema::from_handler( $handler );

		$this->assertArrayNotHasKey( 'show_if', $schema['mode'] );
		$this->assertArrayHasKey( 'show_if', $schema['live_key'] );
		$this->assertSame( [ 'setting' => 'mode', 'value' => 'live' ], $schema['live_key']['show_if'] );
	}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit tests/unit/SettingsApi/ConditionalFieldsTest.php --filter test_field_schema_emits_show_if`
Expected: FAIL — `Failed asserting that an array has the key 'show_if'`.

- [ ] **Step 3: Implement the emission**

In `woodev/settings-page/class-field-schema.php`, after the `if ( null !== $setting->get_validate() ) { $entry['server_validated'] = true; }` block and before `$schema[ $setting->get_id() ] = $entry;`:

```php
			$show_if = $setting->get_show_if_conditions();
			if ( ! empty( $show_if ) ) {
				$entry['show_if'] = $show_if;
			}
```

- [ ] **Step 4: Run to verify it passes**

Run: `./vendor/bin/phpunit tests/unit/SettingsApi/ConditionalFieldsTest.php`
Expected: PASS (20 tests).

- [ ] **Step 5: Commit**

```bash
git add woodev/settings-page/class-field-schema.php tests/unit/SettingsApi/ConditionalFieldsTest.php
git commit -m "feat(settings): Field_Schema emits show_if to both surfaces"
```

---

## Task 5: Wire `filter_visible_values()` into both REST save paths

**Files:**
- Modify: `woodev/rest-api/controllers/class-rest-api-settings-page.php:167` (after the allow-list `array_intersect_key`)
- Modify: `woodev/rest-api/controllers/class-rest-api-setup.php:113` (after reading `$values`)
- Test: `tests/integration/SettingsPageRestTest.php` (add a hidden-required-does-not-block case)

- [ ] **Step 1: Add the settings-page wiring**

In `class-rest-api-settings-page.php`, immediately after line 167 (`$values = array_intersect_key( $values, array_flip( $allowed ) );`):

```php
			// Drop fields hidden by their show_if conditions — never validated, never persisted.
			$values = $handler->filter_visible_values( $values );
```

- [ ] **Step 2: Add the wizard wiring**

In `class-rest-api-setup.php`, immediately after line 113 (`$values  = (array) $request->get_param( 'values' );`):

```php
			if ( $handler ) {
				$values = $handler->filter_visible_values( $values );
			}
```

(Place it before the existing `if ( $handler ) { foreach … }` loop; the loop's own `if ( $handler )` guard stays.)

- [ ] **Step 3: Write an integration test**

Add to `tests/integration/SettingsPageRestTest.php` a test that a hidden required field does not block a save. Mirror the file's existing REST-request pattern (find a passing `save`/`POST` test in that file and copy its request scaffolding — provider id, route `woodev/v1/settings/{provider}`, nonce). The fixture «Карьер» (Task 9) makes `api_key` required + `show_if mode=live`; with `mode=test` submitted, a POST omitting `api_key` must return 200, not a 400 error map:

```php
	public function test_hidden_required_field_does_not_block_save(): void {
		// «Карьер» api_key is required + show_if mode=live. Saving with mode=test must succeed.
		$request = new \WP_REST_Request( 'POST', '/woodev/v1/settings/woodev-test' );
		$request->set_param( 'provider_id', 'woodev-test' );
		$request->set_param( 'values', [ 'mode' => 'test' ] );
		// … set the nonce / current_user exactly as the neighbouring passing test does …

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
	}
```

> The provider id / route / auth scaffolding MUST match an existing green test in the same file — do not invent them. If «Карьер»'s provider id is not `woodev-test`, use the actual id from the fixture's `get_settings_providers()`.

- [ ] **Step 4: Run the integration test**

Run (wp-env must be up — `npx wp-env start`): `composer test:integration -- --filter test_hidden_required_field_does_not_block_save`
Expected: PASS. (If integration env is unavailable, defer to CI — note it in the task handoff.)

- [ ] **Step 5: Commit**

```bash
git add woodev/rest-api/controllers/class-rest-api-settings-page.php woodev/rest-api/controllers/class-rest-api-setup.php tests/integration/SettingsPageRestTest.php
git commit -m "feat(settings): strip hidden fields at both REST save paths"
```

---

## Task 6: JS mirror — `evaluateConditions()` + `isFieldVisible()`

**Files:**
- Modify: `src/components/validate.js` (append two exports)

> No JS unit runner exists (no jest). Parity with the PHP evaluator (Task 1) is enforced by the shared §5 contract, the cross-reference comment, and the browser e2e (Task 10). Match the PHP logic exactly.

- [ ] **Step 1: Append the evaluator to `src/components/validate.js`**

```js
/**
 * Normalizes a show_if group into { relation, members }.
 *
 * PHP serializes a mixed-key array (`['relation'=>…, 0=>{…}]`) to a JSON OBJECT,
 * and a pure list to a JSON ARRAY — so handle both. A bare single condition
 * (has a `setting` key) becomes a one-condition AND group.
 *
 * @param {Object|Array} conditions raw show_if from the schema.
 * @return {{relation: string, members: Array}} normalized group.
 */
function conditionGroup( conditions ) {
	if ( conditions && conditions.setting ) {
		return { relation: 'AND', members: [ conditions ] };
	}
	const relation = String( conditions.relation || 'AND' ).toUpperCase();
	const members = Object.keys( conditions )
		.filter( ( k ) => 'relation' !== k )
		.map( ( k ) => conditions[ k ] )
		.filter( ( c ) => c && 'object' === typeof c );
	return { relation, members };
}

/**
 * Evaluates a show_if condition group against current field values.
 *
 * Faithful mirror of Woodev_Setting::evaluate_conditions() — KEEP IN SYNC with
 * woodev/settings-api/class-setting.php. Rule table: conditional-fields spec §5.
 * Pure + total: empty group → visible; string comparison; an unset/non-scalar
 * controlling value is the empty string; unknown operator → not matching.
 *
 * @param {Object|Array} conditions show_if group.
 * @param {Object}       values     controlling settingId => current value.
 * @return {boolean} true when visible.
 */
export function evaluateConditions( conditions, values ) {
	if ( ! conditions ) {
		return true;
	}

	const { relation, members } = conditionGroup( conditions );

	if ( 0 === members.length ) {
		return true;
	}

	for ( const condition of members ) {
		const settingId = String( condition.setting ?? '' );
		const operator = condition.operator || '=';
		const target = condition.value ?? '';
		const raw = values[ settingId ];
		const current =
			null === raw || undefined === raw || 'object' === typeof raw ? '' : String( raw );

		let match;
		switch ( operator ) {
			case '=':
				match = current === String( target );
				break;
			case '!=':
				match = current !== String( target );
				break;
			case 'in':
				match = ( Array.isArray( target ) ? target : [ target ] ).map( String ).includes( current );
				break;
			case 'not_in':
				match = ! ( Array.isArray( target ) ? target : [ target ] ).map( String ).includes( current );
				break;
			default:
				match = false; // unknown operator → fail-closed
				break;
		}

		if ( 'OR' === relation && match ) {
			return true;
		}
		if ( 'AND' === relation && ! match ) {
			return false;
		}
	}

	return 'AND' === relation;
}

/**
 * Whether a field is visible given the current values (schema.show_if absent → visible).
 *
 * @param {Object} schema field schema slice.
 * @param {Object} values controlling settingId => current value.
 * @return {boolean} true when visible.
 */
export function isFieldVisible( schema, values ) {
	if ( ! schema || ! schema.show_if ) {
		return true;
	}
	return evaluateConditions( schema.show_if, values );
}
```

- [ ] **Step 2: Lint**

Run: `npx wp-scripts lint-js src/components/validate.js`
Expected: no errors (the `no-restricted-syntax` for-of rule is not enabled here; if it flags, convert the loop to `members.every(...)`/`members.some(...)`).

- [ ] **Step 3: Commit**

```bash
git add src/components/validate.js
git commit -m "feat(settings): JS mirror of show_if evaluator"
```

---

## Task 7: Settings page — filter hidden on render + save gate

**Files:**
- Modify: `src/settings-page/section-view.js` (filter fields by visibility)
- Modify: `src/settings-page/app.js` (`renderSection` builds provider-wide condition values; `onSave` excludes hidden)

- [ ] **Step 1: Import + filter in `section-view.js`**

Change the import block to add `isFieldVisible`:

```js
import ControlField from '../components/control-field';
import ConnectionBlock from './connection-block';
import { isFieldVisible } from '../components/validate';
```

Change the signature to accept `conditionValues`, and filter the mapped ids:

```js
export default function SectionView( { providerId, section, values, conditionValues, onFieldChange, showErrors, serverErrors } ) {
```

Replace the `Object.keys( section.fields ).map( … )` with a visibility filter (evaluate against the provider-wide `conditionValues`, falling back to this section's own `values`):

```js
			{ Object.keys( section.fields )
				.filter( ( settingId ) =>
					isFieldVisible( section.fields[ settingId ], conditionValues || values )
				)
				.map( ( settingId ) => (
					<ControlField
						key={ settingId }
						schema={ { ...section.fields[ settingId ], serverError: ( serverErrors || {} )[ settingId ] } }
						value={ values[ settingId ] ?? section.fields[ settingId ].value }
						onChange={ ( next ) => onFieldChange( settingId, next ) }
						showErrors={ showErrors }
					/>
				) ) }
```

- [ ] **Step 2: Build provider-wide condition values in `app.js` `renderSection`**

In `src/settings-page/app.js`, inside `renderSection`, after `const values = edits[ tab.id ] || {};`, add:

```js
		// Provider-wide effective values so a field can react to a controller in
		// any section of this tab (live reactivity still only within the open section).
		const conditionValues = {};
		( tab.sections || [] ).forEach( ( s ) => {
			Object.keys( s.fields || {} ).forEach( ( id ) => {
				conditionValues[ id ] = values[ id ] ?? s.fields[ id ].value;
			} );
		} );
```

Pass it to `SectionView` (add the prop):

```js
					<SectionView
						key={ `${ tab.id }:${ section.id }` }
						providerId={ tab.id }
						section={ section }
						values={ values }
						conditionValues={ conditionValues }
						onFieldChange={ ( settingId, value ) =>
							onFieldChange( tab.id, settingId, value )
						}
						showErrors={ !! showErrors[ tab.id ] }
						serverErrors={ fieldErrors[ tab.id ] || {} }
					/>
```

- [ ] **Step 3: Exclude hidden from the save gate in `onSave`**

Add `isFieldVisible` to the imports in `app.js`:

```js
import { validateFields, isFieldVisible } from '../components/validate';
```

In `onSave`, after `merged` is built and before `validateFields`, filter to visible fields:

```js
		const visibleFields = {};
		Object.keys( allFields ).forEach( ( id ) => {
			if ( isFieldVisible( allFields[ id ], merged ) ) {
				visibleFields[ id ] = allFields[ id ];
			}
		} );

		const clientErrors = validateFields( visibleFields, merged );
```

(Replace the existing `const clientErrors = validateFields( allFields, merged );` line.)

- [ ] **Step 4: Build the settings bundle**

Run: `npm run build:settings`
Expected: builds without error.

- [ ] **Step 5: Commit**

```bash
git add src/settings-page/section-view.js src/settings-page/app.js woodev/assets/build/settings-page
git commit -m "feat(settings): hide conditional fields + skip them in the save gate"
```

---

## Task 8: Setup wizard — filter hidden on render + save gate

**Files:**
- Modify: `src/setup-wizard/step-view.js` (filter fields by visibility)
- Modify: `src/setup-wizard/app.js` (`goNext` excludes hidden)

- [ ] **Step 1: Import + filter in `step-view.js`**

Add the import:

```js
import { createElement, Fragment } from '@wordpress/element';
import ControlField from '../components/control-field';
import { isFieldVisible } from '../components/validate';
```

In `renderFields()`, before `const entries = Object.entries( step.fields || {} );`, build the step's effective values and filter:

```js
	const stepValues = {};
	Object.keys( step.fields || {} ).forEach( ( id ) => {
		stepValues[ id ] = values[ id ] ?? step.fields[ id ].value;
	} );

	const entries = Object.entries( step.fields || {} ).filter( ( [ , schema ] ) =>
		isFieldVisible( schema, stepValues )
	);
```

(Replace the existing `const entries = …` line.)

- [ ] **Step 2: Exclude hidden from the wizard save gate in `app.js`**

Add `isFieldVisible` to the import:

```js
import { validateFields, isFieldVisible } from '../components/validate';
```

In `goNext()`, replace the client-validation block (the `if ( isSettings ) { … validateFields( step.fields, stepValues ) … }`) so it validates only visible fields:

```js
		if ( isSettings ) {
			const stepValues = {};
			Object.keys( step.fields || {} ).forEach( ( id ) => {
				stepValues[ id ] = ( values[ step.id ] || {} )[ id ] ?? step.fields[ id ].value;
			} );

			const visibleFields = {};
			Object.keys( step.fields || {} ).forEach( ( id ) => {
				if ( isFieldVisible( step.fields[ id ], stepValues ) ) {
					visibleFields[ id ] = step.fields[ id ];
				}
			} );

			const clientErrors = validateFields( visibleFields, stepValues );
			if ( Object.keys( clientErrors ).length > 0 ) {
				setShowErrors( true );
				setFieldErrors( {} );
				setError( __( 'Проверьте правильность заполнения полей на этом шаге.', 'woodev-plugin-framework' ) );
				setErrorRevealGen( ( g ) => g + 1 );
				return;
			}
		}
```

- [ ] **Step 3: Build the wizard bundle**

Run: `npm run build:setup`
Expected: builds without error.

- [ ] **Step 4: Commit**

```bash
git add src/setup-wizard/step-view.js src/setup-wizard/app.js woodev/assets/build/setup-wizard
git commit -m "feat(settings): hide conditional fields in the setup wizard + save gate"
```

---

## Task 9: Fixture «Карьер» — demo show_if on both operators + callback

**Files:**
- Modify: `tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php` (`register_settings()` ~line 201-255; the `order`/`general` sections; add a callback method)

- [ ] **Step 1: Make `api_key` conditional (hidden-required demo)**

Change the `api_key` registration (line 203) to add `show_if`:

```php
			$this->register_setting( 'api_key', \Woodev_Setting::TYPE_STRING, [ 'name' => 'API-ключ', 'default' => '', 'required' => true, 'show_if' => [ 'setting' => 'mode', 'value' => 'live' ] ] );
```

- [ ] **Step 2: Add `rate` + `formula` to the order section, driven by a callback**

After the `calc_type` registration (line 211), add:

```php
			$this->register_setting( 'rate', \Woodev_Setting::TYPE_INTEGER, [ 'name' => 'Фикс. ставка, ₽', 'default' => 300, 'show_if' => [ $this, 'order_field_rules' ] ] );
			$this->register_setting( 'formula', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Формула тарифа', 'default' => '', 'show_if' => [ $this, 'order_field_rules' ] ] );
```

After the `calc_type` control (line 219), add their controls:

```php
			$this->register_control( 'rate', \Woodev_Control::TYPE_NUMBER, [ 'min' => 0 ] );
			$this->register_control( 'formula', \Woodev_Control::TYPE_TEXT, [ 'placeholder' => 'weight * 10 + 50' ] );
```

- [ ] **Step 3: Add the callback method**

Add a public method to the fixture class (near `get_settings_providers()`):

```php
		/**
		 * show_if rules for the order-section fields (demo of the callback form + DRY).
		 *
		 * @param string $field_id the setting being resolved.
		 * @return array the condition group, or [] when always visible.
		 */
		public function order_field_rules( string $field_id ): array {
			if ( 'rate' === $field_id ) {
				return [ 'setting' => 'calc_type', 'value' => 'fixed' ];
			}
			if ( 'formula' === $field_id ) {
				return [ 'setting' => 'calc_type', 'operator' => 'not_in', 'value' => [ 'fixed' ] ];
			}
			return [];
		}
```

- [ ] **Step 4: Add `rate`, `formula` to the order section's setting ids**

Change the `order` section (line 384) to include the two new fields:

```php
						\Woodev\Framework\Settings\Settings_Section::create( 'order', 'Форма заказа', [ 'enabled', 'markup', 'calc_type', 'rate', 'formula', 'methods', 'max_weight', 'comment', 'note' ], 'Как тариф и способы доставки отображаются покупателю при оформлении.' ),
```

- [ ] **Step 5: Verify the fixture parses + unit suite stays green**

Run: `php -l tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php` → "No syntax errors".
Run: `composer test:unit`
Expected: all green (existing SettingsPageRegistry/Provider tests still pass with the extra fields).

- [ ] **Step 6: Commit**

```bash
git add tests/_fixtures/woodev-test-plugin/woodev-test-plugin.php
git commit -m "test(fixture): «Карьер» show_if demo (mode→api_key, calc_type→rate/formula callback)"
```

---

## Task 10: ADR, backlog, full build, final checks

**Files:**
- Create: `docs-internal/adr/NNN-conditional-fields-operator-set.md` (NNN = next number)
- Modify: `docs-internal/adr/README.md` (index line)
- Modify: `docs-internal/FUTURE-BACKLOG.md` (deferred entry)

- [ ] **Step 1: Write the ADR**

Read `docs-internal/adr/README.md` to get the next ADR number + the template. Create `docs-internal/adr/NNN-conditional-fields-operator-set.md` covering: the chosen operator set (`=`/`!=`/`in`/`not_in`), the WP_Query-style flat grammar (relation, no nesting), the empty-controlling-value rule (total pure function, string comparison, `!=`/`not_in` match empty), scalar-only controlling fields, and WHY these are deferred — `>`/`<`/`>=`/`<=`, `empty`/`set`, `contains`, nested groups, section/step-level visibility. State that a future operator addition follows this contract (add the operator to `evaluate_conditions()` PHP + the JS mirror + a unit case; keep string comparison + fail-closed default). Add the index line to `adr/README.md`.

- [ ] **Step 2: Add the FUTURE-BACKLOG entry**

Add to `docs-internal/FUTURE-BACKLOG.md` a low-priority entry "conditional-fields v2": comparison operators, `empty`/`set`, `contains` (multiselect controller), nested groups, section/sub-tab/step-level visibility, and an optional `apply_show_if( ids, conditions )` registration helper if per-field/callback proves verbose on the pilot. Cross-reference the ADR and the spec.

- [ ] **Step 3: Full asset build (parity)**

Run: `npm run build`
Then confirm no unintended bundle churn beyond `settings-page` + `setup-wizard`:
Run: `git status --porcelain woodev/assets/build`
Expected: only `settings-page` and `setup-wizard` bundles changed (they share `validate.js`). If `license-page`/`plugins-page`/`ui-kit-gallery` changed, that is expected only if they import `validate.js` — verify; commit whatever the build legitimately produces.

- [ ] **Step 4: Full check**

Run: `composer phpcs`
Expected: no violations (fix any with `composer phpcbf` + manual).
Run: `composer test:unit`
Expected: all green (was 918 unit at s39 + the new ConditionalFieldsTest cases).

> PHPStan: do NOT run locally (Windows segfault, gotcha `phpstan-windows-parallel-worker-segfault`) — Linux CI "Run PHPStan" is authoritative.

- [ ] **Step 5: Commit**

```bash
git add docs-internal/adr docs-internal/FUTURE-BACKLOG.md woodev/assets/build
git commit -m "docs(s40): conditional-fields ADR + backlog; rebuild bundles"
```

---

## Post-plan verification (owner, before merge — NOT a subagent task)

1. **Codex GPT-5.5 critic** on the diff (inline bundle ≤~12KB — spec §5 + the evaluator + filter + one client filter). Re-critic own fixes. (Rule: findings presented, not auto-fixed.)
2. **Browser e2e on `:8888`** (Playwright, admin/password) — MY job, before merge:
   - Settings «Карьер»: `mode=test` → `api_key` hidden, Save succeeds (no required block); switch `mode=live` → `api_key` appears + required star; `calc_type=fixed` → `rate` shows, `formula` hidden; `calc_type=dynamic` → `rate` hidden, `formula` shows.
   - Confirm a hidden required field never blocks Save (the whole point).
   - Screenshot to operator.
3. **CI green** — verify each job PASS + CLEAN (not just grep) before `gh pr merge --squash --delete-branch` (never `--auto`).

## Self-review notes (author)

- Spec §5 refined here: the controlling value is scalar-guarded (`is_scalar ? (string) : ''` in PHP, `typeof 'object' → ''` in JS) — an array controller coerces to empty, not to `"Array"`/`"a,b"`. Both sides agree.
- Spec D6 refined here: hidden fields are skipped **entirely** on save (neither validated NOR persisted) via `filter_visible_values()` at both REST entry points — simpler and consistent across surfaces than "validate-skip but persist".
- Every spec section maps to a task: grammar/operators → T1; declaration array|callback → T2; server skip + posted-else-stored → T3+T5; schema emit → T4; JS mirror → T6; client render+gate both surfaces → T7+T8; fixture → T9; ADR+backlog → T10.
