# §8 Checkout Field Layer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the shipping checkout field layer (core + classic adapter): a generic field registry with cascade + conditional-required + domain field-takeover, a `woodev/v1` REST source seam, and an external-state (store-outside-DOM + delegation) classic client, so a shipping plugin can declare checkout fields (region/city/pickup) that behave correctly through WooCommerce's classic checkout re-renders.

**Architecture:** WC-native fields (rendered via `woocommerce_checkout_fields`) enhanced by a vanilla JS store that holds canonical state outside the DOM and binds via event delegation. The field contract is generic (`depends_on` + `source` + condition-spec `required` + domain `takeover_condition`); domain data lives in the plugin. Server pipeline (`Checkout_Handler`) is HPOS-safe and authoritative; the client mirrors a flat condition grammar for UX gating. Blocks adapter (SP-11), map/modal (SP-5), and DaData suggest source (SP-4) plug into the same core later.

**Tech Stack:** PHP 7.4+ (namespaced `Woodev\Framework\Shipping\Checkout\*`), WooCommerce classic checkout hooks, WP REST (`woodev/v1`), hand-written frontend JS (no webpack; vanilla store + jQuery classic glue), `@wordpress/scripts` jest (`test-unit-js`) for the vanilla store, Brain Monkey + Mockery unit tests, WP integration tests.

**Spec:** `docs-internal/specs/2026-07-06-checkout-field-layer-design.md`. All new code `@since 2.0.2`, VERSION unchanged.

---

## Conventions for every task

- New PHP: namespaced, `Snake_Case` classes, `snake_case` methods, typed params/returns, docblocks with `@since 2.0.2`, short arrays `[]`, Yoda, `??`. Guard each class file with `if ( ! class_exists(...) ) :`.
- Use Serena to read/navigate PHP; use built-in `Edit` for edits (avoid the Serena CRLF-flip gotcha on source).
- After adding/moving any PHP class: regenerate the class-map (`php bin/generate-class-map.php`) — Task 13, but re-run whenever a subagent adds a class.
- Run unit tests with `./vendor/bin/phpunit --testsuite unit` (full suite after wiring a shared path — s40 lesson). PHPStan/phpcs are CI-authoritative (PHPStan segfaults locally on Windows).
- Commit after each task (Conventional Commits). Long messages with backticks/parens → write to a file + `git commit -F`.

## File Structure

**Core (PHP):**
- `woodev/shipping-method/checkout/class-checkout-fields.php` — MODIFY: extend `normalize()` with `section/depends_on/source/source_kind/required(spec)/takeover_condition`.
- `woodev/shipping-method/checkout/class-field.php` — NEW: fluent `Field` builder → array descriptor.
- `woodev/shipping-method/checkout/presets/class-dependent-select.php` — NEW: thin preset.
- `woodev/shipping-method/checkout/presets/class-pickup-field.php` — NEW: thin preset (hidden + gating spec + slot marker).
- `woodev/shipping-method/checkout/class-checkout-condition.php` — NEW: flat condition-spec evaluator (PHP mirror of s40 grammar).
- `woodev/shipping-method/checkout/class-checkout-config.php` — NEW: builds the JS config (fields, cascade edges, required specs, per-country takeover map, root `options`, endpoint URL, nonce) with **no callable/secret leak**.
- `woodev/shipping-method/checkout/class-checkout-handler.php` — MODIFY: enhance-in-place `inject()`; conditional-required in `validate()`; `register()` enqueues assets + localizes config + registers the REST route.
- `woodev/shipping-method/rest-api/class-field-source-controller.php` — NEW: `woodev/v1` generic source controller.

**Frontend JS (hand-written, enqueued directly):**
- `woodev/shipping-method/assets/js/frontend/checkout-field-store.js` — NEW: vanilla store (state, cascade, condition-mirror, restore). **No jQuery / no blocks import.**
- `woodev/shipping-method/assets/js/frontend/checkout-field-classic.js` — NEW: classic adapter (jQuery glue).

**Tests / tooling:**
- `tests/unit/Shipping/Checkout/*Test.php` — NEW unit tests.
- `tests/js/checkout-field-store.test.js` — NEW jest tests for the store.
- `package.json` — MODIFY: add `test:js` → `wp-scripts test-unit-js`.
- `tests/_fixtures/woodev-test-shipping-method/class-woodev-test-shipping-method.php` — MODIFY: override `get_checkout_handler()` with a demo region/city/pickup config.
- `woodev/class-map.php` — regenerated.

---

## Task 1: Extend the field descriptor (`Checkout_Fields::normalize()`)

**Files:**
- Modify: `woodev/shipping-method/checkout/class-checkout-fields.php` (`normalize()`, ~lines 100-125)
- Test: `tests/unit/Shipping/Checkout/CheckoutFieldsTest.php`

- [ ] **Step 1: Write failing tests** for the new normalized keys.

