<?php
/**
 * Realistic shipping fixture plugin class.
 *
 * @package Woodev_Realistic_Shipping_Fixture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Concrete WooCommerce shipping plugin fixture for Platform v2 runtime validation.
 */
final class Woodev_Realistic_Shipping_Plugin extends \Woodev\Framework\Shipping\Shipping_Plugin {

	/** @var Woodev_Realistic_Shipping_Plugin|null Singleton instance. */
	protected static $instance;

	/**
	 * Initializes the fixture through the real shipping plugin base constructor.
	 */
	public function __construct() {
		parent::__construct(
			'woodev-realistic-shipping',
			WOODEV_REALISTIC_SHIPPING_VERSION,
			[
				'text_domain'        => 'woodev-realistic-shipping',
				'supported_features' => [
					'hpos'   => true,
					'blocks' => [
						'cart'     => true,
						'checkout' => true,
					],
				],
			]
		);
	}

	/**
	 * Gets the singleton fixture instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/** @var \Woodev\Framework\Shipping\Pickup\Pickup_Handler|null Pickup handler, built lazily (#734). */
	private $pickup_handler = null;

	/** @var \Woodev\Framework\Shipping\Checkout\Checkout_Handler|null Checkout handler, built lazily (#734). */
	private $checkout_handler_instance = null;

	/**
	 * Builds and registers this fixture's pickup layer (card #734).
	 *
	 * WHY A SECOND CARRIER EXISTS AT ALL. The framework keys a `Pickup_Handler` — and with it
	 * a `Point_Source` and a REST route `/shipping/pickup/{plugin_id}/points` — to a PLUGIN,
	 * never to a shipping method. The rig used to hang both of its pickup methods off one
	 * fixture plugin, so they could not serve different points, and with the rig's standard
	 * `WOODEV_TEST_PICKUP_LIVE_YANDEX` the static data was unreachable through the UI at all.
	 * This fixture is the SECOND carrier: its own source, its own route, its own points —
	 * exactly the arrangement a real shop has with several carrier plugins installed, which
	 * the rig previously could not reproduce in any way.
	 *
	 * Called from `init_shipping_pickup()` rather than the constructor so it runs after the
	 * base class has finished wiring, and guarded so the fixture stays loadable in the unit
	 * suite, where the pickup classes are not necessarily included.
	 *
	 * @return void
	 */
	private function init_realistic_pickup(): void {

		if ( null !== $this->pickup_handler ) {
			return;
		}

		if ( ! class_exists( '\Woodev\Framework\Shipping\Pickup\Pickup_Handler' )
			|| ! class_exists( '\Woodev\Framework\Shipping\Map\Yandex_Map_Provider' )
			|| ! class_exists( 'Woodev_Realistic_Point_Source' ) ) {
			return;
		}

		// A fixture key, never a real one: under PHPUnit no ymaps script is ever fetched, and a
		// real carrier plugin supplies its own key from the Yandex developer console.
		$map_provider = new \Woodev\Framework\Shipping\Map\Yandex_Map_Provider( 'FIXTURE-FAKE-YANDEX-KEY' );

		// Centred on Moscow, matching the majority of this fixture's own points, so the map
		// opens on its data rather than on the whole world.
		$this->pickup_handler = new \Woodev\Framework\Shipping\Pickup\Pickup_Handler(
			'woodev-realistic-shipping',
			'carrier_pickup_point',
			new \Woodev_Realistic_Point_Source(),
			$map_provider,
			[ 'center' => [ 55.76, 37.64 ], 'zoom' => 12 ]
		);

		$this->pickup_handler->register();
	}

	/**
	 * Returns this fixture's pickup handler — used by tests and by the rig.
	 *
	 * @return \Woodev\Framework\Shipping\Pickup\Pickup_Handler|null
	 */
	public function get_pickup_handler(): ?\Woodev\Framework\Shipping\Pickup\Pickup_Handler {

		$this->init_realistic_pickup();

		return $this->pickup_handler;
	}

