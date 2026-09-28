<?php
/**
 * Shared fixtures for the order-persistence tests (#964): the global stand-ins and the
 * require chain `Pickup_Handler` / `Checkout_Handler` need, plus inert point-source and
 * map-provider doubles. Not a test file (no `Test.php` suffix) — required by the tests.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace {

	if ( ! class_exists( 'WC_Order' ) ) {
		/**
		 * Minimal global \WC_Order stand-in — `get_id()` is what the non-HPOS branch of
		 * {@see \Woodev_Order_Compatibility::update_order_meta()} reads.
		 */
		class WC_Order {
			public function get_id() {
				return 123;
			}
		}
	}

	if ( ! class_exists( 'Woodev_Plugin' ) ) {
		/**
		 * Minimal global \Woodev_Plugin stand-in (only the VERSION constant).
		 */
		class Woodev_Plugin {
			const VERSION = '2.0.2-test';
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Order {

	use Woodev\Framework\Shipping\Map\Map_Provider;
	use Woodev\Framework\Shipping\Pickup\Pickup_Point;
	use Woodev\Framework\Shipping\Pickup\Point_Query;
	use Woodev\Framework\Shipping\Pickup\Point_Source;

	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/exceptions/class-shipping-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/interface-map-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-point-query.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-constraint-checker.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-point-source.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-selection-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-selection.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-shipping-order-handler.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/class-woocommerce-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-adapter.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-resolution-cache.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-service.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-policy.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-map-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-settings-tab.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';

	if ( ! class_exists( '\\WP_REST_Controller' ) ) {
		require_once dirname( __DIR__, 4 ) . '/tests/unit/Shipping/Rest_Api/wp-rest-controller-stub.php';
	}

	require_once dirname( __DIR__, 4 ) . '/woodev/http/trait-rest-rate-limit.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/class-pickup-controller.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-handler.php';

	/**
	 * Point source returning one fixed point for every id.
	 */
	final class Order_Persistence_Test_Source implements Point_Source {

		private Pickup_Point $point;

		public function __construct( Pickup_Point $point ) {
			$this->point = $point;
		}

		public function get_strategy(): string {
			return Point_Source::STRATEGY_BULK;
		}

		public function fetch_points( Point_Query $query ): array {
			return [];
		}

		public function fetch_details( string $point_id ): ?Pickup_Point {
			return $this->point;
		}
	}

	/**
	 * Inert map provider — {@see Pickup_Handler} only ever calls the interface.
	 */
	final class Order_Persistence_Test_Map_Provider implements Map_Provider {

		public function get_id(): string {
			return 'yandex';
		}

		public function get_label(): string {
			return 'yandex';
		}

		public function get_script_handle(): string {
			return 'woodev-pickup-map-provider-yandex';
		}

		public function get_settings_fields(): array {
			return [];
		}

		public function owns_chrome(): bool {
			return false;
		}

		public function get_js_config( array $context ): array {
			return [];
		}
	}
}
