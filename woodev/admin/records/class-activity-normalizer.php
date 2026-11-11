<?php
/**
 * Records engine — row and account activity normaliser.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds what a provider says about work in progress from a whitelist.
 *
 * Two shapes leave the server:
 *
 * - `RowActivity` — `locked`, the visible `reason`, the `blocked_actions` the row cannot take, the
 *   `processes` running on it and when the state was `observed_at`;
 * - `account_activity` — the same idea for work that belongs to the whole account.
 *
 * Rules the browser relies on, enforced here so it never has to guess:
 *
 * - a lock or a blocked action always carries a reason sentence (the reason is visible text, not colour);
 * - a process id is an opaque observation id: keys the shape does not know (a claim token, a task
 *   payload) are dropped, not forwarded;
 * - a percentage exists only beside two real counts that fit each other. A process with no measurable
 *   total sends `null` counts and `null` percent — never a percentage guessed from elapsed time.
 *
 * A provider that breaks the contract is a developer error: the whole response becomes a generic 500,
 * like any other broken provider answer ({@see Row_Normalizer}).
 *
 * @since 2.1.0
 */
final class Activity_Normalizer {

	/** Process states, in the order a process normally moves through them. */
	const STATES = [ 'queued', 'running', 'waiting', 'failed', 'completed' ];

	/** States that mean work is still going on. */
	const ACTIVE_STATES = [ 'queued', 'running', 'waiting' ];

	/** Most processes one row may show. */
	const MAX_PROCESSES = 10;

	/** Longest label, process text or reason. */
	const MAX_TEXT_LENGTH = 500;

