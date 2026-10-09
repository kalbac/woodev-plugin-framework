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

	/** @var Step[] every registered step keyed by id (visible or not — see get_steps()). */
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
	 * Builds the step list: every registered step, after the plugin filter.
	 *
	 * Visibility is NOT decided here: it is a server-side predicate over saved state that
	 * changes as the merchant saves steps, so it is evaluated on demand ({@see self::get_steps()}).
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
		 * The return is validated: it feeds array_filter() below directly,
		 * which throws a TypeError on a non-array argument. A non-array
		 * return degrades to the pre-filter steps rather than reaching
		 * that call.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 the filter return is validated with is_array(); a
		 *              non-array return no longer reaches array_filter() raw.
		 *
		 * @param Step[]       $steps    registered steps keyed by id.
		 * @param Setup_Wizard $instance wizard instance.
		 */
		$filtered_steps = apply_filters( "woodev_{$this->get_id()}_setup_wizard_steps", $this->steps, $this );
		$steps          = is_array( $filtered_steps ) ? $filtered_steps : $this->steps;

		$this->steps = array_filter(
			$steps,
			static function ( $step ): bool {
				return $step instanceof Step;
			}
		);
	}

	/**
	 * Registers a settings step (fields resolved from the plugin's Settings API).
	 *
	 * `$on_save` runs AFTER the step's settings are persisted, and may be run more than
	 * once for the same step (the user retries). If it throws anything (a \Throwable),
	 * the REST layer logs it (secrets masked) and answers a generic HTTP 500 error — the
	 * exception message is never shown — while the settings stay saved. Make it
	 * idempotent: see Woodev_REST_API_Setup::save_step() for the full failure contract.
	 *
	 * @since 2.0.2
	 *
	 * @param string        $id          step id.
	 * @param string        $label       step label.
	 * @param string[]      $setting_ids referenced setting ids.
	 * @param callable|null $on_save     optional idempotent save side-effect.
	 * @param string        $description optional step description shown in the wizard UI.
	 * @return Step the registered step, for fluent configuration (`set_skippable()`,
	 *              `set_validation_callback()`, `add_action()`).
	 */
	protected function register_step( string $id, string $label, array $setting_ids, ?callable $on_save = null, string $description = '' ): Step {
		$this->steps[ $id ] = Step::settings( $id, $label, $setting_ids, $on_save, $description );

		return $this->steps[ $id ];
	}

	/**
	 * Registers a content/action step (no fields).
	 *
	 * @since 2.0.2
	 *
	 * @param string          $id          step id.
	 * @param string          $label       step label.
	 * @param callable|string $content     content callback or markup.
	 * @param string          $description optional step description shown in the wizard UI.
	 * @return Step the registered step, for fluent configuration (`set_skippable()`, `add_action()`).
	 */
	protected function register_content_step( string $id, string $label, $content, string $description = '' ): Step {
		$this->steps[ $id ] = Step::content( $id, $label, $content, $description );

		return $this->steps[ $id ];
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
	 * Returns the steps that are visible RIGHT NOW, keyed by id.
	 *
	 * The visibility predicates are evaluated on every call, over the saved state — so after a
	 * save the answer can differ from the one before it (D3).
	 *
	 * @since 2.0.2
	 *
	 * @return Step[]
	 */
	public function get_steps(): array {
		return array_filter(
			$this->steps,
			static function ( Step $step ): bool {
				return $step->is_visible();
			}
		);
	}

	/**
	 * Returns a registered step by id whether or not it is visible now.
	 *
	 * @since 2.0.2
	 *
	 * @param string $step_id step id.
	 * @return Step|null
	 */
	public function get_registered_step( string $step_id ): ?Step {
		return $this->steps[ $step_id ] ?? null;
	}

	/**
	 * Whether the wizard has any registered step.
	 *
	 * Deliberately not "any VISIBLE step": it decides whether the hooks are wired, which happens
	 * while the plugin boots, and running the plugins' visibility predicates that early (over
	 * settings that may not be ready) is exactly what the graph avoids. A wizard whose steps are
	 * all hidden shows just its finish step.
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
	 * The state only moves forward (D1): `completed` means "the merchant reached the end"
	 * and is never overwritten by `skipped`; `skipped` («Настрою позже») may later become
	 * `completed`. A refused downgrade is not an error — nothing is written and the
	 * unchanged state is returned. It says nothing about the plugin being ready.
	 *
	 * @since 2.0.2
	 *
	 * @param string $state 'completed' (default) or 'skipped'; any other value normalises to 'completed'.
	 * @return string the state in force afterwards ('completed' | 'skipped').
	 */
	public function complete_setup( string $state = 'completed' ): string {
		$value = 'skipped' === $state ? 'skipped' : 'completed';

		// Read the option, not this wizard's `$state` cache (WordPress's own option cache still
		// applies). Not atomic: two overlapping requests can race, which D1 tolerates.
		if ( 'skipped' === $value && 'completed' === (string) get_option( $this->get_complete_option_name(), '' ) ) {
			$this->state = 'completed';

			return 'completed';
		}

		update_option( $this->get_complete_option_name(), $value );
		$this->state = $value;

		return $value;
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

		// The asset manifest version tracks the JS bundle only; SCSS-only changes
		// leave it unchanged, so version the stylesheet by its own mtime to bust
		// the cache when just the CSS is rebuilt (and on every release).
		$style_path    = $this->plugin->get_framework_path() . '/assets/build/setup-wizard/style-index.css';
		$style_version = file_exists( $style_path ) ? (string) filemtime( $style_path ) : $asset['version'];

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'woodev-setup-wizard', $build_url . '/style-index.css', [ 'wp-components' ], $style_version );
		// Plugin-supplied step components (D4): their scripts must be loaded before the wizard
		// bundle runs, so they are its dependencies.
		$dependencies = array_values( array_unique( array_merge( $asset['dependencies'], $this->get_component_handles() ) ) );
		wp_enqueue_script( 'woodev-setup-wizard', $build_url . '/index.js', $dependencies, $asset['version'], true );
		\Woodev\Framework\Handlers\Script_Translations::register( $this->plugin, 'woodev-setup-wizard' );

		wp_add_inline_script(
			'woodev-setup-wizard',
			'window.woodevSetupWizard = ' . wp_json_encode( $this->get_bootstrap_data() ) . ';',
			'before'
		);
	}

	/**
	 * Script handles of the custom step components that are registered with WordPress.
	 *
	 * Every registered step counts, visible or not — a hidden step can become visible after a
	 * save, and its script must already be on the page. A handle nobody registered is logged
	 * and left out; the client then shows the step's error state.
	 *
	 * @since 2.0.2
	 *
	 * @return string[]
	 */
	protected function get_component_handles(): array {
		$handles = [];

		foreach ( $this->steps as $step ) {
			$component = $step->get_component();
			if ( null === $component ) {
				continue;
			}

			if ( ! wp_script_is( $component['handle'], 'registered' ) ) {
				error_log( sprintf( '[woodev] setup wizard step "%s" names the script "%s", which is not registered', $step->get_id(), $component['handle'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a plugin misconfiguration.
				continue;
			}

			$handles[] = $component['handle'];
		}

		return $handles;
	}

	/**
	 * The current step graph: every registered step in order, then the terminal «finish» step.
	 *
	 * This is the one structure the client renders from, both at bootstrap and after every
	 * successful save or action (D3). A VISIBLE step carries its full descriptor (`visible: true`,
	 * `fields` with the current saved values, `content`, `skippable`, `validates`, `actions`,
	 * `component`); a step the server-side predicate hides carries only `id` and `visible: false`,
	 * so the client knows it exists, in which position, and that it must not show it. There is no
	 * per-step progress on the server (the spec rejects it), so "status" is the visibility.
	 *
	 * @since 2.0.2
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_step_graph(): array {
		$schema = $this->get_field_schema();
		$steps  = [];

		foreach ( $this->steps as $step ) {
			// Evaluated once per step per graph, over the state saved so far.
			if ( ! $step->is_visible() ) {
				$steps[] = [
					'id'      => $step->get_id(),
					'visible' => false,
				];
				continue;
			}

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
				try {
					$content = (string) call_user_func( $content );
				} catch ( \Throwable $e ) {
					// The content callback is the plugin's; a throw must not blank the whole wizard.
					Callback_Failure::log( sprintf( 'content failed for step "%s"', $step->get_id() ), $e );
					$content = '';
				}
			}

			$steps[] = [
				'id'          => $step->get_id(),
				'visible'     => true,
				'label'       => $step->get_label(),
				'type'        => $step->get_type(),
				'description' => $step->get_description(),
				'fields'      => $fields,
				'content'     => is_string( $content ) ? $content : '',
				'skippable'   => $step->is_skippable(),
				// Whether Continue must ask the server (validation callback) before advancing.
				// Settings steps always do (they persist); a content step only when it validates.
				'validates'   => null !== $step->get_validation_callback(),
				'component'   => $step->get_component(),
				'actions'     => array_values(
					array_map(
						static function ( Step_Action $action ): array {
							return $action->to_client_array();
						},
						$step->get_actions()
					)
				),
			];
		}

		$steps[] = [
			'id'          => 'finish',
			'visible'     => true,
			'label'       => \__( 'Готово', 'woodev-plugin-framework' ),
			'type'        => 'finish',
			'description' => '',
			'fields'      => [],
			'content'     => '',
			'skippable'   => false,
			'validates'   => false,
			'component'   => null,
			'actions'     => [],
		];

		return $steps;
	}

	/**
	 * Builds the PHP-driven bootstrap payload for the React shell.
	 *
	 * @since 2.0.2
	 *
	 * @return array<string,mixed>
	 */
	protected function get_bootstrap_data(): array {
		$steps = $this->get_step_graph();

		return [
			'pluginId'              => $this->get_id(),
			'pluginName'            => $this->plugin->get_plugin_name(),
			'headerLogoUrl'         => esc_url_raw( $this->get_header_image_url() ),
			'restRoot'              => esc_url_raw( rest_url( "woodev/v1/{$this->get_id()}/setup" ) ),
			'nonce'                 => wp_create_nonce( 'wp_rest' ),
			'state'                 => $this->get_state(),
			'adminUrl'              => esc_url_raw( admin_url() ),
			'steps'                 => $steps,
			'finishActions'         => $this->get_finish_actions(),
			'finishSecondaryActions' => $this->get_finish_secondary_actions(),
		];
	}

	/**
	 * Resolves the JSON field schema for referenced settings from the plugin's
	 * Settings API handler. Returns an empty map when the plugin has no handler.
	 *
	 * Each entry includes controlType, description (control description falling
	 * back to setting description), tooltip, and conditional range bounds
	 * (min/max/step) when the associated control provides them.
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

		return \Woodev\Framework\Settings\Field_Schema::from_handler( $handler );
	}

	/**
	 * Finish-screen "what's next" next-step cards. Override per plugin.
	 *
	 * Each card has keys: heading, title, description, actionLabel, url.
	 *
	 * @since 2.0.2
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function get_finish_actions(): array {
		$actions = [];
		if ( $this->plugin->get_documentation_url() ) {
			$actions[] = [
				'heading'     => \__( 'Документация', 'woodev-plugin-framework' ),
				'title'       => \__( 'Тонкая настройка', 'woodev-plugin-framework' ),
				'description' => \__( 'Подробнее о возможностях плагина.', 'woodev-plugin-framework' ),
				'actionLabel' => \__( 'Читать', 'woodev-plugin-framework' ),
				'url'         => \esc_url_raw( $this->plugin->get_documentation_url() ),
			];
		}

		return $actions;
	}

	/**
	 * Finish-screen secondary action links (settings, review, etc.).
	 *
	 * Each item has keys: label, icon ('settings' | 'review'), url.
	 * Items are only added when the plugin returns a non-empty URL from the
	 * corresponding accessor; absent or empty URLs are silently omitted.
	 *
	 * @since 2.0.2
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function get_finish_secondary_actions(): array {
		$actions = [];

		$settings_url = method_exists( $this->plugin, 'get_settings_url' ) ? $this->plugin->get_settings_url() : '';
		if ( $settings_url ) {
			$actions[] = [
				'label' => \__( 'Настройки', 'woodev-plugin-framework' ),
				'icon'  => 'settings',
				'url'   => \esc_url_raw( $settings_url ),
			];
		}

		$reviews_url = method_exists( $this->plugin, 'get_reviews_url' ) ? $this->plugin->get_reviews_url() : '';
		if ( $reviews_url ) {
			$actions[] = [
				'label' => \__( 'Оставить отзыв', 'woodev-plugin-framework' ),
				'icon'  => 'review',
				'url'   => \esc_url_raw( $reviews_url ),
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

		// Shown until the merchant REACHED THE END: «Настрою позже» (skipped) keeps the
		// notice, so the wizard stays reachable (D1).
		if ( $this->is_complete() ) {
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
		if ( ! $this->is_complete() ) {
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
