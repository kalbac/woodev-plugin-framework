<?php
/**
 * Woodev Test Plugin Class
 *
 * Минимальная реализация Woodev_Plugin для тестирования фреймворка.
 *
 * @package Woodev_Test_Plugin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Woodev_Test_Plugin
 */
class Woodev_Test_Plugin extends Woodev_Plugin {

	/** @var string уникальный идентификатор плагина */
	const PLUGIN_ID = 'woodev-test-plugin';

	/** @var string версия плагина */
	const VERSION = '1.0.0';

	/** @var Woodev_Test_Plugin единственный экземпляр */
	protected static Woodev_Test_Plugin $instance;

	/**
	 * Инициализация плагина.
	 */
	public function __construct() {
		parent::__construct(
			self::PLUGIN_ID,
			self::VERSION,
			[
				'text_domain' => 'woodev-test-plugin',
			]
		);
	}

	/**
	 * Singleton.
	 *
	 * @return Woodev_Test_Plugin
	 */
	public static function instance(): Woodev_Test_Plugin {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Возвращает путь до папки плагина.
	 *
	 * @return string
	 */
	public function get_plugin_path(): string {
		return __DIR__;
	}

	/**
	 * Возвращает URL до папки плагина.
	 *
	 * @return string
	 */
	public function get_plugin_url(): string {
		return plugin_dir_url( __FILE__ );
	}
}
