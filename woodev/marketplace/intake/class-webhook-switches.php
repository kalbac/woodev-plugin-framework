<?php
/**
 * The merchant's on/off switches of the notification types.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the switches the notifications settings screen saves, one `wp_options` row per {@see Webhook_Type}
 * (`woodev_{plugin id}_webhook_{type id}`), so the intake can drop a notification the merchant switched off.
 *
 * A type nobody declared is never reported as off: the decoder already ignores types it does not know, and an adapter that
 * declares no types has no switches at all.
 *
 * @since 2.1.2
 */
final class Webhook_Switches {
	/**
	 * Prefix of a switch's setting id; the type id follows.
	 */
	public const SETTING_PREFIX = 'webhook_';

	/**
	 * Declared types by id.
	 *
	 * @var array<string,Webhook_Type>
	 */
	private $types = [];

	/**
	 * Prefix of the plugin's option names (`woodev_{plugin id}_`).
	 *
	 * @var string
	 */
	private $option_prefix;

	/**
	 * Option reader (`function ( string $option, $default = null ): mixed`).
	 *
	 * @var callable
	 */
	private $get_option;

	/**
	 * Creates the reader.
	 *
	 * @since 2.1.2
	 *
	 * @param Webhook_Type[] $types         Types the adapter declares.
	 * @param string         $option_prefix `Uninstaller::option_prefix( $plugin_id )`.
	 * @param callable|null  $get_option    Optional reader (`get_option` by default).
	 */
	public function __construct( array $types, string $option_prefix, ?callable $get_option = null ) {
		foreach ( $types as $type ) {
			$this->types[ $type->id() ] = $type;
		}

		$this->option_prefix = $option_prefix;
		$this->get_option    = $get_option ?? 'get_option';
	}

	/**
	 * Builds the setting id of a type's switch.
	 *
	 * @since 2.1.2
	 *
	 * @param string $type_id Type id.
	 * @return string
	 */
	public static function setting_id( string $type_id ): string {
		return self::SETTING_PREFIX . $type_id;
	}

	/**
	 * Tells whether notifications of a type are accepted.
	 *
	 * @since 2.1.2
	 *
	 * @param string $type_id Event type the decoder gave the notification.
	 * @return bool
	 */
	public function is_enabled( string $type_id ): bool {
		if ( ! isset( $this->types[ $type_id ] ) ) {
			return true;
		}

		$value = call_user_func( $this->get_option, $this->option_prefix . self::setting_id( $type_id ), null );

		if ( null === $value ) {
			return $this->types[ $type_id ]->default_enabled();
		}

		return 'yes' === $value || true === $value || '1' === $value || 1 === $value;
	}
}
