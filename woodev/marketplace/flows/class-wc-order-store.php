<?php
/**
 * WooCommerce side of the order writer.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Line;
use Woodev\Framework\Marketplace\Orders\Order_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and updates WooCommerce orders for the writer, on both order stores (HPOS-safe meta API only, no custom data store,
 * spec 3.2). Lookup goes through {@see Order_Query}, the module's only order query by meta.
 *
 * Stock is WooCommerce's own business: a new order is saved `pending` (no stock effect) and takes stock when the writer gives
 * it its real status; a line set that changes on a stock-reduced order first gives back what the old lines took
 * (`wc_increase_stock_levels()`), then the new lines are reduced, so the order never holds stock twice. Both paths honour
 * WooCommerce's `woocommerce_can_reduce_order_stock` / `woocommerce_can_restore_order_stock` filters.
 *
 * Everything the marketplace decides — the meta that identifies its order, the product of a line, what an order is created
 * via — is the {@see Order_Meta_Contract} the adapter supplies.
 *
 * @since 2.1.0
 */
final class Wc_Order_Store implements Order_Store {
	/**
	 * Order meta of the common grouped-order contract (spec 3.2).
	 */
	public const META_GROUP_MEMBERS = '_woodev_mp_group_members';

	/**
	 * Order meta of an order created but not registered yet.
	 */
	public const META_PROVISIONAL = '_woodev_mp_provisional';

	/**
	 * Order meta set while a line swap has given the order's stock back and its new lines have not taken it yet. It carries the
	 * order's stock-holding state across the swap whatever its status (a custom status included), and across a process death.
	 */
	public const META_STOCK_PENDING = '_woodev_mp_stock_pending';

	/**
	 * Status of an order before its registration won: WooCommerce takes no stock in it.
	 */
	private const INERT_STATUS = 'pending';

	/**
	 * Statuses on which WooCommerce reduces an order's stock (`woocommerce_order_status_{processing,completed,on-hold}`).
	 *
	 * @var string[]
	 */
	private const STOCK_HOLDING_STATUSES = [ 'processing', 'completed', 'on-hold' ];

	/**
	 * Statuses on which WooCommerce gives an order's stock back (`woocommerce_order_status_{cancelled,pending,failed}`).
	 *
	 * @var string[]
	 */
	private const STOCK_RELEASING_STATUSES = [ 'cancelled', 'pending', 'failed' ];

	/**
	 * The marketplace's meta contract.
	 *
	 * @var Order_Meta_Contract
	 */
	private $contract;

	/**
	 * Creates the store.
	 *
	 * @since 2.1.0
	 *
	 * @param Order_Meta_Contract $contract The marketplace's meta contract.
	 */
	public function __construct( Order_Meta_Contract $contract ) {
		$this->contract = $contract;
	}

	/**
	 * Finds the live order of a group by its identity meta.
	 *
	 * @since 2.1.0
	 *
	 * @param Order_DTO $order Whole order.
	 * @return \WC_Order|null
	 */
	public function find( Order_DTO $order ): ?\WC_Order {
		$found = Order_Query::by_meta( $this->contract->identity( $order ), 1 );

		return [] === $found ? null : $found[0];
	}

	/**
	 * Loads an order that is not trashed.
	 *
	 * @since 2.1.0
	 *
	 * @param int $order_id Order id.
	 * @return \WC_Order|null
	 */
	public function get( int $order_id ): ?\WC_Order {
		$order = wc_get_order( $order_id );

		return $order instanceof \WC_Order && 'trash' !== $order->get_status() ? $order : null;
	}

	/**
	 * Saves a new order that takes no stock yet.
	 *
	 * @since 2.1.0
	 *
	 * @param Order_DTO $order Whole order.
	 * @return \WC_Order
	 */
	public function create( Order_DTO $order ): \WC_Order {
		$wc_order = new \WC_Order();
		$wc_order->set_created_via( $this->contract->created_via() );
		$wc_order->set_status( self::INERT_STATUS );

		$lines = $order->lines();
		if ( [] !== $lines ) {
			$wc_order->set_currency( $lines[0]->unit_price()->currency() );
		}

		$created_at = $order->dates()['created_at'] ?? '';
		if ( '' !== $created_at ) {
			$wc_order->set_date_created( $created_at );
		}

		$this->contract->write_meta( $wc_order, $order, true );
		$wc_order->update_meta_data( self::META_PROVISIONAL, '1' );
		$unlinked = $this->attach( $wc_order, $this->build_items( $order ) );
		$wc_order->calculate_totals( false );
		$wc_order->save();

		// A note needs a saved order.
		$this->note_unlinked( $wc_order, $unlinked );

		return $wc_order;
	}

	/**
	 * Tells whether the order still waits for its first real status.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Order $wc_order The order.
	 * @return bool
	 */
	public function is_provisional( \WC_Order $wc_order ): bool {
		return '1' === (string) $wc_order->get_meta( self::META_PROVISIONAL );
	}

