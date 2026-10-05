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
					if ( ! is_admin() || ! self::has_active_pickup_method( $plugin ) || ! self::missing_parent_blocks() ) {
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
						$plugin->get_id_dasherized() . '-checkout-shipping-blocks-missing',
						[
							'notice_class'            => 'notice-error',
							'always_show_on_settings' => false,
						]
					);
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
				|| ! has_block( 'woocommerce/checkout-shipping-methods-block', $content );
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
			foreach ( $plugin->get_shipping_methods() as $method ) {
				if ( $method->is_pickup_shipping() && 'yes' === $method->enabled ) {
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
				$page                     = get_post( $page_id );
				$content                  = is_object( $page ) ? (string) $page->post_content : '';
				self::$page_results[ $page_id ] = self::is_missing_parent_blocks( $content );
			}

			return self::$page_results[ $page_id ];
		}
	}

endif;