```php
<?php
namespace Woodev\Tests\Unit\Shipping\Checkout;

use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Tests\Unit\TestCase;

class CheckoutFieldsTest extends TestCase {

	public function test_normalize_fills_new_keys_with_defaults(): void {
		$field = Checkout_Fields::normalize( [ 'id' => 'billing_city' ] );

		$this->assertSame( 'order', $field['section'] );
		$this->assertNull( $field['depends_on'] );
		$this->assertNull( $field['source'] );
		$this->assertNull( $field['source_kind'] );
		$this->assertNull( $field['takeover_condition'] );
		$this->assertFalse( $field['required'] ); // bool default unchanged
	}

	public function test_normalize_keeps_condition_spec_required_as_array(): void {
		$spec  = [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'carrier_pickup' ] ];
		$field = Checkout_Fields::normalize( [ 'id' => 'pvz', 'required' => $spec ] );

		$this->assertSame( $spec, $field['required'] );
	}

	public function test_normalize_drops_non_callable_source_and_keeps_callable(): void {
		$noop = static function () { return []; };

		$this->assertNull( Checkout_Fields::normalize( [ 'id' => 'a', 'source' => 'nope' ] )['source'] );
		$this->assertSame( $noop, Checkout_Fields::normalize( [ 'id' => 'b', 'source' => $noop ] )['source'] );
	}

	public function test_normalize_coerces_depends_on_and_source_kind(): void {
		$field = Checkout_Fields::normalize( [ 'id' => 'c', 'depends_on' => 'billing_state', 'source_kind' => 'suggest' ] );

		$this->assertSame( 'billing_state', $field['depends_on'] );
		$this->assertSame( 'suggest', $field['source_kind'] );
	}
}
```

- [ ] **Step 2: Run, expect FAIL.** `./vendor/bin/phpunit --filter CheckoutFieldsTest` → fails (keys missing).

- [ ] **Step 3: Extend `normalize()`.** `required` stays as-is when it is an array (condition-spec) or bool; `source` kept only when callable; `takeover_condition` kept only when callable; `depends_on`/`source_kind`/`section` coerced to string-or-null.

```php
public static function normalize( array $definition ): array {
	$sanitize = $definition['sanitize_callback'] ?? null;
	$validate = $definition['validate_callback'] ?? null;
	$source   = $definition['source'] ?? null;
	$takeover = $definition['takeover_condition'] ?? null;
	$required = $definition['required'] ?? false;

	return [
		'id'                 => (string) ( $definition['id'] ?? '' ),
		'type'               => (string) ( $definition['type'] ?? 'text' ),
		'label'              => (string) ( $definition['label'] ?? '' ),
		'section'            => (string) ( $definition['section'] ?? 'order' ),
		'required'           => is_array( $required ) ? $required : (bool) $required,
		'depends_on'         => isset( $definition['depends_on'] ) && '' !== (string) $definition['depends_on'] ? (string) $definition['depends_on'] : null,
		'source'             => is_callable( $source ) ? $source : null,
		'source_kind'        => isset( $definition['source_kind'] ) && '' !== (string) $definition['source_kind'] ? (string) $definition['source_kind'] : null,
		'takeover_condition' => is_callable( $takeover ) ? $takeover : null,
		'sanitize_callback'  => is_callable( $sanitize ) ? $sanitize : null,
		'validate_callback'  => is_callable( $validate ) ? $validate : null,
	];
}
```

Update the `@return` docblock array shape and the class-level docblock (mention the new generic keys).

- [ ] **Step 4: Run, expect PASS.** `./vendor/bin/phpunit --filter CheckoutFieldsTest`.

- [ ] **Step 5: Commit.** `feat(shipping): extend checkout field descriptor with cascade/source/takeover keys`

---

## Task 2: `Field` fluent builder

**Files:**
- Create: `woodev/shipping-method/checkout/class-field.php`
- Test: `tests/unit/Shipping/Checkout/FieldTest.php`

- [ ] **Step 1: Write failing test.**

```php
<?php
namespace Woodev\Tests\Unit\Shipping\Checkout;

use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Tests\Unit\TestCase;

class FieldTest extends TestCase {

	public function test_builder_produces_normalized_array(): void {
		$src   = static function () { return []; };
		$array = Field::create( 'billing_city' )
			->set_type( 'select' )
			->set_label( 'Город' )
			->set_required( true )
			->depends_on( 'billing_state' )
			->set_source( $src, 'suggest' )
			->to_array();

		$this->assertSame( 'billing_city', $array['id'] );
		$this->assertSame( 'select', $array['type'] );
		$this->assertSame( 'billing_state', $array['depends_on'] );
		$this->assertSame( 'suggest', $array['source_kind'] );
		$this->assertSame( $src, $array['source'] );
	}

	public function test_set_required_accepts_condition_spec(): void {
		$spec  = [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'x' ] ];
		$array = Field::create( 'pvz' )->set_required( $spec )->to_array();
		$this->assertSame( $spec, $array['required'] );
	}
}
```

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Implement `Field`.** A thin mutable builder; `to_array()` returns the raw definition (normalization happens in `Checkout_Fields::add()`).

