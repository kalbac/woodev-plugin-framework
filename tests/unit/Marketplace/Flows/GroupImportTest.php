<?php
/**
 * Importing the cards of one marketplace group: one variable product, whatever the order and the number of runs.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Import_Plan;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Flows\Card_Import;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Flows\Group_Assembler;
use Woodev\Framework\Marketplace\Flows\Legacy_Order_Lines;
use Woodev\Framework\Marketplace\Flows\Product_Writer;
use Woodev\Framework\Marketplace\Jobs\Claim_Lost;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Shop;

/**
 * The importer and the assembler run for real, over the links table and a shop in memory ({@see Fake_Shop}); only the product
 * writer is played, by its two halves: the first save (`start`) and the rest (`complete`).
 *
 * @covers \Woodev\Framework\Marketplace\Flows\Group_Assembler
 * @covers \Woodev\Framework\Marketplace\Flows\Card_Import
 * @covers \Woodev\Framework\Marketplace\Data\Wpdb_Link_Store::move_offer
 */
final class GroupImportTest extends Export_Test_Case {
	private const PREFIX = 'fake_seller_';
	private const GROUP  = 'model-7';

	/**
	 * The shop.
	 *
	 * @var Fake_Shop
	 */
	private $shop;

	/**
	 * Options of the test, by name.
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	/**
	 * What the writer was asked to do: `[ 'start', article ]` and `[ 'complete', article, product id, publish, other images ]`.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private $calls = [];

	/**
	 * Articles whose `complete` throws once, before anything of it is saved.
	 *
	 * @var array<string,\Throwable>
	 */
	private $cut = [];

	protected function setUp(): void {
		parent::setUp();

		$this->options = [];
		$this->calls   = [];
		$this->cut     = [];
		$this->shop    = new Fake_Shop();
		$this->shop->install();

		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );

