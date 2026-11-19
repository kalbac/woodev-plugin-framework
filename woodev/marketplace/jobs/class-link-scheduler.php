<?php
/**
 * Scheduling of the link jobs.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler calls for the two read-only link jobs:
 *
 * - `woodev_{plugin id}_link_cards( account_id, category_id, attempt )` — page the cabinet, match by article, link, build the
 *   near-match report. **{@see self::link_by_article()} is the entry point** the tools button and the product-tab action call;
 *   `category_id` 0 means the whole shop.
 * - `woodev_{plugin id}_refresh_cards( account_id, attempt )` — refreshes the stored list of the marketplace's cards
 *   ({@see \Woodev\Framework\Marketplace\Data\Wpdb_Card_Store}) page by page; a run that spent its page budget queues its own
 *   continuation.
 * - `woodev_{plugin id}_refresh_card_rows( account_id, offer_ids, attempt )` — rewrites the rows of the cards a notification named
 *   (2.1.13).
 * - `woodev_{plugin id}_refresh_cards_daily()` — a daily recurring run that queues `refresh_cards` for every account whose list
 *   was loaded once (2.1.13).
 *
 * None of them writes to the marketplace. A request that queues one is still a request-time intent only.
 *
 * @since 2.1.0
 */
final class Link_Scheduler {
	public const LINK_JOB    = 'link_cards';
	public const REFRESH_JOB = 'refresh_cards';
	public const ROWS_JOB    = 'refresh_card_rows';
	public const DAILY_JOB   = 'refresh_cards_daily';
	public const DAY         = 86400;

	/**
	 * Job names of the plugin.
	 *
	 * @var Job_Names
	 */
	private $names;

	/**
	 * Creates the scheduler.
	 *
	 * @since 2.1.0
	 *
	 * @param Job_Names $names Job names of the plugin.
	 */
	public function __construct( Job_Names $names ) {
		$this->names = $names;
	}

	/**
	 * Returns the hook of the linking job.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function link_hook(): string {
		return $this->names->hook( self::LINK_JOB );
	}

	/**
	 * Returns the hook of the snapshot refresh.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function refresh_hook(): string {
		return $this->names->hook( self::REFRESH_JOB );
	}

	/**
	 * Queues the linking of an account's cards to shop products by article.
	 *
	 * A run already waiting for the same account and category is not queued twice.
	 *
	 * @since 2.1.0
	 *
	 * @param int      $account_id  Account id.
	 * @param int|null $category_id Product category (`product_cat` term id) to link, or null for the whole shop.
	 * @return bool False when Action Scheduler is not available or refused the action.
	 */
	public function link_by_article( int $account_id, ?int $category_id = null ): bool {
		return $this->enqueue( $this->link_hook(), [ $account_id, (int) $category_id, 0 ], 0 );
	}

	/**
	 * Queues the refresh of the cards snapshot.
	 *
	 * @since 2.1.0
	 *
	 * @param int $account_id Account id.
	 * @return bool False when Action Scheduler is not available or refused the action.
	 */
	public function refresh_cards( int $account_id ): bool {
		return $this->enqueue( $this->refresh_hook(), [ $account_id, 0 ], 0 );
	}

	/**
	 * Schedules a linking run again.
	 *
	 * @since 2.1.0
	 *
	 * @param int $account_id  Account id.
	 * @param int $category_id Category, 0 for the whole shop.
	 * @param int $attempt     Failed attempts so far.
	 * @param int $delay       Seconds to wait.
	 * @param int $put_offs    Waits so far (2.1.12); carried as a fourth argument only when it is not 0.
	 * @return bool False when Action Scheduler refused it.
	 */
	public function retry_link( int $account_id, int $category_id, int $attempt, int $delay, int $put_offs = 0 ): bool {
		$args = [ $account_id, $category_id, $attempt ];

		if ( $put_offs > 0 ) {
			$args[] = $put_offs;
		}

		return $this->enqueue( $this->link_hook(), $args, $delay );
	}

