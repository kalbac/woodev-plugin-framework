<?php
/**
 * Tests for the realistic shipping fixture's pickup-selection scope (SP-11 C-2b, #1089).
 *
 * The scope is what lets the Store API transport — and so the Checkout block's pickup button —
 * claim this carrier's pickup rate: `Pickup_Handler::owns_store_api_rate()` is true exactly when
 * the scope names a pickup type for the rate's method. The fixture was built WITHOUT a scope until
 * #1089's browser acceptance, where the rig's second carrier showed no button at all.
 *
 * Loaded on its own, like `RealisticPointSourceTest` and for the same reason: the fixture plugin's
 * full load path is a process-wide one-shot.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Woodev\Framework\Shipping\Pickup\Provider_Selection_Scope;
use Woodev\Framework\Shipping\Pickup\Selection_Scope;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-selection-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-provider-selection-scope.php';
require_once dirname( __DIR__, 4 ) . '/tests/_fixtures/woodev-realistic-shipping-plugin/includes/class-realistic-selection-scope.php';
require_once dirname( __DIR__, 4 ) . '/tests/_fixtures/woodev-realistic-shipping-plugin/includes/class-realistic-shipping-plugin.php';

/**
 * Class RealisticSelectionScopeTest
 */
final class RealisticSelectionScopeTest extends TestCase {

	/**
	 * The pickup method carries a pickup type; the same plugin's courier method does not.
	 */
	public function test_it_claims_the_pickup_method_and_nothing_else(): void {

		$scope = new \Woodev_Realistic_Selection_Scope();

		$this->assertInstanceOf( Provider_Selection_Scope::class, $scope );
		$this->assertSame( Selection_Scope::TYPE_ANY, $scope->type_for_method( 'woodev_realistic_pickup_shipping' ) );
		$this->assertNull( $scope->type_for_method( 'woodev_realistic_shipping' ) );
		$this->assertNull( $scope->type_for_method( 'woodev_test_shipping' ) );
		$this->assertNull( $scope->type_for_method( '' ) );
	}

	/**
	 * Two carriers on one checkout must not answer for one another's remembered point.
	 */
	public function test_its_session_key_is_its_own(): void {

		$scope = new \Woodev_Realistic_Selection_Scope();

		$this->assertSame( 'woodev_realistic_pickup_selection', $scope->session_key() );
		$this->assertNotSame( 'woodev_test_provider_pickup_selection', $scope->session_key() );
	}

	/**
	 * The scope is only worth anything once the handler is built with it: argument 13 was `null`.
	 */
	public function test_the_fixture_plugin_builds_its_pickup_handler_with_the_scope(): void {

		$method     = new \ReflectionMethod( \Woodev_Realistic_Shipping_Plugin::class, 'init_realistic_pickup' );
		$file       = (string) $method->getFileName();
		$lines      = array_slice( (array) file( $file ), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 );
		$definition = implode( '', $lines );

		$this->assertStringContainsString( 'new \Woodev_Realistic_Selection_Scope()', $definition );
	}
}
