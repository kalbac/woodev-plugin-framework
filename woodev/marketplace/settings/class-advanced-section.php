<?php
/**
 * «Дополнительно» settings section: debug logging and data deletion on uninstall.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Settings;

use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Data\Uninstaller;
use Woodev\Framework\Settings\Settings_Section;

defined( 'ABSPATH' ) || exit;

/**
 * Contributes the «Дополнительно» card with two merchant toggles:
 * - `advanced_logging` («Логирование») — gates debug API logs, troubleshooting hints, diagnostic cards and the
 *   copyable support report.
 * - `advanced_delete_data_on_uninstall` («Удалять данные при удалении плагина») — gates {@see Uninstaller::run()}: tables, links and
 *   options are removed on plugin deletion only when it is on (off by default).
 *
 * @since 2.1.2
 */
final class Advanced_Section implements Settings_Section_Provider {
	public const LOGGING_ID     = Logging::SETTING_ID;
	public const DELETE_DATA_ID = Uninstaller::DELETE_DATA_SETTING;

	/**
	 * Registers `advanced_logging` and `advanced_delete_data_on_uninstall` on the handler.
	 *
	 * @since 2.1.2
	 *
	 * @param Settings_Handler $handler Settings handler.
	 * @return void
	 */
	public function register_settings( Settings_Handler $handler ): void {
		$handler->register_setting(
			self::LOGGING_ID,
			\Woodev_Setting::TYPE_BOOLEAN,
			[
				'name'    => __( 'Логирование', 'woodev-plugin-framework' ),
				'default' => false,
			]
		);
		$handler->register_control(
			self::LOGGING_ID,
			\Woodev_Control::TYPE_TOGGLE,
			[
				'label'   => __( 'Логирование', 'woodev-plugin-framework' ),
				'tooltip' => __( 'Записывать отладочные события обмена и показывать расширенную диагностику и отчёт для поддержки.', 'woodev-plugin-framework' ),
			]
		);

		$handler->register_setting(
			self::DELETE_DATA_ID,
			\Woodev_Setting::TYPE_BOOLEAN,
			[
				'name'    => __( 'Удалять данные при удалении плагина', 'woodev-plugin-framework' ),
				'default' => false,
			]
		);
		$handler->register_control(
			self::DELETE_DATA_ID,
			\Woodev_Control::TYPE_TOGGLE,
			[
				'label'   => __( 'Удалять данные при удалении плагина', 'woodev-plugin-framework' ),
				'tooltip' => __( 'Если включено, при удалении плагина со страницы «Плагины» будут удалены его таблицы, связки товаров и настройки. Деактивация плагина данные не трогает.', 'woodev-plugin-framework' ),
			]
		);
	}

	/**
	 * Builds the «Дополнительно» section.
	 *
	 * @since 2.1.2
	 *
	 * @return Settings_Section[]
	 */
	public function sections(): array {
		return [
			Settings_Section::create(
				'advanced',
				__( 'Дополнительно', 'woodev-plugin-framework' ),
				[ self::LOGGING_ID, self::DELETE_DATA_ID ]
			),
		];
	}
}
