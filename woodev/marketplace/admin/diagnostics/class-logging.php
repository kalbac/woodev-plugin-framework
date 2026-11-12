<?php
/**
 * Reader for the marketplace plugin's «Логирование» toggle.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Diagnostics;

use Woodev\Framework\Marketplace\Data\Uninstaller;

defined( 'ABSPATH' ) || exit;

/**
 * Tells whether the merchant switched «Логирование» on in the «Дополнительно» settings section.
 *
 * Off by default on production shops: debug logs, troubleshooting hints, diagnostic cards and the copyable support
 * report stay hidden while this toggle is off.
 *
 * @since 2.1.2
 */
final class Logging {
	public const SETTING_ID = 'advanced_logging';

	/**
	 * The sentence a gated endpoint answers with while the switch is off (403).
	 *
	 * @since 2.1.5
	 */
	public const REFUSAL = 'Эта информация показывается только при включённом «Логировании». Включите его на странице «Диагностика» или в настройках плагина, раздел «Дополнительно».';

	/**
	 * Option name in `wp_options`.
	 *
	 * @var string
	 */
	private $option_name;

	/**
	 * Option reader (`function ( string $option, $default = false ): mixed`).
	 *
	 * @var callable
	 */
	private $get_option;

	/**
	 * Creates the reader.
	 *
	 * @since 2.1.2
	 *
	 * @param string        $option_name Full WordPress option name (`woodev_{plugin_id}_advanced_logging`).
	 * @param callable|null $get_option  Optional reader (`get_option` by default).
	 */
	public function __construct( string $option_name, ?callable $get_option = null ) {
		$this->option_name = $option_name;
		$this->get_option  = $get_option ?? 'get_option';
	}

	/**
	 * Builds the option name for a plugin id, matching {@see Uninstaller::option_prefix()}.
	 *
	 * @since 2.1.2
	 *
	 * @param string $plugin_id Plugin id.
	 * @return string
	 */
	public static function option_name_for( string $plugin_id ): string {
		return Uninstaller::option_prefix( $plugin_id ) . self::SETTING_ID;
	}

	/**
	 * Builds a reader for a plugin instance.
	 *
	 * @since 2.1.2
	 *
	 * @param \Woodev_Plugin $plugin     Plugin instance.
	 * @param callable|null  $get_option Optional reader (`get_option` by default).
	 * @return self
	 */
	public static function for_plugin( \Woodev_Plugin $plugin, ?callable $get_option = null ): self {
		return new self( self::option_name_for( $plugin->get_id() ), $get_option );
	}

	/**
	 * Returns the WordPress option name backing this toggle.
	 *
	 * @since 2.1.2
	 *
	 * @return string
	 */
	public function option_name(): string {
		return $this->option_name;
	}

	/**
	 * Tells whether debug logging and diagnostics are enabled.
	 *
	 * @since 2.1.2
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$value = call_user_func( $this->get_option, $this->option_name, 'no' );

		return 'yes' === $value || true === $value || '1' === $value || 1 === $value;
	}

	/**
	 * Returns a troubleshooting hint, or '' while the switch is off, so the hint never reaches a merchant who did not ask.
	 *
	 * @since 2.1.5
	 *
	 * @param string $text Hint text, without the «подсказка:» prefix.
	 * @return string
	 */
	public function hint( string $text ): string {
		return $this->is_enabled() ? 'подсказка: ' . $text : '';
	}

	/**
	 * Stores the switch the way the settings API stores a checkbox (`yes` / `no`).
	 *
	 * @since 2.1.5
	 *
	 * @param bool $enabled New state.
	 * @return void
	 */
	public function set_enabled( bool $enabled ): void {
		update_option( $this->option_name, $enabled ? 'yes' : 'no' );
	}
}
