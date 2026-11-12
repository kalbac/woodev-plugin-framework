<?php
/**
 * What the deletion of an account has done so far.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Deletion;

defined( 'ABSPATH' ) || exit;

/**
 * One option per account under the plugin's option family ({@see \Woodev\Framework\Marketplace\Data\Uninstaller::option_prefix()}),
 * no autoload. The settings screen reads it to draw the progress; the job itself needs none of it to resume, because every step
 * deletes «what is left of this account» and finds nothing left when it has already run.
 *
 * Its life: {@see self::begin()} writes `running`; each batch adds to the counts; a failure turns it into `failed` (the merchant
 * may start again); the end removes the option and leaves the result for a day in a transient, because the account row — the only
 * other place that could tell the screen the deletion finished — is gone by then.
 *
 * @since 2.1.5
 */
final class Account_Deletion_Progress {
	public const RUNNING = 'running';
	public const FAILED  = 'failed';
	public const DONE    = 'done';

	private const OPTION   = 'account_deletion_';
	private const DONE_KEY = 'account_deletion_done_';
	private const DONE_TTL = 86400;
	private const STALE    = 900;

	/**
	 * Option family of the plugin.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Unix time: `function (): int`.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the store.
	 *
	 * @since 2.1.5
	 *
	 * @param string        $option_prefix The plugin's option family.
	 * @param callable|null $clock         `function (): int`; the system clock when null.
	 */
	public function __construct( string $option_prefix, ?callable $clock = null ) {
		$this->prefix = $option_prefix;
		$this->clock  = $clock ?? 'time';
	}

	/**
	 * Marks the deletion as running. A deletion that is already running keeps its counts; a failed one starts afresh.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @return void
	 */
	public function begin( int $account_id ): void {
		if ( $this->is_running( $account_id ) ) {
			return;
		}

		delete_transient( $this->prefix . self::DONE_KEY . $account_id );
		update_option(
			$this->prefix . self::OPTION . $account_id,
			[
				'state'      => self::RUNNING,
				'started_at' => (int) call_user_func( $this->clock ),
				'updated_at' => (int) call_user_func( $this->clock ),
				'step'       => '',
				'removed'    => [],
				'message'    => '',
			],
			false
		);
	}

	/**
	 * Adds the rows a batch removed to the running count of its step.
	 *
	 * @since 2.1.5
	 *
	 * @param int    $account_id Account id.
	 * @param string $step       Step name.
	 * @param int    $removed    Rows the batch removed.
	 * @return void
	 */
	public function record( int $account_id, string $step, int $removed ): void {
		$record = $this->stored( $account_id );

		if ( null === $record || self::RUNNING !== $record['state'] ) {
			return;
		}

		$record['step']             = $step;
		$record['updated_at']       = (int) call_user_func( $this->clock );
		$record['removed'][ $step ] = (int) ( $record['removed'][ $step ] ?? 0 ) + $removed;

		update_option( $this->prefix . self::OPTION . $account_id, $record, false );
	}

	/**
	 * Marks the deletion as failed, with a sentence for the merchant.
	 *
	 * @since 2.1.5
	 *
	 * @param int    $account_id Account id.
	 * @param string $message    What went wrong.
	 * @return void
	 */
	public function fail( int $account_id, string $message ): void {
		$record = $this->stored( $account_id ) ?? [
			'started_at' => (int) call_user_func( $this->clock ),
			'step'       => '',
			'removed'    => [],
		];

		$record['state']      = self::FAILED;
		$record['updated_at'] = (int) call_user_func( $this->clock );
		$record['message']    = $message;

		update_option( $this->prefix . self::OPTION . $account_id, $record, false );
	}

	/**
	 * Ends the record: the account is gone. The result stays for a day so the screen can say so.
	 *
	 * @since 2.1.5
	 *
	 * @param int      $account_id Account id.
	 * @param string[] $notes      Sentences for the merchant (what stays at the marketplace).
	 * @return void
	 */
	public function finish( int $account_id, array $notes ): void {
		$record = $this->stored( $account_id );

		delete_option( $this->prefix . self::OPTION . $account_id );
		set_transient(
			$this->prefix . self::DONE_KEY . $account_id,
			[
				'state'   => self::DONE,
				'step'    => '',
				'removed' => null === $record ? [] : $record['removed'],
				'message' => '',
				'notes'   => array_values( $notes ),
			],
			self::DONE_TTL
		);
	}

	/**
	 * Reads the state of an account's deletion.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @return array<string,mixed>|null `state`, `step`, `removed`, `message` and, once done, `notes`; null when none was started.
	 */
	public function get( int $account_id ): ?array {
		$record = $this->stored( $account_id );

		if ( null !== $record ) {
			return $this->without_stale_run( $record );
		}

		$done = get_transient( $this->prefix . self::DONE_KEY . $account_id );

		return is_array( $done ) ? $done : null;
	}

	/**
	 * Tells whether a deletion is running.
	 *
	 * @since 2.1.5
	 *
	 * @param int $account_id Account id.
	 * @return bool
	 */
	public function is_running( int $account_id ): bool {
		$record = $this->stored( $account_id );

		return null !== $record && self::RUNNING === $record['state'];
	}

	/**
	 * A deletion that says `running` but has not moved for a quarter of an hour was cut off (the queue stopped, the server was
	 * restarted): it is shown as failed so the merchant can start it again. Nothing is written; a run that does come back goes on.
	 *
	 * @param array{state:string,started_at:int,updated_at:int,step:string,removed:array<string,int>,message:string} $record Record.
	 * @return array{state:string,started_at:int,updated_at:int,step:string,removed:array<string,int>,message:string}
	 */
	private function without_stale_run( array $record ): array {
		if ( self::RUNNING === $record['state'] && (int) call_user_func( $this->clock ) - $record['updated_at'] > self::STALE ) {
			$record['state']   = self::FAILED;
			$record['message'] = __( 'Удаление прервалось. Запустите его снова: уже удалённые данные повторно не потребуются.', 'woodev-plugin-framework' );
		}

		return $record;
	}

	/**
	 * Reads the option.
	 *
	 * @param int $account_id Account id.
	 * @return array{state:string,started_at:int,updated_at:int,step:string,removed:array<string,int>,message:string}|null
	 */
	private function stored( int $account_id ): ?array {
		$record = get_option( $this->prefix . self::OPTION . $account_id, null );

		if ( ! is_array( $record ) || ! isset( $record['state'] ) ) {
			return null;
		}

		return [
			'state'      => (string) $record['state'],
			'started_at' => (int) ( $record['started_at'] ?? 0 ),
			'updated_at' => (int) ( $record['updated_at'] ?? $record['started_at'] ?? 0 ),
			'step'       => (string) ( $record['step'] ?? '' ),
			'removed'    => is_array( $record['removed'] ?? null ) ? array_map( 'intval', $record['removed'] ) : [],
			'message'    => (string) ( $record['message'] ?? '' ),
		];
	}
}
