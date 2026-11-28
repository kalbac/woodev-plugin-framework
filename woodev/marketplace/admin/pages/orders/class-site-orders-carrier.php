<?php
/**
 * The delivery service of a seller-delivered order, on the «Заказы» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Marketplace\Admin\Shared\Carrier_State;

defined( 'ABSPATH' ) || exit;

/**
 * Adds to the table the link that opens the delivery wizard — «Оформить доставку», or «Изменить доставку» once a service is
 * chosen — and, under a row's status, a badge with what the merchant still has to do after the service's news ({@see Carrier_State}). The
 * link is declared only where a delivery can be arranged at all.
 *
 * @since 2.1.29
 */
final class Site_Orders_Carrier {
	/**
	 * Row action: arrange the delivery of the order.
	 */
	public const ARRANGE = 'delivery';

	/**
	 * Row action: change the delivery already chosen.
	 */
	public const CHANGE = 'delivery-change';

	/**
	 * @var Carrier_State
	 */
	private $state;

	/**
	 * @since 2.1.29
	 *
	 * @param Carrier_State $state What there is to say about an order.
	 */
	public function __construct( Carrier_State $state ) {
		$this->state = $state;
	}

	/**
	 * Adds the two links to the table's definition, after «Открыть».
	 *
	 * @since 2.1.29
	 *
	 * @param array<string,mixed> $definition The table's definition.
	 * @return array<string,mixed>
	 */
	public function extend_definition( array $definition ): array {
		if ( ! $this->state->delivery_available() ) {
			return $definition;
		}

		$links = [];

		foreach ( [
			self::ARRANGE => __( 'Оформить доставку', 'woodev-plugin-framework' ),
			self::CHANGE  => __( 'Изменить доставку', 'woodev-plugin-framework' ),
		] as $id => $label ) {
			$links[] = [
				'id'          => $id,
				'label'       => $label,
				'short_label' => __( 'Доставка', 'woodev-plugin-framework' ),
				'icon'        => 'car',
				'effect'      => 'read',
				'interaction' => 'link',
			];
		}

		$at = array_search( Site_Orders_Definition::OPEN, array_column( $definition['row_actions'], 'id' ), true );

		array_splice( $definition['row_actions'], false === $at ? 0 : $at + 1, 0, $links );

		return $definition;
	}

	/**
	 * Puts the link of an order among its row actions.
	 *
	 * @since 2.1.29
	 *
	 * @param array<int,array<string,mixed>> $actions The row's actions.
	 * @param \WC_Order                      $order   Order.
	 * @return array<int,array<string,mixed>>
	 */
	public function with_link( array $actions, \WC_Order $order ): array {
		$button = $this->state->button( $order );

		if ( null === $button ) {
			return $actions;
		}

		// The table draws a link only when it has an address, a switched-off one too (it is never followed): without one the row
		// would lose the button together with the reason it is off.
		$link = [
			'id'      => $button['changes'] ? self::CHANGE : self::ARRANGE,
			'enabled' => '' !== $button['url'],
			'reason'  => $button['reason'],
			'url'     => '' !== $button['url'] ? $button['url'] : (string) $order->get_edit_order_url(),
		];

		$at = array_search( Site_Orders_Definition::OPEN, array_column( $actions, 'id' ), true );

		array_splice( $actions, false === $at ? 0 : $at + 1, 0, [ $link ] );

		return $actions;
	}

	/**
	 * Puts under a row's status a badge that says the merchant still has something to do, with the sentence in its tooltip: a
	 * sentence in the cell would widen the table past its card.
	 *
	 * @since 2.1.29
	 *
	 * @param array<int,array{type:string,value:mixed}> $status The items of the status cell.
	 * @param \WC_Order                                 $order  Order.
	 * @return array<int,array{type:string,value:mixed}>
	 */
	public function with_notice( array $status, \WC_Order $order ): array {
		$notice = $this->state->notice( $order );

		if ( '' === $notice ) {
			return $status;
		}

		$status[] = [
			'type'  => 'badge',
			'value' => [
				'label'   => __( 'Проверьте', 'woodev-plugin-framework' ),
				'tone'    => 'warning',
				'tooltip' => $notice,
			],
		];

		return $status;
	}
}
