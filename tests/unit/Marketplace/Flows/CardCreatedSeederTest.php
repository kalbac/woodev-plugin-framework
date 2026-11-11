<?php
/**
 * The first stock and price intents of a created card.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Flows\Card_Created_Seeder;
use Woodev\Framework\Marketplace\Stock\Stock_Policy;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Card_Created_Seeder
 */
final class CardCreatedSeederTest extends Export_Test_Case {
	/**
	 * Builds the seeder over the fake marketplace's stock formula.
	 *
	 * @param Stock_Policy|null $policy Formula; the adapter's when null.
	 * @return Card_Created_Seeder
	 */
	private function seeder( ?Stock_Policy $policy = null ): Card_Created_Seeder {
		return new Card_Created_Seeder( $this->intents(), $this->contexts(), $policy ?? $this->adapter->stock_policy() );
	}

	/**
	 * A product with a price and a managed stock of seven.
	 *
	 * @param array<string,mixed> $props Product properties.
	 * @param int|null            $stock Stock quantity.
	 * @return \WC_Product
	 */
	private function stocked( array $props = [], ?int $stock = 7 ): \WC_Product {
		$product = $this->add_product( $props );
		$product->shouldReceive( 'get_stock_quantity' )->andReturn( $stock );

		return $product;
	}

	/**
	 * Both kinds are seeded: the price payload in the cabinet currency, and the stock under the account's warehouse.
	 *
	 * @return void
	 */
	public function test_both_kinds_are_seeded(): void {
		$product = $this->stocked();
		$link    = $this->add_link( $product, Link_Record::STATE_PRICE_SENT );
		$link_id = (int) $link->link_id();

		$seeded = $this->seeder()->seed( $this->account_now(), $link );

		$this->assertSame( [ Sync_State_Store::KIND_PRICE, Sync_State_Store::KIND_STOCK ], $seeded );
		$this->assertSame(
			[
				'price'         => '1000.00',
				'old_price'     => '0',
				'min_price'     => null,
				'currency_code' => 'RUB',
			],
			$this->intents()->pending_of( $this->account_id, $link_id, 0, Sync_State_Store::KIND_PRICE )['payload']
		);
		$this->assertSame( [ 'stock' => 7 ], $this->intents()->pending_of( $this->account_id, $link_id, self::WAREHOUSE, Sync_State_Store::KIND_STOCK )['payload'] );
	}

	/**
	 * Seeding twice neither duplicates the rows nor inflates their revision.
	 *
	 * @return void
	 */
	public function test_seeding_is_idempotent(): void {
		$product = $this->stocked();
		$link    = $this->add_link( $product, Link_Record::STATE_PRICE_SENT );

		$this->seeder()->seed( $this->account_now(), $link );
		$this->seeder()->seed( $this->account_now(), $link );

		$this->assertSame( 1, $this->intents()->pending_of( $this->account_id, (int) $link->link_id(), 0, Sync_State_Store::KIND_PRICE )['rev'] );
	}

	/**
	 * Only the asked kind is seeded.
	 *
	 * @return void
	 */
	public function test_one_kind_can_be_asked_for(): void {
		$link = $this->add_link( $this->stocked(), Link_Record::STATE_PRICE_SENT );

		$this->assertSame( [ Sync_State_Store::KIND_STOCK ], $this->seeder()->seed( $this->account_now(), $link, [ Sync_State_Store::KIND_STOCK ] ) );
		$this->assertNull( $this->intents()->pending_of( $this->account_id, (int) $link->link_id(), 0, Sync_State_Store::KIND_PRICE ) );
	}

	/**
	 * The link's switches decide: a price off or a stock off seeds nothing of that kind.
	 *
	 * @return void
	 */
	public function test_the_links_switches_are_respected(): void {
		$link = $this->add_link(
			$this->stocked(),
			Link_Record::STATE_PRICE_SENT,
			[
				'sync_price' => 0,
				'sync_stock' => 0,
			]
		);

		$this->assertSame( [], $this->seeder()->seed( $this->account_now(), $link ) );
	}

	/**
	 * The account's directions decide: a price that flows from the marketplace, or a stock the marketplace keeps, seeds nothing.
	 *
	 * @return void
	 */
	public function test_the_accounts_directions_are_respected(): void {
		$this->set_account(
			[
				'price_direction' => Wpdb_Account_Store::PRICE_FROM_MARKETPLACE,
				'stock_mode'      => Wpdb_Account_Store::STOCK_MARKETPLACE,
			]
		);
		$link = $this->add_link( $this->stocked(), Link_Record::STATE_PRICE_SENT );

		$this->assertSame( [], $this->seeder()->seed( $this->account_now(), $link ) );
	}

	/**
	 * The preview says why a kind has nothing to store.
	 *
	 * @return void
	 */
	public function test_the_intent_names_the_reason_for_nothing(): void {
		$link = $this->add_link( $this->stocked( [], null ), Link_Record::STATE_PRICE_SENT );

		$this->assertSame( Card_Created_Seeder::NO_STOCK, $this->seeder()->intent( $this->account_now(), $link, Sync_State_Store::KIND_STOCK )['reason'] );

		$free = $this->add_link( $this->stocked( [ 'price' => '', 'regular_price' => '' ] ), Link_Record::STATE_PRICE_SENT );

		$this->assertSame( Card_Created_Seeder::NO_PRICE, $this->seeder()->intent( $this->account_now(), $free, Sync_State_Store::KIND_PRICE )['reason'] );

		$this->set_account( [ 'warehouse_id' => 0 ] );
		$another = $this->add_link( $this->stocked(), Link_Record::STATE_PRICE_SENT );

		$this->assertSame( Card_Created_Seeder::NO_WAREHOUSE, $this->seeder()->intent( $this->account_now(), $another, Sync_State_Store::KIND_STOCK )['reason'] );
	}

	/**
	 * A product that is gone has no intent.
	 *
	 * @return void
	 */
	public function test_a_missing_product_has_no_intent(): void {
		$product = $this->stocked();
		$link    = $this->add_link( $product, Link_Record::STATE_PRICE_SENT );

		unset( $this->products[ (int) $product->get_id() ] );

		$this->assertSame( Card_Created_Seeder::NO_PRODUCT, $this->seeder()->intent( $this->account_now(), $link, Sync_State_Store::KIND_PRICE )['reason'] );
		$this->assertSame( [], $this->seeder()->seed( $this->account_now(), $link ) );
	}

	/**
	 * The stock follows the marketplace's formula: a formula that adds the reservation adds nothing yet, one that mirrors the shop
	 * pushes the shop's quantity.
	 *
	 * @return void
	 */
	public function test_the_stock_follows_the_adapters_formula(): void {
		$link = $this->add_link( $this->stocked( [], 12 ), Link_Record::STATE_PRICE_SENT );

		$this->assertSame( 12, $this->seeder( new Stock_Policy( Stock_Policy::FORMULA_WC_ONLY ) )->intent( $this->account_now(), $link, Sync_State_Store::KIND_STOCK )['payload']['stock'] );
		$this->assertSame( 12, $this->seeder( new Stock_Policy( Stock_Policy::FORMULA_PLUS_RESERVED ) )->intent( $this->account_now(), $link, Sync_State_Store::KIND_STOCK )['payload']['stock'] );
	}

	/**
	 * A link with no row id seeds nothing.
	 *
	 * @return void
	 */
	public function test_an_unsaved_link_seeds_nothing(): void {
		$this->assertSame( [], $this->seeder()->seed( $this->account_now(), Link_Record::offer( $this->account_id, 999, 0, 'X' ) ) );
	}
}
