<?php
/**
 * A small Action Scheduler that keeps its actions in a list the test can read.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

use Brain\Monkey\Functions;

/**
 * Call {@see self::install()} after `Brain\Monkey\setUp()`. It models the one fact the jobs' scheduling depends on that a stub
 * which always answers «false» hides: `as_has_scheduled_action()` is true for an action that is **running** as well as for a
 * pending one, while `as_get_scheduled_actions( status = pending )` is true for a pending one only.
 */
final class Fake_Action_Scheduler {
	/**
	 * Actions: `hook`, `args`, `group`, `kind` (async, single or recurring), `status` (pending or in-progress) and `delay`.
	 *
	 * @var array<int,array{hook:string,args:array,group:string,kind:string,status:string,delay:int}>
	 */
	public $actions = [];

	/**
	 * Hooks whose scheduling throws, as `hook => true`.
	 *
	 * @var array<string,bool>
	 */
	public $throw_for = [];

	/**
	 * Hooks whose scheduling returns 0, as Action Scheduler does when it cannot store the action.
	 *
	 * @var array<string,bool>
	 */
	public $zero_for = [];

	/**
	 * Replaces the Action Scheduler functions.
	 *
	 * @return self
	 */
	public static function install(): self {
		$fake = new self();

		Functions\when( 'as_has_scheduled_action' )->alias(
			static function ( string $hook, array $args = [] ) use ( $fake ): bool {
				return [] !== $fake->matching( $hook, $args, null );
			}
		);
		Functions\when( 'as_get_scheduled_actions' )->alias(
			static function ( array $query = [] ) use ( $fake ): array {
				return array_keys( $fake->matching( (string) $query['hook'], (array) $query['args'], (string) ( $query['status'] ?? '' ) ) );
			}
		);
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( string $hook, array $args = [], string $group = '' ) use ( $fake ): int {
				return $fake->add( $hook, $args, 'async', 0, $group );
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( int $when, string $hook, array $args = [], string $group = '' ) use ( $fake ): int {
				return $fake->add( $hook, $args, 'single', $when - time(), $group );
			}
		);
		Functions\when( 'as_schedule_recurring_action' )->alias(
			static function ( int $when, int $interval, string $hook, array $args = [], string $group = '' ) use ( $fake ): int {
				return $fake->add( $hook, $args, 'recurring', 0, $group );
			}
		);

		return $fake;
	}

	/**
	 * Marks the pending actions of a hook as running, like the queue runner does just before it calls the hook.
	 *
	 * @param string $hook Hook.
	 * @return void
	 */
	public function start_running( string $hook ): void {
		foreach ( $this->actions as $index => $action ) {
			if ( $action['hook'] === $hook && 'pending' === $action['status'] ) {
				$this->actions[ $index ]['status'] = 'in-progress';
			}
		}
	}

	/**
	 * Lists `kind:hook:status` of the stored actions, for assertions.
	 *
	 * @return string[]
	 */
	public function summary(): array {
		return array_map(
			static function ( array $action ): string {
				return $action['kind'] . ':' . $action['hook'] . ':' . $action['status'];
			},
			$this->actions
		);
	}

	/**
	 * Returns the stored actions of a hook.
	 *
	 * @param string $hook Hook.
	 * @return array<int,array{hook:string,args:array,group:string,kind:string,status:string,delay:int}>
	 */
	public function of( string $hook ): array {
		return array_values(
			array_filter(
				$this->actions,
				static function ( array $action ) use ( $hook ): bool {
					return $action['hook'] === $hook;
				}
			)
		);
	}

	/**
	 * Finds actions by hook and arguments; a null status matches pending and running ones.
	 *
	 * @param string      $hook   Hook.
	 * @param array       $args   Arguments.
	 * @param string|null $status Status or null.
	 * @return array<int,array>
	 */
	public function matching( string $hook, array $args, ?string $status ): array {
		return array_filter(
			$this->actions,
			static function ( array $action ) use ( $hook, $args, $status ): bool {
				return $action['hook'] === $hook && $action['args'] === $args && ( null === $status || '' === $status || $action['status'] === $status );
			}
		);
	}

	/**
	 * Stores an action, or fails the way the test asked.
	 *
	 * @param string $hook  Hook.
	 * @param array  $args  Arguments.
	 * @param string $kind  async, single or recurring.
	 * @param int    $delay Seconds from now (single actions).
	 * @param string $group Group.
	 * @return int Action id, 0 when told to refuse.
	 * @throws \RuntimeException When told to throw.
	 */
	public function add( string $hook, array $args, string $kind, int $delay = 0, string $group = '' ): int {
		if ( isset( $this->throw_for[ $hook ] ) ) {
			throw new \RuntimeException( 'Action Scheduler is not loaded' );
		}

		if ( isset( $this->zero_for[ $hook ] ) ) {
			return 0;
		}

		$this->actions[] = [
			'hook'   => $hook,
			'args'   => $args,
			'group'  => $group,
			'kind'   => $kind,
			'status' => 'pending',
			'delay'  => $delay,
		];

		return count( $this->actions );
	}
}
