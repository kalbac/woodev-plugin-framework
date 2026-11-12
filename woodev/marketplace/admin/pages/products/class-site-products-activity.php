<?php
/**
 * What is going on with the rows of the «Товары сайта» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Admin\Records\Activity_Normalizer;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * The local read behind the table's locks and processes. It reads our own tables once for all the rows asked about, claims
 * nothing, queues nothing and calls no marketplace.
 *
 * | Source | Projection |
 * |---|---|
 * | the link's card state | `submitted`: the card is being exported, so «Экспортировать» and «Снять связь» are blocked; `queued` and the lifecycle states: a process, nothing blocked |
 * | open items of export tasks | a waiting process; «Экспортировать» and «Снять связь» are blocked even when no run holds the task now |
 * | stock and price revisions of the sync state | one process per active send (in flight, waiting, or the last one failed); no lock — a product action only sets a flag or seeds an intent |
 * | the account row's claim | the account line; «Снять связь» takes the claim itself, so it is blocked while another holder has it |
 *
 * @since 2.1.3
 */
final class Site_Products_Activity {
	/**
	 * Actions that conflict with a card being exported.
	 */
	private const EXPORT_LOCKED = [ Site_Products_Bulk::EXPORT, Site_Products_Bulk::DETACH ];

	/**
	 * @var Task_Ledger
	 */
	private $ledger;

	/**
	 * @var \wpdb
	 */
	private $db;

	/**
	 * @var string
	 */
	private $sync_table;

	/**
	 * @var string
	 */
	private $accounts_table;

	/**
	 * Returns the Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the reader.
	 *
	 * @since 2.1.3
	 *
	 * @param Task_Ledger $ledger         Task ledger.
	 * @param \wpdb       $db             Database.
	 * @param string      $sync_table     Full name of the sync state table.
	 * @param string        $accounts_table Full name of the accounts table.
	 * @param callable|null $clock          Returns the Unix time; the system clock when null.
	 */
	public function __construct( Task_Ledger $ledger, \wpdb $db, string $sync_table, string $accounts_table, ?callable $clock = null ) {
		$this->ledger         = $ledger;
		$this->db             = $db;
		$this->sync_table     = $sync_table;
		$this->accounts_table = $accounts_table;
		$this->clock          = $clock ?? 'time';
	}

	/**
	 * Reads the activity of offers.
	 *
	 * @since 2.1.3
	 *
	 * @param int                            $account_id Account.
	 * @param array<int,array<string,mixed>> $offers     Offers by product id, as {@see Site_Products_Reader} builds them.
	 * @return array<int,array<string,mixed>> Row activity by product id, in the shape {@see Activity_Normalizer::row()} accepts.
	 */
	public function for_offers( int $account_id, array $offers ): array {
		$link_ids = array_values( array_filter( array_map( 'intval', array_column( $offers, 'link_id' ) ) ) );
		$tasks    = [] === $link_ids ? [] : $this->ledger->open_items( $account_id, $link_ids );
		$sends    = $this->sends( $account_id, $link_ids );
		$now      = Activity_Normalizer::now();
		$out      = [];

		foreach ( $offers as $product_id => $offer ) {
			$link_id = (int) ( $offer['link_id'] ?? 0 );

			$out[ (int) $product_id ] = $this->row(
				$link_id,
				(string) ( $offer['card_state'] ?? '' ),
				$tasks[ $link_id ] ?? [],
				$sends[ $link_id ] ?? [],
				$now
			);
		}

		return $out;
	}

	/**
	 * Sums the activity of a variable product's variations into the parent row's.
	 *
	 * An action is blocked on the parent only when **every** variation blocks it — then nothing could go; when some can, the
	 * server skips the rest with a reason. The processes are one line per kind with the number of variations, with no counts: the
	 * parts are not the same size, so no percentage is honest.
	 *
	 * @since 2.1.3
	 *
	 * @param array<int,array<string,mixed>> $children Activity of the variations.
	 * @return array<string,mixed>
	 */
	public function for_parent( array $children ): array {
		if ( [] === $children ) {
			return Activity_Normalizer::idle();
		}

		$blocked   = array_values(
			array_intersect(
				...array_map(
					static function ( array $child ): array {
						return $child['blocked_actions'];
					},
					array_values( $children )
				)
			)
		);
		$reasons   = array_values( array_unique( array_filter( array_column( $children, 'reason' ) ) ) );
		$processes = $this->parent_processes( $children );

		return [
			'locked'          => [] !== $blocked,
			'reason'          => [] === $blocked ? '' : (string) ( $reasons[0] ?? '' ),
			'blocked_actions' => $blocked,
			'processes'       => $processes,
			'observed_at'     => Activity_Normalizer::now(),
		];
	}