```php
namespace Woodev\Framework\Shipping\Checkout;
// ... ABSPATH guard + class_exists guard ...
class Field {
	private array $def;
	private function __construct( string $id ) { $this->def = [ 'id' => $id ]; }
	public static function create( string $id ): self { return new self( $id ); }
	public function set_type( string $type ): self { $this->def['type'] = $type; return $this; }
	public function set_label( string $label ): self { $this->def['label'] = $label; return $this; }
	public function set_section( string $section ): self { $this->def['section'] = $section; return $this; }
	/** @param bool|array<string,mixed> $required */
	public function set_required( $required ): self { $this->def['required'] = $required; return $this; }
	public function depends_on( string $parent_id ): self { $this->def['depends_on'] = $parent_id; return $this; }
	public function set_source( callable $source, string $kind = 'options' ): self {
		$this->def['source'] = $source; $this->def['source_kind'] = $kind; return $this;
	}
	public function set_takeover_condition( callable $predicate ): self { $this->def['takeover_condition'] = $predicate; return $this; }
	public function set_sanitize_callback( callable $cb ): self { $this->def['sanitize_callback'] = $cb; return $this; }
	public function set_validate_callback( callable $cb ): self { $this->def['validate_callback'] = $cb; return $this; }
	/** @return array<string,mixed> */
	public function to_array(): array { return $this->def; }
}
```

Also add `Checkout_Fields::add()` acceptance of a `Field` (optional convenience): if `$definition instanceof Field`, call `->to_array()` first. Add a test for that in `CheckoutFieldsTest`.

- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit.** `feat(shipping): add Field fluent builder for checkout fields`

---

## Task 3: Condition-spec evaluator (PHP mirror of s40 grammar)

**Files:**
- Create: `woodev/shipping-method/checkout/class-checkout-condition.php`
- Test: `tests/unit/Shipping/Checkout/CheckoutConditionTest.php`

Grammar (flat, s40-faithful): a spec is either a single `{ state, operator, value }` or `{ relation: 'AND'|'OR', conditions: [ {state,operator,value}, ... ] }`. Operators: `=`, `!=`, `in`, `not_in`. String comparison; bool → `'1'`/`''`; missing/non-scalar state → `''`. **Gate fail-open:** a malformed spec or unknown operator resolves to **`false`** (not required) — never trap a paying customer on a broken spec; the server `validate_callback`/method check remains the hard backstop. Document this explicitly (Codex critic target).

- [ ] **Step 1: Write failing tests.**

```php
<?php
namespace Woodev\Tests\Unit\Shipping\Checkout;

use Woodev\Framework\Shipping\Checkout\Checkout_Condition;
use Woodev\Tests\Unit\TestCase;

class CheckoutConditionTest extends TestCase {

	private array $state = [ 'chosen_shipping_method' => 'carrier_pickup:3' ];

	public function test_bool_required_passthrough(): void {
		$this->assertTrue( Checkout_Condition::is_required( true, $this->state ) );
		$this->assertFalse( Checkout_Condition::is_required( false, $this->state ) );
	}

	public function test_in_operator_matches(): void {
		$spec = [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'carrier_pickup:3', 'x' ] ];
		$this->assertTrue( Checkout_Condition::is_required( $spec, $this->state ) );
	}

	public function test_not_in_operator(): void {
		$spec = [ 'state' => 'chosen_shipping_method', 'operator' => 'not_in', 'value' => [ 'flat_rate' ] ];
		$this->assertTrue( Checkout_Condition::is_required( $spec, $this->state ) );
	}

	public function test_and_or_relations(): void {
		$and = [ 'relation' => 'AND', 'conditions' => [
			[ 'state' => 'chosen_shipping_method', 'operator' => '=', 'value' => 'carrier_pickup:3' ],
			[ 'state' => 'country', 'operator' => '=', 'value' => 'RU' ],
		] ];
		$this->assertFalse( Checkout_Condition::is_required( $and, $this->state ) ); // country missing → '' != 'RU'
	}

	public function test_unknown_operator_fails_open_false(): void {
		$spec = [ 'state' => 'chosen_shipping_method', 'operator' => 'regex', 'value' => '.*' ];
		$this->assertFalse( Checkout_Condition::is_required( $spec, $this->state ) );
	}

	public function test_missing_state_is_empty_string(): void {
		$spec = [ 'state' => 'nope', 'operator' => '=', 'value' => '' ];
		$this->assertTrue( Checkout_Condition::is_required( $spec, $this->state ) ); // '' === ''
	}
}
```

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Implement `Checkout_Condition`.**

```php
class Checkout_Condition {
	/** @param bool|array<string,mixed> $required @param array<string,mixed> $state */
	public static function is_required( $required, array $state ): bool {
		if ( is_bool( $required ) ) { return $required; }
		if ( ! is_array( $required ) || [] === $required ) { return false; }

		if ( isset( $required['conditions'] ) && is_array( $required['conditions'] ) ) {
			$relation = strtoupper( (string) ( $required['relation'] ?? 'AND' ) );
			$results  = array_map( static fn( $c ) => is_array( $c ) && self::evaluate( $c, $state ), $required['conditions'] );
			return 'OR' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true ) && [] !== $results;
		}
		return self::evaluate( $required, $state );
	}

	/** @param array<string,mixed> $c */
	private static function evaluate( array $c, array $state ): bool {
		$actual   = self::scalar( $state[ (string) ( $c['state'] ?? '' ) ] ?? '' );
		$operator = (string) ( $c['operator'] ?? '' );
		$value    = $c['value'] ?? '';

		switch ( $operator ) {
			case '=':      return $actual === self::scalar( $value );
			case '!=':     return $actual !== self::scalar( $value );
			case 'in':     return is_array( $value ) && in_array( $actual, array_map( [ self::class, 'scalar' ], $value ), true );
			case 'not_in': return is_array( $value ) && ! in_array( $actual, array_map( [ self::class, 'scalar' ], $value ), true );
			default:       return false; // fail-open gate
		}
	}

	/** @param mixed $v */
	private static function scalar( $v ): string {
		if ( is_bool( $v ) ) { return $v ? '1' : ''; }
		return is_scalar( $v ) ? (string) $v : '';
	}
}
```

- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit.** `feat(shipping): add checkout condition-spec evaluator (conditional-required)`

---

## Task 4: Presets `Dependent_Select` + `Pickup_Field`

**Files:**
- Create: `woodev/shipping-method/checkout/presets/class-dependent-select.php`
- Create: `woodev/shipping-method/checkout/presets/class-pickup-field.php`
- Test: `tests/unit/Shipping/Checkout/PresetsTest.php`

- [ ] **Step 1: Write failing tests.**

```php
use Woodev\Framework\Shipping\Checkout\Presets\Dependent_Select;
use Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field;

public function test_dependent_select_sets_type_and_parent(): void {
	$a = Dependent_Select::create( 'billing_city', 'billing_state' )->set_label( 'Город' )->to_array();
	$this->assertSame( 'select', $a['type'] );
	$this->assertSame( 'billing_state', $a['depends_on'] );
}

public function test_pickup_field_is_hidden_and_required_when_method_chosen(): void {
	$a = Pickup_Field::create( 'carrier_pickup_point', [ 'carrier_pickup' ] )->to_array();
	$this->assertSame( 'hidden', $a['type'] );
	$this->assertSame( 'in', $a['required']['operator'] );
	$this->assertSame( [ 'carrier_pickup' ], $a['required']['value'] );
	$this->assertSame( 'chosen_shipping_method', $a['required']['state'] );
	$this->assertTrue( $a['is_pickup_slot'] ?? false );
}
```

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Implement presets** (thin `extends Field`).

```php
// Dependent_Select
class Dependent_Select extends Field {
	public static function create( string $id, string $parent_id ): Field {
		return Field::create( $id )->set_type( 'select' )->depends_on( $parent_id );
	}
}
// Pickup_Field — hidden field carrying the chosen point code, required when a pickup method is chosen,
// tagged so the classic adapter mounts the SP-5 slot/anchor next to it.
class Pickup_Field extends Field {
	/** @param string[] $pickup_method_ids */
	public static function create( string $id, array $pickup_method_ids ): Field {
		return Field::create( $id )
			->set_type( 'hidden' )
			->set_required( [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => array_values( $pickup_method_ids ) ] )
			->mark_pickup_slot();
	}
}
```

Add `Field::mark_pickup_slot(): self { $this->def['is_pickup_slot'] = true; return $this; }` and thread `is_pickup_slot` (bool) through `Checkout_Fields::normalize()` (default false) + a test. `Dependent_Select::create` returns a `Field` so `->set_label()` chaining works.

- [ ] **Step 4: Run, expect PASS** (`PresetsTest` + `CheckoutFieldsTest`).
- [ ] **Step 5: Commit.** `feat(shipping): add Dependent_Select + Pickup_Field checkout presets`

---

## Task 5: `Checkout_Config` — JS config emitter

**Files:**
- Create: `woodev/shipping-method/checkout/class-checkout-config.php`
- Test: `tests/unit/Shipping/Checkout/CheckoutConfigTest.php`

Responsibility: from a `Checkout_Fields` + a `plugin_id` + a REST base URL + nonce, build a **JS-safe** array: per-field `{ id, type, section, source_kind, depends_on, required, is_pickup_slot }`, the `endpoint` URL template, `nonce`, and the **per-country takeover map** for fields that declare a `takeover_condition` (evaluated across `WC()->countries->get_countries()` keys). Never include callables/secrets. `required` is emitted as-is when it's a bool or a condition-spec array (the client evaluates the same grammar).

- [ ] **Step 1: Write failing tests** (Brain Monkey: stub `WC()->countries` or inject a country list).

