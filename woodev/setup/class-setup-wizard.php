<?php
/**
 * Neutral setup wizard core.
 *
 * @package Woodev\Framework\Setup
 */

namespace Woodev\Framework\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Platform-neutral, opt-in, React-driven setup wizard.
 *
 * Plugins extend this (or Woocommerce_Setup_Wizard), implement register_steps(),
 * and return an instance from Woodev_Plugin::get_setup_wizard_handler().
 *
 * @since 2.0.2
 */
abstract class Setup_Wizard {

	/** @var \Woodev_Plugin owning plugin. */
	protected $plugin;

	/** @var string required capability (neutral default). */
	protected string $required_capability = 'manage_options';

	/** @var Step[] registered steps keyed by id (visible only, after build). */
	protected array $steps = [];

	/**
	 * Cached completion state ('' | 'completed' | 'skipped'); null until first read.
	 *
	 * @since 2.0.2
	 *
	 * @var string|null
	 */
	protected $state = null;

	/**
	 * Constructs the wizard and wires its hooks.
	 *
	 * @since 2.0.2
	 *
	 * @param \Woodev_Plugin $plugin owning plugin.
	 */
	public function __construct( \Woodev_Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->build_steps();

		if ( $this->has_steps() ) {
			$this->add_hooks();
		}
	}

	/**
	 * Registers the wizard's steps. Plugins implement this.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	abstract protected function register_steps(): void;

	/**
	 * Builds and filters the step list (visible steps only).
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	protected function build_steps(): void {
		$this->steps = [];
		$this->register_steps();

		/**
		 * Filters the registered setup-wizard steps.
		 *
		 * @since 2.0.2
		 *
		 * @param Step[]       $steps    registered steps keyed by id.
		 * @param Setup_Wizard $instance wizard instance.
		 */
		$steps = apply_filters( "woodev_{$this->get_id()}_setup_wizard_steps", $this->steps, $this );

