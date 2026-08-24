<?php
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Settings\Composite_Settings_Handler;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-page/interface-connection-test.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-connection-result.php';
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
	/**
	 * A section that interleaves two handlers' fields must render in the order it declared,
	 * not grouped by owning handler.
	 *
	 * Found on the rig: the «Поля» section lists `region_field` (checkout handler) and the
	 * region field-type axis (location handler) next to each other on purpose — each axis
	 * belongs beside the field it governs — but collecting child by child pulled every
	 * location-owned setting above every checkout-owned one, so the axes rendered detached
	 * from their fields. Unit and jest suites both passed: nothing asserted display order
	 * across handlers.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_get_settings_follows_the_requested_id_order_across_children(): void {
		$location = $this->make_handler(
			'location',
			function ( $h ) {
				$h->register_setting( 'field_mode_region', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Тип поля Регион' ] );
			}
		);

		$checkout = $this->make_handler(
			'checkout',
			function ( $h ) {
				$h->register_setting( 'country_field', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Страна' ] );
				$h->register_setting( 'region_field', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Регион' ] );
			}
		);

		$composite = new Composite_Settings_Handler( 'shipping', [ $location, $checkout ] );

		$this->assertSame(
			[ 'country_field', 'region_field', 'field_mode_region' ],
			array_keys( $composite->get_settings( [ 'country_field', 'region_field', 'field_mode_region' ] ) ),
			'the section declared order wins over child order'
		);

		$this->assertSame(
			[ 'field_mode_region', 'country_field' ],
			array_keys( $composite->get_settings( [ 'field_mode_region', 'country_field' ] ) ),
			'a different requested order is honoured too, and an omitted id stays omitted'
		);
	}

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

	/**
	 * Builds an anonymous handler that ALSO implements `Woodev_Settings_Connection_Test`,
	 * recording every `test_connection()` call it receives, and owning the given
	 * connection ids (#488 D8 round 2 critic MEDIUM: the composite routes by
	 * OWNERSHIP of a connection id, not by "the single implementing child").
	 *
	 * @param string                    $id              handler ID.
	 * @param \Woodev_Connection_Result $result          what `test_connection()` should return.
	 * @param array<int, array{0: string, 1: array<string, mixed>}> &$calls captures every
	 *                                                                       `[connection_id, values]` call.
	 * @param string[]                  $connection_ids  connection ids this handler owns.
	 * @return \Woodev_Abstract_Settings
	 */
	private function make_connection_handler( string $id, \Woodev_Connection_Result $result, array &$calls, array $connection_ids ) {
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		return new class( $id, $result, $calls, $connection_ids ) extends \Woodev_Abstract_Settings implements \Woodev_Settings_Connection_Test {

			private \Woodev_Connection_Result $result;
			private array $calls;
			private array $connection_ids;

			public function __construct( string $id, \Woodev_Connection_Result $result, array &$calls, array $connection_ids ) {
				$this->result         = $result;
				$this->calls          = &$calls;
				$this->connection_ids = $connection_ids;
				parent::__construct( $id );
			}

			protected function register_settings() {}

			public function test_connection( string $connection_id, array $values ): \Woodev_Connection_Result {
				$this->calls[] = [ $connection_id, $values ];

				return $this->result;
			}

			public function get_connection_ids(): array {
				return $this->connection_ids;
			}
		};
	}

	/**
	 * #488 D8: a single child implementing `Woodev_Settings_Connection_Test` —
	 * the composite must delegate the connection_id/values through unchanged
	 * and hand back that child's own result.
	 */
	public function test_delegates_test_connection_to_the_owning_child(): void {
		$calls    = [];
		$expected = \Woodev_Connection_Result::success( 'ok' );
		$location = $this->make_connection_handler( 'location', $expected, $calls, [ 'popular_settlements_clear' ] );
		$fields   = $this->make_handler(
			'fields',
			function ( $h ) {
				$h->register_setting( 'field_order_preset', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Order' ] );
			}
		);

		$composite = new Composite_Settings_Handler( 'shipping', [ $fields, $location ] );

		$this->assertInstanceOf( \Woodev_Settings_Connection_Test::class, $composite );

		$result = $composite->test_connection( 'popular_settlements_clear', [ 'x' => 'y' ] );

		$this->assertSame( $expected, $result );
		$this->assertSame( [ [ 'popular_settlements_clear', [ 'x' => 'y' ] ] ], $calls );
	}

	/**
	 * Zero children implementing the interface — a REST request only ever
	 * reaches this method for a `$connection_id` the tab's own
	 * `Settings_Section::is_connection()` list already proved exists, so
	 * reaching it with no delegate is a programming error, not a user-facing
	 * "unsupported" case; throw rather than guess.
	 */
	public function test_throws_when_no_child_implements_connection_test(): void {
		$a = $this->make_handler(
			'alpha',
			function ( $h ) {
				$h->register_setting( 'one', \Woodev_Setting::TYPE_BOOLEAN, [ 'name' => 'One', 'default' => false ] );
			}
		);
		$composite = new Composite_Settings_Handler( 'shipping', [ $a ] );

		$this->expectException( \Woodev_Plugin_Exception::class );
		$composite->test_connection( 'whatever', [] );
	}

	/**
	 * #488 D8 round 2 critic MEDIUM: a second child implementing the interface
	 * must be an ORDINARY day, not a fatal — as long as the two own DIFFERENT
	 * connection ids, each id routes to its own owner. Reproduces the critic's
	 * exact scenario: two connection-test children coexisting, each answering
	 * only for its own section.
	 */
	public function test_routes_by_connection_id_ownership_when_two_children_implement_the_interface(): void {
		$calls_a = [];
		$calls_b = [];
		$a       = $this->make_connection_handler( 'alpha', \Woodev_Connection_Result::success( 'a' ), $calls_a, [ 'connection_a' ] );
		$b       = $this->make_connection_handler( 'beta', \Woodev_Connection_Result::success( 'b' ), $calls_b, [ 'connection_b' ] );

		$composite = new Composite_Settings_Handler( 'shipping', [ $a, $b ] );

		$this->assertSame( [ 'connection_a', 'connection_b' ], $composite->get_connection_ids() );

		$result_a = $composite->test_connection( 'connection_a', [ 'x' => 1 ] );
		$this->assertTrue( $result_a->is_success() );
		$this->assertSame( 'a', $result_a->get_message() );
		$this->assertSame( [ [ 'connection_a', [ 'x' => 1 ] ] ], $calls_a );
		$this->assertSame( [], $calls_b );

		$result_b = $composite->test_connection( 'connection_b', [ 'y' => 2 ] );
		$this->assertTrue( $result_b->is_success() );
		$this->assertSame( 'b', $result_b->get_message() );
		$this->assertSame( [ [ 'connection_b', [ 'y' => 2 ] ] ], $calls_b );
	}

	/**
	 * A connection id neither child owns still throws, even though other
	 * children DO implement the interface — only actual ownership resolves
	 * the delegate now, never "any implementer will do".
	 */
	public function test_throws_when_no_child_owns_the_connection_id_even_though_others_implement_the_interface(): void {
		$calls = [];
		$a     = $this->make_connection_handler( 'alpha', \Woodev_Connection_Result::success( 'a' ), $calls, [ 'connection_a' ] );

		$composite = new Composite_Settings_Handler( 'shipping', [ $a ] );

		$this->expectException( \Woodev_Plugin_Exception::class );
		$composite->test_connection( 'nobody_owns_this', [] );
	}

	/**
	 * Two children claiming the SAME connection id is a genuine configuration
	 * error (mirrors the existing duplicate-setting-id collision check) — must
	 * fail loudly at construction time, not silently pick one.
	 */
	public function test_construction_throws_when_two_children_own_the_same_connection_id(): void {
		$calls_a = [];
		$calls_b = [];
		$a       = $this->make_connection_handler( 'alpha', \Woodev_Connection_Result::success( 'a' ), $calls_a, [ 'dup' ] );
		$b       = $this->make_connection_handler( 'beta', \Woodev_Connection_Result::success( 'b' ), $calls_b, [ 'dup' ] );

		$this->expectException( \InvalidArgumentException::class );
		new Composite_Settings_Handler( 'shipping', [ $a, $b ] );
	}
}
