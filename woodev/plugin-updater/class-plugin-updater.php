<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Plugin_Updater' ) ) :
	/**
	 * Updater class.
	 *
	 * @since 1.2.1
	 */
	final class Woodev_Plugin_Updater {

		private $plugin;
		private $api_handler;
		private $api_url;
		private $api_data;
		private $name;
		private $slug;
		private $version;
		private $beta;
		private $failed_request_cache_key;

		/**
		 * Nonces of the pending acks attached to the CURRENT outgoing request.
		 *
		 * Set by get_api_params() when it attaches consumed_command_nonces; reset to
		 * empty otherwise. Ruled (s8-p5 critic re-review #1): acks_received may only
		 * confirm the intersection with THIS set — a response must never clear an ack
		 * recorded while the request was in flight (lost-ack protection §9.9).
		 *
		 * @since 2.0.0
		 *
		 * @var array<int, string>
		 */
		private $sent_ack_nonces = array();

		/**
		 * Class constructor.
		 *
		 * @param Woodev_Plugin $plugin       Instance of main plugin
		 *
		 * @uses hook()
		 *
		 * @uses plugin_basename()
		 */
		public function __construct( Woodev_Plugin $plugin ) {

			$this->plugin                   = $plugin;
			$this->api_handler              = new Woodev_Licensing_API( $this->plugin );
			$this->api_url                  = trailingslashit( $this->api_handler->get_url() );
			$this->api_data                 = array(
				'version' => $this->plugin->get_version(),
				'item_id' => $this->plugin->get_download_id(),
				'license' => $this->plugin->get_license_instance()->get_license(),
				'beta'    => is_callable( array( $this->plugin, 'is_beta_allowed' ) ) && $this->plugin->is_beta_allowed(),
			);
			$this->name                     = $this->plugin->get_plugin_file();
			$this->slug                     = basename( $this->plugin->get_plugin_file(), '.php' );
			$this->version                  = $this->plugin->get_version();
			$this->beta                     = ! empty( $this->api_data['beta'] );
			$this->failed_request_cache_key = 'woodev_failed_http_' . md5( $this->api_url );

			// Set up hooks.
			$this->init();
		}

		/**
		 * Set up WordPress filters to hook into WP's update process.
		 *
		 * @return void
		 * @uses add_filter()
		 */
		public function init() {
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
			add_filter( 'plugins_api', array( $this, 'plugins_api_filter' ), 10, 3 );
			add_action( 'after_plugin_row', array( $this, 'show_update_notification' ), 10, 2 );
			add_action( 'admin_init', array( $this, 'show_changelog' ) );
		}

		/**
		 * Check for Updates at the defined API endpoint and modify the update array.
		 *
		 * This function dives into the update API just when WordPress creates its update array,
		 * then adds a custom API call and injects the custom plugin data retrieved from the API.
		 * It is reassembled from parts of the native WordPress plugin update code.
		 * See wp-includes/update.php line 121 for the original wp_update_plugins() function.
		 *
		 * @param stdClass|mixed $_transient_data Update transient build by WordPress.
		 *
		 * @return stdClass Modified update transient with custom plugin data.
		 * @uses api_request()
		 */
		public function check_update( $_transient_data ) {

			if ( ! is_object( $_transient_data ) ) {
				$_transient_data = new stdClass();
			}

			if ( ! empty( $_transient_data->response ) && ! empty( $_transient_data->response[ $this->name ] ) ) {
				return $_transient_data;
			}

			$current = $this->get_repo_api_data();

			if ( false !== $current && is_object( $current ) && isset( $current->new_version ) ) {
				if ( version_compare( $this->version, $current->new_version, '<' ) ) {
					$_transient_data->response[ $this->name ] = $current;
				} else {
					// Populating the no_update information is required to support auto-updates in WordPress 5.5.
					$_transient_data->no_update[ $this->name ] = $current;
				}
			}

			$_transient_data->last_checked           = time();
			$_transient_data->checked[ $this->name ] = $this->version;

			return $_transient_data;
		}

		/**
		 * Get repo API data from store.
		 * Save to cache.
		 *
		 * @return stdClass|false
		 */
		public function get_repo_api_data() {

			$version_info = $this->get_cached_version_info();

			if ( false === $version_info || ( $this->api_handler->is_debug_enabled() && isset( $_GET['force-check'] ) ) ) {

				$version_info = $this->api_request(
					'plugin_latest_version',
					array(
						'slug' => $this->slug,
						'beta' => $this->beta,
					)
				);

				if ( ! $version_info ) {
					return false;
				}

				// This is required for plugin to support auto-updates in WordPress 5.5.
				$version_info->plugin = $this->name;
				$version_info->id     = $this->name;
				$version_info->tested = $this->get_tested_version( $version_info );

				$this->set_version_info_cache( $version_info );
			}

			return $version_info;
		}

		/**
		 * Gets the plugin's tested version.
		 *
		 * @param object $version_info
		 *
		 * @since 1.2.1
		 * @return null|string
		 */
		private function get_tested_version( $version_info ) {

			// There is no tested version.
			if ( empty( $version_info->tested ) ) {
				return null;
			}

			// Strip off extra version data so the result is x.y or x.y.z.
			list( $current_wp_version ) = explode( '-', get_bloginfo( 'version' ) );

			// The tested version is greater than or equal to the current WP version, no need to do anything.
			if ( version_compare( $version_info->tested, $current_wp_version, '>=' ) ) {
				return $version_info->tested;
			}

			$current_version_parts = explode( '.', $current_wp_version );
			$tested_parts          = explode( '.', $version_info->tested );

			// The current WordPress version is x.y.z, so update the tested version to match it.
			if ( isset( $current_version_parts[2] ) && $current_version_parts[0] === $tested_parts[0] && $current_version_parts[1] === $tested_parts[1] ) {
				$tested_parts[2] = $current_version_parts[2];
			}

			return implode( '.', $tested_parts );
		}

		/**
		 * Show the update notification on multisite subsites.
		 *
		 * @param string $file
		 * @param array  $plugin
		 */
		public function show_update_notification( $file, $plugin ) {

			// Return early if in the network admin, or if this is not a multisite install.
			if ( is_network_admin() || ! is_multisite() ) {
				return;
			}

			// Allow single site admins to see that an update is available.
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			if ( $this->name !== $file ) {
				return;
			}

			// Do not print any message if update does not exist.
			$update_cache = get_site_transient( 'update_plugins' );

			if ( ! isset( $update_cache->response[ $this->name ] ) ) {
				if ( ! is_object( $update_cache ) ) {
					$update_cache = new stdClass();
				}
				$update_cache->response[ $this->name ] = $this->get_repo_api_data();
			}

			// Return early if this plugin isn't in the transient->response or if the site is running the current or newer version of the plugin.
			if ( empty( $update_cache->response[ $this->name ] ) || version_compare( $this->version, $update_cache->response[ $this->name ]->new_version, '>=' ) ) {
				return;
			}

			printf(
				'<tr class="plugin-update-tr %3$s" id="%1$s-update" data-slug="%1$s" data-plugin="%2$s">',
				$this->slug,
				$file,
				in_array( $this->name, $this->get_active_plugins(), true ) ? 'active' : 'inactive'
			);

			echo '<td colspan="3" class="plugin-update colspanchange">';
			echo '<div class="update-message notice inline notice-warning notice-alt"><p>';

			$changelog_link = '';
			if ( ! empty( $update_cache->response[ $this->name ]->sections->changelog ) ) {
				$changelog_link = add_query_arg(
					array(
						'woodev_action' => 'view_plugin_changelog',
						'plugin'        => urlencode( $this->name ),
						'slug'          => urlencode( $this->slug ),
						'TB_iframe'     => 'true',
						'width'         => 772,
						'height'        => 911,
					),
					self_admin_url( 'index.php' )
				);
			}

			$update_link = add_query_arg(
				array(
					'action' => 'upgrade-plugin',
					'plugin' => urlencode( $this->name ),
				),
				self_admin_url( 'update.php' )
			);

			printf(
			/* translators: the plugin name. */
				esc_html__( 'There is a new version of %1$s available.', 'woodev-plugin-framework' ),
				esc_html( $plugin['Name'] )
			);

			if ( ! current_user_can( 'update_plugins' ) ) {
				echo ' ';
				esc_html_e( 'Contact your network administrator to install the update.', 'woodev-plugin-framework' );
			} elseif ( empty( $update_cache->response[ $this->name ]->package ) && ! empty( $changelog_link ) ) {
				echo ' ';
				printf(
				/* translators: 1. opening anchor tag, do not translate 2. the new plugin version 3. closing anchor tag, do not translate. */
					__( '%1$sView version %2$s details%3$s.', 'woodev-plugin-framework' ),
					'<a target="_blank" class="thickbox open-plugin-details-modal" href="' . esc_url( $changelog_link ) . '">',
					esc_html( $update_cache->response[ $this->name ]->new_version ),
					'</a>'
				);
			} elseif ( ! empty( $changelog_link ) ) {
				echo ' ';
				printf(
					__( '%1$sView version %2$s details%3$s or %4$supdate now%5$s.', 'woodev-plugin-framework' ),
					'<a target="_blank" class="thickbox open-plugin-details-modal" href="' . esc_url( $changelog_link ) . '">',
					esc_html( $update_cache->response[ $this->name ]->new_version ),
					'</a>',
					'<a target="_blank" class="update-link" href="' . esc_url( wp_nonce_url( $update_link, 'upgrade-plugin_' . $file ) ) . '">',
					'</a>'
				);
			} else {
				printf(
					' %1$s%2$s%3$s',
					'<a target="_blank" class="update-link" href="' . esc_url( wp_nonce_url( $update_link, 'upgrade-plugin_' . $file ) ) . '">',
					esc_html__( 'Update now.', 'woodev-plugin-framework' ),
					'</a>'
				);
			}

			do_action( "in_plugin_update_message-{$file}", $plugin, $plugin );

			echo '</p></div></td></tr>';
		}

		/**
		 * Gets the plugins active in a multisite network.
		 *
		 * @return array
		 */
		private function get_active_plugins() {
			$active_plugins         = (array) get_option( 'active_plugins' );
			$active_network_plugins = (array) get_site_option( 'active_sitewide_plugins' );

			return array_merge( $active_plugins, array_keys( $active_network_plugins ) );
		}

		/**
		 * Updates information on the "View version x.x details" page with custom data.
		 *
		 * @param mixed  $_data
		 * @param string $_action
		 * @param object $_args
		 *
		 * @return object $_data
		 * @uses api_request()
		 */
		public function plugins_api_filter( $_data, $_action = '', $_args = null ) {
			if ( 'plugin_information' !== $_action ) {
				return $_data;
			}

			if ( ! isset( $_args->slug ) || ( $_args->slug !== $this->slug ) ) {
				return $_data;
			}

			$to_send = array(
				'slug'   => $this->slug,
				'is_ssl' => is_ssl(),
				'fields' => array(
					'banners' => array(),
					'reviews' => false,
					'icons'   => array(),
				),
			);

			// Get the transient where we store the api request for this plugin for 24 hours
			$edd_api_request_transient = $this->get_cached_version_info();

			// If we have no transient-saved value, run the API, set a fresh transient with the API value, and return that value too right now.
			if ( empty( $edd_api_request_transient ) ) {

				$api_response = $this->api_request( 'plugin_information', $to_send );

				// Expires in 3 hours
				$this->set_version_info_cache( $api_response );

				if ( false !== $api_response ) {
					$_data = $api_response;
				}
			} else {
				$_data = $edd_api_request_transient;
			}

			// Convert sections into an associative array, since we're getting an object, but Core expects an array.
			if ( isset( $_data->sections ) && ! is_array( $_data->sections ) ) {
				$_data->sections = $this->convert_object_to_array( $_data->sections );
			}

			// Convert banners into an associative array, since we're getting an object, but Core expects an array.
			if ( isset( $_data->banners ) && ! is_array( $_data->banners ) ) {
				$_data->banners = $this->convert_object_to_array( $_data->banners );
			}

			// Convert icons into an associative array, since we're getting an object, but Core expects an array.
			if ( isset( $_data->icons ) && ! is_array( $_data->icons ) ) {
				$_data->icons = $this->convert_object_to_array( $_data->icons );
			}

			// Convert contributors into an associative array, since we're getting an object, but Core expects an array.
			if ( isset( $_data->contributors ) && ! is_array( $_data->contributors ) ) {
				$_data->contributors = $this->convert_object_to_array( $_data->contributors );
			}

			if ( ! isset( $_data->plugin ) ) {
				$_data->plugin = $this->name;
			}

			return $_data;
		}

		/**
		 * Convert some objects to arrays when injecting data into the update API
		 *
		 * Some data like sections, banners, and icons are expected to be an associative array, however due to the JSON
		 * decoding, they are objects. This method allows us to pass in the object and return an associative array.
		 *
		 * @param stdClass $data
		 *
		 * @since 1.2.1
		 *
		 * @return array
		 */
		private function convert_object_to_array( $data ) {
			if ( ! is_array( $data ) && ! is_object( $data ) ) {
				return array();
			}
			$new_data = array();
			foreach ( $data as $key => $value ) {
				$new_data[ $key ] = is_object( $value ) ? $this->convert_object_to_array( $value ) : $value;
			}

			return $new_data;
		}

		/**
		 * Calls the API and, if successfull, returns the object delivered by the API.
		 *
		 * @param string $_action The requested action.
		 * @param array  $_data   Parameters for the API action.
		 *
		 * @return false|object
		 * @uses get_bloginfo()
		 * @uses wp_remote_get()
		 * @uses is_wp_error()
		 */
		private function api_request( $_action, $_data ) {
			$data = array_merge( $this->api_data, $_data );

			if ( $data['slug'] !== $this->slug ) {
				return false;
			}

			if ( $this->request_recently_failed() ) {
				return false;
			}

			return $this->get_version_from_remote();
		}

		/**
		 * Determines if a request has recently failed.
		 *
		 * @since 1.2.1
		 *
		 * @return bool
		 */
		private function request_recently_failed() {
			$failed_request_details = get_option( $this->failed_request_cache_key );

			// Request has never failed.
			if ( empty( $failed_request_details ) || ! is_numeric( $failed_request_details ) ) {
				return false;
			}

			/*
			 * Request previously failed, but the timeout has expired.
			 * This means we're allowed to try again.
			 */
			if ( time() > $failed_request_details ) {
				delete_option( $this->failed_request_cache_key );

				return false;
			}

			return true;
		}

		/**
		 * If available, show the changelog for sites in a multisite install.
		 */
		public function show_changelog() {

			if ( empty( $_REQUEST['woodev_action'] ) || 'view_plugin_changelog' !== $_REQUEST['woodev_action'] ) {
				return;
			}

			if ( empty( $_REQUEST['plugin'] ) ) {
				return;
			}

			if ( empty( $_REQUEST['slug'] ) || $this->slug !== $_REQUEST['slug'] ) {
				return;
			}

			if ( ! current_user_can( 'update_plugins' ) ) {
				wp_die( esc_html__( 'You do not have permission to install plugin updates', 'woodev-plugin-framework' ), esc_html__( 'Error', 'woodev-plugin-framework' ), array( 'response' => 403 ) );
			}

			$version_info = $this->get_repo_api_data();

			if ( isset( $version_info->sections ) ) {

				$sections = $this->convert_object_to_array( $version_info->sections );

				if ( ! empty( $sections['changelog'] ) ) {
					echo '<div style="background:#fff;padding:10px;">' . wp_kses_post( $sections['changelog'] ) . '</div>';
				}
			}

			exit;
		}

		/**
		 * Gets the current version information from the remote site.
		 *
		 * @return object|false
		 */
		private function get_version_from_remote() {

			try {

				$request = $this->api_handler->make_request( $this->get_api_params() );

				$response = $request->get_response_data();

				// D-W3 / §3.2 pull-fallback: consume any license_commands delivered in
				// the response and drain acks BEFORE the sections early-return below —
				// ruled s8-p5 re-review #2: a command-only response (no version payload)
				// must still deliver its commands and confirm sent acks; the function
				// still returns false for it, exactly as before.
				$ack_store = class_exists( 'Woodev_License_Command_Acks' ) ? new Woodev_License_Command_Acks() : null;

				if ( class_exists( 'Woodev_License_Command_Dispatcher' ) ) {
					try {
						Woodev_License_Command_Dispatcher::consume_pull_commands( $response, 'pull', $ack_store );
					} catch ( \Throwable $throwable ) {
						// Ruled containment boundary (s8-p5 critic #4b): a command-processing
						// bug must not break the update flow — loud-but-contained, never silent.
						error_log( 'Woodev updater: pull-command consumption failed: ' . $throwable->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- ruled loud-but-contained boundary (s8-p5 #4b).
					}
				}

				// acks_received drain — ruled s8-p5 re-review #1: confirm ONLY the
				// intersection with the nonces THIS request actually sent; when nothing
				// was sent, skip entirely. A rogue/buggy response must never clear an
				// ack recorded while the request was in flight (lost-ack §9.9).
				if ( null !== $ack_store && array() !== $this->sent_ack_nonces ) {
					try {
						$acks_received = isset( $response->acks_received ) ? $response->acks_received : null;
						if ( is_array( $acks_received ) ) {
							$confirmed = array_values( array_intersect( array_filter( $acks_received, 'is_string' ), $this->sent_ack_nonces ) );
							if ( array() !== $confirmed ) {
								$ack_store->confirm_received( $confirmed );
							}
						}
					} catch ( \Throwable $throwable ) {
						// Ruled containment boundary (s8-p5 critic #4b): ack confirmation must
						// not break the update flow — loud-but-contained, never silent.
						error_log( 'Woodev updater: ack confirmation failed: ' . $throwable->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- ruled loud-but-contained boundary (s8-p5 #4b).
					}
				}

				if ( $response && isset( $response->sections ) ) {
					$response->sections = maybe_unserialize( $response->sections );
				} else {
					return false;
				}

				if ( isset( $response->banners ) ) {
					$response->banners = maybe_unserialize( $response->banners );
				}

				if ( isset( $response->icons ) ) {
					$response->icons = maybe_unserialize( $response->icons );
				}

				if ( ! empty( $response->sections ) ) {
					foreach ( $response->sections as $key => $section ) {
						$response->$key = (array) $section;
					}
				}

				// §4 keyless claim transport (B-3): feed any signed claim riding the
				// get_version response to the claim store. Wrapped in its own guard so a
				// consumption Throwable can NEVER break the update flow — the parsed
				// $response is still returned. consume_from_response() also swallows
				// internally; this is belt-and-suspenders against a resolution failure.
				try {
					$this->plugin->get_license_instance()->get_authority_claims()->consume_from_response( $response );
				} catch ( \Throwable $throwable ) {
					// Intentionally swallowed — the update flow must not break on claim IO.
					unset( $throwable );
				}

				return $response;

			} catch ( Exception $e ) {

			}

			return false;
		}

		/**
		 * Get the version info from the cache, if it exists.
		 *
		 * @param string $cache_key
		 *
		 * @return object|false
		 */
		public function get_cached_version_info( $cache_key = '' ) {

			if ( empty( $cache_key ) ) {
				$cache_key = $this->get_cache_key();
			}

			$cache = get_option( $cache_key );

			// Cache is expired
			if ( empty( $cache['timeout'] ) || time() > $cache['timeout'] ) {
				return false;
			}

			// We need to turn the icons into an array, thanks to WP Core forcing these into an object at some point.
			$cache['value'] = json_decode( $cache['value'] );
			if ( ! empty( $cache['value']->icons ) ) {
				$cache['value']->icons = (array) $cache['value']->icons;
			}

			return $cache['value'];
		}

		/**
		 * Adds the plugin version information to the database.
		 *
		 * @param string $value
		 * @param string $cache_key
		 */
		public function set_version_info_cache( $value = '', $cache_key = '' ) {

			if ( empty( $cache_key ) ) {
				$cache_key = $this->get_cache_key();
			}

			$data = array(
				'timeout' => strtotime( '+3 hours', time() ),
				'value'   => wp_json_encode( $value ),
			);

			update_option( $cache_key, $data, false );
		}

		/**
		 * Gets the parameters for the API request.
		 *
		 * D-W3 / §9.5: if there are pending command acks, they are attached as
		 * `consumed_command_nonces` (structured entries). The field is ABSENT when
		 * there is nothing to send — the request shape is byte-for-byte identical
		 * to the pre-ack shape for the no-pending-acks case (EDD wire contract).
		 *
		 * @since 1.2.1
		 * @return array
		 */
		private function get_api_params() {
			$params = array(
				'edd_action'  => 'get_version',
				'license'     => ! empty( $this->api_data['license'] ) ? $this->api_data['license'] : '',
				'item_id'     => isset( $this->api_data['item_id'] ) ? $this->api_data['item_id'] : false,
				'version'     => isset( $this->api_data['version'] ) ? $this->api_data['version'] : false,
				'slug'        => $this->slug,
				'beta'        => $this->beta,
				'php_version' => phpversion(),
				'wp_version'  => get_bloginfo( 'version' ),
				// ADDITIVE (s8-p0 §9.7): the keyless poll must carry the site so the
				// server can bind acks. RAW home_url() — the server normalizes before
				// signing (plan decision 2); the wire value is NOT normalized here.
				'url'         => home_url(),
			);

			// D-W3 / §9.5: attach pending acks ONLY when non-empty so the request shape
			// remains byte-for-byte identical to the pre-ack shape when there is nothing
			// to send (EDD wire contract). The sent nonce set is stashed so the response
			// handler can confirm ONLY what THIS request carried (s8-p5 re-review #1).
			$this->sent_ack_nonces = array();

			if ( class_exists( 'Woodev_License_Command_Acks' ) ) {
				$pending = ( new Woodev_License_Command_Acks() )->get_pending();
				if ( array() !== $pending ) {
					$params['consumed_command_nonces'] = $pending;
					$this->sent_ack_nonces             = array_values( array_filter( array_column( $pending, 'nonce' ), 'is_string' ) );
				}
			}

			return $params;
		}

		/**
		 * Gets the unique key (option name) for a plugin.
		 *
		 * @since 1.2.1
		 * @return string
		 */
		private function get_cache_key() {
			$string = $this->slug . $this->api_data['license'] . $this->beta;

			return 'woodev_' . md5( serialize( $string ) );
		}
	}

endif;
