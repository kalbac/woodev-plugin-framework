<?php
/**
 * The unit rows of the «Заказы» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Shared\Order_Unit_Text;
use Woodev\Framework\Marketplace\Flows\Order_Unit;
use Woodev\Framework\Marketplace\Flows\Order_Units;

defined( 'ABSPATH' ) || exit;

/**
 * The child rows of a grouped order: one per unit the order keeps ({@see Order_Units}), live or cancelled, with its goods,
 * article, status, price and the actions offered on that unit. The same columns as an order's row, read from the stored order and
 * nothing else.
 *
 * @since 2.1.28
 */
final class Site_Orders_Units {
	/**
	 * Longest text a cell of the table takes.
	 */
	private const TEXT_LENGTH = 500;

	/**
	 * @var Order_Unit_Text
	 */
	private $text;

	/**
	 * Null when the adapter declares no seller actions.
	 *
	 * @var Site_Orders_Seller|null
	 */
	private $seller;

	/**
	 * @since 2.1.28
	 *
	 * @param Order_Unit_Text         $text   How a unit reads.
	 * @param Site_Orders_Seller|null $seller The adapter's seller actions.
	 */
	public function __construct( Order_Unit_Text $text, ?Site_Orders_Seller $seller = null ) {
		$this->text   = $text;
		$this->seller = $seller;
	}

	/**
	 * Tells whether an order can be opened into units.
	 *
	 * @since 2.1.28
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function has_units( \WC_Order $order ): bool {
		return [] !== Order_Units::of( $order );
	}

	/**
	 * Returns the unit a row id's digest names.
	 *
	 * @since 2.1.28
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $digest {@see Site_Orders_Ids::unit_digest()}.
	 * @return Order_Unit|null
	 */
	public function find( \WC_Order $order, string $digest ): ?Order_Unit {
		foreach ( Order_Units::of( $order ) as $unit ) {
			if ( Site_Orders_Ids::unit_digest( $unit->id() ) === $digest ) {
				return $unit;
			}
		}

		return null;
	}

	/**
	 * Builds the rows of units of an order.
	 *
	 * @since 2.1.28
	 *
	 * @param Account      $account  Account.
	 * @param \WC_Order    $order    Order.
	 * @param Order_Unit[] $units    Units of the order.
	 * @param bool         $editable Whether the user may edit the order.
	 * @return array<int,array<string,mixed>>
	 */
	public function rows( Account $account, \WC_Order $order, array $units, bool $editable ): array {
		$actions = null === $this->seller ? [] : $this->seller->unit_states( $account, $order, $editable );
		$rows    = [];

		foreach ( $units as $unit ) {
			$cells = $this->cells( $account, $unit );
			$own   = $actions[ '#' . $unit->id() ] ?? [];

			$rows[] = [
				'id'           => Site_Orders_Ids::unit_row_id( $account->id(), $order->get_id(), $unit->id() ),
				'revision'     => substr( md5( (string) wp_json_encode( [ $cells, $own ] ) ), 0, 16 ),
				'cells'        => $cells,
				'actions'      => $own,
				'selectable'   => false,
				'has_children' => false,
			];
		}

		return $rows;
	}

	/**
	 * @param Account    $account Account.
	 * @param Order_Unit $unit    Unit.
	 * @return array<string,mixed>
	 */
	private function cells( Account $account, Order_Unit $unit ): array {
		$article = $this->text->article( $account->id(), $unit );
		$amount  = $this->text->amount( $unit );
		$goods   = [ self::text_item( $this->text->name( $unit ) ) ];

		if ( '' !== $article ) {
			/* translators: %s: the seller article, with the size beside it. */
			$goods[] = self::text_item( sprintf( __( 'Артикул: %s', 'woodev-plugin-framework' ), $article ) );
		}

		$status = [
			[
				'type'  => 'badge',
				'value' => $this->text->status( $unit ),
			],
		];
		$detail = $this->text->cancellation( $unit );

		if ( '' !== $detail ) {
			$status[] = self::text_item( $detail );
		}

		return [
			'order'    => $goods,
			'posting'  => null,
			'status'   => $status,
			'date'     => $unit->is_cancelled() ? $unit->cancelled_at() : null,
			'customer' => null,
			'total'    => '' === $amount ? null : $amount,
			'actions'  => null,
		];
	}

	/**
	 * @param string $text Text.
	 * @return array{type:string,value:string}
	 */
	private static function text_item( string $text ): array {
		return [
			'type'  => 'text',
			'value' => mb_substr( $text, 0, self::TEXT_LENGTH ), // The table takes no longer one.
		];
	}
}
