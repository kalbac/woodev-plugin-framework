<?php
/**
 * Detects a block checkout page missing the shipping parents required by pickup integrations.
 *
 * @package Woodev\Framework\Shipping\Checkout
 */

namespace Woodev\Framework\Shipping\Checkout;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\Checkout_Parent_Block_Notice' ) ) :

	/**
	 * Warns administrators when saved checkout markup omits WooCommerce shipping parent blocks.
	 *
	 * @since 2.0.2
	 */
	final class Checkout_Parent_Block_Notice {

		/** @var array<int,bool> Request-local page result cache. */
		private static array $page_results = [];

		/** @var bool Whether this site's notice was added during this request. */
		private static bool $notice_added = false;

		/**
		 * Registers the admin notice for a carrier plugin.
		 *
		 * @since 2.0.2
		 * @param \Woodev\Framework\Shipping\Shipping_Plugin $plugin Shipping plugin instance.
		 * @return void
		 */
		public static function register( \Woodev\Framework\Shipping\Shipping_Plugin $plugin ): void {
			add_action(
				'admin_notices',
				static function () use ( $plugin ): void {
					if ( self::$notice_added || ! is_admin() || ! self::missing_parent_blocks() || ! self::has_active_pickup_method( $plugin ) ) {
						return;
					}

					$page_id = wc_get_page_id( 'checkout' );
					$url     = get_edit_post_link( $page_id );
					$message = __( 'На странице оформления заказа отсутствуют блоки «Адрес доставки» или «Способы доставки». Восстановите их в редакторе страницы.', 'woodev-plugin-framework' );

					if ( $url ) {
						$message .= ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Открыть страницу оформления заказа', 'woodev-plugin-framework' ) . '</a>';
					}

					$plugin->get_admin_notice_handler()->add_admin_notice(
						$message,
						'woodev-checkout-shipping-blocks-missing',
						[
							'notice_class'            => 'notice-error',
							'always_show_on_settings' => false,
						]
					);
					self::$notice_added = true;
				}
			);
		}

		/**
		 * Checks the configured checkout page's saved content for a block checkout missing either parent.
		 *
		 * @since 2.0.2
		 * @param string $content Saved page content.
		 * @return bool
		 */
		public static function is_missing_parent_blocks( string $content ): bool {
			if ( ! function_exists( 'has_block' ) || ! has_block( 'woocommerce/checkout', $content ) ) {
				return false;
			}
			if ( ( function_exists( 'has_shortcode' ) && has_shortcode( $content, 'woocommerce_checkout' ) )
				|| has_block( 'woocommerce/classic-shortcode', $content ) ) {
				return false;
			}

			return ! has_block( 'woocommerce/checkout-shipping-address-block', $content )
				&& ! has_block( 'woocommerce/checkout-shipping-methods-block', $content );
		}

		/**
		 * Applies the pickup-capability gate to saved checkout content.
		 *
		 * @since 2.0.2
		 * @param string $content Saved page content.
		 * @param bool   $has_pickup_method Whether an active pickup method exists.
		 * @return bool
		 */
		public static function should_warn( string $content, bool $has_pickup_method ): bool {
			return $has_pickup_method && self::is_missing_parent_blocks( $content );
		}

		/** @param \Woodev\Framework\Shipping\Shipping_Plugin $plugin Shipping plugin. */
		private static function has_active_pickup_method( \Woodev\Framework\Shipping\Shipping_Plugin $plugin ): bool {
			if ( ! class_exists( '\WC_Shipping_Zones' ) || ! class_exists( '\WC_Shipping_Zone' ) ) {
				return false;
			}

			$plugin_methods = [];
			foreach ( $plugin->get_shipping_methods() as $method ) {
				$plugin_methods[ $method->id ] = true;
			}

			$zones = \WC_Shipping_Zones::get_zones();
			foreach ( $zones as $zone_data ) {
				$zone = new \WC_Shipping_Zone( (int) $zone_data['zone_id'] );
				if ( self::zone_has_pickup_method( $zone, $plugin_methods ) ) {
					return true;
				}
			}

			return self::zone_has_pickup_method( new \WC_Shipping_Zone( 0 ), $plugin_methods );
		}

		/** @param \WC_Shipping_Zone $zone Zone to inspect. @param array<string,bool> $plugin_methods Plugin method ids. */
		private static function zone_has_pickup_method( \WC_Shipping_Zone $zone, array $plugin_methods ): bool {
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( $method instanceof \Woodev\Framework\Shipping\Shipping_Method && isset( $plugin_methods[ $method->id ] ) && $method->is_pickup_shipping() ) {
					return true;
				}
			}

			return false;
		}

		private static function missing_parent_blocks(): bool {
			$page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
			if ( $page_id < 1 ) {
				return false;
			}

			if ( ! array_key_exists( $page_id, self::$page_results ) ) {
				$page = get_post( $page_id );
				if ( ! is_object( $page ) || ! in_array( $page->post_status ?? '', [ 'publish', 'private' ], true ) ) {
					self::$page_results[ $page_id ] = false;
				} else {
					self::$page_results[ $page_id ] = self::is_missing_parent_blocks( (string) $page->post_content );
				}
			}

			return self::$page_results[ $page_id ];
		}
	}

endif;
