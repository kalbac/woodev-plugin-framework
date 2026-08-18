# Shipping settings V2 («Доставка» tab) — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the store-level «Доставка» tab (sections «Локация» / «Поля» / «Карта») designed in
`docs-internal/specs/2026-08-18-shipping-settings-v2-design.md`, with every option actually wired
into checkout behaviour, on the classic checkout in full and on the block checkout wherever the
country-locale instrument reaches.

**Architecture:** One new store-level registrar (`Shipping_Settings_Tab`) builds the tab from three
settings handlers behind a `Composite_Settings_Handler` (so each section keeps its own option
namespace and one file); a new `Checkout_Field_Policy` applies the «Поля» settings through exactly
two WooCommerce seams (`woocommerce_get_country_locale` for both checkouts, a late
`woocommerce_checkout_fields` for classic-only removal) and restores the settlement invariants a
third-party field manager may break; the «Карта» settings replace two `Pickup_Handler` constructor
flags and slot into the placement resolver's reserved precedence step; the settings React surface
gains a `disabled` + reason state (D11).

**Tech Stack:** PHP 7.4+ (WordPress/WooCommerce 11.x), Brain Monkey + Mockery unit tests, wp-env
integration tests, React (`@wordpress/element`) + jest for the settings page and checkout JS.

---

## Rules that travel with every task

- **Serena is mandatory for PHP** — `activate_project` by PATH `D:/Projects/woodev_framework`;
  never `Read` a `.php` file; put this rule in every subagent brief.
- **The plan is a plan, the code is the authority.** Every signature and line number below was
  checked on `main` at `ed7f9f8` (18.08.2026), but verify with `find_symbol` before editing. If the
  code disagrees with the plan, follow the code and say so in the task report — do not force the
  plan through.
- Conventions: tabs, `snake_case`, PSR-4 namespaces `Woodev\Framework\Shipping\…` for new code,
  short arrays only, docblocks with `@since 2.0.2` on every public/protected method, type
  declarations everywhere. Russian only in user-facing strings, text domain
  `woodev-plugin-framework`.
- **New PHP files:** add the `require_once` to `Shipping_Plugin::load_*` (see
  `woodev/shipping-method/class-shipping-plugin.php:150–200`) AND regenerate the classmap
  (`php bin/generate-class-map.php`) — `ClassMapCompletenessTest` fails otherwise.
- Test commands (from **bash**, never PowerShell for jest):
  - `composer test:unit` (2316 today) · a single file: `./vendor/bin/phpunit tests/unit/…/XTest.php`
  - `npm run test:js -- --roots "<rootDir>/tests/js"` (1177 today)
  - `composer phpcs && composer phpstan`
  - integration (110): see `docs-internal/CURRENT-STATE.md` → the `docker exec … --testsuite=Integration` line
- Commit after every green task; Conventional Commits; PR when a coherent block is done
  (tasks 1–4 = surface, 5–7 = fields, 8–9 = map, 10–12 = wiring/docs), each PR reviewed by the Codex
  critic (inline bundle, canary line, stdin transport — gotcha `codex-shell-sandbox-broken-windows`).
- Data contracts (ADR-005): existing option names `woodev_location_*` unchanged; new options are new
  keys; `field_mode` values unchanged; the `woodev_pickup_slot_placements` filter signature unchanged.

## File structure

| File | Responsibility |
|---|---|
| `woodev/settings-page/class-composite-settings-handler.php` (NEW) | One duck-typed handler over N child `Woodev_Abstract_Settings`, routing by setting id — what `Settings_Provider` needs to show sections from different handlers on one tab |
| `woodev/settings-api/class-control.php` (MODIFY) | `disabled` + `disabled_reason` on `Woodev_Control` |
| `woodev/settings-page/class-field-schema.php` (MODIFY) | emit `disabled` / `disabled_reason` |
| `src/components/control-field.js`, `src/settings-page/app.js` (MODIFY) | render a disabled field; exclude disabled fields from the save payload |
| `woodev/shipping-method/settings/class-shipping-settings-tab.php` (NEW) | Singleton registrar of the «Доставка» tab: collects declarations (shipping plugin present / location needed / map needed), builds the composite handler + sections, registers with `Settings_Page_Registry` |
| `woodev/shipping-method/checkout/class-checkout-field-settings.php` (NEW) | The «Поля» handler: `field_order_preset`, `country_field`, `region_field`, `address_field`, `postcode_field` (+ availability rules) |
| `woodev/shipping-method/checkout/class-checkout-field-policy.php` (NEW) | Applies «Поля» through the two WC seams; restores settlement invariants; records overrides |
| `woodev/shipping-method/pickup/class-pickup-map-settings.php` (NEW) | The «Карта» handler: `pickup_button_placement`, `pickup_replace_address`, `pickup_close_on_select` |
| `woodev/shipping-method/location/class-location-settings.php` (MODIFY) | add `address_suggestions`; relabel `field_mode` |
| `woodev/shipping-method/location/class-location-provider-registry.php` (MODIFY) | stop registering the tab itself; hand the handler + section to the tab; `is_address_suggestions_enabled()` |
| `woodev/shipping-method/location/class-location-service.php` (MODIFY) | `provider_for_level('address', …)` → `null` while address suggestions are off |
| `woodev/shipping-method/checkout/class-checkout-config.php` (MODIFY) | placement: default → store setting → filter; publish `pickup_method_ids` + field policy flags to the browser |
| `woodev/shipping-method/pickup/class-pickup-handler.php` (MODIFY) | drop `$replace_address` / `$close_on_select` ctor args; read the store settings |
| `woodev/shipping-method/class-shipping-plugin.php` (MODIFY) | declare to `Shipping_Settings_Tab` from `add_hooks()`; `require_once` the new files |
| `woodev/shipping-method/assets/js/frontend/location-cascade.js`, `checkout-field-classic.js` (MODIFY) | hide-for-pickup / country-hide behaviour; DOM-presence checks; #337 comment fix |
| `tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php` (MODIFY) | new `Pickup_Handler` ctor call |
| tests | listed per task |

---

### Task 1: `Woodev_Control` learns `disabled` + `disabled_reason`; `Field_Schema` emits them

**Files:**
- Modify: `woodev/settings-api/class-control.php` (properties block `:63–91`, getters/setters after `set_placeholder()` `:341`)
- Modify: `woodev/settings-api/abstract-class-settings.php:144–215` (`register_control()` args)
- Modify: `woodev/settings-page/class-field-schema.php:50–95`
- Test: `tests/unit/FieldSchemaTest.php`

- [ ] **Step 1: Write the failing test** — append to `FieldSchemaTest`:

