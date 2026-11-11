<?php
/**
 * Order meta of the fake marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Flows\Order_Meta_Contract;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Line;

/**
 * The fake marketplace's meta, deliberately unlike any real one: the order is identified by `_fake_order` alone, its owner is the
 * account's `cabinet_ref` credential stamped in `_fake_cabinet`, and a line is the product whose SKU is the line's article.
 *
 * `$products` maps an article to the product a line sells; an article that is absent is an unlinked line.
 */
final class Fake_Order_Meta implements Order_Meta_Contract {
	public const ORDER   = '_fake_order';
	public const CABINET = '_fake_cabinet';
	public const SKU     = '_fake_sku';

	/**
	 * Products by article.
	 *
	 * @var array<string,\WC_Product>
	 */
	public $products = [];

	/**
	 * Owner stamps by order id (the tests that do not create real orders).
	 *
	 * @var array<int,string>
	 */
	public $owners = [];

	/**
	 * Returns what the orders are created via.
	 *
	 * @return string
	 */
	public function created_via(): string {
		return 'fake-seller';
	}

	/**
	 * Returns the identity clauses of an order.
	 *
	 * @param Order_DTO $order Whole order.
	 * @return array<int,array{key:string,value:string}>
	 */
	public function identity( Order_DTO $order ): array {
		return [
			[
				'key'   => self::ORDER,
				'value' => $order->group_key(),
			],
		];
	}

	/**
	 * Writes the order meta.
	 *
	 * @param \WC_Order $wc_order The order.
	 * @param Order_DTO $order    Whole order.
	 * @param bool      $created  True for a new order.
	 * @return void
	 */
	public function write_meta( \WC_Order $wc_order, Order_DTO $order, bool $created ): void {
		$wc_order->update_meta_data( self::ORDER, $order->group_key() );
		$wc_order->update_meta_data( self::CABINET, (string) $order->account_id() );
	}

	/**
	 * Runs after a save.
	 *
	 * @param \WC_Order $wc_order The order.
	 * @param Order_DTO $order    Whole order.
	 * @param bool      $created  True for the first real save.
	 * @return void
	 */
	public function after_save( \WC_Order $wc_order, Order_DTO $order, bool $created ): void {
	}

	/**
	 * Returns the product of a line.
	 *
	 * @param Order_DTO  $order Whole order.
	 * @param Order_Line $line  Line.
	 * @return \WC_Product|null
	 */
	public function product_for( Order_DTO $order, Order_Line $line ): ?\WC_Product {
		return $this->products[ $line->seller_sku() ] ?? null;
	}

	/**
	 * Writes the item meta.
	 *
	 * @param \WC_Order_Item_Product $item  Item.
	 * @param Order_DTO              $order Whole order.
	 * @param Order_Line             $line  Line.
	 * @return void
	 */
	public function write_line_meta( \WC_Order_Item_Product $item, Order_DTO $order, Order_Line $line ): void {
		$item->update_meta_data( self::SKU, $line->seller_sku() );
	}

	/**
	 * Tells whether an item is the line.
	 *
	 * @param \WC_Order_Item_Product $item Item.
	 * @param Order_Line             $line Line.
	 * @return bool
	 */
	public function matches( \WC_Order_Item_Product $item, Order_Line $line ): bool {
		return (string) $item->get_meta( self::SKU ) === $line->seller_sku() && $item->get_quantity() === $line->quantity();
	}

	/**
	 * Returns the stamp the account puts on its orders.
	 *
	 * @param Account $account Account.
	 * @return string
	 */
	public function account_owner( Account $account ): string {
		return (string) ( $account->credentials()['cabinet_ref'] ?? '' );
	}

	/**
	 * Returns the stamp an order carries.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	public function order_owner( int $order_id ): string {
		if ( isset( $this->owners[ $order_id ] ) ) {
			return $this->owners[ $order_id ];
		}

		$order = wc_get_order( $order_id );

		return $order instanceof \WC_Order ? (string) $order->get_meta( self::CABINET ) : '';
	}
}
