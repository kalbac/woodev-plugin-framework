<?php
/**
 * WP-CLI release check: `wp woodev help-links check`.
 *
 * @package Woodev\Framework\Help
 */

namespace Woodev\Framework\Help;

defined( 'ABSPATH' ) || exit;

/**
 * Checks that no address in a plugin's help-topic table is dead. A plugin runs it before a release:
 *
 *     wp woodev help-links check [--plugin=<id>]
 *
 * Exit code 1 when any address is not answered with a 2xx or 3xx.
 *
 * @since 2.1.11
 */
final class Help_Links_Cli {
	/**
	 * @var array<string,\Woodev_Plugin> plugins that offered their table to the command, by plugin id.
	 */
	private static array $plugins = [];

	/**
	 * @var bool whether the command was registered.
	 */
	private static bool $registered = false;

	/**
	 * Offers a plugin's topic table to the command. A no-op outside WP-CLI, so a web request pays nothing.
	 *
	 * @since 2.1.11
	 *
	 * @param \Woodev_Plugin $plugin The plugin.
	 * @return void
	 */
	public static function register_plugin( $plugin ): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		self::$plugins[ $plugin->get_id() ] = $plugin;

		if ( ! self::$registered ) {
			self::$registered = true;
			\WP_CLI::add_command( 'woodev help-links', self::class );
		}
	}

	/**
	 * Requests every address in the plugin's help-topic table and fails on any that is not 2xx or 3xx.
	 *
	 * ## OPTIONS
	 *
	 * [--plugin=<id>]
	 * : Check only this plugin's table. Default: every plugin that declared one.
	 *
	 * @since 2.1.11
	 *
	 * @param string[]             $args       Positional arguments (the subcommand, `check`).
	 * @param array<string,string> $assoc_args Named arguments.
	 * @return void
	 */
	public function check( array $args, array $assoc_args ): void {
		$only     = isset( $assoc_args['plugin'] ) ? (string) $assoc_args['plugin'] : '';
		$selected = self::select_plugins( self::$plugins, $only );

		// A typo must not turn into «0 addresses checked, every link answers».
		if ( null === $selected ) {
			\WP_CLI::error( sprintf( 'Unknown plugin "%1$s". Plugins with a help table: %2$s.', $only, implode( ', ', array_keys( self::$plugins ) ) ) );
		}

		$checker = new Help_Links_Checker( [ self::class, 'fetch_status' ] );
		$failed  = false;

		foreach ( $selected as $id => $plugin ) {

			$topics = Help_Links::topics_of( $plugin );
			$broken = $checker->find_broken( $topics );

			foreach ( $broken as $topic => $status ) {
				\WP_CLI::warning( sprintf( '%1$s: "%2$s" -> %3$s answers %4$s', $id, $topic, $topics[ $topic ], 0 === $status ? 'nothing' : $status ) );
			}

			$failed = $failed || [] !== $broken;
			\WP_CLI::log( sprintf( '%1$s: %2$d address(es) checked, %3$d broken.', $id, count( $topics ), count( $broken ) ) );
		}

		if ( $failed ) {
			\WP_CLI::error( 'Some help links are broken.' );
		}

		\WP_CLI::success( 'Every help link answers.' );
	}

	/**
	 * The plugins a run covers: every registered one, or the one `--plugin` names.
	 *
	 * @since 2.1.11
	 *
	 * @param array<string,object> $plugins Registered plugins, by id.
	 * @param string               $only    The `--plugin` value; '' for all.
	 * @return array<string,object>|null Null when `$only` names a plugin that is not registered.
	 */
	public static function select_plugins( array $plugins, string $only ): ?array {
		if ( '' === $only ) {
			return $plugins;
		}

		return isset( $plugins[ $only ] ) ? [ $only => $plugins[ $only ] ] : null;
	}

	/**
	 * The HTTP status of an address: a HEAD request, then a GET for a server that does not implement HEAD.
	 *
	 * @since 2.1.11
	 *
	 * @param string $url The address.
	 * @return int The status, 0 when the address was not reached.
	 */
	public static function fetch_status( string $url ): int {
		$status_of = static function ( $response ): int {
			return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		};

		return Help_Links_Checker::status_with_get_fallback(
			static function ( string $address ) use ( $status_of ): int {
				return $status_of( wp_remote_head( $address, [ 'timeout' => 15 ] ) );
			},
			static function ( string $address ) use ( $status_of ): int {
				return $status_of( wp_remote_get( $address, [ 'timeout' => 15 ] ) );
			},
			$url
		);
	}
}
