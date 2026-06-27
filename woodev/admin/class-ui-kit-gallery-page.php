<?php
/**
 * Dev-only UI-kit component gallery page.
 *
 * Registers a `Woodev → UI Kit` submenu that renders every kit component and
 * state in isolation, for reviewing the design system before it is rolled out
 * across the real admin surfaces. Hidden unless explicitly enabled
 * (`WOODEV_UI_KIT_GALLERY` constant or the `woodev_ui_kit_gallery` filter), so
 * production clients never see it.
 *
 * @package woodev-plugin-framework
 */

namespace Woodev\Framework\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * UI-kit gallery admin page.
 *
 * @since 2.0.2
 */
class Ui_Kit_Gallery_Page {

	/** @var string admin page slug. */
	const PAGE_SLUG = 'woodev-ui-kit';

	/** @var \Woodev_Plugin|null plugin used to source framework asset paths. */
	private $plugin;

	/**
	 * @since 2.0.2
	 *
	 * @param \Woodev_Plugin|null $plugin plugin for asset paths (null in unit tests).
	 */
	public function __construct( ?\Woodev_Plugin $plugin = null ) {
		$this->plugin = $plugin;
	}

	/**
	 * Whether the gallery is enabled (dev gate).
	 *
	 * Static so callers can gate BEFORE instantiating (no object created when off).
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( defined( 'WOODEV_UI_KIT_GALLERY' ) && WOODEV_UI_KIT_GALLERY ) {
			return true;
		}

		return (bool) apply_filters( 'woodev_ui_kit_gallery', false );
	}

	/**
	 * Registers the admin_menu hook (only call when enabled).
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function add_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_page' ], 20 );
	}

	/**
	 * Registers the gallery submenu under the Woodev top-level menu.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function register_page(): void {
		$hook = add_submenu_page(
			'woodev',
			__( 'UI Kit', 'woodev-plugin-framework' ),
			__( 'UI Kit', 'woodev-plugin-framework' ),
			'manage_options',
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
		echo '<div class="wrap woodev-ui-kit-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Woodev UI Kit', 'woodev-plugin-framework' ) . '</h1>';
		echo '<hr class="wp-header-end">';
		echo '<div id="woodev-ui-kit-app"></div>';
		echo '<noscript><p>' . esc_html__( 'Для этой страницы нужен JavaScript.', 'woodev-plugin-framework' ) . '</p></noscript>';
		echo '</div>';
	}

	/**
	 * Enqueues the gallery React bundle.
	 *
	 * @internal
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! $this->plugin ) {
			return;
		}

		$asset_file = $this->plugin->get_framework_path() . '/assets/build/ui-kit-gallery/index.asset.php';
		$asset      = file_exists( $asset_file )
			? include $asset_file
			: [
				// Realistic fallback so a missing manifest still loads a working bundle
				// instead of a script with no wp.* deps.
				'dependencies' => [ 'react-jsx-runtime', 'wp-components', 'wp-element', 'wp-i18n' ],
				'version'      => $this->plugin->get_version(),
			];

		$build_url     = $this->plugin->get_framework_assets_url() . '/build/ui-kit-gallery';
		$style_path    = $this->plugin->get_framework_path() . '/assets/build/ui-kit-gallery/style-index.css';
		$style_version = file_exists( $style_path ) ? (string) filemtime( $style_path ) : $asset['version'];

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'woodev-ui-kit-gallery', $build_url . '/style-index.css', [ 'wp-components' ], $style_version );
		wp_enqueue_script( 'woodev-ui-kit-gallery', $build_url . '/index.js', $asset['dependencies'], $asset['version'], true );
	}
}
