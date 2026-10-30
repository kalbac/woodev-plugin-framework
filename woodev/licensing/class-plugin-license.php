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
		 * The §4 signed-claim store backing is_license_required().
		 *
		 * Lazily constructed (get_authority_claims()) so unit tests that build the
		 * engine via newInstanceWithoutConstructor() still resolve a store on demand.
		 *
		 * @since 2.0.0
		 *
		 * @var Woodev_License_Authority_Claims|null
		 */
		private $authority_claims = null;

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
		private static $registered_instances = [];

		/**
		 * Download ids registered by MORE THAN ONE distinct plugin (§9.3).
		 *
		 * A colliding download id from a different plugin id keeps the FIRST
		 * registration and flags the id here; the command dispatcher then rejects
		 * commands targeting an ambiguous id with `unknown_plugin` (deterministic, no
		 * info leak). Keyed by download id → true.
		 *
		 * @since 2.0.0
		 *
		 * @var array<string, bool>
		 */
		private static $ambiguous_download_ids = [];

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
			// The API base URL is overridable via the `woodev_license_base_url` filter,
			// applied centrally in Woodev_Licensing_API::get_url() so BOTH the license
			// handler and the updater honor a single override point.
			$this->api_handler = new Woodev_Licensing_API( $this->plugin );

			$this->includes();
			$this->add_hooks();

			$this->register_instance();
		}

		/**
		 * Registers this engine in the by-download-id registry, flagging collisions.
		 *
		 * The FIRST registration for a download id wins. A second registration for the
		 * SAME download id from a DIFFERENT plugin id (§9.3 collision) keeps the first,
		 * records the id in self::$ambiguous_download_ids, and logs ONE error_log line
		 * — the command endpoint then treats that id as unknown_plugin. A re-register
		 * of the SAME plugin id (e.g. a reload) is not a collision.
		 *
		 * #916: since `download_id` became a required loader-definition field, the
		 * resolver ({@see \Woodev\Framework\Framework_Resolver::load_plugins()} and
		 * {@see \Woodev\Framework\Framework_Resolver::guard_activated_plugin()}) refuses to
		 * ever RUN a second plugin with an already-claimed download id in the first place —
		 * this §9.3 branch is defense in depth for whatever the resolver cannot see (a
		 * legacy v1 plugin sharing the id, or two v2 copies loaded through separate,
		 * non-framework registration paths), not the primary guard.
		 *
		 * @since 2.0.0
		 * @since 2.0.2 #905: a download id that has no store product (see
		 *              self::has_store_product()) is never flagged ambiguous and never
		 *              logged, even when two plugins share it: there is nothing to
		 *              license, so it is not a §9.3 collision. Registration itself is
		 *              unchanged — the first registration still wins and is still
		 *              returned by get_registered_instance()/get_registered_instances().
		 *
		 * @return void
		 */
		private function register_instance(): void {

			$download_id = (string) $this->plugin->get_download_id();

			if ( isset( self::$registered_instances[ $download_id ] ) ) {

				$existing    = self::$registered_instances[ $download_id ];
				$existing_id = $existing->plugin->get_id();
				$this_id     = $this->plugin->get_id();

				if ( $existing_id !== $this_id && self::has_store_product( $download_id ) && ! isset( self::$ambiguous_download_ids[ $download_id ] ) ) {

					self::$ambiguous_download_ids[ $download_id ] = true;

					error_log(
						sprintf(
							'Woodev license: download id %1$s is registered by two plugins (%2$s, %3$s); license commands for it will be rejected as unknown_plugin.',
							$download_id,
							$existing_id,
							$this_id
						)
					);
				}

				// First registration wins — never overwrite.
				return;
			}

			self::$registered_instances[ $download_id ] = $this;
		}

		/**
		 * Whether a download id denotes a real EDD store product (§9.3 predicate).
		 *
		 * `0`, `''`, and negative ids mean "this plugin has no store product" —
		 * there is nothing to license, so such an id is never a §9.3 collision and
		 * the command dispatcher never routes a signed command to it. Shared by
		 * register_instance() and Woodev_License_Command_Dispatcher::handle_envelope()
		 * so the two cannot drift (#905 round 2: the dispatcher used to trust that
		 * "the server never signs for id 0" instead of enforcing it).
		 *
		 * @since 2.0.2 #905
		 *
		 * @param mixed $download_id The download id — get_download_id() is untyped in
		 *                           the abstract, and a command payload's plugin_id
		 *                           arrives as a string.
		 *
		 * @return bool
		 */
		public static function has_store_product( $download_id ): bool {
			$id = (string) $download_id;

			return is_numeric( $id ) && (int) $id > 0;
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

			// Boot the woodev/v1 license-command REST controller (idempotent).
			// Registered unconditionally: server→client signed commands arrive via the
			// public REST endpoint regardless of WP auth state (§9.3 election).
			if ( class_exists( 'Woodev_REST_API_License_Command' ) ) {
				Woodev_REST_API_License_Command::boot();
			}

			// The command vocabulary needs no registration step: the dispatcher builds
			// its SEALED registry internally (get_commands(), holistic-round ruling).

			add_action( 'admin_notices', [ $this, 'notices' ] );

			// #1270: a stored key with no usable signed claim is refreshed promptly, not at the next weekly check.
			add_action( self::get_claim_refresh_hook( $this->plugin ), [ $this, 'run_claim_refresh' ] );
			add_action( 'admin_init', [ $this, 'refresh_claim_on_admin_init' ] );

			add_action( 'admin_print_scripts-plugins.php', [ $this, 'plugin_screen_scripts' ] );

			add_action(
				'in_plugin_update_message-' . plugin_basename( $this->plugin->get_plugin_file() ),
				[
					$this,
					'plugin_row_license_missing',
				],
				10,
				2
			);
		}

		/**
		 * Returns the plugin instance backing this license engine.
		 *
		 * Used by command handlers (e.g. Woodev_License_Command_Deactivate_Plugin)
		 * to resolve the plugin file and call plugin-level methods such as log().
		 *
		 * @since 2.0.0
		 *
		 * @return Woodev_Plugin
		 */
		public function get_plugin(): Woodev_Plugin {
			return $this->plugin;
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

			$statuses = [
				'missing'               => __( 'Лицензия не найдена', 'woodev-plugin-framework' ),
				'missing_url'           => __( 'URL сайта не передан', 'woodev-plugin-framework' ),
				'license_not_activable' => __( 'Это родительский ключ комплекта', 'woodev-plugin-framework' ),
				'disabled'              => __( 'Ключ отозван', 'woodev-plugin-framework' ),
				'revoked'               => __( 'Ключ отозван', 'woodev-plugin-framework' ),
				'no_activations_left'   => __( 'Лимит активаций исчерпан', 'woodev-plugin-framework' ),
				'expired'               => __( 'Срок лицензии истёк', 'woodev-plugin-framework' ),
				'key_mismatch'          => __( 'Ключ не подходит для этого плагина', 'woodev-plugin-framework' ),
				'invalid_item_id'       => __( 'Неверный идентификатор плагина', 'woodev-plugin-framework' ),
				'item_name_mismatch'    => __( 'Ключ не подходит для этого плагина', 'woodev-plugin-framework' ),
				'site_inactive'         => __( 'Сайт не активирован для этой лицензии', 'woodev-plugin-framework' ),
				'invalid'               => __( 'Неверный лицензионный ключ', 'woodev-plugin-framework' ),
				'valid'                 => __( 'Лицензия активна', 'woodev-plugin-framework' ),
			];

			return isset( $statuses[ $status_name ] ) ? $statuses[ $status_name ] : __( 'Неизвестный статус', 'woodev-plugin-framework' );
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
				[
					'activate_license',
					'deactivate_license',
					'check_license',
					'get_version',
				]
			) ) {
				return false;
			}

			// Data to send to the API.
			$api_params = [
				'edd_action' => $action,
				'license'    => $license_key,
				'item_id'    => $this->plugin->get_download_id(),
				'url'        => home_url(),
				'version'    => $this->plugin->get_version(),
			];

			// D-W3 carrier scope (holistic-round ruling): the weekly check_license
			// call and the updater get_version poll are the ONLY command/ack
			// carriers. activate_license / deactivate_license dispatches carry and
			// consume NOTHING — no ack field, no pull consumption, no ack drain.
			// (§4 claim consumption on activate is a SEPARATE machinery and lives in
			// activate() itself, not here.)
			$is_check_license = 'check_license' === self::strtolower( $action );

			// D-W3 / §9.5: attach any pending command acks to this request so the
			// server can clear its queue. The field is ABSENT when there is nothing
			// to send — the request shape must be byte-for-byte identical to the
			// pre-ack shape for the no-pending-acks case (EDD wire contract).
			// $sent_ack_nonces remembers exactly WHICH nonces this request carries:
			// the acks_received drain below may only confirm that set — ruled s8-p5
			// re-review #1, lost-ack protection §9.9.
			$ack_store        = ( $is_check_license && class_exists( 'Woodev_License_Command_Acks' ) ) ? new Woodev_License_Command_Acks() : null;
			$has_pending_acks = false;
			$sent_ack_nonces  = [];

			if ( null !== $ack_store ) {
				$pending = $ack_store->get_pending();
				if ( [] !== $pending ) {
					$api_params['consumed_command_nonces'] = $pending;
					$has_pending_acks                      = true;
					$sent_ack_nonces                       = array_values( array_filter( array_column( $pending, 'nonce' ), 'is_string' ) );
				}
			}

			try {

				$license_data = $this->api_handler->make_request( $api_params );

				if ( ! $license_data ) {
					throw new Exception( __( 'Cannot get license data', 'woodev-plugin-framework' ) );
				}

				// D-W3 / §3.2 pull-fallback: on EVERY successful check_license response,
				// parse it and consume any license_commands delivered in it (identical
				// §9.4 pipeline, minus HTTP gates 1–2). ONLY the acks_received
				// confirmation is skipped when no acks were sent. Inside the existing
				// try so a transport throw changes nothing (outage grace §3.2).
				// Containment catch — critic ruling s8-p5 #4b: a command-processing bug
				// must never break license validation; loud-but-contained (error_log),
				// never silent.
				try {
					if ( $is_check_license && ( class_exists( 'Woodev_License_Command_Dispatcher' ) || $has_pending_acks ) ) {
						// Single get_response_data() fetch reused by both hooks.
						$response_data = $license_data->get_response_data();

						if ( class_exists( 'Woodev_License_Command_Dispatcher' ) ) {
							Woodev_License_Command_Dispatcher::consume_pull_commands(
								$response_data,
								'pull',
								$ack_store
							);
						}

						// acks_received drain — ruled s8-p5 re-review #1: confirm ONLY the
						// intersection with the nonces THIS request actually sent; when
						// nothing was sent, skip entirely. A rogue/buggy response must
						// never clear an ack recorded while the request was in flight
						// (e.g. one just written by consume_pull_commands() above).
						if ( $has_pending_acks && null !== $ack_store ) {
							$acks_received = null;
							if ( is_object( $response_data ) ) {
								$acks_received = isset( $response_data->acks_received ) ? $response_data->acks_received : null;
							} elseif ( is_array( $response_data ) ) {
								$acks_received = $response_data['acks_received'] ?? null;
							}
							if ( is_array( $acks_received ) ) {
								$confirmed = array_values( array_intersect( array_filter( $acks_received, 'is_string' ), $sent_ack_nonces ) );
								if ( [] !== $confirmed ) {
									$ack_store->confirm_received( $confirmed );
								}
							}
						}
					}
				} catch ( \Throwable $throwable ) {
					// Ruled containment boundary (s8-p5 critic #4b) — loud-but-contained.
					error_log( 'Woodev licensing: pull-command/ack consumption failed: ' . \Woodev_API_Base::redact_secret_log_text( $throwable->getMessage(), [], true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- ruled loud-but-contained boundary (s8-p5 #4b).
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
			//
			// Accepted edge (reviewed 2026-06-18): the key option is persisted BEFORE the
			// store call, so if the user submits a DIFFERENT key and the store is unreachable
			// at that moment, the new (unverified) key is stored while the license DATA still
			// holds the previous key's 'valid' status — the card shows a "phantom valid". This
			// is the SAFE direction (an outage never deactivates) and self-heals on the next
			// successful verify / weekly check. Left as-is on purpose: the scenario (key change
			// AND simultaneous outage) is near-impossible and not worth breaking the key-write
			// ordering for. The genuinely-valid SAME-key outage case is regression-tested.
			update_option( $this->plugin->get_plugin_option_name( 'license_key' ), $license_key );
			$this->license_key = $license_key;

			// Always dispatch — do NOT short-circuit on the in-memory is_license_valid()
			// status of the PREVIOUS key. That early-return (legacy parity) silently kept
			// a stale "valid" status when the user submitted a different key, and blocked
			// «Проверить» from refreshing an expiry on an already-valid key.
			$license_data = $this->dispatch( 'activate_license', $license_key );

			if ( ! $license_data ) {
				throw new Woodev_Plugin_Exception( esc_html__( 'Не удалось получить данные лицензии. Попробуйте ещё раз.', 'woodev-plugin-framework' ) );
			}

			// Fetch the response payload once; it feeds both the §4 claim store and the
			// license-data save below (a single get_response_data() call — parity).
			$response_data = $license_data->get_response_data();

			// §4: consume any signed claim riding the activation response (post-dispatch
			// only; a dispatch() throw bypasses this — outage grace untouched).
			$this->get_authority_claims()->consume_from_response( $response_data );
			$this->reset_claim_refresh_backoff_if_claimed();

			// Clear the option for licensed extensions to force regeneration.
			if ( ! empty( $license_data->license ) && 'valid' === $license_data->license ) {
				delete_transient( 'woodev_extensions' );
			}

			$this->woodev_license->save( $response_data );

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
		 *     @type string $renewal_url     Renewal-checkout URL (edd_license_key + download_id).
		 * }
		 */
		public function get_state(): array {

			// Presentation status prefers the EDD `error` token over a generic
			// 'invalid' (e.g. activation-limit failures) — see get_display_status().
			$status = $this->woodev_license->get_display_status();

			// Built once and reused for both the message and the renewal URL so the
			// renewal-checkout link is a single source of truth (get_renewal_url()).
			$messages = new Woodev_License_Messages( $this->woodev_license );

			return [
				'plugin_id'       => (string) $this->plugin->get_download_id(),
				'plugin_name'     => $this->plugin->get_plugin_name(),
				'license_key'     => (string) $this->license_key,
				'status'          => $status,
				'status_label'    => '' === $status ? '' : $this->get_license_status( $status ),
				'message'         => wp_kses_post( $messages->get_message() ),
				'message_variant' => $this->get_message_variant(),
				// Raw expiry — a numeric timestamp stays numeric, a 'Y-m-d H:i:s'
				// string stays a string, ''/null stay as-is. Do NOT coerce the type:
				// the React app distinguishes lifetime / date / unknown by raw value.
				'expires'         => $this->woodev_license->expires,
				'is_valid'        => $this->is_license_valid(),
				'is_active'       => $this->is_active(),
				'is_need_license' => (bool) $this->plugin->is_need_license(),
				'beta_enabled'    => (bool) $this->plugin->is_beta_allowed(),
				// Additive (2.0.2): renewal-checkout URL for the «Продлить» button.
				'renewal_url'     => $messages->get_renewal_url(),
			];
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

			$status = $this->woodev_license->get_display_status();

			$error_statuses = [
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
			];

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
		 * Whether a download id was registered by more than one distinct plugin (§9.3).
		 *
		 * The command dispatcher rejects commands targeting an ambiguous id with
		 * `unknown_plugin` (deterministic, no info leak).
		 *
		 * @since 2.0.0
		 *
		 * @param string $id The download id.
		 *
		 * @return bool
		 */
		public static function is_download_id_ambiguous( string $id ): bool {
			return isset( self::$ambiguous_download_ids[ $id ] );
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
					[
						'license' => $license_data->license,
						'success' => $license_data->success,
						'error'   => $license_data->error,
						'expires' => $license_data->expires,
					]
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

				// A 200 whose JSON carries no licence token ({}, a maintenance object, a bare string, a list) says
				// nothing about this licence: treat it like an unusable answer and keep the last-known-good state
				// (#1460). Every definite EDD answer (valid, expired, invalid, ...) carries a `license` string.
				if ( ! is_string( $license_data->license ) || '' === $license_data->license ) {
					throw new Exception( __( 'Cannot get license data. Please try again.', 'woodev-plugin-framework' ) );
				}

				// §4: consume any signed claim riding this response. Only reached after a
				// successful dispatch — a dispatch() throw bypasses this, so outage grace
				// (§3.2) is preserved byte-for-byte. consume_from_response() swallows its
				// own Throwables, so it can never break the weekly check.
				$this->get_authority_claims()->consume_from_response( $license_data->get_response_data() );
				$this->reset_claim_refresh_backoff_if_claimed();

				$expires = $license_data->expires;

				// A valid answer that omits the expiry must not wipe the stored one (it would render as lifetime).
				if ( 'valid' === $license_data->license && null === $expires ) {
					$expires = $this->woodev_license->expires;
				}

				$this->woodev_license->update(
					[
						'license' => $license_data->license,
						'success' => $license_data->success,
						'error'   => $license_data->error,
						'expires' => $expires,
					]
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

			// Render any pending remote-deactivation notices (plan decision 10, §9.9 dedup).
			// MUST run BEFORE the is_need_license() early-return below: a license-free
			// surviving plugin still renders the deactivated plugin's notice (pinned by
			// LicenseCommandDeactivateTest::test_license_free_surviving_plugin_still_renders_foreign_notice).
			// The static per-request dedup guard inside makes the first caller do the one
			// option read + render pass; every later instance returns immediately (§9.9).
			if ( class_exists( 'Woodev_License_Command_Deactivate_Plugin' ) ) {
				Woodev_License_Command_Deactivate_Plugin::render_remote_deactivation_notices( $this );
			}

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
					[ 'notice_class' => "error {$this->item_shortname}-license-error" ]
				);
			}
		}

		/**
		 * Show plugin changes on the plugins screen.
		 *
		 * Hooked to `in_plugin_update_message-{$file}`. Per the WP contract the
		 * downloadable `package` and `new_version` live on the update RESPONSE object
		 * (2nd arg), NOT on the plugin-header array (1st arg). Reading `package` off
		 * the 1st arg meant this notice never rendered (OB-3 F8).
		 *
		 * @param array          $plugin_data Plugin-header data. Unused.
		 * @param stdClass|array $response    Plugin update response (carries package + new_version).
		 */
		public function plugin_row_license_missing( $plugin_data, $response ) {

			if ( ! $this->extract_update_field( $response, 'package' ) ) {
				return;
			}

			if ( $this->plugin->is_need_license() && ( ! $this->woodev_license || 'valid' !== $this->woodev_license->license ) ) {
				echo '&nbsp;&nbsp;<strong><a href="' . $this->get_license_settings_url() . '" style="color: #aa0000;">' . __( 'Enter valid license key for automatic updates.', 'woodev-plugin-framework' ) . '</a></strong>';
			}

			$new_version = $this->extract_update_field( $response, 'new_version' );

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

		/**
		 * Reads a field from a plugin-update response that may arrive as an object or array.
		 *
		 * WP core supplies the `in_plugin_update_message-{$file}` response as an
		 * stdClass; some callers pass an array. This normalizes both shapes.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed  $response Update response (object or array).
		 * @param string $field    Field name to read.
		 * @return mixed The field value, or null when absent.
		 */
		private function extract_update_field( $response, string $field ) {
			if ( is_array( $response ) && array_key_exists( $field, $response ) ) {
				return $response[ $field ];
			}

			if ( is_object( $response ) && isset( $response->$field ) ) {
				return $response->$field;
			}

			return null;
		}

		public function plugin_screen_scripts() {

			wp_enqueue_style(
				'woodev-plugins-table-style',
				$this->plugin->get_framework_assets_url() . '/css/admin/woodev-plugins-table' . $this->plugin->get_assets_suffix() . '.css'
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
				[
					$this->is_expired(),
					$this->is_disabled(),
					$this->is_invalid(),
				],
				true
			);
		}

		/**
		 * Authoritative answer to whether this product requires a valid license.
		 *
		 * Returns true UNLESS a cryptographically VERIFIED, site-bound, unexpired
		 * server claim says license_required === false. The §4 claim store
		 * (Woodev_License_Authority_Claims) is the sole authority: any doubt — no
		 * stored claim, a tampered/expired/wrong-site/wrong-plugin claim, or a missing
		 * sodium extension — yields a null verified payload and this method returns
		 * true (license required). The 14-day claim expiry IS the outage grace.
		 *
		 * The local Woodev_Plugin::is_need_license() presentation flag does NOT
		 * influence this method (anti-pirate invariant): a pirate cannot flip
		 * enforcement by toggling a client-side flag — only a server signature can.
		 *
		 * @since 2.0.0
		 *
		 * @return bool
		 */
		public function is_license_required() {

			$claim = $this->get_authority_claims()->get_verified();

			// verify_claim() guarantees a strict bool license_required on every verified
			// payload (non-bool claims are rejected wholesale, never stored). The
			// `?? true` + cast is belt-and-braces only: if the guarantee ever regressed,
			// the seam still defaults to locked.
			return null === $claim ? true : (bool) ( $claim['license_required'] ?? true );
		}

		/**
		 * Whether the server-signed statement says this plugin's licence is in force right now (#1270) —
		 * the ONE predicate every licence gate (checkout, carrier actions, notices) asks.
		 *
		 * True only when a VERIFIED stored claim for this site and plugin says the product needs no licence
		 * (`license_required === false`), or the licence is `valid` and not past its `license_expires`
		 * (`null` = lifetime). No claim, an unverifiable / tampered / wrong-site / wrong-plugin one, or an
		 * expired / disabled / invalid licence → false. A plugin with no store product at all (see
		 * {@see self::has_store_product()}) has nothing to license and is never gated.
		 *
		 * It deliberately reads neither the plain licence-status option ({@see self::is_license_valid()},
		 * {@see self::is_active()} — a forgeable option) nor the local {@see Woodev_Plugin::is_need_license()}
		 * flag: see gotcha `license-need-vs-required`. There is NO switch that turns it off.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function is_license_in_force(): bool {

			if ( ! self::has_store_product( $this->plugin->get_download_id() ) ) {
				return true;
			}

			return $this->get_authority_claims()->is_license_in_force();
		}

		/**
		 * The verified Unix time the licence ends at, or null when there is none to report (no verified
		 * claim, a licence-free product, or a lifetime licence). Feeds the «expires soon» reminder.
		 *
		 * @since 2.0.2
		 *
		 * @return int|null
		 */
		public function get_license_expires(): ?int {

			if ( ! self::has_store_product( $this->plugin->get_download_id() ) ) {
				return null;
			}

			return $this->get_authority_claims()->get_license_expires();
		}

		/**
		 * The waits between consecutive AUTOMATIC attempts to refresh a missing signed claim (#1270), by the
		 * number of attempts that already ended without a verified claim: 15 minutes after the first, then 1 h,
		 * 6 h and 24 h — and 24 h from then on. A licence server that is down, erroring or unable to sign (an
		 * old plugin version stays installed for years) is therefore asked at most once a day per site and
		 * plugin once the ramp is over.
		 *
		 * @since 2.0.2
		 *
		 * @var int[]
		 */
		public const CLAIM_REFRESH_BACKOFF = [
			15 * MINUTE_IN_SECONDS,
			HOUR_IN_SECONDS,
			6 * HOUR_IN_SECONDS,
			DAY_IN_SECONDS,
		];

		/**
		 * The suffix of the plugin option holding the backoff state (`failures`, `next_at`). A regular option
		 * (autoload off), not a transient: a site whose object cache does not persist must not forget it.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const CLAIM_REFRESH_STATE_OPTION = 'claim_refresh_state';

		/**
		 * The WP-Cron hook of one plugin's immediate claim refresh.
		 *
		 * @since 2.0.2
		 *
		 * @param Woodev_Plugin $plugin the plugin.
		 * @return string
		 */
		public static function get_claim_refresh_hook( Woodev_Plugin $plugin ): string {
			return 'woodev_' . $plugin->get_id() . '_refresh_license_claim';
		}

		/**
		 * The current Unix time — a seam so a test can move the clock.
		 *
		 * @since 2.0.2
		 *
		 * @return int
		 */
		protected function now(): int {
			return time();
		}

		/**
		 * Whether a licence key is stored but the signed claim is missing — absent, unverifiable, or past its
		 * own 14 days — so the gate is closed for a reason a `check_license` round-trip can lift (#1270).
		 *
		 * Deliberately FALSE when a verified claim exists, whatever it says: a signed `expired` / `invalid`
		 * statement is the server's answer, refreshed on the normal weekly path, not hammered. Also false for
		 * a plugin with no store product and for one with no key (nothing to check).
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function needs_claim_refresh(): bool {

			if ( ! self::has_store_product( $this->plugin->get_download_id() ) || ! $this->get_license() ) {
				return false;
			}

			return null === $this->get_authority_claims()->get_verified();
		}

		/**
		 * The stored backoff state, normalised.
		 *
		 * @since 2.0.2
		 *
		 * @return array{failures: int, next_at: int}
		 */
		private function get_claim_refresh_state(): array {
			return $this->normalize_claim_refresh_state( get_option( $this->plugin->get_plugin_option_name( self::CLAIM_REFRESH_STATE_OPTION ), [] ) );
		}

		/**
		 * Normalises a stored backoff state of any shape to the two integers.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $state the stored value.
		 * @return array{failures: int, next_at: int}
		 */
		private function normalize_claim_refresh_state( $state ): array {
			return [
				'failures' => is_array( $state ) ? max( 0, (int) ( $state['failures'] ?? 0 ) ) : 0,
				'next_at'  => is_array( $state ) ? max( 0, (int) ( $state['next_at'] ?? 0 ) ) : 0,
			];
		}

		/**
		 * Whether the shared automatic-refresh budget is open: no attempt is owed a wait any more.
		 *
		 * Reading it spends nothing — the buyer's request uses it to decide whether queueing a cron event is
		 * worth it, the budget itself is spent only by an attempt ({@see self::take_claim_refresh_budget()}).
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		private function is_claim_refresh_window_open(): bool {
			return $this->now() >= $this->get_claim_refresh_state()['next_at'];
		}

		/**
		 * Spends this plugin's ONE automatic-refresh budget, shared by the buyer-queued cron run and the
		 * manager's `admin_init` attempt: true when the window is open, and then the next window — one step
		 * longer on the {@see self::CLAIM_REFRESH_BACKOFF} ladder — is written BEFORE the caller goes to the
		 * network, so an attempt that dies mid-request still counts.
		 *
		 * Taking is ATOMIC: the state row is read raw from the database (never the object cache) and replaced
		 * by a compare-and-swap `UPDATE … WHERE option_value = <what was read>` — or, while no row exists yet,
		 * by an `INSERT IGNORE` — so of N requests that read the same open window exactly one sees a changed
		 * row and proceeds; the rest get false. No transient and no object cache is involved.
		 *
		 * A pending cron event holds nothing: a buyer only reads the window, so an event that cannot run
		 * (WP-Cron off, no system cron) can never keep the manager's own attempt waiting.
		 *
		 * @since 2.0.2
		 *
		 * @return bool whether the caller may call the licence server now.
		 */
		private function take_claim_refresh_budget(): bool {

			global $wpdb;

			$name  = $this->plugin->get_plugin_option_name( self::CLAIM_REFRESH_STATE_OPTION );
			$now   = $this->now();
			$ladder = self::CLAIM_REFRESH_BACKOFF;

			// No database handle (a bare CLI bootstrap): the best remaining effort is the plain, non-atomic write.
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
				$state = $this->get_claim_refresh_state();

				if ( $now < $state['next_at'] ) {
					return false;
				}

				$failures = $state['failures'] + 1;

				update_option(
					$name,
					[
						'failures' => $failures,
						'next_at' => $now + $ladder[ min( $failures, count( $ladder ) ) - 1 ],
					],
					false
				);

				return true;
			}

			$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
			$state = $this->normalize_claim_refresh_state( null === $raw ? [] : maybe_unserialize( $raw ) );

			if ( $now < $state['next_at'] ) {
				return false;
			}

			$failures = $state['failures'] + 1;
			$next     = maybe_serialize(
				[
					'failures' => $failures,
					'next_at' => $now + $ladder[ min( $failures, count( $ladder ) ) - 1 ],
				]
			);

			if ( null === $raw ) {
				// INSERT IGNORE, not add_option(): add_option() ends in ON DUPLICATE KEY UPDATE, which overwrites a racer's row.
				$won = 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $next ) );
			} else {
				$won = 1 === (int) $wpdb->update(
					$wpdb->options,
					[ 'option_value' => $next ],
					[
						'option_name' => $name,
						'option_value' => $raw,
					]
				);
			}

			// Later reads (this request and, with a persistent cache, others) must see the database, not a stale copy.
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );

			return $won;
		}

		/**
		 * Forgets the backoff once a verified claim is stored — by an automatic attempt or by a human action
		 * (activating or saving a key, «Проверить», the weekly check). Does nothing while the claim is missing.
		 *
		 * @internal Public so the updater, which also receives signed claims, can reset it; not an extension point.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function reset_claim_refresh_backoff_if_claimed(): void {

			if ( null !== $this->get_authority_claims()->get_verified() ) {
				delete_option( $this->plugin->get_plugin_option_name( self::CLAIM_REFRESH_STATE_OPTION ) );
			}
		}

		/**
		 * Schedules ONE immediate WP-Cron refresh of the signed claim when {@see self::needs_claim_refresh()}
		 * and the automatic-refresh window is open (#1270).
		 *
		 * Safe in a buyer's request: it writes a cron entry and does no HTTP — the licence server is called by
		 * {@see self::run_claim_refresh()} in the cron request, which spends the shared budget. A pending event
		 * is left alone and spends nothing; the manager's own attempt
		 * ({@see self::refresh_claim_on_admin_init()}) draws from the same budget but is never held by the
		 * event, so buyer traffic can never starve it.
		 *
		 * @since 2.0.2
		 *
		 * @return bool whether an event was scheduled by this call.
		 */
		public function schedule_claim_refresh(): bool {

			if ( ! $this->needs_claim_refresh() || ! $this->is_claim_refresh_window_open() ) {
				return false;
			}

			$hook = self::get_claim_refresh_hook( $this->plugin );

			if ( wp_next_scheduled( $hook ) ) {
				return false;
			}

			return false !== wp_schedule_single_event( $this->now(), $hook );
		}

		/**
		 * The scheduled refresh: one `check_license` round-trip, whose signed claim (if any) lands in the claim
		 * store. A no-op when the claim has arrived in the meantime or when the shared budget is spent (the
		 * manager's attempt used the window first); a transport failure is swallowed — the backoff, not a retry
		 * loop, decides when the next attempt comes.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function run_claim_refresh(): void {

			if ( ! $this->needs_claim_refresh() || ! $this->take_claim_refresh_budget() ) {
				return;
			}

			$this->attempt_claim_refresh();
		}

		/**
		 * One licence-server round-trip for a missing claim; the budget is already spent by the caller.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		private function attempt_claim_refresh(): void {

			try {
				$this->validate_license( (string) $this->get_license() );
			} catch ( \Throwable $throwable ) {
				return;
			}

			$this->reset_claim_refresh_backoff_if_claimed();
		}

		/**
		 * `admin_init`: a store manager's own request refreshes a missing claim at once (the cron entry may
		 * not run on a site with WP-Cron off) when the shared automatic-refresh window is open, whether or not
		 * a cron event is pending. After a successful refresh the now-pointless pending event is removed.
		 * Never for a buyer, an AJAX heartbeat or a cron run.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function refresh_claim_on_admin_init(): void {

			if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			if ( ! $this->needs_claim_refresh() || ! $this->take_claim_refresh_budget() ) {
				return;
			}

			$this->attempt_claim_refresh();

			if ( ! $this->needs_claim_refresh() ) {
				wp_clear_scheduled_hook( self::get_claim_refresh_hook( $this->plugin ) );
			}
		}

		/**
		 * Gets the §4 signed-claim store for this engine (lazily constructed).
		 *
		 * @since 2.0.0
		 *
		 * @return Woodev_License_Authority_Claims
		 */
		public function get_authority_claims(): Woodev_License_Authority_Claims {

			if ( null === $this->authority_claims ) {
				$this->authority_claims = new Woodev_License_Authority_Claims( $this->plugin );
			}

			return $this->authority_claims;
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
				[
					'disabled',
					'revoked',
				]
			);
		}

		/**
		 * Whether the site is using an invalid license.
		 *
		 * @return bool
		 */
		public function is_invalid() {
			return $this->has_statuses(
				[
					'invalid',
					'invalid_item_id',
					'item_name_mismatch',
					'key_mismatch',
				]
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
		private function has_statuses( $statuses = [] ) {
			return in_array( $this->woodev_license->license, $statuses, true );
		}
	}

endif;
