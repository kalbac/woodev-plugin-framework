<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Plugin' ) ) :

	/**
	 * # Woodev Plugin Framework
	 *
	 * This framework class provides a base level of configurable and overrideable
	 * functionality and features suitable for the implementation of a WooCommerce
	 * plugin.  This class handles all the "non-feature" support tasks such
	 * as verifying dependencies are met, loading the text domain, etc.
	 *
	 * @version 2.0.1
	 */
	abstract class Woodev_Plugin {

		/** Plugin Framework Version */
		const VERSION = '2.0.1';

		/** @var object single instance of plugin */
		protected static $instance;

		/** @var string plugin id */
		private $id;

		/** @var string version number */
		private $version;

		/** @var string plugin path, without trailing slash */
		private $plugin_path;

		/** @var string plugin URL */
		private $plugin_url;

		/** @var string template path, without trailing slash */
		private $template_path;

		/** @var Woodev_Plugins_License instance */
		protected $license;

		/** @var  Woodev_Admin_Message_Handler instance */
		private $message_handler;

		/** @var string the plugin text domain */
		private $text_domain;

		/** @var array memoized list of active plugins */
		private $active_plugins = array();

		/** @var Woodev_Plugin_Dependencies dependency handler instance */
		private $dependency_handler;

		/** @var Woodev_Hook_Deprecator hook deprecator instance */
		private $hook_deprecator;

		/** @var Woodev_Lifecycle lifecycle handler instance */
		protected $lifecycle_handler;

		/** @var Woodev_Admin_Notice_Handler the admin notice handler class */
		private $admin_notice_handler;

		/** @var Woodev_REST_API REST API handler instance */
		protected $rest_api_handler;

		/** @var Woodev_Plugin_Setup_Wizard handler instance */
		protected $setup_wizard_handler;

		/**
		 * Blocks handler instance.
		 *
		 * Null for plugins that do not opt in to a blocks handler (i.e. any
		 * pure-WordPress plugin not extending \Woodev\Framework\Woocommerce_Plugin). Only
		 * WooCommerce plugins initialize this via init_blocks_handler().
		 *
		 * @var Woodev_Blocks_Handler|null
		 */
		protected ?Woodev_Blocks_Handler $blocks_handler = null;

		/** @var \Woodev\Framework\Handlers\Translation_Handler translation handler instance */
		protected \Woodev\Framework\Handlers\Translation_Handler $translation_handler;

		/** @var \Woodev\Framework\Handlers\Cron_Handler cron handler instance */
		protected \Woodev\Framework\Handlers\Cron_Handler $cron_handler;

		/**
		 * Initialize the plugin.
		 *
		 * Child plugin classes may add their own optional arguments.
		 *
		 * @since 1.0.0
		 *
		 * @param string $id plugin id
		 * @param string $version plugin version number
		 * @param array{
		 *     text_domain?: string,
		 *     dependencies?: array{
		 *          php_extensions?: array<string, mixed>,
		 *          php_functions?: array<string, mixed>,
		 *          php_settings?: array<string, mixed>
		 *     }
		 *  } $args
		 */
		public function __construct( string $id, string $version, array $args = [] ) {

			// required params
			$this->id      = $id;
			$this->version = $version;

			$args = wp_parse_args(
				$args,
				[
					'text_domain'  => '',
					'dependencies' => [],
				]
			);

			$this->text_domain = $args['text_domain'];

			$this->includes();
			// initialize the dependencies manager
			$this->init_dependencies( $args['dependencies'] );

			// build the admin message handler instance
			$this->init_admin_message_handler();

			// build the admin notice handler instance
			$this->init_admin_notice_handler();

			// build the license handler instance
			$this->init_license_handler();

			// build the hook deprecator instance
			$this->init_hook_deprecator();

			// build the lifecycle handler instance
			$this->init_lifecycle_handler();

			// build the translation handler instance
			$this->init_translation_handler();

			// build the cron handler instance
			$this->init_cron_handler();

			// build the REST API handler instance
			$this->init_rest_api_handler();

			// build the setup handler instance
			$this->init_setup_wizard_handler();

			// load the admin settings pages
			$this->load_admin_pages();

			// add the action & filter hooks
			$this->add_hooks();
		}

		/**
		 * Initializes the plugin dependency handler.
		 *
		 * @param array $dependencies {
		 *     PHP extension, function, and settings dependencies
		 *
		 * @type array $php_extensions PHP extension dependencies
		 * @type array $php_functions PHP function dependencies
		 * @type array $php_settings PHP settings dependencies
		 * }
		 */
		protected function init_dependencies( $dependencies ) {
			$this->dependency_handler = new Woodev_Plugin_Dependencies( $this, $dependencies );
		}

		/**
		 * Builds the admin message handler instance.
		 *
		 * Plugins can override this with their own handler.
		 */
		protected function init_admin_message_handler() {
			$this->message_handler = new Woodev_Admin_Message_Handler( $this->get_id() );
		}

		/**
		 * Builds the admin notice handler instance.
		 *
		 * Plugins can override this with their own handler.
		 */
		protected function init_admin_notice_handler() {
			$this->admin_notice_handler = new Woodev_Admin_Notice_Handler( $this );
		}

		/**
		 * Initialize Woodev admin pages
		 *
		 * @access private
		 * @return void
		 */
		private function load_admin_pages() {
			if ( is_admin() && ! class_exists( 'Woodev_Admin_Pages' ) ) {
				$admin_pages = $this->load_class( '/woodev/admin/class-admin-pages.php', 'Woodev_Admin_Pages' );
				$admin_pages->instance( $this );
			}
		}

		/**
		 * Builds the hook deprecator instance.
		 *
		 * Plugins can override this with their own handler.
		 */
		protected function init_hook_deprecator() {
			$this->hook_deprecator = new Woodev_Hook_Deprecator( $this->get_plugin_name(), array_merge( $this->get_framework_deprecated_hooks(), $this->get_deprecated_hooks() ) );
		}

		/**
		 * Builds the lifecycle handler instance.
		 *
		 * Plugins can override this with their own handler to perform install and
		 * upgrade routines.
		 */
		protected function init_lifecycle_handler() {
			$this->lifecycle_handler = new Woodev_Lifecycle( $this );
		}

		/**
		 * Builds the translation handler instance.
		 *
		 * The handler registers its own `init` hook and loads both the framework
		 * and the plugin text domains.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		protected function init_translation_handler(): void {
			$this->translation_handler = new \Woodev\Framework\Handlers\Translation_Handler( $this );
		}

		/**
		 * Builds the cron handler instance.
		 *
		 * The handler registers the `weekly` schedule, the
		 * `woodev_weekly_scheduled_events` event, the weekly license check, and
		 * the `wp_ajax_woodev_verify_license` AJAX action.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		protected function init_cron_handler(): void {
			$this->cron_handler = new \Woodev\Framework\Handlers\Cron_Handler( $this );
		}

		/**
		 * Builds the REST API handler instance.
		 *
		 * Plugins can override this to add their own data and/or routes.
		 */
		protected function init_rest_api_handler() {
			$this->rest_api_handler = new Woodev_REST_API( $this );
		}

		/**
		 * Builds the blocks handler instance.
		 *
		 * @since 1.3.2
		 *
		 * @return void
		 */
		protected function init_blocks_handler(): void {

			require_once $this->get_framework_path() . '/handlers/blocks-handler.php';

			// individual plugins should initialize their block integrations handler by overriding this method
			$this->blocks_handler = new Woodev_Blocks_Handler( $this );
		}

		/**
		 * Builds the Setup Wizard handler instance.
		 *
		 * Plugins can override and extend this method to add their own setup wizard.
		 */
		protected function init_setup_wizard_handler() {
			require_once $this->get_framework_path() . '/admin/abstract-plugin-admin-setup-wizard.php';
		}

		private function add_hooks() {

			// initialize the plugin
			add_action( 'plugins_loaded', array( $this, 'init_plugin' ), 15 );

			// initialize the plugin admin
			add_action( 'admin_init', array( $this, 'init_admin' ), 0 );

			// Load plugin updater
			add_action( 'init', array( $this, 'load_updater' ) );

			// Register the woodev/v1 extensions catalog REST controller. Idempotent;
			// must boot in all contexts because REST requests are not is_admin().
			Woodev_REST_API_Extensions::boot();

			// Register the woodev/v1 account disconnect REST controller (idempotent).
			Woodev_REST_API_Account::boot();

			add_action( 'wp_enqueue_scripts', [ $this, 'frontend_enqueue_scripts' ] );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

			// add the admin notices
			add_action( 'admin_notices', array( $this, 'add_admin_notices' ) );
			add_action( 'admin_footer', array( $this, 'add_delayed_admin_notices' ) );

			// add a 'Configure' link to the plugin action links
			add_filter(
				'plugin_action_links_' . plugin_basename( $this->get_plugin_file() ),
				array(
					$this,
					'plugin_action_links',
				)
			);

			// automatically log HTTP requests from Woodev_API_Base
			$this->add_api_request_logging();
		}

		/**
		 * Cloning instances is forbidden due to singleton pattern.
		 */
		public function __clone() {
			_doing_it_wrong( __FUNCTION__, sprintf( 'You cannot clone instances of %s.', esc_html( $this->get_plugin_name() ) ), '1.1.0' );
		}

		/**
		 * Unserializing instances is forbidden due to singleton pattern.
		 */
		public function __wakeup() {
			_doing_it_wrong( __FUNCTION__, sprintf( 'You cannot unserialize instances of %s.', esc_html( $this->get_plugin_name() ) ), '1.1.0' );
		}

		/**
		 * Builds the plugin license instance.
		 *
		 * @return void
		 */
		protected function init_license_handler() {

			if ( ! $this->license ) {
				$this->license = new Woodev_Plugins_License( $this );
			}
		}

		/**
		 * Initialize the Plugin Updater
		 *
		 * @return void
		 */
		public function load_updater() {

			// B-3: the updater is the §4 claim transport for keyless products, so it is
			// constructed UNCONDITIONALLY in any admin / cron / WP-CLI context — no
			// license-key gate. The keyless get_version poll carries signed claims +
			// license_commands. The cron branch is new (was admin/CLI only). This gate
			// MUST stay expression-identical to the updater require gate in includes()
			// (wp_doing_cron() is filterable — a constant-based require with a
			// filter-based gate here would fatal on an unloaded class; the
			// UpdaterKeylessPollingTest source assertion pins the parity).
			if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return;
			}

			$license_key = $this->get_license_instance()->get_license();

			$this->construct_updater();

			// PUBLIC HOOK CONTRACT (byte-for-byte): the woodev_plugin_updater action
			// still fires with the license-key arg exactly as before.
			do_action( 'woodev_plugin_updater', $license_key );
		}

		/**
		 * Constructs the plugin updater.
		 *
		 * Extracted as a seam so load_updater()'s keyless construction decision is unit
		 * testable without newing up the real Woodev_Licensing_API HTTP stack.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		protected function construct_updater(): void {
			new Woodev_Plugin_Updater( $this );
		}

		/**
		 * Initializes the plugin.
		 *
		 * Plugins can override this to set up any handlers after WordPress is ready.
		 */
		public function init_plugin() {}

		/**
		 * Initializes the plugin admin.
		 *
		 * Plugins can override this to set up any handlers after the WordPress admin is ready.
		 */
		public function init_admin() {}

		/**
		 * Enqueue plugin scripts on frontend
		 */
		public function frontend_enqueue_scripts() {

			wp_register_script( 'jquery-suggestions', $this->get_framework_assets_url() . '/js/frontend/jquery.suggestions.js', [ 'jquery' ], '22.6.0' );
			wp_register_script( 'woodev-dadata-suggestions', $this->get_framework_assets_url() . '/js/frontend/woodev-dadata-suggestions.js', [ 'jquery-suggestions' ], self::VERSION );
		}

		/**
		 * Enqueue plugin scripts on plugin settings page
		 */
		public function enqueue_scripts() {

			if ( $this->is_plugin_settings() ) {

				wp_enqueue_style( 'jquery-confirm', $this->get_framework_assets_url() . '/css/admin/jquery-confirm.min.css', array(), '3.3.2' );
				wp_enqueue_style( 'font-awesome', $this->get_framework_assets_url() . '/css/admin/font-awesome.min.css', null, '4.7.0' );
				wp_enqueue_style( 'admin-confirm', $this->get_framework_assets_url() . '/css/admin/admin-confirm.css', null, $this->get_version() );

				wp_register_script( 'jquery-confirm', $this->get_framework_assets_url() . '/js/admin/jquery.jquery-confirm.min.js', array( 'jquery' ), '3.3.2', false );
				wp_enqueue_script( 'woodev-admin-script', $this->get_framework_assets_url() . '/js/admin/woodev-admin-script.js', array( 'jquery-confirm' ), $this->get_version() );

				wp_localize_script( 'woodev-admin-script', 'woodev_admin_strings', $this->get_admin_js_strings() );
			}
		}

		protected function get_admin_js_strings() {
			return array(
				'admin_url'        => admin_url( 'admin-ajax.php', 'relative' ),
				'license_prompt'   => esc_html__( sprintf( 'Для использования плагина <strong>"%s"</strong>, вам необходимо активировать вашу лицензию для этого сайта', $this->get_plugin_name() ) ),
				'enter_license'    => esc_html__( 'Указать лицензию' ),
				'close'            => esc_html__( 'Закрыть' ),
				'admin_nonce'      => wp_create_nonce( 'woodev-admin' ),
				'load_error_text'  => esc_html__( 'Во время загрузки данных о лицензии произошла ошибка. Закройте это окно и попробуйте снова.' ),
				'license_page_url' => esc_url( $this->get_license_instance()->get_license_settings_url() ),
			);
		}

		private function includes() {

			$framework_path = $this->get_framework_path();

			// common exception class
			require_once $framework_path . '/class-plugin-exception.php';

			// Settings API
			require_once $framework_path . '/settings-api/abstract-class-settings.php';
			require_once $framework_path . '/settings-api/class-setting.php';
			require_once $framework_path . '/settings-api/class-control.php';
			require_once $framework_path . '/settings-api/register-settings/class-register-settings.php';
			require_once $framework_path . '/settings-api/register-settings/class-register-settings-fields.php';

			// common utility methods
			require_once $framework_path . '/class-helper.php';
			require_once $framework_path . '/admin/class-notes-helper.php';

			require_once $framework_path . '/compatibility/class-plugin-compatibility.php';
			require_once $framework_path . '/compatibility/class-order-compatibility.php';

			// generic API base
			require_once $framework_path . '/api/class-api-exception.php';
			require_once $framework_path . '/api/class-api-base.php';
			require_once $framework_path . '/api/interface-api-request.php';
			require_once $framework_path . '/api/interface-api-response.php';

			// XML API base
			require_once $framework_path . '/api/abstract-api-xml-request.php';
			require_once $framework_path . '/api/abstract-api-xml-response.php';

			// JSON API base
			require_once $framework_path . '/api/abstract-api-json-request.php';
			require_once $framework_path . '/api/abstract-api-json-response.php';

			// Cacheable API
			require_once $framework_path . '/api/traits/cacheable-request-trait.php';
			require_once $framework_path . '/api/abstract-cacheable-api-base.php';

			// Packer base
			require_once $framework_path . '/box-packer/class-packer-exception.php';
			require_once $framework_path . '/box-packer/interfaces/interface-packer-item.php';
			require_once $framework_path . '/box-packer/interfaces/interface-packer-item-with-product.php';
			require_once $framework_path . '/box-packer/interfaces/interface-packer-box.php';
			require_once $framework_path . '/box-packer/interfaces/interface-packer.php';

			require_once $framework_path . '/box-packer/abstract-class-packer.php';
			require_once $framework_path . '/box-packer/class-item-implementation.php';
			require_once $framework_path . '/box-packer/class-box-implementation.php';
			require_once $framework_path . '/box-packer/class-packed-box.php';
			require_once $framework_path . '/box-packer/class-packer-boxes.php';
			require_once $framework_path . '/box-packer/class-packages-weight.php';
			require_once $framework_path . '/box-packer/class-packer-separatly.php';
			require_once $framework_path . '/box-packer/class-packer-single-box.php';
			require_once $framework_path . '/box-packer/class-packer-virtual-box.php';

			// Packer dispatcher + input/output contracts (platform-neutral).
			require_once $framework_path . '/box-packer/interfaces/interface-packer-packable-item.php';
			require_once $framework_path . '/box-packer/class-packer-input-item.php';
			require_once $framework_path . '/box-packer/class-packer-package-result.php';
			require_once $framework_path . '/box-packer/class-packer-result.php';
			require_once $framework_path . '/box-packer/class-packer-dispatcher.php';

			// WooCommerce-aware dispatcher (cart/order input conversion). Loaded only when WooCommerce is active.
			if ( Woodev_Helper::is_woocommerce_active() ) {
				require_once $framework_path . '/box-packer/class-wc-packer-dispatcher.php';
			}

			require_once $framework_path . '/utilities/class-woodev-async-request.php';
			require_once $framework_path . '/utilities/class-woodev-background-job-handler.php';
			require_once $framework_path . '/utilities/class-woodev-job-batch-handler.php';

			// REST API Controllers
			require_once $framework_path . '/rest-api/controllers/class-plugin-rest-api-settings.php';

			// Handlers
			require_once $framework_path . '/handlers/script-handler.php';
			require_once $framework_path . '/handlers/class-translation-handler.php';
			require_once $framework_path . '/handlers/class-cron-handler.php';
			require_once $framework_path . '/class-woodev-plugin-dependencies.php';
			require_once $framework_path . '/class-woodev-hook-deprecator.php';
			require_once $framework_path . '/class-admin-message-handler.php';
			require_once $framework_path . '/class-admin-notice-handler.php';
			require_once $framework_path . '/class-lifecycle.php';
			require_once $framework_path . '/rest-api/class-plugin-rest-api.php';

			// Load the shared Ed25519 envelope verifier + site-normalization primitive.
			// Unconditional and BEFORE the licensing block: the §4 claim store and the
			// §3.4.1 command dispatcher both consume these (gotcha framework/includes-wiring).
			// Order matters: the function file defines woodev_normalize_site() that the
			// verifier's callers rely on, so it is required before the verifier class.
			require_once $framework_path . '/functions-license-authority.php';
			require_once $framework_path . '/licensing/class-license-envelope-verifier.php';

			// Load plugin license classes
			require_once $framework_path . '/licensing/api/class-licensing-api.php';
			require_once $framework_path . '/licensing/api/class-licensing-api-request.php';
			require_once $framework_path . '/licensing/api/class-licensing-api-response.php';
			require_once $framework_path . '/licensing/class-license-messages.php';
			require_once $framework_path . '/licensing/class-license-store.php';

			// The §4 signed-claim store sits BEHIND is_license_required(); require it
			// within the licensing block BEFORE the license engine that consumes it
			// (gotcha framework/includes-wiring). It depends on the envelope verifier +
			// woodev_normalize_site(), both required above.
			require_once $framework_path . '/licensing/class-license-authority-claims.php';
			require_once $framework_path . '/licensing/class-plugin-license.php';

			// The §3.4.1 signed-command core: the atomic nonce store + the
			// transport-neutral dispatcher. Unconditional and after the licensing
			// block — the dispatcher resolves engines via Woodev_Plugins_License and
			// the REST endpoint (s8-p3) / pull-fallback (s8-p5) both feed it, neither
			// of which is admin- or WooCommerce-gated (gotcha framework/includes-wiring).
			require_once $framework_path . '/licensing/class-license-command-nonce-store.php';
			require_once $framework_path . '/licensing/class-license-command-dispatcher.php';

			// The §9.5 durable structured ack store (pull-fallback delivery + ack drain).
			// Site-level; any surviving plugin drains the store on its next scheduled call.
			// Unconditional and within the licensing block — both transports write acks
			// (gotcha framework/includes-wiring).
			require_once $framework_path . '/licensing/class-license-command-acks.php';

			// v1 command vocabulary — interface BEFORE implementation (gotcha framework/includes-wiring).
			// Both are unconditional: the dispatcher is REST-reachable and pull-reachable regardless
			// of admin/WC context, so a gated require would leave the vocabulary unregistered.
			require_once $framework_path . '/licensing/commands/interface-license-command.php';
			require_once $framework_path . '/licensing/commands/class-license-command-deactivate-plugin.php';

			// Load the woodev/v1 REST namespace registrar + the license REST controller.
			// Unconditional: REST requests are neither admin nor WooCommerce-gated, so an
			// admin/WC-only require would leave these unwired and fatal on a REST request.
			require_once $framework_path . '/rest-api/class-rest-v1-registrar.php';
			require_once $framework_path . '/licensing/api/class-rest-api-license.php';
			require_once $framework_path . '/licensing/api/class-rest-api-license-command.php';
			require_once $framework_path . '/rest-api/controllers/class-rest-api-extensions.php';

			// Account-connection client (Phase B). Signer before the connection that
			// uses it; the disconnect REST controller registers via the woodev/v1
			// registrar. Unconditional: the disconnect route is REST-reachable
			// (neither admin- nor WC-gated) — gotcha framework/includes-wiring.
			require_once $framework_path . '/account/class-account-signer.php';
			require_once $framework_path . '/account/class-account-connection.php';
			require_once $framework_path . '/account/class-installed-plugins.php';
			require_once $framework_path . '/account/class-account-purchases.php';
			require_once $framework_path . '/account/class-account-installer.php';
			require_once $framework_path . '/rest-api/controllers/class-rest-api-account.php';

			// Load plugin updater class. The condition is EXPRESSION-IDENTICAL to the
			// load_updater() gate (B-3): wp_doing_cron() is filterable, so a
			// constant-based require here with a filter-based gate there could construct
			// Woodev_Plugin_Updater on an unloaded class → production fatal (the test
			// classmap masks it — gotcha framework/includes-wiring). The
			// UpdaterKeylessPollingTest source assertion pins this parity.
			if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				require_once $framework_path . '/licensing/updater/class-plugin-updater.php';
			}
		}

		/**
		 * Gets a list of framework deprecated/removed hooks.
		 *
		 * @return array associative array
		 * @see Woodev_Plugin::get_deprecated_hooks()
		 *
		 * @see Woodev_Plugin::init_hook_deprecator()
		 */
		private function get_framework_deprecated_hooks() {

			$plugin_id          = $this->get_id();
			$deprecated_hooks   = array();
			$deprecated_filters = array(
				/** @see Woodev_Payment_Gateway_My_Payment_Methods handler - once migrated to WC core tokens UI, we removed these and have no replacement */
				"wc_{$plugin_id}_my_payment_methods_table_html",
				"wc_{$plugin_id}_my_payment_methods_table_head_html",
				"wc_{$plugin_id}_my_payment_methods_table_title",
				"wc_{$plugin_id}_my_payment_methods_table_title_html",
				"wc_{$plugin_id}_my_payment_methods_table_row_html",
				"wc_{$plugin_id}_my_payment_methods_table_body_html",
				"wc_{$plugin_id}_my_payment_methods_table_body_row_data",
				"wc_{$plugin_id}_my_payment_methods_table_method_expiry_html",
				"wc_{$plugin_id}_my_payment_methods_table_actions_html",
			);

			foreach ( $deprecated_filters as $deprecated_filter ) {
				$deprecated_hooks[ $deprecated_filter ] = [
					'removed'     => true,
					'replacement' => false,
					'version'     => '1.1.8',
				];
			}

			return $deprecated_hooks;
		}

		/**
		 * Gets a list of the plugin's deprecated/removed hooks.
		 *
		 * Implementing classes should override this and return an array of deprecated/removed hooks in the following format:
		 *
		 * $old_hook_name = array {
		 *
		 * @type string $version version the hook was deprecated/removed in
		 * @type bool $removed if present and true, the message will indicate the hook was removed instead of deprecated
		 * @type string|bool $replacement if present and a string, the message will indicate the replacement hook to use,
		 *     otherwise (if bool and false) the message will indicate there is no replacement available.
		 * }
		 *
		 * @return array
		 */
		protected function get_deprecated_hooks() {
			return array();
		}

		/**
		 * Returns true if on the admin plugin settings page, if any
		 *
		 * @return boolean true if on the admin plugin settings page
		 */
		public function is_plugin_settings() {
			return false;
		}

		/**
		 * Returns true if allowed to load and install beta versions
		 *
		 * @return boolean true if allowed to update beta version of Plugin
		 */
		public function is_beta_allowed() {
			return self::string_to_bool( get_option( $this->get_plugin_option_name( 'beta_version' ), 'no' ) );
		}

		/**
		 * Converts a stored string value to a boolean using WooCommerce-compatible semantics.
		 *
		 * @param mixed $string value to convert
		 * @return bool
		 */
		private static function string_to_bool( $string ) {
			$string = $string ?? '';

			if ( is_bool( $string ) ) {
				return $string;
			}

			$string = strtolower( (string) $string );

			return 'yes' === $string || 'true' === $string || '1' === $string;
		}

		/**
		 * Returns the plugin license instance
		 *
		 * @return Woodev_Plugins_License
		 */
		public function get_license_instance() {

			if ( ! $this->license ) {
				$this->init_license_handler();
			}

			return $this->license;
		}

		/**
		 * Adds admin notices upon initialization.
		 */
		public function add_admin_notices() {}

		/**
		 * Convenience method to add delayed admin notices, which may depend upon
		 * some setting being saved prior to determining whether to render
		 */
		public function add_delayed_admin_notices() {}

		/**
		 * Return the plugin action links.  This will only be called if the plugin is active.
		 *
		 * @param array $actions associative array of action names to anchor tags
		 *
		 * @return array associative array of plugin action links
		 */
		public function plugin_action_links( $actions ) {

			$custom_actions = [];

			if ( $this->get_settings_link( $this->get_id() ) ) {
				$custom_actions['configure'] = $this->get_settings_link( $this->get_id() );
			}

			if ( $this->get_documentation_url() ) {
				$custom_actions['docs'] = sprintf( '<a href="%s">%s</a>', $this->get_documentation_url(), 'Документация' );
			}

			if ( $this->get_support_url() ) {
				$custom_actions['support'] = sprintf( '<a href="%s">%s</a>', $this->get_support_url(), 'Поддержка' );
			}

			if ( $this->get_reviews_url() ) {
				$custom_actions['review'] = sprintf( '<a href="%s">%s</a>', $this->get_reviews_url(), 'Оставить отзыв' );
			}

			if ( $this->is_need_license() && $this->get_license_instance()->get_license_settings_url() ) {
				$license_text              = $this->get_license_instance()->is_license_valid() ? 'Лицензия' : 'Указать лицензию';
				$custom_actions['license'] = sprintf( '<a href="%s">%s</a>', $this->get_license_instance()->get_license_settings_url(), esc_html( $license_text ) );
			}

			// add the links to the front of the actions list
			return array_merge( $custom_actions, $actions );
		}

		/**
		 * Automatically log API requests/responses when using Woodev_API_Base
		 *
		 * @see Woodev_API_Base::broadcast_request()
		 */
		public function add_api_request_logging() {

			if ( ! has_action( 'woodev_' . $this->get_id() . '_api_request_performed' ) ) {
				add_action(
					'woodev_' . $this->get_id() . '_api_request_performed',
					array(
						$this,
						'log_api_request',
					),
					10,
					2
				);
			}
		}

		/**
		 * Log API requests/responses
		 *
		 * @param array       $request request data, see Woodev_API_Base::broadcast_request() for format
		 * @param array       $response response data
		 * @param string|null $log_id log to write data to
		 */
		public function log_api_request( $request, $response, $log_id = null ) {

			$this->log( "Запрос\n" . $this->get_api_log_message( $request ), $log_id );

			if ( ! empty( $response ) ) {
				$this->log( "Ответ\n" . $this->get_api_log_message( $response ), $log_id );
			}
		}

		/**
		 * Transform the API request/response data into a string suitable for logging
		 *
		 * @param array $data
		 *
		 * @return string
		 */
		public function get_api_log_message( $data ) {

			$messages = array();

			$messages[] = isset( $data['uri'] ) && $data['uri'] ? 'Запрос' : 'Ответ';

			foreach ( (array) $data as $key => $value ) {
				$messages[] = sprintf( '%s: %s', $key, is_array( $value ) || ( is_object( $value ) && 'stdClass' == get_class( $value ) ) ? print_r( (array) $value, true ) : $value );
			}

			return implode( "\n", $messages );
		}

		/**
		 * Saves errors or messages to the plugin log.
		 *
		 * The base plugin has no runtime logging backend. WooCommerce plugins override this
		 * to write to the WooCommerce logger.
		 *
		 * @since 1.0.0
		 *
		 * @param string      $message Error or message to save to log.
		 * @param string|null $log_id Optional log id to segment the files by.
		 * @return void
		 */
		public function log( $message, $log_id = null ) {}

		/**
		 * @param mixed $assertion
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public function assert( $assertion ): void {
			try {
				assert( $assertion );
			} catch ( Throwable $exception ) {
				$this->log( 'Assertion failed, backtrace summery: ' . wp_debug_backtrace_summary(), 'assertions' );
			}
		}

		/**
		 * Require and instantiate a class
		 *
		 * @param string $local_path path to class file in plugin, e.g. '/includes/class-wc-foo.php'
		 * @param string $class_name class to instantiate
		 *
		 * @return object instantiated class instance
		 */
		public function load_class( $local_path, $class_name ) {

			require_once $this->get_plugin_path() . $local_path;

			return new $class_name();
		}

		/**
		 * Determines if TLS v1.2 is required for API requests.
		 * Subclasses should override this to return true if TLS v1.2 is required.
		 *
		 * @return bool
		 */
		public function require_tls_1_2() {
			return false;
		}


		/**
		 * Determines if TLS 1.2 is available.
		 *
		 * @return bool
		 */
		public function is_tls_1_2_available() {

			$is_available = true;

			if ( is_callable( 'curl_version' ) ) {

				$versions = curl_version();

				if ( version_compare( $versions['version'], '7.34.0', '<' ) ) {
					$is_available = false;
				}
			}

			return $is_available;
		}

		/**
		 * Gets a list of the plugin's compatibility flags.
		 *
		 * @since 1.3.2
		 *
		 * @return array{ hpos?: bool, blocks?: array{ cart?: bool, checkout?: bool }}
		 */
		public function get_supported_features(): array {
			return [];
		}

		/**
		 * Gets the main plugin file.
		 *
		 * @return string
		 */
		public function get_plugin_file() {
			return plugin_basename( $this->get_file() );
		}

		/**
		 * The implementation for this abstract method should simply be:
		 *
		 * return __FILE__;
		 *
		 * @return string the full path and filename of the plugin file
		 */
		abstract protected function get_file();

		/**
		 * Returns the plugin id
		 *
		 * @return string plugin id
		 */
		public function get_id() {
			return $this->id;
		}

		/**
		 * Returns the plugin id with dashes in place of underscores, and
		 * appropriate for use in frontend element names, classes and ids
		 *
		 * @return string plugin id with dashes in place of underscores
		 */
		public function get_id_dasherized() {
			return str_replace( '_', '-', $this->get_id() );
		}

		/**
		 * Returns the plugin id with underscores in place of dashes, and
		 * appropriate for use in frontend element names, classes and ids
		 *
		 * @return string plugin id with underscores in place of dashes
		 */
		public function get_id_underscored() {
			return str_replace( '-', '_', $this->get_id() );
		}

		/**
		 * Returns the plugin full name including "WooCommerce", ie
		 * "WooCommerce X".  This method is defined abstract for localization purposes
		 *
		 * @return string plugin name
		 * @since 2.0.0
		 */
		abstract public function get_plugin_name();

		/**
		 * Gets the dependency handler.
		 *
		 * @return Woodev_Plugin_Dependencies
		 */
		public function get_dependency_handler() {
			return $this->dependency_handler;
		}


		/**
		 * Gets the lifecycle handler instance.
		 *
		 * @return Woodev_Lifecycle
		 */
		public function get_lifecycle_handler() {
			return $this->lifecycle_handler;
		}

		/**
		 * Gets the blocks handler instance.
		 *
		 * Returns null for plugins that do not opt in to a blocks handler
		 * (pure-WordPress plugins that extend Woodev_Plugin directly without
		 * going through \Woodev\Framework\Woocommerce_Plugin).
		 *
		 * @since 1.3.2
		 *
		 * @return Woodev_Blocks_Handler|null
		 */
		public function get_blocks_handler(): ?Woodev_Blocks_Handler {
			return $this->blocks_handler;
		}

		/**
		 * Gets the Setup Wizard handler instance.
		 *
		 * @return null|Woodev_Plugin_Setup_Wizard
		 */
		public function get_setup_wizard_handler() {
			return $this->setup_wizard_handler;
		}

		/**
		 * Gets the admin message handler.
		 *
		 * @return Woodev_Admin_Message_Handler
		 */
		public function get_message_handler() {
			return $this->message_handler;
		}

		/**
		 * Gets the admin notice handler instance.
		 *
		 * @return Woodev_Admin_Notice_Handler
		 */
		public function get_admin_notice_handler() {
			return $this->admin_notice_handler;
		}

		/**
		 * Returns the plugin download id
		 *
		 * @return integer plugin download id
		 */
		abstract public function get_download_id();

		/**
		 * Whether this plugin requires a license to operate.
		 *
		 * Presentation hint only — controls how the license page renders and whether
		 * "enter your license" nags appear. NEVER used to gate features or updates;
		 * the authority on that is the server-signed claim consulted by
		 * {@see Woodev_Plugins_License::is_license_required()}. A plugin shipped
		 * without a license overrides this to return false.
		 *
		 * @since 2.0.0
		 *
		 * @return bool
		 */
		public function is_need_license(): bool {
			return true;
		}

		/**
		 * Gets the settings API handler instance.
		 *
		 * Plugins can use this to init the settings API handler.
		 *
		 * @return void|Woodev_Abstract_Settings
		 */
		public function get_settings_handler() {
			return;
		}

		/**
		 * Returns the plugin version name.  Defaults to woodev_{plugin id}_version
		 *
		 * @return string the plugin version name
		 */
		public function get_plugin_version_name() {
			return 'woodev_' . $this->get_id() . '_version';
		}

		/**
		 * Returns the plugin option key by name.  Defaults to woodev_{plugin underscored id}_{key name}
		 *
		 * @param string $key_name Name of key
		 *
		 * @since 1.2.1
		 * @return string the plugin option key
		 */
		public function get_plugin_option_name( $key_name = '' ) {
			return sprintf( 'woodev_%s_%s', $this->get_id_underscored(), $key_name );
		}

		/**
		 * Returns the current version of the plugin
		 *
		 * @return string plugin version
		 */
		public function get_version() {
			return $this->version;
		}

		/**
		 * Gets the plugin version to be used by any internal scripts.
		 *
		 * This normally returns the plugin version, but will return `time()` if debug is enabled, to burst assets caches.
		 *
		 * @since 1.4.0
		 *
		 * @return string
		 */
		public function get_assets_version(): string {

			if ( ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				return (string) time();
			}

			return $this->version;
		}


		/**
		 * Gets the plugin's textdomain.
		 *
		 * @since 1.4.0
		 *
		 * @return string
		 */
		public function get_textdomain(): string {
			return $this->text_domain;
		}


		/**
		 * Returns the "Configure" plugin action link to go directly to the plugin
		 * settings page (if any)
		 *
		 * @param string $plugin_id optional plugin identifier.  Note that this can be a
		 *        sub-identifier for plugins with multiple parallel settings pages
		 *        (ie a plugin with multiple gateway variants)
		 *
		 * @return string plugin configure link
		 * @see Woodev_Plugin::get_settings_url()
		 */
		public function get_settings_link( $plugin_id = null ) {

			$settings_url = $this->get_settings_url( $plugin_id );

			if ( $settings_url ) {
				return sprintf( '<a href="%s">%s</a>', $settings_url, 'Настройки' );
			}

			return '';
		}

		/**
		 * Gets the plugin configuration URL
		 *
		 * @param string $plugin_id optional plugin identifier.  Note that this can be a
		 *        sub-identifier for plugins with multiple parallel settings pages
		 *        (ie a plugin with multiple gateway variants)
		 *
		 * @return string plugin settings URL
		 * @see Woodev_Plugin::get_settings_link()
		 */
		public function get_settings_url( $plugin_id = null ) {
			return '';
		}

		/**
		 * Returns true if the current page is the admin general configuration page
		 *
		 * @return boolean true if the current page is the admin general configuration page
		 */
		public function is_general_configuration_page() {
			return isset( $_GET['page'] ) && 'woodev-settings' == $_GET['page'] && ( ! isset( $_GET['tab'] ) || 'general' == $_GET['tab'] );
		}

		/**
		 * Returns the admin configuration url for the admin general configuration page
		 *
		 * @return string admin configuration url for the admin general configuration page
		 */
		public function get_general_configuration_url() {
			return admin_url( 'admin.php?page=woodev-settings&tab=general' );
		}

		/**
		 * Gets the plugin documentation url, used for the 'Docs' plugin action
		 *
		 * @since 2.0.0 Must be overridden by plugin subclasses; returns null in base.
		 *
		 * @return string|null documentation URL
		 */
		public function get_documentation_url() {
			return null;
		}

		/**
		 * Gets the support URL, used for the 'Support' plugin action link
		 *
		 * @since 2.0.0 Must be overridden by plugin subclasses; returns null in base.
		 *
		 * @return string|null support url
		 */
		public function get_support_url() {
			return null;
		}

		/**
		 * Gets the plugin sales page URL.
		 *
		 * @since 2.0.0 Must be overridden by plugin subclasses; returns empty string in base.
		 *
		 * @return string
		 */
		public function get_sales_page_url() {
			return '';
		}

		/**
		 * Gets the plugin reviews page URL.
		 *
		 * Used for the 'Reviews' plugin action and review prompts.
		 *
		 * @return string
		 */
		public function get_reviews_url() {

			return $this->get_sales_page_url() ? $this->get_sales_page_url() . '#edd-reviews' : '';
		}

		/**
		 * Gets the plugin's path without a trailing slash.
		 *
		 * e.g. /path/to/wp-content/plugins/plugin-directory
		 *
		 * @return string
		 */
		public function get_plugin_path() {

			if ( null === $this->plugin_path ) {
				$this->plugin_path = untrailingslashit( plugin_dir_path( $this->get_file() ) );
			}

			return $this->plugin_path;
		}

		/**
		 * Gets the plugin's URL without a trailing slash.
		 *
		 * @return string
		 */
		public function get_plugin_url() {

			if ( null === $this->plugin_url ) {
				$this->plugin_url = untrailingslashit( plugins_url( '/', $this->get_file() ) );
			}

			return $this->plugin_url;
		}

		/**
		 * Returns the loaded framework __FILE__
		 *
		 * In multi-version framework arbitration the resolver loads the
		 * highest-version framework copy's class-plugin.php first, so this
		 * method always returns the file of the active framework copy (the
		 * one that owns the running class), not the file of the calling
		 * plugin's vendored framework. Plugin code that needs the vendored
		 * copy path should use its own `__FILE__` constant.
		 *
		 * @return string
		 */
		public function get_framework_file() {
			return __FILE__;
		}

		/**
		 * Gets the loaded framework path, without trailing slash.
		 *
		 * This matches the path to the highest version of the framework currently loaded.
		 *
		 * @return string
		 */
		public function get_framework_path() {
			return untrailingslashit( plugin_dir_path( $this->get_framework_file() ) );
		}

		/**
		 * Gets the absolute path to the loaded framework image directory, without a trailing slash.
		 *
		 * @return string
		 */
		public function get_framework_assets_path() {
			return $this->get_framework_path() . '/assets';
		}

		/**
		 * Gets the loaded framework assets URL without a trailing slash.
		 *
		 * @return string
		 */
		public function get_framework_assets_url() {
			return untrailingslashit( plugins_url( '/assets', $this->get_framework_file() ) );
		}

		/**
		 * Gets the plugin default template path, without a trailing slash.
		 *
		 * @return string
		 */
		public function get_template_path() {
			if ( null === $this->template_path ) {
				$this->template_path = $this->get_plugin_path() . '/templates';
			}

			return $this->template_path;
		}

		/**
		 * Loads and outputs a template file HTML.
		 *
		 * The base plugin has no WooCommerce template loader. WooCommerce plugins override
		 * this to load templates through wc_get_template().
		 *
		 * @since 1.0.0
		 *
		 * @param string $template Template name/part.
		 * @param array  $args Associative array of optional template arguments.
		 * @param string $path Optional template path, can be empty, as themes can override this.
		 * @param string $default_path Optional default template path.
		 * @return void
		 */
		public function load_template( $template, array $args = [], $path = '', $default_path = '' ) {}

		/**
		 * Determines whether a plugin is active.
		 *
		 * @param string $plugin_name plugin name, as the plugin-filename.php
		 *
		 * @return boolean true if the named plugin is installed and active
		 */
		public function is_plugin_active( $plugin_name ) {

			$is_active = false;

			if ( is_string( $plugin_name ) ) {

				if ( ! array_key_exists( $plugin_name, $this->active_plugins ) ) {

					$active_plugins = (array) get_option( 'active_plugins', array() );

					if ( is_multisite() ) {
						$active_plugins = array_merge( $active_plugins, array_keys( get_site_option( 'active_sitewide_plugins', array() ) ) );
					}

					$plugin_filenames = array();

					foreach ( $active_plugins as $plugin ) {

						if ( Woodev_Helper::str_exists( $plugin, '/' ) ) {

							// normal plugin name (plugin-dir/plugin-filename.php)
							list( , $filename ) = explode( '/', $plugin );

						} else {

							// no directory, just plugin file
							$filename = $plugin;
						}

						$plugin_filenames[] = $filename;
					}

					$this->active_plugins[ $plugin_name ] = in_array( $plugin_name, $plugin_filenames, true );
				}

				$is_active = (bool) $this->active_plugins[ $plugin_name ];
			}

			return $is_active;
		}
	}

endif;