		$this->steps = array_filter(
			$steps,
			static function ( $step ): bool {
				return $step instanceof Step && $step->is_visible();
			}
		);
	}

	/**
	 * Registers a settings step (fields resolved from the plugin's Settings API).
	 *
	 * @since 2.0.2
	 *
	 * @param string        $id          step id.
	 * @param string        $label       step label.
	 * @param string[]      $setting_ids referenced setting ids.
	 * @param callable|null $on_save     optional idempotent save side-effect.
	 * @return void
	 */
	protected function register_step( string $id, string $label, array $setting_ids, ?callable $on_save = null ): void {
		$this->steps[ $id ] = Step::settings( $id, $label, $setting_ids, $on_save );
	}

	/**
	 * Registers a content/action step (no fields).
	 *
	 * @since 2.0.2
	 *
	 * @param string          $id      step id.
	 * @param string          $label   step label.
	 * @param callable|string $content content callback or markup.
	 * @return void
	 */
	protected function register_content_step( string $id, string $label, $content ): void {
		$this->steps[ $id ] = Step::content( $id, $label, $content );
	}

	/**
	 * Wires base-owned hooks: install trigger, admin-init redirect, notice, page, REST, action link.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	protected function add_hooks(): void {
		add_action( "woodev_{$this->get_id()}_installed", [ $this, 'handle_installed' ] );
		add_action( 'admin_init', [ $this, 'maybe_redirect' ] );
		add_action( 'admin_init', [ $this, 'maybe_render_full_screen' ] );
		add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest' ], 5 );

		add_filter(
			'plugin_action_links_' . plugin_basename( $this->plugin->get_plugin_file() ),
			[ $this, 'add_action_link' ],
			20
		);
	}

	/**
	 * Returns the wizard id (delegates to the owning plugin).
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->plugin->get_id();
	}

	/**
	 * Returns the owning plugin instance.
	 *
	 * @since 2.0.2
	 *
	 * @return \Woodev_Plugin
	 */
	public function get_plugin(): \Woodev_Plugin {
		return $this->plugin;
	}

	/**
	 * Returns the registered (visible) steps keyed by id.
	 *
	 * @since 2.0.2
	 *
	 * @return Step[]
	 */
	public function get_steps(): array {
		return $this->steps;
	}

	/**
	 * Whether the wizard has any visible steps.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function has_steps(): bool {
		return ! empty( $this->steps );
	}

	/**
	 * Returns the required WordPress capability for accessing the wizard.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_required_capability(): string {
		return $this->required_capability;
	}

	/**
	 * Option name storing completion state.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	protected function get_complete_option_name(): string {
		return "woodev_{$this->get_id()}_setup_wizard_complete";
	}

	/**
	 * Gets the completion state, reading the option once per request.
	 *
	 * @since 2.0.2
	 *
	 * @return string '' | 'completed' | 'skipped'
	 */
	public function get_state(): string {
		if ( null === $this->state ) {
			$this->state = (string) get_option( $this->get_complete_option_name(), '' );
		}

		return $this->state;
	}

	/**
	 * Whether the wizard was completed.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return 'completed' === $this->get_state();
	}

	/**
	 * Whether the wizard was skipped.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function is_skipped(): bool {
		return 'skipped' === $this->get_state();
	}

	/**
	 * Whether the wizard is finished (completed or skipped).
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function is_finished(): bool {
		return '' !== $this->get_state();
	}

	/**
	 * Persists completion state (server-side authority, not a client flag).
	 *
	 * @since 2.0.2
	 *
	 * @param string $state 'completed' (default) or 'skipped'; any other value normalises to 'completed'.
	 * @return void
	 */
	public function complete_setup( string $state = 'completed' ): void {
		$value = 'skipped' === $state ? 'skipped' : 'completed';
		update_option( $this->get_complete_option_name(), $value );
		$this->state = $value;
	}

	/**
	 * Transient name for the one-shot post-install redirect.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	protected function get_redirect_transient_name(): string {
		return "woodev_{$this->get_id()}_setup_wizard_redirect";
	}

	/**
	 * Arms the one-shot redirect on first install.
	 *
	 * Hooked to woodev_{id}_installed (Woodev_Lifecycle).
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function handle_installed(): void {
		set_transient( $this->get_redirect_transient_name(), 1, HOUR_IN_SECONDS );
	}

	/**
	 * Decides whether admin_init should redirect to the wizard this request.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	protected function should_redirect_on_admin_init(): bool {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( ! current_user_can( $this->required_capability ) ) {
			return false;
		}

		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only bulk-activation marker, no state change.
			return false;
		}

		if ( $this->is_finished() ) {
			return false;
		}

		return (bool) get_transient( $this->get_redirect_transient_name() );
	}

	/**
	 * Performs the one-shot redirect to the wizard page.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		if ( ! $this->should_redirect_on_admin_init() ) {
			return;
		}

		delete_transient( $this->get_redirect_transient_name() );
		wp_safe_redirect( $this->get_setup_url() );
		exit;
	}

	/**
	 * Returns the admin page slug for this wizard.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_page_slug(): string {
		return "woodev-{$this->get_id()}-setup";
	}

	/**
	 * Returns the full admin URL to the wizard page.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_setup_url(): string {
		return esc_url_raw( admin_url( 'admin.php?page=' . $this->get_page_slug() ) );
	}

	/**
	 * Registers the hidden full-screen wizard admin page.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function register_page(): void {
		$hook = add_submenu_page(
			'', // hidden: no parent menu.
			$this->plugin->get_plugin_name(),
			$this->plugin->get_plugin_name(),
			$this->required_capability,
			$this->get_page_slug(),
			[ $this, 'render_page' ]
		);

		if ( $hook ) {
			add_action( "admin_print_scripts-{$hook}", [ $this, 'enqueue_assets' ] );
		}
	}

	/**
	 * Renders the React mount node for the wizard.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function render_page(): void {
		echo '<div id="woodev-setup-wizard-root"></div>';
		echo '<noscript><p>' . esc_html__( 'Для мастера настройки нужен JavaScript. Включите его и обновите страницу.', 'woodev-plugin-framework' ) . '</p></noscript>';
	}

	/**
	 * Whether the current request targets the wizard page.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	protected function is_wizard_page_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing check, no state change.
		return is_admin() && ! wp_doing_ajax() && isset( $_GET['page'] ) && $this->get_page_slug() === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Renders the wizard as a standalone full-screen page (no admin chrome).
	 *
	 * Hooked early on admin_init: on the wizard page it enqueues the bundle,
	 * prints a minimal HTML document with the React mount, and exits before
	 * admin-header.php loads the menu/toolbar/notices.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function maybe_render_full_screen(): void {
		if ( ! $this->is_wizard_page_request() ) {
			return;
		}

		if ( ! current_user_can( $this->required_capability ) ) {
			wp_die(
				esc_html__( 'У вас нет прав для доступа к этой странице.', 'woodev-plugin-framework' ),
				'',
				[ 'response' => 403 ]
			);
		}

		$this->enqueue_assets();
		$this->render_full_screen_page();

		exit;
	}

	/**
	 * Outputs the standalone full-screen HTML document for the wizard.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function render_full_screen_page(): void {
		// The standalone wizard has no emoji content; skip the (deprecated in WP 6.4)
		// emoji-styles print so the <head> stays clean.
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $this->plugin->get_plugin_name() ); ?></title>
		<?php
		wp_print_styles();
		wp_print_head_scripts();
		?>
</head>
<body class="woodev-setup-wizard wp-core-ui">
	<div id="woodev-setup-wizard-root"></div>
	<noscript><p><?php echo esc_html__( 'Для мастера настройки нужен JavaScript. Включите его и обновите страницу.', 'woodev-plugin-framework' ); ?></p></noscript>
		<?php wp_print_footer_scripts(); ?>
</body>
</html>
		<?php
	}

	/**
	 * Enqueues the wizard React bundle and inline bootstrap data.
	 *
	 * Mirrors Woodev_Admin_Pages::load_licenses_page_scripts().
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$asset_file = $this->plugin->get_framework_path() . '/assets/build/setup-wizard/index.asset.php';

		if ( file_exists( $asset_file ) ) {
			$asset = include $asset_file;
		} else {
			// Build missing (npm run build not run / failed): the bundle's real
			// dependencies are absent, so the React page would render blank (only the
			// noscript notice shows). Log so the cause is traceable, then fall back.
			error_log( sprintf( '[woodev] Setup wizard asset manifest missing: %s', $asset_file ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a missing build artifact.
			$asset = [
				'dependencies' => [],
				'version'      => $this->plugin->get_version(),
			];
		}

		$build_url = $this->plugin->get_framework_assets_url() . '/build/setup-wizard';

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'woodev-setup-wizard', $build_url . '/style-index.css', [ 'wp-components' ], $asset['version'] );
		wp_enqueue_script( 'woodev-setup-wizard', $build_url . '/index.js', $asset['dependencies'], $asset['version'], true );

		wp_add_inline_script(
			'woodev-setup-wizard',
			'window.woodevSetupWizard = ' . wp_json_encode( $this->get_bootstrap_data() ) . ';',
			'before'
		);
	}

	/**
	 * Builds the PHP-driven bootstrap payload for the React shell.
	 *
	 * @since 2.0.2
	 *
	 * @return array<string,mixed>
	 */
	protected function get_bootstrap_data(): array {
		$schema = $this->get_field_schema();
		$steps  = [];

		foreach ( $this->steps as $step ) {
			$fields = [];
			foreach ( $step->get_setting_ids() as $sid ) {
				if ( isset( $schema[ $sid ] ) ) {
					$fields[ $sid ] = $schema[ $sid ];
				}
			}

			if ( Step::TYPE_SETTINGS === $step->get_type() && empty( $fields ) && ! empty( $step->get_setting_ids() ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s step id */
						esc_html__( 'Setup wizard step "%s" declares setting ids but none resolved — ensure the plugin returns a settings handler from get_settings_handler().', 'woodev-plugin-framework' ),
						esc_html( $step->get_id() )
					),
					'2.0.2'
				);
			}

			$content = $step->get_content();
			if ( is_callable( $content ) ) {
				$content = (string) call_user_func( $content );
			}

			$steps[] = [
				'id'      => $step->get_id(),
				'label'   => $step->get_label(),
				'type'    => $step->get_type(),
				'fields'  => $fields,
				'content' => is_string( $content ) ? $content : '',
			];
		}

		return [
			'pluginId'      => $this->get_id(),
			'pluginName'    => $this->plugin->get_plugin_name(),
			'headerLogoUrl' => esc_url_raw( $this->get_header_image_url() ),
			'restRoot'      => esc_url_raw( rest_url( "woodev/v1/{$this->get_id()}/setup" ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'state'         => $this->get_state(),
			'steps'         => $steps,
			'finishActions' => $this->get_finish_actions(),
		];
	}

	/**
	 * Resolves the JSON field schema for referenced settings from the plugin's
	 * Settings API handler. Returns an empty map when the plugin has no handler.
	 *
	 * @since 2.0.2
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function get_field_schema(): array {
		$handler = $this->plugin->get_settings_handler();
		if ( ! $handler ) {
			return [];
		}

		$schema = [];
		foreach ( $handler->get_settings() as $setting ) {
			$schema[ $setting->get_id() ] = [
				'type'    => $setting->get_type(),
				'name'    => $setting->get_name(),
				'options' => $setting->get_options(),
				'value'   => $handler->get_value( $setting->get_id() ),
			];
		}

		return $schema;
	}

	/**
	 * Finish-screen "what's next" actions. Override per plugin.
	 *
	 * @since 2.0.2
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function get_finish_actions(): array {
		$actions = [];
		if ( $this->plugin->get_documentation_url() ) {
			$actions[] = [
				'label' => __( 'Документация', 'woodev-plugin-framework' ),
				'url'   => esc_url_raw( $this->plugin->get_documentation_url() ),
			];
		}

		return $actions;
	}

	/**
	 * Returns the header logo URL. Override per plugin.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	protected function get_header_image_url(): string {
		return '';
	}

	/**
	 * Renders the "run the wizard" admin notice fallback.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function maybe_render_notice(): void {
		if ( ! current_user_can( $this->required_capability ) ) {
			return;
		}

		if ( $this->is_finished() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, no state change.
		if ( isset( $_GET['page'] ) && $this->get_page_slug() === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s" class="button button-primary">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s plugin name */
					__( 'Завершите настройку %s.', 'woodev-plugin-framework' ),
					$this->plugin->get_plugin_name()
				)
			),
			esc_url( $this->get_setup_url() ),
			esc_html__( 'Запустить мастер настройки', 'woodev-plugin-framework' )
		);
	}

	/**
	 * Adds a "Setup" link to the plugin row while incomplete.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @param string[] $links existing action links.
	 *
	 * @return string[]
	 */
	public function add_action_link( array $links ): array {
		if ( ! $this->is_finished() ) {
			$links[] = sprintf( '<a href="%s">%s</a>', esc_url( $this->get_setup_url() ), esc_html__( 'Настройка', 'woodev-plugin-framework' ) );
		}

		return $links;
	}

	/**
	 * Registers the wizard REST controller through the woodev/v1 registrar.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function register_rest(): void {
		if ( ! class_exists( 'Woodev_REST_API_Setup' ) ) {
			require_once $this->plugin->get_framework_path() . '/rest-api/controllers/class-rest-api-setup.php';
		}

		// Per-plugin dedup key: the controller is stateful (carries this wizard),
		// so two plugins with wizards must each register their own instance —
		// the default class-name key would collapse them to one.
		\Woodev_REST_V1_Registrar::register_controller(
			new \Woodev_REST_API_Setup( $this ),
			\Woodev_REST_API_Setup::class . '_' . $this->get_id()
		);
	}
}
