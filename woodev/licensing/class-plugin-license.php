<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Plugins_License' ) ) :

	/**
	 * Handler Plugins License Class
	 *
	 * Not final: the REST controller (Woodev_REST_API_License) resolves engine
	 * instances through the typed static accessor get_registered_instance() and is
	 * unit-tested against Mockery doubles of this class, which requires it to be
	 * sub-classable.
	 */
	class Woodev_Plugins_License {

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
		 * Registry of live license engines keyed by (string) download id.
		 *
		 * Lets the REST controller and the page enqueue resolve a plugin's license
		 * engine by its EDD download id. The bootstrap registry holds definition
		 * arrays, not engine instances, so this engine keeps its own.
		 *
		 * @since 2.0.0
		 *
		 * @var array<string, Woodev_Plugins_License>
		 */
		private static $registered_instances = array();

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

			self::$registered_instances[ (string) $this->plugin->get_download_id() ] = $this;
		}

		private function includes() {
		}

		private function add_hooks() {

			// Boot the woodev/v1 license REST controller (idempotent). The controller
			// + registrar are require_once'd in Woodev_Plugin::includes(), which runs
			// before the license handler is constructed; the class_exists guard keeps
			// this resilient to any future include-order change.
			if ( class_exists( 'Woodev_REST_API_License' ) ) {
				Woodev_REST_API_License::boot();
			}

			add_action( 'admin_notices', array( $this, 'notices' ) );

			add_action( 'admin_print_scripts-plugins.php', array( $this, 'plugin_screen_scripts' ) );

			add_action(
				'in_plugin_update_message-' . plugin_basename( $this->plugin->get_plugin_file() ),
				array(
					$this,
					'plugin_row_license_missing',
				),
				10,
				2
			);
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
				'valid'                 => __( 'License is valid', 'woodev-plugin-framework' ),
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

			if ( ! in_array(
				self::strtolower( $action ),
				array(
					'activate_license',
					'deactivate_license',
					'check_license',
					'get_version',
				)
			) ) {
				return false;
			}

			// Data to send to the API
			$api_params = array(
				'edd_action' => $action,
				'license'    => $license_key,
				'item_id'    => $this->plugin->get_download_id(),
				'url'        => home_url(),
				'version'    => $this->plugin->get_version(),
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
		 * Normalizes a string to lowercase without WooCommerce helper dependencies.
		 *
		 * @param string $value String to normalize.
		 * @return string
		 */
		private static function strtolower( $value ) {
			return strtolower( (string) $value );
		}

		/**
		 * Activates a license key against the store and persists the result.
		 *
		 * Transport-agnostic single writer for license activation (the REST
		 * controller is the only caller). It reproduces, byte-for-byte, the writes
		 * the legacy flow performed: the Settings API sanitize callback wrote the
		 * woodev_{id}_license_key option, and the admin_init handler dispatched the
		 * EDD activate_license request, cleared the woodev_extensions transient on a
		 * 'valid' response, and saved the payload via Woodev_License::save().
		 *
		 * Write ordering (legacy Settings-API parity): the submitted key is persisted
		 * to the woodev_{id}_license_key option BEFORE the store call — the legacy
		 * Settings API saved the key option even when the activation API call later
		 * failed, so this ordering yields the identical end state. A transport failure
		 * therefore leaves the stored LICENSE DATA (woodev_{id}_license) untouched
		 * (save() is never reached) while the key option retains the submitted value.
		 *
		 * License-free products (Woodev_Plugin::is_need_license() === false) are a
		 * no-op that returns the current state — never an enforcement decision
		 * (anti-pirate invariant: see is_license_required()).
		 *
		 * Throw contract: throws Woodev_Plugin_Exception on validation failure
		 * (empty key, no license data) and propagates the Exception thrown by
		 * dispatch() on transport failure. The REST layer (s6-p2) maps both to a
		 * WP_Error. Stored license DATA is left untouched on any throw (the key option
		 * write above is intentional parity, not a data write).
		 *
		 * @since 2.0.0
		 *
		 * @param string $license_key The license key to activate.
		 *
		 * @return array The new license state (see get_state()).
		 * @throws Woodev_Plugin_Exception On validation failure.
		 * @throws Exception On transport failure (propagated from dispatch()).
		 */
		public function activate( string $license_key ): array {

			// License-free plugins never process license activation (presentation flag — see Woodev_Plugin::is_need_license()).
			if ( ! $this->plugin->is_need_license() ) {
				return $this->get_state();
			}

			$license_key = sanitize_text_field( $license_key );

			if ( '' === $license_key ) {
				throw new Woodev_Plugin_Exception( esc_html__( 'Лицензионный ключ не указан.', 'woodev-plugin-framework' ) );
			}

			// Parity: the Settings API wrote this option in the legacy flow.
			update_option( $this->plugin->get_plugin_option_name( 'license_key' ), $license_key );
			$this->license_key = $license_key;

			// Parity: the legacy activate_license() early-returned when already valid.
			if ( $this->is_license_valid() ) {
				return $this->get_state();
			}

			$license_data = $this->dispatch( 'activate_license', $license_key );

			if ( ! $license_data ) {
				throw new Woodev_Plugin_Exception( esc_html__( 'Не удалось получить данные лицензии. Попробуйте ещё раз.', 'woodev-plugin-framework' ) );
			}

			// Clear the option for licensed extensions to force regeneration.
			if ( ! empty( $license_data->license ) && 'valid' === $license_data->license ) {
				delete_transient( 'woodev_extensions' );
			}

			$this->woodev_license->save( $license_data->get_response_data() );

			return $this->get_state();
		}

		/**
		 * Deactivates the current license key against the store.
		 *
		 * Transport-agnostic single writer for deactivation. It dispatches the EDD
		 * deactivate_license request with the stored key and, on success, deletes
		 * the stored license object via Woodev_License::delete(). It NEVER writes or
		 * deletes the woodev_{id}_license_key option — parity with the legacy
		 * handler, which left the key option in place on deactivation.
		 *
		 * License-free products are a no-op returning the current state.
		 *
		 * Throw contract: propagates the Exception thrown by dispatch() on transport
		 * failure, and throws Woodev_Plugin_Exception when no license data comes
		 * back. The REST layer maps both to a WP_Error.
		 *
		 * @since 2.0.0
		 *
		 * @return array The new license state (see get_state()).
		 * @throws Woodev_Plugin_Exception When no license data is returned.
		 * @throws Exception On transport failure (propagated from dispatch()).
		 */
		public function deactivate(): array {

			// License-free plugins never process license deactivation (presentation flag — see Woodev_Plugin::is_need_license()).
			if ( ! $this->plugin->is_need_license() ) {
				return $this->get_state();
			}

			$response = $this->dispatch( 'deactivate_license', $this->license_key );

			if ( ! $response ) {
				throw new Woodev_Plugin_Exception( esc_html__( 'Не удалось деактивировать лицензию. Попробуйте ещё раз.', 'woodev-plugin-framework' ) );
			}

			$this->woodev_license->delete();

			// Woodev_License::delete() removes the license-data option but leaves the
			// already-populated in-memory object fields (license='valid', expires, …)
			// untouched, because Woodev_License::get() early-returns without resetting
			// them when the key option survives deactivation (which it does by design).
			// Re-instantiate the license object so get_state() reflects a fresh read —
			// legacy parity: the old flow re-instantiated Woodev_License on the next
			// page render, so post-deactivate state was always a fresh read.
			$this->woodev_license = new Woodev_License( $this->plugin->get_id_underscored() );
			$this->license_key    = $this->woodev_license->key;

			return $this->get_state();
		}

		/**
		 * Persists the beta opt-in flag.
		 *
		 * Reproduces the legacy register_setting sanitize semantics exactly: when
		 * enabled, write the woodev_{id}_beta_version option with value 'yes';
		 * otherwise delete the option (absence is the "disabled" state). The
		 * Woodev_Plugin::is_beta_allowed() reader interprets it.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $enabled Whether beta versions are allowed.
		 *
		 * @return void
		 */
		public function set_beta_enabled( bool $enabled ): void {

			$option_name = $this->plugin->get_plugin_option_name( 'beta_version' );

			if ( $enabled ) {
				update_option( $option_name, 'yes' );
			} else {
				delete_option( $option_name );
			}
		}

		/**
		 * Builds the structured license state for the React app and REST responses.
		 *
		 * The is_valid / is_active booleans come from the real accessors, which
		 * consult ONLY is_license_required() (server authority) — never the local
		 * is_need_license() presentation flag (anti-pirate invariant).
		 *
		 * @since 2.0.0
		 *
		 * @return array {
		 *     @type string $plugin_id       The EDD download id (string).
		 *     @type string $plugin_name     The plugin display name.
		 *     @type string $license_key     The full license key, '' when none.
		 *     @type string $status          The raw EDD status token, or ''.
		 *     @type string $status_label    Localized status label, '' when no status.
		 *     @type string $message         The license message, sanitized with wp_kses_post().
		 *     @type string $message_variant One of success|warning|error|info.
		 *     @type mixed  $expires         The raw expiry value, untyped ('lifetime'|date string|numeric timestamp|''|null).
		 *     @type bool   $is_valid        Whether the license is valid.
		 *     @type bool   $is_active       Whether the license is active.
		 *     @type bool   $is_need_license Presentation flag.
		 *     @type bool   $beta_enabled    Whether the beta opt-in is set.
		 * }
		 */
		public function get_state(): array {

			$status = (string) $this->woodev_license->license;

			return array(
				'plugin_id'       => (string) $this->plugin->get_download_id(),
				'plugin_name'     => $this->plugin->get_plugin_name(),
				'license_key'     => (string) $this->license_key,
				'status'          => $status,
				'status_label'    => '' === $status ? '' : $this->get_license_status( $status ),
				'message'         => wp_kses_post( ( new Woodev_License_Messages( $this->woodev_license ) )->get_message() ),
				'message_variant' => $this->get_message_variant(),
				// Raw expiry — a numeric timestamp stays numeric, a 'Y-m-d H:i:s'
				// string stays a string, ''/null stay as-is. Do NOT coerce the type:
				// the React app distinguishes lifetime / date / unknown by raw value.
				'expires'         => $this->woodev_license->expires,
				'is_valid'        => $this->is_license_valid(),
				'is_active'       => $this->is_active(),
				'is_need_license' => (bool) $this->plugin->is_need_license(),
				'beta_enabled'    => (bool) $this->plugin->is_beta_allowed(),
			);
		}

		/**
		 * Maps the raw license status to a presentation bucket.
		 *
		 * Single source of truth for the status → bucket mapping the legacy CSS did
		 * inline (woodev-licenses-status-*). The expires-soon timestamp is computed
		 * the way Woodev_License_Messages::__construct() does (NOT the broken
		 * `is_numeric(...) ?: strtotime(...)` line from the deleted renderer).
		 *
		 * @since 2.0.0
		 *
		 * @return string One of success|warning|error|info.
		 */
		private function get_message_variant(): string {

			$status = (string) $this->woodev_license->license;

			$error_statuses = array(
				'expired',
				'disabled',
				'revoked',
				'missing',
				'missing_url',
				'invalid',
				'invalid_item_id',
				'item_name_mismatch',
				'key_mismatch',
				'site_inactive',
				'no_activations_left',
				'license_not_activable',
			);

			if ( in_array( $status, $error_statuses, true ) ) {
				return 'error';
			}

			if ( 'valid' === $status ) {

				$expires = $this->woodev_license->expires;

				if ( ! empty( $expires ) && 'lifetime' !== $expires ) {

					$now        = current_time( 'timestamp' );
					$expiration = is_numeric( $expires ) ? (int) $expires : strtotime( $expires, $now );

					if ( $expiration && ( $expiration > $now ) && ( ( $expiration - $now ) < MONTH_IN_SECONDS ) ) {
						return 'warning';
					}
				}

				return 'success';
			}

			return 'info';
		}

		/**
		 * Gets the registered license engines keyed by (string) download id.
		 *
		 * @since 2.0.0
		 *
		 * @return array<string, Woodev_Plugins_License>
		 */
		public static function get_registered_instances(): array {
			return self::$registered_instances;
		}

		/**
		 * Resolves a registered license engine by its EDD download id.
		 *
		 * @since 2.0.0
		 *
		 * @param string $plugin_id The download id.
		 *
		 * @return Woodev_Plugins_License|null The engine, or null when unknown.
		 */
		public static function get_registered_instance( string $plugin_id ): ?Woodev_Plugins_License {
			return self::$registered_instances[ $plugin_id ] ?? null;
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

				$this->woodev_license->update(
					array(
						'license' => $license_data->license,
						'success' => $license_data->success,
						'error'   => $license_data->error,
						'expires' => $license_data->expires,
					)
				);

				if ( ! empty( $this->woodev_license->license ) && $this->woodev_license->success ) {

					$this->woodev_license->save( $license_data->get_response_data() );
					// Clean plugins cache
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
				_deprecated_argument( __METHOD__, '1.2.1', 'The "forced" parameter is not using anymore.' );
			}

			try {

				$license_data = $this->dispatch( 'check_license', trim( $license ) );

				if ( ! $license_data ) {
					throw new Exception( __( 'Cannot get license data. Please try again.', 'woodev-plugin-framework' ) );
				}

				$this->woodev_license->update(
					array(
						'license' => $license_data->license,
						'success' => $license_data->success,
						'error'   => $license_data->error,
						'expires' => $license_data->expires,
					)
				);

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

			if ( ! $this->plugin->is_need_license() ) {
				return;
			}

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

			if ( $this->plugin->is_need_license() && ( ! $this->woodev_license || 'valid' !== $this->woodev_license->license ) ) {
				echo '&nbsp;&nbsp;<strong><a href="' . $this->get_license_settings_url() . '" style="color: #aa0000;">' . __( 'Enter valid license key for automatic updates.', 'woodev-plugin-framework' ) . '</a></strong>';
			}

			if ( ! $response ) {
				return;
			}

			$new_version = false;

			if ( is_array( $response ) && array_key_exists( 'new_version', $response ) ) {
				$new_version = $response['new_version'];
			} elseif ( is_object( $response ) && isset( $response->new_version ) ) {
				$new_version = $response->new_version;
			}

			if ( ! $new_version ) {
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

			$update_notice  = '<div class="woodev-plugin-upgrade-notice">';
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
			return $this->woodev_license->data ?? null;
		}

		/**
		 * Whether the license is valid.
		 *
		 * @return bool
		 */
		public function is_license_valid() {
			if ( ! $this->is_license_required() ) {
				return true;
			}

			return ! empty( $this->license_key ) && $this->has_status( 'valid' );
		}

		/**
		 * Whether the site is using an active license.
		 *
		 * A true return carries three distinct meanings, all of which are
		 * acceptable for "active" (do not nag / do not block updates):
		 *
		 * 1. **Genuinely-active license** — a real key is present and its recorded
		 *    status is none of expired / disabled / invalid.
		 * 2. **Not-known-bad** — no failing status has been recorded yet (e.g. an
		 *    empty or unknown status, before the first verification round-trip);
		 *    the license is given the benefit of the doubt rather than treated as
		 *    inactive.
		 * 3. **License-free product per server authority** — is_license_required()
		 *    returns false (a verified server claim says this product needs no
		 *    license), so the question of activity does not apply and the method
		 *    short-circuits to true.
		 *
		 * This depends ONLY on is_license_required() (server authority), never on
		 * the local Woodev_Plugin::is_need_license() presentation flag (anti-pirate
		 * invariant). Behavior is unchanged — this docblock only documents it.
		 *
		 * @return bool
		 */
		public function is_active() {
			if ( ! $this->is_license_required() ) {
				return true;
			}

			return ! in_array(
				true,
				array(
					$this->is_expired(),
					$this->is_disabled(),
					$this->is_invalid(),
				),
				true
			);
		}

		/**
		 * Authoritative answer to whether this product requires a valid license.
		 *
		 * Returns true unless a VERIFIED server claim says it is license-free. Until
		 * signed claims are issued (see the S3.1 spec §4) this always returns true,
		 * so enforcement is byte-for-byte unchanged. The local Woodev_Plugin::is_need_license()
		 * flag does NOT influence this method (anti-pirate).
		 *
		 * @since 2.0.0
		 *
		 * @return bool
		 */
		public function is_license_required() {
			return true;
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
			return $this->has_statuses(
				array(
					'disabled',
					'revoked',
				)
			);
		}

		/**
		 * Whether the site is using an invalid license.
		 *
		 * @return bool
		 */
		public function is_invalid() {
			return $this->has_statuses(
				array(
					'invalid',
					'invalid_item_id',
					'item_name_mismatch',
					'key_mismatch',
				)
			);
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