	/**
	 * The account line: work that belongs to the whole account, and the account actions that take its claim themselves.
	 *
	 * @since 2.1.3
	 *
	 * @param int $account_id Account.
	 * @return array<string,mixed> Shape of {@see Activity_Normalizer::account()}.
	 */
	public function account( int $account_id ): array {
		$until = $this->db->get_var(
			$this->db->prepare( 'SELECT claim_until FROM %i WHERE account_id = %d AND claim_token IS NOT NULL', $this->accounts_table, $account_id ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table, read fresh.
		);

		if ( ! is_string( $until ) || '' === $until || strtotime( $until . ' UTC' ) <= (int) call_user_func( $this->clock ) ) {
			return Activity_Normalizer::idle_account();
		}

		return [
			'active'          => true,
			'text'            => __( 'По аккаунту идёт фоновая работа: отправка остатков, цен или карточек. «Снять связь» станет доступно, когда она закончится.', 'woodev-plugin-framework' ),
			'blocked_actions' => [ Site_Products_Bulk::DETACH ],
			'observed_at'     => Activity_Normalizer::now(),
		];
	}

	/**
	 * Builds the activity of one offer.
	 *
	 * @param int                            $link_id Link id, 0 for a product with no link row.
	 * @param string                         $state   Card state.
	 * @param array<int,array<string,mixed>> $tasks   Open task items of the link.
	 * @param array<int,array<string,mixed>> $sends   Active stock and price sends of the link.
	 * @param string                         $now     `observed_at`.
	 * @return array<string,mixed>
	 */
	private function row( int $link_id, string $state, array $tasks, array $sends, string $now ): array {
		$processes = array_merge( $this->card_process( $link_id, $state ), $this->task_processes( $tasks ), $this->send_processes( $link_id, $sends ) );
		$exporting = Link_Record::STATE_SUBMITTED === $state || [] !== $tasks;

		if ( [] === $processes ) {
			return Activity_Normalizer::idle( $now );
		}

		return [
			'locked'          => $exporting,
			'reason'          => $exporting ? __( 'Карточка сейчас отправляется на маркетплейс — дождитесь результата.', 'woodev-plugin-framework' ) : '',
			'blocked_actions' => $exporting ? self::EXPORT_LOCKED : [],
			'processes'       => array_slice( $processes, 0, Activity_Normalizer::MAX_PROCESSES ),
			'observed_at'     => $now,
		];
	}

	/**
	 * The process of a card that is queued, submitted or being created.
	 *
	 * @param int    $link_id Link id.
	 * @param string $state   Card state.
	 * @return array<int,array<string,mixed>>
	 */
	private function card_process( int $link_id, string $state ): array {
		$states = [
			Link_Record::STATE_QUEUED    => [ 'queued', __( 'В очереди на отправку', 'woodev-plugin-framework' ) ],
			Link_Record::STATE_SUBMITTED => [ 'waiting', __( 'Ожидаем ответ маркетплейса', 'woodev-plugin-framework' ) ],
		];

		foreach ( Link_Record::LIFECYCLE_STATES as $lifecycle ) {
			$states[ $lifecycle ] = [ 'running', __( 'Маркетплейс создаёт карточку', 'woodev-plugin-framework' ) ];
		}

		if ( ! isset( $states[ $state ] ) ) {
			return [];
		}

		return [ $this->process( 'card-' . $link_id, $states[ $state ][0], __( 'Карточка', 'woodev-plugin-framework' ), $states[ $state ][1] ) ];
	}

	/**
	 * One waiting process per open export task of the link.
	 *
	 * @param array<int,array<string,mixed>> $tasks Open items.
	 * @return array<int,array<string,mixed>>
	 */
	private function task_processes( array $tasks ): array {
		$processes = [];

		foreach ( $tasks as $task ) {
			$processes[] = $this->process( 'task-' . (int) $task['task_id'], 'waiting', __( 'Результат выгрузки', 'woodev-plugin-framework' ), __( 'Маркетплейс ещё не ответил по этому товару', 'woodev-plugin-framework' ) );
		}

		return $processes;
	}

	/**
	 * One process per active stock or price send.
	 *
	 * @param int                            $link_id Link id.
	 * @param array<int,array<string,mixed>> $sends   Rows of the sync state.
	 * @return array<int,array<string,mixed>>
	 */
	private function send_processes( int $link_id, array $sends ): array {
		$processes = [];

		foreach ( $sends as $send ) {
			$label = 'stock' === $send['kind'] ? __( 'Отправка остатков', 'woodev-plugin-framework' ) : __( 'Отправка цены', 'woodev-plugin-framework' );
			$id    = $send['kind'] . '-' . $link_id . '-' . (int) $send['warehouse_id'];

			if ( null !== $send['in_flight_rev'] ) {
				$processes[] = $this->process( $id, 'running', $label, __( 'Отправляется', 'woodev-plugin-framework' ) );
			} elseif ( '' !== trim( (string) $send['last_error'] ) ) {
				$processes[] = $this->process( $id, 'failed', $label, __( 'Последняя попытка не удалась', 'woodev-plugin-framework' ) );
			} else {
				$processes[] = $this->process( $id, 'queued', $label, __( 'Ждёт отправки', 'woodev-plugin-framework' ) );
			}
		}

		return $processes;
	}

	/**
	 * A process without a measurable size: the counts and the percent stay null.
	 *
	 * @param string $id    Observation id.
	 * @param string $state `Activity_Normalizer::STATES`.
	 * @param string $label Label.
	 * @param string $text  Detail.
	 * @return array<string,mixed>
	 */
	private function process( string $id, string $state, string $label, string $text ): array {
		return [
			'id'        => $id,
			'state'     => $state,
			'label'     => $label,
			'completed' => null,
			'total'     => null,
			'percent'   => null,
			'text'      => $text,
		];
	}

	/**
	 * Groups the variations' processes by label and state.
	 *
	 * @param array<int,array<string,mixed>> $children Activity of the variations.
	 * @return array<int,array<string,mixed>>
	 */
	private function parent_processes( array $children ): array {
		$groups = [];

		foreach ( $children as $child ) {
			foreach ( $child['processes'] as $process ) {
				$key = $process['label'] . '|' . $process['state'];

				$groups[ $key ] = $groups[ $key ] ?? [
					'label' => $process['label'],
					'state' => $process['state'],
					'count' => 0,
				];
				++$groups[ $key ]['count'];
			}
		}

		$processes = [];

		foreach ( array_values( $groups ) as $index => $group ) {
			/* translators: %d: number of variations. */
			$text        = sprintf( _n( '%d вариант', '%d вариантов', $group['count'], 'woodev-plugin-framework' ), $group['count'] );
			$processes[] = $this->process( 'group-' . $index, $group['state'], $group['label'], $text );
		}

		return array_slice( $processes, 0, Activity_Normalizer::MAX_PROCESSES );
	}

	/**
	 * Reads the stock and price sends that are not finished, for links.
	 *
	 * @param int   $account_id Account.
	 * @param int[] $link_ids   Link ids.
	 * @return array<int,array<int,array<string,mixed>>> Link id => rows of the sync state.
	 */
	private function sends( int $account_id, array $link_ids ): array {
		if ( [] === $link_ids ) {
			return [];
		}

		$found = $this->db->get_results(
			$this->db->prepare(
				'SELECT link_id, kind, warehouse_id, in_flight_rev, last_error FROM %i WHERE account_id = %d AND pending_payload IS NOT NULL AND (sent_payload IS NULL OR pending_payload <> sent_payload OR in_flight_rev IS NOT NULL) AND link_id IN (' . implode( ', ', array_fill( 0, count( $link_ids ), '%d' ) ) . ') ORDER BY link_id ASC, kind ASC, warehouse_id ASC', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only placeholders are concatenated.
				array_merge( [ $this->sync_table, $account_id ], $link_ids )
			),
			'ARRAY_A'
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Prepared above.
		$sends = [];

		foreach ( is_array( $found ) ? $found : [] as $row ) {
			$sends[ (int) $row['link_id'] ][] = [
				'kind'          => (string) $row['kind'],
				'warehouse_id'  => (int) $row['warehouse_id'],
				'in_flight_rev' => null === $row['in_flight_rev'] ? null : (int) $row['in_flight_rev'],
				'last_error'    => (string) $row['last_error'],
			];
		}

		return $sends;
	}
}
