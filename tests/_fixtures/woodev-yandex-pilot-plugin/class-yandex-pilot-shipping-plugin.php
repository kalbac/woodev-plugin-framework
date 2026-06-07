<?php
/**
 * Yandex-shaped pilot fixture plugin class.
 *
 * @package Woodev_Yandex_Pilot_Fixture
 */

defined( 'ABSPATH' ) || exit;

/**
 * Concrete WooCommerce shipping plugin fixture modeling the yandex SHAPE for
 * Platform v2 runtime validation. It exists only to load through the new path and
 * to assert the yandex installed-site contract strings.
 *
 * The framework id is `yandex_delivery`, so the shipping REST namespace resolves
 * to the installed-site contract `yandex-delivery` ({@see get_id_dasherized()}).
 */
final class Woodev_Yandex_Pilot_Shipping_Plugin extends \Woodev\Framework\Shipping\Shipping_Plugin {

	/** Installed-site settings option key preserved by the eventual rewrite. */
	const SETTINGS_OPTION_NAME = 'woocommerce_yandex_delivery_settings';

	/** @var Woodev_Yandex_Pilot_Shipping_Plugin|null Singleton instance. */
	protected static $instance;

	/**
	 * Initializes the fixture through the real shipping plugin base constructor.
	 */
	public function __construct() {
		parent::__construct(
			'yandex_delivery',
			WOODEV_YANDEX_PILOT_VERSION,
			[
				'text_domain'        => 'woodev-yandex-pilot',
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
	 * No-op setup wizard handler for isolated fixture construction.
	 *
	 * @return void
	 */
	protected function init_setup_wizard_handler() {}

	/**
	 * Gets the plugin file.
	 *
	 * @return string
	 */
	protected function get_file(): string {
		return WOODEV_YANDEX_PILOT_FILE;
	}

	/**
	 * Gets the plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return 'Woodev Yandex Pilot Fixture';
	}

	/**
	 * Gets the fixture download ID.
	 *
	 * Models the production yandex EDD download ID shape.
	 *
	 * @return int
	 */
	public function get_download_id(): int {
		return 821;
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
		return self::SETTINGS_OPTION_NAME;
	}

	/**
	 * Gets the fixture shipping method classes.
	 *
	 * The two keys are the installed-site shipping method ID contracts; the yandex
	 * plugin exposes TWO method ids (express + other-day).
	 *
	 * @return array<string,string>
	 */
	protected function get_shipping_method_classes(): array {
		return [
			'yandex_delivery_express'   => 'Woodev_Yandex_Pilot_Express_Method',
			'yandex_delivery_other_day' => 'Woodev_Yandex_Pilot_Other_Day_Method',
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
 * Gets the yandex-shaped pilot fixture plugin instance.
 *
 * @return Woodev_Yandex_Pilot_Shipping_Plugin
 */
function woodev_yandex_pilot_plugin(): Woodev_Yandex_Pilot_Shipping_Plugin {
	return Woodev_Yandex_Pilot_Shipping_Plugin::instance();
}
