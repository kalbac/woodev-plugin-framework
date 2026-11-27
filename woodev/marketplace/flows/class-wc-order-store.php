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
use Woodev\Framework\Marketplace\Sync\Stock_Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and updates WooCommerce orders for the writer, on both order stores (HPOS-safe meta API only, no custom data store,
 * spec 3.2). Lookup goes through {@see Order_Query}, the module's only order query by meta.
 *
 * Stock is WooCommerce's own business: a new order is saved `pending` (no stock effect) and takes stock when the writer gives
 * it its real status. When the line set changes, only the lines that differ are touched: a line that stays keeps its item with
 * its taxes and its stock; an item that leaves first gives back what it took — the stock write and the item's record of it in
 * one transaction, so an interrupted refresh gives it back once — and then the new lines are reduced, so the order never holds
 * stock twice. Both paths honour WooCommerce's `woocommerce_can_reduce_order_stock` / `woocommerce_can_restore_order_stock`
 * filters.
 *
 * A grouped order (one remote order per unit) has one item per live unit, tied to it by {@see self::ITEM_UNIT}; a cancelled
 * unit's item leaves the order that way, the unit stays with the order as a record ({@see Order_Units}) and is announced
 * ({@see self::units_cancelled_hook()}).
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
	 * Item meta of a line of a grouped order: the unit (member) it is.
	 */
	public const ITEM_UNIT = '_woodev_mp_unit';

	/**
	 * WooCommerce's item meta: the quantity the item took from stock.
	 */
	private const ITEM_REDUCED_STOCK = '_reduced_stock';

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
	 * Transaction around giving back an item's stock; the database's own when null.
	 *
	 * @var Stock_Transaction|null
	 */
	private $transaction;

	/**
	 * Creates the store.
	 *
	 * @since 2.1.0
	 * @since 2.1.28 Takes the stock transaction.
	 *
	 * @param Order_Meta_Contract    $contract    The marketplace's meta contract.
	 * @param Stock_Transaction|null $transaction Transaction around giving back an item's stock; one over `$wpdb` when null.
	 */
	public function __construct( Order_Meta_Contract $contract, ?Stock_Transaction $transaction = null ) {
		$this->contract    = $contract;
		$this->transaction = $transaction;
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
	 * Saves a new order that takes no stock yet. An order the marketplace created before the account's history cutoff
	 * is history, and WooCommerce mails nothing for it ({@see History_Import}).
	 *
	 * @since 2.1.0
	 *
	 * @param Order_DTO $order Whole order.
	 * @return \WC_Order
	 */
	public function create( Order_DTO $order ): \WC_Order {
		return History_Import::quietly(
			$order,
			true,
			function () use ( $order ): \WC_Order {
				return $this->save_new( $order );
			}
		);
	}

	/**
	 * Builds and saves the new order.
	 *
	 * @param Order_DTO $order Whole order.
	 * @return \WC_Order
	 */
	private function save_new( Order_DTO $order ): \WC_Order {
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
		$unlinked = $this->attach( $wc_order, $this->build_items( $order, $order->lines() ) );
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
	 * Brings the order in line with the DTO and saves it. The first save of a history order — the one that gives it its first real
	 * status — mails nothing either ({@see History_Import}); a later change of that order mails as usual.
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
		History_Import::quietly(
			$order,
			$created,
			function () use ( $wc_order, $order, $wc_status, $members, $created ): void {
				$this->save_changes( $wc_order, $order, $wc_status, $members, $created );
			}
		);
	}

	/**
	 * Writes the DTO into the order, gives it its status and saves it.
	 *
	 * @param \WC_Order                                                 $wc_order  The order.
	 * @param Order_DTO                                                 $order     Whole order.
	 * @param string                                                    $wc_status WooCommerce status, without `wc-`.
	 * @param array<string,array{status_canonical:string,quantity:int}> $members  Member registry.
	 * @param bool                                                      $created   True for the first real save.
	 * @return void
	 */
	private function save_changes( \WC_Order $wc_order, Order_DTO $order, string $wc_status, array $members, bool $created ): void {
		$this->set_buyer( $wc_order, $order );
		$this->contract->write_meta( $wc_order, $order, $created );
		$wc_order->update_meta_data( self::META_GROUP_MEMBERS, (string) wp_json_encode( $members ) );
		$wc_order->delete_meta_data( self::META_PROVISIONAL );

		list( $kept, $leaving, $arriving ) = $this->compare_lines( $wc_order, $order );

		$this->refresh_item_meta( $order, $kept );

		if ( [] !== $leaving || [] !== $arriving ) {
			$this->change_lines( $wc_order, $order, $leaving, $arriving );
		}

		if ( $wc_order->get_status() !== $wc_status ) {
			$wc_order->set_status( $wc_status );
		}

		$wc_order->save();
		$this->take_stock_if_due( $wc_order );
		$this->record_units( $wc_order, $order );

		$this->contract->after_save( $wc_order, $order, $created );
	}

	/**
	 * Returns the action a unit's cancellation fires: `do_action( $hook, int $account_id, string[] $offer_ids, \WC_Order $order )`,
	 * once per refresh that finds units newly cancelled (a unit first seen already cancelled included), after the order's stock
	 * was settled. The marketplace's stock sender listens: the marketplace may not have given such a unit back, so its pair is
	 * sent again although the number may not have changed ({@see \Woodev\Framework\Marketplace\Sync\Stock_Intents}).
	 *
	 * @since 2.1.28
	 *
	 * @param string $created_via The marketplace's {@see Order_Meta_Contract::created_via()}.
	 * @return string
	 */
	public static function units_cancelled_hook( string $created_via ): string {
		return 'woodev_marketplace_' . $created_via . '_units_cancelled';
	}

	/**
	 * Keeps the unit records of a grouped order and announces the units that turned cancelled. The records are stored after the
	 * announcement, so a death in between is repaired by the next refresh, which announces them again.
	 *
	 * @param \WC_Order $wc_order The order, saved.
	 * @param Order_DTO $order    Whole order.
	 * @return void
	 */
	private function record_units( \WC_Order $wc_order, Order_DTO $order ): void {
		if ( [] === $order->units() ) {
			return;
		}

		$before = Order_Units::of( $wc_order );
		$after  = Order_Units::refreshed(
			$before,
			$order,
			function ( Order_Line $line ) use ( $order ): int {
				$product = $this->contract->product_for( $order, $line );

				return null === $product ? 0 : $product->get_id();
			}
		);

		$cancelled = Order_Units::newly_cancelled( $before, $after );

		if ( [] !== $cancelled ) {
			do_action( // phpcs:ignore WordPress.NamingConventions.ValidHookName.NotLowercase -- Built from the marketplace's id.
				self::units_cancelled_hook( $this->contract->created_via() ),
				$order->account_id(),
				self::offer_ids( $cancelled ),
				$wc_order
			);
		}

		$records = array_map(
			static function ( Order_Unit $unit ): array {
				return $unit->to_array();
			},
			$after
		);

		if ( $wc_order->get_meta( Order_Units::META ) !== $records ) {
			$wc_order->update_meta_data( Order_Units::META, $records );
			$wc_order->save_meta_data();
		}
	}

	/**
	 * Returns the distinct seller article keys of some units.
	 *
	 * @param Order_Unit[] $units Units.
	 * @return string[]
	 */
	private static function offer_ids( array $units ): array {
		$ids = [];

		foreach ( $units as $unit ) {
			foreach ( $unit->lines() as $line ) {
				if ( '' !== $line['offer_id'] ) {
					$ids[] = $line['offer_id'];
				}
			}
		}

		return array_values( array_unique( $ids ) );
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
	 * Pairs each DTO line with the existing item that already is it (unit, marketplace key, quantity and unit price).
	 *
	 * @param \WC_Order $wc_order The order.
	 * @param Order_DTO $order    Whole order.
	 * @return array{0:array<int,array{0:\WC_Order_Item_Product,1:Order_Line}>,1:\WC_Order_Item_Product[],2:Order_Line[]} The items
	 *         that stay with their lines, the items no line is any more, and the lines no item is yet.
	 */
	private function compare_lines( \WC_Order $wc_order, Order_DTO $order ): array {
		$items = [];
		foreach ( $wc_order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$items[] = $item;
			}
		}

		$kept     = [];
		$arriving = [];

		foreach ( $order->lines() as $line ) {
			$match = $this->matching_item( $items, $line );

			if ( null === $match ) {
				$arriving[] = $line;
				continue;
			}

			$kept[] = [ $items[ $match ], $line ];
			unset( $items[ $match ] );
		}

		return [ $kept, array_values( $items ), $arriving ];
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
	 * Finds the first unused item that is the line: of the same unit, and the same for the marketplace.
	 *
	 * @param array<int,\WC_Order_Item_Product> $items Items not matched yet.
	 * @param Order_Line                        $line  Line.
	 * @return int|null Index in `$items`.
	 */
	private function matching_item( array $items, Order_Line $line ): ?int {
		foreach ( $items as $index => $item ) {
			if ( (string) $item->get_meta( self::ITEM_UNIT ) === $line->unit_id() && $this->contract->matches( $item, $line ) ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Changes the line set to the DTO's: the new items are built in full first, then the leaving items give back the stock they
	 * took, then they are removed and the new ones added, and the tax lines and totals are worked out again from the items the
	 * order now has (their taxes as stored: nothing is recalculated from today's tax settings). The new lines take their stock
	 * after the save, in {@see self::take_stock_if_due()}, so a process that dies anywhere in between leaves a state the next
	 * refresh completes.
	 *
	 * @param \WC_Order                $wc_order The order.
	 * @param Order_DTO                $order    Whole order.
	 * @param \WC_Order_Item_Product[] $leaving  Items no line is any more.
	 * @param Order_Line[]             $arriving Lines no item is yet.
	 * @return void
	 */
	private function change_lines( \WC_Order $wc_order, Order_DTO $order, array $leaving, array $arriving ): void {
		$built = $this->build_items( $order, $arriving );

		if ( $wc_order->get_id() > 0 && (bool) $wc_order->get_data_store()->get_stock_reduced( $wc_order->get_id() ) ) {
			// Stored before any stock moves, so a death after this point still knows the order held stock.
			$wc_order->update_meta_data( self::META_STOCK_PENDING, '1' );
			$wc_order->save_meta_data();
		}

		$this->give_back_stock( $wc_order, $leaving );

		foreach ( $leaving as $item ) {
			$wc_order->remove_item( $item->get_id() );
		}

		$unlinked = $this->attach( $wc_order, $built );
		$wc_order->update_taxes();
		$wc_order->calculate_totals( false );

		$this->note_unlinked( $wc_order, $unlinked );
	}

	/**
	 * Gives back the stock the leaving items took, the way `wc_increase_stock_levels()` does for a whole order, with the same
	 * filter and actions. For each item the stock write and the removal of its record are one transaction: a death or a failure
	 * between them (a listener of the stock hook included) leaves neither, and the next refresh gives the stock back once.
	 *
	 * @param \WC_Order                $wc_order The order.
	 * @param \WC_Order_Item_Product[] $items    Items about to leave the order.
	 * @return void
	 * @throws \Throwable What a stock write or its listener threw; the item then keeps its record.
	 */
	private function give_back_stock( \WC_Order $wc_order, array $items ): void {
		if ( [] === $items || 'yes' !== get_option( 'woocommerce_manage_stock' ) || ! apply_filters( 'woocommerce_can_restore_order_stock', true, $wc_order ) ) {
			return;
		}

		$changes = [];

		foreach ( $items as $item ) {
			$taken   = $item->get_meta( self::ITEM_REDUCED_STOCK, true );
			$product = $item->get_product();

			if ( ! $taken || ! $product || ! $product->managing_stock() ) {
				continue;
			}

			$stock     = $this->give_back_item( $wc_order, $item, $product, $taken );
			$changes[] = $product->get_name() . ' (' . ( $stock - $taken ) . '→' . $stock . ')';

			do_action( 'woocommerce_restore_order_item_stock', $item, $stock, $stock - $taken, $wc_order );
		}

		if ( [] === $changes ) {
			return;
		}

		$wc_order->add_order_note(
			/* translators: %s: the goods and their stock before and after, «Футболка (9→10)». */
			sprintf( __( 'Остатки по снятым позициям возвращены: %s', 'woodev-plugin-framework' ), implode( ', ', $changes ) )
		);

		do_action( 'woocommerce_restore_order_stock', $wc_order );
	}

	/**
	 * Gives back one item's stock in one transaction with the removal of the item's record of it.
	 *
	 * @param \WC_Order              $wc_order The order.
	 * @param \WC_Order_Item_Product $item     Item.
	 * @param \WC_Product            $product  Its product.
	 * @param int|float|string       $taken    What the item took.
	 * @return int|float The stock now.
	 * @throws \Throwable What the stock write or its listener threw.
	 */
	private function give_back_item( \WC_Order $wc_order, \WC_Order_Item_Product $item, \WC_Product $product, $taken ) {
		global $wpdb;

		$transaction = $this->transaction ?? new Stock_Transaction( $wpdb );

		try {
			return $transaction->run(
				static function () use ( $item, $product, $taken ) {
					$stock = wc_update_product_stock( $product, $taken, 'increase' );
					$item->delete_meta_data( self::ITEM_REDUCED_STOCK );
					$item->save();

					return $stock;
				},
				[ $product->get_id(), $product->get_stock_managed_by_id() ]
			);
		} catch ( \Throwable $e ) {
			self::forget_item( $item->get_id(), $wc_order->get_id() );

			throw $e;
		}
	}

	/**
	 * Drops the cached copies of an item a rolled-back transaction had written, so this process reads it from the database again.
	 *
	 * @param int $item_id  Item id.
	 * @param int $order_id Order id.
	 * @return void
	 */
	private static function forget_item( int $item_id, int $order_id ): void {
		wp_cache_delete( 'item-' . $item_id, 'order-items' );
		wp_cache_delete( $item_id, 'order_item_meta' );
		wp_cache_delete( 'order-items-' . $order_id, 'orders' );
		\WC_Cache_Helper::invalidate_cache_group( 'object_' . $item_id );
	}

	/**
	 * Takes the order's stock when WooCommerce has not taken it yet and the order holds stock: its status is one WooCommerce reduces
	 * on, or its lines changed while it held stock (the marker of {@see self::change_lines()}, which covers a custom status too)
	 * and its status does not release stock. This also completes a refresh that died between saving new lines and reducing them.
	 * WooCommerce's own `wc_maybe_reduce_stock_levels()` does the work: it reduces only items without a `_reduced_stock` record,
	 * honours the reduce filters and marks the order. In a releasing status the order keeps its mark, so WooCommerce gives back
	 * the lines that stayed.
	 *
	 * @param \WC_Order $wc_order The order, saved.
	 * @return void
	 */
	private function take_stock_if_due( \WC_Order $wc_order ): void {
		$swapped = '1' === (string) $wc_order->get_meta( self::META_STOCK_PENDING );
		$retake  = $swapped && ! $wc_order->has_status( self::STOCK_RELEASING_STATUSES );

		if ( $retake ) {
			// WooCommerce reduces only an order it has not marked; the lines that stayed keep their record and are skipped.
			$wc_order->get_data_store()->set_stock_reduced( $wc_order, false );
		}

		if ( $retake || $wc_order->has_status( self::STOCK_HOLDING_STATUSES ) ) {
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
	 * @param Order_DTO    $order Whole order.
	 * @param Order_Line[] $lines Lines of it.
	 * @return array<int,array{item:\WC_Order_Item_Product,unlinked:bool}>
	 */
	private function build_items( Order_DTO $order, array $lines ): array {
		$items = [];

		foreach ( $lines as $line ) {
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

		if ( '' !== $line->unit_id() ) {
			$item->update_meta_data( self::ITEM_UNIT, $line->unit_id() );
		}

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