```php
public function test_emit_excludes_callables_and_includes_field_shape(): void {
	$fields = Checkout_Fields::from_array( [
		Field::create( 'billing_state' )->set_type( 'select' )->set_source( fn() => [], 'options' )
			->set_takeover_condition( fn( $c ) => in_array( $c['country'] ?? '', [ 'RU', 'BY' ], true ) )->to_array(),
	] );
	$config = ( new Checkout_Config( 'carrier', 'https://x/wp-json/woodev/v1', 'NONCE', [ 'RU', 'BY', 'FR' ] ) )->build( $fields );

	$field = $config['fields']['billing_state'];
	$this->assertArrayNotHasKey( 'source', $field );
	$this->assertArrayNotHasKey( 'takeover_condition', $field );
	$this->assertSame( 'options', $field['source_kind'] );
	$this->assertSame( [ 'RU' => true, 'BY' => true, 'FR' => false ], $config['takeover']['billing_state'] );
	$this->assertSame( 'NONCE', $config['nonce'] );
}
```

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Implement `Checkout_Config`.** Constructor `( string $plugin_id, string $rest_base, string $nonce, array $countries )` (countries injected for testability; the caller passes `array_keys( WC()->countries->get_countries() )`). `build( Checkout_Fields $fields ): array` iterates `get_fields()`, emits the safe shape, and for any field with a non-null `takeover_condition` evaluates it per country into a `[country => bool]` map. `endpoint` = `"{$rest_base}/shipping/checkout/{$plugin_id}/field-source"`.

- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit.** `feat(shipping): add Checkout_Config JS-config emitter (takeover map, no secret leak)`

---

## Task 6: `Checkout_Handler::inject()` — enhance-in-place

**Files:**
- Modify: `woodev/shipping-method/checkout/class-checkout-handler.php` (`inject()`, ~lines 185-211)
- Test: `tests/unit/Shipping/Checkout/CheckoutHandlerInjectTest.php`

- [ ] **Step 1: Write failing tests.** New id → added; existing id → merged (type/options overridden, other WC args preserved); `section` from the descriptor honored.

```php
public function test_inject_enhances_existing_field_in_place(): void {
	$fields  = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->set_type( 'select' )->set_section( 'billing' )->to_array() ] );
	$handler = new Checkout_Handler( $fields, 'carrier' );

	$wc = [ 'billing' => [ 'billing_city' => [ 'type' => 'text', 'class' => [ 'form-row-wide' ], 'priority' => 70 ] ] ];
	$out = $handler->inject( $wc );

	$this->assertSame( 'select', $out['billing']['billing_city']['type'] );          // enhanced
	$this->assertSame( [ 'form-row-wide' ], $out['billing']['billing_city']['class'] ); // preserved
	$this->assertSame( 70, $out['billing']['billing_city']['priority'] );              // preserved
}

public function test_inject_adds_new_field(): void {
	$fields  = Checkout_Fields::from_array( [ Field::create( 'carrier_pickup_point' )->set_type( 'hidden' )->set_section( 'order' )->to_array() ] );
	$out     = ( new Checkout_Handler( $fields, 'carrier' ) )->inject( [ 'order' => [] ] );
	$this->assertSame( 'hidden', $out['order']['carrier_pickup_point']['type'] );
}
```

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Rewrite `inject()`** to group by each field's own `section`, merge onto an existing entry (`array_merge( $existing, $ours )` where `$ours` = type/label/required/options/custom_attributes) rather than replacing, and inject `options` for `options`-kind roots (call `source` with `{}` context when `source_kind==='options'` and `depends_on===null`). Keep the forward `..._checkout_fields` filter. Preserve the `handle_checkout_fields` entrypoint. Drop the fixed `$section = 'order'` param default in favor of per-field `section` (keep BC: if a field has no section it's `'order'`).

- [ ] **Step 4: Run, expect PASS** (full `--filter CheckoutHandler`).
- [ ] **Step 5: Commit.** `feat(shipping): enhance-in-place checkout field injection by section`

---

## Task 7: `Checkout_Handler::validate()` — conditional-required + chosen method

**Files:**
- Modify: `woodev/shipping-method/checkout/class-checkout-handler.php` (`validate()`, `handle_checkout_process()`)
- Test: `tests/unit/Shipping/Checkout/CheckoutHandlerValidateTest.php`

- [ ] **Step 1: Write failing tests.** A field with a condition-spec `required` is enforced only when the chosen shipping method matches; blank + required → an error notice is added and `validate()` returns false; not required → passes.

```php
public function test_conditional_required_blocks_when_pickup_method_chosen(): void {
	\Brain\Monkey\Functions\expect( 'wc_add_notice' )->once();
	$fields  = Checkout_Fields::from_array( [
		Field::create( 'pvz' )->set_required( [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'carrier_pickup' ] ] )->to_array(),
	] );
	$handler = new Checkout_Handler( $fields, 'carrier' );
	$ok = $handler->validate( [ 'pvz' => '' ], [ 'chosen_shipping_method' => 'carrier_pickup' ] );
	$this->assertFalse( $ok );
}

public function test_conditional_required_passes_when_other_method(): void {
	\Brain\Monkey\Functions\expect( 'wc_add_notice' )->never();
	$fields  = Checkout_Fields::from_array( [
		Field::create( 'pvz' )->set_required( [ 'state' => 'chosen_shipping_method', 'operator' => 'in', 'value' => [ 'carrier_pickup' ] ] )->to_array(),
	] );
	$this->assertTrue( ( new Checkout_Handler( $fields, 'carrier' ) )->validate( [ 'pvz' => '' ], [ 'chosen_shipping_method' => 'flat_rate' ] ) );
}
```

- [ ] **Step 2: Run, expect FAIL** (signature change: `validate` needs the state).