	/**
	 * Schedules a snapshot refresh again.
	 *
	 * @since 2.1.0
	 *
	 * @param int $account_id Account id.
	 * @param int $attempt    Failed attempts so far.
	 * @param int $delay      Seconds to wait.
	 * @param int $put_offs   Waits so far (2.1.12); carried as a third argument only when it is not 0.
	 * @return bool False when Action Scheduler refused it.
	 */
	public function retry_refresh( int $account_id, int $attempt, int $delay, int $put_offs = 0 ): bool {
		$args = [ $account_id, $attempt ];

		if ( $put_offs > 0 ) {
			$args[] = $put_offs;
		}

		return $this->enqueue( $this->refresh_hook(), $args, $delay );
	}

	/**
	 * Returns the hook of the card-row refresh.
	 *
	 * @since 2.1.13
	 *
	 * @return string
	 */
	public function rows_hook(): string {
		return $this->names->hook( self::ROWS_JOB );
	}

	/**
	 * Returns the hook of the daily run.
	 *
	 * @since 2.1.13
	 *
	 * @return string
	 */
	public function daily_hook(): string {
		return $this->names->hook( self::DAILY_JOB );
	}

	/**
	 * Queues the refresh of the rows of named cards (a card notification). The same cards already **waiting** are not queued twice;
	 * a read of them that is **running** is not enough, because it may have read the card before the change this notification
	 * announces — so one follow-up waits behind it (#1868). `as_has_scheduled_action()` counts a running action too, hence the
	 * pending-only lookup.
	 *
	 * @since 2.1.13
	 *
	 * @param int      $account_id Account id.
	 * @param string[] $offer_ids  Articles.
	 * @return bool False when Action Scheduler is not available or refused the action.
	 */
	public function refresh_rows( int $account_id, array $offer_ids ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$args    = [ $account_id, array_values( array_map( 'strval', $offer_ids ) ), 0 ];
		$pending = as_get_scheduled_actions(
			[
				'hook'     => $this->rows_hook(),
				'args'     => $args,
				'group'    => $this->names->group(),
				'status'   => 'pending',
				'per_page' => 1,
			],
			'ids'
		);

		if ( is_array( $pending ) && [] !== $pending ) {
			return true;
		}

		return 0 !== as_schedule_single_action( time(), $this->rows_hook(), $args, $this->names->group() );
	}

	/**
	 * Schedules a card-row refresh again.
	 *
	 * @since 2.1.13
	 *
	 * @param int      $account_id Account id.
	 * @param string[] $offer_ids  Articles.
	 * @param int      $attempt    Failed attempts so far.
	 * @param int      $delay      Seconds to wait.
	 * @param int      $put_offs   Waits so far; carried as a fourth argument only when it is not 0.
	 * @return bool False when Action Scheduler refused it.
	 */
	public function retry_rows( int $account_id, array $offer_ids, int $attempt, int $delay, int $put_offs = 0 ): bool {
		$args = [ $account_id, array_values( $offer_ids ), $attempt ];

		if ( $put_offs > 0 ) {
			$args[] = $put_offs;
		}

		return $this->enqueue( $this->rows_hook(), $args, $delay );
	}

	/**
	 * Makes sure the daily run exists.
	 *
	 * @since 2.1.13
	 *
	 * @return void
	 * @throws \RuntimeException When Action Scheduler did not create it.
	 */
	public function ensure_daily(): void {
		if ( as_has_scheduled_action( $this->daily_hook(), [], $this->names->group() ) ) {
			return;
		}

		if ( ! as_schedule_recurring_action( time() + self::DAY, self::DAY, $this->daily_hook(), [], $this->names->group() ) ) {
			throw new \RuntimeException( 'Action Scheduler did not create the daily cards list refresh.' );
		}
	}

	/**
	 * Schedules a single action unless the same one is already waiting.
	 *
	 * @param string       $hook  Hook.
	 * @param array<mixed> $args  Arguments.
	 * @param int          $delay Seconds to wait.
	 * @return bool
	 */
	private function enqueue( string $hook, array $args, int $delay ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		if ( 0 === $delay && as_has_scheduled_action( $hook, $args, $this->names->group() ) ) {
			return true;
		}

		return 0 !== as_schedule_single_action( time() + max( 0, $delay ), $hook, $args, $this->names->group() );
	}
}