	/** Date-time as `observed_at` is written: ISO 8601 with a zone. */
	const TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:?\d{2})$/D';

	/**
	 * The activity of a row with nothing going on.
	 *
	 * @since 2.1.0
	 *
	 * @param string $observed_at Time of the observation; now when empty.
	 * @return array{locked:bool,reason:string,blocked_actions:string[],processes:array<int,array<string,mixed>>,observed_at:string}
	 */
	public static function idle( string $observed_at = '' ): array {
		return [
			'locked'          => false,
			'reason'          => '',
			'blocked_actions' => [],
			'processes'       => [],
			'observed_at'     => '' === $observed_at ? self::now() : $observed_at,
		];
	}

	/**
	 * The current time as `observed_at` is written.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Normalises the `activity` a provider attached to a row.
	 *
	 * @since 2.1.0
	 *
	 * @param Records_Definition $definition Definition the action ids are checked against.
	 * @param mixed              $raw        Provider value; null or absent = nothing going on.
	 * @return array{locked:bool,reason:string,blocked_actions:string[],processes:array<int,array<string,mixed>>,observed_at:string}
	 * @throws \UnexpectedValueException When the value breaks the contract.
	 */
	public static function row( Records_Definition $definition, $raw ): array {
		if ( null === $raw ) {
			return self::idle();
		}
		if ( ! is_array( $raw ) ) {
			throw new \UnexpectedValueException( 'row activity is a map.' );
		}

		$locked  = self::flag( $raw['locked'] ?? false, 'locked' );
		$reason  = self::text( $raw['reason'] ?? '', self::MAX_TEXT_LENGTH );
		$blocked = self::action_ids( $definition, $raw['blocked_actions'] ?? [] );

		if ( ( $locked || [] !== $blocked ) && '' === $reason ) {
			throw new \UnexpectedValueException( 'a lock or a blocked action must say why.' );
		}

		return [
			'locked'          => $locked,
			'reason'          => $reason,
			'blocked_actions' => $blocked,
			'processes'       => self::processes( $raw['processes'] ?? [] ),
			'observed_at'     => self::observed_at( $raw['observed_at'] ?? null ),
		];
	}

	/**
	 * Normalises the account-wide activity.
	 *
	 * `active` is the line «по аккаунту идёт фоновая работа»; `blocked_actions` are the account actions
	 * that take the account's claim themselves and so cannot run meanwhile.
	 *
	 * @since 2.1.0
	 *
	 * @param Records_Definition $definition Definition the action ids are checked against.
	 * @param mixed              $raw        Provider value; null or absent = nothing going on.
	 * @return array{active:bool,text:string,blocked_actions:string[],observed_at:string}
	 * @throws \UnexpectedValueException When the value breaks the contract.
	 */
	public static function account( Records_Definition $definition, $raw ): array {
		if ( null === $raw ) {
			return self::idle_account();
		}
		if ( ! is_array( $raw ) ) {
			throw new \UnexpectedValueException( 'account activity is a map.' );
		}

		$active  = self::flag( $raw['active'] ?? false, 'active' );
		$text    = self::text( $raw['text'] ?? '', self::MAX_TEXT_LENGTH );
		$blocked = self::action_ids( $definition, $raw['blocked_actions'] ?? [] );

		if ( ( $active || [] !== $blocked ) && '' === $text ) {
			throw new \UnexpectedValueException( 'account activity must say what is going on.' );
		}

		return [
			'active'          => $active,
			'text'            => $text,
			'blocked_actions' => $blocked,
			'observed_at'     => self::observed_at( $raw['observed_at'] ?? null ),
		];
	}

	/**
	 * Account activity with nothing going on.
	 *
	 * @since 2.1.0
	 *
	 * @return array{active:bool,text:string,blocked_actions:string[],observed_at:string}
	 */
	public static function idle_account(): array {
		return [
			'active'          => false,
			'text'            => '',
			'blocked_actions' => [],
			'observed_at'     => self::now(),
		];
	}

	/**
	 * Whether any of the given row activities, or the account, still has work going on.
	 *
	 * @since 2.1.0
	 *
	 * @param array<int,array<string,mixed>> $rows    Normalised rows (each with its `activity`).
	 * @param array<string,mixed>            $account Normalised account activity.
	 * @return bool
	 */
	public static function is_busy( array $rows, array $account ): bool {
		if ( true === $account['active'] ) {
			return true;
		}

		foreach ( $rows as $row ) {
			foreach ( $row['activity']['processes'] as $process ) {
				if ( in_array( $process['state'], self::ACTIVE_STATES, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param mixed $processes Provider process list.
	 * @return array<int,array{id:string,state:string,label:string,completed:int|null,total:int|null,percent:int|null,text:string}>
	 */
	private static function processes( $processes ): array {
		if ( ! is_array( $processes ) || array_values( $processes ) !== $processes || count( $processes ) > self::MAX_PROCESSES ) {
			throw new \UnexpectedValueException( 'processes are a short list.' );
		}

		$out = array_map( [ self::class, 'process' ], $processes );
		if ( count( $out ) !== count( array_unique( array_column( $out, 'id' ) ) ) ) {
			throw new \UnexpectedValueException( 'process ids are distinct within a row.' );
		}

		return $out;
	}

	/**
	 * @param mixed $process One provider process.
	 * @return array{id:string,state:string,label:string,completed:int|null,total:int|null,percent:int|null,text:string}
	 */
	private static function process( $process ): array {
		if ( ! is_array( $process ) || ! Query_Validator::is_row_id( $process['id'] ?? null ) || ! in_array( $process['state'] ?? null, self::STATES, true ) ) {
			throw new \UnexpectedValueException( 'a process has an opaque id and a known state.' );
		}

		$label = self::text( $process['label'] ?? '', 200 );
		if ( '' === $label ) {
			throw new \UnexpectedValueException( 'a process needs a label.' );
		}

		return [
			'id'    => $process['id'],
			'state' => $process['state'],
			'label' => $label,
		] + self::progress( $process ) + [ 'text' => self::text( $process['text'] ?? '', 200 ) ];
	}

	/**
	 * Counts and percent. Unknown = null; a percent needs both counts to stand on.
	 *
	 * @param array<string,mixed> $process Provider process.
	 * @return array{completed:int|null,total:int|null,percent:int|null}
	 */
	private static function progress( array $process ): array {
		$completed = self::count( $process['completed'] ?? null );
		$total     = self::count( $process['total'] ?? null );
		$percent   = self::count( $process['percent'] ?? null );

		if ( null !== $completed && null !== $total && $completed > $total ) {
			throw new \UnexpectedValueException( 'completed cannot exceed total.' );
		}
		if ( null !== $percent && ( $percent > 100 || null === $completed || null === $total ) ) {
			throw new \UnexpectedValueException( 'a percent needs both counts: it is never a guess from elapsed time.' );
		}

		return [
			'completed' => $completed,
			'total'     => $total,
			'percent'   => $percent,
		];
	}

	/**
	 * @param mixed $value Count, or null when it cannot be measured.
	 * @return int|null
	 */
	private static function count( $value ): ?int {
		if ( null !== $value && ( ! is_int( $value ) || $value < 0 ) ) {
			throw new \UnexpectedValueException( 'counts are non-negative integers or null.' );
		}

		return $value;
	}

	/**
	 * Declared action ids (row or bulk), each once.
	 *
	 * @param Records_Definition $definition Definition.
	 * @param mixed              $ids        Provider list.
	 * @return string[]
	 */
	private static function action_ids( Records_Definition $definition, $ids ): array {
		if ( ! is_array( $ids ) || array_values( $ids ) !== $ids ) {
			throw new \UnexpectedValueException( 'blocked actions are a list.' );
		}

		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || null === $definition->get_action( $id ) ) {
				throw new \UnexpectedValueException( 'a blocked action must be a declared action.' );
			}
		}
		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			throw new \UnexpectedValueException( 'a blocked action may appear once.' );
		}

		return $ids;
	}

	/**
	 * @param mixed $value Provider time, or null for "now".
	 * @return string
	 */
	private static function observed_at( $value ): string {
		if ( null === $value ) {
			return self::now();
		}
		if ( ! is_string( $value ) || 1 !== preg_match( self::TIME_PATTERN, $value ) ) {
			throw new \UnexpectedValueException( 'observed_at is an ISO 8601 date-time with a zone.' );
		}

		return $value;
	}

	/**
	 * @param mixed  $value Provider flag.
	 * @param string $name  Key, for the message.
	 * @return bool
	 */
	private static function flag( $value, string $name ): bool {
		if ( ! is_bool( $value ) ) {
			throw new \UnexpectedValueException( $name . ' must be a boolean.' );
		}

		return $value;
	}

	/**
	 * @param mixed $value Provider string.
	 * @param int   $max   Longest allowed.
	 * @return string Tags stripped.
	 */
	private static function text( $value, int $max ): string {
		if ( ! is_string( $value ) || mb_strlen( $value ) > $max ) {
			throw new \UnexpectedValueException( 'a string is missing or longer than allowed.' );
		}

		return wp_strip_all_tags( $value );
	}
}
