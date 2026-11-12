<?php
/**
 * The «Заказы» table of the records engine.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Activity_Normalizer;
use Woodev\Framework\Admin\Records\Provides_Field_Options;
use Woodev\Framework\Admin\Records\Records_Provider;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Cancel_Refused;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Flows\Label_Exception;
use Woodev\Framework\Marketplace\Flows\Order_Meta_Contract;

defined( 'ABSPATH' ) || exit;

/**
 * The orders a marketplace account brought to the shop, as a records provider: one row per remote order group that has a WooCommerce
 * order, on either order storage. It is the small coordinator: SQL is in {@see Site_Orders_Reader}, cells in {@see Site_Orders_Rows},
 * processes and locks in {@see Site_Orders_Activity}, labels in {@see Site_Orders_Labels} and the cancellation in
 * {@see Site_Orders_Cancel}.
 *
 * Sorting, filtering and paging are done in SQL over the whole table, never over one page. Reading never calls the marketplace; the
 * only remote reads are the cancellation reasons of one order, when the merchant opens the cancel window, and the two actions.
 *
 * Every row is `{account id}:{order id}`. An action may touch an order only when it is a registry row of the account, carries the
 * account's owner stamp (the check the label download makes too) and the user may edit it.
 *
 * @since 2.1.5
 */
final class Site_Orders_Provider implements Records_Provider, Provides_Field_Options {
	/**
	 * Table id: the page declares `tables => [ 'orders' ]`.
	 */
	public const TABLE = 'orders';

	/**
	 * Most reasons the cancel window lists.
	 */
	private const MAX_REASONS = 100;

	/**
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * @var Site_Orders_Reader
	 */
	private $reader;

	/**
	 * @var Site_Orders_Rows
	 */
	private $rows;

	/**
	 * @var Site_Orders_Activity
	 */
	private $activity;

	/**
	 * Null when the adapter has no label calls.
	 *
	 * @var Site_Orders_Labels|null
	 */
	private $labels;

