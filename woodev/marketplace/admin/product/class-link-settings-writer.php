<?php
/**
 * Applies the settings of the product tab to the link row.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Product;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Framework\Marketplace\Sync\Price_Intents;
use Woodev\Framework\Marketplace\Sync\Stock_Intents;

defined( 'ABSPATH' ) || exit;

/**
 * The one writer behind the product tab and the variation panel. It takes the values {@see Link_Form_Input} accepted and writes
 * them to the **existing** link storage; there is no second storage for overrides:
 *
 * - the switches, the oversize flag, the warehouse and the explicit mapping are columns of the link row
 *   ({@see Wpdb_Link_Store::update_settings()});
 * - the price override is the link's `price_rule_override`, written by {@see Price_Intents::change_override()}, which also works the
 *   new price out again (a change of the override is a price intent);
 * - the article typed before the first export is {@see Pinned_Offer_Id}.
 *
 * A product that has no row yet gets one only when the form asks for something other than what a new row would hold (both
 * switches on, nothing else set), so saving a product the merchant never configured creates nothing. A row of a variable product is
 * the *parent* row: it holds the mapping its variations inherit, and nothing else is written to it.
 *
 * It never calls the marketplace.
 *
 * @since 2.1.2
 */
final class Link_Settings_Writer {
	private const ROW_KEYS    = [ 'sync_stock', 'sync_price', 'is_oversize', 'warehouse_id', 'mapping_id' ];
	private const PARENT_KEYS = [ 'mapping_id' ];

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Price intents.
	 *
	 * @var Price_Intents
	 */
	private $intents;

	/**
	 * Mappings.
	 *
	 * @var Wpdb_Mapping_Store
	 */
	private $mappings;

	/**
	 * Warehouses of the catalogue cache.
	 *
	 * @var Warehouse_Options
	 */
	private $warehouses;

	/**
	 * Stock intents.
	 *
	 * @var Stock_Intents
	 */
	private $stock;

	/**
	 * The chosen articles.
	 *
	 * @var Pinned_Offer_Id
	 */
	private $pinned;

	/**
	 * Creates the writer.
	 *
	 * @since 2.1.2
	 *
	 * @param Wpdb_Link_Store    $links      Links.
	 * @param Price_Intents      $intents    Price intents.
	 * @param Wpdb_Mapping_Store $mappings   Mappings.
	 * @param Warehouse_Options  $warehouses Warehouses.
	 * @param Stock_Intents      $stock      Stock intents.
	 * @param Pinned_Offer_Id    $pinned     The chosen articles.
	 */
	public function __construct( Wpdb_Link_Store $links, Price_Intents $intents, Wpdb_Mapping_Store $mappings, Warehouse_Options $warehouses, Stock_Intents $stock, Pinned_Offer_Id $pinned ) {
		$this->links      = $links;
		$this->intents    = $intents;
		$this->mappings   = $mappings;
		$this->warehouses = $warehouses;
		$this->stock      = $stock;
		$this->pinned     = $pinned;
	}

	/**
	 * Saves one account's settings of a product or variation.
	 *
	 * @since 2.1.2
	 *
	 * @param Account             $account Account the settings are for (the caller has checked that it exists and is enabled).
	 * @param \WC_Product         $product Product or variation.
	 * @param array<string,mixed> $values  What {@see Link_Form_Input::parse()} accepted.
	 * @return string[] Sentences for what could not be saved; empty when everything was.
	 * @throws \Woodev\Framework\Marketplace\Data\Persistence_Exception When the database refuses the new row.
	 * @throws \RuntimeException When the database refuses a write.
	 */
	public function save( Account $account, \WC_Product $product, array $values ): array {
		$row    = $this->links->find( $account->id(), (int) $product->get_id() );
		$errors = $this->unknown_references( $account, $row, $values );

		if ( [] !== $errors ) {
			return $errors;
		}

		if ( array_key_exists( Link_Form_Input::KEY_OFFER_ID, $values ) && self::article_is_editable( $row ) ) {
			$this->pinned->store( $product, $account->id(), $values[ Link_Form_Input::KEY_OFFER_ID ] );
		}

		if ( null === $row ) {
			if ( ! self::asks_for_a_row( $values ) ) {
				return [];
			}

			$row = $this->create_row( $account, $product );
		}

		if ( null === $row ) {
			return [ __( 'Не удалось сохранить настройки товара. Повторите чуть позже.', 'woodev-plugin-framework' ) ];
		}

		$this->links->update_settings( (int) $row->link_id(), array_intersect_key( $values, array_flip( $row->is_offer() ? self::ROW_KEYS : self::PARENT_KEYS ) ) );

		if ( $row->is_offer() ) {
			$this->save_offer_extras( $account, $row, $values );
		} elseif ( self::mapping_changed( $row, $values ) ) {
			$this->recompute_prices_of_variations( $account, $product );
		}

		return [];
	}

	/**
	 * Tells whether the merchant may still choose the article: until the first request carries it.
	 *
	 * @since 2.1.2
	 *
	 * @param Link_Record|null $row The product's row, null when it has none.
	 * @return bool
	 */
	public static function article_is_editable( ?Link_Record $row ): bool {
		return null === $row || ( $row->is_offer() && Link_Record::STATE_UNLINKED === $row->card_state() && null === $row->frozen_offer_id() && null === $row->remote_id( 'product_id' ) );
	}

