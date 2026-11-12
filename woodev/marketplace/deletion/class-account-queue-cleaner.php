<?php
/**
 * Takes an account's queued jobs off the Action Scheduler queue.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Deletion;

defined( 'ABSPATH' ) || exit;

/**
 * Every job of a marketplace plugin is an Action Scheduler action in the plugin's group, under a hook named `woodev_{plugin id}_…`,
 * and takes the account id as its first argument. The exceptions are not the account's:
 *
 * - the sweeps that serve every account carry `0`;
 * - the label poll carries a task id first. It needs no cancelling: its task is deleted with the account, and a poll that finds no
 *   task ends.
 *
 * Only pending actions are cancelled. A run that is going now holds the account's claim, so the deletion waits for it.
 *
 * @since 2.1.5
 */
final class Account_Queue_Cleaner {
	private const PAGE = 100;

	/**
	 * Action Scheduler group of the plugin.
	 *
	 * @var string
	 */
	private $group;

	/**
	 * Hook prefix of the plugin (`woodev_{plugin id}_`).
	 *
	 * @var string
	 */
	private $hook_prefix;

	/**
	 * Hooks left alone: those whose first argument is not an account id.
	 *
	 * @var string[]
	 */
	private $skip;

	/**
	 * The Action Scheduler store, or null for the live one.
	 *
	 * @var object|null
	 */
	private $store;

	/**
	 * Creates the cleaner.
	 *
	 * @since 2.1.5
	 *
	 * @param string      $group       Action Scheduler group.
	 * @param string      $hook_prefix Hook prefix of the plugin's jobs.
	 * @param string[]    $skip        Hooks that never belong to one account (or must go on running), by full name.
	 * @param object|null $store       Action Scheduler store (`query_actions()`, `fetch_action()`, `cancel_action()`); the live one when null.
	 */
	public function __construct( string $group, string $hook_prefix, array $skip, ?object $store = null ) {
		$this->group       = $group;
		$this->hook_prefix = $hook_prefix;
		$this->skip        = $skip;
		$this->store       = $store;
	}

	/**
	 * Cancels the pending jobs of an account.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @return int Actions cancelled.
	 */
	public function cancel_for( int $account_id ): int {
		$store = $this->store();

		if ( null === $store || $account_id < 1 ) {
			return 0;
		}

		$cancelled = 0;
		$offset    = 0;

		do {
			// The default `select` query type returns the action ids; `ids` is a return format of `as_get_scheduled_actions()` and throws here.
			$ids = (array) $store->query_actions(
				[
					'group'    => $this->group,
					'status'   => 'pending',
					'per_page' => self::PAGE,
					'offset'   => $offset,
				]
			);

			$kept     = 0;
			$on_page  = count( $ids );

			foreach ( $ids as $id ) {
				if ( ! $this->belongs_to( $store->fetch_action( $id ), $account_id ) ) {
					++$kept;
					continue;
				}

				$store->cancel_action( $id );
				++$cancelled;
			}

			// A cancelled action leaves the pending list, so only the ones kept move the next page.
			$offset += $kept;
		} while ( self::PAGE === $on_page );

		return $cancelled;
	}

	/**
	 * Tells whether an action is a job of the account.
	 *
	 * @param object $action Action.
	 * @param int    $account_id Account id.
	 * @return bool
	 */
	private function belongs_to( $action, int $account_id ): bool {
		$hook = (string) $action->get_hook();
		$args = (array) $action->get_args();

		return 0 === strpos( $hook, $this->hook_prefix )
			&& ! in_array( $hook, $this->skip, true )
			&& isset( $args[0] )
			&& is_numeric( $args[0] )
			&& (int) $args[0] === $account_id;
	}

	/**
	 * The Action Scheduler store, or null when Action Scheduler is not loaded.
	 *
	 * @return object|null
	 */
	private function store(): ?object {
		if ( null !== $this->store ) {
			return $this->store;
		}

		return class_exists( '\\ActionScheduler' ) ? \ActionScheduler::store() : null;
	}
}
