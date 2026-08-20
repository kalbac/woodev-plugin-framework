<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Settings\Composite_Settings_Handler;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/class-composite-settings-handler.php';

/**
 * `Composite_Settings_Handler` routes every `Field_Schema` / REST-controller call to the real
 * `Woodev_Abstract_Settings` child that registered a given setting id.
 *
 * Copies FieldSchemaTest's `make_handler()` helper (extended with an `$id` param — the plan's
 * test called `make_handler( 'alpha', fn )`, but FieldSchemaTest's original only takes the
 * callable, so the composite needs its own child handlers to keep separate option namespaces).
 */
class CompositeSettingsHandlerTest extends TestCase {

	/**
	 * Builds an anonymous settings handler whose register_settings() runs the supplied closure,
	 * so settings can be registered and have values set purely in memory (no WordPress DB).
	 *
	 * @param string   $id       handler ID (== its option namespace, "woodev_{$id}_...").
	 * @param callable $register closure receiving the handler to register settings on.
	 * @return \Woodev_Abstract_Settings
	 */
	private function make_handler( string $id, callable $register ): \Woodev_Abstract_Settings {

		// Stub the WP plumbing the abstract handler touches during construction.
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		return new class( $id, $register ) extends \Woodev_Abstract_Settings {

			/** @var callable */
			private $register_cb;

			/**
			 * @param string   $id       handler ID.
			 * @param callable $register closure registering settings.
			 */
			public function __construct( string $id, callable $register ) {
				$this->register_cb = $register;
				parent::__construct( $id );
			}

			/**
			 * Runs the supplied registration closure.
			 *
			 * @return void
			 */
			protected function register_settings() {
				( $this->register_cb )( $this );
			}
		};
	}

	public function test_routes_get_set_and_validation_to_the_owning_child(): void {
		$a = $this->make_handler(
			'alpha',
			function ( $h ) {
				$h->register_setting( 'one', \Woodev_Setting::TYPE_BOOLEAN, [ 'name' => 'One', 'default' => false ] );
			}
		);
		$b = $this->make_handler(
			'beta',
			function ( $h ) {
				$h->register_setting( 'two', \Woodev_Setting::TYPE_STRING, [
					'name'    => 'Two',
					'default' => 'x',
					'options' => [ 'x' => 'X', 'y' => 'Y' ],
				] );
			}
		);

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
		$a = $this->make_handler(
			'alpha',
			function ( $h ) {
				$h->register_setting( 'dup', \Woodev_Setting::TYPE_STRING );
			}
		);
		$b = $this->make_handler(
			'beta',
			function ( $h ) {
				$h->register_setting( 'dup', \Woodev_Setting::TYPE_STRING );
			}
		);
		$this->expectException( \InvalidArgumentException::class );
		new Composite_Settings_Handler( 'shipping', [ $a, $b ] );
	}

	public function test_filters_cross_handler_conditions_against_submitted_or_stored_controller_values(): void {
		$checkout_fields = $this->make_handler(
			'checkout_fields',
			function ( $h ) {
				$h->register_setting( 'region_field', \Woodev_Setting::TYPE_STRING, [
					'name'    => 'Region field',
					'default' => 'remove',
				] );
			}
		);
		$location = $this->make_handler(
			'location',
			function ( $h ) {
				$h->register_setting( 'field_mode_region', \Woodev_Setting::TYPE_STRING, [
					'name'    => 'Region mode',
					'default' => 'typeahead',
					'show_if' => [ 'setting' => 'region_field', 'operator' => '!=', 'value' => 'remove' ],
				] );
			}
		);
		$composite = new Composite_Settings_Handler( 'shipping', [ $checkout_fields, $location ] );

		$this->assertSame(
			[ 'region_field' => 'remove' ],
			$composite->filter_visible_values( [ 'region_field' => 'remove', 'field_mode_region' => 'list' ] )
		);
		$this->assertSame(
			[],
			$composite->filter_visible_values( [ 'field_mode_region' => 'list' ] )
		);
		$this->assertSame(
			[ 'region_field' => 'show', 'field_mode_region' => 'list' ],
			$composite->filter_visible_values( [ 'region_field' => 'show', 'field_mode_region' => 'list' ] )
		);
	}

	public function test_filters_cross_handler_conditions_order_independently_and_degrades_unknown_controller_to_empty(): void {
		$checkout_fields = $this->make_handler(
			'checkout_fields',
			function ( $h ) {
				$h->register_setting( 'region_field', \Woodev_Setting::TYPE_STRING, [
					'name'    => 'Region field',
					'default' => 'show',
					'show_if' => [ 'setting' => 'mode', 'value' => 'live' ],
				] );
			}
		);
		$location = $this->make_handler(
			'location',
			function ( $h ) {
				$h->register_setting( 'field_mode_region', \Woodev_Setting::TYPE_STRING, [
					'name'    => 'Region mode',
					'default' => 'typeahead',
					'show_if' => [ 'setting' => 'region_field', 'value' => 'show' ],
				] );
				$h->register_setting( 'unknown_controller', \Woodev_Setting::TYPE_STRING, [
					'name'    => 'Unknown controller',
					'default' => 'x',
					'show_if' => [ 'setting' => 'missing', 'value' => 'present' ],
				] );
			}
		);
		$composite = new Composite_Settings_Handler( 'shipping', [ $checkout_fields, $location ] );

		$this->assertSame(
			[ 'field_mode_region' => 'list' ],
			$composite->filter_visible_values(
				[
					'field_mode_region' => 'list',
					'unknown_controller' => 'value',
					'region_field'       => 'show',
					'mode'               => 'test',
				]
			)
		);
		$this->assertSame(
			[ 'field_mode_region' => 'list' ],
			$composite->filter_visible_values(
				[
					'field_mode_region' => 'list',
					'mode'              => 'test',
					'region_field'      => 'show',
					'unknown_controller' => 'value',
				]
			)
		);
	}

	/**
	 * Decision (deviates from the plan's skeleton, which returned null/false): get_value() and
	 * update_value() on an unknown id THROW \Woodev_Plugin_Exception, mirroring
	 * Woodev_Abstract_Settings exactly. A silent `false`/`null` return would let the REST save
	 * path (class-rest-api-settings-page.php:189, try/catch(\Throwable)) believe an update
	 * succeeded when it did nothing.
	 */
	public function test_get_value_and_update_value_throw_on_unknown_id(): void {
		$a = $this->make_handler(
			'alpha',
			function ( $h ) {
				$h->register_setting( 'one', \Woodev_Setting::TYPE_BOOLEAN, [ 'name' => 'One', 'default' => false ] );
			}
		);
		$composite = new Composite_Settings_Handler( 'shipping', [ $a ] );

		$this->expectException( \Woodev_Plugin_Exception::class );
		$composite->get_value( 'nope' );
	}

	public function test_update_value_throws_on_unknown_id(): void {
		$a = $this->make_handler(
			'alpha',
			function ( $h ) {
				$h->register_setting( 'one', \Woodev_Setting::TYPE_BOOLEAN, [ 'name' => 'One', 'default' => false ] );
			}
		);
		$composite = new Composite_Settings_Handler( 'shipping', [ $a ] );

		$this->expectException( \Woodev_Plugin_Exception::class );
		$composite->update_value( 'nope', true );
	}
}