	/**
	 * Brings the order in line with the DTO and saves it.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Order                                                 $wc_order  The order.
	 * @param Order_DTO                                                 $order     Whole order.
	 * @param string                                                    $wc_status WooCommerce status, without `wc-`.
	 * @param array<string,array{status_canonical:string,quantity:int}> $members  Member registry.
	 * @param bool                                                      $created   True for the first real save.
	 * @return void
	 */
	public function update( \WC_Order $wc_order, Order_DTO $order, string $wc_status, array $members, bool $created ): void {
		$this->set_buyer( $wc_order, $order );
		$this->contract->write_meta( $wc_order, $order, $created );
		$wc_order->update_meta_data( self::META_GROUP_MEMBERS, (string) wp_json_encode( $members ) );
		$wc_order->delete_meta_data( self::META_PROVISIONAL );

		$pairs = $this->matched_items( $wc_order, $order );

		if ( null === $pairs ) {
			$this->replace_lines( $wc_order, $order );
		} else {
			$this->refresh_item_meta( $order, $pairs );
		}

		if ( $wc_order->get_status() !== $wc_status ) {
			$wc_order->set_status( $wc_status );
		}

		$wc_order->save();
		$this->take_stock_if_due( $wc_order );

		$this->contract->after_save( $wc_order, $order, $created );
	}

	/**
	 * Moves an order that lost its registration to the trash.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Order $wc_order The order.
	 * @return void
	 */
	public function discard( \WC_Order $wc_order ): void {
		$wc_order->delete( false );
	}

	/**
	 * Pairs each DTO line with the existing item that already is it (marketplace key, quantity and unit price).
	 *
	 * @param \WC_Order $wc_order The order.
	 * @param Order_DTO $order    Whole order.
	 * @return array<int,array{0:\WC_Order_Item_Product,1:Order_Line}>|null Null when the line sets differ.
	 */
	private function matched_items( \WC_Order $wc_order, Order_DTO $order ): ?array {
		$items = [];
		foreach ( $wc_order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$items[] = $item;
			}
		}

		if ( count( $items ) !== count( $order->lines() ) ) {
			return null;
		}

		$pairs = [];
		foreach ( $order->lines() as $line ) {
			$match = $this->matching_item( $items, $line );

			if ( null === $match ) {
				return null;
			}

			$pairs[] = [ $items[ $match ], $line ];
			unset( $items[ $match ] );
		}

