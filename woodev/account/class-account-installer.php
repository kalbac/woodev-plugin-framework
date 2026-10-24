<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Account_Installer' ) ) :

	/**
	 * Installs a purchased plugin from the connected woodev.ru account.
	 *
	 * Asks the connector (over the existing signed transport) for an EDD download
	 * URL for an owned download, validates that the returned URL points at the
	 * trusted store origin (SSRF guard — the issuer reply is never followed
	 * blindly), then installs it with WordPress's own Plugin_Upgrader. The plugin
	 * is installed inactive. When the active framework refused an older Woodev plugin,
	 * its existing folder is overwritten in place so the stored activation remains intact.
	 *
	 * @since 2.0.2
	 */
	class Woodev_Account_Installer {

		/**
		 * Installs the plugin for an owned download id.
		 *
		 * @since 2.0.2
		 *
		 * @param int $download_id The store download (product) id.
		 *
		 * @return true|WP_Error True on a completed install, else a WP_Error.
		 */
		public function install( int $download_id ) {

			if ( $download_id <= 0 ) {
				return new WP_Error(
					'woodev_install_invalid',
					__( 'Некорректный идентификатор плагина.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			$connection = new Woodev_Account_Connection();

			if ( ! $connection->is_connected() ) {
				return new WP_Error(
					'woodev_install_not_connected',
					__( 'Аккаунт woodev.ru не подключён.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			// Generous timeout: the store may be slow generating the package URL
			// under load (the catalog fetch hit the same class of timeout, s26).
			$response = $connection->request( 'GET', '/download/' . $download_id, [], 30 );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$package = isset( $response['package'] ) ? (string) $response['package'] : '';

			// SSRF guard: the package MUST live on the trusted store origin. The
			// issuer reply is authenticated (HMAC) but still validated here so a
			// compromised/buggy store cannot point the upgrader at an internal URL.
			if ( '' === $package || ! self::is_trusted_package_url( $package ) ) {
				return new WP_Error(
					'woodev_install_bad_package',
					__( 'Не удалось получить корректную ссылку на плагин.', 'woodev-plugin-framework' ),
					[ 'status' => 502 ]
				);
			}

			$overwrite = self::is_framework_incompatible_plugin(
				$download_id,
				$this->get_incompatible_framework_plugins(),
				$this->get_mixed_fleet_incompatible_plugins()
			);

			return $this->run_upgrader( $package, $overwrite, $download_id );
		}

		/**
		 * Whether an owned plugin is installed but refused by the selected framework.
		 *
		 * @since 2.0.12
		 *
		 * @param int              $download_id Store download id.
		 * @param array<int,mixed> $plugins     Resolver records refused by framework compatibility.
		 * @param array<int,mixed> $legacy      Legacy v1 tombstone records.
		 *
		 * @return bool
		 */
		public static function is_framework_incompatible_plugin( int $download_id, array $plugins, array $legacy = [] ): bool {
			$ids = array_merge(
				Woodev_Installed_Plugins::download_ids_from_records( $plugins ),
				Woodev_Installed_Plugins::download_ids_from_mixed_fleet_records( $legacy )
			);

			return in_array( $download_id, $ids, true );
		}

		/**
		 * Gets framework-incompatible plugin records from the bootstrap.
		 *
		 * @since 2.0.12
		 *
		 * @return array<int,array<string,mixed>>
		 */
		protected function get_incompatible_framework_plugins(): array {
			return Woodev_Plugin_Bootstrap::instance()->get_incompatible_framework_plugins();
		}

		/**
		 * Gets legacy v1 plugin records quarantined by the mixed-fleet tombstone.
		 *
		 * @since 2.0.12
		 *
		 * @return array<int,array{plugin_name:string,path:string}>
		 */
		protected function get_mixed_fleet_incompatible_plugins(): array {
			return Woodev_Plugin_Bootstrap::instance()->get_mixed_fleet_incompatible_plugins();
		}

		/**
		 * Whether a package URL is on the trusted store origin.
		 *
		 * Accepts only http(s) URLs whose host matches the configured store host
		 * (`woodev_account_api_url`); rejects other schemes, embedded credentials,
		 * and foreign hosts. The allow-list is filterable for the local e2e rig,
		 * where the issuer's package host differs from the server-to-server base.
		 * A non-string, empty, or malformed `woodev_account_api_url` override is
		 * discarded ({@see Woodev_Helper::filtered_url()}): the configured store
		 * host degrades to the production default, it never disables this check.
		 *
		 * @since 2.0.2
		 *
		 * @param string $url The candidate package URL.
		 *
		 * @return bool
		 */
		public static function is_trusted_package_url( string $url ): bool {

			$parts = wp_parse_url( $url );

			if ( ! is_array( $parts ) ) {
				return false;
			}

			$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
			$host   = strtolower( (string) ( $parts['host'] ?? '' ) );

			// Reject non-http(s), hostless, and credential-embedding URLs outright.
			if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) {
				return false;
			}

			if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				return false;
			}

			$store        = wp_parse_url( Woodev_Helper::filtered_url( 'woodev_account_api_url', 'https://woodev.ru' ) );
			$store_host   = is_array( $store ) ? strtolower( (string) ( $store['host'] ?? '' ) ) : '';
			$store_scheme = is_array( $store ) ? strtolower( (string) ( $store['scheme'] ?? 'https' ) ) : 'https';

			// Pin the transport: when the store is reached over https, the package
			// MUST also be https — never let a reply downgrade the install fetch to
			// cleartext (network tampering of the package). A plain-http store base
			// (the local rig) is the only case where http is accepted.
			if ( 'https' === $store_scheme && 'https' !== $scheme ) {
				return false;
			}

			/**
			 * Filters the hosts a plugin package may be downloaded from for install.
			 *
			 * Defaults to the store host. Add the issuer host on the local rig
			 * (e.g. `localhost`) for end-to-end testing.
			 *
			 * @since 2.0.2
			 *
			 * @param array<int,string> $hosts Allowed lower-cased hosts.
			 */
			$allowed = (array) apply_filters( 'woodev_account_install_allowed_hosts', [ $store_host ] );
			$allowed = array_filter( array_map( 'strtolower', array_map( 'strval', $allowed ) ) );

			return in_array( $host, $allowed, true );
		}

		/**
		 * Runs WordPress's Plugin_Upgrader against the package URL.
		 *
		 * Isolated as a protected seam so the install orchestration can be unit
		 * tested without a live upgrader.
		 *
		 * @since 2.0.2
		 *
		 * @param string $package     The trusted package URL.
		 * @param bool   $overwrite   Whether this replaces an installed but refused plugin.
		 * @param int    $download_id Store download id.
		 *
		 * @return true|WP_Error
		 */
		protected function run_upgrader( string $package, bool $overwrite = false, int $download_id = 0 ) {

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			if ( ! class_exists( 'Plugin_Upgrader' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
				return new WP_Error(
					'woodev_install_upgrader_missing',
					__( 'Механизм установки недоступен.', 'woodev-plugin-framework' ),
					[ 'status' => 500 ]
				);
			}

			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );

			$result = $upgrader->install( $package, [ 'overwrite_package' => $overwrite ] );

			if ( true !== $result ) {
				$error = $this->get_upgrader_error( $upgrader, $skin, is_wp_error( $result ) ? $result : null );
				$this->log_install_error( $package, $error );

				return $this->create_install_error( $download_id, $error );
			}

			return true;
		}

		/**
		 * Creates the public failure error, adding details only in the plugin debug mode.
		 *
		 * @since 2.0.12
		 *
		 * @param int    $download_id Store download id.
		 * @param string $error       Sanitized upgrader detail.
		 * @return WP_Error
		 */
		protected function create_install_error( int $download_id, string $error ): WP_Error {
			$message = __( 'Не удалось установить плагин.', 'woodev-plugin-framework' );
			if ( '' !== $error && $this->is_debug_enabled_for_download( $download_id ) ) {
				$message .= ' ' . sprintf( __( 'Причина: %s', 'woodev-plugin-framework' ), $error );
			}

			return new WP_Error( 'woodev_install_failed', $message, [ 'status' => 500 ] );
		}

		/**
		 * Gets the best available failure detail from WordPress's upgrader and skin.
		 *
		 * WordPress core's Automatic_Upgrader_Skin exposes get_upgrade_messages();
		 * WP_Ajax_Upgrader_Skin additionally exposes get_errors() and
		 * get_error_messages(). The installer uses the automatic skin, whose error
		 * output is captured as upgrade messages.
		 *
		 * @since 2.0.12
		 *
		 * @param Plugin_Upgrader         $upgrader The failed upgrader.
		 * @param Automatic_Upgrader_Skin $skin     The upgrader skin.
		 * @param WP_Error|null           $result   Error returned by Plugin_Upgrader::install().
		 * @return string
		 */
		protected function get_upgrader_error( $upgrader, $skin, ?WP_Error $result = null ): string {
			$result = $result ?? ( is_wp_error( $upgrader->result ) ? $upgrader->result : null );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
				$data  = $result->get_error_data();

				if ( is_string( $data ) && '' !== $data ) {
					$error .= ' ' . $data;
				}
			} elseif ( method_exists( $skin, 'get_error_messages' ) ) {
				$error = (string) $skin->get_error_messages();
			} elseif ( method_exists( $skin, 'get_upgrade_messages' ) ) {
				$messages = (array) $skin->get_upgrade_messages();
				$error    = (string) end( $messages );
			} else {
				$error = '';
			}

			return self::sanitize_install_error( $error );
		}

		/**
		 * Removes signed query strings and credentials from upgrader diagnostics.
		 *
		 * @since 2.0.12
		 *
		 * @param string $error Raw upgrader detail.
		 * @return string
		 */
		public static function sanitize_install_error( string $error ): string {
			$error = wp_strip_all_tags( html_entity_decode( $error, ENT_QUOTES, 'UTF-8' ) );
			$error = preg_replace_callback(
				'~https?://[^\s<>"\']+~i',
				static function ( array $matches ): string {
					$url   = preg_replace( '/[.,);…]+$/u', '', $matches[0] );
					$parts = wp_parse_url( $url );

					if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
						return '[ссылка скрыта]';
					}

					$host = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
					return ( $parts['scheme'] ?? 'https' ) . '://' . $host . ( $parts['path'] ?? '' );
				},
				$error
			);
			$error = preg_replace( '/([?&](?:token|signature|sig|eddfile|expires|key)=)[^&\s]+/i', '$1[скрыто]', $error );
			$error = trim( preg_replace( '/\s+/', ' ', $error ) );

			return '' !== $error ? mb_substr( $error, 0, 500 ) : '';
		}

		/**
		 * Writes the sanitized install failure to the WooCommerce plugin log.
		 *
		 * @since 2.0.12
		 *
		 * @param string $package Package URL used for the install.
		 * @param string $error   Sanitized upgrader error.
		 * @return void
		 */
		protected function log_install_error( string $package, string $error ): void {
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return;
			}

			$logger = wc_get_logger();
			$logger->error(
				sprintf( 'Plugin install failed for %s: %s', self::sanitize_install_error( $package ), $error ),
				[ 'source' => 'woodev-account-installer' ]
			);
		}

		/**
		 * Whether diagnostics may be included in the install response.
		 *
		 * @since 2.0.12
		 *
		 * @param int $download_id Store download id.
		 * @return bool
		 */
		protected function is_debug_enabled_for_download( int $download_id ): bool {
			if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) || $download_id <= 0 ) {
				return false;
			}

			$bootstrap = Woodev_Plugin_Bootstrap::instance();
			foreach ( $bootstrap->get_active_plugin_instances() as $plugin ) {
				if ( ! method_exists( $plugin, 'get_download_id' ) || ! method_exists( $plugin, 'is_debug_enabled' ) ) {
					continue;
				}

				if ( $plugin->get_download_id() === $download_id ) {
					return (bool) $plugin->is_debug_enabled();
				}
			}

			// A framework-incompatible plugin is not instantiated, but its persisted
			// setting still governs whether its install diagnostic may be returned.
			foreach ( $bootstrap->get_incompatible_framework_plugins() as $plugin ) {
				$definition = $plugin['definition'] ?? null;
				if ( ! $definition instanceof Woodev\Framework\Framework_Plugin_Loader_Definition || $definition->get_download_id() !== $download_id ) {
					continue;
				}

				$option_key = 'woodev_' . str_replace( '-', '_', $definition->get_plugin_id() ) . '_advanced_enable_debug';

				return 'yes' === get_option( $option_key, 'no' );
			}

			return false;
		}
	}

endif;