- [ ] **Step 3: Change `validate()` signature** to `validate( array $values, array $state = [] ): bool` and resolve required via `Checkout_Condition::is_required( $field['required'], $state )` before the blank check. Build `$state` in `handle_checkout_process()` from the posted `shipping_method` (WC posts `shipping_method` as an array keyed by package; take the first) + `billing_country`: `$state = [ 'chosen_shipping_method' => $this->chosen_shipping_method(), 'country' => wc_clean( wp_unslash( $_POST['billing_country'] ?? '' ) ) ]`. Add a private `chosen_shipping_method(): string` reading `$_POST['shipping_method'][0] ?? ''`. Thread `$state` through `process()` → `save()` unaffected. Keep the existing `validate_callback` path.

- [ ] **Step 4: Run, expect PASS** + full `--testsuite unit` (shared `validate` path — s40 lesson).
- [ ] **Step 5: Commit.** `feat(shipping): conditional-required checkout validation (A2 gating)`

---

## Task 8: `Field_Source_Controller` REST endpoint

**Files:**
- Create: `woodev/shipping-method/rest-api/class-field-source-controller.php`
- Test: `tests/unit/Shipping/Rest_Api/FieldSourceControllerTest.php` + `tests/integration/Shipping/FieldSourceRouteTest.php`

Route: `GET woodev/v1/shipping/checkout/(?P<plugin_id>[\w-]+)/field-source/(?P<field_id>[\w-]+)`. Query: `country`, `parent` (parent field value), `q` (suggest query). Public read + nonce; the controller is constructed with the owning `Checkout_Fields` + `plugin_id`, resolves the `field_id` against its own registry (404 when absent), builds `$context = [ 'country' => .., 'parent' => .., 'q' => .. ]` (sanitized), invokes the field's `source`, and returns `[ 'options' => [ ['value'=>..,'label'=>..], ... ] ]` (empty array when the field has no source). Per-IP rate-limit (transient counter) + filterable timeout is for the plugin's own outbound call (the source callback owns that); the controller just caps its own work.

- [ ] **Step 1: Write failing unit test** (dispatch the callback directly via a public `get_field_source( string $field_id, array $context ): array`).

```php
public function test_dispatch_returns_source_options(): void {
	$fields = Checkout_Fields::from_array( [
		Field::create( 'billing_city' )->set_source( fn( $ctx ) => [ [ 'value' => 'msk', 'label' => 'Москва' ] ], 'suggest' )->to_array(),
	] );
	$ctrl = new Field_Source_Controller( $fields, 'carrier' );
	$this->assertSame( [ [ 'value' => 'msk', 'label' => 'Москва' ] ], $ctrl->get_field_source( 'billing_city', [ 'q' => 'Мос' ] ) );
}

public function test_dispatch_unknown_field_returns_empty(): void {
	$ctrl = new Field_Source_Controller( Checkout_Fields::from_array( [] ), 'carrier' );
	$this->assertSame( [], $ctrl->get_field_source( 'nope', [] ) );
}
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement the controller** (`register_routes()` on `rest_api_init`, `permission_callback => '__return_true'` + `check_ajax_referer`-style nonce via `X-WP-Nonce` handled by WP; `get_field_source()` pure for unit test).
- [ ] **Step 4: Integration test** — register a fixture handler, hit the route with a nonce as a guest, assert 200 + options; assert 404 for an unknown field. Run `TEST_SUITE=integration` (see wp-env gotcha).
- [ ] **Step 5: Run, expect PASS. Commit.** `feat(shipping): add woodev/v1 checkout field-source REST controller`

---

## Task 9: `Checkout_Handler::register()` — assets + config + REST wiring

**Files:**
- Modify: `woodev/shipping-method/checkout/class-checkout-handler.php` (`register()`, add `enqueue_assets()`, `rest_route()`)
- Test: `tests/unit/Shipping/Checkout/CheckoutHandlerRegisterTest.php`

- [ ] **Step 1: Write failing test** asserting `register()` hooks the four seams: `woocommerce_checkout_fields`, `woocommerce_checkout_process`, `woocommerce_checkout_order_processed`, plus `wp_enqueue_scripts` and `rest_api_init` (Brain Monkey `expect('add_filter'/'add_action')`).

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Extend `register()`** to also `add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] )` and `add_action( 'rest_api_init', [ $this, 'register_rest' ] )`. `enqueue_assets()` (only on `is_checkout()`): enqueue `checkout-field-store.js` then `checkout-field-classic.js` (dependency: store first, `jquery`, `selectWoo`), version by `filemtime` (the s31 CSS lesson generalizes), and `wp_localize_script( 'woodev-checkout-field-classic', 'woodev_checkout_field_config_' . $prefix, ( new Checkout_Config( $this->plugin_id(), rest_url('woodev/v1'), wp_create_nonce('wp_rest'), array_keys( WC()->countries->get_countries() ) ) )->build( $this->fields ) )`. `register_rest()` instantiates `Field_Source_Controller( $this->fields, $this->plugin_id() )->register_routes()`. Add a `plugin_id()` accessor (defaults to `hook_prefix`). Use `Pickup_Checkout_Handler`'s existing `asset_url()`/enqueue helpers as the pattern.

- [ ] **Step 4: Run, expect PASS** + full unit suite.
- [ ] **Step 5: Commit.** `feat(shipping): wire checkout field assets, config localization and REST route`

---

## Task 10: Vanilla JS store (`checkout-field-store.js`) + jest

**Files:**
- Create: `woodev/shipping-method/assets/js/frontend/checkout-field-store.js`
- Create: `tests/js/checkout-field-store.test.js`
- Modify: `package.json` (add `"test:js": "wp-scripts test-unit-js --config tests/js/jest.config.js"` or default config)

The store is a factory exposing a small pure API over a config object — **no jQuery, no DOM**. It holds `state` (field id → value + selected shipping method + country), evaluates the condition grammar (a faithful JS mirror of `Checkout_Condition`), computes cascade actions, and exposes `evaluateRequired(fieldId)`, `setValue(id,val)`, `setChosenMethod(m)`, `setCountry(c)`, `childrenOf(parentId)`, `takeoverFor(fieldId,country)`. It exports for both a browser global (`window.WoodevCheckoutFieldStore`) and CommonJS (`module.exports`) so jest can require it.

- [ ] **Step 1: Write failing jest tests** (parity with `CheckoutConditionTest`).

```js
const { createStore } = require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-store' );

