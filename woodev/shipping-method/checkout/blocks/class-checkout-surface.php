<?php
/**
 * Which checkout surface the current page renders (SP-11 C-1 #1087, C-2b #1089).
 *
 * @package Woodev\Framework\Shipping\Checkout\Blocks
 */

namespace Woodev\Framework\Shipping\Checkout\Blocks;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\Checkout_Surface' ) ) :

	/**
	 * Answers, for the page being rendered, whether it shows ONLY the Checkout block.
	 *
	 * One answer for every classic DOM adapter the shipping module boots on a checkout page: the
	 * checkout field layer ({@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::enqueue_assets()})
	 * and the pickup-point mount ({@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::enqueue_assets()}).
	 * Two copies of this rule would drift, and a page where one adapter thinks «classic» and the other
	 * «block» renders half a checkout.
	 *
	 * @since 2.0.2
	 */
	final class Checkout_Surface {

		/**
		 * Whether the page being rendered shows ONLY the Checkout block — no classic checkout form.
		 *
		 * Answered for the CURRENT page, from what it actually contains. «The store's configured
		 * checkout page uses the Checkout block» ({@see \Woodev_Blocks_Handler::is_checkout_block_in_use()})
		 * says nothing about a second page that carries `[woocommerce_checkout]`, and such a page is
		 * a checkout too (`is_checkout()` is true for it).
		 *
		 * Errs towards the classic adapter: a classic form anywhere on the page — the shortcode, or
		 * WooCommerce's «classic shortcode» block — keeps it, also next to a Checkout block; and so
		 * does a page whose content shows neither (a page builder's or a template's own output),
		 * unless it is the store's checkout page rendered by a block template that holds the block.
		 * Next to a block the classic adapter only scans a DOM it finds nothing in; without it a
		 * classic form loses its fields.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public static function is_block_only(): bool {
			if ( ! function_exists( 'has_block' ) ) {
				return false;
			}

			$post    = get_post();
			$content = is_object( $post ) && isset( $post->post_content ) ? (string) $post->post_content : '';

			if ( has_shortcode( $content, 'woocommerce_checkout' ) || has_block( 'woocommerce/classic-shortcode', $content ) ) {
				return false;
			}

			if ( has_block( 'woocommerce/checkout', $content ) ) {
				return true;
			}

			// A block theme may hold the Checkout block in its checkout TEMPLATE instead of the page;
			// that template renders the store's own checkout page and no other.
			return function_exists( 'wc_get_page_id' )
				&& is_page( wc_get_page_id( 'checkout' ) )
				&& class_exists( '\\Woodev_Blocks_Handler' )
				&& \Woodev_Blocks_Handler::is_checkout_block_in_use();
		}
	}

endif;
