<?php
/**
 * The records provider of the page of shipments.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Shipments;

use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Activity_Normalizer;
use Woodev\Framework\Admin\Records\Provides_Field_Options;
use Woodev\Framework\Admin\Records\Records_Provider;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Seller;
use Woodev\Framework\Marketplace\Admin\Shared\Russian_Plural;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store;
use Woodev\Framework\Marketplace\Flows\Order_Meta_Contract;
use Woodev\Framework\Marketplace\Flows\Shipment_Report;
use Woodev\Framework\Marketplace\Flows\Shipment_Service;
use Woodev\Framework\Marketplace\Settings\Shipment_Noun;
use Woodev\Framework\Marketplace\Shipments\Shipment_Batches;
use Woodev\Framework\Marketplace\Shipments\Shipment_Refused;

defined( 'ABSPATH' ) || exit;

/**
 * The shipments of a marketplace account, one row per shipment with its units as child rows. It is the coordinator of the page:
 * the rows are built by {@see Shipments_Rows} from the shop's copy, the actions run through {@see Shipment_Service}, and the
 * adapter's seller actions on a unit through {@see Site_Orders_Seller}.
 *
 * Reading the table, its children and its activity never calls the marketplace. It is called by an applied action, and for the
 * choices of the «pickup point and date» dialog when the merchant opens it.
 *
 * @since 2.1.28
 */
final class Shipments_Provider implements Records_Provider, Provides_Field_Options {
	/**
	 * Table id: the page declares `tables => [ 'shipments' ]`.
	 */
	public const TABLE = 'shipments';

	/**
	 * Capability the seller actions ask for, the same as on «Заказы».
	 */
	private const EDIT = 'edit_shop_orders';

	/**
	 * Most cargo places the dialog takes: a typing mistake beyond it is refused.
	 */
	private const MAX_CARGO_TOTAL = 1000;

	/**
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * @var Shipment_Batches
	 */
	private $marketplace;

	/**
	 * @var Wpdb_Shipment_Store
	 */
	private $store;

	/**
	 * @var Shipment_Service
	 */
	private $service;

	/**
	 * @var Shipments_Rows
	 */
	private $rows;

	/**
	 * @var Shipment_Noun
	 */
	private $noun;

	/**
	 * The marketplace's id (the adapter's `id()`).
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * @var Order_Meta_Contract
	 */
	private $meta;

	/**
	 * Whether the user may edit an order: `function ( int $order_id ): bool`.
	 *
	 * @var callable
	 */
	private $can_edit;

	/**
	 * Queues a refresh of the account's shipments: `function ( int $account_id ): void`.
	 *
	 * @var callable
	 */
	private $queue_refresh;

	/**
	 * The download address of a document: `function ( int $account_id, string $key, string $document_id ): string`.
	 *
	 * @var callable
	 */
	private $download_url;

	/**
	 * @var Shipments_Data_Status|null
	 */
	private $data_status;

	/**
	 * Null when the adapter declares no seller actions.
	 *
	 * @var Site_Orders_Seller|null
	 */
	private $seller;

	/**
	 * Writes a line to the plugin's log: `function ( string $line ): void`.
	 *
	 * @var callable|null
	 */
	private $log_error;

	/**
	 * @since 2.1.28
	 *
	 * @param Wpdb_Account_Store         $accounts      Accounts.
	 * @param Shipment_Batches           $marketplace   The marketplace's shipments.
	 * @param Wpdb_Shipment_Store        $store         The shop's copy.
	 * @param Shipment_Service           $service       The actions.
	 * @param Shipments_Rows             $rows          Row builder.
	 * @param Shipment_Noun              $noun          Words.
	 * @param string                     $adapter_id    The marketplace's id.
	 * @param Order_Meta_Contract        $meta          The marketplace's order meta (owner stamps).
	 * @param callable                   $can_edit      `function ( int $order_id ): bool`.
	 * @param callable                   $queue_refresh `function ( int $account_id ): void`.
	 * @param callable                   $download_url  `function ( int $account_id, string $key, string $document_id ): string`.
	 * @param Shipments_Data_Status|null $data_status   The «Статус данных» block.
	 * @param Site_Orders_Seller|null    $seller        The adapter's seller actions.
	 * @param callable|null              $log_error     `function ( string $line ): void`.
	 */
	public function __construct( Wpdb_Account_Store $accounts, Shipment_Batches $marketplace, Wpdb_Shipment_Store $store, Shipment_Service $service, Shipments_Rows $rows, Shipment_Noun $noun, string $adapter_id, Order_Meta_Contract $meta, callable $can_edit, callable $queue_refresh, callable $download_url, ?Shipments_Data_Status $data_status = null, ?Site_Orders_Seller $seller = null, ?callable $log_error = null ) {
		$this->accounts      = $accounts;
		$this->marketplace   = $marketplace;
		$this->store         = $store;
		$this->service       = $service;
		$this->rows          = $rows;
		$this->noun          = $noun;
		$this->adapter_id    = $adapter_id;
		$this->meta          = $meta;
		$this->can_edit      = $can_edit;
		$this->queue_refresh = $queue_refresh;
		$this->download_url  = $download_url;
		$this->data_status   = $data_status;
		$this->seller        = $seller;
		$this->log_error     = $log_error;
	}