	/**
	 * Null when the adapter cannot cancel postings.
	 *
	 * @var Site_Orders_Cancel|null
	 */
	private $cancel;

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
	 * The marketplace's id (the adapter's `id()`).
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * Writes a line to the plugin's log: `function ( string $line ): void`.
	 *
	 * @var callable|null
	 */
	private $log_error;

	/**
	 * Creates the provider.
	 *
	 * @since 2.1.5
	 *
	 * @param Wpdb_Account_Store      $accounts   Accounts.
	 * @param Site_Orders_Reader      $reader     Reader.
	 * @param Site_Orders_Rows        $rows       Row builder.
	 * @param Site_Orders_Activity    $activity   Activity reader.
	 * @param Site_Orders_Labels|null $labels     Labels, when the adapter has label calls.
	 * @param Site_Orders_Cancel|null $cancel     Cancellation, when the adapter can cancel.
	 * @param Order_Meta_Contract     $meta       The marketplace's order meta (owner stamps).
	 * @param callable                $can_edit   `function ( int $order_id ): bool`, the object right to edit an order.
	 * @param string                  $adapter_id The marketplace's id.
	 * @param callable|null           $log_error  `function ( string $line ): void`, for failures that are not a refusal.
	 */
	public function __construct( Wpdb_Account_Store $accounts, Site_Orders_Reader $reader, Site_Orders_Rows $rows, Site_Orders_Activity $activity, ?Site_Orders_Labels $labels, ?Site_Orders_Cancel $cancel, Order_Meta_Contract $meta, callable $can_edit, string $adapter_id, ?callable $log_error = null ) {
		$this->accounts   = $accounts;
		$this->reader     = $reader;
		$this->rows       = $rows;
		$this->activity   = $activity;
		$this->labels     = $labels;
		$this->cancel     = $cancel;
		$this->meta       = $meta;
		$this->can_edit   = $can_edit;
		$this->adapter_id = $adapter_id;
		$this->log_error  = $log_error;
	}

	/**
	 * The row actions a plugin's adapter earns: the order link, and the label and cancel actions when it has them.
	 *
	 * @since 2.1.5
	 *
	 * @param bool $with_labels Whether the adapter has label calls.
	 * @param bool $with_cancel Whether the adapter can cancel postings.
	 * @return string[]
	 */
	public static function offered_actions( bool $with_labels, bool $with_cancel ): array {
		return array_values(
			array_filter(
				[
					Site_Orders_Definition::OPEN,
					$with_labels ? Site_Orders_Definition::LABEL : '',
					$with_cancel ? Site_Orders_Definition::CANCEL : '',
				]
			)
		);
	}

	/**
	 * @since 2.1.5
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::TABLE;
	}

	/**
	 * @since 2.1.5
	 *
	 * @param array<string,string> $context Requested context.
	 * @return array<string,mixed>
	 */
	public function get_definition( array $context ): array {
		return Site_Orders_Definition::build( $this->account_options(), $this->status_options(), $this->offered() );
	}

	/**
	 * Every id must be a row of this account's table. For an action (and for the cancel window's reasons) it must also be an order
	 * of the account — registered for it and carrying its owner stamp — that the user may edit; anything else is refused like an unknown
	 * id. The activity refresh asks about rows that were on screen, so a vanished one is answered in `removed_ids`, not refused.
	 *
	 * @since 2.1.5
	 *
	 * @param string               $operation `definition`, `list`, `children`, `options`, `activity` or an action id.
	 * @param array<string,string> $context   Resolved context.
	 * @param string[]             $ids       Row ids.
	 * @return bool
	 */
	public function authorize( string $operation, array $context, array $ids ): bool {
		if ( [] === $ids ) {
			return true;
		}

		$order_ids = 'children' === $operation ? null : $this->order_ids( $this->account_id( $context ), $ids );

		if ( null === $order_ids ) {
			return false;
		}

		if ( 'activity' === $operation ) {
			return true;
		}

		$account = $this->account( $context );

		return null !== $account && null !== $this->targets( $account, $order_ids );
	}

	/**
	 * Reads one page, sorted and filtered over the whole table.
	 *
	 * @since 2.1.5
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

		$page  = $this->reader->page( Site_Orders_Filter::from_query( $account->id(), $query ), (int) $query['page'], (int) $query['per_page'] );
		$total = $page['total'];

		return [
			'rows'     => $this->build_rows( $account, $page['items'] ),
			'total'    => $total,
			'messages' => [
				/* translators: %d: number of orders. */
				'summary' => sprintf( _n( 'Найден %d заказ', 'Найдено заказов: %d', $total, 'woodev-plugin-framework' ), $total ),
			],
		];
	}

	/**
	 * Orders have no children.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,string> $context   Resolved context.
	 * @param string               $parent_id Row id.
	 * @param array<string,mixed>  $query     Validated query.
	 * @return array<string,mixed>
	 */
	public function get_children( array $context, string $parent_id, array $query ): array {
		return [
			'rows'  => [],
			'total' => 0,
		];
	}

	/**
	 * The plan of an action. Writes nothing and calls no marketplace.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string               $action  Action id.
	 * @param string[]             $ids     Selected row ids.
	 * @param array<string,mixed>  $payload Validated fields.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the order, the account or the licence does not allow the action.
	 */
	public function preview( array $context, string $action, array $ids, array $payload ): array {
		$call = $this->prepare( $context, $action, $ids );

		if ( Site_Orders_Definition::CANCEL === $action ) {
			return $this->cancel_plan( $call );
		}

		return $this->label_plan( $call );
	}

	/**
	 * Replans from current state and applies an action.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string               $action  Action id.
	 * @param string[]             $ids     Selected row ids.
	 * @param array<string,mixed>  $payload Validated fields.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the order, the account or the licence does not allow the action, or the marketplace fails.
	 * @throws Action_Input_Exception When the cancel reason is missing.
	 */
	public function apply( array $context, string $action, array $ids, array $payload ): array {
		$call = $this->prepare( $context, $action, $ids );

		if ( Site_Orders_Definition::CANCEL === $action ) {
			return $this->cancel_apply( $call, $payload );
		}

		return $this->label_apply( $call );
	}

	/**
	 * The current rows of the asked ids. A local read.
	 *
	 * @since 2.1.5
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

		$order_ids = $this->order_ids( $account->id(), $ids ) ?? [];
		$found     = $this->reader->find( $account->id(), $order_ids );
		$items     = [];

		foreach ( $order_ids as $order_id ) {
			if ( isset( $found[ $order_id ] ) ) {
				$items[] = $found[ $order_id ];
			}
		}

		$rows = $this->build_rows( $account, $items );

		return [
			'rows'             => $rows,
			'removed_ids'      => array_values( array_diff( $ids, array_column( $rows, 'id' ) ) ),
			'account_activity' => Activity_Normalizer::idle_account(),
		];
	}

	/**
	 * The reasons of the cancel window: the marketplace's own list for the posting of the row.
	 *
	 * @since 2.1.5
	 *
	 * @param array<string,string>                             $context Resolved context.
	 * @param string                                           $action  Action id.
	 * @param string                                           $field   Field id.
	 * @param array{row_id:string,search:string,cursor:string} $query   Validated input.
	 * @return array{options:array<int,array{value:string,label:string}>,next_cursor:null}
	 * @throws Cancel_Refused When the marketplace refuses or does not answer (the engine answers a generic error and logs it).
	 */
	public function get_field_options( array $context, string $action, string $field, array $query ): array {
		$none    = [
			'options'     => [],
			'next_cursor' => null,
		];
		$account = $this->account( $context );
		$target  = null === $account || null === $this->cancel || Site_Orders_Definition::CANCEL !== $action || 'reason' !== $field ? null : $this->target_of_row( $account, $query['row_id'] );
		$order   = null === $target ? null : $this->order( $target['order_id'] );

		if ( null === $account || null === $order ) {
			return $none;
		}

		$options = array_filter(
			$this->cancel->reasons( $account, $order ),
			static function ( array $option ) use ( $query ): bool {
				return '' === $query['search'] || false !== mb_stripos( $option['label'], $query['search'] );
			}
		);

		return [
			'options'     => array_slice( array_values( $options ), 0, self::MAX_REASONS ),
			'next_cursor' => null,
		];
	}

	/**
	 * Checks an action request and resolves it: the account and the registry items of the asked rows.
	 *
	 * @param array<string,string> $context Resolved context.
	 * @param string               $action  Action id.
	 * @param string[]             $ids     Selected row ids.
	 * @return array{account:Account,targets:array<int,array<string,mixed>>}
	 * @throws Action_State_Exception When the action is not one this table runs, the account is gone or the order is not available.
	 */
	private function prepare( array $context, string $action, array $ids ): array {
		if ( ! in_array( $action, $this->offered(), true ) || Site_Orders_Definition::OPEN === $action ) {
			throw new Action_State_Exception( __( 'Это действие недоступно для заказов этого маркетплейса.', 'woodev-plugin-framework' ) );
		}

		$account = $this->account( $context );

		if ( null === $account ) {
			throw new Action_State_Exception( __( 'Аккаунт не найден или выключен.', 'woodev-plugin-framework' ) );
		}

		$targets = $this->targets( $account, $this->order_ids( $account->id(), $ids ) ?? [] );

		if ( null === $targets || [] === $targets ) {
			throw new Action_State_Exception( __( 'Заказ не найден. Обновите страницу.', 'woodev-plugin-framework' ) );
		}

		return [
			'account' => $account,
			'targets' => $targets,
		];
	}

	/**
	 * The plan of a cancellation: one order, which a finished posting cannot take.
	 *
	 * @param array{account:Account,targets:array<int,array<string,mixed>>} $call Resolved call.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the account or the licence does not allow cancelling.
	 */
	private function cancel_plan( array $call ): array {
		$order = $this->order_to_cancel( $call );

		if ( $this->cancel->is_finished( $order ) ) {
			return $this->refused_plan( __( 'Это отправление уже завершено или отменено, отменить его нельзя.', 'woodev-plugin-framework' ), $call['targets'][0]['external_key'] );
		}

		return [
			'requested' => 1,
			'eligible'  => 1,
			'skipped'   => 0,
		];
	}

	/**
	 * Cancels the posting of the order.
	 *
	 * @param array{account:Account,targets:array<int,array<string,mixed>>} $call    Resolved call.
	 * @param array<string,mixed>                                           $payload Validated fields.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the account, the licence or the marketplace does not allow it.
	 * @throws Action_Input_Exception When the reason or its explanation is missing.
	 */
	private function cancel_apply( array $call, array $payload ): array {
		$order = $this->order_to_cancel( $call );

		if ( $this->cancel->is_finished( $order ) ) {
			return $this->refused_result( __( 'Это отправление уже завершено или отменено, отменить его нельзя.', 'woodev-plugin-framework' ), $call['targets'][0]['external_key'] );
		}

		$reason_id = (int) ( $payload['reason'] ?? 0 );
		$message   = trim( (string) ( $payload['message'] ?? '' ) );

		if ( $reason_id < 1 ) {
			throw new Action_Input_Exception( [ 'reason' => __( 'Выберите причину отмены.', 'woodev-plugin-framework' ) ] );
		}

		if ( '' === $message && $this->cancel->needs_message( $reason_id ) ) {
			throw new Action_Input_Exception( [ 'message' => __( 'Опишите причину отмены.', 'woodev-plugin-framework' ) ] );
		}

		try {
			$this->cancel->cancel( $call['account'], $order, $reason_id, $message );
		} catch ( Cancel_Refused $e ) {
			throw new Action_State_Exception( $e->getMessage() );
		} catch ( \Throwable $e ) {
			throw $this->unexpected( 'Cancelling a posting failed', $e );
		}

		return [
			'requested' => 1,
			'accepted'  => 1,
			'skipped'   => 0,
			'failed'    => 0,
			'rows'      => $this->build_rows( $call['account'], $call['targets'] ),
			'messages'  => [ 'action_status_done' => __( 'Отправление отменено. Заказ обновится в течение нескольких минут.', 'woodev-plugin-framework' ) ],
		];
	}

	/**
	 * The WooCommerce order of a cancellation, once the account and the licence allow cancelling.
	 *
	 * @param array{account:Account,targets:array<int,array<string,mixed>>} $call Resolved call.
	 * @return \WC_Order
	 * @throws Action_State_Exception When they do not, or the order cannot be loaded.
	 */
	private function order_to_cancel( array $call ): \WC_Order {
		$refusal = $this->cancel->refusal( $call['account'] );

		if ( null !== $refusal ) {
			throw new Action_State_Exception( $refusal );
		}

		$order = $this->order( $call['targets'][0]['order_id'] );

		if ( null === $order ) {
			throw new Action_State_Exception( __( 'Заказ не найден. Обновите страницу.', 'woodev-plugin-framework' ) );
		}

		return $order;
	}

	/**
	 * The plan of a label request: one posting, which is not asked for twice while its labels are being made.
	 *
	 * @param array{account:Account,targets:array<int,array<string,mixed>>} $call Resolved call.
	 * @return array<string,mixed>
	 */
	private function label_plan( array $call ): array {
		$key = $call['targets'][0]['external_key'];

		if ( $this->labels->states( $call['account']->id(), [ $key ] )[ $key ]['open_task'] > 0 ) {
			return $this->refused_plan( __( 'Этикетки для этого заказа уже готовятся — дождитесь результата.', 'woodev-plugin-framework' ), $key );
		}

		return [
			'requested' => 1,
			'eligible'  => 1,
			'skipped'   => 0,
		];
	}

	/**
	 * Answers with the ready labels, or asks the marketplace for them.
	 *
	 * @param array{account:Account,targets:array<int,array<string,mixed>>} $call Resolved call.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the licence or the marketplace does not allow the request.
	 */
	private function label_apply( array $call ): array {
		$account = $call['account'];
		$key     = $call['targets'][0]['external_key'];
		$state   = $this->labels->states( $account->id(), [ $key ] )[ $key ];

		if ( $state['open_task'] > 0 ) {
			return $this->refused_result( __( 'Этикетки для этого заказа уже готовятся — дождитесь результата.', 'woodev-plugin-framework' ), $key );
		}

		$document = $this->labels->covering( $account->id(), [ $key ] );

		if ( $document > 0 ) {
			return [
				'requested' => 1,
				'accepted'  => 1,
				'skipped'   => 0,
				'failed'    => 0,
				'download'  => [
					'url'      => $this->labels->url( $document ),
					'filename' => '',
				],
			];
		}

		try {
			$this->labels->request( $account, [ $key ] );
		} catch ( Label_Exception $e ) {
			throw new Action_State_Exception( $e->getMessage() );
		} catch ( \Throwable $e ) {
			throw $this->unexpected( 'Asking for labels failed', $e );
		}

		return [
			'requested' => 1,
			'accepted'  => 1,
			'skipped'   => 0,
			'failed'    => 0,
			'queued'    => true,
			'rows'      => $this->build_rows( $account, $call['targets'] ),
		];
	}

	/**
	 * A plan in which the one asked order cannot go, with the reason.
	 *
	 * @param string $reason  What the merchant reads.
	 * @param string $example The posting number.
	 * @return array<string,mixed>
	 */
	private function refused_plan( string $reason, string $example ): array {
		return [
			'requested' => 1,
			'eligible'  => 0,
			'skipped'   => 1,
			'groups'    => $this->refusal_groups( $reason, $example ),
		];
	}

	/**
	 * An apply in which the one asked order was skipped, with the reason.
	 *
	 * @param string $reason  What the merchant reads.
	 * @param string $example The posting number.
	 * @return array<string,mixed>
	 */
	private function refused_result( string $reason, string $example ): array {
		return [
			'requested' => 1,
			'accepted'  => 0,
			'skipped'   => 1,
			'failed'    => 0,
			'groups'    => $this->refusal_groups( $reason, $example ),
		];
	}

	/**
	 * @param string $reason  Reason.
	 * @param string $example Posting number.
	 * @return array<int,array<string,mixed>>
	 */
	private function refusal_groups( string $reason, string $example ): array {
		return [
			[
				'kind'     => 'skipped',
				'reason'   => $reason,
				'count'    => 1,
				'examples' => [ $example ],
			],
		];
	}

	/**
	 * Logs a failure that is not a sentence for the merchant and turns it into one.
	 *
	 * @param string     $what What was being done.
	 * @param \Throwable $e    The failure.
	 * @return Action_State_Exception
	 */
	private function unexpected( string $what, \Throwable $e ): Action_State_Exception {
		if ( null !== $this->log_error ) {
			call_user_func( $this->log_error, sprintf( '%s: %s: %s', $what, get_class( $e ), $e->getMessage() ) );
		}

		return new Action_State_Exception( __( 'Маркетплейс не ответил. Повторите позже.', 'woodev-plugin-framework' ) );
	}

	/**
	 * Builds the rows of listed items, with the label state and the activity of each. An item whose order cannot be loaded (deleted
	 * between the query and now) is left out.
	 *
	 * @param Account                        $account Account.
	 * @param array<int,array<string,mixed>> $items   Items, in display order.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_rows( Account $account, array $items ): array {
		if ( [] === $items ) {
			return [];
		}

		$orders   = $this->orders( array_column( $items, 'order_id' ) );
		$states   = null === $this->labels ? [] : $this->labels->states( $account->id(), array_column( $items, 'external_key' ) );
		$activity = $this->activity->for_items( $items, $states );
		$rows     = [];

		foreach ( $items as $item ) {
			if ( isset( $orders[ $item['order_id'] ] ) ) {
				$rows[] = $this->rows->row( $account->id(), $item, $orders[ $item['order_id'] ], $states[ $item['external_key'] ] ?? Site_Orders_Labels::none(), $activity[ $item['order_id'] ] );
			}
		}

		return $rows;
	}

	/**
	 * The WooCommerce orders of a page, by id: one query on either storage.
	 *
	 * @param int[] $order_ids Order ids.
	 * @return array<int,\WC_Order>
	 */
	private function orders( array $order_ids ): array {
		$found  = wc_get_orders(
			[
				'type'    => 'shop_order',
				'include' => $order_ids,
				'limit'   => count( $order_ids ),
			]
		);
		$orders = [];

		foreach ( is_array( $found ) ? $found : [] as $order ) {
			if ( $order instanceof \WC_Order ) {
				$orders[ (int) $order->get_id() ] = $order;
			}
		}

		return $orders;
	}

	/**
	 * @param int $order_id Order id.
	 * @return \WC_Order|null
	 */
	private function order( int $order_id ): ?\WC_Order {
		$order = wc_get_order( $order_id );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * The order ids named by row ids of the account.
	 *
	 * @param int      $account_id Account.
	 * @param string[] $ids        Row ids.
	 * @return int[]|null Null when any id is malformed or names another account.
	 */
	private function order_ids( int $account_id, array $ids ): ?array {
		$order_ids = [];

		foreach ( $ids as $id ) {
			$order_id = Site_Orders_Ids::order_id( (string) $id, $account_id );

			if ( null === $order_id ) {
				return null;
			}

			$order_ids[] = $order_id;
		}

		return $order_ids;
	}

	/**
	 * Resolves orders for an action: every one must be a registry row of the account that carries its owner stamp and that the user
	 * may edit.
	 *
	 * @param Account $account   Account.
	 * @param int[]   $order_ids Order ids.
	 * @return array<int,array<string,mixed>>|null The items in the order asked; null when any is not available.
	 */
	private function targets( Account $account, array $order_ids ): ?array {
		$items   = $this->reader->find( $account->id(), $order_ids );
		$owner   = $this->meta->account_owner( $account );
		$targets = [];

		foreach ( $order_ids as $order_id ) {
			$allowed = isset( $items[ $order_id ] ) && '' !== $owner && $this->meta->order_owner( $order_id ) === $owner && true === call_user_func( $this->can_edit, $order_id );

			if ( ! $allowed ) {
				return null;
			}

			$targets[] = $items[ $order_id ];
		}

		return $targets;
	}

	/**
	 * The item of one row id, when it passes the action checks.
	 *
	 * @param Account $account Account.
	 * @param string  $row_id  Row id.
	 * @return array<string,mixed>|null
	 */
	private function target_of_row( Account $account, string $row_id ): ?array {
		$order_ids = '' === $row_id ? null : $this->order_ids( $account->id(), [ $row_id ] );
		$targets   = null === $order_ids ? null : $this->targets( $account, $order_ids );

		return $targets[0] ?? null;
	}

	/**
	 * The row actions this provider declares.
	 *
	 * @return string[]
	 */
	private function offered(): array {
		return self::offered_actions( null !== $this->labels, null !== $this->cancel );
	}

	/**
	 * The account selector's options: the enabled accounts of the marketplace.
	 *
	 * @return array<int,array{value:string,label:string}>
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
	 * The WooCommerce order statuses of the filter.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	private function status_options(): array {
		$options = [];

		foreach ( (array) wc_get_order_statuses() as $status => $label ) {
			$options[] = [
				'value' => (string) $status,
				'label' => html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' ),
			];
		}

		return $options;
	}

	/**
	 * The account the table is open for: enabled, and of this marketplace.
	 *
	 * @param array<string,string> $context Resolved context.
	 * @return Account|null
	 */
	private function account( array $context ): ?Account {
		$id      = $this->account_id( $context );
		$account = $id > 0 ? $this->accounts->get( $id ) : null;

		return null !== $account && $account->is_enabled() && $account->adapter_id() === $this->adapter_id ? $account : null;
	}

	/**
	 * @param array<string,string> $context Resolved context.
	 * @return int 0 when the table has no account.
	 */
	private function account_id( array $context ): int {
		return (int) ( $context['account_id'] ?? 0 );
	}
}