```php
public function test_disabled_control_emits_disabled_and_reason(): void {
	$control = Mockery::mock( \Woodev_Control::class );
	$control->shouldReceive( 'get_type' )->andReturn( 'checkbox' );
	$control->shouldReceive( 'get_description' )->andReturn( '' );
	$control->shouldReceive( 'get_tooltip' )->andReturn( '' );
	$control->shouldReceive( 'get_placeholder' )->andReturn( '' );
	$control->shouldReceive( 'get_min' )->andReturn( null );
	$control->shouldReceive( 'get_max' )->andReturn( null );
	$control->shouldReceive( 'get_step' )->andReturn( null );
	$control->shouldReceive( 'is_disabled' )->andReturn( true );
	$control->shouldReceive( 'get_disabled_reason' )->andReturn( 'Недоступно на блочном чекауте' );

	$setting = $this->make_setting( 'x', 'boolean', $control );
	$handler = Mockery::mock();
	$handler->shouldReceive( 'get_settings' )->with( [ 'x' ] )->andReturn( [ $setting ] );
	$handler->shouldReceive( 'get_value' )->with( 'x' )->andReturn( true );

	$schema = Field_Schema::from_handler( $handler, [ 'x' ] );

	$this->assertTrue( $schema['x']['disabled'] );
	$this->assertSame( 'Недоступно на блочном чекауте', $schema['x']['disabled_reason'] );
	// The reason doubles as the description so a client that ignores `disabled` still shows it.
	$this->assertSame( 'Недоступно на блочном чекауте', $schema['x']['description'] );
}

public function test_enabled_control_emits_no_disabled_key(): void {
	$control = Mockery::mock( \Woodev_Control::class );
	foreach ( [ 'get_type' => 'text', 'get_description' => 'd', 'get_tooltip' => '', 'get_placeholder' => '', 'get_min' => null, 'get_max' => null, 'get_step' => null, 'is_disabled' => false, 'get_disabled_reason' => '' ] as $m => $r ) {
		$control->shouldReceive( $m )->andReturn( $r );
	}
	$setting = $this->make_setting( 'y', 'string', $control );
	$handler = Mockery::mock();
	$handler->shouldReceive( 'get_settings' )->andReturn( [ $setting ] );
	$handler->shouldReceive( 'get_value' )->andReturn( '' );

	$schema = Field_Schema::from_handler( $handler, [ 'y' ] );

	$this->assertArrayNotHasKey( 'disabled', $schema['y'] );
	$this->assertSame( 'd', $schema['y']['description'] );
}
```

Existing tests in this file build controls with real `Woodev_Control` objects or mocks — if a
shared mock helper exists, extend it with `is_disabled → false` / `get_disabled_reason → ''`
defaults instead of repeating the list.

- [ ] **Step 2: Run** `./vendor/bin/phpunit tests/unit/FieldSchemaTest.php` — expected: FAIL (`is_disabled` not mocked / key missing).

- [ ] **Step 3: Implement** — `class-control.php`: add after `$placeholder`:

```php
/** @var bool whether the control is rendered disabled (D11: blocked controls are explained). */
protected $disabled = false;

/** @var string the human-readable reason a disabled control cannot be used right now. */
protected $disabled_reason = '';
```

and after `set_placeholder()`:

```php
/**
 * Whether the control is disabled.
 *
 * @since 2.0.2
 * @return bool
 */
public function is_disabled(): bool {
	return $this->disabled;
}

/**
 * Disables (or re-enables) the control, with the reason shown to the merchant.
 *
 * A disabled control is rendered read-only and its stored value is left untouched on
 * save; the reason travels to the browser as the field description (design S3/D11).
 *
 * @since 2.0.2
 * @param bool   $disabled whether the control is disabled.
 * @param string $reason   why — required when disabling, ignored otherwise.
 * @return void
 */
public function set_disabled( bool $disabled, string $reason = '' ): void {
	$this->disabled        = $disabled;
	$this->disabled_reason = $disabled ? $reason : '';
}

/**
 * The reason a disabled control cannot be used; `''` when enabled.
 *
 * @since 2.0.2
 * @return string
 */
public function get_disabled_reason(): string {
	return $this->disabled_reason;
}
```

`abstract-class-settings.php` `register_control()`: after the `placeholder` block add

```php
if ( ! empty( $args['disabled'] ) ) {
	$control->set_disabled( true, (string) ( $args['disabled_reason'] ?? '' ) );
}
```

`class-field-schema.php`: after the `placeholder`/`required` entry block:

```php
if ( $control && $control->is_disabled() ) {
	$entry['disabled']        = true;
	$entry['disabled_reason'] = $control->get_disabled_reason();
	if ( '' !== $entry['disabled_reason'] ) {
		$entry['description'] = $entry['disabled_reason'];
	}
}
```

- [ ] **Step 4: Run** `./vendor/bin/phpunit tests/unit/FieldSchemaTest.php` — PASS. Then `composer test:unit` — all green (other tests mock `Woodev_Control` without `is_disabled`: fix them by adding `->byDefault()` stubs where they break; expect a handful).
- [ ] **Step 5: Commit** — `feat(settings-api): controls can be disabled with a reason (D11)`.

---

### Task 2: React — render a disabled field, never send it on save

**Files:**
- Modify: `src/components/control-field.js` (after the `constant_managed` branch, `:247`)
- Modify: `src/settings-page/app.js` (the save payload builder — find where `hasChanges` / the values map is turned into the REST body)
- Test: `tests/js/settings-page/control-field.test.js` (create if the folder has no test for `ControlField`; check `tests/js` for the existing settings-page test location first and follow it)

- [ ] **Step 1: Failing tests**

```js
import { render, screen } from '@testing-library/react';
import ControlField from '../../src/components/control-field';

test( 'a disabled checkbox renders disabled with the reason as description', () => {
	render( <ControlField
		schema={ { type: 'boolean', controlType: 'checkbox', name: 'Скрывать адрес', disabled: true, disabled_reason: 'Недоступно на блочном чекауте', description: 'Недоступно на блочном чекауте' } }
		value={ true } onChange={ () => {} } showErrors={ false } /> );
	expect( screen.getByRole( 'checkbox' ) ).toBeDisabled();
	expect( screen.getByText( 'Недоступно на блочном чекауте' ) ).toBeInTheDocument();
} );
```

and for `app.js` (in the existing settings-page app test, if one exists; otherwise a focused test
of the extracted helper): a helper `buildSavePayload( schema, values, dirtyIds )` returns no key for
a field whose schema is `disabled: true` even if it is in `dirtyIds`.

- [ ] **Step 2: Run** `npm run test:js -- --roots "<rootDir>/tests/js"` — FAIL.
- [ ] **Step 3: Implement** — `control-field.js`: pass `disabled: !! schema.disabled` into every
  control element created below the `sensitive` branch (checkbox/toggle/select/text/number…);
  the description already renders `schema.description`, so nothing else changes visually. In
  `app.js` extract the payload building into `export function buildSavePayload( schema, values, dirtyIds )`
  (or wherever the values are gathered) and `filter( id => ! schema[ id ]?.disabled )`.
- [ ] **Step 4: Run** jest — PASS. `npm run build` — the assets-parity CI job compares built bundles; commit the rebuilt bundles with the source (check `git status` for `woodev/assets/js/admin/*` changes).
- [ ] **Step 5: Commit** — `feat(settings-page): render disabled fields read-only and keep them out of the save payload`.

---

### Task 3: `Composite_Settings_Handler`

**Files:**
- Create: `woodev/settings-page/class-composite-settings-handler.php`
- Test: `tests/unit/CompositeSettingsHandlerTest.php`

- [ ] **Step 1: Failing test** (build two real anonymous `Woodev_Abstract_Settings` children exactly the way `FieldSchemaTest::make_handler()` does — copy that helper):

