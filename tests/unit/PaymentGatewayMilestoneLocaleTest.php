<?php
/** Payment/refund hooks must never persist translated text. */
namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

if ( ! class_exists( '\WC_Payment_Gateway', false ) ) {
	class Milestone_WC_Gateway_Stub {}
	class_alias( Milestone_WC_Gateway_Stub::class, 'WC_Payment_Gateway' );
}
require_once dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway.php';

/** Runs the real milestone hooks without the WooCommerce constructor. */
class Milestone_Test_Gateway extends \Woodev_Payment_Gateway {
	private $test_plugin;
	public function __construct( $plugin ) { $this->test_plugin = $plugin; $this->add_milestone_hooks(); }
	public function get_method_form_fields() { return []; }
	public function get_id() { return 'milestone'; }
	public function get_plugin() { return $this->test_plugin; }
}

/** Gateway producer coverage complements LifecycleMilestoneTest's renderer coverage. */
class PaymentGatewayMilestoneLocaleTest extends TestCase {
	/** Both event callbacks store stable keys even when a storefront locale is active. */
	public function test_success_events_store_keys_without_translating(): void {
		$callbacks = [];
		Functions\when( 'add_action' )->alias( static function ( string $hook, callable $callback ) use ( &$callbacks ): void { $callbacks[ $hook ] = $callback; } );
		Functions\expect( '__' )->never();
		$lifecycle = \Mockery::mock( \Woodev_Lifecycle::class );
		$lifecycle->shouldReceive( 'trigger_milestone' )->once()->with( 'payment-processed', 'payment-processed' );
		$lifecycle->shouldReceive( 'trigger_milestone' )->once()->with( 'refund-processed', 'refund-processed' );
		$plugin = \Mockery::mock();
		$plugin->shouldReceive( 'get_lifecycle_handler' )->andReturn( $lifecycle );
		new Milestone_Test_Gateway( $plugin );
		$callbacks['wc_payment_gateway_milestone_payment_processed']();
		$callbacks['wc_payment_gateway_milestone_refund_processed']();
		$this->assertCount( 2, $callbacks );
	}
}
