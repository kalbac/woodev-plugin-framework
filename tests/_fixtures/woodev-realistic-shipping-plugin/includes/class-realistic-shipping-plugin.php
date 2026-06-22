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
	 * @return null|\Woodev\Framework\Shipping\Shipping_API
	 */
	public function get_api(): ?\Woodev\Framework\Shipping\Shipping_API {
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
