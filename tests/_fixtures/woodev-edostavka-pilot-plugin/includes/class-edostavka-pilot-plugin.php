<?php
/**
 * Edostavka-shaped pilot fixture plugin class.
 *
 * @package Woodev_Edostavka_Pilot_Fixture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Concrete WooCommerce shipping plugin fixture modeling the edostavka SHAPE for
 * Platform v2 runtime validation. It exists only to load through the new path and
 * to assert two installed-site contract strings.
 */
final class Woodev_Edostavka_Pilot_Plugin extends \Woodev\Framework\Shipping\Shipping_Plugin {

	/** @var Woodev_Edostavka_Pilot_Plugin|null Singleton instance. */
	protected static $instance;

	/**
	 * Initializes the fixture through the real shipping plugin base constructor.
	 */
	public function __construct() {
		parent::__construct(
			'woodev-edostavka-pilot',
			WOODEV_EDOSTAVKA_PILOT_VERSION,
			[
				'text_domain'        => 'woodev-edostavka-pilot',
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
		return WOODEV_EDOSTAVKA_PILOT_FILE;
	}

	/**
	 * Gets the plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return 'Woodev Edostavka Pilot Fixture';
	}

	/**
	 * Gets the fixture download ID.
	 *
	 * Models the production edostavka EDD download ID shape.
	 *
	 * @return int
	 */
	public function get_download_id(): int {
		return 216;
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
	 * Gets the installed-site settings option name for test assertions.
	 *
	 * @return string
	 */
	public function get_fixture_settings_option_name(): string {
		return Woodev_Edostavka_Pilot_Integration::get_settings_option_name();
	}

	/**
	 * Gets the fixture shipping method classes.
	 *
	 * The 'edostavka' key is the installed-site shipping method ID contract.
	 *
	 * @return array<string,string>
	 */
	protected function get_shipping_method_classes(): array {
		return [
			'edostavka' => 'Woodev_Edostavka_Pilot_Shipping_Method',
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
 * Gets the edostavka-shaped pilot fixture plugin instance.
 *
 * @return Woodev_Edostavka_Pilot_Plugin
 */
function woodev_edostavka_pilot_plugin(): Woodev_Edostavka_Pilot_Plugin {
	return Woodev_Edostavka_Pilot_Plugin::instance();
}