	/**
	 * The checkout backbone for this carrier — one field, the pickup slot (card #734).
	 *
	 * The base class registers whatever this returns (see `Shipping_Plugin`), and without it
	 * there is no `carrier_pickup_point` field and therefore no pickup button on the checkout
	 * for this carrier's method. Only the SECOND rig method id is listed, so choosing the
	 * first carrier's method never shows this one's button.
	 *
	 * @return \Woodev\Framework\Shipping\Checkout\Checkout_Handler|null
	 */
	public function get_checkout_handler(): ?\Woodev\Framework\Shipping\Checkout\Checkout_Handler {

		if ( null !== $this->checkout_handler_instance ) {
			return $this->checkout_handler_instance;
		}

		if ( ! class_exists( '\Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field' )
			|| ! class_exists( '\Woodev\Framework\Shipping\Checkout\Checkout_Fields' ) ) {
			return null;
		}

		$this->init_realistic_pickup();

		$fields = \Woodev\Framework\Shipping\Checkout\Checkout_Fields::from_array(
			[
				// No label, deliberately: the visible control is the button and the modal, and a
				// non-empty label would render a stray form row for a hidden field.
				\Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field::create(
					'carrier_pickup_point',
					[ 'woodev_realistic_pickup_shipping' ]
				),
			]
		);

		$this->checkout_handler_instance = new \Woodev\Framework\Shipping\Checkout\Checkout_Handler(
			$fields,
			'woodev_realistic_shipping'
		);

		$this->checkout_handler_instance->set_requires_pickup_methods( [ 'woodev_realistic_pickup_shipping' ] );

		return $this->checkout_handler_instance;
	}


	/**
	 * No-op dependency handler for isolated fixture construction.
	 *
	 * @param array<string,mixed> $dependencies Dependency configuration.
	 * @return void
	 */
	protected function init_dependencies( $dependencies ) {}

	/**
	 * No-op admin message handler for isolated fixture construction.
	 *
	 * @return void
	 */
	protected function init_admin_message_handler() {}

	/**
	 * No-op admin notice handler for isolated fixture construction.
	 *
	 * @return void
	 */
	protected function init_admin_notice_handler() {}

	/**
	 * No-op license handler for isolated fixture construction.
	 *
	 * @return void
	 */
	protected function init_license_handler() {}

	/**
	 * No-op hook deprecator for isolated fixture construction.
	 *
	 * @return void
	 */
	protected function init_hook_deprecator() {}

	/**
	 * No-op lifecycle handler for isolated fixture construction.
	 *
	 * @return void
	 */
	protected function init_lifecycle_handler() {}

	/**
	 * No-op REST API handler for isolated fixture construction.
	 *
	 * @return void
	 */
	protected function init_rest_api_handler() {}

	/**
	 * No-op blocks handler for isolated fixture construction.
	 *
	 * @return void
	 */
	protected function init_blocks_handler(): void {}

	/**
	 * Gets the plugin file.
	 *
	 * @return string
	 */
	protected function get_file(): string {
		return WOODEV_REALISTIC_SHIPPING_FILE;
	}

	/**
	 * Gets the plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return 'Woodev Realistic Shipping Fixture';
	}

	/**
	 * Gets the fixture download ID.
	 *
	 * @return int
	 */
	public function get_download_id(): int {
		return 0;
	}

	/**
	 * Gets the shipping method classes for test assertions.
	 *
	 * @return array<string,string>
	 */
	public function get_fixture_shipping_method_classes(): array {
		return $this->get_shipping_method_classes();
	}

	/**
	 * Gets the fixture shipping method classes.
	 *
	 * @return array<string,string>
	 */
	protected function get_shipping_method_classes(): array {
		return [
			'woodev_realistic_shipping'        => 'Woodev_Realistic_Shipping_Method',
			'woodev_realistic_pickup_shipping' => 'Woodev_Realistic_Pickup_Shipping_Method',
		];
	}

	/**
	 * Gets the carrier API instance.
	 *
	 * @return null|\Woodev\Framework\Shipping\Api\Shipping_API
	 */
	public function get_api(): ?\Woodev\Framework\Shipping\Api\Shipping_API {
		return null;
	}
}

/**
 * Gets the realistic shipping fixture plugin instance.
 *
 * @return Woodev_Realistic_Shipping_Plugin
 */
function woodev_realistic_shipping_plugin(): Woodev_Realistic_Shipping_Plugin {
	return Woodev_Realistic_Shipping_Plugin::instance();
}
