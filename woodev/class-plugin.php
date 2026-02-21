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
	 * @version 1.4.0
	 */
	abstract class Woodev_Plugin {

		/** Plugin Framework Version */
		const VERSION = '1.4.0';

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

		/** @var array{ hpos?: bool, blocks?: array{ cart?: bool, checkout?: bool }} plugin compatibility flags */
		private $supported_features;

		/** @var WC_Logger instance */
		private $logger;

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

		/** @var Woodev_Blocks_Handler blocks handler instance */
		protected Woodev_Blocks_Handler $blocks_handler;

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
		 *     latest_wc_versions?: int|float,
		 *     text_domain?: string,
		 *     supported_features?: array{
		 *          hpos?: bool,
		 *          blocks?: array{
		 *               cart?: bool,
		 *               checkout?: bool
		 *          }
		 *     },
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
					'text_domain'        => '',
					'dependencies'       => [],
					'supported_features' => [
						'hpos'   => false,
						'blocks' => [
							'cart'     => false,
							'checkout' => false,
						],
					],
				]
			);

			$this->text_domain        = $args['text_domain'];
			$this->supported_features = $args['supported_features'];

			// includes that are required to be available at all times
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

			// build the REST API handler instance
			$this->init_rest_api_handler();

			// build the blocks handler instance
			$this->init_blocks_handler();

			// build the setup handler instance
			$this->init_setup_wizard_handler();

			// load the admin settings pages
			$this->load_admin_pages();

			// load the plugin license settings fields
			$this->load_license_settings_fields();

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
		 * Initialize Woodev admin pages
		 *
		 * @access private
		 * @return void
		 */
		private function load_license_settings_fields() {
			if ( is_admin() ) {

				if ( ! class_exists( 'Woodev_License_Settings' ) ) {
					require_once $this->get_framework_path() . '/licensing/class-plugin-license-settings.php';
				}

				new Woodev_License_Settings( $this );
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

		/**
		 * Adds the action & filter hooks.
		 */
		private function add_hooks() {

			// initialize the plugin
			add_action( 'plugins_loaded', array( $this, 'init_plugin' ), 15 );

			// initialize the plugin admin
			add_action( 'admin_init', array( $this, 'init_admin' ), 0 );

			// hook for translations separately to ensure they're loaded
			add_action( 'init', array( $this, 'load_translations' ) );

			// Load plugin updater
			add_action( 'init', array( $this, 'load_updater' ) );

			// handle WooCommerce features compatibility (such as HPOS, WC Cart & Checkout Blocks support...)
			add_action( 'before_woocommerce_init', [ $this, 'handle_features_compatibility' ] );

			add_action( 'wp_enqueue_scripts', [ $this, 'frontend_enqueue_scripts' ] );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_action( 'wp_ajax_woodev_verify_license', array( $this, 'ajax_verify_license' ) );

			foreach ( array( 'shipping', 'checkout', 'integration' ) as $tab ) {
				add_action( 'woocommerce_before_settings_' . $tab, array( $this, 'add_class_form_wrap_start' ) );
				add_action( 'woocommerce_after_settings_' . $tab, array( $this, 'add_class_form_wrap_end' ) );
			}

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

			// add any PHP incompatibilities to the system status report
			add_filter(
				'woocommerce_system_status_environment_rows',
				array(
					$this,
					'add_system_status_php_information',
				)
			);

			// CRON actions
			add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
			add_action( 'wp', array( $this, 'schedule_events' ) );
			add_action( 'woodev_weekly_scheduled_events', array( $this, 'weekly_license_check' ) );
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

			if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return;
			}

			$license_key = $this->get_license_instance()->get_license();

			if ( $license_key ) {
				new Woodev_Plugin_Updater( $this );
			}

			do_action( 'woodev_plugin_updater', $license_key );
		}

		/**
		 * Load plugin & framework text domains.
		 */
		public function load_translations() {

			$this->load_framework_textdomain();

			// if this plugin passes along its text domain, load its translation files
			if ( $this->text_domain ) {
				$this->load_plugin_textdomain();
			}
		}

		/**
		 * Loads the framework textdomain.
		 */
		protected function load_framework_textdomain() {
			$this->load_textdomain( 'woodev-plugin-framework', dirname( plugin_basename( $this->get_framework_file() ) ) );
		}

		/**
		 * Loads the plugin textdomain.
		 */
		protected function load_plugin_textdomain() {
			$this->load_textdomain( $this->text_domain, dirname( plugin_basename( $this->get_plugin_file() ) ) );
		}

		/**
		 * Loads the plugin textdomain.
		 *
		 * @param string $textdomain the plugin textdomain
		 * @param string $path the i18n path
		 */
		protected function load_textdomain( $textdomain, $path ) {
			// user's locale if in the admin for WP 4.7+, or the site locale otherwise
			$locale = is_admin() && is_callable( 'get_user_locale' ) ? get_user_locale() : get_locale();

			$locale = apply_filters( 'plugin_locale', $locale, $textdomain );

			load_textdomain( $textdomain, WP_LANG_DIR . '/' . $textdomain . '/' . $textdomain . '-' . $locale . '.mo' );

			load_plugin_textdomain( $textdomain, false, untrailingslashit( $path ) . '/languages' );
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

		/**
		 * Registers new cron schedules
		 *
		 * @param array $schedules
		 *
		 * @return array
		 */
		public function add_schedules( $schedules = array() ) {

			if ( ! isset( $schedules['weekly'] ) ) {
				$schedules['weekly'] = array(
					'interval' => WEEK_IN_SECONDS,
					'display'  => __( 'Once Weekly', 'woodev-plugin-framework' ),
				);
			}

			return $schedules;
		}


		/**
		 * Schedule weekly events
		 *
		 * @return void
		 */
		public function schedule_events() {
			if ( ! wp_next_scheduled( 'woodev_weekly_scheduled_events' ) ) {
				wp_schedule_event( time(), 'weekly', 'woodev_weekly_scheduled_events' );
			}
		}

		/**
		 * Check if license key is valid once per week
		 *
		 * @return  void
		 */
		public function weekly_license_check() {

			// Don't fire when saving settings.
			if ( ! empty( $_POST['woodev_settings'] ) ) {
				return;
			}

			if ( ! wp_doing_cron() ) {
				return;
			}

			$license_key = $this->get_license_instance()->get_license();

			if ( empty( $license_key ) ) {
				return;
			}

			$this->get_license_instance()->validate_license( $license_key );
		}

		public function ajax_verify_license() {
			check_ajax_referer( 'woodev-admin', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error();
			}

			$license = $this->get_license_instance()->get_license();

			$this->get_license_instance()->validate_license( $license ?: __return_empty_string(), true, true );
		}

		public function add_class_form_wrap_start() {
			if ( $this->is_plugin_settings() && ! $this->get_license_instance()->is_license_valid() ) {
				echo '<div class="woodev-licence-need">';
			}
		}

		public function add_class_form_wrap_end() {
			if ( $this->is_plugin_settings() && ! $this->get_license_instance()->is_license_valid() ) {
				echo '</div><!-- .woodev-licence-need end-->';
			}
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

			// backwards compatibility for older WC versions
			require_once $framework_path . '/class-plugin-compatibility.php';
			require_once $framework_path . '/compatibility/abstract-data-compatibility.php';
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

			require_once $framework_path . '/utilities/class-woodev-async-request.php';
			require_once $framework_path . '/utilities/class-woodev-background-job-handler.php';
			require_once $framework_path . '/utilities/class-woodev-job-batch-handler.php';

			// REST API Controllers
			require_once $framework_path . '/rest-api/controllers/class-plugin-rest-api-settings.php';

			// Handlers
			require_once $framework_path . '/handlers/script-handler.php';
			require_once $framework_path . '/class-woodev-plugin-dependencies.php';
			require_once $framework_path . '/class-woodev-hook-deprecator.php';
			require_once $framework_path . '/class-admin-message-handler.php';
			require_once $framework_path . '/class-admin-notice-handler.php';
			require_once $framework_path . '/class-lifecycle.php';
			require_once $framework_path . '/rest-api/class-plugin-rest-api.php';

			// Load plugin license classes
			require_once $framework_path . '/licensing/api/class-licensing-api.php';
			require_once $framework_path . '/licensing/api/class-licensing-api-request.php';
			require_once $framework_path . '/licensing/api/class-licensing-api-response.php';
			require_once $framework_path . '/licensing/class-license-messages.php';
			require_once $framework_path . '/licensing/class-license-store.php';
			require_once $framework_path . '/licensing/class-plugin-license.php';

			// Load plugin updater class
			if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				require_once $framework_path . '/plugin-updater/class-plugin-updater.php';
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
			return wc_string_to_bool( get_option( $this->get_plugin_option_name( 'beta_version' ), 'no' ) );
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

			$custom_actions = array();

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

			if ( $this->get_license_instance()->get_license_settings_url() ) {
				$license_text              = $this->get_license_instance()->is_license_valid() ? 'Лицензия' : 'Указать лицензию';
				$custom_actions['license'] = sprintf( '<a href="%s">%s</a>', $this->get_license_instance()->get_license_settings_url(), esc_html( $license_text ) );
			}

			// add the links to the front of the actions list
			return array_merge( $custom_actions, $actions );
		}

		/**
		 * Declares HPOS compatibility if the plugin is compatible with HPOS.
		 *
		 * @internal
		 * @deprecated since 1.3.2
		 * @see Woodev_Plugin::handle_features_compatibility()
		 *
		 * @since 1.2.1
		 */
		public function handle_hpos_compatibility() {

			wc_deprecated_function( __METHOD__, '1.3.2', 'Woodev_Plugin::handle_features_compatibility' );

			$this->handle_features_compatibility();
		}

		/**
		 * Declares compatibility with specific WooCommerce features.
		 *
		 * @internal
		 *
		 * @since 1.3.2
		 */
		public function handle_features_compatibility(): void {

			if ( ! class_exists( Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				return;
			}

			Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $this->get_plugin_file(), $this->is_hpos_compatible() );
			Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', $this->get_plugin_file(), $this->get_blocks_handler()->is_cart_block_compatible() && $this->get_blocks_handler()->is_checkout_block_compatible() );
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
		 * Adds any PHP incompatibilities to the system status report.
		 *
		 * @param array $rows WooCommerce system status rows
		 *
		 * @return array
		 */
		public function add_system_status_php_information( $rows ) {

			foreach ( $this->get_dependency_handler()->get_incompatible_php_settings() as $setting => $values ) {

				if ( isset( $values['type'] ) && 'min' === $values['type'] ) {

					// if this setting already has a higher minimum from another plugin, skip it
					if ( isset( $rows[ $setting ]['expected'] ) && $values['expected'] < $rows[ $setting ]['expected'] ) {
						continue;
					}

					$note = __( '%1$s - A minimum of %2$s is required.', 'woodev-plugin-framework' );

				} else {

					// if this requirement is already listed, skip it
					if ( isset( $rows[ $setting ] ) ) {
						continue;
					}

					$note = __( 'Set as %1$s - %2$s is required.', 'woodev-plugin-framework' );
				}

				$note = sprintf( $note, $values['actual'], $values['expected'] );

				$rows[ $setting ] = array(
					'name'     => $setting,
					'note'     => $note,
					'success'  => false,
					'expected' => $values['expected'], // WC doesn't use this, but it's useful for us
				);
			}

			return $rows;
		}

		/**
		 * Saves errors or messages to WooCommerce Log (woocommerce/logs/plugin-id-xxx.txt)
		 *
		 * @param string $message error or message to save to log
		 * @param string $log_id optional log id to segment the files by, defaults to plugin id
		 */
		public function log( $message, $log_id = null ) {

			if ( is_null( $log_id ) ) {
				$log_id = $this->get_id();
			}

			$this->logger()->add( $log_id, $message );
		}

		/**
		 * @since 1.4.0
		 *
		 * @return WC_Logger_Interface
		 */
		protected function logger(): WC_Logger_Interface {
			return $this->logger ??= wc_get_logger();
		}

		/**
		 * @param $assertion
		 *
		 * @since 1.4.0
		 *
		 * @return void
		 */
		public function assert( $assertion ): void {
			try {
				assert( $assertion );
			} catch ( Throwable $exception ) {
				$this->logger()->debug( 'Assertion failed, backtrace summery: ' . wp_debug_backtrace_summary() );
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
			return $this->supported_features ?? [];
		}

		/**
		 * Determines whether the plugin supports HPOS.
		 *
		 * @since 1.2.0
		 *
		 * @return bool
		 */
		public function is_hpos_compatible() {
			return isset( $this->supported_features['hpos'] )
					&& true === $this->supported_features['hpos']
					&& Woodev_Plugin_Compatibility::is_wc_version_gte( '7.6' );
		}

		/**
		 * Gets the main plugin file.
		 *
		 * @return string
		 */
		public function get_plugin_file() {
			$slug = dirname( plugin_basename( $this->get_file() ) );

			return trailingslashit( $slug ) . $slug . '.php';
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
		 * @since 1.3.2
		 *
		 * @return Woodev_Blocks_Handler
		 */
		public function get_blocks_handler(): Woodev_Blocks_Handler {
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

			if ( defined( 'SCRIPT_DEBUG' ) && true === SCRIPT_DEBUG || defined( 'WP_DEBUG' ) && true === WP_DEBUG ) {
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
		 *        (ie a gateway that supports both credit cards and echecks)
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
		 *        (ie a gateway that supports both credit cards and echecks)
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
		 * @return string|null documentation URL
		 */
		public function get_documentation_url() {
			return null;
		}

		/**
		 * Gets the support URL, used for the 'Support' plugin action link
		 *
		 * @return string|null support url
		 */
		public function get_support_url() {
			return null;
		}

		/**
		 * Gets the plugin sales page URL.
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

			return $this->get_sales_page_url() ? $this->get_sales_page_url() . '#comments' : '';
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
		 * Gets the woocommerce uploads path, without trailing slash.
		 *
		 * Oddly WooCommerce core does not provide a way to get this.
		 *
		 * @return string
		 */
		public static function get_woocommerce_uploads_path() {

			$upload_dir = wp_upload_dir();

			return $upload_dir['basedir'] . '/woocommerce_uploads';
		}

		/**
		 * Returns the loaded framework __FILE__
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
		 * @param string $template template name/part
		 * @param array  $args associative array of optional template arguments
		 * @param string $path optional template path, can be empty, as themes can override this
		 * @param string $default_path optional default template path, will normally use the plugin's own template path unless overridden
		 *
		 * @see wc_get_template() except we define automatically the default path
		 */
		public function load_template( $template, array $args = [], $path = '', $default_path = '' ) {

			if ( '' === $default_path || ! is_string( $default_path ) ) {
				$default_path = trailingslashit( $this->get_template_path() );
			}

			if ( function_exists( 'wc_get_template' ) ) {
				wc_get_template( $template, $args, $path, $default_path );
			}
		}

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

		/** Deprecated methods */

		/**
		 * Handles version checking.
		 *
		 * @deprecated 1.1.8
		 */
		public function do_install() {

			wc_deprecated_function( __METHOD__, '1.1.8', get_class( $this->get_lifecycle_handler() ) . '::init()' );

			$this->get_lifecycle_handler()->init();
		}

		/**
		 * Helper method to install default settings for a plugin.
		 *
		 * @param array $settings array of settings in format required by WC_Admin_Settings
		 *
		 * @deprecated 1.1.8
		 */
		public function install_default_settings( array $settings ) {

			wc_deprecated_function( __METHOD__, '1.1.8', get_class( $this->get_lifecycle_handler() ) . '::install_default_settings()' );

			$this->get_lifecycle_handler()->install_default_settings( $settings );
		}

		/**
		 * Plugin activated method. Perform any activation tasks here.
		 * Note that this _does not_ run during upgrades.
		 *
		 * @deprecated 1.1.8
		 */
		public function activate() {
			wc_deprecated_function( __METHOD__, '1.1.8' );
		}

		/**
		 * Plugin deactivation method. Perform any deactivation tasks here.
		 *
		 * @deprecated 1.1.8
		 */
		public function deactivate() {
			wc_deprecated_function( __METHOD__, '1.1.8' );
		}

		/**
		 * Gets the string name of any required PHP extensions that are not loaded.
		 *
		 * @return array
		 * @deprecated 1.1.8
		 */
		public function get_missing_extension_dependencies() {

			wc_deprecated_function( __METHOD__, '1.1.8', get_class( $this->get_dependency_handler() ) . '::get_missing_php_extensions()' );

			return $this->get_dependency_handler()->get_missing_php_extensions();
		}

		/**
		 * Gets the string name of any required PHP functions that are not loaded.
		 *
		 * @return array
		 * @deprecated 1.1.8
		 */
		public function get_missing_function_dependencies() {

			wc_deprecated_function( __METHOD__, '1.1.8', get_class( $this->get_dependency_handler() ) . '::get_missing_php_functions()' );

			return $this->get_dependency_handler()->get_missing_php_functions();
		}

		/**
		 * Gets the string name of any required PHP extensions that are not loaded.
		 *
		 * @return array
		 * @deprecated 1.1.8
		 */
		public function get_incompatible_php_settings() {

			wc_deprecated_function( __METHOD__, '1.1.8', get_class( $this->get_dependency_handler() ) . '::get_incompatible_php_settings()' );

			return $this->get_dependency_handler()->get_incompatible_php_settings();
		}

		/**
		 * Gets the PHP dependencies.
		 *
		 * @return array
		 * @deprecated 1.1.8
		 */
		protected function get_dependencies() {
			wc_deprecated_function( __METHOD__, '1.1.8' );

			return array();
		}

		/**
		 * Gets the PHP extension dependencies.
		 *
		 * @return array
		 * @deprecated 1.1.8
		 */
		protected function get_extension_dependencies() {

			wc_deprecated_function( __METHOD__, '1.1.8', get_class( $this->get_dependency_handler() ) . '::get_php_extensions()' );

			return $this->get_dependency_handler()->get_php_extensions();
		}


		/**
		 * Gets the PHP function dependencies.
		 *
		 * @return array
		 * @deprecated 1.1.8
		 */
		protected function get_function_dependencies() {

			wc_deprecated_function( __METHOD__, '1.1.8', get_class( $this->get_dependency_handler() ) . '::get_php_functions()' );

			return $this->get_dependency_handler()->get_php_functions();
		}


		/**
		 * Gets the PHP settings dependencies.
		 *
		 * @return array
		 * @deprecated 1.1.8
		 */
		protected function get_php_settings_dependencies() {

			wc_deprecated_function( __METHOD__, '1.1.8', get_class( $this->get_dependency_handler() ) . '::get_php_settings()' );

			return $this->get_dependency_handler()->get_php_settings();
		}

		/**
		 * Sets the plugin dependencies.
		 *
		 * @param array $dependencies the environment dependencies
		 *
		 * @deprecated 1.1.8
		 */
		protected function set_dependencies( $dependencies = array() ) {

			wc_deprecated_function( __METHOD__, '1.1.8' );
		}
	}

endif;
