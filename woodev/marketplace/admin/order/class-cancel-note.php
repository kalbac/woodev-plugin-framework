<?php
/**
 * The order note of a cancellation.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * The one sentence both screens write on an order the merchant cancelled: with the reason, or without it when the marketplace's
 * cancellation names none.
 *
 * @since 2.1.27
 */
final class Cancel_Note {
	/**
	 * Builds the note.
	 *
	 * @since 2.1.27
	 *
	 * @param string $noun           The marketplace's word for a posting, capitalised.
	 * @param string $posting_number Posting number.
	 * @param string $marketplace    Marketplace name.
	 * @param string $reason         The reason as the marketplace names it; empty for none.
	 * @return string
	 */
	public static function text( string $noun, string $posting_number, string $marketplace, string $reason ): string {
		if ( '' === $reason ) {
			/* translators: 1: the marketplace's word for a posting, capitalised: «Отправление», 2: posting number, 3: marketplace name. */
			return sprintf( __( '%1$s %2$s отменено: %3$s, из WooCommerce.', 'woodev-plugin-framework' ), $noun, $posting_number, $marketplace );
		}

		/* translators: 1: the marketplace's word for a posting, capitalised: «Отправление», 2: posting number, 3: marketplace name, 4: cancellation reason. */
		return sprintf( __( '%1$s %2$s отменено: %3$s, из WooCommerce. Причина: %4$s.', 'woodev-plugin-framework' ), $noun, $posting_number, $marketplace, $reason );
	}
}
