<?php
/**
 * The shared dialog of the plain-PHP marketplace screens: its stylesheet and script.
 *
 * Every dialog of the marketplace admin is drawn by `css/admin/woodev-dialog.css`. The React screens get it through the SCSS of
 * their bundle (and `src/components/woodev-dialog.tsx`); a plain-PHP screen (the product tab, the order metabox) enqueues it here
 * and builds its dialog with `js/admin/woodev-dialog.js` (`window.WoodevDialog`) or prints the same markup itself.
 *
 * @package Woodev\Framework\Marketplace\Admin
 */

namespace Woodev\Framework\Marketplace\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the shared dialog's assets once.
 *
 * @since 2.1.13
 */
final class Dialog_Assets {

	/** Handle of both the script and the style (they live in separate WordPress registries). */
	public const HANDLE = 'woodev-marketplace-dialog';

	/**
	 * Enqueues the dialog's stylesheet and script and returns their handle, to list as a dependency of the screen's own assets.
	 *
	 * @since 2.1.13
	 *
	 * @param string $assets_url Folder URL of the marketplace module's assets, with a trailing slash.
	 * @param string $version    Plugin version, for the cache of the assets.
	 * @return string The handle.
	 */
	public static function enqueue( string $assets_url, string $version ): string {
		$suffix = \Woodev_Plugin::get_assets_suffix();

		wp_enqueue_style( self::HANDLE, $assets_url . 'css/admin/woodev-dialog' . $suffix . '.css', [], $version );
		wp_enqueue_script( self::HANDLE, $assets_url . 'js/admin/woodev-dialog' . $suffix . '.js', [], $version, true );

		return self::HANDLE;
	}
}
