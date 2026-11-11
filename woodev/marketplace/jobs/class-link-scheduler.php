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
 * - `woodev_{plugin id}_refresh_cards( account_id, attempt )` — fills the ten-minute snapshot of the tab that lists the
 *   marketplace's cards.
 *
 * Neither writes to the marketplace. A request that queues one is still a request-time intent only.
 *
 * @since 2.0.30
 */
final class Link_Scheduler {
	public const LINK_JOB    = 'link_cards';
	public const REFRESH_JOB = 'refresh_cards';

	/**
	 * Job names of the plugin.
	 *
	 * @var Job_Names
	 */
	private $names;

	/**
	 * Creates the scheduler.
	 *
	 * @since 2.0.30
	 *
	 * @param Job_Names $names Job names of the plugin.
	 */
	public function __construct( Job_Names $names ) {
		$this->names = $names;
	}

	/**
	 * Returns the hook of the linking job.
	 *
	 * @since 2.0.30
	 *
	 * @return string
	 */
	public function link_hook(): string {
		return $this->names->hook( self::LINK_JOB );
	}

	/**
	 * Returns the hook of the snapshot refresh.
	 *
	 * @since 2.0.30
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
	 * @since 2.0.30
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
	 * @since 2.0.30
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
	 * @since 2.0.30
	 *
	 * @param int $account_id  Account id.
	 * @param int $category_id Category, 0 for the whole shop.
	 * @param int $attempt     Failed attempts so far.
	 * @param int $delay       Seconds to wait.
	 * @return bool False when Action Scheduler refused it.
	 */
	public function retry_link( int $account_id, int $category_id, int $attempt, int $delay ): bool {
		return $this->enqueue( $this->link_hook(), [ $account_id, $category_id, $attempt ], $delay );
	}

	/**
	 * Schedules a snapshot refresh again.
	 *
	 * @since 2.0.30
	 *
	 * @param int $account_id Account id.
	 * @param int $attempt    Failed attempts so far.
	 * @param int $delay      Seconds to wait.
	 * @return bool False when Action Scheduler refused it.
	 */
	public function retry_refresh( int $account_id, int $attempt, int $delay ): bool {
		return $this->enqueue( $this->refresh_hook(), [ $account_id, $attempt ], $delay );
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