const config = {
  fields: {
    pvz: { id: 'pvz', required: { state: 'chosen_shipping_method', operator: 'in', value: [ 'carrier_pickup' ] } },
    billing_city: { id: 'billing_city', depends_on: 'billing_state', source_kind: 'suggest' },
  },
  takeover: { billing_state: { RU: true, FR: false } },
};

test( 'required mirror matches PHP semantics', () => {
  const s = createStore( config );
  s.setChosenMethod( 'carrier_pickup' );
  expect( s.evaluateRequired( 'pvz' ) ).toBe( true );
  s.setChosenMethod( 'flat_rate' );
  expect( s.evaluateRequired( 'pvz' ) ).toBe( false );
} );

test( 'unknown operator fails open to false', () => {
  const s = createStore( { fields: { x: { id: 'x', required: { state: 'a', operator: 'regex', value: '.*' } } } } );
  expect( s.evaluateRequired( 'x' ) ).toBe( false );
} );

test( 'childrenOf finds dependents', () => {
  expect( createStore( config ).childrenOf( 'billing_state' ) ).toEqual( [ 'billing_city' ] );
} );

test( 'takeoverFor reads the per-country map', () => {
  const s = createStore( config );
  expect( s.takeoverFor( 'billing_state', 'RU' ) ).toBe( true );
  expect( s.takeoverFor( 'billing_state', 'FR' ) ).toBe( false );
} );
```

- [ ] **Step 2: Run, expect FAIL.** `npx wp-scripts test-unit-js tests/js/checkout-field-store.test.js`.

- [ ] **Step 3: Implement `createStore(config)`** with the mirrored `evaluateCondition` (bool passthrough; single vs `conditions`+`relation`; operators `=`/`!=`/`in`/`not_in`; `scalar()` bool→'1'/''; unknown op → false), plus `childrenOf`/`takeoverFor`/setters and a `cascadeChild(parentId)` returning `{ childId, context }` descriptors for the adapter to fetch. UMD-style export.

- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit.** `feat(shipping): add vanilla checkout field store (block-portable core)`

---

## Task 11: Classic adapter JS (`checkout-field-classic.js`)

**Files:**
- Create: `woodev/shipping-method/assets/js/frontend/checkout-field-classic.js`
- Verified via: browser e2e (Task 13) — no jest (jQuery/DOM/select2/`updated_checkout`).

jQuery IIFE (pattern = existing `checkout.js`). Reads `window.woodev_checkout_field_config_{prefix}`, builds the store, then:
- **Delegation** on `document.body`: `change` of any managed field or its `depends_on` parent (incl. native `billing_country`/`billing_state`) → `store.setValue`; on parent change → clear child, fetch `endpoint/{childId}?parent=..&country=..` (+ `q` for suggest via select2 `ajax`), repopulate `<option>`s from data, restore value if still present.
- **`updated_checkout`**: restore every managed field's value from the store into the DOM; re-apply select2 on `suggest`/takeover fields.
- **Takeover**: on `billing_country` change (after WC's `country-select.js` re-render), read `store.takeoverFor(fieldId,country)`; `true` → convert the field to our select/`selectWoo` bound to the source endpoint; `false` → leave WC's native field.
- **`suggest`**: init `selectWoo` with `ajax` pointing at the endpoint + `X-WP-Nonce` header, `minimumInputLength: 2`, debounce.
- **A2 gate**: on `change`/`updated_checkout`, if any managed field `store.evaluateRequired(id)` is true and its value is blank → disable `#place_order` + show an inline error near the pickup slot; re-enable when satisfied. (Server remains authoritative.)
- **Pickup slot**: for a field with `is_pickup_slot`, ensure a stable anchor `<div data-woodev-pickup-slot="{id}">` next to the shipping methods (re-placed on `updated_checkout`, like the existing pickup control) — SP-5 mounts its button/modal here. §8 does not render a map.

