<?php
/**
 * Unit test for Shipping_Plugin::report_half_declared_location_layer() (#1420) — the developer notice for a
 * plugin that declares only part of the Location Provider layer.
 *
 * The fixtures are NAMED classes built with `newInstanceWithoutConstructor()`, like
 * {@see ShippingPluginNeedsLocationProviderTest}: a real Shipping_Plugin constructor runs a long WP/WC chain.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Framework\Shipping\Location\Location_Adapter;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-shipping-plugin.php';

/**
 * No location involvement at all: both seams keep the base defaults.
 */
class Location_Guard_Bare_Plugin_Fixture extends Shipping_Plugin {

	protected function get_shipping_method_classes(): array {
		return [];
	}

	public function get_api(): ?\Woodev\Framework\Shipping\Api\Shipping_API {
		return null;
	}

	protected function get_file() {
		return __FILE__;
	}

	public function get_plugin_name() {
		return 'Stub';
	}

	public function get_download_id() {
		return 0;
	}

	public function get_id() {
		return 'stub-carrier';
	}
}

/**
 * The 2.0.3 Russian Post mistake: an adapter, no opt-in.
 */
class Location_Guard_Adapter_Only_Plugin_Fixture extends Location_Guard_Bare_Plugin_Fixture {

	public function get_location_adapter(): ?Location_Adapter {
		throw new \LogicException( 'The detection must not call the adapter getter.' );
	}
}

/**
 * Opted in; whether the adapter is overridden does not matter to the field check.
 */
class Location_Guard_Opted_In_Plugin_Fixture extends Location_Guard_Bare_Plugin_Fixture {

	public function needs_location_provider(): bool {
		return true;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::report_half_declared_location_layer
 */
final class ShippingPluginHalfDeclaredLocationLayerTest extends TestCase {

	/**
	 * @param class-string<Shipping_Plugin> $class Fixture class.
	 *
	 * @return Shipping_Plugin
	 */
	private function plugin( string $class ): Shipping_Plugin {
		return ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * @param Field[] $fields Fields the handler declares.
	 *
	 * @return Checkout_Handler
	 */
	private function handler_with( array $fields ): Checkout_Handler {
		$handler = Mockery::mock( Checkout_Handler::class );
		$handler->shouldReceive( 'get_fields' )->andReturn( Checkout_Fields::from_array( $fields ) );

		return $handler;
	}

	/**
	 * @return Field[] The correct trio.
	 */
	private function location_trio(): array {
		return [
			Field::create( 'shipping_state' )->set_type( 'text' )->source_location( Location_Record::LEVEL_REGION ),
			Field::create( 'shipping_city' )->set_type( 'text' )->source_location( Location_Record::LEVEL_SETTLEMENT ),
			Field::create( 'shipping_address_1' )->set_type( 'text' )->source_location( Location_Record::LEVEL_ADDRESS ),
		];
	}

	public function test_an_adapter_without_the_opt_in_is_reported_without_calling_the_getter(): void {
		Functions\expect( '_doing_it_wrong' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::pattern( '/stub-carrier.*overrides get_location_adapter\(\).*needs_location_provider\(\)/' ), '2.0.9' );

		$this->plugin( Location_Guard_Adapter_Only_Plugin_Fixture::class )->report_half_declared_location_layer( null );
	}

	public function test_an_opt_in_without_a_source_location_field_is_reported(): void {
		Functions\expect( '_doing_it_wrong' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::pattern( '/stub-carrier.*no field declared with Field::source_location\(\)/' ), '2.0.9' );

		$handler = $this->handler_with( [ Field::create( 'order_note' )->set_type( 'text' ) ] );

		$this->plugin( Location_Guard_Opted_In_Plugin_Fixture::class )->report_half_declared_location_layer( $handler );
	}

	public function test_the_correct_trio_reports_nothing(): void {
		Functions\expect( '_doing_it_wrong' )->never();

		$this->plugin( Location_Guard_Opted_In_Plugin_Fixture::class )->report_half_declared_location_layer( $this->handler_with( $this->location_trio() ) );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_plugin_with_no_location_involvement_reports_nothing(): void {
		Functions\expect( '_doing_it_wrong' )->never();

		$plugin = $this->plugin( Location_Guard_Bare_Plugin_Fixture::class );
		$plugin->report_half_declared_location_layer( null );
		$plugin->report_half_declared_location_layer( $this->handler_with( [ Field::create( 'order_note' )->set_type( 'text' ) ] ) );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_opt_in_without_a_checkout_handler_reports_nothing(): void {
		Functions\expect( '_doing_it_wrong' )->never();

		$this->plugin( Location_Guard_Opted_In_Plugin_Fixture::class )->report_half_declared_location_layer( null );

		$this->addToAssertionCount( 1 );
	}
}
