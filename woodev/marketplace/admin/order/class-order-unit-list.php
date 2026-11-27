<?php
/**
 * The goods of a grouped marketplace order on its order screen.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Admin\Shared\Order_Unit_Text;
use Woodev\Framework\Marketplace\Flows\Order_Unit;
use Woodev\Framework\Marketplace\Flows\Order_Units;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every unit of a grouped order, live or cancelled, in the details box ({@see Order_Details_Box}): the goods, the article,
 * the status and the amount, and — for an adapter that declares actions on a unit — the buttons of {@see Seller_Action_Buttons}
 * beside the unit whose id the adapter's {@see Posting_Unit} carries. A cancelled unit has no line in the order, so this row is
 * where the merchant sees it.
 *
 * @since 2.1.28
 */
final class Order_Unit_List {
	/**
	 * @var Order_Unit_Text
	 */
	private $text;

	/**
	 * Returns the account of an order's posting: `function ( int $order_id ): int`, 0 for an order with no posting.
	 *
	 * @var callable
	 */
	private $account_of_order;

	/**
	 * @var Seller_Action_Buttons|null
	 */
	private $seller;

	/**
	 * @since 2.1.28
	 *
	 * @param Order_Unit_Text            $text             How a unit reads.
	 * @param callable                   $account_of_order `function ( int $order_id ): int`; 0 for an order with no posting.
	 * @param Seller_Action_Buttons|null $seller           The seller action buttons, when the adapter declares actions.
	 */
	public function __construct( Order_Unit_Text $text, callable $account_of_order, ?Seller_Action_Buttons $seller = null ) {
		$this->text             = $text;
		$this->account_of_order = $account_of_order;
		$this->seller           = $seller;
	}

	/**
	 * Returns the list.
	 *
	 * @since 2.1.28
	 *
	 * @param \WC_Order $order Order.
	 * @return string HTML, escaped; empty for an order that keeps no units.
	 */
	public function render( \WC_Order $order ): string {
		$units = Order_Units::of( $order );

		if ( [] === $units ) {
			return '';
		}

		$account_id = (int) call_user_func( $this->account_of_order, $order->get_id() );
		$offered    = $this->offered( $order );
		$actions    = [] !== $offered;
		$rows       = '';

		foreach ( $units as $unit ) {
			$rows .= $this->row( $account_id, $unit, $actions ? ( $offered[ '#' . $unit->id() ]['buttons'] ?? '' ) : null );
			unset( $offered[ '#' . $unit->id() ] );
		}

		// A unit the marketplace lists and the order does not keep still gets its buttons.
		foreach ( $offered as $extra ) {
			$rows .= sprintf(
				'<tr><td>%1$s</td><td></td><td>%2$s</td><td></td><td class="woodev-mp-units__actions">%3$s</td></tr>',
				esc_html( $extra['unit']->label() ),
				esc_html( $extra['unit']->status_label() ),
				$extra['buttons']
			);
		}

		$headings = [
			__( 'Товар', 'woodev-plugin-framework' ),
			__( 'Артикул', 'woodev-plugin-framework' ),
			__( 'Статус', 'woodev-plugin-framework' ),
			__( 'Сумма', 'woodev-plugin-framework' ),
		];

		if ( $actions ) {
			$headings[] = __( 'Действия', 'woodev-plugin-framework' );
			$this->seller->enqueue_style();
		}

		$head = '';

		foreach ( $headings as $heading ) {
			$head .= '<th>' . esc_html( $heading ) . '</th>';
		}

		return '<table class="widefat striped woodev-mp-units"><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table>';
	}

	/**
	 * The buttons the marketplace offers, by unit.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,array{unit:Posting_Unit,buttons:string}> Keyed by `#` and the unit id.
	 */
	private function offered( \WC_Order $order ): array {
		$offered = [];

		foreach ( null === $this->seller ? [] : $this->seller->unit_buttons( $order ) as $row ) {
			$offered[ '#' . $row['unit']->id() ] = $row;
		}

		return $offered;
	}

	/**
	 * @param int         $account_id Account.
	 * @param Order_Unit  $unit       Unit.
	 * @param string|null $buttons    Escaped HTML of the unit's buttons; null when the list has no actions column.
	 * @return string HTML, escaped.
	 */
	private function row( int $account_id, Order_Unit $unit, ?string $buttons ): string {
		$badge  = $this->text->status( $unit );
		$detail = $this->text->cancellation( $unit );
		$status = sprintf( '<span class="woodev-mp-badge is-%1$s">%2$s</span>', esc_attr( $badge['tone'] ), esc_html( $badge['label'] ) );

		if ( '' !== $detail ) {
			$status .= ' <span class="description">' . esc_html( $detail ) . '</span>';
		}

		return sprintf(
			'<tr%1$s><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td>%6$s</tr>',
			$unit->is_cancelled() ? ' class="woodev-mp-units__cancelled"' : '',
			esc_html( $this->text->name( $unit ) ),
			esc_html( $this->text->article( $account_id, $unit ) ),
			$status,
			esc_html( $this->text->amount( $unit ) ),
			null === $buttons ? '' : '<td class="woodev-mp-units__actions">' . $buttons . '</td>'
		);
	}
}