- [ ] **Step 1: Implement the adapter** per the above (no unit test; browser-verified in Task 13). Keep all contract strings (ids, endpoint, nonce, method ids) sourced from the localized config — zero hardcoded contract values.
- [ ] **Step 2: Lint sanity** — `npx wp-scripts lint-js woodev/shipping-method/assets/js/frontend/checkout-field-classic.js` (if the repo lints JS; otherwise skip).
- [ ] **Step 3: Commit.** `feat(shipping): add classic checkout adapter (store + delegation + takeover + A2 gate)`

---

## Task 12: Fixture wiring for e2e/integration («Карьер» shipping)

**Files:**
- Modify: `tests/_fixtures/woodev-test-shipping-method/class-woodev-test-shipping-method.php`
- Test: `tests/integration/Shipping/CheckoutFieldsFixtureTest.php`

- [ ] **Step 1:** Override `get_checkout_handler()` to return a `Checkout_Handler` configured with a demo, **domain-in-fixture** field set:
  - `billing_state` — `Dependent_Select`-free root `select`, `source_kind='options'`, `source` = a small static region list per country, `takeover_condition = fn($c)=>in_array($c['country']??'',['RU','BY','KZ','UZ'],true)`.
  - `billing_city` — `Dependent_Select::create('billing_city','billing_state')`, `source_kind='suggest'`, `source` = a static filtered-by-`q`-and-parent list.
  - `carrier_pickup_point` — `Pickup_Field::create('carrier_pickup_point',[ <the fixture method id> ])`.
- [ ] **Step 2:** Integration test — the handler registers without error; `inject()` enhances `billing_state`/`billing_city` in the WC checkout fields; the field-source route returns the fixture data. Run integration suite.
- [ ] **Step 3: Commit.** `test(shipping): wire demo checkout fields into the shipping fixture`

---

## Task 13: Class-map regen, full suite, browser e2e checklist

**Files:**
- Modify: `woodev/class-map.php` (regenerated)

- [ ] **Step 1:** `php bin/generate-class-map.php` → verify the new `Checkout\Field`, `Checkout\Checkout_Condition`, `Checkout\Checkout_Config`, `Checkout\Presets\Dependent_Select`, `Checkout\Presets\Pickup_Field`, `Rest_Api\Field_Source_Controller` are present.
- [ ] **Step 2:** `composer test:unit` (full) → green; `npx wp-scripts test-unit-js` → green; `composer test:integration` (wp-env) → green.
- [ ] **Step 3:** phpcs (`composer phpcs`) → clean; commit assets with LF (`.gitattributes` already pins build; hand-written frontend JS lives outside `build/` — ensure LF).
- [ ] **Step 4:** Commit. `chore(shipping): regenerate class-map for checkout field layer`
- [ ] **Step 5 — Browser e2e on `:8888` (operator/self, classic), the merge gate:**
  1. Activate the shipping fixture; add a product; go to classic `[woocommerce_checkout]`.
  2. Country RU → `billing_state` is our region select (takeover); FR → WC's native states (no takeover).
  3. Pick a region → `billing_city` select2 loads suggestions filtered by that region as you type `Мос`.
  4. Choose the pickup shipping method, leave the pickup point empty → «Оформить заказ» blocked with a clear error; server also blocks on a forced POST.
  5. Fill everything → order places; `billing_state`/`billing_city`/`carrier_pickup_point` saved on the order (HPOS-safe). Screenshot each to the operator.

---

## Self-Review (author)

**Spec coverage:** decisions 1-8 → Tasks: (1) descriptor keys, (2) builder, (3) condition eval + (7)+(10) mirror, (4) presets, (5) config/takeover map, (6) enhance-in-place, (7) A2 server, (8) REST transport, (9) assets/config wiring, (10) store, (11) classic adapter incl. takeover/suggest/gate/slot, (12) fixture, (13) e2e. Pickup slot (spec §9 boundary) = Task 11 slot + Task 4 `Pickup_Field`; map explicitly NOT built. Cross-cutting (§7): HPOS via existing `save()`; no `_n()` (use count-neutral messages already in `Checkout_Handler`); class-map Task 13; early registration — the handler registers on the shipping plugin's normal `register()` (fires for REST too since it's not `is_checkout()`-gated) — **verify** the shipping plugin's `register()` runs on REST requests; if it is admin/checkout-gated, move field+source registration to `init` (note for the implementer).

**Placeholder scan:** none — every step has real code or an exact command.

**Type consistency:** `Field::create()->to_array()` (raw def) vs `Checkout_Fields::normalize()` (normalized) — Task 6/7/8 consume normalized fields via `Checkout_Fields::get_fields()`, builders feed raw defs into `add()`; consistent. `validate( $values, $state )` new arg threaded in Tasks 7/9. `get_field_source()` name stable across Tasks 8/11. `evaluateRequired`/`childrenOf`/`takeoverFor` stable across Tasks 10/11.

**Open flag for the implementer / Codex critic:** the fail-open-on-malformed-spec gate (Task 3) — confirm this is the desired customer-safe default vs. fail-closed (block). Server `validate_callback` + the carrier's own method-requires-pickup check remain the hard backstop either way.