```php
public function test_routes_get_set_and_validation_to_the_owning_child(): void {
	$a = $this->make_handler( 'alpha', function ( $h ) {
		$h->register_setting( 'one', \Woodev_Setting::TYPE_BOOLEAN, [ 'name' => 'One', 'default' => false ] );
	} );
	$b = $this->make_handler( 'beta', function ( $h ) {
		$h->register_setting( 'two', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Two', 'default' => 'x', 'options' => [ 'x' => 'X', 'y' => 'Y' ] ] );
	} );

	$composite = new Composite_Settings_Handler( 'shipping', [ $a, $b ] );

	$this->assertSame( 'shipping', $composite->get_id() );
	$this->assertSame( [ 'one', 'two' ], array_keys( $composite->get_settings() ) );
	$this->assertSame( [ 'two' ], array_keys( $composite->get_settings( [ 'two' ] ) ) );
	$this->assertSame( 'x', $composite->get_value( 'two' ) );
	$this->assertNull( $composite->get_setting( 'nope' ) );

	Functions\expect( 'update_option' )->once()->with( 'woodev_beta_two', 'y' )->andReturn( true );
	$composite->update_value( 'two', 'y' );

	$errors = $composite->validate_values( [ 'two' => 'zzz', 'one' => true ] );
	$this->assertArrayHasKey( 'two', $errors );

	$this->assertSame( [ 'one' => true ], $composite->filter_visible_values( [ 'one' => true, 'ghost' => 1 ] ) );
}

public function test_duplicate_setting_ids_across_children_are_a_programming_error(): void {
	$a = $this->make_handler( 'alpha', function ( $h ) { $h->register_setting( 'dup', \Woodev_Setting::TYPE_STRING ); } );
	$b = $this->make_handler( 'beta', function ( $h ) { $h->register_setting( 'dup', \Woodev_Setting::TYPE_STRING ); } );
	$this->expectException( \InvalidArgumentException::class );
	new Composite_Settings_Handler( 'shipping', [ $a, $b ] );
}
```

(Verify how `Woodev_Abstract_Settings::update_value()` persists — the option name shape
`woodev_{id}_{setting}` is asserted at `class-location-provider-registry.php:1543`; adjust the
`update_option` expectation to whatever the base class really calls.)

- [ ] **Step 2: Run** — FAIL (class missing).
- [ ] **Step 3: Implement**

```php
<?php
/**
 * Composite settings handler.
 *
 * @package Woodev\Framework\Settings
 */

namespace Woodev\Framework\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One settings handler over several `Woodev_Abstract_Settings` children.
 *
 * `Settings_Provider` binds ONE handler per tab; the «Доставка» tab shows sections owned by
 * three handlers (location / checkout fields / pickup map), each keeping its own option
 * namespace. This class routes every call `Field_Schema` and the settings REST controller make
 * to the child that registered the setting id. It deliberately implements neither
 * `Woodev_Settings_Connection_Test` nor `Woodev_Settings_Connection_Status` — none of the
 * children needs them today; add delegation when one does.
 *
 * @since 2.0.2
 */
final class Composite_Settings_Handler {

	/** @var string */
	private string $id;

	/** @var \Woodev_Abstract_Settings[] setting id => owning child. */
	private array $owner_by_id = [];

	/** @var \Woodev_Abstract_Settings[] */
	private array $children;

	/**
	 * @since 2.0.2
	 * @param string                      $id       tab-level id (NOT an option namespace — children own those).
	 * @param \Woodev_Abstract_Settings[] $children handlers, in section order.
	 * @throws \InvalidArgumentException when two children register the same setting id.
	 */
	public function __construct( string $id, array $children ) {
		$this->id       = $id;
		$this->children = array_values( $children );

		foreach ( $this->children as $child ) {
			foreach ( $child->get_settings() as $setting ) {
				$sid = $setting->get_id();
				if ( isset( $this->owner_by_id[ $sid ] ) ) {
					throw new \InvalidArgumentException( sprintf( 'Setting id "%s" is registered by two handlers.', $sid ) );
				}
				$this->owner_by_id[ $sid ] = $child;
			}
		}
	}

	/** @since 2.0.2 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * @since 2.0.2
	 * @param string[] $ids optional filter.
	 * @return \Woodev_Setting[] keyed by id, children in order.
	 */
	public function get_settings( array $ids = [] ): array {
		$out = [];
		foreach ( $this->children as $child ) {
			foreach ( $child->get_settings( $ids ) as $sid => $setting ) {
				$out[ $sid ] = $setting;
			}
		}
		return $out;
	}

	/** @since 2.0.2 */
	public function get_setting( string $id ) {
		return isset( $this->owner_by_id[ $id ] ) ? $this->owner_by_id[ $id ]->get_setting( $id ) : null;
	}

	/** @since 2.0.2 */
	public function get_value( string $id, bool $with_default = true ) {
		return isset( $this->owner_by_id[ $id ] ) ? $this->owner_by_id[ $id ]->get_value( $id, $with_default ) : null;
	}

	/** @since 2.0.2 */
	public function update_value( string $id, $value ) {
		return isset( $this->owner_by_id[ $id ] ) ? $this->owner_by_id[ $id ]->update_value( $id, $value ) : false;
	}

	/**
	 * @since 2.0.2
	 * @param array<string,mixed> $values
	 * @return array<string,string> setting id => error message.
	 */
	public function validate_values( array $values ): array {
		$errors = [];
		foreach ( $this->split_by_owner( $values ) as $i => $chunk ) {
			$errors += $this->children[ $i ]->validate_values( $chunk );
		}
		return $errors;
	}

	/** @since 2.0.2 */
	public function filter_visible_values( array $values ): array {
		$out = [];
		foreach ( $this->split_by_owner( $values ) as $i => $chunk ) {
			$out += $this->children[ $i ]->filter_visible_values( $chunk );
		}
		return $out;
	}

	/**
	 * Splits a submitted map into per-child chunks (unknown ids are dropped).
	 *
	 * @param array<string,mixed> $values
	 * @return array<int,array<string,mixed>> child index => values.
	 */
	private function split_by_owner( array $values ): array {
		$chunks = [];
		foreach ( $values as $sid => $value ) {
			if ( ! isset( $this->owner_by_id[ $sid ] ) ) {
				continue;
			}
			$i = array_search( $this->owner_by_id[ $sid ], $this->children, true );
			$chunks[ $i ][ $sid ] = $value;
		}
		return $chunks;
	}
}
```

Check `Woodev_Abstract_Settings::get_settings()` really keys by id (`:233`); if it returns a list,
key the composite's map by `$setting->get_id()` yourself. `Field_Schema` and the REST controller
are duck-typed (`$handler` untyped, `class-settings-provider.php:76`), so no interface is needed —
but grep `instanceof \Woodev_Abstract_Settings` once more before relying on that.

- [ ] **Step 4:** run the test — PASS; `php bin/generate-class-map.php`; `composer test:unit`.
- [ ] **Step 5: Commit** — `feat(settings-page): composite handler for a tab whose sections span several handlers`.

---

### Task 4: `Shipping_Settings_Tab` — the registrar; «Локация» moves under it

**Files:**
- Create: `woodev/shipping-method/settings/class-shipping-settings-tab.php`
- Modify: `woodev/shipping-method/location/class-location-provider-registry.php:1420–1458` (`register_settings()`)
- Modify: `woodev/shipping-method/class-shipping-plugin.php:208–235` (`add_hooks()`), `:150–200` (`require_once`)
- Test: `tests/unit/Shipping/Settings/ShippingSettingsTabTest.php` (new dir), adjust `tests/unit/Shipping/Location/LocationProviderRegistryTest.php` where it asserts `register_service()` was called

- [ ] **Step 1: Failing tests**

```php
public function test_no_tab_without_a_shipping_plugin(): void {
	$tab = Shipping_Settings_Tab::instance();
	$this->assertFalse( $tab->is_needed() );
	$this->assertSame( [], $tab->build_sections() ); // pure builder, no WP
}

public function test_sections_follow_declarations(): void {
	$tab = Shipping_Settings_Tab::instance();
	$tab->declare_shipping_plugin();                       // any Shipping_Plugin → tab + «Поля»
	$this->assertSame( [ 'fields' ], array_map( fn( $s ) => $s->get_id(), $tab->build_sections() ) );

	$tab->set_location_section( $this->location_handler_stub(), [ 'active_provider', 'field_mode' ] );
	$tab->declare_map_needed();
	$this->assertSame( [ 'location', 'fields', 'map' ], array_map( fn( $s ) => $s->get_id(), $tab->build_sections() ) );
}

public function test_register_builds_one_service_with_a_composite_handler(): void {
	// Settings_Page_Registry is a singleton with register_service(); spy on it via reflection
	// or the existing reset seam (see LocationProviderRegistryTest for how the s5x tests spy).
	…
	$this->assertSame( 'shipping', $provider->get_id() );
	$this->assertSame( 'Доставка', $provider->get_label() );
	$this->assertInstanceOf( Composite_Settings_Handler::class, $provider->get_handler() );
}
```