		return $pairs;
	}

	/**
	 * Rewrites the marketplace's item meta of lines that stay (commission figures change without the line changing).
	 *
	 * @param Order_DTO                                               $order Whole order.
	 * @param array<int,array{0:\WC_Order_Item_Product,1:Order_Line}> $pairs Items and their lines.
	 * @return void
	 */
	private function refresh_item_meta( Order_DTO $order, array $pairs ): void {
		foreach ( $pairs as $pair ) {
			$this->contract->write_line_meta( $pair[0], $order, $pair[1] );
			$pair[0]->save();
		}
	}

	/**
	 * Finds the first unused item that is the line.
	 *
	 * @param array<int,\WC_Order_Item_Product> $items Items not matched yet.
	 * @param Order_Line                        $line  Line.
	 * @return int|null Index in `$items`.
	 */
	private function matching_item( array $items, Order_Line $line ): ?int {
		foreach ( $items as $index => $item ) {
			if ( $this->contract->matches( $item, $line ) ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Swaps the line set for the DTO's: the new items are built in full first, then the stock the old lines took is given back
	 * and the order marked as holding none, then the lines are swapped. The new lines take their stock after the save, in
	 * {@see self::take_stock_if_due()}, so a process that dies anywhere in between leaves a state the next refresh completes.
	 *
	 * @param \WC_Order $wc_order The order.
	 * @param Order_DTO $order    Whole order.
	 * @return void
	 */
	private function replace_lines( \WC_Order $wc_order, Order_DTO $order ): void {
		$items = $this->build_items( $order );

		if ( $wc_order->get_id() > 0 && (bool) $wc_order->get_data_store()->get_stock_reduced( $wc_order->get_id() ) ) {
			// Stored before the stock is given back, so a death after this point still knows the order held stock.
			$wc_order->update_meta_data( self::META_STOCK_PENDING, '1' );
			$wc_order->save_meta_data();

			wc_increase_stock_levels( $wc_order );
			$wc_order->get_data_store()->set_stock_reduced( $wc_order, false );
		}

		$wc_order->remove_order_items( 'line_item' );
		$this->note_unlinked( $wc_order, $this->attach( $wc_order, $items ) );
		$wc_order->calculate_totals( false );
	}

	/**
	 * Takes the order's stock when WooCommerce has not taken it yet and the order holds stock: its status is one WooCommerce reduces
	 * on, or a line swap gave its stock back (the marker of {@see self::replace_lines()}, which covers a custom status too) and its
	 * status does not release stock. This also completes a refresh that died between saving new lines and reducing them.
	 * WooCommerce's own `wc_maybe_reduce_stock_levels()` does the work: it reduces only items without a `_reduced_stock` record,
	 * honours the reduce filters and marks the order.
	 *
	 * @param \WC_Order $wc_order The order, saved.
	 * @return void
	 */
	private function take_stock_if_due( \WC_Order $wc_order ): void {
		$swapped = '1' === (string) $wc_order->get_meta( self::META_STOCK_PENDING );

		if ( $wc_order->has_status( self::STOCK_HOLDING_STATUSES ) || ( $swapped && ! $wc_order->has_status( self::STOCK_RELEASING_STATUSES ) ) ) {
			wc_maybe_reduce_stock_levels( $wc_order->get_id() );
		}

		if ( $swapped ) {
			$wc_order->delete_meta_data( self::META_STOCK_PENDING );
			$wc_order->save_meta_data();
		}
	}

	/**
	 * Adds built items.
	 *
	 * @param \WC_Order                                                   $wc_order The order.
	 * @param array<int,array{item:\WC_Order_Item_Product,unlinked:bool}> $items    Built items.
	 * @return string[] Names of the lines no shop product was found for.
	 */
	private function attach( \WC_Order $wc_order, array $items ): array {
		$unlinked = [];

		foreach ( $items as $built ) {
			$wc_order->add_item( $built['item'] );

			if ( $built['unlinked'] ) {
				$unlinked[] = $built['item']->get_name();
			}
		}

		return $unlinked;
	}

	/**
	 * Notes each line no shop product was found for.
	 *
	 * @param \WC_Order $wc_order The order, saved.
	 * @param string[]  $names    Line names.
	 * @return void
	 */
	private function note_unlinked( \WC_Order $wc_order, array $names ): void {
		foreach ( $names as $name ) {
			$wc_order->add_order_note(
				sprintf(
					/* translators: %s: product name from the marketplace. */
					__( 'Товар не связан: «%s». Свяжите его с товаром магазина, чтобы остатки учитывались.', 'woodev-plugin-framework' ),
					$name
				)
			);
		}
	}

	/**
	 * Builds one item per line: a product line for a linked product, else a line with the marketplace's name.
	 *
	 * @param Order_DTO $order Whole order.
	 * @return array<int,array{item:\WC_Order_Item_Product,unlinked:bool}>
	 */
	private function build_items( Order_DTO $order ): array {
		$items = [];

		foreach ( $order->lines() as $line ) {
			$items[] = $this->build_item( $order, $line );
		}

		return $items;
	}

	/**
	 * Builds one item.
	 *
	 * @param Order_DTO  $order Whole order.
	 * @param Order_Line $line  Line.
	 * @return array{item:\WC_Order_Item_Product,unlinked:bool}
	 */
	private function build_item( Order_DTO $order, Order_Line $line ): array {
		$item    = new \WC_Order_Item_Product();
		$product = $this->contract->product_for( $order, $line );

		if ( null !== $product ) {
			$item->set_product( $product );
		} else {
			$item->set_name( '' !== $line->name() ? $line->name() : $line->seller_sku() );
		}

		$total = $line->line_total()->to_string();
		$item->set_quantity( $line->quantity() );
		$item->set_subtotal( $total );
		$item->set_total( $total );

		$this->contract->write_line_meta( $item, $order, $line );

		return [
			'item'     => $item,
			'unlinked' => null === $product,
		];
	}

	/**
	 * Copies the buyer fields the marketplace shows: `name`, `phone`, `city`, `region`.
	 *
	 * @param \WC_Order $wc_order The order.
	 * @param Order_DTO $order    Whole order.
	 * @return void
	 */
	private function set_buyer( \WC_Order $wc_order, Order_DTO $order ): void {
		$buyer = $order->buyer() ?? [];

		$name = sanitize_text_field( (string) ( $buyer['name'] ?? '' ) );
		if ( '' !== $name ) {
			$wc_order->set_billing_first_name( $name );
			$wc_order->set_shipping_first_name( $name );
		}

		$phone = wc_sanitize_phone_number( (string) ( $buyer['phone'] ?? '' ) );
		if ( '' !== $phone ) {
			$wc_order->set_billing_phone( $phone );
			$wc_order->set_shipping_phone( $phone );
		}

		$city = sanitize_text_field( (string) ( $buyer['city'] ?? '' ) );
		if ( '' !== $city ) {
			$wc_order->set_billing_city( $city );
			$wc_order->set_shipping_city( $city );
		}

		$region = sanitize_text_field( (string) ( $buyer['region'] ?? '' ) );
		if ( '' !== $region ) {
			$wc_order->set_billing_state( $region );
			$wc_order->set_shipping_state( $region );
		}
	}
}
