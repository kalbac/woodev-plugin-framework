<?php
/**
 * One bulk run of an account.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

defined( 'ABSPATH' ) || exit;

/**
 * The row of `bulk_runs`: an action applied to every product under a filter, in the background, in parts (#1795). It holds what the
 * run needs to continue — the action, its validated fields, the filter, a cursor — and what it has done so far; never a list of ids.
 *
 * @since 2.1.8
 */
final class Bulk_Run {
	public const RUNNING   = 'running';
	public const DONE      = 'done';
	public const CANCELLED = 'cancelled';
	public const STOPPED   = 'stopped';

	/**
	 * Every counter of a run. `products_*` count parent products (the table's unit), `offers_*` count simple products and variations.
	 */
	public const COUNTS = [ 'products_total', 'products_done', 'offers_accepted', 'offers_skipped', 'offers_failed' ];

	/**
	 * @var array<string,mixed>
	 */
	private $row;

	/**
	 * Wraps a database row.
	 *
	 * @since 2.1.8
	 *
	 * @param array<string,mixed> $row Row of `bulk_runs`, without `items`.
	 */
	public function __construct( array $row ) {
		$this->row = $row;
	}

	/**
	 * @since 2.1.8
	 *
	 * @return int
	 */
	public function account_id(): int {
		return (int) $this->row['account_id'];
	}

	/**
	 * @since 2.1.8
	 *
	 * @return string Random token of this run.
	 */
	public function run_id(): string {
		return (string) $this->row['run_id'];
	}

	/**
	 * @since 2.1.8
	 *
	 * @return string One of the state constants.
	 */
	public function state(): string {
		return (string) $this->row['state'];
	}

	/**
	 * @since 2.1.8
	 *
	 * @return bool Whether the run is still going.
	 */
	public function is_running(): bool {
		return self::RUNNING === $this->state();
	}

	/**
	 * @since 2.1.8
	 *
	 * @return bool Whether «Остановить» asked for a stop that no tick has seen yet.
	 */
	public function cancel_requested(): bool {
		return 1 === (int) $this->row['cancel'];
	}

	/**
	 * @since 2.1.8
	 *
	 * @return string Action id.
	 */
	public function action(): string {
		return (string) $this->row['action'];
	}

	/**
	 * @since 2.1.8
	 *
	 * @return array<string,mixed> The action's validated fields.
	 */
	public function payload(): array {
		return $this->json( 'payload' );
	}

	/**
	 * @since 2.1.8
	 *
	 * @return array<string,mixed> The validated query of the table: `search` and `filters`.
	 */
	public function filter(): array {
		return $this->json( 'filter' );
	}

	/**
	 * @since 2.1.8
	 *
	 * @return int The user who started the run.
	 */
	public function user_id(): int {
		return (int) $this->row['user_id'];
	}

	/**
	 * @since 2.1.8
	 *
	 * @return array<string,mixed> The position: `{ parent, child }` of the products run, `{ index }` of the remote-cards run.
	 */
	public function cursor(): array {
		return $this->json( 'cursor' );
	}

	/**
	 * @since 2.1.8
	 *
	 * @return array<string,int> Every counter of {@see self::COUNTS}.
	 */
	public function counts(): array {
		$stored = $this->json( 'counts' );
		$counts = [];

		foreach ( self::COUNTS as $key ) {
			$counts[ $key ] = (int) ( $stored[ $key ] ?? 0 );
		}

		return $counts;
	}

	/**
	 * @since 2.1.8
	 *
	 * @return array<int,array<string,mixed>> Groups of the report, in the shape the records engine reads.
	 */
	public function report(): array {
		return array_values( $this->json( 'report' ) );
	}

	/**
	 * @since 2.1.8
	 *
	 * @return int Unix time the run started.
	 */
	public function started_at(): int {
		return (int) strtotime( (string) $this->row['started_at'] . ' UTC' );
	}

	/**
	 * @since 2.1.8
	 *
	 * @return int Unix time the run finished; 0 while it runs.
	 */
	public function finished_at(): int {
		$value = (string) ( $this->row['finished_at'] ?? '' );

		return '' === $value ? 0 : (int) strtotime( $value . ' UTC' );
	}

	/**
	 * @param string $column Column that holds JSON.
	 * @return array<mixed>
	 */
	private function json( string $column ): array {
		$value = $this->row[ $column ] ?? null;
		$data  = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : null;

		return is_array( $data ) ? $data : [];
	}
}
