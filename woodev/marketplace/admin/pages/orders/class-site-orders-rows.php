<?php
/**
 * The rows of the «Заказы» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Marketplace\Admin\Shared\Plain_Price;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Names;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Text;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a registry item and its WooCommerce order into a row of the records engine: the cells of
 * {@see Site_Orders_Definition}, the row actions and the activity. The engine escapes and checks every cell, so the cells here are
 * plain values.
 *
 * @since 2.1.5
 */
final class Site_Orders_Rows {
	/**
	 * Order status (without `wc-`) => badge tone, for an order the marketplace has sent no status for. A status of another plugin is
	 * neutral.
	 */
	private const TONES = [
		'pending'    => 'warning',
		'on-hold'    => 'warning',
		'processing' => 'info',
		'completed'  => 'success',
		'cancelled'  => 'danger',
		'failed'     => 'danger',
		'refunded'   => 'neutral',
	];

	/**
	 * The row action ids the table declares.
	 *
	 * @var string[]
	 */
	private $offered;

	/**
	 * Whether the user may edit an order: `function ( int $order_id ): bool`.
	 *
	 * @var callable
	 */
	private $can_edit;

	/**
	 * Whether the marketplace has finished with the posting of an order: `function ( \WC_Order $order ): bool`.
	 *
	 * @var callable
	 */
	private $is_finished;

	/**
	 * The adapter's own names of posting statuses; null when it has none.
	 *
	 * @var Posting_Status_Names|null
	 */
	private $status_names;

	/**
	 * Creates the builder.
	 *
	 * @since 2.1.5
	 *
	 * @param string[]                  $offered     Row action ids the table declares.
	 * @param callable                  $can_edit    `function ( int $order_id ): bool`, the object right to edit an order.
	 * @param callable                  $is_finished `function ( \WC_Order $order ): bool`, whether the posting can no longer be cancelled.
	 * @param Posting_Status_Names|null $status_names The adapter's own names of posting statuses, when it has them.
	 */
	public function __construct( array $offered, callable $can_edit, callable $is_finished, ?Posting_Status_Names $status_names = null ) {
		$this->offered      = $offered;
		$this->can_edit     = $can_edit;
		$this->is_finished  = $is_finished;
		$this->status_names = $status_names;
	}

	/**
	 * The options of the «Статус на маркетплейсе» filter: each stored code under the name its badge shows, so the filter and the
	 * column say the same words, with its count. Two codes the framework names alike («Другой статус» for every code nobody names)
	 * keep the code in brackets, so the merchant can tell the options apart.
	 *
	 * @since 2.1.13
	 *
	 * @param array<string,int> $counts Orders by stored posting status.
	 * @return array<int,array{value:string,label:string,count:int}> The records engine's facet shape.
	 */
	public function posting_status_options( array $counts ): array {
		$labels = [];

		foreach ( array_keys( $counts ) as $code ) {
			$labels[ (string) $code ] = Posting_Status_Text::label( (string) $code, $this->status_names );
		}

		$uses    = array_count_values( $labels );
		$options = [];

		foreach ( $labels as $code => $label ) {
			$options[] = [
				'value' => (string) $code,
				'label' => $uses[ $label ] > 1 ? $label . ' (' . $code . ')' : $label,
				'count' => (int) $counts[ $code ],
			];
		}

		return $options;
	}

	/**
	 * Builds a row.
	 *
	 * @since 2.1.5
	 *
	 * @param int                 $account_id Account.
	 * @param array<string,mixed> $item       The registry item.
	 * @param \WC_Order           $order      Its WooCommerce order.
	 * @param array<string,mixed> $activity   Activity of the row.
	 * @return array<string,mixed>
	 */
	public function row( int $account_id, array $item, \WC_Order $order, array $activity ): array {
		$editable = true === call_user_func( $this->can_edit, $item['order_id'] );
		$url      = $editable ? (string) $order->get_edit_order_url() : '';
		$cells    = $this->cells( $item, $order, $url );

		return [
			'id'           => Site_Orders_Ids::row_id( $account_id, $item['order_id'] ),
			'revision'     => substr( md5( (string) wp_json_encode( [ $cells, $activity['processes'] ] ) ), 0, 16 ),
			'cells'        => $cells,
			'actions'      => $this->actions( $order, $editable, $url ),
			'selectable'   => false,
			'has_children' => false,
			'activity'     => $activity,
		];
	}

