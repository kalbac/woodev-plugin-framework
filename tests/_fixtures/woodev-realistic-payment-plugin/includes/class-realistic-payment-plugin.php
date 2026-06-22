<?php
/**
 * Realistic payment fixture plugin class.
 *
 * @package Woodev_Realistic_Payment_Fixture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Concrete WooCommerce payment gateway plugin fixture for Platform v2 runtime validation.
 */
final class Woodev_Realistic_Payment_Plugin extends \Woodev_Payment_Gateway_Plugin {

	/** Plugin ID. */
	const PLUGIN_ID = 'woodev-realistic-payment';

	/** Gateway ID. */
	const GATEWAY_ID = 'woodev_realistic';

	/** Gateway class name. */
	const GATEWAY_CLASS_NAME = 'Woodev_Realistic_Gateway';

	/** @var Woodev_Realistic_Payment_Plugin|null Singleton instance. */
	protected static $instance;

	/**
	 * Initializes the fixture through the real payment gateway plugin base constructor.
	 */
	public function __construct() {
		parent::__construct(
			self::PLUGIN_ID,
			WOODEV_REALISTIC_PAYMENT_VERSION,
			[
				'text_domain'        => 'woodev-realistic-payment',
				'supported_features' => [
					'hpos' => true,
				],
				'gateways'           => [
					self::GATEWAY_ID => self::GATEWAY_CLASS_NAME,
				],
				'currencies'         => [ 'RUB', 'USD', 'EUR' ],
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
		return WOODEV_REALISTIC_PAYMENT_FILE;
	}

	/**
	 * Gets the plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return 'Woodev Realistic Payment Fixture';
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
	 * Gets the registered gateway class names for test assertions.
	 *
	 * @return array<int,string>
	 */
	public function get_fixture_gateway_class_names(): array {
		return $this->get_gateway_class_names();
	}
}

/**
 * Gets the realistic payment fixture plugin instance.
 *
 * @return Woodev_Realistic_Payment_Plugin
 */
function woodev_realistic_payment_plugin(): Woodev_Realistic_Payment_Plugin {
	return Woodev_Realistic_Payment_Plugin::instance();
}
