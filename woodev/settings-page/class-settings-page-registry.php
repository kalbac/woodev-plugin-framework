<?php
/**
 * Settings-page registry.
 *
 * @package Woodev\Framework\Settings
 */

namespace Woodev\Framework\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton aggregator for the neutral Woodev > Настройки page.
 *
 * Collects Settings_Provider descriptors from every active plugin's
 * get_settings_providers() seam plus framework-service stubs, resolves each
 * tab's capability, builds the aggregated schema, and registers the submenu,
 * React assets, and legacy-URL redirect.
 *
 * @since 2.0.2
 */
final class Settings_Page_Registry {

	/** @var string admin page slug. */
	const PAGE_SLUG = 'woodev-settings';

	/** @var self|null singleton. */
	private static $instance = null;

	/** @var \Woodev_Plugin[] plugins that may contribute providers. */
	private array $plugins = [];

	/** @var Settings_Provider[] framework-service providers (no owning plugin). */
	private array $services = [];

	/** @var bool whether the shared hooks were added. */
	private bool $hooked = false;

	/** @var array<int,array<string,mixed>>|null memoized tabs for this request. */
	private $tabs_cache = null;

	/** @var array<int,array<string,mixed>>|null memoized provider entries for this request. */
	private $entries_cache = null;

	/**
	 * Returns the singleton.
	 *
	 * @since 2.0.2
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Resolves a provider's capability per the spec rules.
	 *
	 * 1. explicit declaration wins; 2. WC-dependent plugin → manage_woocommerce;
	 * 3. otherwise neutral manage_options.
	 *
	 * @since 2.0.2
	 *
	 * @param string|null $declared       explicit capability or null.
	 * @param bool        $is_woocommerce whether the owning plugin is WC-dependent.
	 * @return string
	 */
	public static function resolve_capability( ?string $declared, bool $is_woocommerce ): string {
		if ( null !== $declared && '' !== $declared ) {
			return $declared;
		}

		return $is_woocommerce ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Resolves the page (submenu) capability: the broadest-reach cap among tabs.
	 *
	 * A WordPress submenu carries a single capability, but a user should be able
	 * to open the page when they can access ANY tab. manage_woocommerce reaches a
	 * wider audience (admins + shop managers) than admin-only manage_options, so
	 * the page opens under the broadest cap present; per-tab visibility + the
	 * per-provider REST route still enforce the exact capability. Custom caps not
	 * in the reach table rank narrowest (a known SP-1 limitation — real plugins
	 * use the two standard caps).
	 *
	 * @since 2.0.2
	 *
	 * @param string[] $caps resolved per-tab capabilities.
	 * @return string
	 */
	public static function resolve_page_capability( array $caps ): string {
		$reach = [
			'manage_woocommerce' => 2,
			'manage_options'     => 1,
		];

		$best      = 'manage_options';
		$best_rank = 0;

		foreach ( $caps as $cap ) {
			$rank = $reach[ $cap ] ?? 0;
			if ( $rank > $best_rank ) {
				$best_rank = $rank;
				$best      = $cap;
			}
		}

		return $best;
	}

	/**
	 * Builds the cap-filtered, deduped tab list (pure; injectable for tests).
	 *
	 * Each entry: [ 'provider' => Settings_Provider, 'is_woocommerce' => bool ].
	 * Dedup is by provider id (first wins). Tabs whose resolved capability the
	 * current user lacks are omitted.
	 *
	 * @since 2.0.2
	 *
	 * @param array<int,array<string,mixed>> $entries provider entries.
	 * @param callable                       $can     predicate: (string $cap) => bool.
	 * @return array<int,array<string,mixed>> tab schema array.
	 */
	public function build_tabs( array $entries, callable $can ): array {
		$tabs = [];
		$seen = [];

		foreach ( $entries as $entry ) {
			$provider = $entry['provider'];
			$id       = $provider->get_id();

			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			$capability = self::resolve_capability(
				$provider->get_declared_capability(),
				! empty( $entry['is_woocommerce'] )
			);

			if ( ! $can( $capability ) ) {
				continue;
			}

			$tabs[] = [
				'id'         => $id,
				'label'      => $provider->get_label(),
				'capability' => $capability,
				'sections'   => $this->build_sections( $provider ),
			];
		}

		return $tabs;
	}

	/**
	 * Builds a provider's section schema (each section's fields resolved).
	 *
	 * @since 2.0.2
	 *
	 * @param Settings_Provider $provider provider.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_sections( Settings_Provider $provider ): array {
		$handler  = $provider->get_handler();
		$sections = [];

		foreach ( $provider->get_sections() as $section ) {
			$entry = [
				'id'          => $section->get_id(),
				'label'       => $section->get_label(),
				'description' => $section->get_description(),
				'fields'      => Field_Schema::from_handler( $handler, $section->get_setting_ids() ),
			];

			if ( $section->is_connection() ) {
				$entry['is_connection'] = true;
				$entry['action_label']  = $section->get_action_label();
				$entry['supports_test'] = $handler instanceof \Woodev_Settings_Connection_Test;

				if ( $handler instanceof \Woodev_Settings_Connection_Status ) {
					$status = $handler->get_connection_status( $section->get_id() );
					if ( null !== $status ) {
						$entry['status'] = $status->to_array();
					}
				}
			}

			$sections[] = $entry;
		}

		return $sections;
	}

	/**
	 * Registers a plugin that may contribute settings providers.
	 *
	 * Idempotent per plugin id; adds the shared admin/REST hooks exactly once.
	 *
	 * @since 2.0.2
	 *
	 * @param \Woodev_Plugin $plugin owning plugin.
	 * @return void
	 */
	public function register_plugin( $plugin ): void {
		$this->plugins[ $plugin->get_id() ] = $plugin;
		$this->tabs_cache                    = null;
		$this->entries_cache                 = null;
		$this->add_hooks();
	}

	/**
	 * Registers a framework-service provider (no owning plugin → neutral cap).
	 *
	 * @since 2.0.2
	 *
	 * @param Settings_Provider $provider service provider.
	 * @return void
	 */
	public function register_service( Settings_Provider $provider ): void {
		$this->services[ $provider->get_id() ] = $provider;
		$this->tabs_cache                       = null;
		$this->entries_cache                    = null;
		$this->add_hooks();
	}

	/**
	 * Adds the shared menu / REST / redirect hooks exactly once.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function add_hooks(): void {
		if ( $this->hooked ) {
			return;
		}
		$this->hooked = true;

		add_action( 'admin_menu', [ $this, 'register_page' ], 40 );
		add_action( 'admin_init', [ $this, 'maybe_redirect_legacy' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest' ], 5 );
	}

	/**
	 * Collects provider entries from registered plugins + services (memoized).
	 *
	 * @since 2.0.2
	 *
	 * @return array<int,array<string,mixed>> entries [ provider, is_woocommerce ].
	 */
	public function collect_entries(): array {
		if ( null !== $this->entries_cache ) {
			return $this->entries_cache;
		}

		$entries = [];

		foreach ( $this->plugins as $plugin ) {
			$is_wc = $plugin instanceof \Woodev\Framework\Woocommerce_Plugin;

			foreach ( (array) $plugin->get_settings_providers() as $provider ) {
				if ( $provider instanceof Settings_Provider ) {
					$entries[] = [
						'provider'       => $provider,
						'is_woocommerce' => $is_wc,
					];
				}
			}
		}

		foreach ( $this->services as $provider ) {
			$entries[] = [
				'provider'       => $provider,
				'is_woocommerce' => false,
			];
		}

		$this->entries_cache = $entries;

		return $entries;
	}

	/**
	 * Builds the request's tab list for the current user (memoized).
	 *
	 * @since 2.0.2
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_tabs(): array {
		if ( null === $this->tabs_cache ) {
			$this->tabs_cache = $this->build_tabs(
				$this->collect_entries(),
				static function ( string $cap ): bool {
					return current_user_can( $cap );
				}
			);
		}

		return $this->tabs_cache;
	}

	/**
	 * Whether at least one tab is registered (regardless of current user).
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function has_providers(): bool {
		return ! empty( $this->collect_entries() );
	}

	/**
	 * Returns the page (submenu) capability: broadest reach among ALL tabs.
	 *
	 * Uses the full provider set (not the current user's visible subset) so the
	 * submenu cap is stable across users.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_page_capability(): string {
		$caps = [];

		foreach ( $this->collect_entries() as $entry ) {
			$caps[] = self::resolve_capability(
				$entry['provider']->get_declared_capability(),
				! empty( $entry['is_woocommerce'] )
			);
		}

		return self::resolve_page_capability( $caps );
	}

	/**
	 * Returns the resolved capability for one provider id, or null if unknown.
	 *
	 * @since 2.0.2
	 *
	 * @param string $provider_id provider id.
	 * @return string|null
	 */
	public function get_provider_capability( string $provider_id ): ?string {
		foreach ( $this->collect_entries() as $entry ) {
			if ( $entry['provider']->get_id() === $provider_id ) {
				return self::resolve_capability(
					$entry['provider']->get_declared_capability(),
					! empty( $entry['is_woocommerce'] )
				);
			}
		}

		return null;
	}

	/**
	 * Returns the provider for one id, or null.
	 *
	 * @since 2.0.2
	 *
	 * @param string $provider_id provider id.
	 * @return Settings_Provider|null
	 */
	public function get_provider( string $provider_id ): ?Settings_Provider {
		foreach ( $this->collect_entries() as $entry ) {
			if ( $entry['provider']->get_id() === $provider_id ) {
				return $entry['provider'];
			}
		}

		return null;
	}

	/**
	 * Registers the Настройки submenu when ≥1 provider is present.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function register_page(): void {
		if ( ! $this->has_providers() ) {
			return;
		}

		$hook = add_submenu_page(
			'woodev',
			__( 'Настройки Woodev', 'woodev-plugin-framework' ),
			__( 'Настройки', 'woodev-plugin-framework' ),
			$this->get_page_capability(),
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);

		if ( $hook ) {
			add_action( "admin_print_scripts-{$hook}", [ $this, 'enqueue_assets' ] );
		}
	}

	/**
	 * Renders the React mount node.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function render_page(): void {
		echo '<div class="wrap woodev-settings-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Настройки Woodev', 'woodev-plugin-framework' ) . '</h1>';
		echo '<hr class="wp-header-end">';
		echo '<div id="woodev-settings-app"></div>';
		echo '<noscript><p>' . esc_html__( 'Для страницы настроек нужен JavaScript. Включите его и обновите страницу.', 'woodev-plugin-framework' ) . '</p></noscript>';
		echo '</div>';
	}

	/**
	 * Enqueues the settings-page React bundle + inline bootstrap.
	 *
	 * Mirrors Woodev_Admin_Pages::load_licenses_page_scripts(). The schema is NOT
	 * inlined — the app fetches it from GET woodev/v1/settings (cap-filtered
	 * server-side).
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$plugin = $this->get_asset_plugin();

		if ( ! $plugin ) {
			return;
		}

		$asset_file = $plugin->get_framework_path() . '/assets/build/settings-page/index.asset.php';

		if ( file_exists( $asset_file ) ) {
			$asset = include $asset_file;
		} else {
			error_log( sprintf( '[woodev] Settings page asset manifest missing: %s', $asset_file ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a missing build artifact.
			$asset = [
				'dependencies' => [],
				'version'      => $plugin->get_version(),
			];
		}

		$build_url     = $plugin->get_framework_assets_url() . '/build/settings-page';
		$style_path    = $plugin->get_framework_path() . '/assets/build/settings-page/style-index.css';
		$style_version = file_exists( $style_path ) ? (string) filemtime( $style_path ) : $asset['version'];

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'woodev-settings-page', $build_url . '/style-index.css', [ 'wp-components' ], $style_version );
		wp_enqueue_script( 'woodev-settings-page', $build_url . '/index.js', $asset['dependencies'], $asset['version'], true );

		wp_add_inline_script(
			'woodev-settings-page',
			'window.woodevSettings = ' . wp_json_encode(
				[
					'restRoot' => esc_url_raw( rest_url( \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE . '/settings' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'adminUrl' => esc_url_raw( admin_url() ),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Returns any registered plugin to source framework asset paths/version from.
	 *
	 * @since 2.0.2
	 *
	 * @return \Woodev_Plugin|null
	 */
	private function get_asset_plugin() {
		$plugin = reset( $this->plugins );

		return false !== $plugin ? $plugin : null;
	}

	/**
	 * Redirects a provider's legacy settings URL to its new tab.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy(): void {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL routing, no state change.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $request_uri ) {
			return;
		}

		foreach ( $this->collect_entries() as $entry ) {
			$provider    = $entry['provider'];
			$legacy_page = $provider->get_legacy_page();

			if ( null === $legacy_page ) {
				continue;
			}

			if ( false === strpos( $request_uri, $legacy_page ) ) {
				continue;
			}

			$capability = self::resolve_capability( $provider->get_declared_capability(), ! empty( $entry['is_woocommerce'] ) );
			if ( ! current_user_can( $capability ) ) {
				continue;
			}

			wp_safe_redirect(
				admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . rawurlencode( $provider->get_id() ) )
			);
			exit;
		}
	}

	/**
	 * Registers the aggregated REST controller through the woodev/v1 registrar.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function register_rest(): void {
		$plugin = $this->get_asset_plugin();

		if ( ! $plugin ) {
			return;
		}

		if ( ! class_exists( 'Woodev_REST_API_Settings_Page' ) ) {
			require_once $plugin->get_framework_path() . '/rest-api/controllers/class-rest-api-settings-page.php';
		}

		\Woodev_REST_V1_Registrar::register_controller( new \Woodev_REST_API_Settings_Page( $this ) );
	}

	/**
	 * Resets registration state. Test-only.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function reset_for_tests(): void {
		// Remove the hooks add_hooks() registered so a fresh register_plugin()
		// does not double-bind register_page() (which would duplicate the submenu).
		remove_action( 'admin_menu', [ $this, 'register_page' ], 40 );
		remove_action( 'admin_init', [ $this, 'maybe_redirect_legacy' ] );
		remove_action( 'rest_api_init', [ $this, 'register_rest' ], 5 );

		$this->plugins       = [];
		$this->services      = [];
		$this->tabs_cache    = null;
		$this->entries_cache = null;
		$this->hooked        = false;
	}
}
