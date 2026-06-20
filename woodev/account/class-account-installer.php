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
	 * is installed INACTIVE; activation is left to the user on the Plugins page.
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
					array( 'status' => 400 )
				);
			}

			$connection = new Woodev_Account_Connection();

			if ( ! $connection->is_connected() ) {
				return new WP_Error(
					'woodev_install_not_connected',
					__( 'Аккаунт woodev.ru не подключён.', 'woodev-plugin-framework' ),
					array( 'status' => 400 )
				);
			}

			$response = $connection->request( 'GET', '/download/' . $download_id );

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
					array( 'status' => 502 )
				);
			}

			return $this->run_upgrader( $package );
		}

		/**
		 * Whether a package URL is on the trusted store origin.
		 *
		 * Accepts only http(s) URLs whose host matches the configured store host
		 * (`woodev_account_api_url`); rejects other schemes, embedded credentials,
		 * and foreign hosts. The allow-list is filterable for the local e2e rig,
		 * where the issuer's package host differs from the server-to-server base.
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
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
				return false;
			}

			if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				return false;
			}

			$store      = wp_parse_url( untrailingslashit( apply_filters( 'woodev_account_api_url', 'https://woodev.ru' ) ) );
			$store_host = is_array( $store ) ? strtolower( (string) ( $store['host'] ?? '' ) ) : '';

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
			$allowed = (array) apply_filters( 'woodev_account_install_allowed_hosts', array( $store_host ) );
			$allowed = array_filter( array_map( 'strtolower', array_map( 'strval', $allowed ) ) );

			return in_array( $host, $allowed, true );
		}

		/**
		 * Runs WordPress's Plugin_Upgrader against the package URL (no activation).
		 *
		 * Isolated as a protected seam so the install orchestration can be unit
		 * tested without a live upgrader.
		 *
		 * @since 2.0.2
		 *
		 * @param string $package The trusted package URL.
		 *
		 * @return true|WP_Error
		 */
		protected function run_upgrader( string $package ) {

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			if ( ! class_exists( 'Plugin_Upgrader' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
				return new WP_Error(
					'woodev_install_upgrader_missing',
					__( 'Механизм установки недоступен.', 'woodev-plugin-framework' ),
					array( 'status' => 500 )
				);
			}

			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );

			$result = $upgrader->install( $package ); // No activation — user activates manually.

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( true !== $result ) {
				$errors = method_exists( $skin, 'get_errors' ) ? $skin->get_errors() : null;

				if ( is_wp_error( $errors ) && $errors->has_errors() ) {
					return $errors;
				}

				return new WP_Error(
					'woodev_install_failed',
					__( 'Не удалось установить плагин.', 'woodev-plugin-framework' ),
					array( 'status' => 500 )
				);
			}

			return true;
		}
	}

endif;