	/**
	 * @since 2.1.28
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::TABLE;
	}

	/**
	 * @since 2.1.28
	 *
	 * @param array<string,string> $context Requested context.
	 * @return array<string,mixed>
	 */
	public function get_definition( array $context ): array {
		$options = $this->account_options();
		$account = $this->account( $context ) ?? $this->account( [ 'account_id' => (string) ( $options[0]['value'] ?? '' ) ] );

		return Shipments_Definition::build(
			$options,
			$this->noun,
			null === $account ? [] : $this->marketplace->shipping_fields( $account ),
			$this->marketplace->max_cargo_places(),
			$this->marketplace->shipment_documents(),
			null === $this->seller ? [] : $this->seller->unit_definitions( self::EDIT )
		);
	}

	/**
	 * Every id must name a stored row of the account; an activity refresh may ask about rows that are gone.
	 *
	 * @since 2.1.28
	 *
	 * @param string               $operation Operation or action id.
	 * @param array<string,string> $context   Resolved context.
	 * @param string[]             $ids       Row ids.
	 * @return bool
	 */
	public function authorize( string $operation, array $context, array $ids ): bool {
		if ( [] === $ids ) {
			return true;
		}

		$account = $this->account( $context );

		if ( null === $account ) {
			return false;
		}

		foreach ( $ids as $id ) {
			$found = Shipments_Ids::parse( (string) $id, $account->id() );

			if ( null === $found ) {
				return false;
			}

			if ( 'activity' !== $operation && null === $this->stored( $account, $found ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @since 2.1.28
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param array<string,mixed>  $query   Validated query.
	 * @return array<string,mixed>
	 */
	public function query( array $context, array $query ): array {
		$account = $this->account( $context );

		if ( null === $account ) {
			return [
				'rows'  => [],
				'total' => 0,
			];
		}

		$filters = (array) ( $query['filters'] ?? [] );
		$asked   = [
			'search'    => (string) ( $query['search'] ?? '' ),
			'reship'    => true === ( $filters['reship'] ?? false ),
			'sort'      => (string) ( $query['sort'] ?? 'created' ),
			'direction' => (string) ( $query['direction'] ?? 'desc' ),
			'page'      => (int) $query['page'],
			'per_page'  => (int) $query['per_page'],
		];

		if ( isset( $filters['state'] ) ) {
			$asked['open'] = Shipments_Definition::STATE_OPEN === $filters['state'];
		}

		$page    = $this->store->page( $account->id(), $asked );
		$counts  = $this->store->state_counts( $account->id(), $asked );
		$refusal = $this->refusal( $account );
		$result  = [
			'rows'             => [],
			'total'            => $page['total'],
			'facets'           => [
				'state' => [
					[
						'value' => Shipments_Definition::STATE_OPEN,
						'label' => __( 'Открытые', 'woodev-plugin-framework' ),
						'count' => $counts['open'],
					],
					[
						'value' => Shipments_Definition::STATE_CLOSED,
						'label' => __( 'Переданные', 'woodev-plugin-framework' ),
						'count' => $counts['closed'],
					],
				],
			],
			'account_activity' => $this->account_activity( $account ),
			'messages'         => [ 'summary_label' => Russian_Plural::pick( $page['total'], $this->noun->one(), $this->noun->of_one(), $this->noun->of_many() ) ],
		];

		foreach ( $page['rows'] as $row ) {
			$result['rows'][] = $this->rows->row( $account, $row, $refusal );
		}

		if ( null !== $this->data_status ) {
			$result['data_status'] = $this->data_status->for_account( $account->id() );
		}

		return $result;
	}

	/**
	 * The units of one shipment.
	 *
	 * @since 2.1.28
	 *
	 * @param array<string,string> $context   Resolved context.
	 * @param string               $parent_id Shipment row id.
	 * @param array<string,mixed>  $query     Validated query.
	 * @return array<string,mixed>
	 */
	public function get_children( array $context, string $parent_id, array $query ): array {
		$account = $this->account( $context );
		$found   = null === $account ? null : Shipments_Ids::parse( $parent_id, $account->id() );
		$row     = null === $found || '' !== $found['unit'] ? null : $this->store->find( $account->id(), $found['shipment'] );

		if ( null === $row ) {
			return [
				'rows'  => [],
				'total' => 0,
			];
		}

		$page       = $this->store->units_page( $account->id(), $found['shipment'], (int) $query['page'], (int) $query['per_page'] );
		$refusal    = $this->refusal( $account );
		$has_target = $this->has_target( $account, $found['shipment'] );
		$rows       = [];

		foreach ( $page['rows'] as $unit ) {
			$rows[] = $this->rows->unit_row( $account, $row, $unit, $refusal, $has_target );
		}

		return [
			'rows'  => $rows,
			'total' => $page['total'],
		];
	}

	/**
	 * The plan of an action. Writes nothing and calls no marketplace.
	 *
	 * @since 2.1.28
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string               $action  Action id.
	 * @param string[]             $ids     Selected row ids.
	 * @param array<string,mixed>  $payload Validated fields.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the account, the licence or the stored state does not allow it.
	 * @throws Action_Input_Exception When the input is refused.
	 */
	public function preview( array $context, string $action, array $ids, array $payload ): array {
		$account = $this->require_account( $context );

		if ( $this->is_move( $action ) ) {
			$report = $this->plan_moves( $account, $ids, $payload );

			return [
				'requested' => $report['eligible'] + count( $report['report']->skipped() ),
				'eligible'  => $report['eligible'],
				'skipped'   => count( $report['report']->skipped() ),
				'groups'    => Shipment_Report_Groups::of( $report['report'] ),
			];
		}

		if ( $this->is_unit_action( $action ) ) {
			list( , $order, $unit_id ) = $this->unit_target( $account, $ids );

			return $this->seller->preview( $account, $action, [ $order ], $payload, $unit_id );
		}

		$reason = $this->stored_reason( $account, $action, $ids, $payload );

		if ( '' !== $reason ) {
			throw new Action_State_Exception( $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return [
			'requested' => 1,
			'eligible'  => 1,
			'skipped'   => 0,
		];
	}

	/**
	 * Judges an action from current state and applies it.
	 *
	 * @since 2.1.28
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string               $action  Action id.
	 * @param string[]             $ids     Selected row ids.
	 * @param array<string,mixed>  $payload Validated fields.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When nothing was done.
	 * @throws Action_Input_Exception When the input is refused.
	 */
	public function apply( array $context, string $action, array $ids, array $payload ): array {
		$account = $this->require_account( $context );

		try {
			if ( Shipments_Definition::CREATE === $action ) {
				return $this->apply_create( $account, $payload );
			}

			if ( Shipments_Definition::REFRESH === $action ) {
				return $this->apply_refresh( $account );
			}

			if ( $this->is_move( $action ) ) {
				return $this->apply_moves( $account, $ids, $payload );
			}

			if ( $this->is_unit_action( $action ) ) {
				return $this->apply_unit_action( $account, $action, $ids, $payload );
			}

			return $this->apply_to_shipment( $account, $action, $ids, $payload );
		} catch ( Shipment_Refused $e ) {
			throw new Action_State_Exception( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		} catch ( Action_State_Exception | Action_Input_Exception $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			$this->write_log( sprintf( 'A shipment action failed: %s: %s', get_class( $e ), $e->getMessage() ) );

			throw new Action_State_Exception( __( 'Не удалось выполнить действие. Проверьте состояние, прежде чем повторять.', 'woodev-plugin-framework' ) );
		}
	}

	/**
	 * The current rows of the asked ids. A local read.
	 *
	 * @since 2.1.28
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string[]             $ids     Visible row ids.
	 * @return array<string,mixed>
	 */
	public function get_activity( array $context, array $ids ): array {
		$account = $this->account( $context );

		if ( null === $account ) {
			return [ 'removed_ids' => $ids ];
		}

		$rows = $this->current_rows( $account, $ids );

		return [
			'rows'             => $rows,
			'removed_ids'      => array_values( array_diff( $ids, array_column( $rows, 'id' ) ) ),
			'account_activity' => $this->account_activity( $account ),
		];
	}

	/**
	 * The choices of a list of a dialog: the open shipments of a move, the marketplace's own choices of the shipping dialog (asked
	 * only once the account may change shipments), or a seller action's list.
	 *
	 * @since 2.1.28
	 *
	 * @param array<string,string>                             $context Resolved context.
	 * @param string                                           $action  Action id.
	 * @param string                                           $field   Field id.
	 * @param array{row_id:string,search:string,cursor:string} $query   Validated input.
	 * @return array{options:array<int,array{value:string,label:string}>,next_cursor:null}
	 * @throws \Throwable When the marketplace does not answer (the engine answers a generic error and logs it).
	 */
	public function get_field_options( array $context, string $action, string $field, array $query ): array {
		$account = $this->account( $context );
		$options = [];

		if ( null !== $account ) {
			$found = '' === $query['row_id'] ? null : Shipments_Ids::parse( $query['row_id'], $account->id() );

			if ( $this->is_move( $action ) && Shipments_Definition::FIELD_TARGET === $field ) {
				$options = $this->targets( $account, $query['search'], null === $found ? '' : $found['shipment'] );
			} elseif ( Shipments_Definition::SHIPPING === $action && null !== $found && '' === $this->refusal( $account ) ) {
				$options = $this->shipping_options( $account, $found['shipment'], $field, $query['search'] );
			} elseif ( $this->is_unit_action( $action ) ) {
				$options = $this->seller->field_options( $account, $action, $field, $query['search'] );
			}
		}

		return [
			'options'     => array_slice( $options, 0, Wpdb_Shipment_Store::CHOICES ),
			'next_cursor' => null,
		];
	}

	/**
	 * Why an action on one shipment cannot run, judged from the stored copy.
	 *
	 * @param Account             $account Account.
	 * @param string              $action  Action id.
	 * @param string[]            $ids     Row ids.
	 * @param array<string,mixed> $payload Fields.
	 * @return string Empty when it can.
	 * @throws Action_State_Exception When the row is not available.
	 * @throws Action_Input_Exception When a value is refused.
	 */
	private function stored_reason( Account $account, string $action, array $ids, array $payload ): string {
		if ( Shipments_Definition::CREATE === $action ) {
			$this->name_of( $payload );

			return $this->refusal( $account );
		}

		if ( Shipments_Definition::REFRESH === $action ) {
			return '';
		}

		$row      = $this->shipment_target( $account, $ids );
		$contents = $this->store->contents( $row );

		if ( 0 === strpos( $action, Shipments_Definition::DOCUMENT ) ) {
			$reason = $this->marketplace->document_refusal( $contents->shipment(), $contents, $this->document_id( $action ) );

			return '' !== $reason ? $reason : $this->service->download_refusal( $account );
		}

		$refusal = $this->refusal( $account );

		if ( '' !== $refusal ) {
			return $refusal;
		}

		if ( Shipments_Definition::HAND_OVER === $action ) {
			return $this->service->hand_over_reason( $contents );
		}

		if ( Shipments_Definition::CARGO === $action ) {
			return $this->service->cargo_total_reason( $contents, $this->total_of( $payload ) );
		}

		if ( Shipments_Definition::SHIPPING !== $action ) {
			throw new Action_State_Exception( __( 'Это действие здесь недоступно.', 'woodev-plugin-framework' ) );
		}

		return $contents->shipment()->is_open() ? '' : $this->service->closed_sentence();
	}

	/**
	 * @param Account             $account Account.
	 * @param array<string,mixed> $payload Fields.
	 * @return array<string,mixed>
	 * @throws \Throwable When the shipment was not created.
	 */
	private function apply_create( Account $account, array $payload ): array {
		$this->service->create( $account, $this->name_of( $payload ) );

		return self::one_done();
	}

	/**
	 * @param Account $account Account.
	 * @return array<string,mixed>
	 * @throws \Throwable When the refresh cannot be queued.
	 */
	private function apply_refresh( Account $account ): array {
		$refusal = $this->service->refusal( $account, __( 'прочитать список с маркетплейса', 'woodev-plugin-framework' ) );

		if ( '' !== $refusal ) {
			throw new Action_State_Exception( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$this->store->retry_failed_reads( $account->id() );
		call_user_func( $this->queue_refresh, $account->id() );

		return [ 'queued' => true ] + self::one_done();
	}

	/**
	 * Runs an action on one shipment: the shipping choice, the cargo places, the hand-over or a document.
	 *
	 * @param Account             $account Account.
	 * @param string              $action  Action id.
	 * @param string[]            $ids     Row ids.
	 * @param array<string,mixed> $payload Fields.
	 * @return array<string,mixed>
	 * @throws \Throwable When nothing was done.
	 */
	private function apply_to_shipment( Account $account, string $action, array $ids, array $payload ): array {
		$row = $this->shipment_target( $account, $ids );
		$key = (string) $row['shipment_key'];

		if ( 0 === strpos( $action, Shipments_Definition::DOCUMENT ) ) {
			$reason = $this->stored_reason( $account, $action, $ids, $payload );

			if ( '' !== $reason ) {
				throw new Action_State_Exception( $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			}

			return [
				'download' => [
					'url'      => call_user_func( $this->download_url, $account->id(), $key, $this->document_id( $action ) ),
					'filename' => '',
				],
			] + self::one_done();
		}

		$result = self::one_done();

		if ( Shipments_Definition::SHIPPING === $action ) {
			$this->service->choose_shipping( $account, $key, $this->shipping_values( $account, $payload ) );
		} elseif ( Shipments_Definition::CARGO === $action ) {
			if ( 0 === $this->service->fill_cargo_places( $account, $key, $this->total_of( $payload ) ) ) {
				/* translators: %s: the marketplace's word for cargo places, «грузовых мест». */
				$result['messages'] = [ 'action_status_done' => sprintf( __( 'Нужное количество %s уже есть: ничего не отправлено.', 'woodev-plugin-framework' ), $this->noun->of_places() ) ];
			}
		} elseif ( Shipments_Definition::HAND_OVER === $action ) {
			$this->service->hand_over( $account, $key );
		} else {
			throw new Action_State_Exception( __( 'Это действие здесь недоступно.', 'woodev-plugin-framework' ) );
		}

		return [ 'rows' => $this->current_rows( $account, [ $ids[0] ] ) ] + $result;
	}

	/**
	 * Judges a move of the asked units, one source shipment at a time.
	 *
	 * @param Account             $account Account.
	 * @param string[]            $ids     Unit row ids.
	 * @param array<string,mixed> $payload Fields.
	 * @return array{eligible:int,report:Shipment_Report}
	 * @throws Action_State_Exception When the account does not allow it or nothing could be judged.
	 * @throws Action_Input_Exception When no target was chosen.
	 */
	private function plan_moves( Account $account, array $ids, array $payload ): array {
		$target  = $this->target_of( $payload );
		$refusal = $this->refusal( $account );

		if ( '' !== $refusal ) {
			throw new Action_State_Exception( $refusal ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$total    = new Shipment_Report( $target );
		$eligible = 0;

		foreach ( $this->units_by_shipment( $account, $ids ) as $source => $units ) {
			try {
				list( , , $movable, , $report ) = $this->service->move_plan( $account, (string) $source, $units, $target );
			} catch ( Shipment_Refused $e ) {
				$this->skip_all( $total, $account, (string) $source, $units, $e->getMessage() );
				continue;
			}

			$eligible += count( $movable );

			foreach ( $report->skipped() as $skip ) {
				$total->add_skipped( $skip['reason'], $skip['example'] );
			}
		}

		return [
			'eligible' => $eligible,
			'report'   => $total,
		];
	}

	/**
	 * @param Account             $account Account.
	 * @param string[]            $ids     Unit row ids.
	 * @param array<string,mixed> $payload Fields.
	 * @return array<string,mixed>
	 * @throws \Throwable When nothing was done.
	 */
	private function apply_moves( Account $account, array $ids, array $payload ): array {
		$target  = $this->target_of( $payload );
		$total   = new Shipment_Report( $target );
		$done    = 0;
		$groups  = $this->units_by_shipment( $account, $ids );
		$refused = null;

		foreach ( $groups as $source => $units ) {
			try {
				$report = $this->service->move( $account, (string) $source, $units, $target );
			} catch ( Shipment_Refused $e ) {
				$refused = $e;

				foreach ( $units as $unit_key ) {
					$total->add_failed( $e->getMessage(), $this->example( $account, (string) $source, $unit_key ) );
				}

				continue;
			}

			$done += $report->done();

			foreach ( $report->failed() as $failure ) {
				$total->add_failed( $failure['reason'], $failure['example'] );
			}

			foreach ( $report->skipped() as $skip ) {
				$total->add_skipped( $skip['reason'], $skip['example'] );
			}
		}

		if ( null !== $refused && 1 === count( $groups ) && 0 === $done ) {
			throw $refused; // One shipment, nothing moved: the merchant reads the reason, not a count.
		}

		$affected = [ Shipments_Ids::row( $account->id(), $target ) ];

		foreach ( array_keys( $groups ) as $source ) {
			$affected[] = Shipments_Ids::row( $account->id(), (string) $source );
		}

		$rows = $this->current_rows( $account, array_merge( $affected, $ids ) );

		return [
			'requested'   => $done + count( $total->failed() ) + count( $total->skipped() ),
			'accepted'    => $done,
			'failed'      => count( $total->failed() ),
			'skipped'     => count( $total->skipped() ),
			'groups'      => Shipment_Report_Groups::of( $total ),
			'rows'        => $rows,
			'removed_ids' => array_values( array_diff( $ids, array_column( $rows, 'id' ) ) ),
		];
	}

	/**
	 * @param Account             $account Account.
	 * @param string              $action  Action id.
	 * @param string[]            $ids     One unit row id.
	 * @param array<string,mixed> $payload Fields.
	 * @return array<string,mixed>
	 * @throws \Throwable When nothing was done.
	 */
	private function apply_unit_action( Account $account, string $action, array $ids, array $payload ): array {
		list( $key, $order, $unit_id ) = $this->unit_target( $account, $ids );

		$result = $this->seller->apply( $account, $action, [ $order ], $payload, $unit_id );

		$this->service->reread( $account, $key ); // The unit's «what is missing» changed on the marketplace.

		return $result + [ 'rows' => $this->current_rows( $account, [ Shipments_Ids::row( $account->id(), $key ), $ids[0] ] ) ];
	}

	/**
	 * The shipment key, the order and the unit id of the one asked unit row, for a seller action. The order must carry the
	 * account's owner stamp and the user must be allowed to edit it, as on «Заказы».
	 *
	 * @param Account  $account Account.
	 * @param string[] $ids     Row ids.
	 * @return array{0:string,1:\WC_Order,2:string}
	 * @throws Action_State_Exception When the unit has no order the user may change.
	 */
	private function unit_target( Account $account, array $ids ): array {
		$found = 1 === count( $ids ) ? Shipments_Ids::parse( $ids[0], $account->id() ) : null;
		$unit  = null === $found || '' === $found['unit'] ? null : $this->store->unit( $account->id(), $found['shipment'], $found['unit'] );
		$order = null === $unit ? null : $this->own_order( $account, (int) $unit['order_id'] );

		if ( null === $order ) {
			throw new Action_State_Exception( __( 'Заказ этого товара не найден. Обновите страницу.', 'woodev-plugin-framework' ) );
		}

		return [ $found['shipment'], $order, (string) $unit['unit_id'] ];
	}

	/**
	 * @param Account $account  Account.
	 * @param int     $order_id Order id; 0 for none.
	 * @return \WC_Order|null The order, when it carries the account's owner stamp and the user may edit it.
	 */
	private function own_order( Account $account, int $order_id ): ?\WC_Order {
		$owner = $this->meta->account_owner( $account );

		if ( $order_id < 1 || '' === $owner || $this->meta->order_owner( $order_id ) !== $owner || true !== call_user_func( $this->can_edit, $order_id ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * @param Account  $account Account.
	 * @param string[] $ids     Row ids.
	 * @return array<string,mixed> The row of the one asked shipment.
	 * @throws Action_State_Exception When it is not available.
	 */
	private function shipment_target( Account $account, array $ids ): array {
		$found = 1 === count( $ids ) ? Shipments_Ids::parse( $ids[0], $account->id() ) : null;
		$row   = null === $found || '' !== $found['unit'] ? null : $this->store->find( $account->id(), $found['shipment'] );

		if ( null === $row ) {
			throw new Action_State_Exception( $this->service->not_found_sentence() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $row;
	}

	/**
	 * @param Account  $account Account.
	 * @param string[] $ids     Unit row ids.
	 * @return array<string,string[]> Shipment key => unit keys.
	 */
	private function units_by_shipment( Account $account, array $ids ): array {
		$groups = [];

		foreach ( $ids as $id ) {
			$found = Shipments_Ids::parse( (string) $id, $account->id() );

			if ( null !== $found && '' !== $found['unit'] ) {
				$groups[ $found['shipment'] ][] = $found['unit'];
			}
		}

		return $groups;
	}

	/**
	 * @param Shipment_Report $report   Report.
	 * @param Account         $account  Account.
	 * @param string          $shipment Shipment key.
	 * @param string[]        $units    Unit keys.
	 * @param string          $reason   Why none of them can move.
	 * @return void
	 */
	private function skip_all( Shipment_Report $report, Account $account, string $shipment, array $units, string $reason ): void {
		foreach ( $units as $unit_key ) {
			$report->add_skipped( $reason, $this->example( $account, $shipment, $unit_key ) );
		}
	}

	/**
	 * @param Account $account  Account.
	 * @param string  $shipment Shipment key.
	 * @param string  $unit_key Unit key.
	 * @return string The unit as the merchant knows it.
	 */
	private function example( Account $account, string $shipment, string $unit_key ): string {
		$row = $this->store->unit( $account->id(), $shipment, $unit_key );

		return null === $row ? '' : (string) $row['label'];
	}

	/**
	 * @param Account $account Account.
	 * @param string  $search  Search.
	 * @param string  $exclude Shipment key left out; empty for none.
	 * @return array<int,array{value:string,label:string}> The open shipments.
	 */
	private function targets( Account $account, string $search, string $exclude ): array {
		$options = [];

		foreach ( $this->store->open( $account->id(), $search ) as $row ) {
			if ( $row['shipment_key'] === $exclude ) {
				continue;
			}

			$options[] = [
				'value' => (string) $row['shipment_key'],
				'label' => mb_substr( '' === $row['name'] ? (string) $row['remote_id'] : $row['name'] . ' (' . $row['remote_id'] . ')', 0, 200 ),
			];
		}

		return $options;
	}

	/**
	 * @param Account $account  Account.
	 * @param string  $shipment Shipment key.
	 * @return bool Whether another open shipment exists.
	 */
	private function has_target( Account $account, string $shipment ): bool {
		return [] !== $this->targets( $account, '', $shipment );
	}

	/**
	 * @param Account $account Account.
	 * @param string  $key     Shipment key.
	 * @param string  $field   Field id.
	 * @param string  $search  Search.
	 * @return array<int,array{value:string,label:string}> The marketplace's choices.
	 * @throws \Throwable When the marketplace does not answer.
	 */
	private function shipping_options( Account $account, string $key, string $field, string $search ): array {
		$row     = $this->store->find( $account->id(), $key );
		$options = [];

		if ( null === $row || 1 !== (int) $row['is_open'] ) {
			return $options;
		}

		foreach ( $this->marketplace->shipping_options( $account, $this->store->shipment( $row ), $field, $search ) as $value => $label ) {
			$options[] = [
				'value' => (string) $value,
				'label' => mb_substr( (string) $label, 0, 200 ),
			];
		}

		return $options;
	}

	/**
	 * @param Account             $account Account.
	 * @param array<string,mixed> $payload Fields.
	 * @return array<string,string> The values of the shipping dialog's fields.
	 */
	private function shipping_values( Account $account, array $payload ): array {
		$values = [];

		foreach ( $this->marketplace->shipping_fields( $account ) as $field ) {
			if ( isset( $payload[ $field->id() ] ) && '' !== (string) $payload[ $field->id() ] ) {
				$values[ $field->id() ] = (string) $payload[ $field->id() ];
			}
		}

		return $values;
	}

	/**
	 * @param array<string,mixed> $payload Fields.
	 * @return string The name of a new shipment.
	 * @throws Action_Input_Exception When it is empty or too long.
	 */
	private function name_of( array $payload ): string {
		$name = trim( (string) ( $payload[ Shipments_Definition::FIELD_NAME ] ?? '' ) );

		if ( '' === $name || mb_strlen( $name ) > Shipment_Service::MAX_NAME_LENGTH ) {
			/* translators: %d: most characters. */
			throw new Action_Input_Exception( [ Shipments_Definition::FIELD_NAME => sprintf( __( 'Введите название не длиннее %d символов.', 'woodev-plugin-framework' ), Shipment_Service::MAX_NAME_LENGTH ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $name;
	}

	/**
	 * @param array<string,mixed> $payload Fields.
	 * @return int How many cargo places there must be in all.
	 * @throws Action_Input_Exception When it is not a whole number in range.
	 */
	private function total_of( array $payload ): int {
		$value = $payload[ Shipments_Definition::FIELD_TOTAL ] ?? 0;
		$total = is_numeric( $value ) && (float) (int) $value === (float) $value ? (int) $value : 0;

		if ( $total < 1 || $total > self::MAX_CARGO_TOTAL ) {
			/* translators: %d: largest number accepted. */
			throw new Action_Input_Exception( [ Shipments_Definition::FIELD_TOTAL => sprintf( __( 'Введите целое число от 1 до %d.', 'woodev-plugin-framework' ), self::MAX_CARGO_TOTAL ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $total;
	}

	/**
	 * @param array<string,mixed> $payload Fields.
	 * @return string Key of the shipment a move goes into.
	 * @throws Action_Input_Exception When none was chosen.
	 */
	private function target_of( array $payload ): string {
		$target = (string) ( $payload[ Shipments_Definition::FIELD_TARGET ] ?? '' );

		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', $target ) ) {
			throw new Action_Input_Exception( [ Shipments_Definition::FIELD_TARGET => __( 'Выберите значение из списка.', 'woodev-plugin-framework' ) ] );
		}

		return $target;
	}

	/**
	 * The current rows of asked ids, shipments and units alike; one that is gone is left out.
	 *
	 * @param Account  $account Account.
	 * @param string[] $ids     Row ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function current_rows( Account $account, array $ids ): array {
		$refusal = $this->refusal( $account );
		$rows    = [];

		foreach ( array_unique( $ids ) as $id ) {
			$found = Shipments_Ids::parse( (string) $id, $account->id() );
			$row   = null === $found ? null : $this->store->find( $account->id(), $found['shipment'] );

			if ( null === $row ) {
				continue;
			}

			if ( '' === $found['unit'] ) {
				$rows[] = $this->rows->row( $account, $row, $refusal );
				continue;
			}

			$unit = $this->store->unit( $account->id(), $found['shipment'], $found['unit'] );

			if ( null !== $unit ) {
				$rows[] = $this->rows->unit_row( $account, $row, $unit, $refusal, $this->has_target( $account, $found['shipment'] ) );
			}
		}

		return $rows;
	}

	/**
	 * @param Account                            $account Account.
	 * @param array{shipment:string,unit:string} $found   Parsed id.
	 * @return array<string,mixed>|null The stored row the id names.
	 */
	private function stored( Account $account, array $found ): ?array {
		return '' === $found['unit']
			? $this->store->find( $account->id(), $found['shipment'] )
			: $this->store->unit( $account->id(), $found['shipment'], $found['unit'] );
	}

	/**
	 * @param Account $account Account.
	 * @return string Why the account cannot change shipments now; empty when it can.
	 */
	private function refusal( Account $account ): string {
		/* translators: %s: the marketplace's word for shipments, «поставки». */
		return $this->service->refusal( $account, sprintf( __( 'изменить %s на маркетплейсе', 'woodev-plugin-framework' ), $this->noun->many() ) );
	}

	/**
	 * @param Account $account Account.
	 * @return array<string,mixed> The line of a refresh in progress.
	 */
	private function account_activity( Account $account ): array {
		if ( ! $this->store->is_refreshing( $account->id() ) ) {
			return Activity_Normalizer::idle_account();
		}

		return [
			'active'          => true,
			'text'            => __( 'Список обновляется с маркетплейса.', 'woodev-plugin-framework' ),
			'blocked_actions' => [ Shipments_Definition::REFRESH ],
		];
	}

	/**
	 * @param string $action Action id.
	 * @return bool
	 */
	private function is_move( string $action ): bool {
		return Shipments_Definition::MOVE === $action || Shipments_Definition::BULK_MOVE === $action;
	}

	/**
	 * @param string $action Action id.
	 * @return bool Whether it is a seller action offered on a unit.
	 */
	private function is_unit_action( string $action ): bool {
		return null !== $this->seller && $this->seller->handles_unit( $action );
	}

	/**
	 * @param string $action A document action id.
	 * @return string Document id.
	 */
	private function document_id( string $action ): string {
		return substr( $action, strlen( Shipments_Definition::DOCUMENT ) );
	}

	/**
	 * @return array{requested:int,accepted:int,skipped:int,failed:int}
	 */
	private static function one_done(): array {
		return [
			'requested' => 1,
			'accepted'  => 1,
			'skipped'   => 0,
			'failed'    => 0,
		];
	}

	/**
	 * @return array<int,array{value:string,label:string}> The enabled accounts of the marketplace.
	 */
	private function account_options(): array {
		$options = [];

		foreach ( $this->accounts->all( $this->adapter_id, true ) as $account ) {
			$options[] = [
				'value' => (string) $account->id(),
				'label' => '' === $account->name() ? '#' . $account->id() : $account->name(),
			];
		}

		return $options;
	}

	/**
	 * @param array<string,string> $context Context.
	 * @return Account|null The enabled account of this marketplace the table is open for.
	 */
	private function account( array $context ): ?Account {
		$id      = (int) ( $context['account_id'] ?? 0 );
		$account = $id > 0 ? $this->accounts->get( $id ) : null;

		return null !== $account && $account->is_enabled() && $account->adapter_id() === $this->adapter_id ? $account : null;
	}

	/**
	 * @param array<string,string> $context Context.
	 * @return Account
	 * @throws Action_State_Exception When the account is gone or switched off.
	 */
	private function require_account( array $context ): Account {
		$account = $this->account( $context );

		if ( null === $account ) {
			throw new Action_State_Exception( __( 'Аккаунт не найден или выключен.', 'woodev-plugin-framework' ) );
		}

		return $account;
	}

	/**
	 * @param string $line Log line.
	 * @return void
	 */
	private function write_log( string $line ): void {
		if ( null !== $this->log_error ) {
			call_user_func( $this->log_error, $line );
		}
	}
}
