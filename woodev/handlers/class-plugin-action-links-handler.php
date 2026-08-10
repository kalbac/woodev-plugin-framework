<?php
/**
 * Plugin action links handler.
 *
 * @package Woodev\Framework\Handlers
 */

namespace Woodev\Framework\Handlers;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Handlers\Plugin_Action_Links_Handler' ) ) :
	/**
	 * Builds the "Settings / Docs / Support / Review / License" plugin action links.
	 *
	 * This handler owns the `plugin_action_links_{basename}` filter registration.
	 * Unlike Cron_Handler/Translation_Handler, the filter callback is bound to the
	 * *plugin* instance ( `[ $plugin, 'plugin_action_links' ] `), not to this handler:
	 * `Woodev_Payment_Gateway_Plugin::plugin_action_links()` overrides
	 * `Woodev_Plugin::plugin_action_links()` and calls `parent::plugin_action_links()`
	 * to compose the base links with its own per-gateway links. Binding the filter to
	 * the handler directly would bypass that override entirely. `Woodev_Plugin` keeps
	 * a thin `plugin_action_links()` method that delegates to `build_links()` below, so
	 * the override chain and the filter name both stay intact.
	 *
	 * The filter name and the produced link shape are preserved exactly as they were
	 * inline on Woodev_Plugin to keep the installed-site contract stable.
	 *
	 * @since 2.0.1
	 */
	class Plugin_Action_Links_Handler {

		/** @var \Woodev_Plugin current plugin instance */
		private \Woodev_Plugin $plugin;

		/**
		 * Plugin action links handler constructor.
		 *
		 * @since 2.0.1
		 *
		 * @param \Woodev_Plugin $plugin the plugin instance
		 */
		public function __construct( \Woodev_Plugin $plugin ) {

			$this->plugin = $plugin;

			// Bound to the plugin instance (not to $this) so a plugin_action_links()
			// override on the concrete plugin class still fires — see class docblock.
			add_filter(
				'plugin_action_links_' . plugin_basename( $plugin->get_plugin_file() ),
				array( $plugin, 'plugin_action_links' )
			);
		}

		/**
		 * Builds the plugin action links.  This will only be called if the plugin is active.
		 *
		 * @since 2.0.1
		 *
		 * @param array $actions associative array of action names to anchor tags
		 *
		 * @return array associative array of plugin action links
		 */
		public function build_links( $actions ) {

			$custom_actions = [];

			if ( $this->plugin->get_settings_link( $this->plugin->get_id() ) ) {
				$custom_actions['configure'] = $this->plugin->get_settings_link( $this->plugin->get_id() );
			}

			if ( $this->plugin->get_documentation_url() ) {
				$custom_actions['docs'] = sprintf( '<a href="%s">%s</a>', $this->plugin->get_documentation_url(), 'Документация' );
			}

			if ( $this->plugin->get_support_url() ) {
				$custom_actions['support'] = sprintf( '<a href="%s">%s</a>', $this->plugin->get_support_url(), 'Поддержка' );
			}

			if ( $this->plugin->get_reviews_url() ) {
				$custom_actions['review'] = sprintf( '<a href="%s">%s</a>', $this->plugin->get_reviews_url(), 'Оставить отзыв' );
			}

			if ( $this->plugin->is_need_license() && $this->plugin->get_license_instance()->get_license_settings_url() ) {
				$license_text              = $this->plugin->get_license_instance()->is_license_valid() ? 'Лицензия' : 'Указать лицензию';
				$custom_actions['license'] = sprintf( '<a href="%s">%s</a>', $this->plugin->get_license_instance()->get_license_settings_url(), esc_html( $license_text ) );
			}

			// add the links to the front of the actions list
			return array_merge( $custom_actions, $actions );
		}
	}

endif;
