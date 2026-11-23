<?php
/**
 * An amount of money as plain text, the way the shop writes a price.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * «2 120,00 ₽»: `wc_price()` with its markup taken off, for a table cell that shows text. The orders table and the marketplace cards
 * table both say an amount through this class, so a price reads the same on every screen.
 *
 * @since 2.1.22
 */
final class Plain_Price {
	/**
	 * The amount as the shop writes a price.
	 *
	 * @since 2.1.22
	 *
	 * @param string|int|float $amount   The amount.
	 * @param string           $currency ISO currency code; '' for the shop's own currency.
	 * @return string
	 */
	public static function format( $amount, string $currency = '' ): string {
		$args = '' === $currency ? [] : [ 'currency' => $currency ];

		return html_entity_decode( wp_strip_all_tags( (string) wc_price( $amount, $args ) ), ENT_QUOTES, 'UTF-8' );
	}
}
