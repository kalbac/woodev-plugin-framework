<?php
/**
 * The import of a marketplace's order history, which must not send WooCommerce e-mails.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * The first poll of an account reads weeks of orders at once, and WooCommerce mails the shop's administrator for every order it
 * sees created and for every status it sees change: hundreds of «New order» and «Cancelled order» letters about orders that were
 * placed, paid and often finished long ago (#1925).
 *
 * Two small pieces keep those letters back without touching any other order:
 *
 * - {@see self::during()} marks the stretch of code that writes history. The posting jobs open it around the first poll of an
 *   account, and around every `refresh_posting` that poll queued ({@see \Woodev\Framework\Marketplace\Jobs\Posting_Jobs});
 * - {@see self::quietly()} is what {@see Wc_Order_Store} wraps its own saves in. Inside the mark it turns WooCommerce's e-mails
 *   off for the length of that one save, and puts them back in `finally`, so a write that throws leaves them on. Outside the
 *   mark it does nothing at all.
 *
 * The mark is a counter of the running request, not an option: it cannot outlive the code that opened it, and a checkout order
 * created in the same request or cron tick, outside the stretch, still sends its mails. WooCommerce's own way to switch an e-mail
 * off is used — the `woocommerce_email_enabled_{id}` filter — so no mail is built or attempted, and no «failed to send» note is
 * ever written for it.
 *
 * @since 2.1.15
 */
final class History_Import {
	/**
	 * Depth of the running {@see self::during()} calls.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Ids of the e-mails currently switched off, empty when none is. A save inside a save keeps the outer switch.
	 *
	 * @var string[]
	 */
	private static $silenced = [];

	/**
	 * Runs a piece of work that writes history.
	 *
	 * @since 2.1.15
	 *
	 * @param callable $work The work.
	 * @return mixed What the work returns.
	 */
	public static function during( callable $work ) {
		++self::$depth;

		try {
			return $work();
		} finally {
			--self::$depth;
		}
	}

	/**
	 * Tells whether history is being written now.
	 *
	 * @since 2.1.15
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return self::$depth > 0;
	}

	/**
	 * Runs one order save; while history is being written, WooCommerce sends no e-mail during it.
	 *
	 * @since 2.1.15
	 *
	 * @param callable $save The save.
	 * @return mixed What the save returns.
	 */
	public static function quietly( callable $save ) {
		if ( ! self::is_active() || [] !== self::$silenced ) {
			return $save();
		}

		self::$silenced = self::email_ids();

		foreach ( self::$silenced as $id ) {
			add_filter( 'woocommerce_email_enabled_' . $id, [ self::class, 'off' ], PHP_INT_MAX );
		}

		try {
			return $save();
		} finally {
			foreach ( self::$silenced as $id ) {
				remove_filter( 'woocommerce_email_enabled_' . $id, [ self::class, 'off' ], PHP_INT_MAX );
			}

			self::$silenced = [];
		}
	}

	/**
	 * The answer of `woocommerce_email_enabled_{id}` while the e-mails are off. Public only because WordPress calls it.
	 *
	 * @since 2.1.15
	 *
	 * @return bool
	 */
	public static function off(): bool {
		return false;
	}

	/**
	 * Ids of every e-mail WooCommerce knows, the ones other plugins add included.
	 *
	 * @return string[]
	 */
	private static function email_ids(): array {
		$ids = [];

		foreach ( WC()->mailer()->get_emails() as $email ) {
			$ids[] = (string) $email->id;
		}

		return $ids;
	}
}