	/**
	 * Works out again what the changed settings of an offer row change on the marketplace: the price (a changed override, a price
	 * switch turned on, or another effective mapping, whose profile may carry another price rule) and the stock (a stock switch
	 * turned on, or another warehouse). Each goes through the intent recorder of its own flow; nothing here calls the marketplace.
	 *
	 * @param Account             $account Account.
	 * @param Link_Record         $row     The offer row as it was before the save.
	 * @param array<string,mixed> $values  Accepted values.
	 * @return void
	 */
	private function save_offer_extras( Account $account, Link_Record $row, array $values ): void {
		$fresh = $this->links->get( (int) $row->link_id() );

		if ( null === $fresh ) {
			return;
		}

		if ( array_key_exists( 'price_override', $values ) && $values['price_override'] !== $row->price_rule_override() ) {
			$this->intents->change_override( $account, $row, $values['price_override'] );
		} elseif ( ( ! $row->sync_price() && ! empty( $values['sync_price'] ) ) || self::mapping_changed( $row, $values ) ) {
			$this->intents->record_for_link( $account, $fresh );
		}

		$switched_on     = ! $row->sync_stock() && ! empty( $values['sync_stock'] );
		$moved_warehouse = array_key_exists( 'warehouse_id', $values ) && $values['warehouse_id'] !== $row->warehouse_id();
		$moved_oversize  = array_key_exists( 'is_oversize', $values ) && (bool) $values['is_oversize'] !== $row->is_oversize();

		if ( $switched_on || ( $fresh->sync_stock() && ( $moved_warehouse || $moved_oversize ) ) ) {
			$this->stock->record_stock( $fresh );
		}
	}

	/**
	 * Tells whether the posted mapping differs from the stored one (an absent key leaves it alone).
	 *
	 * @param Link_Record         $row    The row as it was before the save.
	 * @param array<string,mixed> $values Accepted values.
	 * @return bool
	 */
	private static function mapping_changed( Link_Record $row, array $values ): bool {
		return array_key_exists( 'mapping_id', $values ) && $values['mapping_id'] !== $row->mapping_id();
	}

	/**
	 * A variable product's mapping is inherited by its variations, so another one can change their prices: works each variation's
	 * price out again.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product The variable product.
	 * @return void
	 */
	private function recompute_prices_of_variations( Account $account, \WC_Product $product ): void {
		foreach ( array_map( 'intval', (array) $product->get_children() ) as $child_id ) {
			$child = $this->links->find( $account->id(), $child_id );

			if ( null !== $child && $child->is_offer() ) {
				$this->intents->record_for_link( $account, $child );
			}
		}
	}

	/**
	 * Checks that a chosen warehouse and a chosen mapping exist (the shape was checked by the input).
	 *
	 * What the row already holds stays acceptable: a warehouse the cached list lacks must not block saving the rest of the form.
	 *
	 * @param Account             $account Account.
	 * @param Link_Record|null    $row     The product's row.
	 * @param array<string,mixed> $values  Accepted values.
	 * @return string[]
	 */
	private function unknown_references( Account $account, ?Link_Record $row, array $values ): array {
		$errors = [];

		if ( ! empty( $values['warehouse_id'] ) && ( null === $row || $row->warehouse_id() !== $values['warehouse_id'] ) && ! $this->warehouse_listed( $account, (int) $values['warehouse_id'] ) ) {
			$errors[] = __( 'Такого склада нет в списке аккаунта. Обновите страницу и выберите склад из списка.', 'woodev-plugin-framework' );
		}

		if ( ! empty( $values['mapping_id'] ) && ( null === $row || $row->mapping_id() !== $values['mapping_id'] ) && null === $this->mappings->find( (int) $values['mapping_id'] ) ) {
			$errors[] = __( 'Такого сопоставления нет. Обновите страницу и выберите сопоставление из списка.', 'woodev-plugin-framework' );
		}

		return $errors;
	}

	/**
	 * Tells whether the cached warehouse list of an account holds a warehouse.
	 *
	 * @param Account $account      Account.
	 * @param int     $warehouse_id Warehouse id.
	 * @return bool
	 */
	private function warehouse_listed( Account $account, int $warehouse_id ): bool {
		return in_array( (string) $warehouse_id, array_map( 'strval', array_keys( $this->warehouses->for_account( $account->id() ) ) ), true );
	}

	/**
	 * Tells whether the values differ from what a new row holds, so a row has to be created for them.
	 *
	 * @param array<string,mixed> $values Accepted values.
	 * @return bool
	 */
	private static function asks_for_a_row( array $values ): bool {
		return ( array_key_exists( 'sync_stock', $values ) && ! $values['sync_stock'] )
			|| ( array_key_exists( 'sync_price', $values ) && ! $values['sync_price'] )
			|| ! empty( $values['is_oversize'] )
			|| ! empty( $values['warehouse_id'] )
			|| ! empty( $values['mapping_id'] )
			|| ! empty( $values['price_override'] );
	}

	/**
	 * Creates the row of a product that has none: an offer row without an article, or the parent row of a variable product.
	 *
	 * @param Account     $account Account.
	 * @param \WC_Product $product Product or variation.
	 * @return Link_Record|null
	 * @throws \Woodev\Framework\Marketplace\Data\Persistence_Exception When the database refuses the insert.
	 */
	private function create_row( Account $account, \WC_Product $product ): ?Link_Record {
		$record = $product->is_type( 'variable' )
			? Link_Record::parent_row( $account->id(), (int) $product->get_id() )
			: Link_Record::offer(
				$account->id(),
				(int) $product->get_id(),
				(int) $product->get_parent_id(),
				'',
				[
					'card_state' => Link_Record::STATE_UNLINKED,
					'sync_stock' => 1,
					'sync_price' => 1,
				]
			);

		$this->links->insert_if_absent( $record );

		return $this->links->find( $account->id(), (int) $product->get_id() );
	}
}
