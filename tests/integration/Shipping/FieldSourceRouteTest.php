<?php
/**
 * Integration: checkout field-source REST route (woodev/v1/shipping/checkout).
 *
 * Proves the Field_Source_Controller registers under woodev/v1 in a real REST
 * server and that a GUEST (unauthenticated) dispatch returns the expected
 * escaped `{ options: [...] }` payload — the endpoint is intentionally public so
 * guest checkout works (spec §11, Codex hardening HIGH #1). Also proves an
 * unknown field id yields an empty option list and a mismatched plugin_id 404s.
 *
 * Modelled on SettingsPageRestTest (rest_get_server / WP_REST_Request / dispatch).
 *
 * @package Woodev\Tests\Integration\Shipping
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Framework\Shipping\Rest_Api\Field_Source_Controller;
use Woodev\Tests\Integration\TestCase;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 2 ) . '/woodev/shipping-method/rest-api/class-field-source-controller.php';

class FieldSourceRouteTest extends TestCase {

	/**
	 * Registers a controller (field `billing_city` with a known suggest source)
	 * on rest_api_init and rebuilds the REST server so its route registers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$fields = Checkout_Fields::from_array(
			[
				Field::create( 'billing_city' )
					->set_source(
						static fn( $ctx ) => [ [ 'value' => 'msk', 'label' => 'Москва' ] ],
						'suggest'
					)
					->to_array(),
			]
		);

		$controller = new Field_Source_Controller( $fields, 'carrier' );

		add_action(
			'rest_api_init',
			static function () use ( $controller ) {
				$controller->register_routes();
			}
		);

		// Guest by default — the endpoint must work with no logged-in user.
		wp_set_current_user( 0 );

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	public function test_route_is_registered(): void {
		$routes = rest_get_server()->get_routes( 'woodev/v1' );

		// The plugin id is a literal path segment (not a capture group) so each plugin
		// gets a distinct route — see Field_Source_Controller::register_routes() (Codex P2).
		$this->assertArrayHasKey(
			'/woodev/v1/shipping/checkout/carrier/field-source/(?P<field_id>[\w-]+)',
			$routes
		);
	}

	public function test_guest_dispatch_returns_options(): void {
		$request = new WP_REST_Request(
			'GET',
			'/woodev/v1/shipping/checkout/carrier/field-source/billing_city'
		);
		$request->set_param( 'q', 'Мос' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[ 'options' => [ [ 'value' => 'msk', 'label' => 'Москва' ] ] ],
			$response->get_data()
		);
	}

	public function test_unknown_field_returns_empty_options(): void {
		$request = new WP_REST_Request(
			'GET',
			'/woodev/v1/shipping/checkout/carrier/field-source/nope'
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'options' => [] ], $response->get_data() );
	}

	public function test_mismatched_plugin_id_returns_404(): void {
		$request = new WP_REST_Request(
			'GET',
			'/woodev/v1/shipping/checkout/other/field-source/billing_city'
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}
}