Add `reset_for_tests()` mirroring `Location_Provider_Registry::reset_for_tests()` (`:380–410`) and
call it in `setUp()`.

- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement**

```php
namespace Woodev\Framework\Shipping\Settings;

use Woodev\Framework\Settings\Composite_Settings_Handler;
use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Settings;
use Woodev\Framework\Shipping\Pickup\Pickup_Map_Settings;

/**
 * Registrar of the store-level «Доставка» tab (design S1/S9).
 *
 * Section visibility is DERIVED from declarations, never configured: any Shipping_Plugin →
 * the tab and «Поля»; a plugin that needs the location layer → «Локация» (the
 * Location_Provider_Registry hands its handler over here instead of registering a tab of its
 * own); a constructed Pickup_Handler → «Карта».
 *
 * @since 2.0.2
 */
final class Shipping_Settings_Tab {

	public const SERVICE_ID = 'shipping';

	private static ?self $instance = null;
	private bool $shipping_plugin_declared = false;
	private bool $map_needed = false;
	/** @var \Woodev_Abstract_Settings|null */
	private $location_handler = null;
	/** @var string[] */
	private array $location_setting_ids = [];
	private ?Checkout_Field_Settings $field_settings = null;
	private ?Pickup_Map_Settings $map_settings = null;
	private bool $registered = false;

	public static function instance(): self { … }
	public static function reset_for_tests(): void { self::$instance = null; }

	/** @since 2.0.2 */
	public function declare_shipping_plugin(): void {
		$this->shipping_plugin_declared = true;
		$this->hook_once();
	}

	/** @since 2.0.2 */
	public function declare_map_needed(): void { $this->map_needed = true; }

	/**
	 * Called by Location_Provider_Registry::register_settings() (init:20) instead of registering
	 * its own service.
	 *
	 * @since 2.0.2
	 * @param \Woodev_Abstract_Settings $handler the Location_Settings handler.
	 * @param string[]                  $ids     its owned setting ids, in display order.
	 */
	public function set_location_section( $handler, array $ids ): void {
		$this->location_handler     = $handler;
		$this->location_setting_ids = $ids;
	}

	public function is_needed(): bool { return $this->shipping_plugin_declared; }

	/** Lazily built so tests can read the handlers without WP. @since 2.0.2 */
	public function get_field_settings(): Checkout_Field_Settings { return $this->field_settings ??= new Checkout_Field_Settings(); }
	public function get_map_settings(): Pickup_Map_Settings { return $this->map_settings ??= new Pickup_Map_Settings(); }

	/**
	 * @since 2.0.2
	 * @return Settings_Section[]
	 */
	public function build_sections(): array {
		if ( ! $this->shipping_plugin_declared ) {
			return [];
		}
		$sections = [];
		if ( null !== $this->location_handler ) {
			$sections[] = Settings_Section::create( 'location', __( 'Локация', 'woodev-plugin-framework' ), $this->location_setting_ids );
		}
		$sections[] = Settings_Section::create( 'fields', __( 'Поля', 'woodev-plugin-framework' ), $this->get_field_settings()->get_owned_setting_ids(), $this->get_field_settings()->get_section_note() );
		if ( $this->map_needed ) {
			$sections[] = Settings_Section::create( 'map', __( 'Карта', 'woodev-plugin-framework' ), $this->get_map_settings()->get_owned_setting_ids() );
		}
		return $sections;
	}

	private function hook_once(): void {
		if ( $this->registered ) { return; }
		$this->registered = true;
		// AFTER Location_Provider_Registry::collect() (init:20) so the location handler exists.
		add_action( 'init', [ $this, 'register' ], 25 );
	}

	/** @since 2.0.2 */
	public function register(): void {
		$children = array_filter( [ $this->location_handler, $this->get_field_settings(), $this->map_needed ? $this->get_map_settings() : null ] );
		Settings_Page_Registry::instance()->register_service(
			Settings_Provider::create(
				self::SERVICE_ID,
				__( 'Доставка', 'woodev-plugin-framework' ),
				new Composite_Settings_Handler( self::SERVICE_ID, array_values( $children ) ),
				$this->build_sections()
			)
		);
	}
}
```

`Location_Provider_Registry::register_settings()` (`:1443–1457`): replace the
`Settings_Page_Registry::instance()->register_service( … )` call with
`Shipping_Settings_Tab::instance()->set_location_section( $this->settings_handler, $this->settings_handler->get_owned_setting_ids() );`.
Keep `apply_default_locality_status_note()`. **The tab id changes from `location` to `shipping`**
— grep `'location'` in `src/`, `tests/`, `docs-internal/` for anything that addresses the tab by
id (REST route `woodev/v1/settings/{tab}` — the React app discovers tabs, but a test may hardcode
it) and update.

`Shipping_Plugin::add_hooks()`: before the `needs_location_provider()` block add
`Settings\Shipping_Settings_Tab::instance()->declare_shipping_plugin();`. Add
`require_once $path . '/settings/class-shipping-settings-tab.php';` next to the location requires
(the file needs `Checkout_Field_Settings` and `Pickup_Map_Settings` — Tasks 5 and 8 create them;
until then stub both as empty handlers, or order the tasks so 5 and 8's handler files land first —
**recommended: create the two handler files as empty `Woodev_Abstract_Settings` subclasses in
this task, fill them in Tasks 5/8**).

`Pickup_Handler::__construct()` (`class-pickup-handler.php:520`): add
`\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::instance()->declare_map_needed();` as
the last line.

- [ ] **Step 4:** unit green; `php bin/generate-class-map.php`; integration suite green (the settings tab test(s) in `tests/integration` that look for «Локация» must now find the «Доставка» tab with a «Локация» section — update them).
- [ ] **Step 5: Rig check** — `Woodev → Настройки`: one tab «Доставка», sections «Локация» (all previous controls intact) / «Поля» (empty for now) / «Карта» (empty). Screenshot into the scratchpad.
- [ ] **Step 6: Commit** — `feat(shipping): «Доставка» tab registrar; «Локация» becomes its first section`.

**PR #1 here** (Tasks 1–4). Codex critic. Merge on green.

---

### Task 5: `Checkout_Field_Settings` — the «Поля» handler with availability rules

**Files:**
- Create/fill: `woodev/shipping-method/checkout/class-checkout-field-settings.php`
- Test: `tests/unit/Shipping/Checkout/CheckoutFieldSettingsTest.php`

Settings (ids, option names `woodev_checkout_fields_{id}`):

| id | type | control | options | default |
|---|---|---|---|---|
| `field_order_preset` | boolean | checkbox | — | `true` |
| `country_field` | string | select | `show` / `hide` | `show` |
| `region_field` | string | select | `show` / `remove` | `show` |
| `address_field` | string | select | `show` / `hide_for_pickup` | `show` |
| `postcode_field` | string | select | `show` / `hide_for_pickup` / `remove` | `show` |

Availability (design §3.2) is computed at construction from an injected `Environment` value object
so it is unit-testable without WP:

```php
/**
 * What the availability rules need to know, resolved once by the caller.
 * @since 2.0.2
 */
final class Checkout_Field_Environment {
	public function __construct( public bool $block_checkout, public int $shipping_country_count ) {}
	public static function from_wc(): self {
		$block = class_exists( '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils' )
			&& \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default();
		$count = function_exists( 'WC' ) && WC()->countries ? count( WC()->countries->get_shipping_countries() ) : 0;
		return new self( $block, $count );
	}
}
```

(PHP 7.4 target: **no constructor property promotion** — write the two properties + assignments
long-hand. Reuse `Blocks_Handler::is_checkout_block_in_use()` (`woodev/handlers/blocks-handler.php:77`)
if it is reachable statically; it is an instance method on a plugin-bound handler, so the direct
`CartCheckoutUtils` call above is acceptable — say which you used.)

- [ ] **Step 1: Failing tests**

```php
public function test_defaults_and_ids(): void {
	$s = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 1 ) );
	$this->assertSame( [ 'field_order_preset', 'country_field', 'region_field', 'address_field', 'postcode_field' ], $s->get_owned_setting_ids() );
	$this->assertTrue( $s->get_value( 'field_order_preset' ) );
	$this->assertSame( 'show', $s->get_value( 'postcode_field' ) );
}

public function test_country_hide_disabled_with_reason_when_store_ships_to_many_countries(): void {
	$s = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 3 ) );
	$c = $s->get_setting( 'country_field' )->get_control();
	$this->assertTrue( $c->is_disabled() );
	$this->assertStringContainsString( 'одну страну', $c->get_disabled_reason() );
}

public function test_block_checkout_disables_js_driven_options_and_narrows_postcode(): void {
	$s = new Checkout_Field_Settings( new Checkout_Field_Environment( true, 1 ) );
	$this->assertTrue( $s->get_setting( 'address_field' )->get_control()->is_disabled() );
	$this->assertTrue( $s->get_setting( 'country_field' )->get_control()->is_disabled() );
	$this->assertSame( [ 'show', 'remove' ], array_keys( $s->get_setting( 'postcode_field' )->get_control()->get_options() ) );
	$this->assertFalse( $s->get_setting( 'region_field' )->get_control()->is_disabled() ); // locale instrument reaches blocks
	$this->assertFalse( $s->get_setting( 'field_order_preset' )->get_control()->is_disabled() );
}

public function test_effective_values_clamp_on_read(): void {
	Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => 'woodev_checkout_fields_country_field' === $k ? 'hide' : $d );
	$s = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 2 ) );
	$this->assertSame( 'show', $s->effective( 'country_field' ) ); // stored `hide`, no longer allowed
	Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => 'woodev_checkout_fields_postcode_field' === $k ? 'hide_for_pickup' : $d );
	$s = new Checkout_Field_Settings( new Checkout_Field_Environment( true, 1 ) );
	$this->assertSame( 'show', $s->effective( 'postcode_field' ) );
}
```

- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** — `class Checkout_Field_Settings extends \Woodev_Abstract_Settings`,
  id `checkout_fields`, constructor `( ?Checkout_Field_Environment $env = null )` (defaults to
  `Checkout_Field_Environment::from_wc()`), `register_settings()` registers the five settings and
  controls, then applies availability:

```php
$block = $this->env->block_checkout;
$reason_block = __( 'Недоступно на блочном чекауте: эта опция работает через скрипт классической формы оформления.', 'woodev-plugin-framework' );

if ( 1 !== $this->env->shipping_country_count ) {
	$this->get_setting( 'country_field' )->get_control()->set_disabled( true, __( 'Доступно, когда магазин доставляет ровно в одну страну (WooCommerce → Настройки → Общие → Доставка в).', 'woodev-plugin-framework' ) );
} elseif ( $block ) {
	$this->get_setting( 'country_field' )->get_control()->set_disabled( true, $reason_block );
}
if ( $block ) {
	$this->get_setting( 'address_field' )->get_control()->set_disabled( true, $reason_block );
	$pc = $this->get_setting( 'postcode_field' )->get_control();
	$pc->set_options( [ 'show' => …, 'remove' => … ], $this->get_setting( 'postcode_field' )->get_options() );
	$pc->set_description( $pc->get_description() . ' ' . __( 'Значение «Скрывать для методов ПВЗ» недоступно на блочном чекауте.', 'woodev-plugin-framework' ) );
}
```

plus

```php
/**
 * The value the checkout must ACT on: the stored value clamped to what is currently
 * allowed (design §7 — clamp on read, never rewrite).
 * @since 2.0.2
 */
public function effective( string $id ) { … }   // country_field: 'show' unless allowed; postcode: 'show' if 'hide_for_pickup' && block; address: 'show' if block; others: stored
public function get_owned_setting_ids(): array { … }
public function get_section_note(): string { return ''; } // Task 7 fills it with the override report
```

- [ ] **Step 4:** unit green; commit — `feat(shipping): «Поля» settings handler with availability rules`.

---

### Task 6: `Checkout_Field_Policy` — locale + late unset (+ pickup-method ids to the browser)

**Files:**
- Create: `woodev/shipping-method/checkout/class-checkout-field-policy.php`
- Modify: `woodev/shipping-method/checkout/class-checkout-config.php` (publish `field_policy` + `pickup_method_ids` in the JS config; find where the location block is merged, ~`:229–256`)
- Modify: `woodev/shipping-method/settings/class-shipping-settings-tab.php` (`register()` also boots the policy: `Checkout_Field_Policy::instance()->register( $this->get_field_settings() )`)
- Test: `tests/unit/Shipping/Checkout/CheckoutFieldPolicyTest.php`

- [ ] **Step 1: Failing tests** (pure functions first — the policy exposes its two contributions as static/pure methods, then thin hook callbacks over them):

```php
public function test_locale_contribution_for_preset_only(): void {
	$out = Checkout_Field_Policy::locale_contribution( [ 'field_order_preset' => true, 'region_field' => 'show', 'postcode_field' => 'show' ], [ 'RU' => [] ], [ 'RU', 'KZ' ] );
	$this->assertSame( 10, $out['RU']['country']['priority'] );
	$this->assertSame( 20, $out['RU']['state']['priority'] );
	$this->assertSame( 30, $out['RU']['city']['priority'] );
	$this->assertSame( 40, $out['RU']['address_1']['priority'] );
	$this->assertSame( 50, $out['RU']['address_2']['priority'] );
	$this->assertSame( 60, $out['RU']['postcode']['priority'] );
	$this->assertSame( 20, $out['KZ']['state']['priority'] );        // every shipping country (S5)
	$this->assertTrue( $out['RU']['city']['required'] );            // settlement invariant, always
	$this->assertArrayNotHasKey( 'hidden', $out['RU']['state'] );
}

public function test_locale_contribution_removes_region_and_postcode(): void {
	$out = Checkout_Field_Policy::locale_contribution( [ 'field_order_preset' => false, 'region_field' => 'remove', 'postcode_field' => 'remove' ], [], [ 'RU' ] );
	$this->assertTrue( $out['RU']['state']['hidden'] );
	$this->assertFalse( $out['RU']['state']['required'] );
	$this->assertTrue( $out['RU']['postcode']['hidden'] );
	$this->assertArrayNotHasKey( 'priority', $out['RU']['country'] );
	// existing locale keys survive
	$out2 = Checkout_Field_Policy::locale_contribution( [ 'field_order_preset' => false, 'region_field' => 'show', 'postcode_field' => 'show' ], [ 'RU' => [ 'postcode' => [ 'label' => 'Индекс' ] ] ], [ 'RU' ] );
	$this->assertSame( 'Индекс', $out2['RU']['postcode']['label'] );
}

public function test_checkout_fields_late_unset_removes_from_both_sections(): void {
	$fields = [ 'billing' => [ 'billing_state' => [], 'billing_postcode' => [], 'billing_city' => [ 'required' => true ] ], 'shipping' => [ 'shipping_state' => [], 'shipping_postcode' => [], 'shipping_city' => [ 'required' => true ] ] ];
	$out = Checkout_Field_Policy::checkout_fields_contribution( [ 'region_field' => 'remove', 'postcode_field' => 'show' ], $fields );
	$this->assertArrayNotHasKey( 'billing_state', $out['billing'] );
	$this->assertArrayNotHasKey( 'shipping_state', $out['shipping'] );
	$this->assertArrayHasKey( 'shipping_postcode', $out['shipping'] );
}
```

- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** — a singleton with `register( Checkout_Field_Settings $settings )`:

```php
add_filter( 'woocommerce_get_country_locale', [ $this, 'filter_country_locale' ], self::LATE );
add_filter( 'woocommerce_checkout_fields', [ $this, 'filter_checkout_fields' ], self::LATE );
```

`const LATE = PHP_INT_MAX - 10;` (documented as «after everyone with an opinion», T4). Callbacks
compute the effective values map once per request (`effective()` for the five ids), call the pure
functions, and — Task 7 — run the invariant restoration. `filter_country_locale( $locale )` passes
`WC()->countries->get_shipping_countries()` keys as the third argument.

`Checkout_Config`: publish to the browser (in the block the classic script reads):

```php
'field_policy' => [
	'address'  => $settings->effective( 'address_field' ),   // 'show' | 'hide_for_pickup'
	'postcode' => $settings->effective( 'postcode_field' ),  // 'show' | 'hide_for_pickup' | 'remove'
	'country'  => $settings->effective( 'country_field' ),   // 'show' | 'hide'
],
'pickup_method_ids' => $this->pickup_method_ids(),          // string[] of WC method ids whose Shipping_Method::is_pickup_shipping()
```

`pickup_method_ids()`: iterate `WC()->shipping()->get_shipping_methods()`, keep instances of
`\Woodev\Framework\Shipping\Shipping_Method` with `is_pickup_shipping()`, return `get_id()`s.
Guard for the unit suite (no `WC()`): return `[]`.

- [ ] **Step 4:** unit green; commit — `feat(shipping): checkout field policy — order preset, region/postcode removal via locale + late unset`.

---

### Task 7: Invariant restoration + status note (S8)

**Files:**
- Modify: `class-checkout-field-policy.php`, `class-checkout-field-settings.php` (`get_section_note()`), `class-shipping-settings-tab.php` (section description reads the note)
- Test: extend `CheckoutFieldPolicyTest.php`

- [ ] **Step 1: Failing tests**

```php
public function test_settlement_removed_by_a_third_party_is_restored_and_recorded(): void {
	$fields = [ 'billing' => [ 'billing_address_1' => [] ], 'shipping' => [ 'shipping_address_1' => [] ] ];
	$policy = new Checkout_Field_Policy_Probe(); // test subclass exposing overrides
	$out = $policy->restore_invariants( $fields, [ 'city' => [ 'label' => 'Город', 'required' => true, 'class' => [ 'form-row-wide' ], 'priority' => 70 ] ] );
	$this->assertTrue( $out['billing']['billing_city']['required'] );
	$this->assertTrue( $out['shipping']['shipping_city']['required'] );
	$this->assertSame( [ [ 'field' => 'city', 'what' => 'restored' ], [ 'field' => 'city', 'what' => 'restored' ] ], array_map( fn( $o ) => [ 'field' => $o['field'], 'what' => $o['what'] ], $policy->get_overrides() ) );
}

public function test_settlement_made_optional_is_made_required_again(): void {
	$fields = [ 'billing' => [ 'billing_city' => [ 'required' => false ] ], 'shipping' => [ 'shipping_city' => [ 'required' => false ] ] ];
	$policy = new Checkout_Field_Policy_Probe();
	$out = $policy->restore_invariants( $fields, [ 'city' => [ 'required' => true ] ] );
	$this->assertTrue( $out['shipping']['shipping_city']['required'] );
	$this->assertSame( 'required', $policy->get_overrides()[0]['what'] );
}

public function test_other_fields_are_left_to_the_field_manager(): void {
	$fields = [ 'billing' => [ 'billing_city' => [ 'required' => true ], 'billing_phone' => [ 'required' => false ] ], 'shipping' => [ 'shipping_city' => [ 'required' => true ] ] ];
	$policy = new Checkout_Field_Policy_Probe();
	$out = $policy->restore_invariants( $fields, [ 'city' => [ 'required' => true ] ] );
	$this->assertFalse( $out['billing']['billing_phone']['required'] );
	$this->assertSame( [], $policy->get_overrides() );
}
```

- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** — in `filter_checkout_fields()`, after the contribution:
  `$fields = $this->restore_invariants( $fields, WC()->countries->get_default_address_fields() )`
  (the second argument is WC's default template — the source for re-inserting a removed `city`).
  In `filter_country_locale()`: force `city.required = true` for every shipping country (already
  in Task 6's contribution — keep it there). Record overrides in `$this->overrides[]` as
  `[ 'field' => 'city', 'section' => 'billing'|'shipping', 'what' => 'restored'|'required' ]`.
  Persist a compact summary for the admin page: `update_option( 'woodev_checkout_fields_last_overrides', … )`
  only when the array is non-empty and different from the stored one (a transient is fine too —
  choose and say). `Checkout_Field_Settings::get_section_note()` renders it:
  «Поле «Город» было изменено сторонним кодом (снята обязательность / удалено); фреймворк
  восстановил его — оформление заказа зависит от этого поля.» Empty string when nothing was
  overridden. `Shipping_Settings_Tab::build_sections()` already passes the note as the «Поля»
  section description.
- [ ] **Step 4:** unit green. Rig: install a throwaway mu-plugin in the scratchpad → `docker cp`
  that unsets `shipping_city` at priority 20; confirm the field is back on `/classic-checkout/`
  and the note appears in admin; remove the mu-plugin (control run: note disappears on the next
  request). Commit — `feat(shipping): restore the settlement field invariants a field manager broke, and say so in admin`.

**PR #2 here** (Tasks 5–7). Codex critic. Merge on green.

---

### Task 8: `Pickup_Map_Settings` + placement precedence + `Pickup_Handler` reads the store

**Files:**
- Create/fill: `woodev/shipping-method/pickup/class-pickup-map-settings.php`
- Modify: `woodev/shipping-method/checkout/class-checkout-config.php:319–340` (`resolve_pickup_slot_placements()`)
- Modify: `woodev/shipping-method/pickup/class-pickup-handler.php:520–553` (ctor), `:1421–1440` (`get_js_config()`), the `$replace_address` / `$close_on_select` property docblocks `:185–216`
- Modify: `tests/_fixtures/woodev-test-shipping-method/woodev-test-shipping-method.php:736–752` (ctor call; drop the two constants `WOODEV_TEST_PICKUP_SELECTION_CLOSE` argument — keep `…_REFRESH_CHECKOUT`)
- Test: `tests/unit/Shipping/Pickup/PickupMapSettingsTest.php`, extend `tests/unit/Shipping/Checkout/CheckoutConfigTest.php`, `tests/unit/Shipping/Pickup/PickupHandlerTest.php`

Settings (option names `woodev_pickup_map_{id}`):

| id | type | control | options | default |
|---|---|---|---|---|
| `pickup_button_placement` | string | select | `rate` («В строке выбранного метода») / `review` («После списка методов») | `rate` |
| `pickup_replace_address` | boolean | checkbox | — | `true` |
| `pickup_close_on_select` | boolean | checkbox | — | `false` |

- [ ] **Step 1: Failing tests**

```php
// CheckoutConfigTest
public function test_placement_precedence_default_then_store_setting_then_filter(): void {
	// default
	$this->assertSame( [ 'rate' ], $this->resolve_placements() );
	// store setting
	Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => 'woodev_pickup_map_pickup_button_placement' === $k ? 'review' : $d );
	$this->assertSame( [ 'review' ], $this->resolve_placements() );
	// filter wins last
	Filters\expectApplied( 'woodev_pickup_slot_placements' )->once()->with( [ 'review' ], Mockery::any(), Mockery::any() )->andReturn( [ 'rate', 'review' ] );
	$this->assertSame( [ 'review', 'rate' ], $this->resolve_placements() );
}

// PickupHandlerTest
public function test_js_config_reads_replace_address_and_close_from_the_store(): void {
	Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => [ 'woodev_pickup_map_pickup_replace_address' => false, 'woodev_pickup_map_pickup_close_on_select' => true ][ $k ] ?? $d );
	$config = $this->handler()->get_js_config();
	$this->assertFalse( $config['replaceAddress']['enabled'] );
	$this->assertTrue( $config['selection']['close'] );
	$this->assertFalse( $config['selection']['refreshCheckout'] ); // still the ctor arg
}
```

(Look at how `PickupHandlerTest` builds a handler today — `Functions\when('get_option')` may
already be aliased there; merge, do not shadow.)

- [ ] **Step 2: Run** — FAIL (ctor arity, option not read).
- [ ] **Step 3: Implement**
  - `Pickup_Map_Settings extends \Woodev_Abstract_Settings`, id `pickup_map`, the three settings +
    `get_owned_setting_ids()`; a static accessor
    `Pickup_Map_Settings::current(): self` that returns
    `Shipping_Settings_Tab::instance()->get_map_settings()`.
  - `resolve_pickup_slot_placements()`: `$default = [ Pickup_Map_Settings::current()->get_value( 'pickup_button_placement' ) ]` (clamped to `rate`/`review`, else `[ 'rate' ]`), then the filter receives `$default` instead of the literal `[ 'rate' ]`. Update the docblock: the reserved «future framework-level store setting» step now exists.
  - `Pickup_Handler::__construct()`: **remove** `bool $replace_address = true` (position 8) and `bool $close_on_select = false` (position 13); remove the two properties; in `get_js_config()`:
    `'enabled' => Pickup_Map_Settings::current()->get_value( 'pickup_replace_address' )` and
    `'close' => (bool) Pickup_Map_Settings::current()->get_value( 'pickup_close_on_select' )`.
    Rewrite the `$close_on_select` docblock paragraph («a carrier's decision to make») — it is now
    the store's decision by design S7; keep the `??`-reading note for `Selection_Result`.
  - Fixture ctor call: drop the `true` (8th) and `WOODEV_TEST_PICKUP_SELECTION_CLOSE` (13th)
    arguments; delete that constant's definition (grep `WOODEV_TEST_PICKUP_SELECTION_CLOSE` across
    the repo, `.wp-env*.json`, `docs-internal/CURRENT-STATE.md`).
  - grep `new Pickup_Handler(` / `Pickup_Handler(` in `tests/` for other constructions and fix arity.
- [ ] **Step 4:** `composer test:unit` green (expect ctor-arity fallout in `PickupHandlerTest` — fix); jest untouched. Commit — `feat!(pickup): map behaviour is a store setting — placement, replace-address, close-on-select (drops two Pickup_Handler ctor args)`.

---

### Task 9: JS — hide-for-pickup, country-hide, DOM-presence checks, #337 comment

**Files:**
- Modify: `woodev/shipping-method/assets/js/frontend/checkout-field-classic.js` (it already reads the config and reacts to `updated_checkout` — put the field policy there; `location-cascade.js` stays about suggestions)
- Modify: `woodev/shipping-method/assets/js/frontend/location-cascade.js` — `refreshAddressLock()` (#337) comment: replace «the same standing operator rule» with «the narrow exception (D11 in `2026-08-18-location-and-field-settings-brainstorm-input.md` §4): the customer just performed the action that locked the field and can see the causality; blocked controls are explained by default elsewhere»
- Test: `tests/js/checkout-field-classic.test.js`

- [ ] **Step 1: Failing tests**

```js
describe( 'field policy', () => {
	function mountCheckout( policy, chosen ) {
		document.body.innerHTML = `
			<form class="checkout">
				<p class="form-row" id="shipping_country_field"><select id="shipping_country" name="shipping_country"><option value="RU" selected>RU</option></select></p>
				<p class="form-row" id="shipping_address_1_field"><input id="shipping_address_1" name="shipping_address_1" required></p>
				<p class="form-row" id="shipping_postcode_field"><input id="shipping_postcode" name="shipping_postcode" required></p>
				<ul><li><input type="radio" name="shipping_method[0]" value="${ chosen }" checked></li></ul>
			</form>`;
		window.woodevCheckoutFieldConfig = { field_policy: policy, pickup_method_ids: [ 'test_pickup' ], fields: [] };
		jest.isolateModules( () => require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-classic.js' ) );
		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
	}

	test( 'hide_for_pickup hides address + postcode rows and drops required while a pickup method is chosen', () => {
		mountCheckout( { address: 'hide_for_pickup', postcode: 'hide_for_pickup', country: 'show' }, 'test_pickup:1' );
		expect( document.getElementById( 'shipping_address_1_field' ).classList.contains( 'woodev-field--hidden-for-pickup' ) ).toBe( true );
		expect( document.getElementById( 'shipping_address_1' ).required ).toBe( false );
		expect( document.getElementById( 'shipping_postcode' ).required ).toBe( false );
	} );

	test( 'switching to a courier method restores the rows and required', () => {
		mountCheckout( { address: 'hide_for_pickup', postcode: 'show', country: 'show' }, 'test_pickup:1' );
		document.querySelector( 'input[name^="shipping_method"]' ).value = 'test_courier:1';
		jQuery( document.body ).trigger( 'updated_checkout' );
		expect( document.getElementById( 'shipping_address_1_field' ).classList.contains( 'woodev-field--hidden-for-pickup' ) ).toBe( false );
		expect( document.getElementById( 'shipping_address_1' ).required ).toBe( true );
	} );

	test( 'country hide keeps the value in the DOM', () => {
		mountCheckout( { address: 'show', postcode: 'show', country: 'hide' }, 'test_courier:1' );
		expect( document.getElementById( 'shipping_country_field' ).classList.contains( 'woodev-field--hidden' ) ).toBe( true );
		expect( document.getElementById( 'shipping_country' ).value ).toBe( 'RU' );
	} );

	test( 'an absent field is a no-op, not an exception', () => {
		expect( () => mountCheckout( { address: 'hide_for_pickup', postcode: 'remove', country: 'hide' }, 'test_pickup:1' ) ).not.toThrow();
	} );
} );
```

Adapt the config global name and the module's init contract to what `checkout-field-classic.js`
actually does today (read its header docblock; the config object name and the init trigger are
there). The point of the tests, not the literal selectors, is the contract.

- [ ] **Step 2: Run** jest — FAIL.
- [ ] **Step 3: Implement** — a `fieldPolicy` module inside `checkout-field-classic.js`:
  `applyFieldPolicy()` on init and on `updated_checkout`; `chosenIsPickup()` = the checked
  `input[name^="shipping_method"]` value equals or starts with `id + ':'` for any of
  `pickup_method_ids` (same rule as `Checkout_Handler::chosen_method_matches()`); for `billing_*`
  and `shipping_*` targets: `hide_for_pickup` → toggle class `woodev-field--hidden-for-pickup` on
  the `_field` row + `required=false` while pickup (remembering the original `required` on a
  `data-` attribute to restore); `country: hide` → class `woodev-field--hidden` on the country row
  (never touch the value). Every lookup goes through `document.getElementById(...)` at call time
  (T6, and the s78 note that element references go stale after `update_checkout`). Add the two CSS
  classes to the classic checkout stylesheet (`display:none`).
- [ ] **Step 4:** jest green; `npm run build`; commit the built bundle. Rig: `/classic-checkout/`,
  set «Поле «Адрес» → Скрывать для ПВЗ», pick **Woodev Test Shipping** (pickup) → row hidden;
  pick the courier method → row back; place an order with the pickup method → the order's shipping
  address_1 holds the point's address (replace-address ON) — control: turn replace-address OFF,
  address empty. Commit — `feat(checkout-js): store field policy — hide address/postcode for pickup methods, hide country`.

**PR #3 here** (Tasks 8–9). Codex critic. Merge on green.

---

### Task 10: `address_suggestions` in «Локация»

**Files:**
- Modify: `woodev/shipping-method/location/class-location-settings.php:159–260` (register the setting after `field_mode`; relabel `field_mode` to «Тип поля НП/Регион»; `get_owned_setting_ids()` order: `active_provider`, `field_mode`, `address_suggestions`, `default_locality_*`, provider fields)
- Modify: `woodev/shipping-method/location/class-location-provider-registry.php` — constant `SETTING_ADDRESS_SUGGESTIONS = 'address_suggestions'`; `is_address_suggestions_enabled(): bool`; in `register_settings()` compute availability: any served country where `Location_Service` (or the registry's own chain logic — see `provider_for_level()`'s implementation, `class-location-service.php:1510`) resolves a provider for `address` → enabled; else `set_disabled( true, «Выбранный провайдер не отдаёт адреса, а учётные данные DaData не заполнены.» )`
- Modify: `woodev/shipping-method/location/class-location-service.php:1510` (`provider_for_level()`: `if ( 'address' === $level && ! $registry->is_address_suggestions_enabled() ) return null;` — put it BEFORE the chain walk so levels/owners/suggest all agree)
- Test: `tests/unit/Shipping/Location/LocationServiceTest.php`, `LocationProviderRegistryTest.php`

- [ ] **Step 1: Failing tests**

```php
// LocationServiceTest
public function test_address_level_is_unserved_while_address_suggestions_are_off(): void {
	$this->registry_with_dadata_configured();     // existing helper pattern in this file
	Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => 'woodev_location_address_suggestions' === $k ? false : $d );
	$this->assertNull( $this->service()->provider_for_level( 'address', 'RU' ) );
	$this->assertNotNull( $this->service()->provider_for_level( 'settlement', 'RU' ) );
	$this->assertFalse( $this->service()->get_levels_for_country( 'RU' )['address'] );
}

// LocationProviderRegistryTest
public function test_address_suggestions_control_is_disabled_when_nobody_serves_address(): void {
	// active provider = CDEK fixture (region+settlement), DaData unconfigured
	…
	$c = $registry->get_settings_handler()->get_setting( 'address_suggestions' )->get_control();
	$this->assertTrue( $c->is_disabled() );
	$this->assertFalse( $registry->is_address_suggestions_enabled() ); // effective value clamps to false
}
```

- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** as described; `is_address_suggestions_enabled()` = `stored && available` (clamp on read).
- [ ] **Step 4:** unit + integration green; rig: DaData active → checkbox enabled, uncheck → address field on `/classic-checkout/` is a plain input (no typeahead, no lock); `test-cdek` active with DaData token blanked → checkbox disabled with the reason. Commit — `feat(location): «Подсказки для адреса» store switch; field type relabelled`.

---

### Task 11: Docs, gotcha, board

- [ ] `docs-internal/wiki/architecture.md` — add the «Доставка» tab: registrar, composite handler, field policy, the two-instrument rule.
- [ ] Gotcha `block-checkout-reads-country-locale-not-checkout-fields` (root cause: `CheckoutFields::get_core_fields()` hard-codes core fields; `CartCheckoutUtils::get_country_data()` maps locale `priority→index`; ❌ unset in `woocommerce_checkout_fields` and expect the block form to follow / ✅ contribute `hidden`/`required`/`priority` through `woocommerce_get_country_locale`) — s79 already added it? **Check `docs-internal/GOTCHAS.md` first**; if the s79 handoff filed it, only cross-link.
- [ ] `docs/` public docs — do NOT touch (operator decision, `CURRENT-STATE.md`).
- [ ] Update `docs-internal/specs/2026-08-18-shipping-settings-v2-design.md` header: status → IMPLEMENTED (PRs #…).
- [ ] Board: #362 → `Готово` via `Closes #362` in the last PR; comment in Russian listing the PRs. #337 — close if the comment fix in Task 9 was its whole scope (read the card first).
- [ ] `docs-internal/CURRENT-STATE.md`: rig facts — the fixture no longer takes `WOODEV_TEST_PICKUP_SELECTION_CLOSE`; the store setting replaces it (`wp option update woodev_pickup_map_pickup_close_on_select 1`).

### Task 12: Full verification before the last merge

- [ ] `composer phpcs && composer phpstan && composer test:unit` — all green, counts recorded.
- [ ] `npm run test:js -- --roots "<rootDir>/tests/js"` (bash) — green.
- [ ] Integration suite via the container command — green.
- [ ] Rig matrix (each with a control run, screenshots to the scratchpad):
  1. classic: preset ON → order Страна > Регион > Город > Адрес > Индекс; OFF → WC default.
  2. block `/checkout/`: preset ON → same order; `region_field=remove` → no region field rendered.
  3. classic: `region_field=remove` → no `shipping_state` in DOM; order saved without state.
  4. classic: `postcode_field=hide_for_pickup` + pickup method → hidden; courier → back.
  5. `country_field=hide` with one shipping country → hidden, value in the order; add a second country → control disabled with the reason and the checkout shows the field again (clamp on read).
  6. map: placement `review` → button under the list; `close_on_select` ON → map closes; `replace_address` OFF → address untouched.
  7. `address_suggestions` OFF → address is a plain input.
- [ ] Codex critic on the final diff; findings verified against code, not on faith.

---

## Self-review against the spec

- S1 tab/sections → Task 4. S2 field type/labels → Task 10 (relabel; the mapping table is BUILT). S3 address suggestions placement + availability → Task 10. S4 selects → Task 5. S5 preset scope → Task 6. S6 block-checkout reach → Tasks 5/6 (no disabling of the preset; region `remove` via locale). S7 map options + ctor removal → Task 8. S8 invariants + note → Task 7. S9 derived sections → Task 4. §3.3 disabled surface → Tasks 1–2. §4.3 two instruments → Task 6. §4.4 JS presence checks → Task 9. §6 data contracts → Task 4 note on the tab id (`location` → `shipping` is a UI id, not a stored option — verify no option key embeds it), Task 8 (option keys new). §8 tests → per task + Task 12.
- Type/name consistency: `Checkout_Field_Settings::effective()`, `get_owned_setting_ids()`, `get_section_note()`; `Pickup_Map_Settings::current()`; `Shipping_Settings_Tab::{declare_shipping_plugin, declare_map_needed, set_location_section, build_sections, register, get_field_settings, get_map_settings}`; `Checkout_Field_Policy::{locale_contribution, checkout_fields_contribution, restore_invariants, get_overrides, register}` — used consistently above.
- Known open detail left to the implementer, on purpose: whether `Checkout_Field_Environment` uses `Blocks_Handler::is_checkout_block_in_use()` or `CartCheckoutUtils` directly (state which); persistence shape of the override note (option vs transient).
