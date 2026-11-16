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

	/** Most buttons the account line may carry, and the longest value of one button's payload. */
	const MAX_LINE_ACTIONS      = 2;
	const MAX_LINE_VALUE_LENGTH = 200;

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
	 * that take the account's claim themselves and so cannot run meanwhile. The optional `actions` are
	 * at most two buttons of the line (`action`, `label`, `payload`): each runs a declared account
	 * action, the payload being a flat map of short strings under that action's declared fields. A
	 * line with buttons says what it is about, like an active one.
	 *
	 * @since 2.1.0 `actions` since 2.1.8.
	 *
	 * @param Records_Definition $definition Definition the action ids are checked against.
	 * @param mixed              $raw        Provider value; null or absent = nothing going on.
	 * @return array{active:bool,text:string,blocked_actions:string[],observed_at:string,actions?:array<int,array{action:string,label:string,payload:array<string,string>}>}
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
		$actions = self::line_actions( $definition, $raw['actions'] ?? [] );

		if ( ( $active || [] !== $blocked || [] !== $actions ) && '' === $text ) {
			throw new \UnexpectedValueException( 'account activity must say what is going on.' );
		}

		$account = [
			'active'          => $active,
			'text'            => $text,
			'blocked_actions' => $blocked,
			'observed_at'     => self::observed_at( $raw['observed_at'] ?? null ),
		];

		// Absent, not empty, when the provider offers no button: a table without them keeps its old shape.
		return [] === $actions ? $account : $account + [ 'actions' => $actions ];
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
	 * The buttons of the account line: each names an account-wide action the browser runs through the normal
	 * action flow, with a payload the server put there («Остановить» carries the id of the run on the line).
	 *
	 * @param Records_Definition $definition Definition the action ids are checked against.
	 * @param mixed              $actions    Provider list.
	 * @return array<int,array{action:string,label:string,payload:array<string,string>}>
	 * @throws \UnexpectedValueException When a button names an action that is not a declared account action, or breaks the shape.
	 */
	private static function line_actions( Records_Definition $definition, $actions ): array {
		if ( ! is_array( $actions ) || array_values( $actions ) !== $actions || count( $actions ) > self::MAX_LINE_ACTIONS ) {
			throw new \UnexpectedValueException( 'account line actions are a list of at most ' . self::MAX_LINE_ACTIONS . '.' );
		}

		$out = [];
		foreach ( $actions as $entry ) {
			$out[] = self::line_action( $definition, $entry );
		}

		if ( count( $out ) !== count( array_unique( array_column( $out, 'action' ) ) ) ) {
			throw new \UnexpectedValueException( 'an account line action may appear once.' );
		}

		return $out;
	}

	/**
	 * @param Records_Definition $definition Definition.
	 * @param mixed              $entry      One provider button.
	 * @return array{action:string,label:string,payload:array<string,string>}
	 */
	private static function line_action( Records_Definition $definition, $entry ): array {
		$id     = is_array( $entry ) && is_string( $entry['action'] ?? null ) ? $entry['action'] : '';
		$action = '' === $id ? null : $definition->get_action( $id );

		if ( null === $action || 'account' !== $action['scope'] || 'link' === $action['interaction'] ) {
			throw new \UnexpectedValueException( 'an account line button must name a declared account action.' );
		}

		$label = self::text( $entry['label'] ?? '', 200 );
		if ( '' === $label ) {
			throw new \UnexpectedValueException( 'an account line button needs a label.' );
		}

		return [
			'action'  => $id,
			'label'   => $label,
			'payload' => self::line_payload( $definition, $id, $entry['payload'] ?? [] ),
		];
	}

	/**
	 * A flat map of short strings, each under a field the action declares: the payload goes back to the server
	 * unchanged, so a key the action does not know could only ever be refused there.
	 *
	 * @param Records_Definition $definition Definition.
	 * @param string             $action_id  The button's action.
	 * @param mixed              $payload    Provider payload.
	 * @return array<string,string>
	 */
	private static function line_payload( Records_Definition $definition, string $action_id, $payload ): array {
		if ( ! is_array( $payload ) || ( [] !== $payload && array_values( $payload ) === $payload ) || count( $payload ) > Records_Definition::MAX_ACTION_FIELDS ) {
			throw new \UnexpectedValueException( 'an account line payload is a short map.' );
		}

		$out = [];
		foreach ( $payload as $key => $value ) {
			if ( ! is_string( $key ) || null === $definition->get_action_field( $action_id, $key ) || ! is_string( $value ) || mb_strlen( $value ) > self::MAX_LINE_VALUE_LENGTH ) {
				throw new \UnexpectedValueException( 'an account line payload maps declared field ids to short strings.' );
			}

			$out[ $key ] = $value;
		}

		return $out;
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
