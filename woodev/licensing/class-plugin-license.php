<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Plugins_License' ) ) :

	/**
	 * Handler Plugins License Class
	 */
	final class Woodev_Plugins_License {

		/**
		 * The plugin URL.
		 *
		 * @var string
		 */
		protected $plugin_url;

		/**
		 * The plugin name.
		 *
		 * @var string
		 */
		protected $item_name;

		/**
		 * The plugin shortname (slug).
		 *
		 * @var string
		 */
		private $item_shortname;

		/**
		 * The license key.
		 *
		 * @var string
		 */
		private $license_key;

		/**
		 * The main plugin class instance
		 *
		 * @var Woodev_Plugin
		 */
		protected $plugin;

		/**
		 * The Woodev license object.
		 * This contains standard license data (from the API response) and the license key.
		 *
		 * @var Woodev_License
		 */
		private $woodev_license;

		/**
		 * Returns Woodev API License handler
		 *
		 * @var Woodev_Licensing_API
		 */
		private $api_handler;

		/**
		 * Class constructor
		 *
		 * @param Woodev_Plugin $plugin
		 */
		public function __construct( Woodev_Plugin $plugin ) {

			$this->plugin = $plugin;

			$this->plugin_url     = $this->plugin->get_plugin_url();
			$this->item_name      = $this->plugin->get_plugin_name();
			$this->item_shortname = $this->plugin->get_id_underscored();
			$this->woodev_license = new Woodev_License( $this->plugin->get_id_underscored() );
			$this->license_key    = $this->woodev_license->key;
			$this->api_handler    = new Woodev_Licensing_API( $this->plugin );

			$this->includes();
			$this->add_hooks();
		}

		private function includes() {
		}

		private function add_hooks() {

			//License actions
			add_action( 'admin_init', array( $this, 'activate_license' ) );
			add_action( 'admin_init', array( $this, 'deactivate_license' ) );


			add_action( 'admin_notices', array( $this, 'notices' ) );

			add_action( 'admin_print_scripts-plugins.php', array( $this, 'plugin_screen_scripts' ) );

			add_action( 'in_plugin_update_message-' . plugin_basename( $this->plugin->get_plugin_file() ), array(
				$this,
				'plugin_row_license_missing'
			), 10, 2 );
		}

		/**
		 * Returns the plugin license key
		 *
		 * @return false|string
		 */
		public function get_license() {
			return ! empty( $this->license_key ) ? $this->license_key : false;
		}

		/**
		 * Returns license status description by status name
		 *
		 * @access private
		 *
		 * @param string $status_name
		 *
		 * @return string
		 */
		public function get_license_status( $status_name ) {

			$statuses = array(
				'missing'               => __( 'License does not exist', 'woodev-plugin-framework' ),
				'missing_url'           => __( 'Site URL is not provided', 'woodev-plugin-framework' ),
				'license_not_activable' => __( 'Attempting to activate a bundles parent license', 'woodev-plugin-framework' ),
				'disabled'              => __( 'License key revoked', 'woodev-plugin-framework' ),
				'no_activations_left'   => __( 'No activations left', 'woodev-plugin-framework' ),
				'expired'               => __( 'License has expired', 'woodev-plugin-framework' ),
				'key_mismatch'          => __( 'License is not valid for this plugin', 'woodev-plugin-framework' ),
				'invalid_item_id'       => __( 'Invalid plugin ID', 'woodev-plugin-framework' ),
				'item_name_mismatch'    => __( 'License is not valid for this plugin', 'woodev-plugin-framework' ),
				'site_inactive'         => __( 'Site is not active for this license', 'woodev-plugin-framework' ),
				'invalid'               => __( 'License key does not match', 'woodev-plugin-framework' ),
				'valid'                 => __( 'License is valid', 'woodev-plugin-framework' )
			);

			return isset( $statuses[ $status_name ] ) ? $statuses[ $status_name ] : __( 'Unknown license status', 'woodev-plugin-framework' );
		}

		/**
		 * Makes request to API to get license data
		 *
		 * @access private
		 *
		 * @param string $action
		 * @param string $license_key
		 *
		 * @return false|object|Woodev_Licencing_API_Response
		 * @throws Woodev_API_Exception
		 * @throws Woodev_Plugin_Exception
		 */
		private function dispatch( $action = 'check_license', $license_key = '' ) {

			if ( ! in_array( wc_strtolower( $action ), array(
				'activate_license',
				'deactivate_license',
				'check_license',
				'get_version'
			) ) ) {
				return false;
			}

			// Data to send to the API
			$api_params = array(
				'edd_action' => $action,
				'license'    => $license_key,
				'item_id'    => $this->plugin->get_download_id(),
				'url'        => home_url(),
				'version'    => $this->plugin->get_version()
			);

			try {

				$license_data = $this->api_handler->make_request( $api_params );

				if ( ! $license_data ) {
					throw new Exception( __( 'Cannot get license data', 'woodev-plugin-framework' ) );
				}

				return $license_data;

			} catch ( Exception $e ) {
				throw $e;
			}
		}

		/**
		 * Activate the license key.
		 *
		 * @return  void
		 */
		public function activate_license() {

			if ( ! isset( $_POST['option_page'] ) || 'woodev_license_fields_group' !== $_POST['option_page'] ) {
				return;
			}

			$nonce_name = sprintf( '%s-nonce', $this->plugin->get_id_dasherized() );

			if ( ! isset( $_REQUEST[ $nonce_name ] ) || ! wp_verify_nonce( $_REQUEST[ $nonce_name ], $nonce_name ) ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$license_key = sanitize_text_field( $_POST[ $this->plugin->get_plugin_option_name( 'license_key' ) ] );

			if ( empty( $license_key ) ) {
				$this->woodev_license->delete();

				return;
			}

			if ( isset( $_POST[ $this->plugin->get_plugin_option_name( 'deactivate' ) ] ) ) {
				// Don't activate a key when deactivating a different key
				return;
			}

			if ( $this->is_license_valid() ) {
				return;
			}

			try {

				// Call the API
				$license_data = $this->dispatch( 'activate_license', $license_key );

				// Make sure there are no errors
				if ( ! $license_data ) {
					return;
				}

				// Clear the option for licensed extensions to force regeneration.
				if ( ! empty( $license_data->license ) && 'valid' === $license_data->license ) {
					delete_transient( 'woodev_extensions' );
				}

				$this->woodev_license->save( $license_data->get_response_data() );

			} catch ( Exception $exception ) {
			}

		}

		/**
		 * Deactivate the license key
		 *
		 * @param bool $deprecated Deprecated since 1.2.1.
		 *
		 * @return  void
		 */
		public function deactivate_license( $deprecated = false ) {

			if ( $deprecated ) {
				wc_deprecated_argument( __METHOD__, '1.2.1', 'The AJAX parameter is not using anymore.' );
			}

			if ( ! isset( $_POST['option_page'] ) || 'woodev_license_fields_group' !== $_POST['option_page'] ) {
				return;
			}

			$nonce_name = sprintf( '%s-nonce', $this->plugin->get_id_dasherized() );

			if ( ! isset( $_REQUEST[ $nonce_name ] ) || ! wp_verify_nonce( $_REQUEST[ $nonce_name ], $nonce_name ) ) {
				wp_die( __( 'Nonce verification failed', 'woodev-plugin-framework' ), __( 'Error', 'woodev-plugin-framework' ), array( 'response' => 403 ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			// Run on deactivate button press
			if ( isset( $_POST[ $this->plugin->get_plugin_option_name( 'deactivate' ) ] ) ) {

				try {
					// Call the API
					$response = $this->dispatch( 'deactivate_license', $this->license_key );

					// Make sure there are no errors
					if ( ! $response ) {
						return;
					}

					$this->woodev_license->delete();

				} catch ( Exception $exception ) {

					wp_die( $exception->getMessage(), __( 'Error', 'woodev-plugin-framework' ), $exception->getCode() );

				}
			}
		}

		/**
		 * Make license verification
		 *
		 * @param string $license Plugin license key
		 * @param bool   $ajax    Flag is it request AJAX or direct
		 *
		 * @return boolean
		 */
		public function verify_license( $license, $ajax = false ) {

			try {

				if ( empty( $license ) ) {
					throw new Exception( __( 'License key was not provided.', 'woodev-plugin-framework' ) );
				}

				// Call our API
				$license_data = $this->dispatch( 'check_license', trim( $license ) );

				if ( ! $license_data ) {
					throw new Exception( __( 'Cannot get license data. Please try again.', 'woodev-plugin-framework' ) );
				}

				$this->woodev_license->update( array(
					'license' => $license_data->license,
					'success' => $license_data->success,
					'error'   => $license_data->error,
					'expires' => $license_data->expires
				) );

				if ( $this->woodev_license->success && ! empty( $this->woodev_license->license ) ) {

					$this->woodev_license->save( $license_data->get_response_data() );
					//Clean plugins cache
					wp_clean_plugins_cache();

					if ( $ajax ) {

						if ( ! $this->woodev_license->item_name ) {
							$this->woodev_license->item_name = $this->plugin->get_plugin_name();
						}

						$license_message = new Woodev_License_Messages( $this->woodev_license );

						wp_send_json_success( $license_message->get_message() );

					} else {
						return true;
					}

				}

				if ( false === $this->woodev_license->success ) {
					throw new Exception( sprintf( __( 'Your license key for %s is not valid. The key is not existing, or linked customer was deleted. Please provide another license key to continue to get support and updates.', 'woodev-plugin-framework' ), $this->item_name ) );
				}

				throw new Exception( __( 'Cannot verify license key. Please try again.', 'woodev-plugin-framework' ) );

			} catch ( Exception $e ) {

				if ( $ajax ) {
					wp_send_json_error( $e->getMessage() );
				}

			}

			return false;

		}

		/**
		 * Validates license by license key
		 *
		 * @param string $license    Plugin license key
		 * @param bool   $deprecated Deprecated since 1.2.1.
		 * @param bool   $ajax       Flag is it request AJAX or direct
		 *
		 * @return void
		 */
		public function validate_license( $license = '', $deprecated = false, $ajax = false ) {

			if ( $deprecated ) {
				wc_deprecated_argument( __METHOD__, '1.2.1', 'The "forced" parameter is not using anymore.' );
			}

			try {

				$license_data = $this->dispatch( 'check_license', trim( $license ) );

				if ( ! $license_data ) {
					throw new Exception( __( 'Cannot get license data. Please try again.', 'woodev-plugin-framework' ) );
				}

				$this->woodev_license->update( array(
					'license' => $license_data->license,
					'success' => $license_data->success,
					'error'   => $license_data->error,
					'expires' => $license_data->expires
				) );

				if ( false === $this->woodev_license->success ) {
					throw new Exception( sprintf( __( 'Your license key for %s is not valid. The key is not existing, or linked customer was deleted. Please provide another key to continue to get support and updates.', 'woodev-plugin-framework' ), $this->item_name ) );
				}

				if ( ! $this->woodev_license->item_name ) {
					$this->woodev_license->item_name = $this->plugin->get_plugin_name();
				}

				$license_message = new Woodev_License_Messages( $this->woodev_license );

				if ( $this->woodev_license->license == 'valid' ) {
					$ajax && wp_send_json_success( $license_message->get_message() );
				} else {
					$ajax && wp_send_json_error( $license_message->get_message() );
				}

				return;

			} catch ( Exception $exception ) {
				$ajax && wp_send_json_error( $exception->getMessage() );
			}
		}

		public function notices() {

			if ( empty( $this->license_key ) ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( ! empty( $_GET['page'] ) && 'woodev-licenses' === $_GET['page'] ) {
				return;
			}

			if ( ( empty( $this->woodev_license->license ) || 'valid' !== $this->woodev_license->license ) ) {

				$this->plugin->get_admin_notice_handler()->add_admin_notice(
					sprintf(
						__( 'You have invalid or expired license keys for %1$s. %2$sActivate License%3$s', 'woodev-plugin-framework' ),
						'<strong>' . $this->item_name . '</strong>',
						'<a href="' . $this->get_license_settings_url() . '" class="button button-secondary">',
						'</a>'
					),
					sprintf( 'woodev-%s-missing-license', $this->plugin->get_id_dasherized() ),
					array( 'notice_class' => "error {$this->item_shortname}-license-error" )
				);
			}
		}

		/**
		 * Show plugin changes on the plugins screen.
		 *
		 * @param array    $plugin_data Unused parameter.
		 * @param stdClass $response    Plugin update response.
		 */
		public function plugin_row_license_missing( $plugin_data, $response ) {

			if ( ! $plugin_data || ! isset( $plugin_data['package'] ) ) {
				return;
			}

			if ( ! $this->woodev_license || 'valid' !== $this->woodev_license->license ) {
				echo '&nbsp;&nbsp;<strong><a href="' . $this->get_license_settings_url() . '" style="color: #aa0000;">' . __( 'Enter valid license key for automatic updates.', 'woodev-plugin-framework' ) . '</a></strong>';
			}

			if( ! $response ) {
				return;
			}

			$new_version = false;

			if( is_array( $response ) && array_key_exists( 'new_version', $response ) ) {
				$new_version = $response['new_version'];
			} elseif( is_object( $response ) && isset( $response->new_version ) ) {
				$new_version = $response->new_version;
			}

			if( ! $new_version ) {
				return;
			}

			$current_version_parts = explode( '.', $this->plugin->get_version() );
			$new_version_parts     = explode( '.', $new_version );

			$current_version_top    = $current_version_parts[0];
			$current_version_second = isset( $current_version_parts[1] ) ? $current_version_parts[1] : '0';
			$new_version_top        = $new_version_parts[0];
			$new_version_second     = isset( $new_version_parts[1] ) ? $new_version_parts[1] : '0';

			// If user has already moved to the minor version, we don't need to flag up anything.
			if ( version_compare( $current_version_top . '.' . $current_version_second, $new_version_top . '.' . $new_version_second, '=' ) ) {
				return;
			}

			$update_notice = '<div class="woodev-plugin-upgrade-notice">';
			$update_notice .= '<p><strong>' . __( 'Attention! Please Backup your site before updating.', 'woodev-plugin-framework' ) . '</strong></p>';
			$update_notice .= '<p>' . sprintf(
					__( 'The latest version of %1$s%2$s %3$s%4$s includes massive changes across different areas of the plugin with revamped code structure for optimized performance. We would highly recommend you to backup your entire site before updating the plugin & test it on your staging website. %5$sContact our Support%6$s if you encounter any kind of errors or if you need any kind of assistance.', 'woodev-plugin-framework' ),
					'<strong>',
					$this->plugin->get_plugin_name(),
					$new_version_top . '.' . $new_version_second . '.0',
					'</strong>',
					'<a href="https://woodev.ru/support" target="_blank">',
					'</a>'
				) . '</p>';

			if ( $this->plugin->get_documentation_url() ) {
				$update_notice .= '<p>' . sprintf(
						__( 'We also recommend that you %1$sread the documentation%2$s after updating the plugin.', 'woodev-plugin-framework' ),
						'<a href="' . esc_url( $this->plugin->get_documentation_url() ) . '" target="_blank">',
						'</a>'
					) . '</p>';
			}

			$update_notice .= '</div>';

			echo '</p>' . wp_kses_post( $update_notice ) . '<p class="dummy">';
		}

		public function plugin_screen_scripts() {

			wp_enqueue_style(
				'woodev-plugins-table-style',
				$this->plugin->get_framework_assets_url() . '/css/admin/woodev-plugins-table.css'
			);
		}

		public function get_license_settings_url() {
			return menu_page_url( 'woodev-licenses', false );
		}

		/**
		 * Returns additional license data if it exists
		 *
		 * @return object|string|null
		 */
		public function get_license_data() {
			return isset( $this->woodev_license->data ) ? $this->woodev_license->data : null;
		}

		/**
		 * Whether the license is valid.
		 *
		 * @return bool
		 */
		public function is_license_valid() {
			return ! empty( $this->license_key ) && $this->has_status( 'valid' );
		}

		/**
		 * Whether the site is using an active license.
		 *
		 * @return bool
		 */
		public function is_active() {
			return ! in_array( true, array(
				$this->is_expired(),
				$this->is_disabled(),
				$this->is_invalid()
			), true );
		}

		/**
		 * Whether the site is using an expired license.
		 *
		 * @return bool
		 */
		public function is_expired() {
			return $this->has_status( 'expired' );
		}

		/**
		 * Whether the site is using a disabled license.
		 *
		 * @return bool
		 */
		public function is_disabled() {
			return $this->has_statuses( array(
				'disabled',
				'revoked'
			) );
		}

		/**
		 * Whether the site is using an invalid license.
		 *
		 * @return bool
		 */
		public function is_invalid() {
			return $this->has_statuses( array(
				'invalid',
				'invalid_item_id',
				'item_name_mismatch',
				'key_mismatch'
			) );
		}

		/**
		 * Check whether there is a specific license status.
		 *
		 * @param string $status License status.
		 *
		 * @return bool
		 */
		private function has_status( $status ) {
			return $this->woodev_license->license === $status;
		}

		/**
		 * Check whether there are specific license statuses.
		 *
		 * @param array $statuses License status keys.
		 *
		 * @return bool
		 */
		private function has_statuses( $statuses = array() ) {
			return in_array( $this->woodev_license->license, $statuses, true );
		}
	}

endif;