	/**
	 * @param array<string,mixed> $item  Item.
	 * @param \WC_Order           $order Order.
	 * @param string              $url   Edit address; empty without the right.
	 * @return array<string,mixed>
	 */
	private function cells( array $item, \WC_Order $order, string $url ): array {
		return [
			'order'    => [ $this->order_number( $order, $url ) ],
			'posting'  => $item['external_key'],
			'status'   => $this->status_items( $order, $item['status_raw'] ),
			'date'     => $this->date( $order ),
			'customer' => $this->customer( $order ),
			'total'    => $this->total( $order ),
			'actions'  => null,
		];
	}

	/**
	 * «№ 123», a link to the order when the user may open it.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $url   Edit address; empty without the right.
	 * @return array{type:string,value:mixed}
	 */
	private function order_number( \WC_Order $order, string $url ): array {
		$label = '№ ' . $order->get_order_number();

		if ( '' === $url ) {
			return [
				'type'  => 'text',
				'value' => $label,
			];
		}

		return [
			'type'  => 'link',
			'value' => [
				'label' => $label,
				'url'   => $url,
			],
		];
	}

	/**
	 * One badge: the marketplace's own status in Russian. An order the marketplace has sent no status for shows the WooCommerce one.
	 *
	 * @param \WC_Order $order      Order.
	 * @param string    $status_raw The marketplace's status.
	 * @return array<int,array{type:string,value:mixed}>
	 */
	private function status_items( \WC_Order $order, string $status_raw ): array {
		if ( '' !== $status_raw ) {
			return [
				[
					'type'  => 'badge',
					'value' => [
						'label' => Posting_Status_Text::label( $status_raw, $this->status_names ),
						'tone'  => Posting_Status_Text::tone( $status_raw ),
					],
				],
			];
		}

		$status = (string) $order->get_status();

		return [
			[
				'type'  => 'badge',
				'value' => [
					'label' => html_entity_decode( (string) wc_get_order_status_name( $status ), ENT_QUOTES, 'UTF-8' ),
					'tone'  => self::TONES[ $status ] ?? 'neutral',
				],
			],
		];
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return string|null The buyer's name; null when the order has none.
	 */
	private function customer( \WC_Order $order ): ?string {
		$name = trim( (string) $order->get_formatted_billing_full_name() );

		return '' === $name ? null : $name;
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return string|null ISO 8601 time in UTC; null for an order with no date.
	 */
	private function date( \WC_Order $order ): ?string {
		$created = $order->get_date_created();

		return null === $created ? null : gmdate( 'Y-m-d\TH:i:s\Z', $created->getTimestamp() );
	}

	/**
	 * The order total as the shop writes a price, as plain text.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function total( \WC_Order $order ): string {
		return Plain_Price::format( $order->get_total(), (string) $order->get_currency() );
	}

	/**
	 * The actions of a row, each with a reason when it is off. The cancel action is left out of a finished posting's row.
	 *
	 * @param \WC_Order $order    Order.
	 * @param bool      $editable Whether the user may edit the order.
	 * @param string    $url      Edit address; empty without the right.
	 * @return array<int,array<string,mixed>>
	 */
	private function actions( \WC_Order $order, bool $editable, string $url ): array {
		$actions = [];

		foreach ( $this->offered as $id ) {
			// A finished posting cannot be cancelled: the row does not offer the action at all.
			if ( Site_Orders_Definition::CANCEL === $id && $editable && true === call_user_func( $this->is_finished, $order ) ) {
				continue;
			}

			$actions[] = $this->action( $id, $order, $editable, $url );
		}

		return $actions;
	}

	/**
	 * @param string    $id       Action id.
	 * @param \WC_Order $order    Order.
	 * @param bool      $editable Whether the user may edit the order.
	 * @param string    $url      Edit address.
	 * @return array<string,mixed>
	 */
	private function action( string $id, \WC_Order $order, bool $editable, string $url ): array {
		if ( Site_Orders_Definition::OPEN === $id ) {
			return [
				'id'      => $id,
				'enabled' => '' !== $url,
				'reason'  => '' !== $url ? '' : __( 'Нет права открывать этот заказ.', 'woodev-plugin-framework' ),
			] + ( '' === $url ? [] : [ 'url' => $url ] );
		}

		if ( ! $editable ) {
			return [
				'id'      => $id,
				'enabled' => false,
				'reason'  => __( 'Нет права изменять этот заказ.', 'woodev-plugin-framework' ),
			];
		}

		return [
			'id'      => $id,
			'enabled' => true,
			'reason'  => '',
		];
	}
}