				return true;
			}
		);
	}

	/**
	 * @return Card_Import
	 */
	private function importer(): Card_Import {
		return new Card_Import(
			$this->links(),
			new Card_Linker( $this->links() ),
			new Link_Report_Store( self::PREFIX ),
			new Effective_Settings(
				Sqlite_Tables::MARKETPLACE_ID,
				function ( string $name, $fallback = false ) {
					return $this->options[ $name ] ?? $fallback;
				}
			),
			new Group_Assembler( $this->links() ),
			function ( Account $account, Card_Content $content, bool $create_categories, ?callable $prepare = null ): \WC_Product {
				return $this->start( $content, $prepare );
			},
			function ( Account $account, Card_Content $content, \WC_Product $product, bool $publish, callable $renew ): array {
				return $this->complete( $content, $product, $publish );
			}
		);
	}

	/**
	 * Plays `Product_Writer::start()`: a draft with the name, the SKU, the marker and what `$prepare` sets, in one save.
	 *
	 * @param Card_Content  $content Card.
	 * @param callable|null $prepare Called on the unsaved product.
	 * @return \WC_Product
	 */
	private function start( Card_Content $content, ?callable $prepare ): \WC_Product {
		$article       = (string) $content->card()['offer_id'];
		$this->calls[] = [ 'start', $article ];
		$product       = $this->shop->product( 'simple' );

		$product->set_name( $content->name() );
		$product->set_status( 'draft' );
		$product->set_sku( $article );
		$product->update_meta_data( $this->marker(), '1' );

		if ( null !== $prepare ) {
			$prepare( $product );
		}

		$product->save();

		return $product;
	}

	/**
	 * Plays `Product_Writer::complete()`: the price and the image, then the final save with the status and without the marker.
	 * A variation that is not published is switched off, as the writer's final save stores it.
	 *
	 * @param Card_Content $content Card.
	 * @param \WC_Product  $product The started product or variation.
	 * @param bool         $publish Publish it.
	 * @return string[]
	 */
	private function complete( Card_Content $content, \WC_Product $product, bool $publish ): array {
		$article       = (string) $content->card()['offer_id'];
		$this->calls[] = [ 'complete', $article, (int) $product->get_id(), $publish, count( $content->images() ) ];

		if ( isset( $this->cut[ $article ] ) ) {
			$cut = $this->cut[ $article ];
			unset( $this->cut[ $article ] );

			throw $cut;
		}

		$product->set_regular_price( (string) ( 100 + strlen( $article ) ) );
		$product->set_image_id( crc32( $content->primary_image() ) % 1000 + 1 );
		$product->set_status( $publish ? 'publish' : ( $product->is_type( 'variation' ) ? 'private' : 'draft' ) );
		$product->delete_meta_data( $this->marker() );
		$product->save();

		return [];
	}

	/**
	 * @return string
	 */
	private function marker(): string {
		return Product_Writer::marker_key( Sqlite_Tables::MARKETPLACE_ID );
	}

	/**
	 * Builds a card of the group.
	 *
	 * @param string              $article Article.
	 * @param string              $colour  Its colour, '' for a card with no aspect.
	 * @param int                 $size    How many cards the marketplace says the group has.
	 * @param array<string,mixed> $card    Link card values that replace the defaults.
	 * @param string              $group   Group key, '' for a card that stands alone.
	 * @return Card_Content
	 */
	private function card( string $article, string $colour, int $size, array $card = [], string $group = self::GROUP ): Card_Content {
		return new Card_Content(
			array_merge(
				[
					'offer_id'   => $article,
					'remote_ids' => [ 'card_ref' => crc32( $article ) % 100000 ],
					'state'      => Link_Record::STATE_CREATED,
					'is_created' => true,
					'archived'   => false,
				],
				$card
			),
			[
				'name'          => 'Футболка ' . $colour,
				'primary_image' => 'https://img.example.test/' . $article . '.jpg',
				'images'        => [ 'https://img.example.test/' . $article . '-2.jpg', 'https://img.example.test/' . $article . '-3.jpg' ],
				'group'         => [
					'key'     => $group,
					'size'    => $size,
					'aspects' => '' === $colour ? [] : [ 'Цвет' => $colour ],
				],
			]
		);
	}

	/**
	 * Imports a card in a run of its own that holds the account.
	 *
	 * @param Card_Content     $content Card.
	 * @param Claim_Fence|null $fence   Claim of the run; a held one when null.
	 * @return string
	 */
	private function import( Card_Content $content, ?Claim_Fence $fence = null ): string {
		$held = $this->job_gate()->claim( $this->account_id );

		$this->assertNotNull( $held );

		try {
			return $this->importer()->import( $this->account_now(), $content, $fence ?? $held, static function (): void {} );
		} finally {
			$this->job_gate()->release( $this->account_id, $held );
		}
	}

	/**
	 * The stored product with a SKU.
	 *
	 * @param string $sku SKU.
	 * @return array{id:int,type:string,props:array<string,mixed>,meta:array<string,mixed>}
	 */
	private function by_sku( string $sku ): array {
		$id = $this->shop->id_by_sku( $sku );

		$this->assertGreaterThan( 0, $id, 'A product has the SKU ' . $sku );

		return [ 'id' => $id ] + $this->shop->stored[ $id ];
	}

	/**
	 * The products of the shop that are not variations, by id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function products(): array {
		return array_filter(
			$this->shop->stored,
			static function ( array $row ): bool {
				return 'variation' !== $row['type'];
			}
		);
	}

	/**
	 * What the shop and the links table hold, without ids and names: the comparison of two ways to import the same cards.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function shape(): array {
		$shape = [];

		foreach ( $this->products() as $id => $row ) {
			$variations = [];

			foreach ( $this->shop->variations( $id ) as $variation_id => $variation ) {
				$link = $this->links()->find( $this->account_id, $variation_id );

				$variations[ $variation['props']['sku'] ] = [
					'attributes' => $variation['props']['attributes'],
					'price'      => $variation['props']['regular_price'],
					'status'     => $variation['props']['status'],
					'link'       => null === $link ? null : [ $link->is_offer(), $link->offer_id(), $link->wc_parent_id() === $id ],
				];
			}

			ksort( $variations );

			$own     = $this->links()->find( $this->account_id, $id );
			$shape[] = [
				'type'       => $row['type'],
				'sku'        => $row['props']['sku'],
				'price'      => $row['props']['regular_price'],
				'attributes' => array_map(
					static function ( $attribute ): array {
						return [ $attribute->get_name(), $attribute->get_options(), $attribute->get_variation() ];
					},
					$row['props']['attributes']
				),
				'pending'    => isset( $row['meta'][ Group_Assembler::variant_key( Sqlite_Tables::MARKETPLACE_ID ) ] ),
				'row'        => null === $own ? null : [ $own->is_offer(), $own->offer_id() ],
				'variations' => $variations,
			];
		}

		return $shape;
	}

	/**
	 * Starts the test again with an empty shop and an empty links table.
	 *
	 * @return void
	 */
	private function empty_shop(): void {
		$this->shop = new Fake_Shop();
		$this->shop->install();
		$this->calls = [];
		$this->db->query( 'DELETE FROM ' . Sqlite_Tables::table( 'link_ids' ) );
		$this->db->query( 'DELETE FROM ' . Sqlite_Tables::table( 'links' ) );
	}

	/**
	 * The note of a card, null when it has none.
	 *
	 * @param string $article Article.
	 * @return string|null
	 */
	private function note( string $article ): ?string {
		foreach ( ( new Link_Report_Store( self::PREFIX ) )->import_notes( $this->account_id ) as $note ) {
			if ( $article === $note['offer_id'] ) {
				return $note['text'];
			}
		}

		return null;
	}

	public function test_a_card_with_no_group_is_a_simple_product_exactly_as_before(): void {
		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( 'SOLO', 'белый', 0, [], '' ) ) );

		$product = $this->by_sku( 'SOLO' );

		$this->assertSame( 'simple', $product['type'] );
		$this->assertSame( [], $product['meta'], 'no group mark, and the marker is gone' );
		$this->assertSame( 2, $this->calls[1][4], 'the product takes every image of the card' );
		$this->assertTrue( $this->links()->find( $this->account_id, $product['id'] )->is_offer() );
		$this->assertSame( 1, $this->links()->count( $this->account_id ), 'one offer row, no parent row' );
	}

	public function test_the_only_card_of_its_group_is_a_simple_product_that_remembers_the_group(): void {
		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( 'RED', 'красный', 1 ) ) );

		$product = $this->by_sku( 'RED' );

		$this->assertSame( 'simple', $product['type'] );
		$this->assertSame( $this->account_id . ':' . self::GROUP, $product['meta'][ Group_Assembler::group_key( Sqlite_Tables::MARKETPLACE_ID ) ] );
		$this->assertSame( [ 'aspects' => [ 'Цвет' => 'красный' ] ], $product['meta'][ Group_Assembler::variant_key( Sqlite_Tables::MARKETPLACE_ID ) ] );
		$this->assertSame( 'RED', $this->links()->find( $this->account_id, $product['id'] )->offer_id() );
	}

	public function test_a_lone_card_of_a_group_of_several_is_a_variable_product_with_one_variation(): void {
		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( 'RED', 'красный', 2 ) ) );

		$variation = $this->by_sku( 'RED' );
		$parent_id = (int) $variation['props']['parent_id'];
		$parent    = $this->shop->stored[ $parent_id ];

		$this->assertSame( 'variation', $variation['type'] );
		$this->assertSame( 900, $parent_id, 'the product the writer made is the parent: its id is kept' );
		$this->assertSame( [ 'variable', '' ], [ $parent['type'], $parent['props']['sku'] ], 'the variable product has no SKU of its own' );
		$this->assertSame( [ '103', 'instock' ], [ $variation['props']['regular_price'], $variation['props']['stock_status'] ], 'the variation has the price and the stock status the product was given' );
		$this->assertSame( 'instock', $parent['props']['stock_status'] );
		$this->assertSame( 'Футболка красный', $parent['props']['name'] );
		$this->assertSame( [ rawurlencode( 'Цвет' ) => 'красный' ], array_change_key_case( $variation['props']['attributes'], CASE_UPPER ) );

		$link = $this->links()->find_by_offer_id( $this->account_id, 'RED' );

		$this->assertSame( [ $variation['id'], $parent_id ], [ $link->wc_product_id(), $link->wc_parent_id() ], 'the card is the variation' );

		$parent_row = $this->links()->find( $this->account_id, $parent_id );

		$this->assertFalse( $parent_row->is_offer(), 'the variable product has a row of kind parent' );
		$this->assertNull( $parent_row->offer_id(), 'and no article: it is not a card' );
	}

	public function test_red_then_black_equals_red_and_black_together(): void {
		// Together: the marketplace already says the group has two cards when the first one arrives.
		$this->import( $this->card( 'RED', 'красный', 2 ) );
		$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );

		$together = $this->shape();

		$this->assertCount( 1, $together, 'two cards, one product' );
		$this->assertSame( [ 'BLACK', 'RED' ], array_keys( $together[0]['variations'] ) );
		$this->assertSame( [ [ 'Цвет', [ 'красный', 'чёрный' ], true ] ], array_values( $together[0]['attributes'] ) );
		$this->assertFalse( $together[0]['pending'] );

		// A week apart: the red card was alone in its group when it was imported, the black one was created later.
		$this->empty_shop();
		$this->import( $this->card( 'RED', 'красный', 1 ) );

		$this->assertSame( 'simple', $this->by_sku( 'RED' )['type'] );

		$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );

		$this->assertSame( $together, $this->shape(), 'red, then black a week later, is the same product as both at once' );
	}

	public function test_the_cards_in_reverse_order_give_the_same_variations(): void {
		$this->import( $this->card( 'RED', 'красный', 2 ) );
		$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );

		$forward = $this->shape()[0]['variations'];

		$this->empty_shop();
		$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );
		$this->import( $this->card( 'RED', 'красный', 2 ) );

		$this->assertCount( 1, $this->shape() );
		$this->assertSame( $forward, $this->shape()[0]['variations'] );
	}

	public function test_the_conversion_keeps_the_product_moves_its_sku_and_repoints_its_link(): void {
		$this->import( $this->card( 'RED', 'красный', 1 ) );

		$simple = $this->by_sku( 'RED' );
		$before = $this->links()->find( $this->account_id, $simple['id'] );

		// A week of the merchant's work on the simple product: it is published, stock is managed, the link is configured.
		$product = $this->shop->product( 'simple', $simple['id'] );
		$product->set_status( 'publish' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 7 );
		$product->save();
		$this->links()->update_settings(
			(int) $before->link_id(),
			[
				'sync_stock' => true,
				'mapping_id' => $this->mapping_id,
			]
		);

		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( 'BLACK', 'чёрный', 2 ) ) );

		$parent = $this->shop->stored[ $simple['id'] ];
		$red    = $this->by_sku( 'RED' );

		$this->assertSame( 'variable', $parent['type'], 'the product became variable in place' );
		$this->assertSame( [ '', '', false ], [ $parent['props']['sku'], $parent['props']['regular_price'], $parent['props']['manage_stock'] ], 'it gave up its SKU, its price and its stock' );
		$this->assertSame( 'publish', $parent['props']['status'], 'and stays published' );
		$this->assertNotSame( $simple['id'], $red['id'] );
		$this->assertSame( [ 'variation', $simple['id'] ], [ $red['type'], $red['props']['parent_id'] ], 'the former SKU is the first variation' );
		$this->assertSame( [ $simple['props']['regular_price'], true, 7 ], [ $red['props']['regular_price'], $red['props']['manage_stock'], $red['props']['stock_quantity'] ] );
		$this->assertSame( [ 'instock', 'instock' ], [ $red['props']['stock_status'], $parent['props']['stock_status'] ], 'what the product had in stock, its variation has' );
		$this->assertSame( $simple['props']['image_id'], $red['props']['image_id'] );

		$after = $this->links()->find_by_offer_id( $this->account_id, 'RED' );

		$this->assertSame( $before->link_id(), $after->link_id(), 'the same link row: nothing new was linked' );
		$this->assertSame( [ $red['id'], $simple['id'] ], [ $after->wc_product_id(), $after->wc_parent_id() ] );
		$this->assertSame( [ true, $this->mapping_id, $before->remote_ids() ], [ $after->sync_stock(), $after->mapping_id(), $after->remote_ids() ], 'with its settings and identifiers' );

		$parent_row = $this->links()->find( $this->account_id, $simple['id'] );

		$this->assertSame( [ false, null, $this->mapping_id ], [ $parent_row->is_offer(), $parent_row->offer_id(), $parent_row->mapping_id() ], 'a parent row with no article carries the mapping' );

		$black = $this->by_sku( 'BLACK' );

		$this->assertSame( [ 'variation', $simple['id'], 'private' ], [ $black['type'], $black['props']['parent_id'], $black['props']['status'] ], 'the new card joins a published product switched off' );
		$this->assertStringContainsString( 'выключенным', (string) $this->note( 'BLACK' ) );
		$this->assertSame( 3, $this->links()->count( $this->account_id ), 'two offer rows and the parent row' );
	}

	public function test_a_conversion_cut_at_any_save_is_finished_by_the_next_run(): void {
		$this->import( $this->card( 'RED', 'красный', 2 ) );
		$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );

		$whole = $this->shape();

		// The conversion saves three times: the product as variable, the first variation, the product's clean-up.
		foreach ( [ 1, 2, 3 ] as $save ) {
			$this->empty_shop();
			$this->import( $this->card( 'RED', 'красный', 1 ) );

			$this->shop->fail_save_at = $this->shop->saves + $save;

			try {
				$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );
				$this->fail( 'The run is cut at save ' . $save . ' of the conversion.' );
			} catch ( \RuntimeException $cut ) {
				$this->assertSame( 0, $this->shop->id_by_sku( 'BLACK' ), 'the black card has no variation yet' );
			}

			$this->assertSame( Card_Import::CREATED, $this->import( $this->card( 'BLACK', 'чёрный', 2 ) ) );
			$this->assertSame( $whole, $this->shape(), 'cut at save ' . $save . ', the next run finishes the same product' );
		}
	}

	public function test_a_conversion_cut_between_its_link_writes_is_finished_by_the_next_card(): void {
		$this->import( $this->card( 'RED', 'красный', 2 ) );
		$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );

		$whole = $this->shape();

		$this->empty_shop();
		$this->import( $this->card( 'RED', 'красный', 1 ) );

		// The run that converts loses the account claim: the product is variable, its first variation exists, the link stays.
		try {
			$this->import( $this->card( 'BLACK', 'чёрный', 2 ), new Claim_Fence( Sqlite_Tables::table( 'accounts' ), 'a-token-another-run-replaced' ) );
			$this->fail( 'A lost claim stops the conversion.' );
		} catch ( Claim_Lost $lost ) {
			$this->assertSame( 'RED', $this->links()->find( $this->account_id, 900 )->offer_id(), 'the link was not moved without the claim' );
			$this->assertTrue( $this->shape()[0]['pending'], 'and the conversion is still open' );
		}

		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( 'BLACK', 'чёрный', 2 ) ) );
		$this->assertSame( $whole, $this->shape() );
	}

	public function test_a_card_cut_after_its_variation_was_started_resumes_on_that_variation(): void {
		$this->import( $this->card( 'RED', 'красный', 2 ) );

		$this->cut['BLACK'] = new \RuntimeException( 'time limit during the image' );

		try {
			$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );
			$this->fail( 'The run is cut.' );
		} catch ( \RuntimeException $cut ) {
			$started = $this->by_sku( 'BLACK' );

			$this->assertSame( [ 'variation', 'private', '1' ], [ $started['type'], $started['props']['status'], $started['meta'][ $this->marker() ] ], 'the SKU and the marker are stored together, the variation switched off' );
			$this->assertNull( $this->links()->find_by_offer_id( $this->account_id, 'BLACK' ) );
		}

		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( 'BLACK', 'чёрный', 2 ) ) );

		$black = $this->by_sku( 'BLACK' );

		$this->assertSame( $started['id'], $black['id'], 'the same variation, not a second one' );
		$this->assertCount( 2, $this->shop->variations( 900 ) );
		$this->assertSame( [ 'publish', [] ], [ $black['props']['status'], $black['meta'] ] );
		$this->assertSame( [ $black['id'], 900 ], [ $this->links()->find_by_offer_id( $this->account_id, 'BLACK' )->wc_product_id(), $this->links()->find_by_offer_id( $this->account_id, 'BLACK' )->wc_parent_id() ] );
		$this->assertSame( [ 'start', 'complete', 'complete', 'complete' ], array_column( $this->calls, 0 ), 'the writer never starts a second product for the group' );
	}

	public function test_a_variation_whose_link_was_refused_for_a_lost_claim_is_linked_by_the_retry(): void {
		$this->import( $this->card( 'RED', 'красный', 2 ) );

		try {
			$this->import( $this->card( 'BLACK', 'чёрный', 2 ), new Claim_Fence( Sqlite_Tables::table( 'accounts' ), 'a-token-another-run-replaced' ) );
			$this->fail( 'The lost claim stops the run.' );
		} catch ( Claim_Lost $lost ) {
			$this->assertSame( [], $this->by_sku( 'BLACK' )['meta'], 'the variation is finished, only its link is missing' );
		}

		$this->assertSame( Card_Import::LINKED_EXISTING, $this->import( $this->card( 'BLACK', 'чёрный', 2 ) ) );

		$link = $this->links()->find_by_offer_id( $this->account_id, 'BLACK' );

		$this->assertSame( [ $this->by_sku( 'BLACK' )['id'], 900 ], [ $link->wc_product_id(), $link->wc_parent_id() ], 'linked with its real parent' );
		$this->assertCount( 2, $this->shop->variations( 900 ) );
	}

	public function test_a_variation_takes_the_card_without_its_other_images_and_is_always_switched_on_by_the_writer(): void {
		$this->import( $this->card( 'RED', 'красный', 2 ) );
		$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );

		$this->assertSame( [ 'complete', 'RED', 900, false, 2 ], $this->calls[1], 'the product of the group takes the whole first card, as a draft' );
		$this->assertSame( [ 'complete', 'BLACK', $this->by_sku( 'BLACK' )['id'], true, 0 ], $this->calls[2], 'a variation takes one image and is never a draft' );
		$this->assertSame( 'publish', $this->by_sku( 'BLACK' )['props']['status'], 'a variation of a draft product is switched on: it sells when the product is published' );
		$this->assertNull( $this->note( 'BLACK' ) );
	}

	public function test_an_archived_card_of_a_group_gets_no_variation(): void {
		$this->import( $this->card( 'RED', 'красный', 3 ) );

		$this->assertSame( Card_Import::SKIPPED, $this->import( $this->card( 'OLD', 'синий', 3, [ 'archived' => true ] ) ) );
		$this->assertSame( 0, $this->shop->id_by_sku( 'OLD' ) );
		$this->assertCount( 1, $this->shop->variations( 900 ) );
		$this->assertStringContainsString( 'архиве', (string) $this->note( 'OLD' ) );
	}

	public function test_a_group_whose_first_product_is_half_made_is_not_built_upon(): void {
		$this->cut['RED'] = new \RuntimeException( 'time limit during images' );

		try {
			$this->import( $this->card( 'RED', 'красный', 2 ) );
			$this->fail( 'The run is cut.' );
		} catch ( \RuntimeException $cut ) {
			$this->assertArrayHasKey( Group_Assembler::group_key( Sqlite_Tables::MARKETPLACE_ID ), $this->by_sku( 'RED' )['meta'], 'the group mark is stored before the images' );
		}

		$this->assertSame( Card_Import::SKIPPED, $this->import( $this->card( 'BLACK', 'чёрный', 2 ) ) );
		$this->assertSame( 0, $this->shop->id_by_sku( 'BLACK' ), 'no second product for the group, no variation of a half-made one' );
		$this->assertStringContainsString( 'RED', (string) $this->note( 'BLACK' ) );

		// The red card is resumed, and then the black one joins it.
		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( 'RED', 'красный', 2 ) ) );
		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( 'BLACK', 'чёрный', 2 ) ) );
		$this->assertCount( 1, $this->products() );
		$this->assertSame( [ 'BLACK', 'RED' ], array_keys( $this->shape()[0]['variations'] ) );
		$this->assertNull( $this->note( 'BLACK' ) );
	}

	public function test_a_product_the_merchant_linked_by_its_article_is_never_converted(): void {
		$own = $this->shop->product( 'simple' );
		$own->set_sku( 'RED' );
		$own->set_regular_price( '500' );
		$own->save();

		$this->assertSame( Card_Import::LINKED_EXISTING, $this->import( $this->card( 'RED', 'красный', 2 ) ) );
		$this->assertSame( Card_Import::CREATED, $this->import( $this->card( 'BLACK', 'чёрный', 2 ) ) );

		$red = $this->by_sku( 'RED' );

		$this->assertSame( [ 'simple', '500', [] ], [ $red['type'], $red['props']['regular_price'], $red['meta'] ], 'the merchant\'s product is linked and left alone' );
		$this->assertCount( 2, $this->products(), 'the black card is a product of the group of its own' );
		$this->assertSame( 'variation', $this->by_sku( 'BLACK' )['type'] );
	}

	public function test_a_card_with_no_aspect_is_told_apart_by_its_article(): void {
		$this->import( $this->card( 'RED', '', 2 ) );
		$this->import( $this->card( 'BLACK', '', 2 ) );

		$shape = $this->shape()[0];

		$this->assertSame( [ [ 'Вариант', [ 'RED', 'BLACK' ], true ] ], array_values( $shape['attributes'] ) );
		$this->assertSame( [ 'BLACK', 'RED' ], array_map( 'current', array_column( $shape['variations'], 'attributes' ) ) );
	}

	public function test_the_shop_that_publishes_at_once_gets_a_new_variation_switched_on(): void {
		$this->options[ Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ) ] = [ 'import_publish' => true ];

		$this->import( $this->card( 'RED', 'красный', 2 ) );
		$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );

		$this->assertSame( 'publish', $this->shop->stored[900]['props']['status'] );
		$this->assertSame( 'publish', $this->by_sku( 'BLACK' )['props']['status'] );
		$this->assertNull( $this->note( 'BLACK' ) );
	}

	public function test_the_link_of_another_account_moves_to_the_first_variation_too(): void {
		$this->import( $this->card( 'RED', 'красный', 1 ) );

		$other = $this->accounts()->save(
			new Account(
				0,
				Sqlite_Tables::MARKETPLACE_ID,
				'Второй кабинет',
				true,
				[
					'cabinet_ref' => 'cabinet-9001',
					'api_token'   => 'tok-2222-3333',
				],
				null,
				[ 'currency' => 'RUB' ]
			)
		);
		$this->links()->insert_if_absent( Link_Record::offer( $other, 900, 0, 'RED-2', [ 'remote_ids' => [ 'card_ref' => 9 ] ] ) );

		$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );

		$red  = $this->by_sku( 'RED' );
		$link = $this->links()->find_by_offer_id( $other, 'RED-2' );

		$this->assertSame( [ $red['id'], 900 ], [ $link->wc_product_id(), $link->wc_parent_id() ] );
		$this->assertFalse( $this->links()->find( $other, 900 )->is_offer(), 'and that account has its parent row' );
	}

	public function test_a_card_of_another_group_is_another_product(): void {
		$this->import( $this->card( 'RED', 'красный', 2 ) );
		$this->import( $this->card( 'CUP', 'белый', 2, [], 'model-8' ) );

		$this->assertCount( 2, $this->products() );
		$this->assertCount( 1, $this->shop->variations( 900 ) );
	}

	/**
	 * Critic round 1, finding 1: the group's mark is part of the product's first save, so a run cut right after that save
	 * cannot lose it and a sibling cannot start a second product for the group.
	 */
	public function test_a_run_cut_right_after_the_first_save_has_stored_the_group_and_no_second_product_is_made(): void {
		$this->shop->fail_save_at = 2;

		try {
			$this->import( $this->card( 'RED', 'красный', 2 ) );
			$this->fail( 'The run is cut at its second save.' );
		} catch ( \RuntimeException $cut ) {
			$this->assertSame( 1, $this->shop->saves - 1, 'only the first save was stored' );
			$this->assertSame( $this->account_id . ':' . self::GROUP, $this->by_sku( 'RED' )['meta'][ Group_Assembler::group_key( Sqlite_Tables::MARKETPLACE_ID ) ] ?? null, 'and it carries the group' );
		}

		$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );
		$this->import( $this->card( 'RED', 'красный', 2 ) );
		$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );

		$this->assertCount( 1, $this->products(), 'one product for the group, whatever was cut' );
		$this->assertSame( [ 'BLACK', 'RED' ], array_keys( $this->shape()[0]['variations'] ) );
	}

	/**
	 * Critic round 1, finding 2: the first card of a group of several, cut at any of its saves or at its link, is finished by
	 * the retry of that same card — no sibling is needed.
	 */
	public function test_the_first_card_of_a_group_of_several_cut_anywhere_is_finished_by_its_own_retry(): void {
		$this->import( $this->card( 'RED', 'красный', 2 ) );

		$whole = $this->shape();
		$saves = $this->shop->saves;

		for ( $save = 1; $save <= $saves; $save++ ) {
			$this->empty_shop();
			$this->shop->fail_save_at = $save;

			try {
				$this->import( $this->card( 'RED', 'красный', 2 ) );
				$this->fail( 'The run is cut at save ' . $save . '.' );
			} catch ( \RuntimeException $cut ) {
				$this->assertSame( $save, $this->shop->saves );
			}

			$this->import( $this->card( 'RED', 'красный', 2 ) );

			$this->assertSame( $whole, $this->shape(), 'cut at save ' . $save . ', the retry of the same card finishes the product' );
		}

		// The same when the run loses its claim: whatever was written, the retry ends in the same product.
		$this->empty_shop();

		try {
			$this->import( $this->card( 'RED', 'красный', 2 ), new Claim_Fence( Sqlite_Tables::table( 'accounts' ), 'a-token-another-run-replaced' ) );
			$this->fail( 'A lost claim stops the run.' );
		} catch ( Claim_Lost $lost ) {
			$this->assertNull( $this->links()->find_by_offer_id( $this->account_id, 'RED' ) );
		}

		$this->import( $this->card( 'RED', 'красный', 2 ) );

		$this->assertSame( $whole, $this->shape() );
	}

	/**
	 * Critic round 1, finding 3: with «Сразу публиковать…» off, a variation that joins a published product is never stored
	 * switched on — not for a moment, and not after a cut at any save of its import.
	 */
	public function test_a_variation_of_a_published_product_is_never_stored_on_sale_when_the_shop_does_not_publish(): void {
		$prepare = function (): void {
			$this->empty_shop();
			$this->import( $this->card( 'RED', 'красный', 1 ) );

			$product = $this->shop->product( 'simple', 900 );
			$product->set_status( 'publish' );
			$product->save();
		};

		$prepare();
		$before = $this->shop->saves;
		$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );
		$saves = $this->shop->saves - $before;

		for ( $save = 1; $save <= $saves; $save++ ) {
			$prepare();
			$this->shop->fail_save_at = $this->shop->saves + $save;

			try {
				$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );
				$this->fail( 'The run is cut at save ' . $save . '.' );
			} catch ( \RuntimeException $cut ) {
				$stored = $this->shop->id_by_sku( 'BLACK' );

				$this->assertNotSame( 'publish', 0 === $stored ? 'none' : $this->shop->stored[ $stored ]['props']['status'], 'cut at save ' . $save . ': nothing on sale is stored' );
			}

			$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );

			$this->assertSame( 'private', $this->by_sku( 'BLACK' )['props']['status'], 'cut at save ' . $save . ': the retry leaves the variation switched off' );
			$this->assertStringContainsString( 'выключен', (string) $this->note( 'BLACK' ), 'and the merchant is told' );
			$this->assertNotNull( $this->links()->find_by_offer_id( $this->account_id, 'BLACK' ) );
		}
	}

	/**
	 * Critic round 1, finding 5: the preview and the job make one decision about a group whose first product is half-made.
	 */
	public function test_the_preview_and_the_job_agree_about_a_half_made_group(): void {
		$this->cut['RED'] = new \RuntimeException( 'time limit during images' );

		try {
			$this->import( $this->card( 'RED', 'красный', 2 ) );
		} catch ( \RuntimeException $cut ) {
			unset( $cut );
		}

		$plan  = new Remote_Cards_Import_Plan(
			function ( string $sku ): string {
				return 0 === $this->shop->id_by_sku( $sku ) ? Remote_Cards_Import_Plan::SKU_NONE : Remote_Cards_Import_Plan::SKU_MARKED;
			},
			Group_Assembler::lookup( Sqlite_Tables::MARKETPLACE_ID )
		);
		$black = [
			'offer_id'   => 'BLACK',
			'details_ok' => true,
			'group'      => [
				'key'  => self::GROUP,
				'size' => 2,
			],
		];
		$red   = array_merge( $black, [ 'offer_id' => 'RED' ] );

		$alone = $plan->build( [ $black ], [], [], $this->account_id );

		$this->assertSame( [], $alone[ Remote_Cards_Import_Plan::CREATE ], 'the preview does not promise what the job will not do' );
		$this->assertSame( [ 'BLACK' ], $alone['skipped'][ Remote_Cards_Import_Plan::GROUP_BUILDING ] ?? null );
		$this->assertSame( Card_Import::SKIPPED, $this->import( $this->card( 'BLACK', 'чёрный', 2 ) ), 'and the job skips it' );

		// Selected together, the half-made card goes first, whatever the order of the selection, and the other one joins it.
		$both = $plan->build( [ $black, $red ], [], [], $this->account_id );

		$this->assertSame( [ 'RED', 'BLACK' ], $both[ Remote_Cards_Import_Plan::CREATE ] );
		$this->assertSame( [ 1, 0, [] ], [ $both['products'], $both['joined'], $both['skipped'] ] );

		foreach ( $both[ Remote_Cards_Import_Plan::CREATE ] as $article ) {
			$this->assertSame( Card_Import::CREATED, $this->import( $this->card( $article, 'RED' === $article ? 'красный' : 'чёрный', 2 ) ) );
		}

		$this->assertCount( 1, $this->products() );
		$this->assertCount( 2, $this->shop->variations( 900 ) );
	}

	/**
	 * Critic round 1, finding 4: an order line made while the product was simple names the product and no variation. After
	 * the conversion it is the line of the product's first variation, which holds the stock the line was sold from.
	 */
	public function test_an_order_line_of_the_simple_product_resolves_to_its_first_variation_after_the_conversion(): void {
		$this->import( $this->card( 'RED', 'красный', 1 ) );
		$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );

		$lines  = new Legacy_Order_Lines( Sqlite_Tables::MARKETPLACE_ID );
		$parent = $this->shop->product( 'variable', 900 );
		$line   = function ( int $variation_id ) {
			$item = \Mockery::mock( 'WC_Order_Item_Product' );
			$item->shouldReceive( 'get_variation_id' )->andReturn( $variation_id );

			return $item;
		};

		$this->assertSame( $this->by_sku( 'RED' )['id'], $lines->resolve( $parent, $line( 0 ) )->get_id(), 'the old line is the red variation\'s' );
		$this->assertSame( 900, $lines->resolve( $parent, $line( $this->by_sku( 'BLACK' )['id'] ) )->get_id(), 'a line that names a variation is left alone' );

		$other = $this->shop->product( 'variable' );
		$other->save();

		$this->assertSame( $other->get_id(), $lines->resolve( $other, $line( 0 ) )->get_id(), 'a variable product the importer did not convert is left alone' );
		$this->assertFalse( $lines->resolve( false, $line( 0 ) ), 'and so is a product that is gone' );
	}

	/**
	 * Row 1 for a card of a group: nothing is imported, but the card's product is put right — a conversion another card left
	 * open is finished, and the product of what was a group of one becomes variable once the group has several.
	 */
	public function test_a_linked_card_finishes_what_is_open_on_its_product(): void {
		$this->import( $this->card( 'RED', 'красный', 1 ) );

		// The black card's run converts the red product and is cut after the product became variable: no variation yet.
		$this->shop->fail_save_at = $this->shop->saves + 2;

		try {
			$this->import( $this->card( 'BLACK', 'чёрный', 2 ) );
			$this->fail( 'The run is cut.' );
		} catch ( \RuntimeException $cut ) {
			$this->assertSame( [ 'variable', 0 ], [ $this->shop->stored[900]['type'], count( $this->shop->variations( 900 ) ) ] );
		}

		$this->assertSame( Card_Import::ALREADY_LINKED, $this->import( $this->card( 'RED', 'красный', 2 ) ) );

		$red = $this->by_sku( 'RED' );

		$this->assertSame( [ 'variation', 900 ], [ $red['type'], $red['props']['parent_id'] ], 'the red card is sellable again, as the first variation' );
		$this->assertFalse( $this->shape()[0]['pending'] );
		$this->assertSame( $red['id'], $this->links()->find_by_offer_id( $this->account_id, 'RED' )->wc_product_id() );
		$this->assertFalse( $this->links()->find( $this->account_id, 900 )->is_offer() );

		// A group of one that grew: the linked card's product becomes variable when the card is seen again.
		$this->empty_shop();
		$this->import( $this->card( 'RED', 'красный', 1 ) );

		$this->assertSame( 'simple', $this->by_sku( 'RED' )['type'] );
		$this->assertSame( Card_Import::ALREADY_LINKED, $this->import( $this->card( 'RED', 'красный', 2 ) ) );
		$this->assertSame( 'variation', $this->by_sku( 'RED' )['type'] );
		$this->assertSame( Card_Import::ALREADY_LINKED, $this->import( $this->card( 'RED', 'красный', 2 ) ), 'and a third look changes nothing' );
		$this->assertCount( 1, $this->shop->variations( 900 ) );
	}
}
