<?php
/**
 * The import of a marketplace's order history, which must not send WooCommerce e-mails.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Orders\Order_DTO;

defined( 'ABSPATH' ) || exit;

/**
 * The first poll of an account reads weeks of orders at once, and WooCommerce mails the shop's administrator for every order it
 * sees created and for every status it sees change: hundreds of «New order» and «Cancelled order» letters about orders that were
 * placed, paid and often finished long ago (#1925).
 *
 * **Which writes are history.** The marketplace's own plugin decides when and how its orders are read, so the answer cannot be an
 * argument it has to pass. The posting jobs keep, per account, the moment its first poll began (the account's *cutoff*) and run
 * every poll and every refresh of the account inside {@see self::during()} with it. An order is history when its first write —
 * {@see Wc_Order_Store::create()} and the {@see Wc_Order_Store::update()} that gives it its first real status — saves an order the
 * marketplace had created before that cutoff. That holds however many polls the import takes, in whatever order the refresh jobs
 * run, and an order placed after the cutoff mails as WooCommerce always does; so does a later status change of an imported order.
 *
 * **How the mail is held back.** While a history order is saved, WooCommerce's own e-mail dispatchers — the callbacks
 * `WC_Emails::send_transactional_email()` and, with deferred e-mails on, `WC_Emails::queue_transactional_email()` — are taken off
 * every action they listen to, and put back in `finally`. Nothing is built, queued or attempted, so a deferred e-mail has no later
 * request in which to escape, no «failed to send» note is written, and the low-stock, no-stock and backorder notices of the
 * stock the order takes (which never ask `woocommerce_email_enabled_{id}`) are held back with the rest. Only the one save is quiet,
 * so no later e-mail of that order is lost.
 *
 * @since 2.1.15
 */
final class History_Import {
	/**
	 * The two callbacks WooCommerce hangs on every e-mail action: send now, or queue for later when e-mails are deferred.
	 */
	private const DISPATCHERS = [ 'send_transactional_email', 'queue_transactional_email' ];

	/**
	 * The number of arguments WooCommerce registers its dispatchers with.
	 */
	private const ACCEPTED_ARGS = 10;

	/**
	 * The cutoff of the running {@see self::during()}; 0 when none runs.
	 *
	 * @var int
	 */
	private static $cutoff = 0;

	/**
	 * The dispatchers found in the running {@see self::during()}, looked up once per run: scanning every action of the site costs more
	 * than the hundreds of saves of one import together may spend on it. Null before the first save.
	 *
	 * @var array<int,array{0:string,1:array{0:string,1:string},2:int}>|null
	 */
	private static $known;

	/**
	 * The dispatchers taken off, `[ action, callback, priority ]`; empty when none is.
	 *
	 * @var array<int,array{0:string,1:array{0:string,1:string},2:int}>
	 */
	private static $taken_off = [];

	/**
	 * Runs a piece of work of an account whose history is being imported, or was.
	 *
	 * @since 2.1.15
	 *
	 * @param int      $cutoff Unix time the account's first poll began; 0 for an account without a history, which makes the call a plain one.
	 * @param callable $work   The work.
	 * @return mixed What the work returns.
	 */
	public static function during( int $cutoff, callable $work ) {
		$outer        = self::$cutoff;
		self::$cutoff = max( 0, $cutoff );

		try {
			return $work();
		} finally {
			self::$cutoff = $outer;

			if ( 0 === $outer ) {
				self::$known = null;
			}
		}
	}

	/**
	 * The cutoff of the running work.
	 *
	 * @since 2.1.15
	 *
	 * @return int Unix time; 0 when the work has no history.
	 */
	public static function cutoff(): int {
		return self::$cutoff;
	}

	/**
	 * Runs one save of an order; when the save writes a history order, WooCommerce sends and queues no e-mail during it.
	 *
	 * @since 2.1.15
	 *
	 * @param Order_DTO $order       The remote order the save writes.
	 * @param bool      $first_write True for the first write of the order: its creation, and the save that gives it its first real status.
	 * @param callable  $save        The save.
	 * @return mixed What the save returns.
	 */
	public static function quietly( Order_DTO $order, bool $first_write, callable $save ) {
		if ( [] !== self::$taken_off || ! self::is_history( $order, $first_write ) ) {
			return $save();
		}

		self::$known     = self::$known ?? self::dispatchers();
		self::$taken_off = self::$known;

		foreach ( self::$taken_off as $dispatcher ) {
			remove_action( $dispatcher[0], $dispatcher[1], $dispatcher[2] );
		}

		try {
			return $save();
		} finally {
			foreach ( self::$taken_off as $dispatcher ) {
				add_action( $dispatcher[0], $dispatcher[1], $dispatcher[2], self::ACCEPTED_ARGS );
			}

			self::$taken_off = [];
		}
	}

	/**
	 * Tells whether the write is the first of an order the marketplace created before the account's cutoff.
	 *
	 * An order whose creation time cannot be read counts as live: an e-mail too many costs less than one lost.
	 *
	 * @param Order_DTO $order       The remote order.
	 * @param bool      $first_write True for the first write of the order.
	 * @return bool
	 */
	private static function is_history( Order_DTO $order, bool $first_write ): bool {
		if ( ! $first_write || self::$cutoff <= 0 ) {
			return false;
		}

		$created = strtotime( (string) ( $order->dates()['created_at'] ?? '' ) );

		return false !== $created && $created < self::$cutoff;
	}

	/**
	 * Finds every action WooCommerce's e-mail dispatchers listen to, whichever e-mail actions the shop has.
	 *
	 * @return array<int,array{0:string,1:array{0:string,1:string},2:int}>
	 */
	private static function dispatchers(): array {
		$found = [];

		foreach ( array_keys( (array) ( $GLOBALS['wp_filter'] ?? [] ) ) as $action ) {
			foreach ( self::DISPATCHERS as $method ) {
				$callback = [ 'WC_Emails', $method ];
				$priority = has_action( (string) $action, $callback );

				if ( false !== $priority ) {
					$found[] = [ (string) $action, $callback, (int) $priority ];
				}
			}
		}

		return $found;
	}
}
